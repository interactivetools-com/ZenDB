<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use RuntimeException;
use Itools\SmartArray\SmartArrayBase;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use Throwable;
use WeakMap;
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
     *   - query($sql, ['a', 'b', 'c'])                    // Positional params in array
     *
     * @param array $args Variadic args from query method
     * @return array Parameter map, e.g. [':1' => 'a', ':2' => 'b'] or [':name' => 'Bob']
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
        $hasPositional   = false;
        $hasNamed        = false;

        foreach ($inputParams as $key => $value) {
            // Determine param name
            if (is_int($key)) {
                $hasPositional = true;
                $name          = ':' . ++$positionalCount;
            } else {
                $hasNamed = true;
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

        // Enforce consistent placeholder style
        if ($hasPositional && $hasNamed) {
            throw new InvalidArgumentException("Can't mix positional (?) and named (:param) placeholders. Use one style consistently.");
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
        /*
         * Fast path: skip checks if template has no standalone numbers, quotes, backslashes,
         * NULL bytes, or CTRL-Z. Word boundaries (\b) match standalone numbers like WHERE num = 5
         * but not numbers embedded in identifiers like col2, user_id3, address1, etc.
         */
        if (!preg_match('/\b\d+\b|[\'\"\\\\\\x00\\x1a]/', $sql)) {
            return;
        }

        /* Update lastQuery with where clause context (so it's available if we throw below) */
        if (str_starts_with($sql, 'WHERE ') && str_contains($this->mysqli->lastQuery, '[WHERE ...]')) {
            $this->mysqli->lastQuery = str_replace('[WHERE ...]', $sql, $this->mysqli->lastQuery);
        }

        /*
         * Allow '' and "" empty string literals - these are safe and commonly used.
         *
         * We strip '' and "" from a copy of the SQL before the quote check so they
         * aren't flagged as quoted strings. The original query is NOT modified.
         *
         * Why this is safe: if a developer writes WHERE city = '$city', the only value
         * that produces valid '' is an empty string, which carries no payload. Any real
         * value like "Vancouver" produces WHERE city = 'Vancouver', which throws
         * immediately, forcing the developer to use placeholders.
         */
        $sqlForQuoteCheck = str_replace(["''", '""'], '', $sql);

        /*
         * Quotes are never allowed in templates. Code that embeds a quoted value
         * throws the first time it runs with real data. This forces the developer to
         * use placeholders before the code can work at all.
         *
         *   // This throws the moment $city contains any real value like "Vancouver"
         *   DB::query("SELECT * FROM ::users WHERE city = '$city'");
         *   // Throws: Quotes not allowed in template. Replace 'Vancouver' with ...
         */
        if (preg_match('/[\'"]/', $sqlForQuoteCheck, $matches)) {
            $quotedText = preg_match('/(([\'"]).*?\2)/', $sqlForQuoteCheck, $matches) ? $matches[1] : '';
            if ($quotedText) {
                throw new InvalidArgumentException("Quotes not allowed in template. Replace $quotedText with :paramName and add: [ ':paramName' => $quotedText ]");
            } else {
                throw new InvalidArgumentException("Quotes not allowed in template. Use :paramName placeholder instead.");
            }
        }

        /*
         * Allow trailing "LIMIT #" clause - this is safe and commonly used.
         *
         * MySQL LIMIT only accepts literal integers, so \d+ matches the only valid
         * syntax. We strip the trailing "LIMIT #" from a copy of the SQL so it
         * doesn't trigger the standalone number check. The original query is NOT
         * modified. Only the trailing LIMIT is stripped - any injected content
         * either breaks the regex match or leaves numbers exposed (which throw on
         * the number check below).
         *
         *   $limit = $_GET['limit'];
         *   DB::query("SELECT * FROM ::users LIMIT $limit");
         *
         *   // Even if user input is interpolated directly, attacks still fail:
         *
         *   // Attack examples that fail:
         *   "10; DROP TABLE users"           -> doesn't end in LIMIT #, no match
         *   "10 INTO OUTFILE '/tmp/hack.txt" -> doesn't end in LIMIT #, "10" + quotes caught
         *   "10 UNION ... LIMIT 5"           -> LIMIT 5 stripped, but "10" caught by number check
         *   "1e1 UNION ... LIMIT 1"          -> LIMIT 1 stripped, but MySQL rejects LIMIT 1e1 (LIMIT only accepts integer constants)
         */
        $sql = preg_replace('/\bLIMIT\s+\d+\s*$/i', '', $sql);

        // Standalone numbers - force use of placeholders
        if (preg_match('/\b(\d+)\b/', $sql, $matches)) {
            $n = $matches[1];
            throw new InvalidArgumentException("Standalone number in template. Replace $n with :n$n and add: [ ':n$n' => $n ]");
        }

        // Potentially dangerous characters - backslashes, NULL bytes, CTRL-Z
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
    private function logDeprecatedNumericWhere(int|array|string $where): void
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
     * @param array $values Column => value pairs
     * @return string SQL SET clause
     * @throws InvalidArgumentException
     */
    private function buildSetClause(array $values): string
    {
        if (!$values) {
            throw new InvalidArgumentException("No values provided");
        }

        $setElements = [];
        foreach ($values as $column => $value) {
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

        // Prepend WHERE if not already present
        $hasLeadingKeyword = preg_match('/^\s*(WHERE|FOR|ORDER|LIMIT|OFFSET)\b/i', $where);
        if (!$hasLeadingKeyword) {
            $where = "WHERE $where";
        }

        // Validate - no quotes or numbers (must use placeholders)
        $this->assertSafeTemplate($where);

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
                $value instanceof RawSql         => "`$column` = " . $value,
                $value instanceof SmartString    => "`$column` = '" . $this->mysqli->real_escape_string((string)$value->value()) . "'",
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

        // {{column}} or {{table.column}} - expand encrypted column references, see decryptExpr()
        $template = preg_replace_callback('/\{\{([\w.-]+)}}/', fn($m) => DB::decryptExpr($m[1]), $template);

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
    //region Escape Methods

    /**
     * Escape a string for safe inclusion in raw SQL.
     *
     * @param string|int|float|null|SmartString $input Value to escape
     * @param bool $escapeLikeWildcards Also escape % and _ for LIKE queries
     * @return string Escaped string (without quotes)
     */
    public function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        // Unwrap SmartString
        if ($input instanceof SmartString) {
            $input = $input->value();
        }

        // Escape using mysqli
        $escaped = $this->mysqli->real_escape_string((string)$input);

        // Escape LIKE wildcards if needed
        if ($escapeLikeWildcards) {
            $escaped = addcslashes($escaped, '%_');
        }

        return $escaped;
    }

    /**
     * Escapes and quotes values, inserting them into a format string with ? placeholders.
     *
     * @param string $format    Format string with ? placeholders
     * @param mixed  ...$values Values to escape and insert
     * @return string SQL-safe string
     * @throws InvalidArgumentException
     */
    public function escapef(string $format, mixed ...$values): string
    {
        $this->mysqli || throw new RuntimeException(__METHOD__ . "() called before DB connection established");

        return preg_replace_callback('/\?/', function () use (&$values) {
            $value = array_shift($values);

            return match (true) {
                is_string($value)                => "'" . $this->mysqli->real_escape_string($value) . "'",
                is_int($value), is_float($value) => $value,
                is_null($value)                  => 'NULL',
                is_array($value)                 => (string) $this->escapeCSV($value),
                $value instanceof SmartArrayBase => (string) $this->escapeCSV($value->toArray()),
                $value instanceof SmartString    => "'" . $this->mysqli->real_escape_string((string) $value->value()) . "'",
                is_bool($value)                  => $value ? 'TRUE' : 'FALSE',
                default                          => throw new InvalidArgumentException("Unsupported type: " . get_debug_type($value)),
            };
        }, $format);
    }

    /**
     * Converts array values to a safe CSV string for use in MySQL IN clauses.
     *
     * Tip: You probably don't need this! Named placeholders handle arrays
     * automatically, which is simpler and keeps your values parameterized:
     *
     *     // Instead of this:
     *     DB::select('users', "id IN (?)", DB::escapeCSV([1, 2, 3]));
     *
     *     // Do this:
     *     DB::select('users', "id IN (:ids)", [
     *         ':ids' => [1, 2, 3],
     *     ]);
     *
     * @param array $values Array of values to convert
     * @return RawSql SQL-safe comma-separated list
     * @throws InvalidArgumentException
     */
    public function escapeCSV(array $values): RawSql
    {
        $this->mysqli || throw new RuntimeException(__METHOD__ . "() called before DB connection established");

        $safeValues = [];
        foreach (array_unique($values) as $value) {
            $value        = $value instanceof SmartString ? (string) $value->value() : $value;
            $safeValues[] = match (true) {
                is_int($value) || is_float($value) => $value,
                is_null($value)                    => 'NULL',
                is_bool($value)                    => $value ? 'TRUE' : 'FALSE',
                is_string($value)                  => "'" . $this->mysqli->real_escape_string($value) . "'",
                default                            => throw new InvalidArgumentException("Unsupported value type: " . get_debug_type($value)),
            };
        }

        return new RawSql($safeValues ? implode(',', $safeValues) : 'NULL');
    }

    //endregion
    //region Result Processing

    /**
     * Fetch result rows with column mapping, smart joins, and auto-decryption.
     *
     * - "First wins": duplicate column names use the first occurrence
     * - SmartJoins: multi-table queries add qualified names (e.g., 'users.name')
     * - Self-joins: adds alias-based names (e.g., 'a.name', 'b.name')
     * - Auto-decryption: MEDIUMBLOB columns are decrypted when an encryption key is configured
     */
    private function fetchMappedRows(mysqli_result|bool $mysqliResult): array
    {
        if (!$mysqliResult instanceof mysqli_result) {
            return [];  // INSERT/UPDATE/DELETE return true, not mysqli_result
        }

        // Extract field metadata from result
        $fields       = $mysqliResult->fetch_fields();
        $names        = array_column($fields, 'name');
        $tableAliases = array_filter(array_column($fields, 'orgtable', 'table'));      // e.g., ['u' => 'users']

        // Fast path: no duplicate columns and no SmartJoins needed - use C-level associative fetch
        $hasDuplicateCols = count($names) !== count(array_flip($names));
        $needsSmartJoins  = $this->useSmartJoins && count($tableAliases) > 1;
        if (!$hasDuplicateCols && !$needsSmartJoins) {
            $rows = $mysqliResult->fetch_all(MYSQLI_ASSOC);
            $mysqliResult->free();
            $this->decryptRows($rows, $fields);                     // auto-detect and decrypt MEDIUMBLOB columns
            return $rows;
        }

        // Build column index map for numeric-to-named remapping (first wins for duplicates)
        $columnIndexes = array_flip(array_unique($names));      // e.g., ['name' => 0, 'email' => 1]

        // SmartJoins: add qualified names (e.g., 'users.name') and alias names for self-joins (e.g., 'a.name')
        if ($needsSmartJoins) {
            $prefixLen      = strlen($this->tablePrefix);
            $selfJoinTables = array_filter(array_count_values($tableAliases), fn($c) => $c > 1);

            foreach ($fields as $index => $field) {
                if ($field->orgtable && $field->orgname) {
                    $baseTable = str_starts_with($field->orgtable, $this->tablePrefix)
                        ? substr($field->orgtable, $prefixLen)
                        : $field->orgtable;

                    $columnIndexes["$baseTable.$field->orgname"] ??= $index;       // e.g., 'users.name', first wins

                    // Self-joined tables: add table alias names as well (e.g., 'a.name', 'b.name')
                    if (isset($selfJoinTables[$field->orgtable])) {
                        $columnIndexes["$field->table.$field->orgname"] ??= $index;     // e.g., 'u.name', first wins
                    }
                }
            }
        }

        // Fetch as numeric arrays and remap to named columns
        $rows = [];
        foreach ($mysqliResult->fetch_all(MYSQLI_NUM) as $values) {
            $row = [];
            foreach ($columnIndexes as $name => $index) {
                $row[$name] = $values[$index];
            }
            $rows[] = $row;
        }
        $mysqliResult->free();

        // Auto-decrypt MEDIUMBLOB columns (no-op when no encryption key configured)
        $this->decryptRows($rows, $fields);

        return $rows;
    }

    /**
     * Wrap rows in a SmartArrayHtml with connection metadata.
     */
    private function toSmartArray(array $rows, string $sql, string $baseTable = ''): SmartArrayHtml
    {
        return new SmartArrayHtml($rows, [
            'useSmartStrings' => $this->useSmartStrings,
            'loadHandler'     => $this->loadHandler,
            'mysqli'          => [
                'query'         => $sql,
                'baseTable'     => $baseTable,
                'affected_rows' => $this->mysqli->affected_rows,
                'insert_id'     => $this->mysqli->insert_id,
            ],
        ]);
    }

    //endregion
    //region Encryption

    /**
     * Derive and cache the AES-128 key matching MySQL's AES_ENCRYPT() key handling.
     * MySQL XOR-folds the SHA-512 hash into a 16-byte key for AES-128-ECB.
     */
    private function aesKey(): string
    {
        static $cache = new WeakMap();
        if (!isset($cache[$this])) {
            $encryptionKey = $this->secret('encryptionKey') ?: throw new RuntimeException("aesKey() requires 'encryptionKey' in connection config.");
            $keyBytes      = hash('sha512', $encryptionKey, true);
            $cache[$this]  = substr($keyBytes, 0, 16);
            $cache[$this] ^= substr($keyBytes, 16, 16);
            $cache[$this] ^= substr($keyBytes, 32, 16);
            $cache[$this] ^= substr($keyBytes, 48, 16);
        }

        return $cache[$this];
    }

    /**
     * Auto-encrypt values for encrypted columns in an insert/update values array.
     * Detects MEDIUMBLOB columns via a cached LIMIT 0 query, then encrypts matching values in place.
     * No-op when no encryption key is configured or no encrypted columns exist.
     *
     * @param string $fullTable Full table name (with prefix)
     * @param array  $values    Column => value pairs (modified in place)
     */
    private function autoEncryptValues(string $fullTable, array &$values): void
    {
        if (!$this->secret('encryptionKey') || !$values) {
            return;
        }

        // Get encrypted column names for this table (cached per table per request)
        static $tableCache = [];
        if (!isset($tableCache[$fullTable])) {
            $result = $this->mysqli->query("SELECT * FROM `$fullTable` LIMIT 0");
            $tableCache[$fullTable] = $result ? DB::getEncryptedColumns($result->fetch_fields()) : [];
            $result?->free();
        }

        $encryptedCols = $tableCache[$fullTable];
        if (!$encryptedCols) {
            return;
        }

        // Encrypt values for encrypted columns
        $aesKey = $this->aesKey();
        foreach ($encryptedCols as $encryptedCol) {
            if (!array_key_exists($encryptedCol, $values) || $values[$encryptedCol] === null) {
                continue;
            }
            $value = $values[$encryptedCol];
            if ($value instanceof SmartString) {
                $value = $value->value();
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                continue; // skip RawSql, arrays, and other non-scalar types
            }
            $values[$encryptedCol] = openssl_encrypt((string) $value, 'aes-128-ecb', $aesKey, OPENSSL_RAW_DATA);
        }
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

        // Restore sealed credentials for debug output
        foreach (self::$secrets[$this] ?? [] as $key => $value) {
            $props[$key] = $value;
        }
        foreach (['hostname', 'username', 'password', 'encryptionKey'] as $sensitive) {
            if ($props[$sensitive] !== '' && $props[$sensitive] !== null) {
                $props[$sensitive] = '********';
            }
        }

        return $props;
    }

    //endregion
    //region Credential Vault

    /** @var string[] Keys sealed into the WeakMap vault (encryptionKey is optional) */
    private static array $secretKeys = ['hostname', 'username', 'password', 'database', 'encryptionKey'];

    /**
     * Credentials stored outside instance properties to prevent leakage
     * via serialize(), var_export(), and (array) cast.
     */
    private static WeakMap $secrets;

    /**
     * Seal credentials into the WeakMap vault and null them on the object.
     * Requires hostname, username, password, and database to be present
     * in $config (construct) or $source vault (clone), throws otherwise.
     *
     *     $this->sealSecrets(config: $config);    // construct: credentials from config
     *     $clone->sealSecrets(source: $this);     // clone: copy from source vault
     *
     * @param self|null $source Source connection to copy secrets from (for clones)
     * @param array     $config Config array; credential keys are consumed (construct path only)
     * @throws RuntimeException If any required credential is missing
     */
    private function sealSecrets(?self $source = null, array &$config = []): void
    {
        self::$secrets       ??= new WeakMap();
        self::$secrets[$this] = [];

        $optional = ['encryptionKey'];

        foreach (self::$secretKeys as $key) {
            $value = $source
                ? self::$secrets[$source][$key] ?? null  // clone: copy from source
                : $config[$key] ?? null;                 // construct: from config

            if ($value === null && !in_array($key, $optional, true)) {
                throw new RuntimeException("Missing required config: '$key'");
            }
            self::$secrets[$this][$key] = $value;
            $this->$key = null;            // clear property to prevent leakage
            unset($config[$key]);          // consume key so it won't hit the property loop
        }
    }

    /**
     * Read a credential from the vault.
     */
    private function secret(string $key): ?string
    {
        return self::$secrets[$this][$key] ?? null;
    }

    //endregion
    //region Connection Settings

    // Connection credentials (exist for property_exists validation, values stored in WeakMap)
    private ?string $hostname      = null;
    private ?string $username      = null;
    private ?string $password      = null;
    private ?string $database      = null;
    private ?string $encryptionKey = null;

    // Result handling
    /** @var callable|null Custom handler for loading results */
    private mixed $loadHandler = null;

    // Connect-time settings (only used during connect(), changing after has no effect)
    private bool   $usePhpTimezone     = true;
    private string $versionRequired    = '5.7.32';
    private bool   $requireSSL         = false;
    private bool   $databaseAutoCreate = false;
    private int    $connectTimeout     = 3;
    private int    $readTimeout        = 60;
    private mixed  $queryLogger        = null;   // e.g., fn(string $query, float $durationSecs, ?Throwable $error): void
    private string $sqlMode            = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    //endregion
}
