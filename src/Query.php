<?php
declare(strict_types=1);
namespace Itools\ZenDB;

use InvalidArgumentException;
use mysqli;

/**
 * Query class for ZenDB
 *
 * Builds and executes SQL queries with parameter support (named and positional).
 */
class Query
{
    // Connection settings
    public mysqli $mysqli;
    public string $tablePrefix;

    // Parameter collection
    public Params $params;

    // Generated clause properties (populated by constructor options)
    public string $whereEtc  = '';
    public string $setClause = '';

    //region Query Generation

    /**
     * Build SQL from template with clause placeholders and parameter binding.
     *
     * Replaces {whereEtc} and {setClause} placeholders, then processes parameter placeholders.
     *
     *     $sql = Query::build(
     *         template:    "SELECT * FROM `::$baseTable` {whereEtc}",
     *         mysqli:      $this->mysqli,
     *         tablePrefix: $this->tablePrefix,
     *         where:       $where,
     *         params:      $params,
     *     );
     *
     * @param string            $template    SQL template with {whereEtc}, {setClause}, ?, :name, :: placeholders
     * @param mysqli            $mysqli      The mysqli connection to use
     * @param string            $tablePrefix The table prefix for :: placeholders
     * @param array|string|null $where       WHERE condition (for {whereEtc})
     * @param array             $params      Parameters to bind
     * @param array|null        $set         Column => value pairs (for {setClause})
     * @return string Final SQL query ready to execute
     * @throws DBException
     */
    public static function build(string $template, mysqli $mysqli, string $tablePrefix = '', array|string|null $where = null, array $params = [], ?array $set = null): string
    {
        $query              = new self();
        $query->mysqli      = $mysqli;
        $query->tablePrefix = $tablePrefix;
        $query->params      = new Params();

        // Add user-provided params
        if ($params) {
            $query->params->addFromArgs($params);
        }

        // Build clauses (adds internal params as side effect)
        if ($set !== null) {
            $query->setClause = $query->getSetClause($set);
        }
        if ($where !== null) {
            $query->whereEtc = $query->getWhereEtc($where);
        }

        // Replace clause placeholders (no-op if not present in template)
        $template = strtr($template, [
            '{whereEtc}'  => $query->whereEtc,
            '{setClause}' => $query->setClause,
        ]);

        return $query->getSql($template);
    }

    /**
     * Compile SQL template with escaped parameter values.
     *
     * @param string $template SQL template with placeholders (?, :name, ::, etc.)
     * @return string Final SQL query ready to execute
     * @throws DBException
     */
    public function getSql(string $template): string
    {
        MysqliWrapper::setLastQuery($template);

        $template = rtrim($template); // trim trailing space from "...query $whereEtc" with no where

        // Handle trailing LIMIT # clauses
        $limitRx = '/\bLIMIT\s+\d+\s*$/i';
        if (!str_contains($template, ';') && preg_match($limitRx, $template, $matches)) {
            $limitExpr = $matches[0];
            $template  = preg_replace($limitRx, ':zdb_limit', $template);
            $this->params->addInternal(':zdb_limit', DB::rawSql($limitExpr));
        }

        // Template error checking
        self::assertSafeTemplate($template);

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
    //region Clause Builders

    /**
     * Build WHERE clause from various input types.
     *
     * @param array|string $where Column=>value array or SQL string
     * @return string SQL WHERE clause (or empty string)
     * @throws DBException|InvalidArgumentException
     */
    public function getWhereEtc(array|string $where): string
    {
        if (is_array($where)) {
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
        } else {
            $whereEtc = $where;
        }

        // Add WHERE if not already present
        $requiredLeadingKeyword = "/^\s*(WHERE|FOR|ORDER|LIMIT|OFFSET)\b/i";
        if (!preg_match($requiredLeadingKeyword, $whereEtc) && !preg_match("/^\s*$/", $whereEtc)) {
            $whereEtc = "WHERE " . $whereEtc;
        }

        // Validate
        if ($whereEtc !== "") {
            if (!preg_match($requiredLeadingKeyword, $whereEtc)) {
                throw new InvalidArgumentException("SQL clauses must start with one of: WHERE, ORDER BY, LIMIT, OFFSET. Got: $whereEtc");
            }
            self::assertSafeTemplate($whereEtc);
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
    public static function assertValidTable(string $identifier): void
    {
        if (!preg_match('/^[\w-]+$/', $identifier)) {
            throw new InvalidArgumentException("Invalid table name '$identifier', allowed characters: a-z, A-Z, 0-9, _, -");
        }
    }

    /**
     * Reject numeric WHERE values - require array syntax instead.
     * Catches both int (legacy) and numeric strings, providing helpful migration guidance.
     * @throws InvalidArgumentException
     */
    public static function rejectNumericWhere(int|array|string $where): void
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
    public static function rejectLimitAndOffset(int|array|string $where): void
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
    public static function rejectEmptyWhere(array|string $where, string $operation): void
    {
        $isInvalid = match (true) {
            is_array($where)  => !$where,
            is_string($where) => !trim($where) || preg_match('/^\s*(ORDER|LIMIT|OFFSET|FOR)\b/i', $where),
        };

        if ($isInvalid) {
            throw new InvalidArgumentException("$operation requires a WHERE condition to prevent accidental bulk $operation");
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
     * Assert SQL template is safe - rejects quotes and standalone numbers.
     *
     * Forces developers to use placeholders instead of embedding values directly.
     * This catches accidental inclusion of user input in templates.
     *
     * @throws DBException
     */
    public static function assertSafeTemplate(string $sql): void
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

    //endregion
}
