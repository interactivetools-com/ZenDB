<?php
declare(strict_types=1);
namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArray;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use mysqli;
use RuntimeException;

/**
 * Query class for ZenDB
 *
 * Builds SQL queries with parameter support (named and positional).
 */
class Query
{
    private string $sqlTemplate;
    private mysqli $mysqli;
    private string $tablePrefix;

    /**
     * Represents a collection of SQL parameters for database queries, both named and positional.
     * All parameter keys start with ':', e.g. ':namedParam' or ':1' for positional parameters.
     *
     * @var array $paramMap Associative array of positional and named parameters, e.g., [':1' => 'Bob', ':name' => 'Tom']
     */
    private array $paramMap = [];

    //region Constructor

    /**
     * Create a new Parser instance.
     *
     * @param mysqli $mysqli The mysqli connection to use for escaping
     * @param string $tablePrefix The table prefix for :: placeholders (:_ is deprecated)
     */
    public function __construct(mysqli $mysqli, string $tablePrefix = '')
    {
        $this->mysqli      = $mysqli;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * For when you need to set the sqlTemplate after the parser has been created
     */
    public function setSqlTemplate(string $sqlTemplate): void
    {
        $this->sqlTemplate = rtrim($sqlTemplate); // trim blank space from "...query $whereEtc" with no where
    }

    //endregion
    //region Define Parameters

    /**
     * Adds parameters to the paramMap from variadic ...$params arguments passed to database query methods. e.g., query($sql, ...$params)
     *
     * Supports multiple positional params ($param1, $param2, $param3) or a single array of named
     * and positional parameters, allowing flexibility in how parameters are specified.
     *
     * Restrictions:
     *   - Named parameters cannot start with 'zdb_' (reserved prefix).
     *   - Up to three positional arguments unless provided within an array.
     *
     * @param array $methodParams Variadic arguments, which can be:
     *                            - A single array containing mixed named and positional parameters.
     *                            - Multiple arguments treated as positional parameters.
     *
     * Usage examples:
     *   - query($sql, 'Bob', 'Tom', 'Soccer');                           // Positional
     *   - query($sql, ['name' => 'Bob', 'age' => 45]);                   // Named
     *   - query($sql, ['Bob', 'Tom', 'Soccer', ':city' => 'Vancouver']); // Mixed
     *
     * @throws InvalidArgumentException If parameters do not meet expected criteria.
     */
    public function addParamsFromArgs(array $methodParams): void
    {
        $this->throwIfQueryFinalized();

        // error checking
        $isSingleArrayArg    = count($methodParams) === 1 && is_array($methodParams[0]);
        $allArgsAreNonArrays = empty(array_filter($methodParams, 'is_array'));
        $isCorrectFormat     = $isSingleArrayArg || $allArgsAreNonArrays;
        match (true) {
            !$isCorrectFormat        => throw new InvalidArgumentException("Param args must be either a single array or multiple non-array values"),
            count($methodParams) > 3 => throw new InvalidArgumentException("Max 3 positional arguments allowed.  If you need more pass an array instead"),
            !empty($this->paramMap)  => throw new InvalidArgumentException("Params already exist, be sure to call addParamsFromArgs() before setting params"),
            default                  => null,
        };

        // add params
        $inputParams = $isSingleArrayArg ? $methodParams[0] : $methodParams;
        foreach ($inputParams as $indexOrName => $value) {
            match (true) {
                is_int($indexOrName) => $this->addPositionalParam($value),  // positional params are received as [0 => 'Bob', 1 => 'Tom']
                default              => $this->addNamedParam($indexOrName, $value),
            };
        }
    }

    /**
     * Adds a positional parameter, automatically assigning it a name with next available number (e.g., ':1', ':2')
     * that comes after the highest existing number. Supports various value types, including DB::rawSql() for raw SQL.
     *
     * @param string|int|float|bool|null|RawSql $value The value of the positional parameter to add.
     */
    public function addPositionalParam(string|int|float|bool|null|RawSql|SmartArrayHtml|SmartString|SmartNull $value): void
    {
        // error checking
        match (true) {
            $value instanceof SmartArray => throw new InvalidArgumentException("Positional parameters cannot be SmartArray\n" . DB::occurredInFile()),
            default                      => null,
        };
        // get unused number of highest positional parameter
        $positionalNums = preg_filter('/^:(\d+)$/', '$1', array_keys($this->paramMap));  // extracts numbers from keys matching pattern
        $nextUnusedNum  = $positionalNums ? max($positionalNums) + 1 : 1;                // get next number to use

        // add parameter
        $name = ":$nextUnusedNum";
        $value = $value instanceof SmartString ? $value->value() : $value;
        $this->addParam($name, $value);
    }

    /**
     * Adds a named SQL parameter. Ensures parameter names are unique, start with ':', and conform to naming rules.
     * Rejects names starting with 'zdb_' (reserved for internal use). Supports various value types, including DB::rawSql() for raw SQL.
     *
     * @param string                            $name  The parameter name, starting with ':'.
     * @param string|int|float|bool|null|RawSql $value The value to be bound to the named parameter.
     *
     * @throws InvalidArgumentException For invalid or duplicate parameter names.
     */
    public function addNamedParam(string $name, string|int|float|bool|null|RawSql|SmartString|SmartNull $value): void
    {

        // error checking
        match (true) {
            !preg_match("/^:\w+$/", $name)  => throw new InvalidArgumentException("Invalid key name '$name'. Must start with ':' followed by (a-z, A-Z, 0-9, _)"),
            str_starts_with($name, ':zdb_') => throw new InvalidArgumentException("Invalid key name '$name'.  Named keys can't start with zdb_ (internal prefix)"),
            default                         => null,
        };

        // add parameter
        $this->addParam($name, $value);
    }

    public function addInternalParam(string $name, string|int|float|bool|null|RawSql|SmartString $value): void
    {
        // error checking
        if (!preg_match("/^:zdb_\w+$/", $name)) {
            throw new InvalidArgumentException("Invalid key name '$name'. Must start with ':zdb_' followed by (a-z, A-Z, 0-9, _)");
        }

        // add parameter
        $this->addParam($name, $value);
    }

    /**
     * @noinspection PhpDuplicateMatchArmBodyInspection
     */
    private function addParam(string $name, string|int|float|bool|null|RawSql|SmartString|SmartNull $value): void
    {
        $this->throwIfQueryFinalized();

        // name error checking
        match (true) {
            !str_starts_with($name, ':')             => throw new InvalidArgumentException("Invalid key name '$name'. Must start with ':'"),
            array_key_exists($name, $this->paramMap) => throw new InvalidArgumentException("Duplicate key name '$name'"),
            default                                  => null,
        };

        // set value
        match (get_debug_type($value)) {
            RawSql::class      => $this->paramMap[$name] = $value,            // maintain RawSql objects so their values get passed unquoted later
            SmartString::class => $this->paramMap[$name] = $value->value(),   // convert Field objects to their values
            SmartNull::class   => $this->paramMap[$name] = $value->value(),   // convert Field objects to their values
            default            => $this->paramMap[$name] = $value,
        };
    }

    //endregion
    //region Query Generation

    /**
     * Return a SQL query with values escaped with mysqli_real_escape_string()
     * For queries that don't support prepared statements, e.g., SHOW, etc. (non-DML queries)
     * @throws DBException
     */
    public function getEscapedQuery(): string
    {
        $this->isQueryFinalized = true; // prevent further changes to query

        $escapedQuery = $this->replacePlaceholders();

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
    private function replacePlaceholders(): string
    {
        // Normalize :_ to :: (deprecated syntax)
        $template = str_replace(':_', '::', $this->sqlTemplate);

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
        if (!array_key_exists($paramKey, $this->paramMap)) {
            throw new DBException(
                $isPositional
                    ? "Missing value for ? parameter at position $positionalCount"
                    : "Missing value for '$paramKey' parameter",
            );
        }

        $value = $this->paramMap[$paramKey];
        return $addTablePrefix ? $this->tablePrefix . $value : $value;
    }

    //endregion
    //region Utilities

    private bool $isQueryFinalized = false;

    private function throwIfQueryFinalized(): void
    {
        if ($this->isQueryFinalized) {
            throw new RuntimeException("Query has already been finalized.  Cannot add more parameters or modify query");
        }
    }

    public function getSqlTemplate(): string
    {
        return $this->sqlTemplate;
    }

    //endregion
}
