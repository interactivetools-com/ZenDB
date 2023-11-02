<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use DateTime, DateTimeZone;
use Itools\SmartArray\SmartArray;
use Itools\SmartString\SmartString;
use mysqli;
use Throwable, InvalidArgumentException, RuntimeException, Exception;
use Itools\ZenDB\QueryExecutor;
use Itools\ZenDB\Parser;

/**
 * DB is a wrapper for mysqli that provides a simple, secure, and consistent interface for database access.
 */
class DB
{
    public static string     $tablePrefix     = '';                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              // table prefix, set by config()
    public static ?mysqli    $mysqli          = null;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    // mysqli object
    private static ?Config   $config          = null;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    // config instance
    private static ?Instance $defaultInstance = null;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    // default instance for static methods
    /**
     * Parser instance for backward compatibility with tests.
     * Tests access this through reflection.
     * @var Parser|null
     */
    public static ?Parser $parser = null;

    #region Config & Connection

    /**
     * Configure database configuration settings.
     *
     * This is a convenience wrapper around the Config class methods.
     *
     * - To get the entire config: self::config()
     * - To get a single value: self::config('key')
     * - To set a single value: self::config('key', 'value')
     * - To set multiple values: self::config(['key1' => 'value1', 'key2' => 'value2'])
     * - To set configuration from a Config object: self::config($configObj)
     *
     * @param string|array|Config|null $keyOrArrayOrConfig Key to retrieve, key-value pairs to set, or a Config object.
     * @param mixed $keyValue Value to set for the given key. Ignored if first parameter is an array or Config.
     *
     * @return mixed The requested configuration value, array of all settings, or null after setting values.
     */
    public static function config(string|array|Config|null $keyOrArrayOrConfig = null, mixed $keyValue = null): mixed
    {
        self::$config ??= new Config();  // Initialize config instance if not already created
        $argCount     = func_num_args();

        // Get all values
        if ($argCount === 0) {
            return get_object_vars(self::$config);
        }

        // Get single value
        if ($argCount === 1 && is_string($keyOrArrayOrConfig)) {
            if (!property_exists(self::$config, $keyOrArrayOrConfig)) {
                throw new InvalidArgumentException("Invalid configuration key: $keyOrArrayOrConfig");
            }
            return self::$config->$keyOrArrayOrConfig;
        }

        // For setting values, map the inputs to key-value pairs
        $keysToValues = match (true) {
            is_array($keyOrArrayOrConfig)         => $keyOrArrayOrConfig,
            is_string($keyOrArrayOrConfig)        => [$keyOrArrayOrConfig => $keyValue],
            $keyOrArrayOrConfig instanceof Config => get_object_vars($keyOrArrayOrConfig),
            default                               => throw new InvalidArgumentException("Invalid arguments for config() method"),
        };

        // Validate and set all properties
        foreach ($keysToValues as $key => $value) {
            if (!property_exists(self::$config, $key)) {
                throw new InvalidArgumentException("Invalid configuration key: $key");
            }
            self::$config->$key = $value;
        }

        // Update table prefix alias property in case it changed
        self::$tablePrefix = &self::$config->tablePrefix;

        return null;
    }

    /**
     * @throws Exception
     */
    public static function connect(): void
    {
        if (!isset(self::$config)) {
            throw new RuntimeException("No config, call DB::config() before DB::connect()");
        }

        if (self::$defaultInstance !== null) {
            return;
        }

        $connection            = new Connection(self::$config);
        self::$mysqli          = $connection->mysqli;
        self::$defaultInstance = new Instance(self::$config);
    }

    /**
     * Check if database connection was made and optionally check if it's still active.
     *
     * This method verifies if the MySQLi connection is established.
     * If $doPing is true, it will ping the database server to confirm the connection is still alive.
     *
     *  Consider using $doPing for:
     *  - Long-running scripts where the connection might time out
     *  - When the application has been idle for an extended period
     *  - Other factors may have caused you to lose the connection
     *
     * @param bool $doPing Whether to ping the server to check for active connection. Default is false.
     * @return bool True if the connection is valid (and responsive if $doPing is true), false otherwise.
     */
    public static function isConnected(bool $doPing = false): bool
    {
        if (self::$mysqli === null) {
            return false;
        }

        return match (true) {
            self::$mysqli->connect_errno,                                      // Connection attempted but failed
            empty(self::$mysqli->host_info) => false,                           // Connection not attempted yet
            $doPing                         => self::$mysqli->stat() !== false, // Replacement for deprecated ping()
            default                         => true,
        };
    }

    /**
     * Close the database connection.
     *
     * @return void
     */
    public static function disconnect(): void
    {
        self::$tablePrefix = '';
        self::$mysqli?->close();
        self::$mysqli          = null;
        self::$config          = null;
        self::$defaultInstance = null;
        self::$parser          = null;
    }

    #endregion
    #region Query Methods

    /**
     * Executes an SQL query and returns a ResultSet object. Static factory function
     *
     * @param string $sqlTemplate SQL statement template with placeholders for parameters.
     * @param mixed ...$params Optional prepared statement parameters to be bound to the SQL template.
     *
     * @return SmartArray Returns a ResultSet object containing the results or status of the query.
     *
     * @throws DBException
     * @example DB::query("SELECT * FROM `accounts` WHERE  name = :name AND date <= :targetDate", $params);
     */
    public static function query(string $sqlTemplate, ...$params): SmartArray
    {
        return self::getDefaultInstance()->query($sqlTemplate, ...$params);
    }

    /**
     * Executes an SQL SELECT query and returns a ResultSet object. Static factory function.
     *
     * @param string $baseTable The base table name for the query.
     * @param int|array|string $idArrayOrSql Optional array of IDs, SQL condition string, or empty (to select all rows).
     * @param mixed ...$params Optional prepared statement parameters to be bound to the SQL template.
     *
     * @return SmartArray Returns a ResultSet object containing the results or status of the query.
     *
     * @throws DBException
     * @example
     * DB::select("accounts"); // Selects all rows from the "accounts" table.
     * DB::select("accounts", 123); // Selects rows with ID 123 from the "accounts" table.
     * DB::select("accounts", ['city' => 'New York']); // Selects rows from the "accounts" table where the city is "New York".
     * DB::select("accounts", "WHERE balance > 1000"); // Selects rows from the "accounts" table where the balance is greater than 1000.
     */
    public static function select(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArray
    {
        return self::getDefaultInstance()->select($baseTable, $idArrayOrSql, ...$params);
    }

    /**
     * Retrieves a single row from the specified table based on the given conditions and parameters.
     *
     * @param string $baseTable The base table name to select from.
     * @param int|array|string $idArrayOrSql The condition(s) to match when selecting the row. Can be an ID, an array of IDs, or a custom SQL query.
     * @param mixed ...$params Optional prepared statement parameters to be bound to the SQL template.
     *
     * @return SmartArray Returns a Row object representing the first matching row, or null if no row is found.
     *
     * @throws DBException If an error occurs while retrieving the row.
     * @example
     * DB::get("users", 1); // Retrieve a row with the ID 1 from the "users" table
     * DB::get("orders", ["id" => 123]); // Retrieve a row from the "orders" table where the ID is 123
     * DB::get("products", "price > :price", ["price" => 50]); // Retrieve a row from the "products" table based on a custom SQL query
     */
    public static function get(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArray
    {
        return self::getDefaultInstance()->get($baseTable, $idArrayOrSql, ...$params);
    }

    /**
     * Insert rows into a database table using the provided column-value pairs.
     *
     * @param string $baseTable The name of the base table where the rows will be inserted.
     * @param array $colsToValues An associative array mapping column names to their corresponding values.
     *
     * @return int                           The id of the inserted record
     * @throws DBException                   If there is an error inserting the row.
     *
     * @example DB::insert("accounts", ["name" => "John Doe", "age" => 30]);
     */
    public static function insert(string $baseTable, array $colsToValues): int
    {
        return self::getDefaultInstance()->insert($baseTable, $colsToValues);
    }

    /**
     * Updates records in a table based on conditions and returns affected rows.  Note that if you update a
     * row with the same values, it won't count as an affected row.
     *
     * @param string $baseTable The name of the table to update.
     * @param array $colsToValues Associative array representing columns and their new values.
     * @param int|array|string $idArrayOrSql An array of conditions or a raw SQL condition string.
     * @param mixed ...$params Optional additional prepared statement parameters.
     *
     * @return int  Returns the number of affected rows.
     *
     * @throws DBException
     * @example DB::update('accounts', $colsToValues, "WHERE num = ? AND city = :city", ...$params);
     * @example DB::update('accounts', $colsToValues, ['num' => $num]);
     */
    public static function update(string $baseTable, array $colsToValues, int|array|string $idArrayOrSql, ...$params): int
    {
        return self::getDefaultInstance()->update($baseTable, $colsToValues, $idArrayOrSql, ...$params);
    }

    /**
     * Deletes a record or records from a table.
     *
     * @param string $baseTable
     * @param int|array|string $idArrayOrSql
     * @param mixed ...$params Optional additional prepared statement parameters.
     *
     * @return int Returns the number of affected rows, or 0 if no rows were deleted, or -1 if there was an error.
     *
     * @throws DBException
     * @example DB::delete('accounts', ['num' => $num]);
     * @example DB::delete('accounts', "WHERE num = ? AND city = :city", ...$params);
     */
    public static function delete(string $baseTable, int|array|string $idArrayOrSql, ...$params): int
    {
        return self::getDefaultInstance()->delete($baseTable, $idArrayOrSql, ...$params);
    }

    /**
     * Returns the count of rows in a table matching the given conditions.
     *
     * @param string $baseTable
     * @param int|array|string $idArrayOrSql
     * @param ...$params
     * @return int
     * @throws DBException
     */
    public static function count(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): int
    {
        return self::getDefaultInstance()->count($baseTable, $idArrayOrSql, ...$params);
    }

    #endregion


    #region Table Helpers

    /**
     * Extracts base table name by removing table prefix, optionally verifying table exists.
     *
     * @param string $table Table name with or without prefix
     * @param bool $strict If true, verifies table exists in database, only needed if table name may contain double prefix
     * @return string Base table name without prefix
     * @throws DBException
     */
    public static function getBaseTable(string $table, bool $strict = false): string
    {
        return match (true) {
            !str_starts_with($table, self::$tablePrefix) => $table,                                     // doesn't start with prefix
            $strict && self::tableExists($table, false)  => $table,                                     // exists as baseTable already, meaning there's a double prefix, e.g., 'cms_cms_table'
            default                                      => substr($table, strlen(self::$tablePrefix)), // remove prefix
        };
    }

    /**
     * Returns the full table name with the current table prefix.  Optionally verifies table exists.
     *
     * @param bool $strict If true, verifies table exists in database, only needed if table name may contain double prefix
     * @throws DBException
     */
    public static function getFullTable(string $table, bool $strict = false): string
    {
        return match (true) {
            $strict && !self::tableExists($table, true) => self::$tablePrefix . $table,  // doesn't exist, add prefix.  Catches double prefix, e.g., 'cms_cms_table'
            str_starts_with($table, self::$tablePrefix) => $table,                       // already starts with prefix, return as is
            default                                     => self::$tablePrefix . $table,  // doesn't start with prefix, add prefix
        };
    }

    /**
     * Check if table exists in the database.  Assumes baseTable unless isFullTable is true.
     *
     * @usage DB::tableExists('tableName');
     *
     * @param string $table
     * @param bool $isFullTable
     * @return bool
     * @throws DBException
     */
    public static function tableExists(string $table, bool $isFullTable = false): bool
    {
        $fullTable = $isFullTable ? $table : self::$tablePrefix . $table;
        return self::query("SHOW TABLES LIKE ?", $fullTable)->count() > 0;
    }

    /**
     * Retrieve MySQL table names without the current table prefix.
     *
     * @return string[] Array of table names.
     * @throws DBException
     */
    public static function getTableNames(bool $includePrefix = false): array
    {
        // get tables that start with the current table prefix
        $likePattern = self::likeStartsWith(self::$tablePrefix);
        $tableNames  = self::query("SHOW TABLES LIKE ?", $likePattern)->pluckNth(0)->toArray();

        // sort _tables to the bottom
        $baseOffset = strlen(self::$tablePrefix); // get baseTable offset, so we can check if first char is _
        usort($tableNames, fn($a, $b) => ($a[$baseOffset] === '_') <=> ($b[$baseOffset] === '_') ?: ($a <=> $b));

        // remove prefix
        if (!$includePrefix) {
            $tablePrefixRx = preg_quote(self::$tablePrefix, "/");
            $tableNames    = preg_replace("/^$tablePrefixRx/", "", $tableNames);
        }

        //
        return $tableNames;
    }

    /**
     * Get column definitions from a table, excluding redundant charset/collation settings.
     *
     * @param string $baseTable The base table name without prefix
     * @return array<string,string> Array of column name => column definition pairs
     */
    public static function getColumnDefinitions(string $baseTable): array
    {
        $columnDefinitions = [];
        try {  // mysql throws an error if table doesn't exist
            $createTableSQL = self::query('SHOW CREATE TABLE `::?`', $baseTable)->first()->nth(1)->value();
            $lines          = explode("\n", $createTableSQL);
        } catch (Throwable $e) {
            $lines = [];
        }

        // Extract charset/collation from last line (table defaults)
        $defaults = [];
        if (preg_match('/\bDEFAULT CHARSET=(\S+) COLLATE=(\S+)\b/', (string)array_pop($lines), $m)) {
            $defaults = [" CHARACTER SET $m[1]", " COLLATE $m[2]"];
        }

        // get column definitions
        foreach ($lines as $line) {
            if (preg_match('/^  `([^`]+)` (.*?),?$/', $line, $matches)) {
                [, $columnName, $definition] = $matches;
                $definition                     = str_replace($defaults, '', $definition);             // remove redundant column values that match table defaults
                $definition                     = preg_replace("/(int)\(\d*\)/i", "$1", $definition);  // replace int(displayWidth) with int (displayWidth removed in MySQL 8)
                $columnDefinitions[$columnName] = $definition;
            }
        }

        return $columnDefinitions;
    }

    #endregion
    #region SQL Generation

    /**
     * Returns a string that has been escaped for safe inclusion in raw SQL statements.  Does NOT add quotes.
     *
     * Essentially a shortcut for $mysqli->real_escape_string() that also supports SmartString input.
     */
    public static function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        return self::getDefaultInstance()->escape($input, $escapeLikeWildcards);
    }

    /**
     * Creates a MySQL LIKE pattern for "column contains value" searches, e.g., '%value%'.
     * Escapes MySQL special characters and LIKE wildcards. Returns rawSQL for query params.
     *
     * Example:
     * DB::select('table', "title LIKE :contains", [
     *   ':contains' => DB::likeContains($keyword)  // creates '%keyword%'
     * ]);
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return rawSQL Escaped LIKE pattern wrapped in wildcards '%value%'
     * @throws Exception
     */
    public static function likeContains(string|int|float|null|SmartString $input): rawSQL
    {
        return self::getDefaultInstance()->likeContains($input);
    }

    /**
     * Creates a MySQL LIKE pattern for matching values in tab-delimited columns, e.g., '%\tValue\t%'.
     * Escapes MySQL special characters and LIKE wildcards. Returns rawSQL for query params.
     *
     * Example:
     * DB::select('table', "categories LIKE :contains", [
     *   ':contains' => DB::likeContainsTSV($category)  // creates '%\tValue\t%'
     * ]);
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return rawSQL Escaped LIKE pattern with tab delimiters '%\tValue\t%'
     * @throws Exception
     */
    public static function likeContainsTSV(string|int|float|null|SmartString $input): rawSQL
    {
        return self::getDefaultInstance()->likeContainsTSV($input);
    }

    /**
     * Creates a MySQL LIKE pattern for "column starts with value" searches, e.g., 'value%'.
     * Escapes MySQL special characters and LIKE wildcards. Returns rawSQL for query params.
     *
     * Example:
     * DB::select('table', "title LIKE :startsWith", [
     *   ':startsWith' => DB::likeStartsWith($keyword)  // creates 'keyword%'
     * ]);
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return rawSQL Escaped LIKE pattern with trailing wildcard 'value%'
     * @throws Exception
     */
    public static function likeStartsWith(string|int|float|null|SmartString $input): rawSQL
    {
        return self::getDefaultInstance()->likeStartsWith($input);
    }

    /**
     * Creates a MySQL LIKE pattern for "column ends with value" searches, e.g., '%value'.
     * Escapes MySQL special characters and LIKE wildcards. Returns rawSQL for query params.
     *
     * Example:
     * DB::select('table', "title LIKE :endsWith", [
     *   ':endsWith' => DB::likeEndsWith($keyword)  // creates '%keyword'
     * ]);
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return rawSQL Escaped LIKE pattern with leading wildcard '%value'
     * @throws Exception
     */
    public static function likeEndsWith(string|int|float|null|SmartString $input): rawSQL
    {
        return self::getDefaultInstance()->likeEndsWith($input);
    }

    /**
     * Converts array values to a safe CSV string for use in MySQL IN clauses, defaults to NULL.
     * - Numbers are unquoted: 1,2,3
     * - Strings are escaped and quoted: 'a','b','c'
     * - NULL becomes SQL NULL keyword
     * - Booleans become TRUE/FALSE keywords
     * - Empty arrays return NULL so "column IN (NULL)" is valid SQL but won't match any values
     *
     * @param array $array Array of values to convert
     * @return RawSql SQL-safe comma-separated list wrapped in RawSql
     * @throws InvalidArgumentException If array contains unsupported value type
     * @throws Exception
     */
    public static function escapeCSV(array $array): RawSql
    {
        return self::getDefaultInstance()->escapeCSV($array);
    }

    /**
     * Generates a LIMIT/OFFSET SQL clause for pagination.
     *
     * @param mixed $pageNum The current page number. Must be numeric. Default value set to 1 if zero or negative.
     * @param mixed $perPage The number of records per page. Must be numeric. Default value set to 10 if zero or negative.
     *
     * Usage Examples:
     * 1. Using as an inline argument:
     *    DB::SELECT('table', "date <= NOW() ?", pagingSql(1, 25));
     *
     * 2. Using as a named parameter:
     *    DB::SELECT('table', "date <= NOW() :pagingSql", [
     *      ':pagingSql' => pagingSql(1, 25),
     *    ]);
     *
     * @return RawSql LIMIT/OFFSET clause for SQL queries.
     * @throws Exception
     */
    public static function pagingSql(mixed $pageNum, mixed $perPage = 10): RawSql
    {
        return self::getDefaultInstance()->pagingSql($pageNum, $perPage);
    }

    /**
     * indicate that a value is intended to be a SQL literal and not quoted, e.g., NOW(), CURRENT_TIMESTAMP, etc.
     * Check with: DB::isRawSql($var)
     *
     * Note: DB::rawSql() values get inserted before prepared statements are executed, so they can be used in non-DML SQL
     * This is because prepared queries are used to bind values not structural components of the SQL query, so you can't
     * normally use a placeholder for a table name, column name, etc.  However, DB::rawSql() values are inserted before
     * the prepared statement is executed, so they can be used for constructing queries for non-DML SQL.
     *
     * @param string|int|float|null $value
     *
     * @return RawSql
     * @throws Exception
     */
    public static function rawSql(string|int|float|null $value): RawSql
    {
        return self::getDefaultInstance()->rawSql($value);
    }


    // Usage: self::isRawSql($value)
    public static function isRawSql(mixed $stringOrObj): bool
    {
        return self::getDefaultInstance()->isRawSql($stringOrObj);
    }

    #endregion
    #region Utility Functions

    /**
     * Show resultSet information about the last query
     *
     * @return void
     * @throws Exception
     */
    public static function debug(): void
    {
        if (self::$defaultInstance !== null) {
            self::getDefaultInstance()->debug();
        } else {
            echo "No connection or query has been executed yet.\n";
        }
    }

    /**
     * Sets the MySQL timezone to match the PHP timezone.
     *
     * @throws RuntimeException|Exception  If not connected to the database or if the set command fails.
     */
    public static function setTimezoneToPhpTimezone(string $mysqlTzOffset = ''): void
    {
        self::getDefaultInstance()->setTimezoneToPhpTimezone($mysqlTzOffset);
    }

    /**
     * Add "Occurred in file:line" to the end of the error messages with the first non-SmartArray file and line number.
     */
    public static function occurredInFile($addReportedFileLine = false): string
    {
        return self::getDefaultInstance()->occurredInFile($addReportedFileLine);
    }

    #endregion
    #region Magic Methods


    /**
     * Gets the default DBInstance used by static methods. Creates it if it doesn't exist yet.
     *
     * @return Instance The default database instance
     * @throws Exception
     */
    public static function getDefaultInstance(): Instance
    {
        if (self::$defaultInstance === null) {
            self::connect();
            self::$defaultInstance = new Instance(self::$config);
        }

        self::$defaultInstance->parser = new Parser(); // reset parser

        return self::$defaultInstance;
    }

    /**
     * Creates a new DBInstance that shares the same Connection as the default instance.
     *
     * Use this method when you need a separate instance with its own configuration
     * but want to share the same database connection.
     *
     * @return Instance A new database instance with the same connection as the default
     * @throws Exception
     */
    public static function newInstance(): Instance
    {
        // Ensure we have a default instance first
        self::getDefaultInstance();

        // Create a new instance with the same connection object
        $connection = self::getDefaultInstance()->connection;
        $instance   = new Instance($connection);

        return $instance;
    }

    /**
     * Creates a new DBInstance with a fresh database connection.
     *
     * Use this method when you need a completely separate database connection,
     * for example when working with different databases or requiring different
     * connection settings.
     *
     * @param array $overrides Optional configuration overrides to merge with the default config
     * @return Instance A new database instance with its own connection
     * @throws Exception
     */
    public static function newConnection(array $overrides = []): Instance
    {
        $config = new Config($overrides);
        return new Instance($config);
    }

    /**
     * Constructor for object instance usage.
     * Instance methods will delegate to the singleton DBInstance:
     * - $db = DB::newInstance(); $db->select('table')
     */
    public function __construct()
    {
        throw new RuntimeException("Use DB::newInstance() instead.");
    }

    /**
     * Handles static calls to the DB Utils methods.
     */
    public static function __callStatic(string $name, array $args)
    {
        // Try delegating to default instance first
        if (method_exists(Instance::class, $name)) {
            return self::getDefaultInstance()->$name(...$args);
        }

        // legacy methods
        return match ($name) {
            'like', 'escapeLikeWildcards' => addcslashes((string)($args[0] ?? ''), '%_'),                           // Replacement: See escape(value, true) and like* methods
            'identifier'                  => self::rawSql("`" . self::$mysqli->real_escape_string(...$args) . "`"), // Replacement: Automatically handled by Prepare->getParamQuery()
            'getTablePrefix'              => self::$tablePrefix,                                                    // Replacement: DB::$tablePrefix
            'raw'                         => self::rawSql(...$args),                                                // Replacement: DB::rawSql()
            'datetime'                    => date('Y-m-d H:i:s', ($args[0] ?? time())),                             // Replacement: date('Y-m-d H:i:s', $unixTime)
            default                       => throw new InvalidArgumentException("Unknown static method: $name"),
        };
    }

    #endregion
}
