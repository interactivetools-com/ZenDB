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
        $sqlTemplate = $parser->getFinalizedSqlTemplate();

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

            $smartRows = new SmartArray($rows, [
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

        return $smartRows;
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
                $rows[] = self::createAssociativeRow($fields, $rowData, $useSmartJoins);
            }
            $result->free(); // Free the result set
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
        // Prepare and execute statement
        $stmt         = self::prepareAndExecuteStatement($parser);

        // Initialize common return values
        $affectedRows = $stmt->affected_rows;
        $insertId     = $stmt->insert_id;
        $rows         = [];

        // Get rows (if any)
        $meta = $stmt->result_metadata();
        if ($meta) {
            // Get Field metadata
            $fields = $meta->fetch_fields();

            // Prepare references for bind_result()
            $rowData          = [];
            $columnReferences = [];
            foreach ($fields as $i => $field) {
                $rowData[$i]        = null;
                $columnReferences[] = &$rowData[$i];
            }

            // Bind columns & fetch rows
            $stmt->store_result();
            if ($stmt->bind_result(...$columnReferences)) {
                $useSmartJoins = DB::config('useSmartJoins');
                while ($stmt->fetch()) {
                    $rows[] = self::createAssociativeRow($fields, $rowData, $useSmartJoins);
                }
            }

            // Free metadata and result set
            $meta->free();
            $stmt->free_result();
        }

        // Free statement
        $stmt->close();

        return [$rows, $affectedRows, $insertId];
    }

    /**
     * Prepares and executes a statement for DML queries
     *
     * @param Parser $parser The parser containing the query and parameters
     * @return mysqli_stmt The prepared and executed statement
     * @throws DBException
     */
    private static function prepareAndExecuteStatement(Parser $parser): mysqli_stmt
    {
        // 1) Prepare the statement
        $stmt = DB::$mysqli->prepare($parser->getParamQuery());
        if (!$stmt || DB::$mysqli->errno) {
            $errorMessage = match (DB::$mysqli->errno) {
                1146    => "Error: Invalid table name, use :: to insert table prefix if needed.",
                default => '',
            };
            throw new DBException($errorMessage, DB::$mysqli->errno);
        }

        // 2) Bind parameters and execute
        $bindValues = $parser->getBindValues();
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
                $stmt->close(); // Clean up before throwing
                throw new DBException("Error calling bind_param()");
            }
        }

        // 3) Execute statement
        try {
            $stmt->execute();
        } catch (DBException $e) {
            $stmt->close(); // Clean up before rethrowing
            throw $e;
        }

        return $stmt;
    }

    #endregion
    #region Query Helpers

    /**
     * Creates an associative array row from field metadata and values
     *
     * @param array $fields Field metadata objects from result_metadata() or fetch_fields()
     * @param array $rowData Row data values (enumerated array)
     * @param bool $useSmartJoins Whether to use smart joins
     * @return array Associative array row with `table.column` keys if needed
     * @throws DBException
     */
    private static function createAssociativeRow(array $fields, array $rowData, bool $useSmartJoins): array
    {
        $assocRow     = [];
        $tableColKeys = []; // Store table.column keys separately to add at the end

        // Create a lookup for field index to baseTable (to avoid calling DB::getBaseTable repeatedly)
        $baseTables = [];
        foreach ($fields as $index => $field) {
            if ($field->orgtable) {
                $baseTables[$index] = DB::getBaseTable($field->orgtable);
            }
        }

        // Process each field/column
        $hasMultipleTables = $useSmartJoins && self::hasMultipleTables($fields);
        foreach ($fields as $index => $field) {
            $columnName  = $field->name;
            $columnValue = $rowData[$index];

            // Cast column value to the correct type based on MySQL field type, some MySQL drivers return all values as strings,
            // while others might return some type such as DOUBLE as an incorrect type
            if (is_string($columnValue)) {
                $columnValue = match ($field->type) {
                    MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_YEAR => (int)$columnValue,
                    MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL                               => (float)$columnValue,
                    default                                                                                                          => $columnValue
                };
            }
            $assocRow[$columnName] = $columnValue;

            // SmartJoins: When multiple tables are used, add additional BaseTable.column keys
            if ($useSmartJoins && $hasMultipleTables && isset($baseTables[$index]) && $field->orgname) {
                $baseTable                         = $baseTables[$index];          // Use cached baseTable
                $baseTableDotColumn                = "$baseTable.$field->orgname"; // e.g. `users.id`
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
