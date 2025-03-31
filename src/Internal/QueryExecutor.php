<?php
declare(strict_types=1);

namespace Itools\ZenDB\Internal;

use Exception;
use Itools\ZenDB\Assert;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Parser;
use Itools\ZenDB\Internal\MysqliStmtResultEmulator;
use Itools\SmartArray\SmartArray;
use mysqli;
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
        MysqliWrapper::setLastQuery($sqlTemplate);  // set $sqlTemplate as last query for debugging

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
         */
        $limitRx = '/\bLIMIT\s+\d+\s*$/i';
        if (!str_contains($sqlTemplate, ';') && preg_match($limitRx, $sqlTemplate, $matches)) {
            $limitExpr   = $matches[0];
            $sqlTemplate = preg_replace($limitRx, ':zdb_limit', $sqlTemplate);
            $parser->addInternalParam(':zdb_limit', DB::rawSql($limitExpr));
        }

        // sqlTemplate Error Checking
        Assert::SqlSafeString($sqlTemplate);

        // check for too many positional parameters
        $positionalCount = substr_count($sqlTemplate, '?');
        if ($positionalCount > 4) {
            throw new InvalidArgumentException("Too many ? parameters, max 4 allowed. Try using :named parameters instead");
        }

        // throw exceptions for all MySQL errors, so we can catch them
        $oldReportMode = (new mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // Prepare, bind, and execute statement
        $useEscapedQuery = !$parser->isDmlQuery();                // use escaped value query for non-DML (Data Manipulation Language) queries
        $escapedQuery    = $parser->getEscapedQuery();

        if ($useEscapedQuery) {                               // non-DML queries don't support prepared statements, e.g., SHOW, DESCRIBE, etc.
            $mysqliResult = DB::$mysqli->query($escapedQuery);  // returns true|false|mysqli_result
            $mysqliOrStmt = DB::$mysqli;
        } else {
            [$mysqliResult, $mysqliStmt] = self::prepareBindExecute($parser);
            $mysqliOrStmt = $mysqliStmt;
        }

        // get mysql info before we close the statement
        $rows         = self::fetchRows($mysqliResult);
        $affectedRows = $mysqliOrStmt->affected_rows;
        $insertId     = $mysqliOrStmt->insert_id;

        // restore previous error reporting mode and clean up
        mysqli_report($oldReportMode);
        if ($mysqliOrStmt instanceof mysqli_stmt) {
            $mysqliOrStmt->free_result();
            $mysqliOrStmt->close();
        }
        if ($mysqliOrStmt instanceof mysqli) {
            if ($mysqliResult instanceof mysqli_result) {
                $mysqliResult->free();
            }
            while (DB::$mysqli->more_results()) {
                DB::$mysqli->next_result();
                if ($nextResult = DB::$mysqli->store_result()) {
                    $nextResult->free();
                }
            }
        }

        // return SmartArray object
        return new SmartArray($rows, [
            'useSmartStrings'  => true,
            'loadHandler'      => '\Itools\Cmsb\SmartArrayLoadHandler::load',
            'mysqli' => [
                'query'         => $escapedQuery,
                'baseTable'     => $baseTable,
                'affected_rows' => $affectedRows,
                'insert_id'     => $insertId,
            ],
        ]);
    }

    /**
     * @param Parser $parser
     * @return array [mysqliResult, mysqliStmt]
     * @throws DBException
     */
    private static function prepareBindExecute(Parser $parser): array
    {
        // prepare
        $mysqliStmt = DB::$mysqli->prepare($parser->getParamQuery());
        if (DB::$mysqli->errno || !$mysqliStmt) {
            $errorMessage = "";
            if (DB::$mysqli->errno === 1146) {
                $errorMessage = "Error: Invalid table name, use :_ to insert table prefix if needed.";
            }
            throw new DBException($errorMessage, DB::$mysqli->errno);
        }

        // bind
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
            if (!$mysqliStmt->bind_param($bindParamTypes, ...$bindParamRefs)) {
                throw new DBException("Error calling bind_param()");
            }
        }

        // execute statement
        $mysqliStmt->execute();

        // mysqliStmt->get_result() method is only available when mysqlnd is installed
        if (method_exists($mysqliStmt, 'get_result')) {
            // returns mysqli_result object or false (for queries that don't return rows, e.g., INSERT, UPDATE, DELETE, etc.)
            $mysqliResult = $mysqliStmt->get_result();
        } else {
            // returns mysqli_result object or false (for queries that don't return rows, e.g., INSERT, UPDATE, DELETE, etc.)
            $mysqliResult = new MysqliStmtResultEmulator($mysqliStmt);
        }

        return [$mysqliResult, $mysqliStmt];
    }

    /**
     * Fetches rows from mysqli result
     * @throws DBException
     */
    private static function fetchRows(bool|mysqli_result|MysqliStmtResultEmulator $mysqliResult): array
    {
        // load rows
        $rows = [];
        $hasRows = $mysqliResult instanceof mysqli_result || $mysqliResult instanceof MysqliStmtResultEmulator;

        if ($hasRows) {  // returns true|false for queries that don't return a resultSet
            $colNameToIndex = self::getColumnNameToIndex($mysqliResult);

            // if not using smart joins, just return the next row
            if (!DB::config('useSmartJoins')) {
                $rows = $mysqliResult->fetch_all(MYSQLI_ASSOC);
            }
            else { // use smart joins
                while ($rowObj = self::loadNextRow($mysqliResult, $colNameToIndex)) {
                    $rows[] = $rowObj;
                }
            }
        }

        return $rows;
    }

    /**
     * Gets a mapping of column names to indices
     * @throws DBException
     */
    private static function getColumnNameToIndex(mysqli_result|MysqliStmtResultEmulator $mysqliResult): array
    {
        // Check for multiple tables (from JOIN queries) and create a map for column indices to their field names.
        $colNameToIndex   = [];   // first defined: alias or column name
        $colFQNameToIndex = [];   // Fully qualified name (baseTable.column)
        $tableCounter     = [];

        foreach ($mysqliResult->fetch_fields() as $index => $fieldInfo) {
            $colNameToIndex[$fieldInfo->name] ??= $index; // maintain first index assigned so duplicate column names don't overwrite each other

            // Collect all fully-qualified column names, we'll only add them later if there's multiple tables
            if ($fieldInfo->orgtable && $fieldInfo->orgname) {
                $fqName                             = DB::getBaseTable($fieldInfo->orgtable) . '.' . $fieldInfo->orgname;
                $colFQNameToIndex[$fqName]          = $index;
                $tableCounter[$fieldInfo->orgtable] = true;
            }
        }

        // Add fully qualified column names, e.g. users.id
        $isMultipleTables = count($tableCounter) > 1;
        if ($isMultipleTables) {
            $colNameToIndex = array_merge($colNameToIndex, $colFQNameToIndex);
        }

        return $colNameToIndex;
    }

    /**
     * Loads the next row from the result
     * @throws DBException
     * @throws Exception
     */
    private static function loadNextRow(mysqli_result|MysqliStmtResultEmulator $mysqliResult, array $colNameToIndex): array|bool
    {
        // try to get next row - or return if done
        $result = $mysqliResult->fetch_row();
        if (is_null($result)) { // no more rows
            return false;
        }

        // error checking
        if ($result === false || DB::$mysqli->error) {  // Check for fetch errors, if necessary
            throw new DBException("Failed to fetch row");
        }

        // process next row
        $colsToValues = [];
        $indexedRow   = $result;
        foreach ($colNameToIndex as $colName => $colIndex) {
            $colsToValues[$colName] = $indexedRow[$colIndex];
        }

        return $colsToValues;
    }
}
