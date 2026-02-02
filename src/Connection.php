<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
use mysqli;
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
    use ConnectionInternals;

    //region Connection

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

    //endregion
    //region Query Methods

    /**
     * Execute a raw SQL query and return results.
     *
     * @param string $sqlTemplate
     * @param mixed  ...$params Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws DBException|Throwable
     */
    public function query(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        // Validate
        if (!preg_match('/^\s*([a-zA-Z]+)\b/', $sqlTemplate)) {
            throw new InvalidArgumentException("SQL statement must start with a valid SQL keyword such as SELECT, INSERT, etc.");
        }
        $this->assertSafeTemplate($sqlTemplate);

        // Build SQL
        $sql = $this->replacePlaceholders($sqlTemplate, $params);

        // Execute
        $result = $this->mysqli->query($sql);
        $rows   = $this->fetchMappedRows($result);

        return $this->toSmartArray($rows, $sql);
    }

    /**
     * Select rows from a table.
     *
     * @param string       $baseTable Table name (without prefix)
     * @param int|array|string $where     WHERE condition
     * @param mixed            ...$params Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws DBException
     * @throws Throwable
     */
    public function select(string $baseTable, int|array|string $where = [], ...$params): SmartArrayHtml
    {
        // Validate
        $this->assertValidTable($baseTable);
        $this->rejectNumericWhere($where);

        // Build SQL
        $fullTable = $this->tablePrefix . $baseTable;
        $whereEtc  = $this->whereFromArgs($where, $params);
        $sql       = "SELECT * FROM `$fullTable` $whereEtc";

        // Execute
        $result = $this->mysqli->query($sql);
        $rows   = $this->fetchMappedRows($result);

        return $this->toSmartArray($rows, $sql, $baseTable);
    }

    /**
     * Get a single row from a table.
     *
     * @param string           $baseTable Table name (without prefix)
     * @param int|array|string $where     WHERE condition
     * @param mixed            ...$params Parameters to bind
     * @return SmartArrayHtml Single row or empty SmartArrayHtml
     * @throws DBException|Throwable
     */
    public function get(string $baseTable, int|array|string $where = [], ...$params): SmartArrayHtml
    {
        // Validate
        $this->assertValidTable($baseTable);
        $this->rejectNumericWhere($where);
        $this->rejectLimitAndOffset($where);

        // Build SQL
        $fullTable = $this->tablePrefix . $baseTable;
        $whereEtc  = $this->whereFromArgs($where, $params);
        $sql       = "SELECT * FROM `$fullTable` $whereEtc LIMIT 1";

        // Execute
        $result    = $this->mysqli->query($sql);
        $rows      = $this->fetchMappedRows($result);
        $resultSet = $this->toSmartArray($rows, $sql, $baseTable);

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
        // Validate
        $this->assertValidTable($baseTable);

        // Build SQL
        $fullTable = $this->tablePrefix . $baseTable;
        $setClause = $this->getSetClause($colsToValues);
        $sql       = "INSERT INTO `$fullTable` $setClause";

        // Execute
        $this->mysqli->query($sql);

        return $this->mysqli->insert_id;
    }

    /**
     * Update rows in a table.
     *
     * @param string       $baseTable    Table name (without prefix)
     * @param array        $colsToValues Column => value pairs to update
     * @param int|array|string $where        WHERE condition (required)
     * @param mixed            ...$params    Parameters to bind
     * @return int Number of affected rows
     * @throws DBException|Throwable
     */
    public function update(string $baseTable, array $colsToValues, int|array|string $where, ...$params): int
    {
        $this->assertValidTable($baseTable);
        $this->rejectNumericWhere($where);
        $this->rejectEmptyWhere($where, 'UPDATE');

        $fullTable = $this->tablePrefix . $baseTable;
        $setClause = $this->getSetClause($colsToValues);
        $whereEtc  = $this->whereFromArgs($where, $params);
        $sql       = "UPDATE `$fullTable` $setClause $whereEtc";
        $this->mysqli->query($sql);

        return $this->mysqli->affected_rows;
    }

    /**
     * Delete rows from a table.
     *
     * @param string       $baseTable Table name (without prefix)
     * @param int|array|string $where     WHERE condition (required)
     * @param mixed            ...$params Parameters to bind
     * @return int Number of affected rows
     * @throws DBException
     */
    public function delete(string $baseTable, int|array|string $where, ...$params): int
    {
        $this->assertValidTable($baseTable);
        $this->rejectNumericWhere($where);
        $this->rejectEmptyWhere($where, 'DELETE');

        $fullTable = $this->tablePrefix . $baseTable;
        $whereEtc  = $this->whereFromArgs($where, $params);
        $sql       = "DELETE FROM `$fullTable` $whereEtc";
        $this->mysqli->query($sql);

        return $this->mysqli->affected_rows;
    }

    /**
     * Count rows in a table.
     *
     * @param string       $baseTable Table name (without prefix)
     * @param int|array|string $where     WHERE condition
     * @param mixed            ...$params Parameters to bind
     * @return int Row count
     * @throws DBException|Throwable
     */
    public function count(string $baseTable, int|array|string $where = [], ...$params): int
    {
        $this->assertValidTable($baseTable);
        $this->rejectNumericWhere($where);
        $this->rejectLimitAndOffset($where);

        $fullTable = $this->tablePrefix . $baseTable;
        $whereEtc  = $this->whereFromArgs($where, $params);
        $sql       = "SELECT COUNT(*) FROM `$fullTable` $whereEtc";
        $result    = $this->mysqli->query($sql);
        $row       = $result->fetch_row();
        $result->free();

        return (int) $row[0];
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
     * @param string $table       Table name
     * @param bool   $isFullTable If true, treat as full table name (with prefix)
     * @return bool True if table exists
     * @throws DBException|Throwable
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
     * @throws DBException|Throwable
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
    //region Public Properties

    /**
     * The mysqli connection instance
     */
    public ?MysqliWrapper $mysqli = null;

    /**
     * Table prefix prepended to table names (e.g., 'cms_')
     */
    public string $tablePrefix = '';

    //endregion
}
