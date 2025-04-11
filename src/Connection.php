<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use DateTime, DateTimeZone;
use mysqli_driver;
use Throwable, InvalidArgumentException, RuntimeException, Exception;
use mysqli;

/**
 * Connection class handles database connection management and configuration.
 */
class Connection
{
    #region Public Interface

    /**
     * Creates a new Connection instance with the provided configuration and establishes connection.
     *
     * @param array $properties Configuration for the connection
     */
    public function __construct(array $properties)
    {
        // check for required keys
        $missing = array_filter($properties, 'is_null');
        if ($missing) {
            throw new InvalidArgumentException("Missing required connection options: " . implode(', ', array_keys($missing)));
        }

        // set properties
        foreach ($properties as $property => $value) {
            $this->$property = $value;
        }

        // Establish connection
        $this->connect();
    }

    /**
     * Establish a connection to the database server.
     *
     * @throws InvalidArgumentException If required configuration is missing
     * @throws RuntimeException If connection fails
     */
    private function connect(): void
    {
        // Throw error if called directly after initial connection was made
        if (isset($this->mysqli)) {
            throw new RuntimeException("Connection already established. Use isConnected() or reconnectIfNeeded() to check connection status.");
        }

        // Check for required keys
        $required = ['hostname', 'username'];
        $missing  = array_filter($required, fn($property) => !isset($this->$property));
        if ($missing) {
            throw new InvalidArgumentException("Missing required connection properties: " . implode(', ', $missing));
        }

        // throw exceptions for all MySQL errors, so we can catch them.  Enables strict error reporting for all DB code from this point on.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // Attempt to connect
        try {
            // connect to db
            $tempMysqli = new MysqliWrapper();                                                 // old way: new mysqli();
            $tempMysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $this->connectTimeout);           // throw exception after x seconds trying to connect
            $tempMysqli->options(MYSQLI_OPT_READ_TIMEOUT, $this->readTimeout);                 // throw exception after x seconds trying to read
            $tempMysqli->options(MYSQLI_OPT_LOCAL_INFILE, false);                              // disable "LOAD DATA LOCAL INFILE" for security reasons

            // Enable native int/float return types when mysqlnd driver is available
            // Converts numeric results from strings to proper PHP types for mysqli::query() results
            // mysqli_stmt already returns native types by default, this makes them both consistent
            if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
                $tempMysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
            }
            $tempMysqli->real_connect(
                hostname: $this->hostname,                               // can also contain :port
                username: $this->username,
                password: $this->password,
                flags: $this->requireSSL ? MYSQLI_CLIENT_SSL : 0,        // require ssl connections
            );

            // set charset - DO THIS FIRST and use "utf8mb4", required so mysqli_real_escape_string() knows what charset to use.
            // See: https://www.php.net/manual/en/mysqlinfo.concepts.charset.php#example-1421
            if ($tempMysqli->character_set_name() !== 'utf8mb4') {
                $tempMysqli->set_charset('utf8mb4') || throw new RuntimeException("Error setting charset utf8mb4." . $tempMysqli->error);
            }

            // check mysql version
            if ($this->versionRequired) {
                $requiredVersion = $this->versionRequired;
                $currentVersion  = preg_replace("/[^0-9.]/", '', $tempMysqli->server_info);
                if (version_compare($requiredVersion, $currentVersion, '>')) {
                    $error = "This program requires MySQL v$requiredVersion or newer. This server has v$currentVersion installed.\n";
                    $error .= "Please ask your server administrator to install MySQL v$requiredVersion or newer.\n";
                    throw new RuntimeException($error);
                }
            }

            // set some database vars based on properties settings -  SET time_zone etc
            $this->setDatabaseVars($tempMysqli);

            // Only after successful connection, set $this->mysqli
            $this->mysqli = $tempMysqli;
        } catch (Throwable $e) {
            $errorCode    = $tempMysqli?->connect_errno ?? 0;
            $baseErrorMsg = "MySQL Error($errorCode)";
            $errorDetail  = $e->getMessage() ?? $tempMysqli?->connect_error ?? 'Unknown error';
            $errorMsg     = match (true) {
                $errorCode === 2002                      => "$baseErrorMsg: Couldn't connect to server, check database server is running and connection settings are correct.\n$errorDetail",
                $errorCode === 2006 && $this->requireSSL => "$baseErrorMsg: Try disabling 'requireSSL' in database configuration.\n$errorDetail",  // 2006 = MySQL error constant CR_SERVER_GONE_ERROR: MySQL server has gone away
                default                                  => "$baseErrorMsg: $errorDetail",
            };

            // Clean up failed connection
            $tempMysqli?->close();

            throw new RuntimeException($errorMsg . ", hostname: " . $this->hostname);
        } finally {
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
        $this->mysqli;
    }


    /**
     * Sets the MySQL timezone to match the PHP timezone.
     *
     * @param mysqli|null $mysqli Optional mysqli connection if different from the instance's connection
     * @param string $mysqlTzOffset Current MySQL timezone offset
     * @throws RuntimeException|Exception  If not connected to the database or if the set command fails.
     */
    public function setTimezoneToPhpTimezone(?mysqli $mysqli = null, string $mysqlTzOffset = ''): void
    {
        // Use the provided mysqli connection or the instance's connection
        $mysqli = $mysqli ?? $this->mysqli;

        if (!$mysqli) {
            throw new RuntimeException("Not connected to database");
        }

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

    #endregion
    #region Connection State

    /**
     * The underlying MySQLi connection object
     */
    public ?mysqli $mysqli;

#endregion
#region Basic Connection Properties

    /**
     * Mysql hostname (can include port, e.g. 'localhost:3306')
     */
    private string $hostname;

    /**
     * Mysql username for authentication
     */
    private string $username;

    /**
     * Mysql password for authentication
     */
    private string $password;

    /**
     * Mysql database to connect to
     */
    private string $database;

#endregion
#region Advanced Connection Settings

    /**
     * Minimum MySQL version required for compatibility
     */
    private string $versionRequired;

    /**
     * MySQL SQL mode configuration
     * Default enforces strict mode and important error conditions
     */
    private string $sqlMode;

    /**
     * Whether to require SSL for database connections
     */
    private bool $requireSSL;

    /**
     * Connection timeout in seconds (MYSQLI_OPT_CONNECT_TIMEOUT)
     */
    private int $connectTimeout;

    /**
     * Read timeout in seconds (MYSQLI_OPT_READ_TIMEOUT)
     */
    private int $readTimeout;

    /**
     * Whether to synchronize MySQL timezone with PHP timezone
     * Ensures MySQL NOW() matches PHP time() function
     */
    private bool $usePhpTimezone;

    /**
     * Whether to automatically create the database if it doesn't exist
     */
    private bool $databaseAutoCreate;

    #endregion
    #region Internals

    /**
     * Right after connecting set some database vars based on properties settings.
     *
     * @param mysqli $mysqli The MySQLi object.
     * @throws RuntimeException|Exception If any of the initialization queries fail.
     */
    private function setDatabaseVars(mysqli $mysqli): void
    {
        // Get current vars
        $mysqlDefaults = $mysqli->query("SELECT @@sql_mode, TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP), '%H:%i') AS timezone_offset")->fetch_assoc();

        // SET time_zone
        if ($this->usePhpTimezone) {
            $this->setTimezoneToPhpTimezone($mysqli, $mysqlDefaults['timezone_offset']);
        }

        // SET sql_mode
        if (!empty($this->sqlMode) && $this->sqlMode !== $mysqlDefaults['@@sql_mode']) {
            $query = "SET sql_mode = '{$this->sqlMode}';";
            $mysqli->real_query($query) || throw new RuntimeException("Set command failed:\n$query");
        }
    }

    /**
     * Select the database or create it if it doesn't exist and databaseAutoCreate is enabled.
     */
    private function selectOrCreateDatabase(): void
    {
        // Skip if no database name is provided
        if (empty($this->database)) {
            return;
        }

        // Error checking
        Assert::validDatabaseName($this->database);

        // Select database and/or try to create it
        $isSuccessful = $this->mysqli->select_db($this->database);

        // If database doesn't exist, try to create it
        if (!$isSuccessful && $this->databaseAutoCreate) {
            $result = $this->mysqli->query("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if ($result === false) {
                throw new RuntimeException("Couldn't create DB: " . $this->mysqli->error);
            }
            $isSuccessful = $this->mysqli->select_db($this->database) || throw new RuntimeException("MySQL Error selecting database: " . $this->mysqli->error);
        }

        // If still not successful, throw an exception
        if (!$isSuccessful) {
            throw new RuntimeException("MySQL Error selecting database: " . $this->mysqli->error);
        }
    }

    /**
     * Prevents setting undefined properties
     *
     * @param string $name Property name being set
     * @param mixed $value Value being assigned to the property
     * @throws InvalidArgumentException Always throws an exception for undefined properties
     */
    public function __set(string $name, mixed $value): void
    {
        throw new InvalidArgumentException("Attempting to set unknown connection property: '$name'. ");
    }

    /**
     * Prevents accessing undefined properties
     *
     * @param string $name Property name being accessed
     * @throws InvalidArgumentException Always throws an exception for undefined properties
     */
    public function __get(string $name): never
    {
        throw new InvalidArgumentException("Attempting to get unknown connection property: '$name'. ");
    }

    #endregion
}
