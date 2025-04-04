<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use DateTime, DateTimeZone;
use Itools\SmartArray\SmartArray;
use Itools\SmartString\SmartString;
use mysqli;
use Throwable, InvalidArgumentException, RuntimeException, Exception;
use Itools\ZenDB\Internal\QueryExecutor;

/**
 * DB is a wrapper for mysqli that provides a simple, secure, and consistent interface for database access.
 */
class DB
{
    public static string       $tablePrefix;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             // table prefix, set by config()
    public static ?mysqli      $mysqli        = null;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    // mysqli object
    public static DB           $lastInstance;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     // for testing
    public static ?Throwable   $lastException = null;
    private static ?Connection $connection    = null;     // connection instance
    private static ?Config     $config        = null;     // config instance

    private Parser     $parser;
    private SmartArray $resultSet;

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
     *
     * @param string|array|null $keyOrArray Key to retrieve, or key-value pairs to set.
     * @param string|bool|null $keyValue Value to set for the given key. Ignored if first parameter is an array.
     *
     * @return mixed The requested configuration value, array of all settings, or null after setting values.
     */
    public static function config(string|array|null $keyOrArray = null, string|int|bool|null $keyValue = null): mixed
    {
        self::$config ??= new Config();  // Initialize config instance if not already created
        $argCount     = func_num_args();
        $result = match (true) {
            $argCount === 0                           => self::$config->getAll(), // get all config values
            $argCount === 1 && is_array($keyOrArray)  => self::$config->setMany($keyOrArray),
            $argCount === 1 && is_string($keyOrArray) => self::$config->get($keyOrArray),
            $argCount === 2 && is_string($keyOrArray) => self::$config->set($keyOrArray, $keyValue),
            default                                   => throw new InvalidArgumentException("Invalid arguments for config() method"),
        };

        // update table prefix alias property in case it changed
        self::$tablePrefix = self::$config->tablePrefix;

        return $result;
    }

    /**
     * @throws Exception
     */
    public static function connect(): void
    {
        // Skip if connection exists and is still active (isConnected() checks for a live connection)
        if (self::isConnected(true)) {
            return;
        }

        // Get config and create connection if needed
        $cfg = self::config();

        // Create Connection instance if it doesn't exist yet
        // The Connection constructor will attempt connection, but we also call reconnect()
        // explicitly to ensure we're connected (handles reconnection cases)
        if (!self::$connection) {
            self::$connection = new Connection($cfg); // Create & Connect
        } else {
            self::$connection->reconnectIfNeeded(); // Try to reconnect if connection was lost
        }

        // Update the static mysqli property
        self::$mysqli = self::$connection->mysqli;
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
        return (bool) self::$connection?->isConnected($doPing);
    }

    /**
     * Close the database connection.
     *
     * @return void
     */
    public static function disconnect(): void
    {
        self::$connection?->disconnect();
        self::$mysqli = null;
    }

    #endregion
    #region Query Functions


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
        $db = new DB();

        // error checking
        if (!preg_match('/^\s*([a-zA-Z]+)\b/', $sqlTemplate)) {
            throw new InvalidArgumentException("SQL statement must start with a valid SQL keyword such as SELECT, INSERT, etc.");
        }

        // return ResultSet
        $db->parser->addParamsFromArgs($params);
        $db->parser->setSqlTemplate($sqlTemplate);

        try {
            $db->resultSet = QueryExecutor::fetchAll($db->parser);
        } catch (Throwable $e) {
            // mysqli prepare/bind/execute can throw PHP \Error exceptions when a MySQL query is invalid, but without a message or code
            // since these are thrown before $resultSet is defined, we'll assume relevant errors are in $mysqli->error and $mysqli->errno
            self::$lastException = $e;
            throw new DBException("Error executing query: {$e->getMessage()}", 0, $e);
        }

        //
        return $db->resultSet;
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
        // returns array with any (or none) matching rows or throws exception on sql error
        Assert::ValidTableName($baseTable);

        // build SQL template
        $db = new DB();
        $db->parser->addParamsFromArgs($params);
        $whereEtc    = $db->getWhereEtc($idArrayOrSql);
        $sqlTemplate = "SELECT * FROM `:_$baseTable` $whereEtc";

        // return ResultSet
        $db->parser->setSqlTemplate($sqlTemplate);
        $db->resultSet = QueryExecutor::fetchAll($db->parser, $baseTable);
        return $db->resultSet;
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
        Assert::ValidTableName($baseTable);
        $db = new DB();

        // build SQL template
        $db->parser->addParamsFromArgs($params);
        $whereEtc    = $db->getWhereEtc($idArrayOrSql);
        $sqlTemplate = rtrim("SELECT * FROM `:_$baseTable` $whereEtc") . " LIMIT 1";
        $db->parser->setSqlTemplate($sqlTemplate);

        // error checking
        if (is_string($idArrayOrSql) && preg_match('/\b(LIMIT|OFFSET)\s+[0-9:?]+\s*/i', $idArrayOrSql)) {
            throw new InvalidArgumentException("This method doesn't support LIMIT or OFFSET, use select() instead");
        }
        if (!preg_match('/^\s*([a-zA-Z]+)\b/', $sqlTemplate)) {
            throw new InvalidArgumentException("SQL statement must start with a valid SQL keyword such as SELECT, INSERT, etc.");
        }

        // return ResultSet
        try {
            $db->resultSet = QueryExecutor::fetchAll($db->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error getting row.", 0, $e);
        }

        // return first row
        if ($db->resultSet->isEmpty()) {
            // Create new empty SmartArray with parent properties
            return $db->resultSet->filter(fn() => false);
        }
        return $db->resultSet->first();
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
        Assert::ValidTableName($baseTable);

        // Create insert query
        $db          = new DB();
        $setClause   = $db->getSetClause($colsToValues);
        $sqlTemplate = "INSERT INTO `:_$baseTable` $setClause";

        // prepare and execute statement
        $db->parser->setSqlTemplate($sqlTemplate);
        try {
            $db->resultSet = QueryExecutor::fetchAll($db->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error inserting row.", 0, $e);
        }

        //
        return $db->resultSet->mysqli('insert_id');
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
        Assert::ValidTableName($baseTable);

        // create query
        $db = new DB();
        $db->parser->addParamsFromArgs($params);
        $setClause   = $db->getSetClause($colsToValues);
        $whereEtc    = $db->getWhereEtc($idArrayOrSql, true); // 2nd argument REQUIRES WHERE to prevent accidental deletions
        $sqlTemplate = "UPDATE `:_$baseTable` $setClause $whereEtc";

        // prepare and execute statement
        $db->parser->setSqlTemplate($sqlTemplate);
        try {
            $db->resultSet = QueryExecutor::fetchAll($db->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error updating row.", 0, $e);
        }

        // Return the number of affected rows
        return $db->resultSet->mysqli('affected_rows');
    }

    /**
     * Deletes a record or records from the 'accounts' table.
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
        Assert::ValidTableName($baseTable);

        // create query
        $db = new DB();
        $db->parser->addParamsFromArgs($params);
        $whereEtc = $db->getWhereEtc($idArrayOrSql, true); // 2nd argument REQUIRES WHERE to prevent accidental deletions
        $db->parser->setSqlTemplate("DELETE FROM `:_$baseTable` $whereEtc");

        // prepare and execute statement
        try {
            $db->resultSet = QueryExecutor::fetchAll($db->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error updating row.", 0, $e);
        }

        // Return the number of affected rows
        return $db->resultSet->mysqli('affected_rows');
    }


    /**
     * @param string $baseTable
     * @param int|array|string $idArrayOrSql
     * @param ...$params
     * @return int
     * @throws DBException
     */
    public static function count(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): int
    {
        Assert::ValidTableName($baseTable);

        // error checking
        if (is_string($idArrayOrSql) && preg_match('/\b(LIMIT|OFFSET)\s+[0-9:?]+\s*/i', $idArrayOrSql)) {
            throw new InvalidArgumentException("This method doesn't support LIMIT or OFFSET");
        }

        // create query
        $db = new DB();
        $db->parser->addParamsFromArgs($params);
        $whereEtc = $db->getWhereEtc($idArrayOrSql);
        $db->parser->setSqlTemplate("SELECT COUNT(*) FROM `:_$baseTable` $whereEtc");

        // return count
        try {
            $db->resultSet = QueryExecutor::fetchAll($db->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error selecting count.", 0, $e);
        }
        $count = (int)$db->resultSet->first()->nth(0)->value();
        return $count;
    }

    /**
     * Prepare and validate SQL conditions for clauses such as WHERE, ORDER BY, LIMIT, or OFFSET.
     *
     * This method can handle both a raw SQL string and an associative array of column-value pairs.
     * When a raw SQL string is provided, it should start with either 'WHERE', 'ORDER BY', 'LIMIT', or 'OFFSET'.
     * When an associative array is provided, the function dynamically constructs the SQL conditions and accompanying parameters.
     * The function sets the property $whereEtc.
     *
     * @param int|array|string $idArrayOrSql The raw SQL string or an associative array of column-value pairs.
     * @param bool $whereRequired
     *
     * @return string
     */
    private function getWhereEtc(int|array|string $idArrayOrSql, bool $whereRequired = false): string
    {
        // Get sql clauses from int|array|string
        if (is_int($idArrayOrSql)) {
            $primaryKey = self::config('primaryKey');
            if (!$primaryKey) {
                throw new InvalidArgumentException("Primary key not defined in config");
            }
            $whereEtc = "WHERE `$primaryKey` = ?";
            $this->parser->addPositionalParam($idArrayOrSql); // value is integer
        } elseif (is_array($idArrayOrSql)) {
            $whereEtc = $this->getWhereEtcForArray($idArrayOrSql);
        } elseif (is_string($idArrayOrSql)) { // Use provided sql clauses as-is
            if (preg_match("/^\s*\d+\s*$/", $idArrayOrSql)) {
                throw new InvalidArgumentException("Numeric string detected, convert to integer with (int) \$num to search by record number");
            }
            $whereEtc = $idArrayOrSql;
        } else {
            throw new InvalidArgumentException("Invalid type for \$idArrayOrSql: " . gettype($idArrayOrSql));
        }

        // Add WHERE if not already present
        $requiredLeadingKeyword = "/^\s*(WHERE|FOR|ORDER|LIMIT|OFFSET)\b/i";
        if (!preg_match($requiredLeadingKeyword, $whereEtc) &&
            !preg_match("/^\s*$/", $whereEtc)) { // don't add where if no content
            $whereEtc = "WHERE " . $whereEtc;
        }

        // Validate: Check SQL clauses start with a valid keyword and don't contain unsafe characters
        if ($whereEtc !== "") { // allow empty string
            if (!preg_match($requiredLeadingKeyword, $whereEtc)) {
                throw new InvalidArgumentException("SQL clauses must start with one of the following: WHERE, ORDER BY, LIMIT, OFFSET. Got: $whereEtc");
            }
            Assert::sqlSafeString($whereEtc);
        }

        // Require WHERE
        if ($whereRequired && !preg_match('/^\s*WHERE\b/i', $whereEtc)) {
            throw new InvalidArgumentException("No where condition, operations that can change multiple rows require a WHERE condition");
        }

        return $whereEtc;
    }

    /**
     * Initializes the SQL clauses for an array of conditions.
     *
     * @param array $idArrayOrSql An associative array of conditions.
     *
     * @return string
     *
     * @example $idArrayOrSql = ['column1' => $value1, 'column2' => $value2];
     *          $whereEtcInitForArray($idArrayOrSql);
     */
    private function getWhereEtcForArray(array $idArrayOrSql): string
    {
        $whereEtc   = "";
        $conditions = [];
        foreach ($idArrayOrSql as $column => $value) {
            Assert::ValidColumnName($column);
            if (is_null($value)) {
                $conditions[] = "`$column` IS NULL";
            } else {
                $conditions[] = "`$column` = ?";
                $this->parser->addPositionalParam($value);
            }
        }
        if ($conditions) {
            $whereEtc = "WHERE " . implode(" AND ", $conditions);
        }
        return $whereEtc;
    }

    //

    /**
     * Create set clause from colsToValues array for INSERT/UPDATE SET.  e.g., SET `column1` = :colVal1, `column2` = :colVal2
     * Usage: $this->buildSetClause($colsToValues);
     *
     * @param array $colsToValues
     *
     * @return string
     */
    private function getSetClause(array $colsToValues): string
    {
        // error checking
        if (!$colsToValues) {
            throw new InvalidArgumentException("No colsToValues, please specify some column values");
        }

        // build set clause and defined named parameters, e.g., SET `column1` = :colVal1, `column2` = :colVal2
        $setElements          = [];
        $tempPlaceholderCount = 0;
        foreach ($colsToValues as $column => $value) {
            Assert::ValidColumnName($column);

            // add to setClause
            $tempPlaceholderCount++;
            $tempPlaceholder = ":zdb_$tempPlaceholderCount"; // zdb_ is internal placeholder prefix
            $setElements[]   = "`$column` = $tempPlaceholder";

            // add values to paramMap
            $this->parser->addInternalParam($tempPlaceholder, $value);
        }

        //
        return "SET " . implode(", ", $setElements);
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
        $input   = $input instanceof SmartString ? $input->value() : $input;           // Unwrap SmartString
        $string  = (string)$input;                                                     // Convert to string
        $escaped = self::$mysqli->real_escape_string($string);                         // Mysqli escape
        $escaped = $escapeLikeWildcards ? addcslashes($escaped, '%_') : $escaped;      // Optionally escape LIKE wildcards
        return $escaped;
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
     */
    public static function likeContains(string|int|float|null|SmartString $input): rawSQL
    {
        return self::rawSQL("'%" . self::escape($input, true) . "%'");
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
     */
    public static function likeContainsTSV(string|int|float|null|SmartString $input): rawSQL
    {
        return self::rawSQL("'%\\t" . self::escape($input, true) . "\\t%'"); // double-escape tab so it's visible as \t in the SQL queries for easier debugging
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
     */
    public static function likeStartsWith(string|int|float|null|SmartString $input): rawSQL
    {
        return self::rawSQL("'" . self::escape($input, true) . "%'");
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
     */
    public static function likeEndsWith(string|int|float|null|SmartString $input): rawSQL
    {
        return self::rawSQL("'%" . self::escape($input, true) . "'");
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
     */
    public static function escapeCSV(array $array): RawSql
    {
        $safeValues = [];
        foreach (array_unique($array) as $value) {                                                                                                                                                                                                                                                                                                         // Remove duplicates for efficiency
            $value        = $value instanceof SmartString ? (string)$value->value(
            ) : $value;                                                                                                                                                                                                                                                                                                                                    // Convert SmartString objects to string of raw value
            $safeValues[] = match (true) {
                is_int($value) || is_float($value) => $value,
                is_null($value)                    => 'NULL',
                is_bool($value)                    => $value ? 'TRUE' : 'FALSE',
                is_string($value)                  => "'" . self::$mysqli->real_escape_string($value) . "'",
                default                            => throw new InvalidArgumentException("Unsupported value type: " . get_debug_type($value)),
            };
        }

        $sqlSafeCSV = $safeValues ? implode(',', $safeValues) : 'NULL';
        return self::rawSql($sqlSafeCSV);
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
     */
    public static function pagingSql(mixed $pageNum, mixed $perPage = 10): RawSql
    {
        // Force positive whole numbers
        $pageNum = abs((int)$pageNum) ?: 1;  // defaults to 1 (if set to 0)
        $perPage = abs((int)$perPage) ?: 10; // defaults to 10 (if set to 0)

        // Generate LIMIT/OFFSET SQL clause
        $offset         = ($pageNum - 1) * $perPage;
        $limitOffsetSQL = self::rawSql("LIMIT $perPage OFFSET $offset");

        return $limitOffsetSQL;
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
     */
    public static function rawSql(string|int|float|null $value): RawSql
    {
        return new RawSql((string)$value);
    }


    // Usage: self::isRawSql($value)
    public static function isRawSql(mixed $stringOrObj): bool
    {
        return $stringOrObj instanceof RawSql;
    }

    #endregion
    #region Utility Functions

    /**
     * Show resultSet information about the last query
     *
     * @return void
     */
    public static function debug(): void
    {
        /** @noinspection ForgottenDebugOutputInspection */
        print_r(self::$lastInstance->resultSet);
    }

    /**
     * Sets the MySQL timezone to match the PHP timezone.
     *
     * @throws RuntimeException|Exception  If not connected to the database or if the set command fails.
     */
    public static function setTimezoneToPhpTimezone(string $mysqlTzOffset = ''): void
    {
        // check we're connected
        if (!self::isConnected()) {
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
        self::$mysqli->real_query($query) || throw new RuntimeException("Set command failed:\n$query");
    }

    /**
     * Add "Occurred in file:line" to the end of the error messages with the first non-SmartArray file and line number.
     */
    public static function occurredInFile($addReportedFileLine = false): string
    {
        $file      = "unknown";
        $line      = "unknown";
        $inMethod  = "";
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Add Occurred in file:line
        foreach ($backtrace as $index => $caller) {
            if (empty($caller['file']) || dirname($caller['file']) !== __DIR__) {
                $file       = $caller['file'] ?? $file;
                $line       = $caller['line'] ?? $line;
                $prevCaller = $backtrace[$index + 1] ?? [];
                $inMethod   = match (true) {
                    !empty($prevCaller['class'])    => " in {$prevCaller['class']}{$prevCaller['type']}{$prevCaller['function']}()",
                    !empty($prevCaller['function']) => " in {$prevCaller['function']}()",
                    default                         => "",
                };
                break;
            }
        }
        $output = "Occurred in $file:$line$inMethod\nReported";

        // Add Reported in file:line (if requested)
        if ($addReportedFileLine) {
            $method       = basename($backtrace[1]['class']) . $backtrace[1]['type'] . $backtrace[1]['function'];
            $reportedFile = $backtrace[0]['file'] ?? "unknown";
            $reportedLine = $backtrace[0]['line'] ?? "unknown";
            $output       .= " in $reportedFile:$reportedLine in $method()\n";
        }

        // return output
        return $output;
    }

    #endregion
    #region Magic Methods

    private function __construct()
    {
        $this->parser = new Parser();

        // For testing
        self::$lastInstance = $this;
    }

    /**
     * Handles static calls to the DB Utils methods.
     */
    public static function __callStatic(string $name, array $args)
    {
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
