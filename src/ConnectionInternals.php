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
use mysqli_result;
use stdClass;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function addcslashes, array_column, array_count_values, array_filter, array_flip, array_is_list, array_key_exists, array_keys, array_map, array_unique, array_values, count, explode, get_debug_type, get_object_vars, implode, is_array, is_bool, is_finite, is_float, is_int, is_object, is_string, preg_grep, preg_match, preg_match_all, preg_replace, preg_replace_callback, str_contains, str_replace, str_starts_with, strlen, strspn, strtoupper, substr, trim, var_export;
use const MYSQLI_ASSOC, MYSQLI_NUM;

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
    private int   $positionalParamCount      = 0;      // set by parseParams(): count of :N keys in paramValues, read by replacePlaceholders()

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
     * The two common shapes (positional values, named array) are handled with plain
     * typed loops. PHP named arguments (query($sql, name: 'Bob')) are rejected outright;
     * every other odd shape - arrays mixing int and string keys, the deprecated
     * positional array, invalid input - goes to parseParamsGeneral(), which owns the
     * remaining error and deprecation messages.
     *
     * @param array $args Variadic args from query method
     * @return array Parameter map, e.g. [':1' => 'a', ':2' => 'b'] or [':name' => 'Bob']
     * @throws InvalidArgumentException
     */
    private function parseParams(array $args): array
    {
        $this->paramsFromPositionalArray = false;
        $this->positionalParamCount      = 0;

        if (!$args) {
            return [];
        }

        // PHP collects named arguments (query($sql, name: 'Bob')) into ...$params as
        // string keys - legal syntax, but not a supported way to pass query params
        if (!array_is_list($args)) {
            throw new InvalidArgumentException("Query params can't be passed as PHP named arguments. Pass the array as one argument instead of spreading it: query(\$sql, \$params)");
        }

        // Named params: single array of ':name' => value pairs
        if (count($args) === 1 && is_array($args[0])) {
            $params = $args[0];
            if (!$params) {
                return [];
            }
            $values          = [];
            $positionalCount = 0;
            foreach ($params as $key => $value) {
                // Int keys (deprecated positional array, or mixed with named) and invalid
                // names take the general path, which logs or throws the canonical message
                if (!is_string($key) || !preg_match('/^:(?!_|zdb_)\w+\z/', $key)) {
                    return $this->parseParamsGeneral($args);
                }
                if ($key[1] <= '9' && strspn($key, '0123456789', 1) === strlen($key) - 1) {
                    $positionalCount++;   // ':12'-style keys count as positional for the unused-positional check
                }
                $values[$key] = !is_object($value) ? $value : $this->unwrapParamObject($value);
            }
            $this->positionalParamCount = $positionalCount;
            return $values;
        }

        // Single scalar value (query($sql, 123)): most common positional shape, skip the loop.
        // Can't be an array here - the named-params branch above handled that.
        if (count($args) === 1) {
            $this->positionalParamCount = 1;
            return [':1' => !is_object($args[0]) ? $args[0] : $this->unwrapParamObject($args[0])];
        }

        // 2-3 direct values: over the cap or arrays mixed in take the general path (throws)
        if (count($args) > 3) {
            return $this->parseParamsGeneral($args);
        }
        foreach ($args as $value) {
            if (is_array($value)) {
                return $this->parseParamsGeneral($args);
            }
        }

        // Positional values become [':1' => v1, ':2' => v2, ...]
        $values          = [];
        $positionalCount = 0;
        foreach ($args as $value) {
            $values[':' . ++$positionalCount] = !is_object($value) ? $value : $this->unwrapParamObject($value);
        }
        $this->positionalParamCount = $positionalCount;
        return $values;
    }

    /**
     * Parser for the shapes parseParams() doesn't fast-path, and the single owner of
     * every param-shape error and deprecation message. parseParams() has already
     * handled empty args and reset the per-call state when this runs.
     *
     * @param array $args Variadic args from query method
     * @return array Parameter map, e.g. [':1' => 'a', ':2' => 'b'] or [':name' => 'Bob']
     * @throws InvalidArgumentException
     */
    private function parseParamsGeneral(array $args): array
    {
        // Valid forms: up to 3 direct values (for ? placeholders), or one array of ':name' => value pairs.
        // Positional values in an array are deprecated and will throw in a future version.
        $passedAsArray  = count($args) === 1 && is_array($args[0]);
        $passedAsValues = true;
        foreach ($args as $arg) {
            if (is_array($arg)) {
                $passedAsValues = false;
                break;
            }
        }
        $isPositionalArray = $passedAsArray && $args[0] !== [];
        if ($isPositionalArray) {
            foreach (array_keys($args[0]) as $key) {
                if (is_string($key)) { // any string key means named params, not positional
                    $isPositionalArray = false;
                    break;
                }
            }
        }

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
                    // SECURITY: key failed validation, encode before it can reach page output (match arm, so no room for the $h variable)
                    !preg_match("/^:\w+\z/", $key) => throw new InvalidArgumentException("Invalid param name '" . DB::h($key) . "'. Must start with ':' followed by (a-z, A-Z, 0-9, _)"),
                    str_starts_with($key, ':_')    => throw new InvalidArgumentException("Invalid param name '$key'. Names can't start with :_ (the deprecated table-prefix syntax); start the name with a letter or digit"),
                    str_starts_with($key, ':zdb_') => throw new InvalidArgumentException("Invalid param name '$key'. Names can't start with :zdb_ (reserved prefix)"),
                    default                        => $key,
                };
            }

            // Check for duplicates
            if (array_key_exists($name, $values)) {
                throw new InvalidArgumentException("Duplicate param name '$name'");
            }

            $values[$name] = !is_object($value) ? $value : $this->unwrapParamObject($value);
        }

        // Enforce consistent placeholder style
        if ($hasPositional && $hasNamed) {
            throw new InvalidArgumentException("Can't mix positional (?) and named (:param) placeholders. Use one style consistently.");
        }

        $this->positionalParamCount = count(preg_grep('/^:\d+$/', array_keys($values)));
        return $values;
    }

    /**
     * Unwrap an object parameter value: RawSql passes through, SmartString/SmartNull/
     * SmartArray unwrap to their raw values, anything else throws.
     */
    private function unwrapParamObject(object $value): mixed
    {
        return match (true) {
            $value instanceof RawSql         => $value,
            $value instanceof SmartString    => $value->value(),
            $value instanceof SmartNull      => null,
            $value instanceof SmartArrayBase => $value->toArray(),
            default                          => throw new InvalidArgumentException("Parameters cannot be " . get_debug_type($value)),
        };
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
         * What this catches: a developer writing WHERE city = '$city' gets a throw the
         * moment a real value arrives - "Vancouver" produces WHERE city = 'Vancouver' -
         * which forces placeholders before the code can work at all.
         *
         * What it doesn't: a value carrying its own balanced quotes reaches this check
         * as nothing but empty strings and passes. That gap is known and documented in
         * docs/security-gotchas.md; the tradeoff is settled in
         * docs/internal/design-decisions.md.
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
            if (preg_match('/([\'"])(.*?)\1/', $sqlForQuoteCheck, $matches)) {
                $h          = DB::h(...); // SECURITY: quoted text can carry interpolated user data, encode before it can reach page output (quote chars stay literal)
                $quotedText = $matches[1] . $h($matches[2]) . $matches[1];
                throw new InvalidArgumentException("Quotes not allowed in template. Replace $quotedText with :paramName and add: [ ':paramName' => $quotedText ]");
            }

            throw new InvalidArgumentException("Quotes not allowed in template. Use :paramName placeholder instead.");
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

        // Standalone numbers and numeric literals - force use of placeholders. Hex
        // (0x1AF), binary (0b1010), and scientific (1e10) need their own alternatives
        // because their digits touch a letter, so \b\d+\b can't match them. MySQL
        // evaluates each to a value, so they belong in placeholders like any other
        // literal. Requiring at least one digit after 0x/0b keeps identifiers like
        // `0boxes` from matching. Group 1 captures only for standalone numbers.
        if (preg_match('/\b0[xb][0-9a-f]+|\b\d+e[+-]?\d+|\b(\d+)\b/i', $sql, $matches)) {
            if (isset($matches[1])) {
                $n = $matches[1];
                throw new InvalidArgumentException("Standalone number in template. Replace $n with :n$n and add: [ ':n$n' => $n ]");
            }
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
     * We reject empty input or strings starting with a clause keyword
     * (ORDER, GROUP, LIMIT, ...) which indicate no WHERE condition was provided.
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

        // string - valid if where has content and doesn't start with a non-condition clause
        // like ORDER/GROUP/LIMIT: those without WHERE would affect all rows, e.g.
        // "DELETE FROM t ORDER BY id LIMIT 1". Conditions like "id = ?" are valid
        // because whereFromString() prepends "WHERE "
        if (is_string($where) && trim($where) && !preg_match('/^\s*(ORDER|GROUP|HAVING|LIMIT|OFFSET|FOR)\b/i', $where)) {
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
     *   - SmartNull (becomes NULL, same as placeholders)
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

            isset(DB::$safeIdentifiers[$column]) || DB::assertIdentifier($column, 'column name');

            if ($value instanceof SmartString) {
                $value = $value->value(); // unwrap before the type check; SmartString can wrap null/bool
            }
            if ($value instanceof SmartNull) {
                $value = null; // same as the placeholder path
            }
            // string/int arms copy escapeValue() output to skip the method call; EscapeParityTest pins them identical
            if (is_string($value)) {
                $setElements[] = "`$column` = '" . $this->mysqli->real_escape_string($value) . "'";
            } elseif (is_int($value)) {
                $setElements[] = "`$column` = $value";
            } else {
                $setElements[] = "`$column` = " . $this->escapeValue($value, "column '$column'");
            }
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
        $hasLeadingKeyword = preg_match('/^\s*(WHERE|FOR|ORDER|GROUP|HAVING|LIMIT|OFFSET)\b/i', $where);
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
     *   - null, SmartNull (becomes IS NULL)
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

            isset(DB::$safeIdentifiers[$column]) || DB::assertIdentifier($column, 'column name');

            if ($value instanceof SmartString) {
                $value = $value->value(); // unwrap before the type check; SmartString can wrap null/bool
            }
            if ($value instanceof SmartNull) {
                $value = null; // same as the placeholder path
            }
            // string/int arms copy escapeValue() output to skip the method call; EscapeParityTest pins them identical
            if (is_string($value)) {
                $conditions[] = "`$column` = '" . $this->mysqli->real_escape_string($value) . "'";
            } elseif (is_int($value)) {
                $conditions[] = "`$column` = $value";
            } elseif ($value === null) {
                $conditions[] = "`$column` IS NULL";
            } elseif ($value instanceof SmartArrayBase) {
                $conditions[] = "`$column` IN (" . $this->escapeCSV($value->toArray()) . ")";
            } elseif (is_array($value)) {
                $conditions[] = "`$column` IN (" . $this->escapeCSV($value) . ")";
            } else {
                $conditions[] = "`$column` = " . $this->escapeValue($value, "column '$column'");
            }
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
     *   {{column}}         - encrypted column read, expands to AES_DECRYPT(`column`, @ek);
     *                        a leading :: applies the table prefix ({{::users.token}})
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
        if (str_contains($template, ':_')) {
            $template = preg_replace('/(?<!:):_/', '::', $template, -1, $count);
            if ($count > 0) {
                DB::logDeprecation(":_ syntax is deprecated, use :: instead");
            }
        }

        // {{column}} or {{table.column}} - expand encrypted column references, see decryptExpr().
        // A leading :: applies the table prefix, same as outside the braces: write the column
        // reference as you would unencrypted ({{::users.token}}, {{u.token}}), wrapped in braces.
        if (str_contains($template, '{{')) {
            $template = preg_replace_callback(
                '/\{\{(::)?([\w.-]+)}}/',
                fn($m) => DB::decryptExpr(($m[1] ? $this->tablePrefix : '') . $m[2]),
                $template,
            );
        }

        /*
         * Placeholder types, one regex alternative each (in match-priority order):
         *
         *   \?                    ?         O'Brien → "O\'Brien"        - value, quoted and escaped
         *   :[a-zA-Z]\w*\b        :name     O'Brien → "O\'Brien"        - value, quoted and escaped
         *   `\?`                  `?`       users → `users`             - identifier, unquoted, throws if unsafe chars
         *   `:[a-zA-Z]\w*\b`      `:name`   users → `users`             - identifier, unquoted, throws if unsafe chars
         *   `::\?`                `::?`     users → `cms_users`         - identifier with table prefix
         *   `:::[a-zA-Z]\w*\b`    `:::name` users → `cms_users`         - identifier with table prefix
         *   ::\?                  ::?       user% → 'cms_user%'         - value with table prefix, quoted and escaped
         *   :::[a-zA-Z]\w*\b      :::name   user% → 'cms_user%'         - value with table prefix, quoted and escaped
         *   ::                    ::        cms_                        - table prefix alone (after the ::placeholders above)
         */
        $placeholderRegex = '/\?|:[a-zA-Z]\w*\b|`\?`|`:[a-zA-Z]\w*\b`|`::\?`|`:::[a-zA-Z]\w*\b`|::\?|:::[a-zA-Z]\w*\b|::/';

        // A placeholder inside a /* */ block comment isn't protected: escaping handles quotes
        // and newlines but not the */ close sequence, so a value containing */ closes the
        // comment and the rest runs as live SQL. Line comments (-- and #) are safe - breakout
        // needs a newline, which real_escape_string escapes. str_contains gates the strip so
        // the recount only runs when a block comment is present (28 ns vs 227 ns ungated).
        if (str_contains($template, '/*')) {
            $withoutBlockComments = preg_replace('!/\*.*?(?:\*/|$)!s', '', $template);
            if (preg_match_all($placeholderRegex, $template) !== preg_match_all($placeholderRegex, $withoutBlockComments)) {
                throw new InvalidArgumentException("Placeholders are not supported inside /* */ comments; a value containing */ would close the comment and run as SQL. Move the value out of the comment, or build the comment text yourself.");
            }
        }

        // Find and replace all placeholders with their escaped/formatted values
        $positionalCount = 0;
        $sql = preg_replace_callback(
            pattern : $placeholderRegex,
            callback: function ($matches) use (&$positionalCount) {
                $match = $matches[0]; // e.g., ?, :name, `?`, etc

                // Fast arms for the dominant shapes: bare ? and :name with int/string values.
                // Output matches escapeValue() exactly; anything else falls through to the generic path.
                if ($match === '?') {
                    $value = $this->paramValues[':' . ($positionalCount + 1)] ?? null;
                    if (is_int($value)) {
                        $positionalCount++;
                        return (string)$value;
                    }
                    if (is_string($value)) {
                        $positionalCount++;
                        return "'" . $this->mysqli->real_escape_string($value) . "'";
                    }
                } elseif ($match[0] === ':' && $match[1] !== ':') {  // :name (not :: or :::name)
                    $value = $this->paramValues[$match] ?? null;
                    if (is_int($value)) {
                        return (string)$value;
                    }
                    if (is_string($value)) {
                        return "'" . $this->mysqli->real_escape_string($value) . "'";
                    }
                } elseif ($match === '::') {
                    return $this->tablePrefix;  // bare table prefix
                }

                $value = $this->getPlaceholderValue($match, $positionalCount);

                // Backtick placeholders: insert safe identifiers (table/column names) unquoted (or throw if unsafe).
                if ($match[0] === '`') {
                    if (!is_string($value)) {
                        $h = DB::h(...); // SECURITY: var_export prints array contents, encode before they can reach page output
                        throw new InvalidArgumentException("Invalid backtick identifier: {$h(var_export($value, true))}, expected a string");
                    }
                    isset(DB::$safeIdentifiers[$value]) || DB::assertIdentifier($value, 'backtick identifier');
                    return "`$value`";
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
        $positionalProvided = $this->positionalParamCount;   // recorded by parseParams(); paramValues only ever holds its return value
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
     * With $escapeLikeWildcards, the result is built for a LIKE pattern: the whole
     * value matches as literal text, and nothing in it acts as a wildcard. To get
     * that, % _ and \ are escaped before the SQL escape, because MySQL decodes a
     * pattern twice (string parser first, then LIKE). The like*() helpers wrap
     * this; reach for them first.
     *
     *     $sql = "name LIKE '%" . $this->escape($search, true) . "%'";  // $search matches literally, even "50%"
     *
     * @internal
     * @param string|int|float|null|SmartString $input               Value to escape
     * @param bool                              $escapeLikeWildcards Make the value match as literal text in a LIKE pattern (escapes %, _, and \)
     * @return string Escaped string (without quotes)
     */
    public function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        // Unwrap SmartString
        if ($input instanceof SmartString) {
            $input = $input->value();
        }

        // Floats get the same exact literal as every other escape path
        if (is_float($input)) {
            $input = $this->floatToSql($input, 'escape() value');
        }

        // Escape LIKE wildcards first, on the raw value: MySQL decodes a pattern twice
        // (string parser, then LIKE), and real_escape_string() adapts to the server's
        // string-parsing mode, so it must run last. Backslash is escaped too so a
        // literal backslash in the input can't turn a following % or _ into a wildcard.
        if ($escapeLikeWildcards) {
            $input = addcslashes((string)$input, '\\%_');
        }

        // Escape using mysqli
        return $this->mysqli->real_escape_string((string)$input);
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

        $parts            = explode('?', $format);
        $placeholderCount = count($parts) - 1;
        $valueCount       = count($values);
        if ($placeholderCount !== $valueCount) {
            throw new InvalidArgumentException("escapef() placeholder count ($placeholderCount) doesn't match value count ($valueCount)");
        }

        $sql = $parts[0];
        foreach ($values as $i => $value) {
            if ($value instanceof SmartString) {
                $value = $value->value(); // unwrap before the type check; SmartString can wrap null/bool
            }
            // arms copy escapeValue() output to skip the method call; EscapeParityTest pins them identical
            if (is_string($value)) {
                $sql .= "'" . $this->mysqli->real_escape_string($value) . "'";
            } elseif (is_int($value)) {
                $sql .= $value;
            } elseif (is_array($value)) {
                $sql .= $this->escapeCSV($value);
            } elseif ($value instanceof SmartArrayBase) {
                $sql .= $this->escapeCSV($value->toArray());
            } elseif ($value === null) {
                $sql .= 'NULL';
            } elseif (is_float($value)) {
                $sql .= $this->floatToSql($value, 'escapef() value');
            } elseif (is_bool($value)) {
                $sql .= $value ? 'TRUE' : 'FALSE';
            } elseif ($value instanceof RawSql) {
                $sql .= $value;
            } else {
                throw new InvalidArgumentException("Unsupported type for escapef() value: " . get_debug_type($value));
            }
            $sql .= $parts[$i + 1];
        }
        return $sql;
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
     * becomes the zero-row subquery SELECT 0 FROM (SELECT 0) empty_set WHERE 0, so
     * IN matches nothing and NOT IN matches everything.
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
            // int/string arms copy escapeValue() output to skip the method call (ints first: ID lists
            // are the common case); EscapeParityTest pins them identical
            if (is_int($value)) {
                $safeValues[] = (string)$value;
            } elseif (is_string($value)) {
                $safeValues[] = "'" . $this->mysqli->real_escape_string($value) . "'";
            } elseif ($value === null) {
                continue; // NULL never matches in IN and makes NOT IN return zero rows; use IS NULL to match NULLs
            } else {
                $safeValues[] = $this->escapeValue($value, 'IN-list value');
            }
        }

        // Dedupe the finished SQL literals, not the raw values: array_unique on raw input
        // uses SORT_STRING, which would collapse type-distinct values like '' and false.
        $safeValues = array_unique($safeValues);

        // Empty list: a zero-row subquery, so IN matches nothing and NOT IN matches everything.
        // The row source must be a derived table, not DUAL: MySQL before 5.7.8 and early MariaDB 10.2
        // ignore an impossible WHERE on DUAL inside IN subqueries (MySQL bug #17895), which reads as
        // `x = 0` and matches every row of a string column via string-to-number coercion
        return new RawSql($safeValues ? implode(',', $safeValues) : 'SELECT 0 FROM (SELECT 0) empty_set WHERE 0');
    }

    /**
     * Convert one PHP value to a SQL literal. Every value ZenDB writes into SQL goes
     * through here or through an inlined copy of the string/int arms in a hot path
     * (whereFromArray(), buildSetClause(), escapeCSV(), replacePlaceholders() fast
     * arms); EscapeParityTest pins those copies byte-identical to this function.
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
        // Checked most-common-first: strings and ints cover nearly all real values
        if (is_string($value)) {
            return "'" . $this->mysqli->real_escape_string($value) . "'";
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if ($value === null) {
            return 'NULL';
        }
        if (is_float($value)) {
            return $this->floatToSql($value, $context);
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if ($value instanceof RawSql) {
            return (string)$value;
        }
        throw new InvalidArgumentException("Unsupported type for $context: " . get_debug_type($value));
    }

    /**
     * Convert a finite float to the shortest SQL literal that parses back to the
     * identical double. Every float ZenDB writes into SQL goes through here, so one
     * value has one spelling on every path: placeholders, SET clauses, IN lists,
     * escape(), and the like* helpers.
     *
     *     0.1 + 0.2            →  0.30000000000000004    the value the variable actually holds
     *     2.0                  →  2.0
     *     12345678901234567.0  →  12345678901234568.0    ...567 is not representable; the variable holds ...568
     *
     * A plain (string) cast rounds to 14 digits and prints values PHP isn't
     * actually holding, so writes lose precision and WHERE equality misses
     * stored values (MySQL reads '0.3' as a number that won't equal the sum).
     *
     * The imprecision starts in PHP, not here: floats are binary, so 0.1 + 0.2
     * is already 0.30000000000000004 before ZenDB sees it. This function writes
     * exactly what PHP has. To store something else, convert before passing:
     *
     *     round(0.1 + 0.2, 2)          →  0.3                    rounded to a precision you chose
     *     (string)(0.1 + 0.2)          →  '0.3'                  strings pass through untouched...
     *     (string)12345678901234568.0  →  '1.2345678901235E+16'  ...but the cast E-notates large floats
     *
     * For exact values, use ints of the smallest unit (cents, not dollars) or a
     * DECIMAL column with string input.
     *
     * NAN and INF have no SQL literal, so they throw.
     */
    private function floatToSql(float $value, string $context = 'value'): string
    {
        return is_finite($value)
            ? var_export($value, true) // exact: php.ini serialize_precision, -1 (shortest round-trip) by default since PHP 7.1
            : throw new InvalidArgumentException("NAN and INF have no SQL literal, can't escape $context");
    }

    //endregion
    //region Result Processing

    /**
     * Whether a query() can only produce columns from one table, so field metadata
     * isn't needed: no SmartJoins possible, and duplicate columns are detectable
     * structurally (assoc fetch collapses them).
     *
     * Sniffs the template BEFORE placeholder expansion, so escaped string values
     * (which can legally contain 'join' or commas) can't affect the answer. RawSql
     * params are the one way SQL text enters after expansion, so their text is
     * checked too (paramValues is set by the caller, bare or inside IN-list arrays).
     *
     * False negatives just take the metadata path; false positives would silently
     * drop SmartJoins keys, so anything that could involve a second table answers
     * no: a statement that isn't SELECT or WITH (CALL and EXECUTE run SQL the
     * template doesn't contain), JOIN, or a comma anywhere after the first FROM
     * (comma-joins, and conservatively multi-column ORDER BY). RawSql fragments
     * can land anywhere in the SQL, so they also reject FROM and bare commas;
     * pagingSql's 'LIMIT x OFFSET y' passes. UNIONs are single-table-equivalent: no server
     * attributes union result columns in fetch_fields() (table/orgtable empty on
     * all 22 matrix servers), so SmartJoins can't trigger and duplicate columns
     * are still caught structurally. See docs/internal/db-behavior-matrix.md.
     */
    private function isSingleTableQuery(string $template): bool
    {
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $template)) {
            return false;   // CALL, EXECUTE, and friends: the tables they read aren't in the template
        }

        // [^,]* rather than .*: same answer, no backtracking. preg_match returns false when
        // PCRE gives up, and that has to land on the metadata path, not the fast one.
        if (preg_match('/join|from[^,]*,/i', $template) !== 0) {
            return false;
        }

        foreach ($this->paramValues as $param) {
            foreach (is_array($param) ? $param : [$param] as $value) {
                if ($value instanceof RawSql && preg_match('/join|from|,/i', (string)$value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Forget cached encrypted-column lists when a query template contains DDL, so the
     * next read/write re-probes the changed schema (e.g. a column altered to MEDIUMBLOB
     * mid-request would otherwise keep writing plaintext from a stale list).
     * Called by query() and queryOne(), the only methods that accept DDL.
     */
    private function clearEncryptedColumnsCacheOnDdl(string $sqlTemplate): void
    {
        if ($this->encryptedColumnsCache && preg_match('/^\s*(ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $sqlTemplate)) {
            $this->encryptedColumnsCache = [];
        }
    }

    /**
     * Fetch result rows with column mapping, smart joins, and auto-decryption.
     *
     * - $singleTable: caller guarantees a single-table `SELECT *` (no duplicate columns or
     *   SmartJoins possible), skipping metadata checks entirely; $fullTable names the table
     *   so keyed connections can cache its encrypted-column list
     * - $sqlTemplate: query()'s pre-expansion template; when it (and any RawSql params)
     *   can only produce single-table columns, metadata is skipped the same way
     * - Fast path: direct C-level MYSQLI_ASSOC fetch when no remapping is needed
     * - "First wins": duplicate column names use the first occurrence
     * - SmartJoins: multi-table queries add qualified names (e.g., 'users.name')
     * - Self-joins: adds alias-based names (e.g., 'a.name', 'b.name')
     * - Auto-decryption: MEDIUMBLOB columns are decrypted when an encryption key is configured
     */
    private function fetchMappedRows(mysqli_result|MysqliResultReplay|bool $mysqliResult, bool $singleTable = false, string $fullTable = '', string $sqlTemplate = ''): array
    {
        if (is_bool($mysqliResult)) {
            return [];  // INSERT/UPDATE/DELETE return true, not a result set
        }

        // Single-table `SELECT *` (select/selectOne build that SQL themselves) can't have
        // duplicate columns or joined tables, so rows need no remapping. Encrypted columns
        // are looked up once per table per connection: the first select harvests the
        // MEDIUMBLOB column list from this result's own field metadata, later selects skip
        // fetch_fields() entirely (it builds a stdClass per column on every call)
        if ($singleTable) {
            $encryptedCols = [];
            if ($this->hasEncryptionKey) {
                if (!isset($this->encryptedColumnsCache[$fullTable])) {
                    $this->encryptedColumnsCache[$fullTable] = array_values(DB::getEncryptedColumns($mysqliResult->fetch_fields()));
                }
                $encryptedCols = $this->encryptedColumnsCache[$fullTable];
            }
            $rows = $mysqliResult->fetch_all(MYSQLI_ASSOC);
            $mysqliResult->free();
            $this->decryptRows($rows, $encryptedCols);
            return $rows;
        }

        // query() fast path: single-table template on an unkeyed connection. The only
        // remap trigger left is duplicate SELECT-list columns, and those show up
        // structurally: assoc fetch collapses them, leaving fewer keys than field_count.
        if ($sqlTemplate !== '' && !$this->hasEncryptionKey && $this->isSingleTableQuery($sqlTemplate)) {
            $rows = $mysqliResult->fetch_all(MYSQLI_ASSOC);
            if (!$rows || count($rows[0]) === $mysqliResult->field_count) {
                $mysqliResult->free();
                return $rows;
            }
            $mysqliResult->data_seek(0);  // duplicate columns: refetch through the metadata path
            unset($rows);                 // assoc collapse is last-wins, we return first-wins: unusable, and it would sit here through the numeric fetch
        }

        // Field metadata finds encrypted columns (when a key is set) plus, for arbitrary
        // query() SQL, duplicate columns and SmartJoins
        $fetchFields  = $mysqliResult->fetch_fields();
        $encryptedMap = $this->hasEncryptionKey ? DB::getEncryptedColumns($fetchFields) : [];   // [fieldIndex => colName] for MEDIUMBLOB cols

        // Extract field metadata from result
        $names        = array_column($fetchFields, 'name');
        $aliasToTable = array_filter(array_column($fetchFields, 'orgtable', 'table'));      // e.g., ['u' => 'users']

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
        $selfJoinTables = [];
        foreach (array_count_values($aliasToTable) as $table => $aliasCount) {
            if ($aliasCount > 1) {
                $selfJoinTables[$table] = $aliasCount;
            }
        }

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

        // fromDatabaseRows() trusts the rows are flat scalar/null arrays - guaranteed here,
        // every fetchMappedRows() path returns mysqli-shaped rows - and skips the
        // constructor's per-field scan; toArray() then returns the same rows without rebuilding
        return $this->useSmartStrings
            ? SmartArrayHtml::fromDatabaseRows($rows, $properties)
            : SmartArray::fromDatabaseRows($rows, $properties);
    }

    /**
     * Single-row version of toSmartArray() for selectOne()/queryOne(): builds the same
     * result set but returns its first row (an empty collection when there are no rows),
     * skipping the first() and asHtml()/asRaw() conversion steps. root() on the row
     * returns the full result set.
     */
    private function toSmartArrayRow(array $rows, string $sql, string $baseTable = ''): SmartArrayBase
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

        return $this->useSmartStrings
            ? SmartArrayHtml::fromDatabaseRow($rows, $properties)
            : SmartArray::fromDatabaseRow($rows, $properties);
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
        if ($this->mysqli !== null) {
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
        foreach (self::$secrets[$this->vaultKey] ?? [] as $key => $value) {
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
     * Vault lookup token. Secrets are keyed by this empty object rather than the
     * Connection itself, so PHP's clone operator copies the token and every clone
     * reads the same vault entry. The entry is freed when the last clone is.
     */
    private ?stdClass $vaultKey = null;

    /**
     * Seal credentials into the WeakMap vault and null them on the object.
     * Requires hostname, username, password, and database to be present
     * in $config, throws otherwise. Clones skip this: they share the
     * source's vault entry through the copied $vaultKey.
     *
     * @param array $config Config array; credential keys are consumed
     * @throws RuntimeException If a required credential is missing, or any credential isn't a string
     */
    private function sealSecrets(array &$config): void
    {
        self::$secrets                  ??= new WeakMap();
        $this->vaultKey                 = new stdClass();
        self::$secrets[$this->vaultKey] = [];

        foreach (self::$secretKeys as $key) {
            $value = $config[$key] ?? null;

            if ($value === null && $key !== 'encryptionKey') { // encryptionKey is the one optional secret
                throw new RuntimeException("Missing required config: '$key'");
            }
            if ($value !== null && !is_string($value)) {
                throw new RuntimeException("Config '$key' must be a string, got " . get_debug_type($value));
            }
            // '0' never worked as a key (PHP-falsey, presence checks read it as "no key"), so
            // accepting it now would write ciphertext into tables holding plaintext - reject it
            if ($key === 'encryptionKey' && $value === '0') {
                throw new RuntimeException("Config 'encryptionKey' can't be '0'. Use '' to disable encryption, or choose a different key.");
            }
            self::$secrets[$this->vaultKey][$key] = $value;
            $this->$key                           = null;  // clear property to prevent leakage
            unset($config[$key]);                          // consume key so it won't hit the property loop
        }

        // Hoisted so per-query paths can check key presence without a vault lookup.
        // Strict compare: only null and '' mean "no encryption"
        $this->hasEncryptionKey = (self::$secrets[$this->vaultKey]['encryptionKey'] ?? '') !== '';
    }

    /**
     * Read a credential from the vault.
     */
    private function secret(string $key): ?string
    {
        return self::$secrets[$this->vaultKey][$key] ?? null;
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

    /** @var bool True when a non-empty `encryptionKey` is sealed in the vault (set by sealSecrets) */
    private bool $hasEncryptionKey = false;

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
