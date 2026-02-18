<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayBase;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
use RuntimeException;
use Throwable;
use mysqli;
use mysqli_sql_exception;

/**
 * Connection class for ZenDB - manages a single database connection with its own settings.
 *
 * Usage:
 *     // Create default connection (use DB::connect)
 *     DB::connect(['hostname' => 'localhost', 'database' => 'my_db', ...]);
 *
 *     // Create standalone connection
 *     $remote = new Connection(['hostname' => 'remote.example.com', 'database' => 'legacy']);
 *
 *     // Clone with different settings
 *     $db = DB::clone(['useSmartJoins' => false]);
 */
class Connection
{
    use ConnectionInternals;

    private bool $ownsConnection = true;

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
    //region Connection

    /**
     * Create a new database connection.
     *
     *     DB::connect($config)      // Create and set as default connection
     *     new Connection($config)   // Create standalone connection
     *
     * @param array $config Configuration options:
     *     - `hostname`              string   Database server hostname
     *     - `username`              string   Database username
     *     - `password`              string   Database password
     *     - `database`              string   Database name
     *     - `tablePrefix`           string   Prefix for table names (default: '')
     *     - `useSmartJoins`         bool     Add table.column keys to JOIN results (default: true)
     *     - `useSmartStrings`       bool     Return SmartString values (default: true)
     *     - `usePhpTimezone`        bool     Sync MySQL timezone with PHP (default: true)
     *     - `smartArrayLoadHandler` callable Custom result loading handler
     *     - `versionRequired`       string   Minimum MySQL version (default: '5.7.32')
     *     - `requireSSL`            bool     Require SSL connection (default: false)
     *     - `databaseAutoCreate`    bool     Create database if missing (default: false)
     *     - `connectTimeout`        int      Connection timeout in seconds (default: 3)
     *     - `readTimeout`           int      Read timeout in seconds (default: 60)
     *     - `queryLogger`           callable fn(string $query, float $secs, ?Throwable $error)
     *     - `sqlMode`               string   MySQL SQL mode
     */
    public function __construct(array $config = [])
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
    }

    /**
     * Connect to the database using the configured settings.
     *
     * @throws RuntimeException If connection fails or version requirements not met
     */
    public function connect(): void
    {
        // Throw if already connected
        if ($this->isConnected()) {
            throw new RuntimeException("Already connected. To reconnect, call disconnect() first.");
        }

        // Validate required connection credentials
        if ($this->hostname === null || $this->username === null) {
            throw new InvalidArgumentException("Missing required database credentials: hostname and username must be set");
        }

        // Create a new mysqli instance
        $this->mysqli = new MysqliWrapper(queryLogger: $this->queryLogger);
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
                    if (!preg_match('/^[\w-]+$/', $this->database)) {
                        throw new InvalidArgumentException("Invalid database name '$this->database', allowed characters: a-z, A-Z, 0-9, _, -");
                    }
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
     * Disconnect from the database if it owns the connection (not a clone).
     */
    public function disconnect(): void
    {
        if ($this->mysqli instanceof mysqli) {
            if ($this->ownsConnection) {
                $this->mysqli->close();
            }
            $this->mysqli = null;
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
        $clone->ownsConnection = false;  // Clones don't own the connection
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
     * @throws InvalidArgumentException
     */
    public function query(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        // Validate
        $this->assertSafeTemplate($sqlTemplate);

        // Build SQL
        $sql = $this->replacePlaceholders($sqlTemplate, $params);

        // Execute
        $result = $this->mysqli->query($sql);
        $rows   = $this->fetchMappedRows($result);

        return $this->toSmartArray($rows, $sql);
    }

    /**
     * Execute a raw SQL query and return the first row.
     *
     *     $row = DB::queryOne("SELECT name, email FROM ::users WHERE num = ?", 5);
     *     echo "$row->name - $row->email";
     *
     *     $row = DB::queryOne("SELECT MAX(price) AS max_price FROM ::products");
     *     echo $row->max_price;
     *
     * @param string $sqlTemplate SQL statement with placeholders
     * @param mixed  ...$params   Parameters to bind
     * @return SmartArrayHtml First row, or empty SmartArrayHtml if no rows
     * @throws InvalidArgumentException
     */
    public function queryOne(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        $this->rejectLimitAndOffset($sqlTemplate);

        $resultSet = $this->query("$sqlTemplate LIMIT 1", ...$params);

        return $resultSet->first()->asHtml(); // asHtml() ensures SmartNull from empty results becomes SmartArrayHtml
    }

    /**
     * Select rows from a table.
     *
     * @param string           $baseTable  Table name (without prefix)
     * @param int|array|string $whereEtc   WHERE and other clauses (ORDER BY, LIMIT, etc.)
     * @param mixed            ...$params  Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws InvalidArgumentException
     */
    public function select(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        // Validate
        $this->assertValidTable($baseTable);
        $this->warnDeprecatedNumericWhere($whereEtc);

        // Build SQL
        $fullTable   = $this->tablePrefix . $baseTable;
        $whereClauses = $this->whereFromArgs($whereEtc, $params);
        $sql         = "SELECT * FROM `$fullTable` $whereClauses";

        // Execute
        $result = $this->mysqli->query($sql);
        $rows   = $this->fetchMappedRows($result);

        return $this->toSmartArray($rows, $sql, $baseTable);
    }

    /**
     * Select a single row from a table. Always sends LIMIT 1 to MySQL.
     *
     *     $user = DB::selectOne('users', ['num' => 5]);
     *     echo "$user->name - $user->email";
     *
     *     $user = DB::selectOne('users', "status = ?", 'Active');
     *
     * @param string           $baseTable  Table name (without prefix)
     * @param int|array|string $whereEtc   WHERE and other clauses
     * @param mixed            ...$params  Parameters to bind
     * @return SmartArrayHtml Single row or empty SmartArrayHtml
     * @throws InvalidArgumentException
     */
    public function selectOne(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        $this->assertValidTable($baseTable);
        $this->warnDeprecatedNumericWhere($whereEtc);
        $this->rejectLimitAndOffset($whereEtc);

        $fullTable    = $this->tablePrefix . $baseTable;
        $whereClauses = $this->whereFromArgs($whereEtc, $params);
        $sql          = "SELECT * FROM `$fullTable` $whereClauses LIMIT 1";

        $result    = $this->mysqli->query($sql);
        $rows      = $this->fetchMappedRows($result);
        $resultSet = $this->toSmartArray($rows, $sql, $baseTable);

        return $resultSet->first()->asHtml(); // asHtml() ensures SmartNull from empty results becomes SmartArrayHtml
    }

    /**
     * Insert a row into a table.
     *
     * @param string $baseTable Table name (without prefix)
     * @param array $colsToValues Column => value pairs
     * @return int Insert ID
     * @throws InvalidArgumentException
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
     * @param string           $baseTable    Table name (without prefix)
     * @param array            $colsToValues Column => value pairs to update
     * @param int|array|string $whereEtc     WHERE condition (required), may include ORDER BY, LIMIT
     * @param mixed            ...$params    Parameters to bind
     * @return int Number of affected rows
     * @throws InvalidArgumentException
     */
    public function update(string $baseTable, array $colsToValues, int|array|string $whereEtc, ...$params): int
    {
        $this->assertValidTable($baseTable);
        $this->warnDeprecatedNumericWhere($whereEtc);
        $this->rejectEmptyWhere($whereEtc, 'UPDATE');

        // Detect likely reversed arguments: SET ['num' => 5] is almost always a mistake
        if (count($colsToValues) === 1 && in_array(array_key_first($colsToValues), ['num', 'id', 'ID'], true)) {
            throw new InvalidArgumentException("Suspicious SET clause: only updating '" . array_key_first($colsToValues) . "'. Did you reverse the arguments? Signature is: update(\$table, \$colsToValues, \$whereEtc)");
        }

        $fullTable    = $this->tablePrefix . $baseTable;
        $setClause    = $this->getSetClause($colsToValues);
        $whereClauses = $this->whereFromArgs($whereEtc, $params);
        $sql          = "UPDATE `$fullTable` $setClause $whereClauses";
        $this->mysqli->query($sql);

        return $this->mysqli->affected_rows;
    }

    /**
     * Delete rows from a table.
     *
     * @param string           $baseTable  Table name (without prefix)
     * @param int|array|string $whereEtc   WHERE condition (required), may include ORDER BY, LIMIT
     * @param mixed            ...$params  Parameters to bind
     * @return int Number of affected rows
     * @throws InvalidArgumentException
     */
    public function delete(string $baseTable, int|array|string $whereEtc, ...$params): int
    {
        $this->assertValidTable($baseTable);
        $this->warnDeprecatedNumericWhere($whereEtc);
        $this->rejectEmptyWhere($whereEtc, 'DELETE');

        $fullTable    = $this->tablePrefix . $baseTable;
        $whereClauses = $this->whereFromArgs($whereEtc, $params);
        $sql          = "DELETE FROM `$fullTable` $whereClauses";
        $this->mysqli->query($sql);

        return $this->mysqli->affected_rows;
    }

    /**
     * Count rows in a table.
     *
     * @param string           $baseTable  Table name (without prefix)
     * @param int|array|string $whereEtc   WHERE and other clauses (but not LIMIT/OFFSET)
     * @param mixed            ...$params  Parameters to bind
     * @return int Row count
     * @throws InvalidArgumentException
     */
    public function count(string $baseTable, int|array|string $whereEtc = [], ...$params): int
    {
        $this->assertValidTable($baseTable);
        $this->warnDeprecatedNumericWhere($whereEtc);
        $this->rejectLimitAndOffset($whereEtc);

        $fullTable    = $this->tablePrefix . $baseTable;
        $whereClauses = $this->whereFromArgs($whereEtc, $params);
        $sql          = "SELECT COUNT(*) FROM `$fullTable` $whereClauses";
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
     * @throws InvalidArgumentException
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
     * @throws InvalidArgumentException
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
     * Check if a permanent (non-temporary) table exists in the database.
     *
     * Uses INFORMATION_SCHEMA.TABLES instead of SHOW TABLES LIKE for two reasons:
     * - MariaDB 11.2-11.2.3, 11.3.x, and 11.4.0-11.4.1 have a bug (MDEV-32973)
     *   where SHOW TABLES LIKE ignores the LIKE pattern for temporary tables,
     *   returning false positives for any pattern when a temp table exists.
     * - SHOW TABLES LIKE doesn't escape _ and % wildcards, so table names
     *   containing underscores (common with prefixes) match unintended tables.
     *
     * Note: This method does not detect temporary tables. MySQL doesn't
     * expose them in INFORMATION_SCHEMA, and MariaDB only added support in
     * 11.2+ (with TABLE_TYPE = 'TEMPORARY').
     *
     * @param string $table       Table name
     * @param bool   $isFullTable If true, treat as full table name (with prefix)
     * @return bool True if table exists
     * @throws InvalidArgumentException
     *
     * @see https://jira.mariadb.org/browse/MDEV-32973
     */
    public function tableExists(string $table, bool $isFullTable = false): bool
    {
        $fullTable = $isFullTable ? $table : $this->tablePrefix . $table;
        $result    = $this->mysqli->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '" . $this->mysqli->real_escape_string($fullTable) . "'"
            . " AND TABLE_TYPE = 'BASE TABLE'"
            . " LIMIT 1"
        );
        $exists = $result->num_rows > 0;
        $result->free();

        return $exists;
    }

    /**
     * Retrieve MySQL table names.
     *
     * @param bool $includePrefix If true, return names with prefix
     * @return string[] Array of table names
     * @throws InvalidArgumentException
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
        } catch (mysqli_sql_exception) {
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
            if (preg_match('/^ {2}`([^`]+)` (.*?),?$/', $line, $matches)) {
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
        // Unwrap SmartString
        if ($input instanceof SmartString) {
            $input = $input->value();
        }

        // Escape using mysqli
        $escaped = $this->mysqli->real_escape_string((string)$input);

        // Escape LIKE wildcards if needed
        if ($escapeLikeWildcards) {
            $escaped = addcslashes($escaped, '%_');
        }

        return $escaped;
    }

    /**
     * Escapes and quotes values, inserting them into a format string with ? placeholders.
     *
     * @param string $format    Format string with ? placeholders
     * @param mixed  ...$values Values to escape and insert
     * @return string SQL-safe string
     * @throws InvalidArgumentException
     */
    public function escapef(string $format, mixed ...$values): string
    {
        $this->mysqli || throw new RuntimeException(__METHOD__ . "() called before DB connection established");

        return preg_replace_callback('/\?/', function () use (&$values) {
            $value = array_shift($values);

            return match (true) {
                is_string($value)                => "'" . $this->mysqli->real_escape_string($value) . "'",
                is_int($value), is_float($value) => $value,
                is_null($value)                  => 'NULL',
                is_array($value)                 => (string) $this->escapeCSV($value),
                $value instanceof SmartArrayBase => (string) $this->escapeCSV($value->toArray()),
                $value instanceof SmartString    => "'" . $this->mysqli->real_escape_string((string) $value->value()) . "'",
                is_bool($value)                  => $value ? 'TRUE' : 'FALSE',
                default                          => throw new InvalidArgumentException("Unsupported type: " . get_debug_type($value)),
            };
        }, $format);
    }

    /**
     * Converts array values to a safe CSV string for use in MySQL IN clauses.
     *
     * Tip: You probably don't need this! Named placeholders handle arrays
     * automatically, which is simpler and keeps your values parameterized:
     *
     *     // Instead of this:
     *     DB::select('users', "id IN (?)", DB::escapeCSV([1, 2, 3]));
     *
     *     // Do this:
     *     DB::select('users', "id IN (:ids)", [
     *         ':ids' => [1, 2, 3],
     *     ]);
     *
     * @param array $array Array of values to convert
     * @return RawSql SQL-safe comma-separated list
     * @throws InvalidArgumentException
     */
    public function escapeCSV(array $array): RawSql
    {
        $this->mysqli || throw new RuntimeException(__METHOD__ . "() called before DB connection established");

        $safeValues = [];
        foreach (array_unique($array) as $value) {
            $value        = $value instanceof SmartString ? (string) $value->value() : $value;
            $safeValues[] = match (true) {
                is_int($value) || is_float($value) => $value,
                is_null($value)                    => 'NULL',
                is_bool($value)                    => $value ? 'TRUE' : 'FALSE',
                is_string($value)                  => "'" . $this->mysqli->real_escape_string($value) . "'",
                default                            => throw new InvalidArgumentException("Unsupported value type: " . get_debug_type($value)),
            };
        }

        $sqlSafeCSV = $safeValues ? implode(',', $safeValues) : 'NULL';
        return new RawSql($sqlSafeCSV);
    }

    /**
     * Creates a MySQL LIKE pattern for "column contains value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%value%'
     */
    public function likeContains(string|int|float|null|SmartString $input): RawSql
    {
        return new RawSql("'%" . $this->escape($input, true) . "%'");
    }

    /**
     * Creates a MySQL LIKE pattern for matching values in tab-delimited columns.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%\tValue\t%'
     */
    public function likeContainsTSV(string|int|float|null|SmartString $input): RawSql
    {
        return new RawSql("'%\\t" . $this->escape($input, true) . "\\t%'");
    }

    /**
     * Creates a MySQL LIKE pattern for "column starts with value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern 'value%'
     */
    public function likeStartsWith(string|int|float|null|SmartString $input): RawSql
    {
        return new RawSql("'" . $this->escape($input, true) . "%'");
    }

    /**
     * Creates a MySQL LIKE pattern for "column ends with value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%value'
     */
    public function likeEndsWith(string|int|float|null|SmartString $input): RawSql
    {
        return new RawSql("'%" . $this->escape($input, true) . "'");
    }

    //endregion
}
