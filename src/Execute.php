<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use mysqli;
use mysqli_result;

/**
 * Execute class for ZenDB - runs compiled SQL and processes results.
 *
 * Handles:
 * - Running SQL queries via mysqli
 * - Smart column mapping for multi-table joins
 * - Result set processing
 */
class Execute
{
    //region Properties & Constructor

    public function __construct(
        private mysqli $mysqli,
        private string $tablePrefix = '',
        private bool   $useSmartJoins = true,
    ) {}

    //endregion
    //region Execution

    /**
     * Execute SQL and return rows.
     *
     * @param string $sql Compiled SQL query
     * @return array Rows from query result
     */
    public function run(string $sql): array
    {
        return $this->fetchSmartRows($this->mysqli->query($sql));
    }

    //endregion
    //region Result Processing

    /**
     * Process mysqli result into rows with smart column mapping.
     *
     * Features:
     * - "First wins" rule: duplicate column names use the first occurrence
     * - Smart joins: multi-table queries get qualified names (e.g., 'users.name')
     * - Self-joins: adds alias-based names (e.g., 'a.name', 'b.name')
     */
    private function fetchSmartRows(mysqli_result|bool $mysqliResult): array
    {
        if (!$mysqliResult instanceof mysqli_result) {
            return [];  // INSERT/UPDATE/DELETE return true, not mysqli_result
        }

        // First pass: get single column names => indexes, and table aliases
        $columnMap    = [];                                                         // Column name to index, first wins, e.g., ['name' => 0, 'total' => 1]
        $tableAliases = [];                                                         // Table alias to name, e.g., ['u' => 'users']
        foreach ($mysqliResult->fetch_fields() as $index => $field) {
            $columnMap[$field->name] ??= $index;                                    // First wins for duplicate names
            if ($field->orgtable) {
                $tableAliases[$field->table] = $field->orgtable;                    // 'a' => 'users' or 'users' => 'users'
            }
        }

        // Second pass: if smart joins enabled AND multi-table query, add qualified names, e.g., 'users.name' => "John"
        if ($this->useSmartJoins && count($tableAliases) > 1) {
            $selfJoinTables = array_filter(array_count_values($tableAliases), fn($c) => $c > 1);

            foreach ($mysqliResult->fetch_fields() as $index => $field) {
                if ($field->orgtable && $field->orgname) {
                    // Strip table prefix to get base table name: 'cms_users' => 'users'
                    $hasPrefix      = $this->tablePrefix && str_starts_with($field->orgtable, $this->tablePrefix);
                    $fieldBaseTable = $hasPrefix ? substr($field->orgtable, strlen($this->tablePrefix)) : $field->orgtable;

                    $columnMap["$fieldBaseTable.$field->orgname"] ??= $index;       // e.g., 'users.name', first wins

                    // Self-joined tables: add table alias names as well (e.g., 'a.name', 'b.name')
                    if (isset($selfJoinTables[$field->orgtable])) {
                        $columnMap["$field->table.$field->orgname"] ??= $index;     // e.g., 'u.name', first wins
                    }
                }
            }
        }

        // Fetch all rows and remap to column names
        $rows = [];
        foreach ($mysqliResult->fetch_all(MYSQLI_NUM) as $values) {                 // e.g., ['John', 'john@example.com']
            $row = [];
            foreach ($columnMap as $name => $index) {
                $row[$name] = $values[$index];                                      // Remap indices to column names
            }
            $rows[] = $row;
        }

        $mysqliResult->free();

        return $rows;
    }

    //endregion
}
