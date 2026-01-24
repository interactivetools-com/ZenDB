<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
use mysqli;
use mysqli_driver;
use mysqli_result;
use mysqli_sql_exception;
use Throwable, InvalidArgumentException, RuntimeException;

/**
 * Connection class for ZenDB - manages a single database connection with its own settings.
 *
 * Usage:
 *     // Create default connection
 *     new Connection(['hostname' => 'localhost', 'database' => 'mydb'], default: true);
 *
 *     // Create additional connection
 *     $remote = new Connection(['hostname' => 'remote.example.com', 'database' => 'legacy']);
 *
 *     // Clone with different settings
 *     $db = DB::clone(['useSmartJoins' => false]);
 */
class Connection
{
    //region Constructor

    /**
     * Create a new database connection.
     *
     *     new Connection($config)                  // Create connection
     *     new Connection($config, default: true)   // Create and set as default
     *
     * @param array $config Configuration options to set as properties
     * @param bool $default If true, set this connection as the default (DB::setDefault)
     */
    public function __construct(array $config = [], bool $default = false)
    {
        // Apply configuration
        foreach ($config as $key => $value) {
            if (!property_exists($this, $key)) {
                throw new InvalidArgumentException("Unknown configuration key: '$key'");
            }
            $this->$key = $value;
        }

        // Connect immediately if connection settings provided
        if ($this->hostname !== null) {
            $this->connect();
        }

        // Set as default if requested
        if ($default) {
            DB::setDefault($this);
        }
    }

    //endregion
    //region Connection Management

    /**
     * Connect to the database using the configured settings.
     *
     * @throws RuntimeException If connection fails or version requirements not met
     */
    public function connect(): void
    {
        // Skip if already connected
        if ($this->isConnected(true)) {
            return;
        }

        // Validate required connection credentials
        if ($this->hostname === null || $this->username === null) {
            throw new InvalidArgumentException("Missing required database credentials: hostname and username must be set");
        }

        // Create a new mysqli instance
        $this->mysqli = new MysqliWrapper(
            enableLogging: $this->enableLogging,
            logFile: $this->logFile,
            debugMode: $this->debugMode,
            webRootCallback: $this->webRootCallback
        );
        $this->mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $this->connectTimeout);
        $this->mysqli->options(MYSQLI_OPT_READ_TIMEOUT, $this->readTimeout);
        $this->mysqli->options(MYSQLI_OPT_LOCAL_INFILE, false); // disable "LOAD DATA LOCAL INFILE" for security

        // Return native PHP types (int/float) instead of strings
        if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
            $this->mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
        }

        $flags = $this->requireSSL ? MYSQLI_CLIENT_SSL : 0;

        // Attempt to connect
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);  // ensure exceptions are thrown (default for PHP 8.1+)

            try {
                $this->mysqli->real_connect($this->hostname, $this->username, $this->password, $this->database, null, null, $flags);
            } catch (mysqli_sql_exception $e) {
                // if database doesn't exist and auto-create enabled, try again and create database
                if ($this->databaseAutoCreate && $e->getCode() === 1049) {
                    $dbCreateQuery = "CREATE DATABASE `$this->database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                    $this->mysqli->real_connect($this->hostname, $this->username, $this->password, null, null, null, $flags);
                    $this->mysqli->query($dbCreateQuery) ?: throw new RuntimeException("Couldn't create DB: " . $this->mysqli->error);
                    $this->mysqli->select_db($this->database) ?: throw new RuntimeException("MySQL Error selecting database: " . $this->mysqli->error);
                } else {
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            $errorCode    = $this->mysqli->connect_errno;
            $baseErrorMsg = "MySQL Error($errorCode)";
            $errorDetail  = $e->getMessage() ?? $this->mysqli->connect_error;

            // Detect WSL + Unix socket failure
            $isWslSocketError = isset($_SERVER['WSL_DISTRO_NAME']) && $errorCode === 2002 && preg_match('/No such file/i', $errorDetail) && preg_match('/^localhost$/i', (string) $this->hostname);

            $errorMsg = match (true) {
                $isWslSocketError                        => "'localhost' uses Unix sockets. To connect to Windows MySQL from WSL, use '127.0.0.1' or 'localhost:3306' with WSL mirrored networking.\n$baseErrorMsg: $errorDetail",
                $errorCode === 2002                      => "Couldn't connect to server, check database server is running and connection settings are correct.\n$baseErrorMsg: $errorDetail",
                $errorCode === 2006 && $this->requireSSL => "Try disabling 'requireSSL' in database configuration.\n$baseErrorMsg: $errorDetail",
                default                                  => "$baseErrorMsg: $errorDetail",
            };
            throw new RuntimeException($errorMsg);
        }

        // Set charset - DO THIS FIRST
        if ($this->mysqli->character_set_name() !== 'utf8mb4') {
            $this->mysqli->set_charset('utf8mb4') || throw new RuntimeException("Error setting charset utf8mb4." . $this->mysqli->error);
        }

        // Check mysql version
        if ($this->versionRequired) {
            $currentVersion = preg_replace("/[^0-9.]/", '', $this->mysqli->server_info);
            if (version_compare($this->versionRequired, $currentVersion, '>')) {
                $error  = "This program requires MySQL v$this->versionRequired or newer. This server has v$currentVersion installed.\n";
                $error .= "Please ask your server administrator to install MySQL v$this->versionRequired or newer.\n";
                throw new RuntimeException($error);
            }
        }

        // Set MySQL server variables
        $sets = $this->usePhpTimezone ? "time_zone = '" . date('P') . "', " : '';
        $sets .= $this->sqlMode ? "sql_mode = '$this->sqlMode', " : '';
        if ($sets = rtrim($sets, ', ')) {
            $this->mysqli->real_query("SET $sets") || throw new RuntimeException("Set command failed:\nSET $sets");
        }

        // Clear sensitive credentials after connection
        $this->hostname = null;
        $this->username = null;
        $this->password = null;
    }

    /**
     * Disconnect from the database.
     */
    public function disconnect(): void
    {
        if ($this->mysqli instanceof mysqli) {
            $this->mysqli->close();
            $this->mysqli = null;
        }
    }

    /**
     * Check if database connection was made and optionally check if it's still active.
     *
     * @param bool $doPing Whether to ping the server to check for active connection
     * @return bool True if connected (and responsive if $doPing is true)
     */
    public function isConnected(bool $doPing = false): bool
    {
        return match (true) {
            is_null($this->mysqli) => false,
            $doPing                => $this->mysqli->stat() !== false,
            default                => true,
        };
    }

    /**
     * Ensure connection is alive, reconnect if needed.
     * Useful for long-running processes where MySQL may drop idle connections.
     *
     * @throws RuntimeException If reconnection fails
     */
    public function ensureConnected(): void
    {
        if (!$this->isConnected(true)) {
            $this->disconnect();
            $this->connect();
        }
    }

    //endregion
    //region Query Methods

    /**
     * Execute a raw SQL query and return results.
     *
     * @param string $sqlTemplate SQL statement with placeholders
     * @param mixed ...$params Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws DBException
     */
    public function query(string $template, ...$params): SmartArrayHtml
    {
        // Error checking
        if (!preg_match('/^\s*([a-zA-Z]+)\b/', $template)) {
            throw new InvalidArgumentException("SQL statement must start with a valid SQL keyword such as SELECT, INSERT, etc.");
        }

        $query = new Query(
            mysqli:          $this->mysqli,
            tablePrefix:     $this->tablePrefix,
            primaryKey:      $this->primaryKey,
            useSmartJoins:   $this->useSmartJoins,
            useSmartStrings: $this->useSmartStrings,
            loadHandler:     $this->smartArrayLoadHandler,
            params:          $params,
        );

        try {
            return $query->execute($template);
        } catch (Throwable $e) {
            throw new DBException("Error executing query", 0, $e);
        }
    }

    /**
     * Select rows from a table.
     *
     * @param string           $baseTable    Table name (without prefix)
     * @param int|array|string $idArrayOrSql WHERE condition
     * @param mixed            ...$params    Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws DBException
     * @throws Throwable
     */
    public function select(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArrayHtml
    {
        $query = new Query(
            mysqli:          $this->mysqli,
            tablePrefix:     $this->tablePrefix,
            primaryKey:      $this->primaryKey,
            useSmartJoins:   $this->useSmartJoins,
            useSmartStrings: $this->useSmartStrings,
            loadHandler:     $this->smartArrayLoadHandler,
            baseTable:       $baseTable,
            params:          $params,
            where:           $idArrayOrSql,
        );

        return $query->execute("SELECT * FROM `::$query->baseTable` $query->whereEtc");
    }

    /**
     * Get a single row from a table.
     *
     * @param string $baseTable Table name (without prefix)
     * @param int|array|string $idArrayOrSql WHERE condition
     * @param mixed ...$params Parameters to bind
     * @return SmartArrayHtml Single row or empty SmartArrayHtml
     * @throws DBException
     */
    public function get(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArrayHtml
    {
        // Error checking
        if (is_string($idArrayOrSql) && preg_match('/\b(LIMIT|OFFSET)\s+[0-9:?]+\s*/i', $idArrayOrSql)) {
            throw new InvalidArgumentException("This method doesn't support LIMIT or OFFSET, use select() instead");
        }

        $query = new Query(
            mysqli:          $this->mysqli,
            tablePrefix:     $this->tablePrefix,
            primaryKey:      $this->primaryKey,
            useSmartJoins:   $this->useSmartJoins,
            useSmartStrings: $this->useSmartStrings,
            loadHandler:     $this->smartArrayLoadHandler,
            baseTable:       $baseTable,
            params:          $params,
            where:           $idArrayOrSql,
        );

        try {
            $resultSet = $query->execute("SELECT * FROM `::$query->baseTable` $query->whereEtc LIMIT 1");
        } catch (Throwable $e) {
            throw new DBException("Error getting row.", 0, $e);
        }

        // Return first row
        if ($resultSet->isEmpty()) {
            return $resultSet->filter(fn() => false);
        }
        return $resultSet->first();
    }

    /**
     * Insert a row into a table.
     *
     * @param string $baseTable Table name (without prefix)
     * @param array $colsToValues Column => value pairs
     * @return int Insert ID
     * @throws DBException
     */
    public function insert(string $baseTable, array $colsToValues): int
    {
        $query = new Query(
            mysqli:          $this->mysqli,
            tablePrefix:     $this->tablePrefix,
            primaryKey:      $this->primaryKey,
            useSmartJoins:   $this->useSmartJoins,
            useSmartStrings: $this->useSmartStrings,
            loadHandler:     $this->smartArrayLoadHandler,
            baseTable:       $baseTable,
            set:             $colsToValues,
        );

        try {
            $resultSet = $query->execute("INSERT INTO `::$query->baseTable` $query->setClause");
        } catch (Throwable $e) {
            throw new DBException("Error inserting row.", 0, $e);
        }

        return $resultSet->mysqli('insert_id');
    }

    /**
     * Update rows in a table.
     *
     * @param string $baseTable Table name (without prefix)
     * @param array $colsToValues Column => value pairs to update
     * @param int|array|string $idArrayOrSql WHERE condition (required)
     * @param mixed ...$params Parameters to bind
     * @return int Number of affected rows
     * @throws DBException
     */
    public function update(string $baseTable, array $colsToValues, int|array|string $idArrayOrSql, ...$params): int
    {
        $query = new Query(
            mysqli:          $this->mysqli,
            tablePrefix:     $this->tablePrefix,
            primaryKey:      $this->primaryKey,
            useSmartJoins:   $this->useSmartJoins,
            useSmartStrings: $this->useSmartStrings,
            loadHandler:     $this->smartArrayLoadHandler,
            baseTable:       $baseTable,
            params:          $params,
            where:           $idArrayOrSql,
            whereRequired:   true,
            set:             $colsToValues,
        );

        try {
            $resultSet = $query->execute("UPDATE `::$query->baseTable` $query->setClause $query->whereEtc");
        } catch (Throwable $e) {
            throw new DBException("Error updating row.", 0, $e);
        }

        return $resultSet->mysqli('affected_rows');
    }

    /**
     * Delete rows from a table.
     *
     * @param string $baseTable Table name (without prefix)
     * @param int|array|string $idArrayOrSql WHERE condition (required)
     * @param mixed ...$params Parameters to bind
     * @return int Number of affected rows
     * @throws DBException
     */
    public function delete(string $baseTable, int|array|string $idArrayOrSql, ...$params): int
    {
        $query = new Query(
            mysqli:          $this->mysqli,
            tablePrefix:     $this->tablePrefix,
            primaryKey:      $this->primaryKey,
            useSmartJoins:   $this->useSmartJoins,
            useSmartStrings: $this->useSmartStrings,
            loadHandler:     $this->smartArrayLoadHandler,
            baseTable:       $baseTable,
            params:          $params,
            where:           $idArrayOrSql,
            whereRequired:   true,
        );

        try {
            $resultSet = $query->execute("DELETE FROM `::$query->baseTable` $query->whereEtc");
        } catch (Throwable $e) {
            throw new DBException("Error deleting row.", 0, $e);
        }

        return $resultSet->mysqli('affected_rows');
    }

    /**
     * Count rows in a table.
     *
     * @param string $baseTable Table name (without prefix)
     * @param int|array|string $idArrayOrSql WHERE condition
     * @param mixed ...$params Parameters to bind
     * @return int Row count
     * @throws DBException
     */
    public function count(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): int
    {
        if (is_string($idArrayOrSql) && preg_match('/\b(LIMIT|OFFSET)\s+[0-9:?]+\s*/i', $idArrayOrSql)) {
            throw new InvalidArgumentException("This method doesn't support LIMIT or OFFSET");
        }

        $query = new Query(
            mysqli:          $this->mysqli,
            tablePrefix:     $this->tablePrefix,
            primaryKey:      $this->primaryKey,
            useSmartJoins:   $this->useSmartJoins,
            useSmartStrings: $this->useSmartStrings,
            loadHandler:     $this->smartArrayLoadHandler,
            baseTable:       $baseTable,
            params:          $params,
            where:           $idArrayOrSql,
        );

        try {
            $resultSet = $query->execute("SELECT COUNT(*) FROM `::$query->baseTable` $query->whereEtc");
        } catch (Throwable $e) {
            throw new DBException("Error selecting count.", 0, $e);
        }

        return (int) $resultSet->first()->nth(0)->value();
    }

    //endregion
    //region Table Helpers

    /**
     * Extracts base table name by removing table prefix.
     *
     * @param string $table  Table name with or without prefix
     * @param bool   $strict If true, verifies table exists in database
     * @return string Base table name without prefix
     * @throws DBException
     */
    public function getBaseTable(string $table, bool $strict = false): string
    {
        return match (true) {
            !str_starts_with($table, $this->tablePrefix)  => $table,
            $strict && $this->tableExists($table, false)  => $table,
            default                                       => substr($table, strlen($this->tablePrefix)),
        };
    }

    /**
     * Returns the full table name with the current table prefix.
     *
     * @param string $table  Table name
     * @param bool   $strict If true, verifies table exists in database
     * @return string Full table name with prefix
     * @throws DBException
     */
    public function getFullTable(string $table, bool $strict = false): string
    {
        $prefixedTable = $this->tablePrefix . $table;
        return match (true) {
            $strict && $this->tableExists($prefixedTable, true) => $prefixedTable,
            str_starts_with($table, $this->tablePrefix)         => $table,
            default                                             => $prefixedTable,
        };
    }

    /**
     * Check if table exists in the database.
     *
     * @param string $table Table name
     * @param bool $isFullTable If true, treat as full table name (with prefix)
     * @return bool True if table exists
     * @throws DBException
     */
    public function tableExists(string $table, bool $isFullTable = false): bool
    {
        $fullTable = $isFullTable ? $table : $this->tablePrefix . $table;
        return $this->query("SHOW TABLES LIKE ?", $fullTable)->count() > 0;
    }

    /**
     * Retrieve MySQL table names.
     *
     * @param bool $includePrefix If true, return names with prefix
     * @return string[] Array of table names
     * @throws DBException
     */
    public function getTableNames(bool $includePrefix = false): array
    {
        $likePattern = $this->likeStartsWith($this->tablePrefix);
        $tableNames  = $this->query("SHOW TABLES LIKE ?", $likePattern)->pluckNth(0)->toArray();

        // Sort _tables to the bottom
        $baseOffset = strlen($this->tablePrefix);
        usort($tableNames, fn($a, $b) => ($a[$baseOffset] === '_') <=> ($b[$baseOffset] === '_') ?: ($a <=> $b));

        // Remove prefix
        if (!$includePrefix) {
            $tablePrefixRx = preg_quote($this->tablePrefix, "/");
            $tableNames    = preg_replace("/^$tablePrefixRx/", "", $tableNames);
        }

        return $tableNames;
    }

    /**
     * Get column definitions from a table.
     *
     * @param string $baseTable The base table name without prefix
     * @return array<string,string> Array of column name => column definition pairs
     */
    public function getColumnDefinitions(string $baseTable): array
    {
        $columnDefinitions = [];

        try {
            $createTableSQL = $this->query('SHOW CREATE TABLE `::?`', $baseTable)->first()->nth(1)->value();
            $lines          = explode("\n", $createTableSQL);
        } catch (Throwable) {
            $lines = [];
        }

        // Extract charset/collation from last line (table defaults)
        $defaults = [];
        if (preg_match('/\bDEFAULT CHARSET=(\S+) COLLATE=(\S+)\b/', (string) array_pop($lines), $m)) {
            $defaults = [" CHARACTER SET $m[1]", " COLLATE $m[2]"];
        }

        // Get column definitions
        $intTypesRx = 'tinyint|smallint|mediumint|int|bigint';
        foreach ($lines as $line) {
            if (preg_match('/^  `([^`]+)` (.*?),?$/', $line, $matches)) {
                [, $columnName, $definition] = $matches;
                $definition                     = str_replace($defaults, '', $definition);
                $definition                     = preg_replace("/\b($intTypesRx)\(\d+\)/i", '$1', $definition);
                $columnDefinitions[$columnName] = $definition;
            }
        }

        return $columnDefinitions;
    }

    //endregion
    //region SQL Generation

    /**
     * Escape a string for safe inclusion in raw SQL.
     *
     * @param string|int|float|null|SmartString $input Value to escape
     * @param bool $escapeLikeWildcards Also escape % and _ for LIKE queries
     * @return string Escaped string (without quotes)
     */
    public function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        $input   = $input instanceof SmartString ? $input->value() : $input;
        $string  = (string)$input;
        $escaped = $this->mysqli->real_escape_string($string);
        $escaped = $escapeLikeWildcards ? addcslashes($escaped, '%_') : $escaped;
        return $escaped;
    }

    /**
     * Creates a MySQL LIKE pattern for "column contains value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%value%'
     */
    public function likeContains(string|int|float|null|SmartString $input): RawSql
    {
        return DB::rawSql("'%" . $this->escape($input, true) . "%'");
    }

    /**
     * Creates a MySQL LIKE pattern for matching values in tab-delimited columns.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%\tValue\t%'
     */
    public function likeContainsTSV(string|int|float|null|SmartString $input): RawSql
    {
        return DB::rawSql("'%\\t" . $this->escape($input, true) . "\\t%'");
    }

    /**
     * Creates a MySQL LIKE pattern for "column starts with value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern 'value%'
     */
    public function likeStartsWith(string|int|float|null|SmartString $input): RawSql
    {
        return DB::rawSql("'" . $this->escape($input, true) . "%'");
    }

    /**
     * Creates a MySQL LIKE pattern for "column ends with value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%value'
     */
    public function likeEndsWith(string|int|float|null|SmartString $input): RawSql
    {
        return DB::rawSql("'%" . $this->escape($input, true) . "'");
    }

    //endregion
    //region Clone Support

    /**
     * Clone this connection with optional config overrides.
     * The clone shares the mysqli connection but has its own settings.
     *
     *     $db->clone()                           // Clone with same settings
     *     $db->clone(['useSmartJoins' => false]) // Clone with overrides
     *
     * @param array $config Configuration overrides
     * @return self New Connection instance sharing this connection
     */
    public function clone(array $config = []): self
    {
        $clone = clone $this;
        foreach ($config as $key => $value) {
            if (!property_exists($clone, $key)) {
                throw new InvalidArgumentException("Unknown configuration key: '$key'");
            }
            $clone->$key = $value;
        }
        return $clone;
    }

    /**
     * Mark cloned connections as non-owners.
     */
    public function __clone(): void
    {
        $this->ownsConnection = false;
    }

    //endregion
    //region Magic Methods

    /**
     * Clean up connection on destruction.
     */
    public function __destruct()
    {
        // Only close connection if we own it
        if ($this->ownsConnection && $this->mysqli instanceof mysqli) {
            try {
                // Drain any extra result sets
                while ($this->mysqli->more_results() && $this->mysqli->next_result()) {
                    // Drain
                }
                $this->mysqli->close();
            } catch (Throwable) {
                // Never throw from a destructor
            }
            $this->mysqli = null;
        }
    }

    //endregion
    //region Connection State

    /**
     * The mysqli connection - exposed for backwards compatibility
     */
    public ?MysqliWrapper $mysqli = null;

    /**
     * Whether this instance owns (and should close) the mysqli connection.
     * Set to false for clones which share the connection.
     */
    private bool $ownsConnection = true;

    //endregion
    //region Settings - Public Properties

    // Connection settings (used during connect)
    public ?string $hostname = null;
    public ?string $username = null;
    public ?string $password = null;
    public ?string $database = null;
    public string  $tablePrefix = '';
    public string  $primaryKey = 'num';

    // Query behavior settings
    public bool $useSmartJoins   = true;
    public bool $useSmartStrings = true;
    public bool $usePhpTimezone  = true;

    // Result handling
    /** @var callable|null Custom handler for loading results */
    public mixed $smartArrayLoadHandler = null;

    // Error handling
    /** @var bool|callable Show SQL in exceptions - true, false, or callable returning bool */
    public mixed $showSqlInErrors = false;

    // Advanced connection settings
    public string $versionRequired    = '5.7.32';
    public bool   $requireSSL         = false;
    public bool   $databaseAutoCreate = true;
    public int    $connectTimeout     = 3;
    public int    $readTimeout        = 60;
    public bool   $enableLogging      = false;
    public string $logFile            = '_mysql_query_log.php';
    public string $sqlMode            = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    // Debug mode - tracks queries for debug output
    public bool $debugMode = false;

    /** @var callable|null Callback that returns web root path for relative file paths in debug output */
    public mixed $webRootCallback = null;

    //endregion
}
