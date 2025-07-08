<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use DateTime, DateTimeZone;
use Itools\SmartArray\SmartArray;
use Itools\SmartString\SmartString;
use mysqli;
use Throwable, InvalidArgumentException, RuntimeException, Exception;
use Itools\ZenDB\QueryExecutor;

/**
 * DBInstance represents a database connection instance with its own configuration.
 *
 * It provides methods for querying and manipulating the database.
 */
class Instance
{
    #region Properties

    /**
     * The database connection object
     */
    public Connection $connection;

    /**
     * The SQL parser for query construction
     */
    public Parser $parser;

    /**
     * The last query result set
     */
    private ?SmartArray $resultSet;

    #endregion
    #region Configuration Properties

    /**
     * Table prefix automatically added to all table names (e.g. 'cms_')
     */
    public ?string $tablePrefix;

    /**
     * Default primary key field name used for shorthand where=$num queries
     */
    public ?string $primaryKey;

    /**
     * Custom load handler for SmartArray integration
     *
     * @var string|callable|null
     *
     * Possible values:
     *
     * $instance->smartArrayLoadHandler = '\Namespace\Class::load';           // Function name as string
     * $instance->smartArrayLoadHandler = [MyLoadHandler::class, 'load'];     // Static method
     * $instance->smartArrayLoadHandler = [$object, 'load'];                  // Instance method
     */
    public mixed $smartArrayLoadHandler;

    /**
     * Controls whether to show SQL in error messages
     *
     * @var bool|callable
     *
     * Possible values:
     * - false = never show SQL (default)
     * - true = always show SQL
     * - callable = function(): bool - custom logic to determine if SQL should be shown
     */
    public mixed $showSqlInErrors;

    /**
     * Use prepared statements instead of escaped queries
     * Recommended for security and performance
     */
    public bool $usePreparedStatements;

    /**
     * Enable smart join functionality for table relationships
     * Can be toggled at runtime
     */
    public bool $useSmartJoins;

    /**
     * Enable logging of SQL queries to a file
     */
    public bool $enableLogging;

    /**
     * Path to file for SQL query logging
     */
    public ?string $logFile;

    #endregion
    #region Config & Connection

    /**
     * Creates a new database instance with the provided configuration or connection.
     *
     * @param array $options Configuration array, Connection instance, or Config object (for backward compatibility)
     */
    public function __construct(array $options, Connection $connection)
    {
        $this->parser     = new Parser();
        $this->connection = $connection;

        // Set option properties
        foreach ($options as $property => $value) {
            if (property_exists($this, $property)) {
                $this->$property = $value;
            } else {
                throw new InvalidArgumentException("Invalid configuration property: $property");
            }
        }
    }

    // Connection is directly accessible as a public property

    #endregion
    #region Query Methods

    /**
     * Executes an SQL query and returns a ResultSet object.
     *
     * @param string $sqlTemplate SQL statement template with placeholders for parameters.
     * @param mixed ...$params Optional prepared statement parameters to be bound to the SQL template.
     *
     * @return SmartArray Returns a ResultSet object containing the results or status of the query.
     *
     * @throws DBException
     * @example $dbInstance->query("SELECT * FROM `accounts` WHERE name = :name AND date <= :targetDate", $params);
     */
    public function query(string $sqlTemplate, ...$params): SmartArray
    {
        // error checking
        if (!preg_match('/^\s*([a-zA-Z]+)\b/', $sqlTemplate)) {
            throw new InvalidArgumentException("SQL statement must start with a valid SQL keyword such as SELECT, INSERT, etc.");
        }

        // Reset parser for new query
        $this->parser = new Parser();

        // Add parameters and set SQL template
        $this->parser->params->addParamsFromArgs($params);
        $this->parser->setSqlTemplate($sqlTemplate);

        try {
            $this->resultSet = $this->executeQuery($this->parser);
        } catch (Throwable $e) {
            throw new DBException("Error executing query: {$e->getMessage()}", 0, $e);
        }

        //
        return $this->resultSet;
    }

    /**
     * Executes an SQL SELECT query and returns a ResultSet object.
     *
     * @param string $baseTable The base table name for the query.
     * @param int|array|string $idArrayOrSql Optional array of IDs, SQL condition string, or empty (to select all rows).
     * @param mixed ...$params Optional prepared statement parameters to be bound to the SQL template.
     *
     * @return SmartArray Returns a ResultSet object containing the results or status of the query.
     *
     * @throws DBException
     * @example
     * $dbInstance->select("accounts"); // Selects all rows from the "accounts" table.
     * $dbInstance->select("accounts", 123); // Selects rows with ID 123 from the "accounts" table.
     * $dbInstance->select("accounts", ['city' => 'New York']); // Selects rows from the "accounts" table where the city is "New York".
     * $dbInstance->select("accounts", "WHERE balance > 1000"); // Selects rows from the "accounts" table where the balance is greater than 1000.
     */
    public function select(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArray
    {
        // returns array with any (or none) matching rows or throws exception on sql error
        Assert::validTableName($baseTable);

        // Reset parser for new query
        $this->parser = new Parser();

        // build SQL template
        $this->parser->params->addParamsFromArgs($params);
        $primaryKey  = $this->primaryKey;
        $whereEtc    = $this->parser->getWhereEtc($idArrayOrSql, false, $primaryKey);
        $tablePrefix = $this->tablePrefix;
        $sqlTemplate = "SELECT * FROM `$tablePrefix$baseTable` $whereEtc";

        // return ResultSet
        $this->parser->setSqlTemplate($sqlTemplate);
        $this->resultSet = $this->executeQuery($this->parser, $baseTable);
        return $this->resultSet;
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
     * $dbInstance->get("users", 1); // Retrieve a row with the ID 1 from the "users" table
     * $dbInstance->get("orders", ["id" => 123]); // Retrieve a row from the "orders" table where the ID is 123
     * $dbInstance->get("products", "price > :price", ["price" => 50]); // Retrieve a row from the "products" table based on a custom SQL query
     */
    public function get(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArray
    {
        Assert::validTableName($baseTable);

        // Reset parser for new query
        $this->parser = new Parser();

        // build SQL template
        $this->parser->params->addParamsFromArgs($params);
        $primaryKey  = $this->primaryKey;
        $whereEtc    = $this->parser->getWhereEtc($idArrayOrSql, false, $primaryKey);
        $tablePrefix = $this->tablePrefix;
        $sqlTemplate = rtrim("SELECT * FROM `$tablePrefix$baseTable` $whereEtc") . " LIMIT 1";
        $this->parser->setSqlTemplate($sqlTemplate);

        // error checking
        if (is_string($idArrayOrSql) && preg_match('/\b(LIMIT|OFFSET)\s+[0-9:?]+\s*/i', $idArrayOrSql)) {
            throw new InvalidArgumentException("This method doesn't support LIMIT or OFFSET, use select() instead");
        }
        if (!preg_match('/^\s*([a-zA-Z]+)\b/', $sqlTemplate)) {
            throw new InvalidArgumentException("SQL statement must start with a valid SQL keyword such as SELECT, INSERT, etc.");
        }

        // return ResultSet
        try {
            // Ensure we have a valid mysqli connection
            $mysqli = $this->connection->mysqli;
            if (!$mysqli) {
                throw new RuntimeException("Missing mysqli connection");
            }

            // Execute the query with our connection
            $this->resultSet = $this->executeQuery($this->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error getting row", 0, $e);
        }

        // return first row
        if ($this->resultSet->isEmpty()) {
            // Create new empty SmartArray with parent properties
            return $this->resultSet->filter(fn() => false);
        }
        return $this->resultSet->first();
    }

    /**
     * Insert rows into a database table using the provided column-value pairs.
     *
     * @param string $baseTable The name of the base table where the rows will be inserted.
     * @param array $colsToValues An associative array mapping column names to their corresponding values.
     *
     * @return int The id of the inserted record
     * @throws DBException If there is an error inserting the row.
     *
     * @example $dbInstance->insert("accounts", ["name" => "John Doe", "age" => 30]);
     */
    public function insert(string $baseTable, array $colsToValues): int
    {
        Assert::validTableName($baseTable);

        // Reset parser for new query
        $this->parser = new Parser();

        // Create insert query
        $setClause   = $this->parser->getSetClause($colsToValues);
        $tablePrefix = $this->tablePrefix;
        $sqlTemplate = "INSERT INTO `$tablePrefix$baseTable` $setClause";

        // prepare and execute statement
        $this->parser->setSqlTemplate($sqlTemplate);
        try {
            // Ensure we have a valid mysqli connection
            $mysqli = $this->connection->mysqli;
            if (!$mysqli) {
                throw new RuntimeException("Missing mysqli connection");
            }

            // Execute the query with our connection
            $this->resultSet = $this->executeQuery($this->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error inserting row.", 0, $e);
        }

        //
        return $this->resultSet->mysqli('insert_id');
    }

    /**
     * Updates records in a table based on conditions and returns affected rows. Note that if you update a
     * row with the same values, it won't count as an affected row.
     *
     * @param string $baseTable The name of the table to update.
     * @param array $colsToValues Associative array representing columns and their new values.
     * @param int|array|string $idArrayOrSql An array of conditions or a raw SQL condition string.
     * @param mixed ...$params Optional additional prepared statement parameters.
     *
     * @return int Returns the number of affected rows.
     *
     * @throws DBException
     * @example $dbInstance->update('accounts', $colsToValues, "WHERE num = ? AND city = :city", ...$params);
     * @example $dbInstance->update('accounts', $colsToValues, ['num' => $num]);
     */
    public function update(string $baseTable, array $colsToValues, int|array|string $idArrayOrSql, ...$params): int
    {
        Assert::validTableName($baseTable);

        // Reset parser for new query
        $this->parser = new Parser();

        // create query
        $this->parser->params->addParamsFromArgs($params);
        $setClause   = $this->parser->getSetClause($colsToValues);
        $primaryKey  = $this->primaryKey;
        $whereEtc    = $this->parser->getWhereEtc($idArrayOrSql, true, $primaryKey); // REQUIRES WHERE to prevent accidental deletions
        $tablePrefix = $this->tablePrefix;
        $sqlTemplate = "UPDATE `$tablePrefix$baseTable` $setClause $whereEtc";

        // prepare and execute statement
        $this->parser->setSqlTemplate($sqlTemplate);
        try {
            // Ensure we have a valid mysqli connection
            $mysqli = $this->connection->mysqli;
            if (!$mysqli) {
                throw new RuntimeException("Missing mysqli connection");
            }

            // Execute the query with our connection
            $this->resultSet = $this->executeQuery($this->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error updating row.", 0, $e);
        }

        // Return the number of affected rows
        return $this->resultSet->mysqli('affected_rows');
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
     * @example $dbInstance->delete('accounts', ['num' => $num]);
     * @example $dbInstance->delete('accounts', "WHERE num = ? AND city = :city", ...$params);
     */
    public function delete(string $baseTable, int|array|string $idArrayOrSql, ...$params): int
    {
        Assert::validTableName($baseTable);

        // Reset parser for new query
        $this->parser = new Parser();

        // create query
        $this->parser->params->addParamsFromArgs($params);
        $primaryKey  = $this->primaryKey;
        $whereEtc    = $this->parser->getWhereEtc($idArrayOrSql, true, $primaryKey); // REQUIRES WHERE to prevent accidental deletions
        $tablePrefix = $this->tablePrefix;
        $this->parser->setSqlTemplate("DELETE FROM `$tablePrefix$baseTable` $whereEtc");

        // prepare and execute statement
        try {
            // Ensure we have a valid mysqli connection
            $mysqli = $this->connection->mysqli;
            if (!$mysqli) {
                throw new RuntimeException("Missing mysqli connection");
            }

            // Execute the query with our connection
            $this->resultSet = $this->executeQuery($this->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error deleting row.", 0, $e);
        }

        // Return the number of affected rows
        return $this->resultSet->mysqli('affected_rows');
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
    public function count(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): int
    {
        Assert::validTableName($baseTable);

        // error checking
        if (is_string($idArrayOrSql) && preg_match('/\b(LIMIT|OFFSET)\s+[0-9:?]+\s*/i', $idArrayOrSql)) {
            throw new InvalidArgumentException("This method doesn't support LIMIT or OFFSET");
        }

        // Reset parser for new query
        $this->parser = new Parser();

        // create query
        $this->parser->params->addParamsFromArgs($params);
        $primaryKey  = $this->primaryKey;
        $whereEtc    = $this->parser->getWhereEtc($idArrayOrSql, false, $primaryKey);
        $tablePrefix = $this->tablePrefix;
        $this->parser->setSqlTemplate("SELECT COUNT(*) FROM `$tablePrefix$baseTable` $whereEtc");

        // return count
        try {
            // Ensure we have a valid mysqli connection
            $mysqli = $this->connection->mysqli;
            if (!$mysqli) {
                throw new RuntimeException("Missing mysqli connection");
            }

            // Execute the query with our connection
            $this->resultSet = $this->executeQuery($this->parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error selecting count.", 0, $e);
        }
        $count = (int)$this->resultSet->first()->nth(0)->value();
        return $count;
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
    public function getBaseTable(string $table, bool $strict = false): string
    {
        $tablePrefix = $this->tablePrefix;
        return match (true) {
            !str_starts_with($table, $tablePrefix)       => $table,                                                            // doesn't start with prefix
            $strict && $this->tableExists($table, false) => $table,                                                            // exists as baseTable already, meaning there's a double prefix
            default                                      => substr($table, strlen($tablePrefix)),                              // remove prefix
        };
    }

    /**
     * Returns the full table name with the current table prefix. Optionally verifies table exists.
     *
     * @param string $table The base table name
     * @param bool $strict If true, verifies table exists in database, only needed if table name may contain double prefix
     * @return string The full table name with prefix
     * @throws DBException
     */
    public function getFullTable(string $table, bool $strict = false): string
    {
        $tablePrefix = $this->tablePrefix;
        return match (true) {
            $strict && !$this->tableExists($table, true) => $tablePrefix . $table,                                      // doesn't exist, add prefix
            str_starts_with($table, $tablePrefix)        => $table,                                                     // already starts with prefix
            default                                      => $tablePrefix . $table,                                      // doesn't start with prefix, add prefix
        };
    }

    /**
     * Check if table exists in the database. Assumes baseTable unless isFullTable is true.
     *
     * @param string $table The table name to check
     * @param bool $isFullTable Whether the table name includes the prefix
     * @return bool True if the table exists
     * @throws DBException
     */
    public function tableExists(string $table, bool $isFullTable = false): bool
    {
        $tablePrefix = $this->tablePrefix;
        $fullTable   = $isFullTable ? $table : $tablePrefix . $table;
        return $this->query("SHOW TABLES LIKE ?", $fullTable)->count() > 0;
    }

    /**
     * Retrieve MySQL table names without the current table prefix.
     *
     * @param bool $includePrefix Whether to include the table prefix in the returned names
     * @return string[] Array of table names.
     * @throws DBException
     */
    public function getTableNames(bool $includePrefix = false): array
    {
        $tablePrefix = $this->tablePrefix;

        // get tables that start with the current table prefix
        $likePattern = $this->likeStartsWith($tablePrefix);
        $tableNames  = $this->query("SHOW TABLES LIKE ?", $likePattern)->pluckNth(0)->toArray();

        // sort _tables to the bottom
        $baseOffset = strlen($tablePrefix); // get baseTable offset, so we can check if first char is _
        usort($tableNames, fn($a, $b) => ($a[$baseOffset] === '_') <=> ($b[$baseOffset] === '_') ?: ($a <=> $b));

        // remove prefix
        if (!$includePrefix) {
            $tablePrefixRx = preg_quote($tablePrefix, "/");
            $tableNames    = preg_replace("/^$tablePrefixRx/", "", $tableNames);
        }

        return $tableNames;
    }

    /**
     * Get column definitions from a table, excluding redundant charset/collation settings.
     *
     * @param string $baseTable The base table name without prefix
     * @return array<string,string> Array of column name => column definition pairs
     */
    public function getColumnDefinitions(string $baseTable): array
    {
        $columnDefinitions = [];
        try {  // mysql throws an error if table doesn't exist
            $createTableSQL = $this->query('SHOW CREATE TABLE `::?`', $baseTable)->first()->nth(1)->value();
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
     * Returns a string that has been escaped for safe inclusion in raw SQL statements.
     * Does NOT add quotes.
     *
     * @param string|int|float|null|SmartString $input Value to escape
     * @param bool $escapeLikeWildcards Whether to also escape LIKE wildcards (% and _)
     * @return string Escaped string safe for inclusion in SQL
     */
    public function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        // Convert SmartString to raw value if needed
        $string = $input instanceof SmartString ? $input->value() : (string)$input;
        
        // Escape the string using mysqli
        $escaped = $this->connection->mysqli->real_escape_string($string);
        
        // Optionally escape LIKE wildcards
        if ($escapeLikeWildcards) {
            $escaped = addcslashes($escaped, '%_');
        }
        
        return $escaped;
    }

    /**
     * Creates a MySQL LIKE pattern for "column contains value" searches, e.g., '%value%'.
     * Escapes MySQL special characters and LIKE wildcards. Returns rawSQL for query params.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern wrapped in wildcards '%value%'
     */
    public function likeContains(string|int|float|null|SmartString $input): RawSql
    {
        $escaped = $this->escape($input, true);
        return new RawSql("%$escaped%");
    }

    /**
     * Creates a MySQL LIKE pattern for matching values in tab-delimited columns, e.g., '%\tValue\t%'.
     * Escapes MySQL special characters and LIKE wildcards. Returns rawSQL for query params.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern with tab delimiters '%\tValue\t%'
     */
    public function likeContainsTSV(string|int|float|null|SmartString $input): RawSql
    {
        $escaped = $this->escape($input, true);
        return new RawSql("%\t$escaped\t%");
    }

    /**
     * Creates a MySQL LIKE pattern for "column starts with value" searches, e.g., 'value%'.
     * Escapes MySQL special characters and LIKE wildcards. Returns rawSQL for query params.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern with trailing wildcard 'value%'
     */
    public function likeStartsWith(string|int|float|null|SmartString $input): RawSql
    {
        $escaped = $this->escape($input, true);
        return new RawSql("$escaped%");
    }

    /**
     * Creates a MySQL LIKE pattern for "column ends with value" searches, e.g., '%value'.
     * Escapes MySQL special characters and LIKE wildcards. Returns rawSQL for query params.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern with leading wildcard '%value'
     */
    public function likeEndsWith(string|int|float|null|SmartString $input): RawSql
    {
        $escaped = $this->escape($input, true);
        return new RawSql("%$escaped");
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
    public function escapeCSV(array $array): RawSql
    {
        $safeValues = [];
        foreach (array_unique($array) as $value) {                                                                                                                                                                                                                                                                                                         // Remove duplicates for efficiency
            $value        = $value instanceof SmartString ? (string)$value->value(
            ) : $value;                                                                                                                                                                                                                                                                                                                                    // Convert SmartString objects to string of raw value
            $safeValues[] = match (true) {
                is_int($value) || is_float($value) => $value,
                is_null($value)                    => 'NULL',
                is_bool($value)                    => $value ? 'TRUE' : 'FALSE',
                is_string($value)                  => "'" . $this->escape($value) . "'",
                default                            => throw new InvalidArgumentException("Unsupported value type: " . get_debug_type($value)),
            };
        }

        $sqlSafeCSV = $safeValues ? implode(',', $safeValues) : 'NULL';
        return new RawSQL($sqlSafeCSV);
    }

    /**
     * Generates a LIMIT/OFFSET SQL clause for pagination.
     *
     * @param mixed $pageNum The current page number. Must be numeric. Default value set to 1 if zero or negative.
     * @param mixed $perPage The number of records per page. Must be numeric. Default value set to 10 if zero or negative.
     *
     * @return RawSql LIMIT/OFFSET clause for SQL queries.
     */
    public function pagingSql(mixed $pageNum, mixed $perPage = 10): RawSql
    {
        // Force positive whole numbers
        $pageNum = abs((int)$pageNum) ?: 1;  // defaults to 1 (if set to 0)
        $perPage = abs((int)$perPage) ?: 10; // defaults to 10 (if set to 0)

        // Generate LIMIT/OFFSET SQL clause
        $offset         = ($pageNum - 1) * $perPage;
        $limitOffsetSQL = new RawSQL("LIMIT $perPage OFFSET $offset");

        return $limitOffsetSQL;
    }

    /**
     * indicate that a value is intended to be a SQL literal and not quoted, e.g., NOW(), CURRENT_TIMESTAMP, etc.
     */
    public function rawSql(string|int|float|null $value): RawSql
    {
        return new RawSql((string)$value);
    }

    /**
     * Tests if a value is a RawSql instance
     *
     * @param mixed $stringOrObj Value to test
     * @return bool Whether the value is a RawSql instance
     */
    public function isRawSql(mixed $stringOrObj): bool
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
    public function debug(): void
    {
        if ($this->resultSet !== null) {
            print_r($this->resultSet);
        } else {
            echo "No query has been executed yet.\n";
        }
    }

    /**
     * Sets the MySQL timezone to match the PHP timezone.
     *
     * @throws RuntimeException|Exception  If not connected to the database or if the set command fails.
     */
    public function setTimezoneToPhpTimezone(string $mysqlTzOffset = ''): void
    {
        $this->connection->setTimezoneToPhpTimezone(null, $mysqlTzOffset);
    }

    /**
     * Add "Occurred in file:line" to the end of the error messages with the first non-SmartArray file and line number.
     */
    public function occurredInFile($addReportedFileLine = false): string
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
    #region Internal Query Execution

    /**
     * Executes a query using this instance's connection and returns the results
     *
     * @param Parser $parser The parser containing the query and parameters
     * @param string $baseTable Optional base table name for smart joins
     * @return SmartArray The result set
     * @throws DBException
     */
    private function executeQuery(Parser $parser, string $baseTable = ''): SmartArray
    {
        try {
            // Create a options object with our behavior settings for the QueryExecutor
            $queryConfig = [
                'tablePrefix'           => $this->tablePrefix,
                'primaryKey'            => $this->primaryKey,
                'smartArrayLoadHandler' => $this->smartArrayLoadHandler,
                'showSqlInErrors'       => $this->showSqlInErrors,
                'usePreparedStatements' => $this->usePreparedStatements,
                'useSmartJoins'         => $this->useSmartJoins,
                'enableLogging'         => $this->enableLogging,
                'logFile'               => $this->logFile,
            ];

            // Create a QueryExecutor instance with our connection and settings
            $queryExecutor = new QueryExecutor(
                $this->connection,
                $queryConfig,
            );

            // Execute the query through the QueryExecutor
            return $queryExecutor->fetchAll($parser, $baseTable);
        } catch (Throwable $e) {
            throw new DBException("Error executing query: {$e->getMessage()}", 0, $e);
        }
    }

    #endregion
    #region Magic Methods

    /**
     * Prevents setting undefined properties
     *
     * @param string $name Property name being set
     * @param mixed $value Value being assigned to the property
     * @throws InvalidArgumentException Always throws an exception for undefined properties
     */
    public function __set(string $name, mixed $value): void
    {
        throw new InvalidArgumentException(
            "Attempting to set unknown instance property: '$name'. " .
            "Instance properties must be explicitly declared in the class.",
        );
    }

    /**
     * Prevents accessing undefined properties
     *
     * @param string $name Property name being accessed
     * @throws InvalidArgumentException Always throws an exception for undefined properties
     */
    public function __get(string $name): void
    {
        throw new InvalidArgumentException(
            "Attempting to get unknown instance property: '$name'. " .
            "Instance properties must be explicitly declared in the class.",
        );
    }

    #endregion
}
