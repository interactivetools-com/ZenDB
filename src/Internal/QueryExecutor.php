<?php
declare(strict_types=1);

namespace Itools\ZenDB\Internal;

use Itools\ZenDB\Assert;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Parser;
use Itools\SmartArray\SmartArray;
use mysqli_driver;
use mysqli_result;
use InvalidArgumentException;
use mysqli_stmt;

/**
 * Internal class to handle query execution and result fetching.
 * Not meant to be used directly by end-users.
 */
class QueryExecutor
{
    #region Main
    /**
     * Executes a query and fetches the results
     *
     * @param Parser $parser The parser containing the query and parameters
     * @param string $baseTable Optional base table name for smart joins
     * @return SmartArray The result set
     * @throws DBException
     */
    public static function executeAndFetch(Parser $parser, string $baseTable = ''): SmartArray
    {
        $sqlTemplate = $parser->getSqlTemplate();
        MysqliWrapper::setLastQuery($sqlTemplate);                      // set $sqlTemplate as last query for debugging
        $sqlTemplate = self::allowTrailingLimit($sqlTemplate, $parser); // Handle trailing LIMIT clause if present
        self::validateSqlTemplate($sqlTemplate);                        // Validate SQL template, throw exception if invalid

        // throw exceptions for all MySQL errors, so we can catch them
        $oldReportMode = (new mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            // Choose the right execution path based on query type
            $useEscapedQuery = !$parser->isDmlQuery();                // use escaped value query for non-DML (Data Manipulation Language) queries
            $escapedQuery    = $parser->getEscapedQuery();

            // TODO: For testing both query types work with testplans
            //$useEscapedQuery = true;

            // Execute the query and get results
            if ($useEscapedQuery) {
                // Non-DML queries don't support prepared statements (e.g., SHOW, DESCRIBE, etc.)
                [$rows, $affectedRows, $insertId] = self::fetchRowsFromMysqliResult($escapedQuery);
            } else {
                // DML queries (SELECT, INSERT, UPDATE, DELETE) - use prepared statements
                [$rows, $affectedRows, $insertId] = self::fetchRowsFromMysqliStmt($parser);
            }

            $result = new SmartArray($rows, [
                'useSmartStrings' => true,
                'loadHandler'     => '\Itools\Cmsb\SmartArrayLoadHandler::load',
                'mysqli'          => [
                    'query'         => $escapedQuery,
                    'baseTable'     => $baseTable,
                    'affected_rows' => $affectedRows,
                    'insert_id'     => $insertId,
                ],
            ]);
        } finally {
            // restore previous error reporting mode
            mysqli_report($oldReportMode);
        }

        return $result;
    }

    /**
     * Special case handling for allowing trailing "LIMIT #" clauses (common use case)
     * Detects and parameterizes queries ending in "LIMIT" followed by any number.
     *
     * This pattern is safe because:
     *   1. The regex requires the query to end with "LIMIT #" (where # is a number)
     *   2. To bypass our filters the injected code would also need to end in "LIMIT #" and multiple limits are invalid
     *   3. We don't support multi_query() semicolon-separated queries like "SELECT...; DELETE..." and check ; isn't present
     *   4. There is no possible valid MySql code that both starts with LIMIT and ends with another LIMIT #
     *
     * Example injection that fails (even if the user writes insecure code):
     *   Template: "SELECT * FROM users LIMIT {$_GET['limit']}"
     *   Attack: "(SELECT id FROM admins LIMIT 1)"         // fails, doesn't end in a number
     *   Attack: "1; SELECT * FROM users LIMIT 1"          // fails, multiple queries not supported
     *   Attack: "1 INTO OUTFILE '/var/www/html/temp.php'" // fails, doesn't end in a number
     *
     * @param string $sqlTemplate The SQL template to check for trailing LIMIT
     * @param Parser $parser The parser to add parameter to
     * @return string The modified SQL template
     */
    private static function allowTrailingLimit(string $sqlTemplate, Parser $parser): string
    {
        $limitRx = '/\bLIMIT\s+\d+\s*$/i';
        if (!str_contains($sqlTemplate, ';') && preg_match($limitRx, $sqlTemplate, $matches)) {
            $limitExpr   = $matches[0];
            $sqlTemplate = preg_replace($limitRx, ':zdb_limit', $sqlTemplate);
            $parser->addInternalParam(':zdb_limit', DB::rawSql($limitExpr));
        }
        return $sqlTemplate;
    }

    /**
     * Validates the SQL template for safety and parameter constraints
     *
     * @param string $sqlTemplate The SQL template to validate
     * @return void
     * @throws InvalidArgumentException|DBException
     */
    private static function validateSqlTemplate(string $sqlTemplate): void
    {
        // Check for SQL injection risks
        Assert::SqlSafeString($sqlTemplate);

        // Check for too many positional parameters
        $positionalCount = substr_count($sqlTemplate, '?');
        if ($positionalCount > 4) {
            throw new InvalidArgumentException("Too many ? parameters, max 4 allowed. Try using :named parameters instead");
        }
    }

    #endregion
    #region Direct Queries (escaped values)

    /**
     * Fetch all rows from a mysqli_result resource into associative arrays.
     * If Config::$useSmartJoins = true AND there are multiple tables in the query,
     * additional keys in "table.column" format are added to the rows at the end.
     *
     * @param string $escapedQuery The escaped query to execute
     * @return array An array of [rows, affectedRows, insertId]
     * @throws DBException
     */
    private static function fetchRowsFromMysqliResult(string $escapedQuery): array
    {
        // Execute the query
        $result = DB::$mysqli->query($escapedQuery);
        if ($result === false) {
            throw new DBException("Error executing query: " . DB::$mysqli->error, DB::$mysqli->errno);
        }

        // Initialize common return values
        $affectedRows = DB::$mysqli->affected_rows;
        $insertId     = DB::$mysqli->insert_id;
        $rows         = [];

        // Fetch rows
        if ($result instanceof mysqli_result) {
            $fields        = $result->fetch_fields();
            $useSmartJoins = DB::config('useSmartJoins');
            while (($rowData = $result->fetch_row()) !== null) {
                $rows[] = self::createAssociativeRow($fields, $rowData, $useSmartJoins, false);
            }
            $result->free(); // Free the result set
        }

        // Handle potential multi-statements safely
        while (DB::$mysqli->more_results()) {
            if (!DB::$mysqli->next_result()) {
                throw new DBException("Error processing next result: " . DB::$mysqli->error, DB::$mysqli->errno);
            }

            $nextResult = DB::$mysqli->store_result();
            if ($nextResult instanceof mysqli_result) {
                $nextResult->free();
            }
        }

        return [$rows, $affectedRows, $insertId];
    }

    #endregion
    #region Prepared Queries (bound parameters)

    /**
     * Fetch all rows from a mysqli_stmt into associative arrays.
     * Doesn't rely on mysqlnd (no get_result()).
     * If Config::$useSmartJoins = true AND there are multiple tables in the query,
     * additional keys in "table.column" format are added to the rows at the end.
     *
     * @param Parser $parser The parser containing the query and parameters
     * @return array An array of [rows, affectedRows, insertId]
     * @throws DBException
     */
    private static function fetchRowsFromMysqliStmt(Parser $parser): array
    {
        // prepare
        $stmt = DB::$mysqli->prepare($parser->getParamQuery());
        if (!$stmt || DB::$mysqli->errno) {
            $errorMessage = match (DB::$mysqli->errno) {
                1146    => "Error: Invalid table name, use :: to insert table prefix if needed.",
                default => '',
            };
            throw new DBException($errorMessage, DB::$mysqli->errno);
        }

        // bind and execute
        self::executePreparedStatement($stmt, $parser->getBindValues());
        $affectedRows = $stmt->affected_rows;
        $insertId     = $stmt->insert_id;

        // If no metadata, there's no result-set (e.g. INSERT/UPDATE/DELETE).
        $meta = $stmt->result_metadata();
        if (!$meta) {
            $stmt->close();
            return [[], $affectedRows, $insertId];
        }

        // Buffer the entire result set so we can fetch row-by-row in a loop
        $stmt->store_result();

        $fields = $meta->fetch_fields();
        $meta->free();

        // Get smart join configuration
        $useSmartJoins = DB::config('useSmartJoins');

        // Prepare an array for row data and references for bind_result()
        $rowData = [];
        $refs    = [];
        foreach ($fields as $f) {
            // We'll store each column by $rowData['aliasName']
            $refs[] = &$rowData[$f->name];
        }

        // Bind the columns to the $refs array
        if (!$stmt->bind_result(...$refs)) {
            // handle error as needed
            $stmt->free_result();
            $stmt->close();
            return [[], $affectedRows, $insertId];
        }

        // Now fetch each row into $rowData, then copy it to a final assoc row
        $rows = [];
        while ($stmt->fetch()) {
            $rows[] = self::createAssociativeRow($fields, $rowData, $useSmartJoins, true);
        }

        // Clean up
        $stmt->free_result();
        $stmt->close();

        return [$rows, $affectedRows, $insertId];
    }

    /**
     * Prepares and executes a statement for DML queries
     *
     * @param mysqli_stmt $stmt The prepared statement to execute
     * @param array $bindValues Values to bind to the statement
     * @return void
     * @throws DBException
     */
    private static function executePreparedStatement(mysqli_stmt $stmt, array $bindValues): void
    {
        if (!empty($bindValues)) {
            // build bind_param() args
            $bindParamTypes = ''; // bind_param requires a string of types, e.g., 'sss' for 3 string parameters
            $bindParamRefs  = []; // bind_param requires referenced values, so we need to build an array of references
            foreach ($bindValues as $key => $param) {
                $bindParamTypes .= match (gettype($param)) {
                    'integer' => 'i',
                    'double'  => 'd',
                    default   => 's',
                };
                /** @noinspection PhpArrayAccessCanBeReplacedWithForeachValueInspection */ // we need the key for the reference
                $bindParamRefs[$key] = &$bindValues[$key];
            }

            // bind parameters
            if (!$stmt->bind_param($bindParamTypes, ...$bindParamRefs)) {
                throw new DBException("Error calling bind_param()");
            }
        }

        // execute statement
        $stmt->execute();
    }

    #endregion
    #region Query Helpers

    /**
     * Creates an associative array row from field metadata and values
     *
     * @param array $fields Field metadata objects
     * @param array $rowData Row data values
     * @param bool $useSmartJoins Whether to use smart joins
     * @param bool $isStmt Whether using mysqli stmt (true) or mysqli result (false)
     * @return array Associative array row with `table.column` keys if needed
     * @throws DBException
     */
    private static function createAssociativeRow(array $fields, array $rowData, bool $useSmartJoins, bool $isStmt): array
    {
        $assocRow     = [];
        $tableColKeys = []; // Store table.column keys separately to add at the end

        // Process each field/column
        foreach ($fields as $index => $field) {
            $columnName  = $field->name;
            $columnValue = $isStmt ? $rowData[$columnName] : $rowData[$index];

            // Cast column value to the correct type based on MySQL field type
            // Note: We now apply this to both query types since some drivers might not properly convert
            // certain types like DOUBLE even with prepared statements
            if (is_string($columnValue)) {
                $columnValue = match ($field->type) {
                    MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_YEAR => (int)$columnValue,
                    MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL                               => (float)$columnValue,
                    MYSQLI_TYPE_BIT                                                                                                  => (bool)$columnValue,
                    default                                                                                                          => $columnValue
                };
            }

            $assocRow[$columnName] = $columnValue;

            // SmartJoins: When multiple tables are used, add additional BaseTable.column keys
            if ($useSmartJoins && $field->orgtable && $field->orgname && self::hasMultipleTables($fields)) {
                $baseTable                         = DB::getBaseTable($field->orgtable); // remove table prefix
                $baseTableDotColumn                = "$baseTable.$field->orgname";      // e.g. `users.id`
                $tableColKeys[$baseTableDotColumn] = $columnValue;
            }
        }

        // Append table.column keys at the end
        // Using + operator is more efficient than array_merge() here
        // and ensures any numeric column names won't be re-indexed
        if (!empty($tableColKeys)) {
            $assocRow += $tableColKeys;
        }

        return $assocRow;
    }

    /**
     * Determines if a result set contains fields from multiple tables
     *
     * @param array $fields Array of field metadata objects from result_metadata() or fetch_fields()
     * @return bool True if fields are from multiple tables, false otherwise
     */
    private static function hasMultipleTables(array $fields): bool
    {
        $distinctTables = [];
        foreach ($fields as $field) {
            if ($field->orgtable) {
                $distinctTables[$field->orgtable] = true;
            }
        }
        return count($distinctTables) > 1;
    }
}
