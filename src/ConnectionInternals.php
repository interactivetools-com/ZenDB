<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayBase;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use Throwable;
use mysqli;
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
    //region Parameter Parsing

    /**
     * Parameter values for current query (reset per query method call)
     */
    private array $paramValues = [];

    /**
     * Parse variadic query args into a parameter map.
     *
     * Converts positional params (0, 1, 2) to named format (:1, :2, :3).
     * Validates named params start with ':' and don't use reserved ':zdb_' prefix.
     * Unwraps SmartString/SmartNull values.
     *
     * Supports:
     *   - query($sql, 'a', 'b', 'c')                    // Up to 3 positional args (use array for more)
     *   - query($sql, [':name' => 'Bob', ':age' => 45]) // Named params in array
     *   - query($sql, ['a', 'b', ':name' => 'c'])       // Mixed positional and named in array
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
                $name = match (true) {
                    !preg_match("/^:\w+$/", $key)  => throw new InvalidArgumentException("Invalid param name '$key'. Must start with ':' followed by (a-z, A-Z, 0-9, _)"),
                    str_starts_with($key, ':zdb_') => throw new InvalidArgumentException("Invalid param name '$key'. Names can't start with :zdb_ (reserved prefix)"),
                    default                        => $key,
                };
            }

            // Check for duplicates
            if (array_key_exists($name, $values)) {
                throw new InvalidArgumentException("Duplicate param name '$name'");
            }

            // Unwrap SmartString/SmartNull/SmartArray, validate type
            $values[$name] = match (true) {
                !is_object($value)               => $value,
                $value instanceof RawSql         => $value,
                $value instanceof SmartString    => $value->value(),
                $value instanceof SmartNull      => null,
                $value instanceof SmartArrayBase => $value->toArray(),
                default                          => throw new InvalidArgumentException("Parameters cannot be " . get_debug_type($value)),
            };
        }

        return $values;
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
     * Assert SQL template is safe - rejects quotes, standalone numbers, and dangerous characters.
     *
     * Forces developers to use placeholders instead of embedding values directly.
     * This catches accidental inclusion of user input in templates.
     *
     * Security checks:
     * - Standalone numbers: could be injection point if user input concatenated
     * - Quotes: force placeholder usage to prevent SQL injection
     * - Backslash: escape character that could manipulate LIKE patterns or escape quotes
     * - NULL byte: can cause string truncation in some contexts
     * - CTRL-Z: Windows EOF, can affect file/stream operations
     *
     * @throws InvalidArgumentException
     */
    private function assertSafeTemplate(string $sql): void
    {
        // Fast path: skip all checks if no suspicious patterns found (covers most queries)
        // Uses \b\d+\b for standalone numbers so col1/user2 don't trigger false positives
        if (!preg_match('/\b\d+\b|[\'\"\\\\\\x00\\x1a]/', $sql)) {
            return;
        }

        /**
         * Allow trailing "LIMIT #" clause - this is safe and commonly used.
         *
         * We temporarily replace "LIMIT #" at end of string so it doesn't trigger the
         * standalone number check below. The original query is NOT modified.
         *
         * Security analysis - even if developer writes insecure code like:
         *   $limit = $_GET['limit'];
         *   DB::query("SELECT * FROM users LIMIT $limit");
         *
         * Attack vectors that FAIL:
         *
         *   1. Attack: "10; DROP TABLE users"
         *      Result: "SELECT * FROM users LIMIT 10; DROP TABLE users"
         *      Why fails: Doesn't end in "LIMIT #", so regex doesn't match.
         *                 Standalone number check catches the "10".
         *
         *   2. Attack: "10 UNION SELECT * FROM secrets LIMIT 5"
         *      Result: "SELECT * FROM users LIMIT 10 UNION SELECT * FROM secrets LIMIT 5"
         *      Why fails: Regex matches "LIMIT 5" at end (replaced with ?).
         *                 Standalone number check catches the "10" from injection.
         *
         *   3. Attack: "10 INTO OUTFILE '/tmp/hack.txt'"
         *      Result: "SELECT * FROM users LIMIT 10 INTO OUTFILE '/tmp/hack.txt'"
         *      Why fails: Doesn't end in "LIMIT #", regex doesn't match.
         *                 Standalone number check catches "10".
         *                 Quote check catches '/tmp/hack.txt'.
         *
         *   4. Attack: "10 OR 1=1 LIMIT 5"
         *      Result: "SELECT * FROM users LIMIT 10 OR 1=1 LIMIT 5"
         *      Why fails: Regex matches "LIMIT 5" at end (replaced with ?).
         *                 Standalone number check catches "10", "1", "1".
         *
         * The defense works because we only strip the FINAL trailing "LIMIT #". Any injected
         * content either: (a) prevents the regex from matching, or (b) leaves numbers exposed
         * for the standalone number check to catch.
         */
        $trailingLimitRx = '/\bLIMIT\s+\d+\s*$/i';
        $sql = preg_replace($trailingLimitRx, 'LIMIT ?', $sql);

        // Standalone numbers - force use of placeholders
        if (preg_match('/\b(\d+)\b/', $sql, $matches)) {
            $n = $matches[1];
            throw new InvalidArgumentException("Standalone number in template. Replace $n with :n$n and add: [ ':n$n' => $n ]");
        }

        // Quotes - force use of placeholders
        if (preg_match('/[\'"]/', $sql, $matches)) {
            $quotedText = preg_match('/(([\'"]).*?\2)/', $sql, $matches) ? $matches[1] : '';
            if ($quotedText) {
                throw new InvalidArgumentException("Quotes not allowed in template. Replace $quotedText with :paramName and add: [ ':paramName' => $quotedText ]");
            } else {
                throw new InvalidArgumentException("Quotes not allowed in template. Use :paramName placeholder instead.");
            }
        }

        // Dangerous characters - defense in depth
        $error = match (true) {
            str_contains($sql, "\\")   => "Backslashes not allowed in template",
            str_contains($sql, "\x00") => "NULL character not allowed in template",
            str_contains($sql, "\x1a") => "CTRL-Z character not allowed in template",
            default                    => null,
        };
        if ($error) {
            throw new InvalidArgumentException($error);
        }
    }

    /**
     * Warn when integer WHERE is used (deprecated feature being phased out).
     * Users should migrate to array syntax: ['num' => $value]
     */
    private function warnDeprecatedNumericWhere(int|array|string $where): void
    {
        if (is_int($where)) {
            DB::logDeprecation("Numeric WHERE is deprecated, use array syntax instead: ['num' => $where]");
        }
    }

    /**
     * Reject LIMIT/OFFSET - these methods add their own LIMIT internally.
     * @throws InvalidArgumentException
     */
    private function rejectLimitAndOffset(int|array|string $where): void
    {
        if (is_string($where) && preg_match('/\b(LIMIT|OFFSET)\b/i', $where)) {
            throw new InvalidArgumentException("This method doesn't support LIMIT or OFFSET");
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
    private function rejectEmptyWhere(int|array|string $where, string $operation): void
    {
        if (is_int($where)) {
            return;  // deprecated but still supported
        }

        if (is_array($where) && !empty($where)) {
            return;
        }

        // string - valid if where has content and doesn't start with ORDER/LIMIT/OFFSET/FOR
        // These clauses without WHERE would affect all rows: "DELETE FROM t ORDER BY id LIMIT 1"
        // Conditions like "id = ?" are valid because whereFromString() prepends "WHERE "
        if (is_string($where) && trim($where) && !preg_match('/^\s*(ORDER|LIMIT|OFFSET|FOR)\b/i', $where)) {
            return;
        }

        throw new InvalidArgumentException("$operation requires a WHERE condition to prevent accidental bulk $operation");

    }

    //endregion
    //region Query Building

    /**
     * Build SET clause for INSERT/UPDATE.
     * Returns complete SQL with values escaped inline.
     *
     * Supported value types:
     *   - null, int, float, bool, string (escaped and quoted)
     *   - RawSql (inserted as-is, for NOW(), UUID(), etc.)
     *   - SmartString (unwrapped via ->value(), then escaped)
     *   - array, SmartArrayBase (converted via escapeCSV for multi-value columns)
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
            // Reject non-string keys (e.g., numeric array keys)
            if (!is_string($column)) {
                throw new InvalidArgumentException("Column names must be strings, got " . get_debug_type($column));
            }

            $this->assertValidColumn($column);

            $escaped = match (true) {
                is_null($value)                  => "NULL",
                is_int($value), is_float($value) => $value,
                is_bool($value)                  => $value ? 'TRUE' : 'FALSE',
                $value instanceof RawSql          => (string) $value,
                $value instanceof SmartString    => "'" . $this->mysqli->real_escape_string((string) $value->value()) . "'",
                $value instanceof SmartArrayBase => (string) $this->escapeCSV($value->toArray()),
                is_array($value)                 => (string) $this->escapeCSV($value),
                is_string($value)                => "'" . $this->mysqli->real_escape_string($value) . "'",
                default                          => throw new InvalidArgumentException("Unsupported value type for column '$column': " . get_debug_type($value)),
            };
            $setElements[] = "`$column` = $escaped";
        }

        return "SET " . implode(", ", $setElements);
    }

    /**
     * Build WHERE clause from any input type (string, array, or int).
     * If $params passed, parses them for placeholder replacement.
     * @throws InvalidArgumentException
     */
    private function whereFromArgs(int|array|string $where, array $params = []): string
    {
        if ($params) {
            $this->paramValues = $this->parseParams($params);
        }
        return match (true) {
            is_string($where) => $this->whereFromString($where),
            is_array($where)  => $this->whereFromArray($where),
            is_int($where)    => "WHERE `num` = $where",  // Deprecated - hardcoded for CMS Builder
        };
    }

    /**
     * Build WHERE clause from string input (has placeholders like ? and :name).
     * Validates input, replaces placeholders, returns complete SQL.
     * @throws InvalidArgumentException
     */
    private function whereFromString(string $where): string
    {
        if (trim($where) === '') {
            return '';
        }

        // Reject numeric strings - must use array syntax or cast to int
        if (preg_match('/^\s*\d+\s*$/', $where)) {
            throw new InvalidArgumentException(
                "Numeric string '$where' detected. Use array syntax: ['num' => $where] or cast to int: (int) \$value"
            );
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
     *
     * Supported value types:
     *   - null (becomes IS NULL)
     *   - int, float, bool, string (escaped and quoted)
     *   - RawSql (inserted as-is, for NOW(), expressions, etc.)
     *   - SmartString (unwrapped via ->value(), then escaped)
     *   - array, SmartArrayBase (becomes IN clause via escapeCSV)
     */
    private function whereFromArray(array $where): string
    {
        if (!$where) {
            return '';
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            // Reject non-string keys
            if (!is_string($column)) {
                throw new InvalidArgumentException("Column names must be strings, got " . get_debug_type($column));
            }

            $this->assertValidColumn($column);

            $conditions[] = match (true) {
                is_null($value)                  => "`$column` IS NULL",
                is_int($value), is_float($value) => "`$column` = $value",
                is_bool($value)                  => "`$column` = " . ($value ? 'TRUE' : 'FALSE'),
                $value instanceof RawSql          => "`$column` = " . $value,
                $value instanceof SmartString    => "`$column` = '" . $this->mysqli->real_escape_string((string) $value->value()) . "'",
                $value instanceof SmartArrayBase => "`$column` IN (" . $this->escapeCSV($value->toArray()) . ")",
                is_array($value)                 => "`$column` IN (" . $this->escapeCSV($value) . ")",
                is_string($value)                => "`$column` = '" . $this->mysqli->real_escape_string($value) . "'",
                default                          => throw new InvalidArgumentException("Unsupported value type for column '$column': " . get_debug_type($value)),
            };
        }

        return "WHERE " . implode(" AND ", $conditions);
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
     * @throws InvalidArgumentException
     */
    private function replacePlaceholders(string $template, array $params = []): string
    {
        if ($params) {
            $this->paramValues = $this->parseParams($params);
        }

        // Normalize :_ to :: (deprecated syntax) - but not ::_ (prefix + underscore table)
        $template = preg_replace('/(?<!:):_/', '::', $template, -1, $count);
        if ($count > 0) {
            DB::logDeprecation(":_ syntax is deprecated, use :: instead");
        }

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
                    return $isSafeIdentifier ? "`$value`" : throw new InvalidArgumentException("Invalid backtick identifier: " . var_export($value, true) . ". Only word characters (a-z, 0-9, _, -) allowed.");
                }

                // Regular placeholders: escape and quote values based on type
                return match (true) {
                    is_null($value)                  => 'NULL',
                    is_int($value), is_float($value) => $value,
                    is_bool($value)                  => $value ? 'TRUE' : 'FALSE',
                    $value instanceof RawSql          => (string) $value,
                    is_array($value)                 => (string) $this->escapeCSV($value),
                    is_string($value)                => "'" . $this->mysqli->real_escape_string($value) . "'",
                    default                          => throw new InvalidArgumentException("Unsupported type for placeholder $match: " . get_debug_type($value)),
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
     * @throws InvalidArgumentException If placeholder has no corresponding param
     */
    private function getPlaceholderValue(string $match, int &$positionalCount): string|int|float|bool|null|array|RawSql
    {
        // Handle bare :: (table prefix alone)
        if ($match === '::') {
            return new RawSql($this->tablePrefix);
        }

        // Parse placeholder: strip backticks and :: prefix
        $addTablePrefix = str_starts_with($match, "`::");                           // e.g., `::?` or `:::name`
        $placeholder    = preg_replace("/^::/", "", trim($match, '`'));             // e.g., `:::name` → :name, `::?` → ?

        // Look up value in param map
        $isPositional = ($placeholder === '?');
        $paramKey     = $isPositional ? ':' . ++$positionalCount : $placeholder;    // ? → :1, :2, :3; :name stays as-is
        if (!array_key_exists($paramKey, $this->paramValues)) {
            throw new InvalidArgumentException(
                $isPositional
                    ? "Missing value for ? parameter at position $positionalCount"
                    : "Missing value for '$paramKey' parameter",
            );
        }

        $value = $this->paramValues[$paramKey];

        // Arrays only allowed with named placeholders (positional would be ambiguous)
        if (is_array($value) && $isPositional) {
            throw new InvalidArgumentException("Arrays not allowed with positional ? placeholders (ambiguous). Use named placeholder instead: ':paramName' => [...]");
        }

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
        $columnMap        = [];                                                     // Column name to index, first wins, e.g., ['name' => 0, 'total' => 1]
        $tableAliases     = [];                                                     // Table alias to name, e.g., ['u' => 'users']
        $hasDuplicateCols = false;
        foreach ($mysqliResult->fetch_fields() as $index => $field) {
            if (isset($columnMap[$field->name])) {
                $hasDuplicateCols = true;
            }
            $columnMap[$field->name] ??= $index;                                    // First wins for duplicate names
            if ($field->orgtable) {
                $tableAliases[$field->table] = $field->orgtable;                    // 'a' => 'users' or 'users' => 'users'
            }
        }

        // Fast path: no duplicate columns and no SmartJoins needed - use C-level associative fetch
        $needsSmartJoins = $this->useSmartJoins && count($tableAliases) > 1;
        if (!$hasDuplicateCols && !$needsSmartJoins) {
            $rows = $mysqliResult->fetch_all(MYSQLI_ASSOC);
            $mysqliResult->free();
            return $rows;
        }

        // Second pass: if smart joins enabled AND multi-table query, add qualified names, e.g., 'users.name' => "John"
        if ($needsSmartJoins) {
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

        // Slow path: fetch as numeric arrays and remap to column names (for "first wins" or SmartJoins)
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
    //region Object Lifecycle

    /**
     * Clean up on destruction - drain pending results but let PHP handle connection closing.
     *
     * When connections are cloned (via clone() or DB::clone()), they share the same
     * underlying mysqli connection. We don't explicitly close the connection here
     * because PHP's internal reference counting handles it automatically - the mysqli
     * connection stays open until ALL Connection objects sharing it are destroyed,
     * regardless of destruction order.
     */
    public function __destruct()
    {
        if ($this->mysqli instanceof mysqli) {
            try {
                // Drain any pending result sets to leave connection in clean state
                while ($this->mysqli->more_results() && $this->mysqli->next_result()) {
                    // Drain
                }
                // Note: We intentionally don't call close() here - see PHPDoc above
            } catch (Throwable) {
                // Defensive: destructors must never throw
            }
        }
    }

    /**
     * Control what's shown in var_dump/print_r - masks sensitive credentials.
     */
    public function __debugInfo(): array
    {
        $props = get_object_vars($this);
        if (!empty($props['password'])) {
            $props['password'] = '********';    // mask password
        }
        return $props;
    }

    //endregion
    //region Connection Settings

    // Connection credentials (kept for reconnection)
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

    // Advanced connection settings
    public string $versionRequired    = '5.7.32';
    public bool   $requireSSL         = false;
    public bool   $databaseAutoCreate = false;
    public int    $connectTimeout     = 3;
    public int    $readTimeout        = 60;
    public mixed $queryLogger         = null;   // e.g., fn(string $query, float $durationSecs, ?Throwable $error): void
    public string $sqlMode            = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    //endregion
}
