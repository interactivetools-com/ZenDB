<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use DateTime, DateTimeZone;
use mysqli;
use Throwable, InvalidArgumentException, RuntimeException, Exception;

/**
 * Connection class handles database connection management and configuration.
 */
class Connection
{
    #region Properties

    public ?mysqli $mysqli = null;
    private array  $config;

    #endregion
    #region Constructor

    /**
     * Creates a new Connection instance with the provided configuration and establishes connection.
     *
     * @throws Exception If connection fails or configuration is invalid
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    #endregion
    #region Connection Management

    /**
     * Connect to the database server.
     *
     * @internal This method should not be called directly. Use reconnect() instead.
     * @throws Exception If connection fails or configuration is invalid
     */
    private function connect(): void
    {
        // Throw error if called directly after initial connection was made
        if ($this->mysqli !== null) {
            return; // Already connected, just return
        }

        $cfg = $this->config; // shortcut var

        // Check for required config keys - using consistent approach from DB class
        $requiredKeys = ['hostname', 'username', 'database'];
        $missingKeys  = array_filter($requiredKeys, fn($key) => !isset($cfg[$key]) || $cfg[$key] === '');
        if ($missingKeys) {
            throw new InvalidArgumentException("Missing required config keys: " . implode(', ', $missingKeys));
        }

        // Check for valid database name
        Assert::ValidDatabaseName($cfg['database']);

        // Attempt to connect
        $tempMysqli = null;
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);                  // throw exceptions for all MySQL errors, so we can catch them

            // Security: Locally scope credentials
            [$username, $password, $hostname] = [$cfg['username'], $cfg['password'], $cfg['hostname']];

            // connect to db
            $tempMysqli = new MysqliWrapper();                                          // old way: new mysqli();
            $tempMysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $cfg['connectTimeout']);   // throw exception after x seconds trying to connect
            $tempMysqli->options(MYSQLI_OPT_READ_TIMEOUT, $cfg['readTimeout']);         // throw exception after x seconds trying to read
            $tempMysqli->options(MYSQLI_OPT_LOCAL_INFILE, false);                       // disable "LOAD DATA LOCAL INFILE" for security reasons

            // Enable native int/float return types when mysqlnd driver is available
            // Converts numeric results from strings to proper PHP types for mysqli::query() results
            // mysqli_stmt already returns native types by default, this makes them both consistent
            if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
                $tempMysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
            }
            $tempMysqli->real_connect(
                hostname: $hostname,                              // can also contain :port
                username: $username,
                password: $password,
                flags: $cfg['requireSSL'] ? MYSQLI_CLIENT_SSL : 0, // require ssl connections
            );

            // set charset - DO THIS FIRST and use "utf8mb4", required so mysqli_real_escape_string() knows what charset to use.
            // See: https://www.php.net/manual/en/mysqlinfo.concepts.charset.php#example-1421
            if ($tempMysqli->character_set_name() !== 'utf8mb4') {
                $tempMysqli->set_charset('utf8mb4') || throw new RuntimeException("Error setting charset utf8mb4." . $tempMysqli->error);
            }

            // check mysql version
            if ($cfg['versionRequired']) {
                Assert::mysqlVersion($tempMysqli, $cfg['versionRequired']);
            }

            // set some database vars based on config settings -  SET time_zone etc
            $this->setDatabaseVars($tempMysqli, $cfg);

            // Only after successful connection, set $this->mysqli
            $this->mysqli = $tempMysqli;

        } catch (Throwable $e) {
            $errorCode    = $tempMysqli?->connect_errno ?? 0;
            $baseErrorMsg = "MySQL Error($errorCode)";
            $errorDetail  = $e->getMessage() ?? $tempMysqli?->connect_error ?? 'Unknown error';
            $errorMsg = match (true) {
                $errorCode === 2002                       => "$baseErrorMsg: Couldn't connect to server, check database server is running and connection settings are correct.\n$errorDetail",
                $errorCode === 2006 && $cfg['requireSSL'] => "$baseErrorMsg: Try disabling 'requireSSL' in database configuration.\n$errorDetail",  // 2006 = MySQL error constant CR_SERVER_GONE_ERROR: MySQL server has gone away
                default                                   => "$baseErrorMsg: $errorDetail",
            };

            // Clean up failed connection
            $tempMysqli?->close();

            throw new RuntimeException($errorMsg . ", hostname: $hostname");
        } finally {
            mysqli_report(MYSQLI_REPORT_OFF); // Disable strict mode, errors now return false instead of throwing exceptions
        }

        // Select database and/or try to create it
        $this->selectOrCreateDatabase();

    }

    /**
     * Reconnect to the database server if the connection is lost.
     *
     * This method checks if the connection is active, and if not, it attempts to reconnect.
     *
     * @return bool True if reconnected successfully or already connected, false if unable to reconnect
     * @throws Exception If connection fails or configuration is invalid
     */
    public function reconnectIfNeeded(): bool
    {
        $isConnected = $this->isConnected(true); // check if connection is still alive with stat() (like ping but not deprecated)

        // Reconnect if not connected
        if (!$isConnected) {
            try {
                $this->disconnect();  // Clean up any existing connection resources
                $this->connect();
                $isConnected = $this->isConnected(true);
            } catch (Throwable $e) {
                // Log the error but don't throw during reconnect
                // This allows reconnecting without immediately throwing an exception
                $isConnected = false; // Important: set to false on connection failure
            }
        }

        return $isConnected;
    }

    /**
     * Check if database connection was made and optionally check if it's still active.
     *
     * This method verifies if the MySQLi connection is established.
     * If $doPing is true, it will ping the database server to confirm the connection is still alive.
     *
     * @param bool $doPing Whether to ping the server to check for active connection. Default is false.
     * @return bool True if the connection is valid (and responsive if $doPing is true), false otherwise.
     */
    public function isConnected(bool $doPing = false): bool
    {
        return match (true) {
            is_null($this->mysqli),                                             // MySQLi object not defined yet
            $this->mysqli->connect_errno,                                       // Connection attempted but failed
            empty($this->mysqli->host_info) => false,                           // Connection not attempted yet (!connect_errno & !host_info)
            $doPing                         => $this->mysqli->stat() !== false, // replacement for deprecated ping(), returns string on active connection
            default                         => true,
        };
    }

    /**
     * Close the database connection.
     */
    public function disconnect(): void
    {
        $this->mysqli?->close();
        $this->mysqli = null;
    }

    #endregion
    #region Configuration Helpers

    /**
     * Right after connecting set some database vars based on config settings.
     *
     * @param mysqli $mysqli The MySQLi object.
     * @param array $cfg Configuration array containing settings.
     * @throws RuntimeException|Exception If any of the initialization queries fail.
     */
    private function setDatabaseVars(mysqli $mysqli, array $cfg): void
    {
        // Get current vars
        $mysqlDefaults = $mysqli->query("SELECT @@sql_mode, TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP), '%H:%i') AS timezone_offset")->fetch_assoc();

        // SET time_zone
        if (!empty($cfg['usePhpTimezone'])) {
            $this->setTimezoneToPhpTimezone($mysqli, $mysqlDefaults['timezone_offset']);
        }


        // SET sql_mode
        if (!empty($cfg['set_sql_mode']) && $cfg['set_sql_mode'] !== $mysqlDefaults['@@sql_mode']) {
            $query = "SET sql_mode = '{$cfg['set_sql_mode']}';";
            $mysqli->real_query($query) || throw new RuntimeException("Set command failed:\n$query");
        }
    }

    /**
     * Sets the MySQL timezone to match the PHP timezone.
     *
     * @param mysqli $mysqli The MySQLi connection
     * @param string $mysqlTzOffset Current MySQL timezone offset
     * @throws RuntimeException|Exception  If not connected to the database or if the set command fails.
     */

    private function setTimezoneToPhpTimezone(mysqli $mysqli, string $mysqlTzOffset = ''): void
    {
        // get PHP timezone offset
        $phpDateTz   = new DateTimeZone(date_default_timezone_get());
        $phpTzOffset = (new DateTime('now', $phpDateTz))->format('P');  // UTC offset, e.g., +00:00

        if ($phpTzOffset === $mysqlTzOffset) {
            return; // no need to set timezone if it's already set
        }

        // Set MySQL timezone offset to the same as PHP (so NOW(), etc matches PHP time)
        $query = "SET time_zone = '$phpTzOffset'";
        $mysqli->real_query($query) || throw new RuntimeException("Set command failed:\n$query");
    }

    /**
     * Select the database or create it if it doesn't exist and databaseAutoCreate is enabled.
     */
    private function selectOrCreateDatabase(): void
    {
        // Error checking
        Assert::ValidDatabaseName($this->config['database']);

        // Select database and/or try to create it
        $isSuccessful = $this->mysqli->select_db($this->config['database']);

        // If database doesn't exist, try to create it
        if (!$isSuccessful && $this->config['databaseAutoCreate']) {

            $result = $this->mysqli->query("CREATE DATABASE `{$this->config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if ($result === false) {
                throw new RuntimeException("Couldn't create DB: " . $this->mysqli->error);
            }
            $isSuccessful = $this->mysqli->select_db($this->config['database']) || throw new RuntimeException("MySQL Error selecting database: " . $this->mysqli->error);
        }

        // If still not successful, throw an exception
        if (!$isSuccessful) {
            throw new RuntimeException("MySQL Error selecting database: " . $this->mysqli->error);
        }
    }

    #endregion
}
