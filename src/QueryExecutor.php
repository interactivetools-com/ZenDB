<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Itools\SmartArray\SmartArray;
use mysqli_driver, mysqli_result, mysqli_stmt;
use Throwable;

/**
 * Class to handle query execution and result fetching.
 */
class QueryExecutor
{
    // region Main

    /**
     * The database connection to use for queries
     */
    private Connection $connection;

    /**
     * The configuration settings to use
     */
    private Config $config;

    /**
     * Create a new QueryExecutor instance
     *
     * @param Connection $connection Database connection to use for queries
     * @param Config|null $config Configuration settings (optional - will use connection's config if not provided)
     */
    public function __construct(Connection $connection, ?Config $config = null)
    {
        $this->connection = $connection;
        $this->config     = $config ?? $connection->config;
    }

    /**
     * Executes a query and fetches the results
     *
     * @param Parser $parser The parser containing the query and parameters
     * @param string $baseTable Optional base table name for smart joins
     * @return SmartArray The result set
     * @throws DBException
     */
    public function fetchAll(Parser $parser, string $baseTable = ''): SmartArray
    {
        $parser->finalizeQuery();

        // throw exceptions for all MySQL errors, so we can catch them
        $oldReportMode = (new mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            // Choose the right execution path based on query type and config
            $usePreparedStatements = $this->config->usePreparedStatements && $parser->isDmlQuery();
            $escapedQuery          = $parser->getEscapedQuery(); // Always generate the escaped query for display purposes

            // Execute the query and get results
            if ($usePreparedStatements) {
                // DML queries (SELECT, INSERT, UPDATE, DELETE) - use prepared statements
                [$rows, $affectedRows, $insertId] = $this->fetchRowsFromMysqliStmt($parser);
            } else {
                // Non-DML queries don't support prepared statements (e.g., SHOW, DESCRIBE, etc.)
                // or forceEscapedQueries was enabled
                [$rows, $affectedRows, $insertId] = $this->fetchRowsFromMysqliResult($escapedQuery);
            }

            // Get the actual query type used (this is crucial for testing)
            $actualQueryType = $usePreparedStatements ? 'prepared' : 'escaped';

            // Build SmartArray options
            $options = [
                'useSmartStrings' => true,
                'loadHandler'     => $this->config->smartArrayLoadHandler,
                'mysqli'          => [
                    'query'         => $escapedQuery,
                    'baseTable'     => $baseTable,
                    'affected_rows' => $affectedRows,
                    'insert_id'     => $insertId,
                    'queryType'     => $actualQueryType,
                ],
            ];

            $smartRows = new SmartArray($rows, $options);
        } finally {
            // restore previous error reporting mode
            mysqli_report($oldReportMode);
        }

        // Check for any warnings or errors
        if ($this->connection->mysqli->warning_count > 0) {
            $warnings = $this->connection->mysqli->query("SHOW WARNINGS");
            if ($warnings) {
                while ($warning = $warnings->fetch_assoc()) {
                    // log warnings to the error log
                    if ($warning['Level'] !== 'Note') { // Levels: Note, Warning, Error
                        $warning = "MySQL Warning({$warning['Code']}): {$warning['Level']} - {$warning['Message']}\n";
                        @trigger_error($warning, E_USER_WARNING);
                        throw new DBException($warning);
                    }
                }
                $warnings->free();
            }
        }

        return $smartRows;
    }

    // endregion
    // region Direct Queries (escaped values)

    /**
     * Fetch all rows from a mysqli_result resource into associative arrays.
     * If Config::$useSmartJoins = true AND there are multiple tables in the query,
     * additional keys in "table.column" format are added to the rows at the end.
     *
     * @param string $escapedQuery The escaped query to execute
     * @return array An array of [rows, affectedRows, insertId]
     * @throws DBException
     */
    private function fetchRowsFromMysqliResult(string $escapedQuery): array
    {
        // Execute the query
        $result = $this->connection->mysqli->query($escapedQuery);
        if ($result === false) {
            throw new DBException("Error executing query: " . $this->connection->mysqli->error, $this->connection->mysqli->errno);
        }

        // Initialize common return values
        $affectedRows = $this->connection->mysqli->affected_rows;
        $insertId     = $this->connection->mysqli->insert_id;
        $rows         = [];

        // Fetch rows
        if ($result instanceof mysqli_result) {
            $fields             = $result->fetch_fields();
            $smartJoinColumnMap = $this->getSmartJoinColumnMap($fields);

            while ($rowData = $result->fetch_row()) {
                $rows[] = $this->createAssociativeRow($fields, $rowData, $smartJoinColumnMap);
            }
            $result->free(); // Free the result set
        }

        return [$rows, $affectedRows, $insertId];
    }

    // endregion
    // region Prepared Queries (bound parameters)

    /**
     * Fetch all rows from a mysqli_stmt into associative arrays.
     *
     * @param Parser $parser The parser containing the query and parameters
     * @return array An array of [rows, affectedRows, insertId]
     * @throws DBException
     */
    private function fetchRowsFromMysqliStmt(Parser $parser): array
    {
        // Prepare and execute statement
        $stmt = $this->prepareAndExecuteStatement($parser);

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
            $stmt->store_result(); // Store the result set in memory
            if ($stmt->bind_result(...$columnReferences)) {
                $smartJoinColumnMap = $this->getSmartJoinColumnMap($fields);
                while ($stmt->fetch()) {
                    $rows[] = $this->createAssociativeRow($fields, $rowData, $smartJoinColumnMap);
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
    private function prepareAndExecuteStatement(Parser $parser): mysqli_stmt
    {
        // 1) Prepare the statement
        $stmt = $this->connection->mysqli->prepare($parser->getParamQuery());
        if (!$stmt || $this->connection->mysqli->errno) {
            $errorMessage = match ($this->connection->mysqli->errno) {
                1146    => "Error: Invalid table name, use :: to insert table prefix if needed.",
                default => '',
            };
            throw new DBException($errorMessage, $this->connection->mysqli->errno);
        }

        // 2) Bind parameters and execute
        // If bindValues is not set, call getParamQuery to generate it
        if (!isset($parser->bindValues)) {
            $parser->getParamQuery();
        }
        if (!empty($parser->bindValues)) {
            // build bind_param() args
            $bindParamTypes = ''; // bind_param requires a string of types, e.g., 'sss' for 3 string parameters
            $bindParamRefs  = []; // bind_param requires referenced values, so we need to build an array of references
            foreach ($parser->bindValues as $key => $param) {
                $bindParamTypes .= match (gettype($param)) {
                    'integer' => 'i',
                    'double'  => 'd',
                    default   => 's',
                };
                /**
                 * @noinspection PhpArrayAccessCanBeReplacedWithForeachValueInspection
                 */
                $bindParamRefs[$key] = &$parser->bindValues[$key];
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
        } catch (Throwable $e) {
            $stmt->close(); // Clean up before rethrowing
            throw new DBException("Error executing statement: " . $e->getMessage(), 0, $e);
        }

        return $stmt;
    }

    // endregion
    // region Query Helpers

    /**
     * Creates an associative array row from field metadata and values
     *
     * @param array $fields Field metadata objects from result_metadata() or fetch_fields()
     * @param array $rowData Row data values (enumerated array)
     * @param array $smartJoinColumnMap Pre-calculated mapping of field indices to "baseTable.column" strings
     * @return array Associative array row with `table.column` keys if needed
     */
    private function createAssociativeRow(array $fields, array $rowData, array $smartJoinColumnMap = []): array
    {
        $assocRow     = [];
        $tableColKeys = []; // Store table.column keys separately to add at the end

        // Process each field/column
        foreach ($fields as $index => $field) {
            $columnName            = $field->name;
            $columnValue           = $rowData[$index];
            $assocRow[$columnName] = $columnValue;

            // SmartJoins: Add additional "baseTable.column" keys if defined
            if (isset($smartJoinColumnMap[$index])) {
                $tableColKeys[$smartJoinColumnMap[$index]] = $columnValue;
            }
        }

        // Append table.column keys at the end
        if (!empty($tableColKeys)) {
            $assocRow += $tableColKeys;
        }

        return $assocRow;
    }

    /**
     * Builds a mapping of field indices to their "baseTable.column" identifiers for SmartJoins
     *
     * @param array $fields Array of field metadata objects
     * @return array Mapping of field indices to "baseTable.column" identifiers
     */
    private function getSmartJoinColumnMap(array $fields): array
    {
        $useSmartJoins  = $this->config->useSmartJoins;
        $tablePrefix    = $this->config->tablePrefix ?? '';
        $tableColumnMap = [];

        // Skip if SmartJoins are not enabled or if not multiple tables
        if (!$useSmartJoins || !$this->hasMultipleTables($fields)) {
            return $tableColumnMap;
        }

        // Build the mapping of field indices to "baseTable.column" identifiers
        $baseTableOffset = strlen($tablePrefix);
        foreach ($fields as $index => $field) {
            if ($field->orgtable && $field->orgname) {
                $hasPrefix              = str_starts_with($field->orgtable, $tablePrefix);
                $baseTable              = $hasPrefix ? substr($field->orgtable, $baseTableOffset) : $field->orgtable;
                $tableColumnMap[$index] = "$baseTable.$field->orgname";
            }
        }
        return $tableColumnMap;
    }

    /**
     * Determines if a result set contains fields from multiple tables
     *
     * @param array $fields Array of field metadata objects from result_metadata() or fetch_fields()
     * @return bool True if fields are from multiple tables, false otherwise
     */
    private function hasMultipleTables(array $fields): bool
    {
        $distinctTables = [];
        foreach ($fields as $field) {
            if ($field->orgtable) {
                $distinctTables[$field->orgtable] = true;
            }
        }
        return count($distinctTables) > 1;
    }

    // endregion
}
