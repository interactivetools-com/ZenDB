<?php
declare(strict_types=1);
namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayHtml;
use mysqli;
use mysqli_result;
use Throwable;

/**
 * Query class for ZenDB
 *
 * Builds and executes SQL queries with parameter support (named and positional).
 */
class Query
{
    // Connection settings
    private mysqli  $mysqli;
    private string  $tablePrefix;
    private string  $primaryKey;
    private bool    $useSmartJoins;
    private bool    $useSmartStrings;
    private mixed   $loadHandler;

    // Parameter collection
    public Params $params;

    // Generated clause properties (populated by constructor options)
    public string $baseTable = '';
    public string $whereEtc  = '';
    public string $setClause = '';

    //region Constructor

    /**
     * Create a new Query instance.
     *
     * @param mysqli                $mysqli          The mysqli connection to use
     * @param string                $tablePrefix     The table prefix for :: placeholders
     * @param string                $primaryKey      The primary key column name for int shortcuts
     * @param bool                  $useSmartJoins   Add qualified column names for multi-table queries
     * @param bool                  $useSmartStrings Wrap values in SmartString objects
     * @param callable|null         $loadHandler     SmartArray load handler callback
     * @param string|null           $baseTable       Table name (auto-validated, stored in $this->baseTable)
     * @param array                 $params          Parameters to bind (auto-added to $this->params)
     * @param int|array|string|null $where           WHERE condition (generates $this->whereEtc)
     * @param bool                  $whereRequired   Require WHERE clause (for destructive operations)
     * @param array|null            $set             Column => value pairs (generates $this->setClause)
     * @throws DBException
     */
    public function __construct(
        mysqli                $mysqli,
        string                $tablePrefix     = '',
        string                $primaryKey      = '',
        bool                  $useSmartJoins   = true,
        bool                  $useSmartStrings = true,
        ?callable             $loadHandler     = null,
        ?string               $baseTable       = null,
        array                 $params          = [],
        int|array|string|null $where           = null,
        bool                  $whereRequired   = false,
        ?array                $set             = null,
    ) {
        $this->mysqli          = $mysqli;
        $this->tablePrefix     = $tablePrefix;
        $this->primaryKey      = $primaryKey;
        $this->useSmartJoins   = $useSmartJoins;
        $this->useSmartStrings = $useSmartStrings;
        $this->loadHandler     = $loadHandler;
        $this->params          = new Params();

        // Process optional convenience parameters
        if ($baseTable !== null) {
            self::validTableName($baseTable);
            $this->baseTable = $baseTable;
        }

        if ($params) {
            $this->params->addFromArgs($params);
        }

        if ($set !== null) {
            $this->setClause = $this->getSetClause($set);
        }

        if ($where !== null) {
            $this->whereEtc = $this->getWhereEtc($where, $whereRequired);
        }
    }

    //endregion
    //region Query Generation

    /**
     * Compile SQL template with escaped parameter values.
     *
     * @param string $template SQL template with placeholders (?, :name, ::, etc.)
     * @return string Final SQL query ready to execute
     * @throws DBException
     */
    public function getSql(string $template): string
    {
        $template = rtrim($template); // trim trailing space from "...query $whereEtc" with no where

        // Check for too many positional parameters
        if (substr_count($template, '?') > 4) {
            throw new InvalidArgumentException("Too many ? parameters, max 4 allowed. Try using :named parameters instead");
        }

        $escapedQuery = $this->replacePlaceholders($template);

        // ltrim each line for a multiline string (for better formatting in logs and debug output)
        return preg_replace('/^ +/m', '', $escapedQuery);
    }

    /**
     * Replace placeholders with their escaped/formatted values and return final SQL.
     *
     * Replacements:
     *   ?, :name           - quoted and escaped
     *   `?`, `:name`       - backtick-wrapped and unescaped, throws if unsafe chars
     *   `::?`, `:::name`   - same as above with table prefix prepended
     *   ::                 - table prefix alone
     *
     * @throws DBException
     */
    private function replacePlaceholders(string $template): string
    {
        // Normalize :_ to :: (deprecated syntax)
        $template = str_replace(':_', '::', $template);

        // Placeholder types
        $placeholderRegex = '/' . implode("|", [
                // Values - quoted and escaped
                "\?",                   // ?         O'Brien → "O\'Brien"
                ":[a-zA-Z]\w*\b",       // :name     O'Brien → "O\'Brien"
                // `Identifiers` - table/column names (unquoted, unescaped, throws if unsafe chars)
                "`\?`",                 // `?`       users → `users`
                "`:[a-zA-Z]\w*\b`",     // `:name`   users → `users`
                // `::Identifiers` - with table prefix (unquoted, unescaped, throws if unsafe chars)
                "`::\?`",               // `::?`     users → `cms_users`
                "`:::[a-zA-Z]\w*\b`",   // `:::name` users → `cms_users`
                // Table prefix alone
                "::",                   // e.g., SELECT * FROM ::users → SELECT * FROM cms_users
            ]) . '/';

        // Find and replace all placeholders with their escaped/formatted values
        $positionalCount = 0;
        return preg_replace_callback(
            pattern: $placeholderRegex,
            callback: function ($matches) use (&$positionalCount) {
                $match = $matches[0]; // e.g., ?, :name, `?`, etc
                $value = $this->getPlaceholderValue($match, $positionalCount);

                // Backtick placeholders: insert safe identifiers (table/column names) unquoted (or throw if unsafe)
                if ($match[0] === '`') {
                    $isSafeIdentifier = is_string($value) && !preg_match('/[^\w-]/', $value);
                    return $isSafeIdentifier ? "`$value`" : throw new DBException("Invalid backtick identifier: " . var_export($value, true) . ". Only word characters (a-z, 0-9, _, -) allowed.");
                }

                // Regular placeholders: escape and quote values based on type
                return match (true) {
                    is_string($value)                => '"' . $this->mysqli->real_escape_string($value) . '"', // Quote and escape string values
                    is_int($value), is_float($value) => $value,                                                // Return int/float as is
                    is_null($value)                  => 'NULL',                                                // NULL values should be SQL NULL keyword
                    is_bool($value)                  => $value ? 'TRUE' : 'FALSE',                             // Boolean values as SQL keywords
                    DB::isRawSql($value)             => (string)$value,                                        // DB::rawSql("...") passed unquoted
                    default                          => throw new InvalidArgumentException("Invalid type for $match: $value"),
                };
            },
            subject: $template,
        );
    }

    /**
     * Maps a placeholder match to its corresponding value from the param map.
     *
     * Handles these placeholder styles:
     *   - Positional:  ?                  → returns param value by position (:1, :2, ...)
     *   - Named:       :name              → returns param value for :name
     *   - Prefixed:    `::?` or `:::name` → returns table prefix + value
     *   - Bare prefix: ::                 → returns table prefix as RawSql
     *
     * @throws DBException If placeholder has no corresponding param
     */
    private function getPlaceholderValue($match, &$positionalCount): string|int|float|bool|null|RawSql
    {
        // Handle bare :: (table prefix alone)
        if ($match === '::') {
            return DB::rawSql($this->tablePrefix);
        }

        // Parse placeholder: strip backticks and :: prefix
        $addTablePrefix = str_starts_with($match, "`::");                           // e.g., `::?` or `:::name`
        $placeholder    = preg_replace("/^::/", "", trim($match, '`'));             // e.g., `:::name` → :name, `::?` → ?

        // Look up value in param map
        $isPositional = ($placeholder === '?');
        $paramKey     = $isPositional ? ':' . ++$positionalCount : $placeholder;    // ? → :1, :2, :3; :name stays as-is
        if (!array_key_exists($paramKey, $this->params->values)) {
            throw new DBException(
                $isPositional
                    ? "Missing value for ? parameter at position $positionalCount"
                    : "Missing value for '$paramKey' parameter",
            );
        }

        $value = $this->params->values[$paramKey];
        return $addTablePrefix ? $this->tablePrefix . $value : $value;
    }

    //endregion
    //region Execution

    /**
     * Execute query and return result set.
     *
     * @param string $template SQL template with placeholders
     * @return SmartArrayHtml Result set
     * @throws DBException
     * @throws Throwable
     */
    public function execute(string $template): SmartArrayHtml
    {
        MysqliWrapper::setLastQuery($template);

        // Handle trailing LIMIT # clauses
        $limitRx = '/\bLIMIT\s+\d+\s*$/i';
        if (!str_contains($template, ';') && preg_match($limitRx, $template, $matches)) {
            $limitExpr = $matches[0];
            $template  = preg_replace($limitRx, ':zdb_limit', $template);
            $this->params->addInternal(':zdb_limit', DB::rawSql($limitExpr));
        }

        // Template error checking
        self::sqlSafeString($template);

        // Execute query and return results
        $sql  = $this->getSql($template);
        $rows = $this->fetchSmartRows($this->mysqli->query($sql));

        return new SmartArrayHtml($rows, [
            'useSmartStrings' => $this->useSmartStrings,
            'loadHandler'     => $this->loadHandler,
            'mysqli'          => [
                'query'         => $sql,
                'baseTable'     => $this->baseTable,
                'affected_rows' => $this->mysqli->affected_rows,
                'insert_id'     => $this->mysqli->insert_id,
            ],
        ]);
    }

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
    //region Clause Builders

    /**
     * Build WHERE clause from various input types.
     *
     * @param int|array|string $where         Record ID, column=>value array, or SQL string
     * @param bool             $whereRequired Require WHERE clause (for destructive operations)
     * @return string SQL WHERE clause (or empty string)
     * @throws DBException|InvalidArgumentException
     */
    public function getWhereEtc(int|array|string $where, bool $whereRequired = false): string
    {
        // Get SQL clauses from int|array|string
        if (is_int($where)) {
            if (!$this->primaryKey) {
                throw new InvalidArgumentException("Primary key not defined in config");
            }
            $whereEtc = "WHERE `$this->primaryKey` = ?";
            $this->params->addPositional($where);
        } elseif (is_array($where)) {
            $conditions = [];
            foreach ($where as $column => $value) {
                self::validColumnName($column);
                if (is_null($value)) {
                    $conditions[] = "`$column` IS NULL";
                } else {
                    $conditions[] = "`$column` = ?";
                    $this->params->addPositional($value);
                }
            }
            $whereEtc = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
        } elseif (is_string($where)) {
            if (preg_match("/^\s*\d+\s*$/", $where)) {
                throw new InvalidArgumentException("Numeric string detected, convert to integer with (int) \$num to search by record number");
            }
            $whereEtc = $where;
        } else {
            throw new InvalidArgumentException("Invalid type for \$where: " . gettype($where));
        }

        // Add WHERE if not already present
        $requiredLeadingKeyword = "/^\s*(WHERE|FOR|ORDER|LIMIT|OFFSET)\b/i";
        if (!preg_match($requiredLeadingKeyword, $whereEtc) &&
            !preg_match("/^\s*$/", $whereEtc)) {
            $whereEtc = "WHERE " . $whereEtc;
        }

        // Validate
        if ($whereEtc !== "") {
            if (!preg_match($requiredLeadingKeyword, $whereEtc)) {
                throw new InvalidArgumentException("SQL clauses must start with one of: WHERE, ORDER BY, LIMIT, OFFSET. Got: $whereEtc");
            }
            self::sqlSafeString($whereEtc);
        }

        // Require WHERE for destructive operations
        if ($whereRequired && !preg_match('/^\s*WHERE\b/i', $whereEtc)) {
            throw new InvalidArgumentException("No where condition, operations that can change multiple rows require a WHERE condition");
        }

        return $whereEtc;
    }

    /**
     * Build SET clause for INSERT/UPDATE.
     *
     * @param array $colsToValues Column => value pairs
     * @return string SQL SET clause
     * @throws InvalidArgumentException
     */
    public function getSetClause(array $colsToValues): string
    {
        if (!$colsToValues) {
            throw new InvalidArgumentException("No colsToValues, please specify some column values");
        }

        $setElements          = [];
        $tempPlaceholderCount = 0;
        foreach ($colsToValues as $column => $value) {
            self::validColumnName($column);

            $tempPlaceholderCount++;
            $tempPlaceholder = ":zdb_$tempPlaceholderCount";
            $setElements[]   = "`$column` = $tempPlaceholder";

            $this->params->addInternal($tempPlaceholder, $value);
        }

        return "SET " . implode(", ", $setElements);
    }

    //endregion
    //region Validation

    /**
     * Validate table name contains only safe characters.
     * @throws InvalidArgumentException
     */
    public static function validTableName(string $identifier): void
    {
        if (!preg_match('/^[\w-]+$/', $identifier)) {
            throw new InvalidArgumentException("Invalid table name '$identifier', allowed characters: a-z, A-Z, 0-9, _, -");
        }
    }

    /**
     * Validate column name contains only safe characters.
     * @throws InvalidArgumentException
     */
    public static function validColumnName(string $identifier): void
    {
        if (!preg_match('/^[\w-]+$/', $identifier)) {
            throw new InvalidArgumentException("Invalid column name '$identifier', allowed characters: a-z, A-Z, 0-9, _, -");
        }
    }

    /**
     * Assert that a SQL string is safe to use in a query.
     * @throws DBException
     */
    public static function sqlSafeString(string $string, ?string $inputName = null, bool $allowNumbers = false): void
    {
        $inputName ??= "sql template";

        // Standalone numbers - prevent raw numbers that could be SQL injection vectors
        if (!$allowNumbers && preg_match('/\b(\d+)\b/', $string, $matches)) {
            $n = $matches[1];
            throw new DBException("Disallowed standalone number in $inputName. Replace $n with :n$n and add a named parameter: [ ':n$n' => $n ]");
        }

        // Quote characters - force use of placeholders
        if (preg_match('/[\'"]/', $string, $matches)) {
            $quote        = $matches[0];
            $quotedText   = preg_match('/(([\'"]).*?\2)/', $string, $matches) ? $matches[1] : '';
            $quoteContext = substr($string, max(0, strpos($string, $quote) - 15), 30);

            $error = $quotedText ? "Quotes are not allowed in $inputName, replace $quotedText with a :paramName and add: [ ':paramName' => $quotedText ]"
                : "Quotes are not allowed in $inputName, found $quote in: ...$quoteContext...";
            throw new DBException($error);
        }

        // Other unsafe characters
        $error = match (true) {
            str_contains($string, "\\")   => "Backslashes (\\) are not allowed in $inputName.",
            str_contains($string, "\x00") => "Disallowed NULL character in $inputName.",
            str_contains($string, "\x1a") => "Disallowed CTRL-Z character in $inputName.",
            default                       => null,
        };

        if (isset($error)) {
            throw new DBException($error);
        }
    }

    //endregion
}
