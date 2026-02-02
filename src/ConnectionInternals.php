<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Itools\SmartArray\SmartArrayBase;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use InvalidArgumentException;
use mysqli_result;

/**
 * Query building and result processing internals for Connection.
 *
 * Handles:
 * - SQL clause building (SET, WHERE)
 * - Placeholder replacement (?, :name, `::?`, etc.)
 * - Result fetching with smart column mapping
 * - SmartArray wrapping
 */
trait ConnectionInternals
{
    //region Query Building

    /**
     * Build SET clause for INSERT/UPDATE.
     * Returns complete SQL with values escaped inline.
     *
     * @param array $colsToValues Column => value pairs
     * @return string SQL SET clause
     * @throws InvalidArgumentException
     */
    private function getSetClause(array $colsToValues): string
    {
        if (!$colsToValues) {
            throw new InvalidArgumentException("No colsToValues, please specify some column values");
        }

        $setElements = [];
        foreach ($colsToValues as $column => $value) {
            $this->assertValidColumn($column);
            $escaped = match (true) {
                is_null($value)                  => "NULL",
                is_int($value), is_float($value) => $value,
                is_bool($value)                  => $value ? 'TRUE' : 'FALSE',
                default                          => '"' . $this->mysqli->real_escape_string((string) $value) . '"',
            };
            $setElements[] = "`$column` = $escaped";
        }

        return "SET " . implode(", ", $setElements);
    }

    /**
     * Build WHERE clause from any input type (string, array, or int).
     * If $params passed, parses them for placeholder replacement.
     * @throws DBException
     */
    private function whereFromArgs(int|array|string $where, array $params = []): string
    {
        if ($params) {
            $this->paramValues = $this->parseParams($params);
        }
        return match (true) {
            is_string($where) => $this->whereFromString($where),
            is_array($where)  => $this->whereFromArray($where),
            is_int($where)    => $this->whereFromInt($where),
        };
    }

    /**
     * Build WHERE clause from string input (has placeholders like ? and :name).
     * Validates input, replaces placeholders, returns complete SQL.
     * @throws DBException
     */
    private function whereFromString(string $where): string
    {
        if (!$where) {
            return '';
        }

        // Validate - no quotes or numbers (must use placeholders)
        $this->assertSafeTemplate($where);

        // Prepend WHERE if not already present
        $hasLeadingKeyword = preg_match('/^\s*(WHERE|FOR|ORDER|LIMIT|OFFSET)\b/i', $where);
        if (!$hasLeadingKeyword) {
            $where = "WHERE $where";
        }

        // Replace ? and :name placeholders with escaped values
        return $this->replacePlaceholders($where);
    }

    /**
     * Build WHERE clause from array input (['column' => value]).
     * Returns complete SQL with values escaped inline.
     */
    private function whereFromArray(array $where): string
    {
        if (!$where) {
            return '';
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $this->assertValidColumn($column);
            $conditions[] = match (true) {
                is_null($value)                  => "`$column` IS NULL",
                is_int($value), is_float($value) => "`$column` = $value",
                is_bool($value)                  => "`$column` = " . ($value ? 'TRUE' : 'FALSE'),
                default                          => "`$column` = \"" . $this->mysqli->real_escape_string((string) $value) . "\"",
            };
        }

        return "WHERE " . implode(" AND ", $conditions);
    }

    /**
     * Build WHERE clause from int input (id lookup).
     */
    private function whereFromInt(int $id): string
    {
        return "WHERE `id` = $id";
    }

    /**
     * Replace placeholders with their escaped/formatted values and return final SQL.
     * If $params passed, parses them for placeholder replacement.
     *
     * Replacements:
     *   ?, :name           - quoted and escaped
     *   `?`, `:name`       - backtick-wrapped and unescaped, throws if unsafe chars
     *   `::?`, `:::name`   - same as above with table prefix prepended
     *   ::                 - table prefix alone
     *
     * @throws DBException
     */
    private function replacePlaceholders(string $template, array $params = []): string
    {
        if ($params) {
            $this->paramValues = $this->parseParams($params);
        }
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
                    is_string($value)                => '"' . $this->mysqli->real_escape_string($value) . '"',
                    is_int($value), is_float($value) => $value,
                    is_null($value)                  => 'NULL',
                    is_bool($value)                  => $value ? 'TRUE' : 'FALSE',
                    DB::isRawSql($value)             => (string) $value,
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
    private function getPlaceholderValue(string $match, int &$positionalCount): string|int|float|bool|null|RawSql
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
        if (!array_key_exists($paramKey, $this->paramValues)) {
            throw new DBException(
                $isPositional
                    ? "Missing value for ? parameter at position $positionalCount"
                    : "Missing value for '$paramKey' parameter",
            );
        }

        $value = $this->paramValues[$paramKey];
        return $addTablePrefix ? $this->tablePrefix . $value : $value;
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
    private function fetchMappedRows(mysqli_result|bool $mysqliResult): array
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

    /**
     * Wrap rows in a SmartArrayHtml with connection metadata.
     */
    private function toSmartArray(array $rows, string $sql, string $baseTable = ''): SmartArrayHtml
    {
        return new SmartArrayHtml($rows, [
            'useSmartStrings' => $this->useSmartStrings,
            'loadHandler'     => $this->smartArrayLoadHandler,
            'mysqli'          => [
                'query'         => $sql,
                'baseTable'     => $baseTable,
                'affected_rows' => $this->mysqli->affected_rows,
                'insert_id'     => $this->mysqli->insert_id,
            ],
        ]);
    }

    //endregion
    //region Validation

    /**
     * Validate table name contains only safe characters.
     * @throws InvalidArgumentException
     */
    private function assertValidTable(string $identifier): void
    {
        if (!preg_match('/^[\w-]+$/', $identifier)) {
            throw new InvalidArgumentException("Invalid table name '$identifier', allowed characters: a-z, A-Z, 0-9, _, -");
        }
    }

    /**
     * Validate column name contains only safe characters.
     * @throws InvalidArgumentException
     */
    private function assertValidColumn(string $identifier): void
    {
        if (!preg_match('/^[\w-]+$/', $identifier)) {
            throw new InvalidArgumentException("Invalid column name '$identifier', allowed characters: a-z, A-Z, 0-9, _, -");
        }
    }

    /**
     * Assert SQL template is safe - rejects quotes and standalone numbers.
     *
     * Forces developers to use placeholders instead of embedding values directly.
     * This catches accidental inclusion of user input in templates.
     *
     * @throws DBException
     */
    private function assertSafeTemplate(string $sql): void
    {
        // Standalone numbers - force use of placeholders
        if (preg_match('/\b(\d+)\b/', $sql, $matches)) {
            $n = $matches[1];
            throw new DBException("Standalone number in template. Replace $n with :n$n and add: [ ':n$n' => $n ]");
        }

        // Quotes - force use of placeholders
        if (preg_match('/[\'"]/', $sql, $matches)) {
            $quote      = $matches[0];
            $quotedText = preg_match('/(([\'"]).*?\2)/', $sql, $matches) ? $matches[1] : '';

            throw new DBException($quotedText
                ? "Quotes not allowed in template. Replace $quotedText with :paramName and add: [ ':paramName' => $quotedText ]"
                : "Quotes not allowed in template. Use :paramName placeholder instead.");
        }
    }

    /**
     * Reject numeric WHERE values - require array syntax instead.
     * Catches both int (legacy) and numeric strings, providing helpful migration guidance.
     * @throws InvalidArgumentException
     */
    private function rejectNumericWhere(int|array|string $where): void
    {
        $numericValue = match (true) {
            is_int($where)                                       => $where,
            is_string($where) && preg_match('/^\s*\d+\s*$/', $where) => trim($where),
            default                                              => null,
        };

        if ($numericValue !== null) {
            throw new InvalidArgumentException("Numeric where not allowed, use array syntax instead: ['id' => $numericValue]");
        }
    }

    /**
     * Reject LIMIT/OFFSET in WHERE clause - use select() instead.
     * @throws InvalidArgumentException
     */
    private function rejectLimitAndOffset(int|array|string $where): void
    {
        if (is_string($where) && preg_match('/\b(LIMIT|OFFSET)\s+[0-9:?]+\s*/i', $where)) {
            throw new InvalidArgumentException("This method doesn't support LIMIT or OFFSET, use select() instead");
        }
    }

    /**
     * Reject empty WHERE clause - prevents accidental bulk updates/deletes.
     *
     * Conditions like "num = ?" or "id = :id" are valid (WHERE gets prepended).
     * We reject empty input or strings starting with ORDER/LIMIT/OFFSET/FOR
     * which indicate no WHERE condition was provided.
     *
     * @throws InvalidArgumentException
     */
    private function rejectEmptyWhere(array|string $where, string $operation): void
    {
        $isInvalid = match (true) {
            is_array($where)  => !$where,
            is_string($where) => !trim($where) || preg_match('/^\s*(ORDER|LIMIT|OFFSET|FOR)\b/i', $where),
        };

        if ($isInvalid) {
            throw new InvalidArgumentException("$operation requires a WHERE condition to prevent accidental bulk $operation");
        }
    }

    //endregion
    //region Parameter Parsing

    /**
     * Parse variadic query args into a parameter map.
     *
     * Converts positional params (0, 1, 2) to named format (:1, :2, :3).
     * Validates named params start with ':' and don't use reserved ':zdb_' prefix.
     * Unwraps SmartString/SmartNull values.
     *
     * Supports:
     *   - query($sql, 'a', 'b', 'c')                    // Up to 3 positional args
     *   - query($sql, [':name' => 'Bob', ':age' => 45]) // Named params in array
     *   - query($sql, ['a', 'b', ':name' => 'c'])       // Mixed in array
     *
     * @param array $args Variadic args from query method
     * @return array Parameter map [':1' => 'value', ':name' => 'value2']
     * @throws InvalidArgumentException
     */
    private function parseParams(array $args): array
    {
        if (!$args) {
            return [];
        }

        // Validate format: either single array OR multiple non-array values
        $passedAsArray  = count($args) === 1 && is_array($args[0]);
        $passedAsValues = empty(array_filter($args, 'is_array'));
        if (!$passedAsArray && !$passedAsValues) {
            throw new InvalidArgumentException("Param args must be either a single array or multiple non-array values");
        }
        if (count($args) > 3 && !$passedAsArray) {
            throw new InvalidArgumentException("Max 3 positional arguments allowed. If you need more pass an array instead");
        }

        // Parse params into map
        $inputParams     = $passedAsArray ? $args[0] : $args;
        $values          = [];
        $positionalCount = 0;

        foreach ($inputParams as $key => $value) {
            // Determine param name
            if (is_int($key)) {
                $name = ':' . ++$positionalCount;
            } else {
                $name = $key;
                // Validate named param format
                if (!preg_match("/^:\w+$/", $name)) {
                    throw new InvalidArgumentException("Invalid param name '$name'. Must start with ':' followed by (a-z, A-Z, 0-9, _)");
                }
                if (str_starts_with($name, ':zdb_')) {
                    throw new InvalidArgumentException("Invalid param name '$name'. Names can't start with :zdb_ (reserved prefix)");
                }
            }

            // Check for duplicates
            if (array_key_exists($name, $values)) {
                throw new InvalidArgumentException("Duplicate param name '$name'");
            }

            // Unwrap SmartString/SmartNull, validate type
            $values[$name] = match (true) {
                !is_object($value)            => $value,
                $value instanceof RawSql      => $value,
                $value instanceof SmartString => $value->value(),
                $value instanceof SmartNull   => null,
                default                       => throw new InvalidArgumentException("Parameters cannot be " . get_debug_type($value) . "\n" . DB::occurredInFile()),
            };
        }

        return $values;
    }

    //endregion
    //region Magic Methods

    /**
     * Mark cloned connections as non-owners.
     */
    public function __clone(): void
    {
        $this->ownsConnection = false;
    }

    /**
     * Clean up connection on destruction.
     */
    public function __destruct()
    {
        // Only close connection if we own it
        if ($this->ownsConnection && $this->mysqli instanceof \mysqli) {
            try {
                // Drain any extra result sets
                while ($this->mysqli->more_results() && $this->mysqli->next_result()) {
                    // Drain
                }
                $this->mysqli->close();
            } catch (\Throwable) {
                // Never throw from a destructor
            }
            $this->mysqli = null;
        }
    }

    //endregion
    //region Internal State

    /**
     * Parameter values for current query (reset per query method call)
     */
    private array $paramValues = [];

    /**
     * Whether this instance owns (and should close) the mysqli connection.
     * Set to false for clones which share the connection.
     */
    private bool $ownsConnection = true;

    //endregion
    //region Connection Settings

    // Connection credentials (used during connect, cleared after)
    public ?string $hostname = null;
    public ?string $username = null;
    public ?string $password = null;
    public ?string $database = null;

    // Query behavior
    public bool $useSmartJoins   = true;
    public bool $useSmartStrings = true;
    public bool $usePhpTimezone  = true;

    // Result handling
    /** @var callable|null Custom handler for loading results */
    public mixed $smartArrayLoadHandler = null;

    // Error handling
    /** @var bool|callable Show SQL in exceptions - true, false, or callable returning bool */
    public mixed $showSqlInErrors = false;

    // Advanced connection settings
    public string $versionRequired    = '5.7.32';
    public bool   $requireSSL         = false;
    public bool   $databaseAutoCreate = true;
    public int    $connectTimeout     = 3;
    public int    $readTimeout        = 60;
    public bool   $enableLogging      = false;
    public string $logFile            = '_mysql_query_log.php';
    public string $sqlMode            = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    // Debug mode
    public bool $debugMode = false;

    /** @var callable|null Callback that returns web root path for relative file paths in debug output */
    public mixed $webRootCallback = null;

    //endregion
}
