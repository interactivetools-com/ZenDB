<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use RuntimeException;
use Itools\SmartArray\SmartArray;
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
    private bool  $paramsFromPositionalArray = false;  // set by parseParams(), read by the unused-positional check in replacePlaceholders()

    /**
     * Parse variadic query args into a parameter map.
     *
     * Converts positional params (0, 1, 2) to named format (:1, :2, :3).
     * Validates named params start with ':' and don't use reserved ':zdb_' prefix.
     * Unwraps SmartString/SmartNull values.
     *
     * Supports:
     *   - query($sql, 'a', 'b', 'c')                    // Positional values for ? placeholders (max 3)
     *   - query($sql, [':name' => 'Bob', ':age' => 45]) // Named params in array
     *   - query($sql, ['a', 'b', 'c'])                  // Deprecated: positional values in an array (use named placeholders)
     *
     * @param array $args Variadic args from query method
     * @return array Parameter map, e.g. [':1' => 'a', ':2' => 'b'] or [':name' => 'Bob']
     * @throws InvalidArgumentException
     */
    private function parseParams(array $args): array
    {
        $this->paramsFromPositionalArray = false;  // reset per call; the no-args return below skips the assignment further down

        if (!$args) {
            return [];
        }

        // Valid forms: up to 3 direct values (for ? placeholders), or one array of ':name' => value pairs.
        // Positional values in an array are deprecated and will throw in a future version.
        $passedAsArray     = count($args) === 1 && is_array($args[0]);
        $passedAsValues    = empty(array_filter($args, 'is_array'));
        $isPositionalArray = $passedAsArray && $args[0] !== [] && !array_filter(array_keys($args[0]), 'is_string');

        $this->paramsFromPositionalArray = $isPositionalArray;
        match (true) {
            !$passedAsArray && !$passedAsValues => throw new InvalidArgumentException("Param args must be either a single array or multiple non-array values"),
            count($args) > 3                    => throw new InvalidArgumentException("Max 3 positional arguments allowed. For more, use named placeholders: [':name' => \$value]"),
            $isPositionalArray                  => DB::logDeprecation("Positional values in an array are deprecated. Pass up to 3 values directly for ? placeholders, or use named placeholders: [':name' => \$value]"),
            default                             => null,
        };

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
                $name     = match (true) {
                    !preg_match("/^:\w+\z/", $key) => throw new InvalidArgumentException("Invalid param name '$key'. Must start with ':' followed by (a-z, A-Z, 0-9, _)"),
                    str_starts_with($key, ':_')    => throw new InvalidArgumentException("Invalid param name '$key'. Names can't start with :_ (the deprecated table-prefix syntax); start the name with a letter or digit"),
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
     * Assert SQL template is safe - rejects quotes, standalone numbers, and dangerous characters.
     *
     * Forces developers to use placeholders instead of embedding values directly.
     * This catches accidental inclusion of user-supplied values: a real value
     * carries digits or quotes, so interpolating one throws the first time the
     * code runs with real data.
     *
     * It can't catch user-supplied identifiers: a column name has no digits and
     * no quotes, so "ORDER BY $sort" passes this check and is SQL injection when
     * $sort comes from user input. For dynamic column names use a backtick
     * placeholder - ORDER BY `:sortCol` throws unless the value is a plain
     * identifier (a-z, A-Z, 0-9, _, -).
     *
     * Security checks:
     * - Standalone numbers: could be injection point if user input concatenated
     * - Numeric literals (hex 0x, binary 0b, scientific 1e10): evade the standalone-number
     *   check because their digits touch a letter, so they get a separate check
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
         * Fast path: skip checks if template has no digits, quotes, backslashes,
         * NULL bytes, or CTRL-Z. \b\d covers every number-based check below (standalone
         * numbers, hex, binary, scientific) because each starts with a digit at a word
         * boundary - so new literal forms can't slip past this gate. Digits embedded in
         * identifiers like col2, user_id3, address1 don't match (no boundary before them).
         */
        if (!preg_match('/\b\d|[\'\"\\\\\\x00\\x1a]/', $sql)) {
            return;
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
         *   "1e1 UNION ... LIMIT 1"          -> LIMIT 1 stripped, but "1e1" caught by the numeric-literal check
         */
        $sql = preg_replace('/\bLIMIT\s+\d+\s*$/i', '', $sql);

        // Standalone numbers - force use of placeholders
        if (preg_match('/\b(\d+)\b/', $sql, $matches)) {
            $n = $matches[1];
            throw new InvalidArgumentException("Standalone number in template. Replace $n with :n$n and add: [ ':n$n' => $n ]");
        }

        // Numeric literals that slip past the \b\d+\b check because their digits touch a
        // letter: hex (0x1AF), binary (0b1010), scientific (1e10). MySQL evaluates each to
        // a value, so they belong in placeholders like any other literal. Requiring at
        // least one digit after 0x/0b keeps identifiers like `0boxes` from matching.
        if (preg_match('/\b0[xb][0-9a-f]+|\b\d+e[+-]?\d+/i', $sql, $matches)) {
            throw new InvalidArgumentException("Numeric literal '$matches[0]' in template. Replace it with a :paramName placeholder.");
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
     * Reject template patterns that conflict with the auto-appended `LIMIT 1`.
     *
     * queryOne() and selectOne() unconditionally append ` LIMIT 1` to the caller's
     * template. Three end-of-template shapes break that splice:
     *
     * 1. Row-locking clauses (FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE).
     *    MySQL grammar requires LIMIT to come before these, so the appended
     *    LIMIT lands in the wrong spot and produces a parse error.
     *
     * 2. Trailing line comments (`--` or `#`). The appended ` LIMIT 1` lands
     *    on the same line as the comment marker, so MySQL silently ignores
     *    it and runs the full query (silent full-table scan).
     *
     * 3. Trailing semicolons (`;`). The appended ` LIMIT 1` produces `...; LIMIT 1`,
     *    which MySQL rejects with a confusing "near 'LIMIT 1'" parse error.
     *
     * INTO OUTFILE / INTO DUMPFILE are also post-LIMIT, but calling queryOne
     * on them is nonsensical (they don't return rows), so we let MySQL's parse
     * error surface instead of guarding here.
     *
     * Callers needing any of these should use query()->first() instead.
     *
     * @throws InvalidArgumentException
     */
    private function rejectPreLimitConflicts(int|array|string $where): void
    {
        if (!is_string($where)) {
            return;
        }

        // Row-locking clauses - grammar requires LIMIT before these
        if (preg_match('/\bFOR\s+(?:UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $where, $m)) {
            $clause = preg_replace('/\s+/', ' ', strtoupper($m[0]));
            throw new InvalidArgumentException("This method doesn't support $clause. Use query(...)->first() instead.");
        }

        // Trailing line comment - would swallow the appended LIMIT 1 and cause a silent full-table scan
        if (preg_match('/(?:--|#)[^\r\n]*\z/', $where)) {
            throw new InvalidArgumentException("This method appends LIMIT 1 automatically; a trailing '--' or '#' comment would swallow it and cause a full-table scan. Remove the comment or use query(...)->first() instead.");
        }

        // Trailing semicolon - appended LIMIT 1 would become '; LIMIT 1' and fail parsing
        if (preg_match('/;\s*\z/', $where)) {
            throw new InvalidArgumentException("This method appends LIMIT 1 automatically; a trailing ';' would produce '; LIMIT 1' and fail parsing. Remove the semicolon or use query(...)->first() instead.");
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
     *
     * Arrays are not supported: column assignment is single-valued, so
     * callers must serialize (json_encode, implode, etc.) before passing.
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

            DB::assertIdentifier($column, 'column name');

            if ($value instanceof SmartString) {
                $value = $value->value(); // unwrap before the type check; SmartString can wrap null/bool
            }
            $setElements[] = "`$column` = " . $this->escapeValue($value, "column '$column'");
        }

        return "SET " . implode(", ", $setElements);
    }

    /**
     * Build WHERE clause from any input type (string, array, or int).
     * Reads placeholder values from $this->paramValues (set by the caller).
     * @throws InvalidArgumentException
     */
    private function whereFromArgs(int|array|string $where): string
    {
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
                "Numeric string '$where' detected. Use array syntax: ['num' => $where] or cast to int: (int) \$value",
            );
        }

        // Prepend WHERE if not already present
        $hasLeadingKeyword = preg_match('/^\s*(WHERE|FOR|ORDER|LIMIT|OFFSET)\b/i', $where);
        if (!$hasLeadingKeyword) {
            $where = "WHERE $where";
        }

        // Replace [WHERE ...] in lastQuery with the resolved WHERE so errors below report real context
        $this->mysqli->lastQuery = str_replace('[WHERE ...]', $where, $this->mysqli->lastQuery);

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

            DB::assertIdentifier($column, 'column name');

            if ($value instanceof SmartString) {
                $value = $value->value(); // unwrap before the type check; SmartString can wrap null/bool
            }
            $conditions[] = match (true) {
                is_null($value)                  => "`$column` IS NULL",
                $value instanceof SmartArrayBase => "`$column` IN (" . $this->escapeCSV($value->toArray()) . ")",
                is_array($value)                 => "`$column` IN (" . $this->escapeCSV($value) . ")",
                default                          => "`$column` = " . $this->escapeValue($value, "column '$column'"),
            };
        }

        return "WHERE " . implode(" AND ", $conditions);
    }

    /**
     * Replace placeholders with their escaped/formatted values and return final SQL.
     * Reads placeholder values from $this->paramValues (set by the caller).
     *
     * Replacements:
     *   ?, :name           - quoted and escaped
     *   ::?, :::name       - same as above with table prefix prepended (prefix lands inside the quotes)
     *   `?`, `:name`       - backtick-wrapped and unescaped, throws if unsafe chars
     *   `::?`, `:::name`   - same as above with table prefix prepended
     *   ::                 - table prefix alone
     *
     * In LIKE patterns, a `_` in the table prefix matches any single character
     * (::? with 'user%' and prefix 'cms_' also matches cms2users); escape the
     * prefix yourself if that matters.
     *
     * @throws InvalidArgumentException
     */
    private function replacePlaceholders(string $template): string
    {
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

                // ::Values - with table prefix (quoted and escaped)
                "::\?",                 // ::?       user% → 'cms_user%'
                ":::[a-zA-Z]\w*\b",     // :::name   user% → 'cms_user%'

                // Table prefix alone (must come after the ::placeholder patterns above)
                "::",                   // e.g., SELECT * FROM ::users → SELECT * FROM cms_users
            ]) . '/';

        // Find and replace all placeholders with their escaped/formatted values
        $positionalCount = 0;
        $sql = preg_replace_callback(
            pattern : $placeholderRegex,
            callback: function ($matches) use (&$positionalCount) {
                $match = $matches[0]; // e.g., ?, :name, `?`, etc
                $value = $this->getPlaceholderValue($match, $positionalCount);

                // Backtick placeholders: insert safe identifiers (table/column names) unquoted (or throw if unsafe)
                if ($match[0] === '`') {
                    $isSafeIdentifier = is_string($value) && preg_match('/^[\w-]+\z/', $value); // + rejects '', \z rejects trailing newline
                    return $isSafeIdentifier ? "`$value`" : throw new InvalidArgumentException("Invalid backtick identifier: " . var_export($value, true) . ". Only word characters (a-z, 0-9, _, -) allowed.");
                }

                // Regular placeholders: escape and quote values based on type
                return is_array($value)
                    ? (string)$this->escapeCSV($value)
                    : $this->escapeValue($value, "placeholder $match");
            },
            subject : $template,
        );

        // Unused positional values almost always mean a bug, e.g. "IN (?)" with [1, 2, 3] only uses the 1.
        // Skipped when parseParams() already logged the positional-array deprecation for this call.
        // Unused named values stay allowed: passing a shared param array with extras is legitimate.
        $positionalProvided = count(preg_grep('/^:\d+$/', array_keys($this->paramValues)));
        if ($positionalCount < $positionalProvided && !$this->paramsFromPositionalArray) {
            DB::logDeprecation("Query has $positionalCount positional (?) placeholder(s) but $positionalProvided values were passed. Unused positional values are deprecated and will throw in a future version. For IN() lists use a named placeholder: ':ids' => [...]");
        }

        return $sql;
    }

    /**
     * Maps a placeholder match to its corresponding value from the param map.
     *
     * Handles these placeholder styles:
     *   - Positional:  ?                  → returns param value by position (:1, :2, ...)
     *   - Named:       :name              → returns param value for :name
     *   - Prefixed:    ::?, :::name       → returns table prefix + value (backticked or not)
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
        $unbackticked   = trim($match, '`');
        $addTablePrefix = str_starts_with($unbackticked, '::');                     // e.g., `::?`, `:::name`, ::?, :::name
        $placeholder    = $addTablePrefix ? substr($unbackticked, 2) : $unbackticked; // e.g., :::name → :name, ::? → ?

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

        if (!$addTablePrefix) {
            return $value;
        }

        // Backtick prefix placeholders (`::?`, `:::name`) require a string; otherwise PHP silently coerces
        // bool/null/array via string concat and the result sneaks past the \w- identifier check below
        if ($match[0] === '`') {
            if (!is_string($value)) {
                throw new InvalidArgumentException("Backtick prefix placeholder $match requires a string value, got " . get_debug_type($value));
            }
            return $this->tablePrefix . $value;
        }

        // Bare prefix placeholders (::?, :::name) work like ? and :name with the prefix prepended first:
        // the string gets quoted, arrays prefix each element then expand to CSV. String values only:
        // the prefix is a table prefix, so anything else is a mistake worth surfacing
        $allowedTypes = $isPositional ? "string" : "string or array";
        return match (true) {
            is_string($value)        => $this->tablePrefix . $value,
            $value instanceof RawSql => throw new InvalidArgumentException("Prefix placeholder $match doesn't support RawSql; prepend the prefix yourself with DB::rawSql(DB::\$tablePrefix . ...)"),
            is_array($value)         => array_map(
                function ($v) use ($match) {
                    if ($v instanceof SmartString) {
                        $v = $v->value(); // unwrap before the type check; SmartString can wrap null/bool
                    }
                    if (!is_string($v)) {
                        throw new InvalidArgumentException("Prefix placeholder $match array elements must be strings, got " . get_debug_type($v));
                    }
                    return $this->tablePrefix . $v;
                },
                $value,
            ),
            default                  => throw new InvalidArgumentException("Prefix placeholder $match requires a $allowedTypes value, got " . get_debug_type($value)),
        };
    }

    //endregion
    //region Escape Methods

    /**
     * Internal use, undocumented by design. Use placeholders instead; they're
     * safer unless you know exactly what you're doing.
     *
     * Escape a string for safe inclusion in raw SQL.
     *
     * @internal
     * @param string|int|float|null|SmartString $input               Value to escape
     * @param bool                              $escapeLikeWildcards Also escape % and _ for LIKE queries
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
     * Internal use, undocumented by design. Use placeholders instead; they're
     * safer unless you know exactly what you're doing.
     *
     * Escapes and quotes values, inserting them into a format string with ? placeholders.
     *
     * @internal
     * @param string $format    Format string with ? placeholders
     * @param mixed  ...$values Values to escape and insert
     * @return string SQL-safe string
     * @throws InvalidArgumentException
     */
    public function escapef(string $format, mixed ...$values): string
    {
        $this->mysqli || throw new RuntimeException(__METHOD__ . "() called before DB connection established");

        $placeholderCount = substr_count($format, '?');
        $valueCount       = count($values);
        if ($placeholderCount !== $valueCount) {
            throw new InvalidArgumentException("escapef() placeholder count ($placeholderCount) doesn't match value count ($valueCount)");
        }

        return preg_replace_callback('/\?/', function () use (&$values) {
            $value = array_shift($values);
            if ($value instanceof SmartString) {
                $value = $value->value(); // unwrap before the type check; SmartString can wrap null/bool
            }

            return match (true) {
                is_array($value)                 => (string)$this->escapeCSV($value),
                $value instanceof SmartArrayBase => (string)$this->escapeCSV($value->toArray()),
                default                          => $this->escapeValue($value, 'escapef() value'),
            };
        }, $format);
    }

    /**
     * Internal use, undocumented by design. Use placeholders instead; they're
     * safer unless you know exactly what you're doing.
     *
     * Converts array values to a safe CSV string for use in MySQL IN clauses.
     *
     * NULL values are skipped: NULL never matches inside IN (...), and one NULL in a
     * NOT IN (...) list makes the whole clause return zero rows. Use IS NULL to match
     * NULL rows. Duplicates are removed. An empty list (or one that was all NULLs)
     * returns the SQL literal NULL, so IN (NULL) matches nothing.
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
     * @internal
     * @param array $values Array of values to convert
     * @return RawSql SQL-safe comma-separated list, deduplicated, NULLs skipped
     * @throws InvalidArgumentException on unsupported value types
     */
    public function escapeCSV(array $values): RawSql
    {
        $this->mysqli || throw new RuntimeException(__METHOD__ . "() called before DB connection established");

        $safeValues = [];
        foreach ($values as $value) {
            if ($value instanceof SmartString) {
                $value = $value->value(); // unwrap before the type check; SmartString can wrap null/bool
            }
            if ($value === null) {
                continue; // NULL never matches in IN and makes NOT IN return zero rows; use IS NULL to match NULLs
            }
            $safeValues[] = $this->escapeValue($value, 'IN-list value');
        }

        // Dedupe the finished SQL literals, not the raw values: array_unique on raw input
        // uses SORT_STRING, which would collapse type-distinct values like '' and false.
        $safeValues = array_unique($safeValues);
        return new RawSql($safeValues ? implode(',', $safeValues) : 'NULL');
    }

    /**
     * Convert one PHP value to a SQL literal. Every value ZenDB writes into SQL goes
     * through here: SET clauses, WHERE arrays, placeholders, escapef(), escapeCSV().
     *
     *   "O'Brien"        →  'O\'Brien'    escaped and quoted
     *   42               →  42
     *   3.14             →  3.14          exact, never rounded (see below)
     *   true             →  TRUE
     *   null             →  NULL
     *   DB::rawSql(...)  →  as-is         trusted SQL, not escaped
     *   NAN, INF         →  throws        no SQL literal exists
     *   array, object    →  throws        callers expand arrays to IN lists before calling
     *
     * Floats print as the shortest string that parses back to the same number
     * (var_export). A plain string cast rounds to 14 significant digits, which
     * silently changes large values: (string)12345678901234567.0 gives
     * "1.2345678901235E+16", a different number that matches the wrong rows.
     *
     * @param mixed  $value   Value to convert
     * @param string $context Named in error messages, e.g. "column 'age'" or "placeholder ?"
     * @return string SQL literal, safe to concatenate into a query
     * @throws InvalidArgumentException on NAN/INF and unsupported types
     */
    private function escapeValue(mixed $value, string $context = 'value'): string
    {
        return match (true) {
            is_null($value)          => 'NULL',
            is_int($value)           => (string)$value,
            is_float($value)         => is_finite($value)
                                        ? var_export($value, true) // shortest exact representation, independent of the precision ini setting
                                        : throw new InvalidArgumentException("NAN and INF have no SQL literal, can't escape $context"),
            is_bool($value)          => $value ? 'TRUE' : 'FALSE',
            $value instanceof RawSql => (string)$value,
            is_string($value)        => "'" . $this->mysqli->real_escape_string($value) . "'",
            default                  => throw new InvalidArgumentException("Unsupported type for $context: " . get_debug_type($value)),
        };
    }

    //endregion
    //region Result Processing

    /**
     * Fetch result rows with column mapping, smart joins, and auto-decryption.
     *
     * - Fast path: direct C-level MYSQLI_ASSOC fetch when no remapping is needed
     * - "First wins": duplicate column names use the first occurrence
     * - SmartJoins: multi-table queries add qualified names (e.g., 'users.name')
     * - Self-joins: adds alias-based names (e.g., 'a.name', 'b.name')
     * - Auto-decryption: MEDIUMBLOB columns are decrypted when an encryption key is configured
     */
    private function fetchMappedRows(mysqli_result|bool $mysqliResult): array
    {
        if (is_bool($mysqliResult)) {
            return [];  // INSERT/UPDATE/DELETE return true, not a result set
        }

        // Extract field metadata from result
        $fetchFields  = $mysqliResult->fetch_fields();
        $names        = array_column($fetchFields, 'name');
        $aliasToTable = array_filter(array_column($fetchFields, 'orgtable', 'table'));      // e.g., ['u' => 'users']
        $encryptedMap = DB::getEncryptedColumns($fetchFields);                              // [fieldIndex => colName] for MEDIUMBLOB cols

        // Fast path: no duplicate columns and no SmartJoins needed - use C-level associative fetch
        $hasDuplicateCols = count($names) !== count(array_flip($names));
        $needsSmartJoins  = $this->useSmartJoins && count($aliasToTable) > 1;
        if (!$hasDuplicateCols && !$needsSmartJoins) {
            $rows = $mysqliResult->fetch_all(MYSQLI_ASSOC);
            $mysqliResult->free();
            $this->decryptRows($rows, array_values($encryptedMap));         // decrypt by column name
            return $rows;
        }

        // Decrypt indexed values before the remap so bare, qualified, and alias keys all share one plaintext copy.
        // e.g., $values[0] → row['token'], row['users.token'], row['u.token']
        $mysqliNumRows = $mysqliResult->fetch_all(MYSQLI_NUM);
        $mysqliResult->free();
        $this->decryptRows($mysqliNumRows, array_keys($encryptedMap));      // decrypt by field index

        // Build the name → field-index map, then remap each numeric row into an associative row
        $columnIndexes = $this->buildColumnIndexes($fetchFields, $aliasToTable, $needsSmartJoins);
        $rows          = [];
        foreach ($mysqliNumRows as $numRow) {
            $row = [];
            foreach ($columnIndexes as $name => $index) {
                $row[$name] = $numRow[$index];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Build the name → field-index map used to remap numeric rows into associative rows.
     *
     * Starts with bare column names (first wins on duplicates). When SmartJoins is active, also adds
     * qualified names (e.g., 'users.name') and, for self-joins, alias names (e.g., 'a.name').
     *
     * Aliased views split by vendor: MySQL/Percona report the view's real name in orgtable while
     * MariaDB reports the alias, so a qualified key like 'viewname.col' only exists on MySQL and
     * 'alias.col' only on MariaDB (outside self-joins). Only the bare column name is portable for
     * aliased view columns; unaliased views agree everywhere. No code fix is possible - MariaDB's
     * metadata never exposes the view name. See docs/internal/db-behavior-matrix.md (2026-07).
     *
     * @param array $fetchFields     Field objects from mysqli_result::fetch_fields()
     * @param array $aliasToTable    Map of table alias → orgtable, e.g. ['u' => 'users']
     * @param bool  $needsSmartJoins Whether to add qualified and self-join alias keys
     * @return array Name → field index map, e.g. ['name' => 0, 'users.name' => 0, 'u.name' => 0]
     */
    private function buildColumnIndexes(array $fetchFields, array $aliasToTable, bool $needsSmartJoins): array
    {
        // Bare column names, first wins for duplicates
        $names         = array_column($fetchFields, 'name');
        $columnIndexes = array_flip(array_unique($names));    // e.g., ['name' => 0, 'email' => 1]

        if (!$needsSmartJoins) {
            return $columnIndexes;
        }

        // SmartJoins: add qualified names (e.g., 'users.name') and alias names for self-joins (e.g., 'a.name')
        $prefixLen      = strlen($this->tablePrefix);
        $selfJoinTables = array_filter(array_count_values($aliasToTable), fn($c) => $c > 1);

        foreach ($fetchFields as $index => $field) {
            if (!$field->orgtable || !$field->orgname) {
                continue;    // skip expression columns (COUNT(*), computed values)
            }

            $baseTable = str_starts_with($field->orgtable, $this->tablePrefix)
                ? substr($field->orgtable, $prefixLen)
                : $field->orgtable;

            $columnIndexes["$baseTable.$field->orgname"] ??= $index;            // e.g., 'users.name', first wins

            // Self-joined tables: add table alias names as well (e.g., 'a.name', 'b.name')
            if (isset($selfJoinTables[$field->orgtable])) {
                $columnIndexes["$field->table.$field->orgname"] ??= $index;     // e.g., 'u.name', first wins
            }
        }

        return $columnIndexes;
    }

    /**
     * Wrap rows in a result object with connection metadata: SmartArrayHtml with SmartString
     * values by default, plain SmartArray with raw values when `useSmartStrings` is off.
     */
    private function toSmartArray(array $rows, string $sql, string $baseTable = ''): SmartArrayBase
    {
        $properties = [
            'loadHandler' => $this->loadHandler,
            'mysqli'      => [
                'query'         => $sql,
                'baseTable'     => $baseTable,
                'affected_rows' => $this->mysqli->affected_rows,
                'insert_id'     => $this->mysqli->insert_id,
            ],
        ];
        return $this->useSmartStrings ? new SmartArrayHtml($rows, $properties) : new SmartArray($rows, $properties);
    }

    //endregion
    //region Object Lifecycle

    /**
     * Bind the clone's TableInfo to the clone, so it reads the clone's own tablePrefix
     * (clone() applies prefix overrides after cloning; TableInfo reads the prefix at call time).
     */
    public function __clone()
    {
        if ($this->table !== null) {
            $this->table = new TableInfo($this);
        }
    }

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
        self::$secrets        ??= new WeakMap();
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
            $this->$key                 = null;            // clear property to prevent leakage
            unset($config[$key]);                          // consume key so it won't hit the property loop
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

    // Connection credentials (values live in the WeakMap vault; the properties exist so
    // sealSecrets()'s $this->$key = null writes aren't PHP 8.2+ deprecated dynamic properties)
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

    /**
     * Sets identically on every supported server (MySQL emits warnings, MariaDB doesn't).
     * NO_ZERO_DATE is deliberately omitted so '0000-00-00' inserts work; partial-zero dates
     * like '2024-00-15' still fail with error 1292 everywhere.
     * See docs/internal/db-behavior-matrix.md (2026-07).
     */
    private string $sqlMode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    //endregion
}
