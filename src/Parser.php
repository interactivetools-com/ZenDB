<?php
declare(strict_types=1);
namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArray;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use RuntimeException;

/**
 * Parser class for ZenDB
 *
 * Manages SQL parameters for database queries, supporting both named and positional parameters.
 */
class Parser
{
    private string $sqlTemplate;

    /**
     * Represents a collection of SQL parameters for database queries, both named and positional.
     * All parameter keys start with ':', e.g. ':namedParam' or ':1' for positional parameters.
     *
     * @var array $paramMap Associative array of positional and named parameters, e.g., [':1' => 'Bob', ':name' => 'Tom']
     */
    private array $paramMap = [];

    private string $paramQuery;
    private array  $bindValues;


    #region Constructor

    /**
     * For when you need to set the sqlTemplate after the parser has been created
     *
     * @param string $sqlTemplate
     * @return void
     */
    public function setSqlTemplate(string $sqlTemplate): void
    {
        $this->sqlTemplate = rtrim($sqlTemplate); // trim blank space from "...query $whereEtc" with no where
    }

    #endregion
    #region Define Parameters

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
    public function addPositionalParam(string|int|float|bool|null|RawSql|SmartArray|SmartString|SmartNull $value): void
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
    public function addNamedParam(string $name, string|int|float|bool|null|RawSql|SmartString $value): void
    {

        // error checking
        match (true) {
            !preg_match("/^:\w+$/", $name)  => throw new InvalidArgumentException("Invalid key name '$name'. Must start with ':' followed by (a-z, A-Z, 0-9, _)"),
            str_starts_with($name, ':zdb_') => throw new InvalidArgumentException("Invalid key name '$name'.  Named keys can't start with zdb_ (internal prefix)"),
            default                         => null,
        };

        // add parameter
        $value = $value instanceof SmartString ? $value->value() : $value;
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
     * @param string                                                              $name
     * @param string|int|float|bool|RawSql|SmartString|null $value
     *
     * @return void
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

    #endregion
    #region Generate Queries

    /**
     * Return a SQL query with values escaped with mysqli_real_escape_string()
     * For queries that don't support prepared statements, e.g., SHOW, etc. (non-DML queries)
     *
     * @return string
     * @throws DBException
     */
    public function getEscapedQuery(): string
    {
        $this->isQueryFinalized = true; // prevent further changes to query

        // replace :named and positional ("?") parameters with mysql escaped values
        $positionalCount = 0; // Index to keep track of positional parameters
        $escapedQuery    = preg_replace_callback(
            pattern: $this->getPlaceholderRegexp(),  // match `?`, ?, :name, `:name`
            callback: function ($matches) use (&$positionalCount) {
                $matchedString    = $matches[0];
                $value            = $this->getPlaceholderValue($matchedString, $positionalCount);
                $valueType        = get_debug_type($value);
                $isSafeValueType  = in_array($valueType, ['int', 'float', 'bool', 'null']); // safe value types
                return match (true) {
                    DB::isRawSql($value)              => (string) $value,                                   // DB::rawSql("...") values are passed unquoted
                    str_contains($matchedString, '`') => self::replaceParamInBackticks($value),             // Return backtick-quoted values (already sanitized)
                    $isSafeValueType                  => $value,                                            // Return safe value types as is
                    is_string($value)                 => '"' .DB::$mysqli->real_escape_string($value). '"',  // Quote and escape string values
                    default                           => throw new InvalidArgumentException("Invalid type for $matchedString: $value"),
                };
            },
            subject: $this->sqlTemplate,
        );

        // ltrim each line for a multiline string (for better formatting in logs and debug output)
        $escapedQuery = preg_replace('/^ +/m', '', $escapedQuery);

        return $escapedQuery;
    }

    /**
     * get parameterized query (positional only) and bind values from sqlTemplate and paramMap
     * converts named parameters to positional parameters and builds bindValues array
     * @throws DBException
     */
    public function getParamQuery(): string
    {
        // return cached values if already generated
        if (isset($this->paramQuery)) {
            return $this->paramQuery;
        }

        // replace :named parameters with positional ("?") parameters and build bindValues array
        $positionalCount = 0;           // Index to keep track of positional parameters
        $bindValues      = [];
        $preparedQuery   = preg_replace_callback(
            pattern: $this->getPlaceholderRegexp(),  // match `?`, ?, :name, `:name`
            callback: function ($matches) use (&$positionalCount, &$bindValues) {
                $matchedString    = $matches[0];
                $inBackticks      = str_starts_with($matchedString, '`') && str_ends_with($matchedString, '`');
                $replacementValue = $this->getPlaceholderValue($matchedString, $positionalCount);

                return match (true) {
                    $inBackticks => self::replaceParamInBackticks($replacementValue),
                    default      => self::replaceParam($replacementValue, $bindValues),
                };
            },
            subject: $this->sqlTemplate,
        );

        // store values
        $this->isQueryFinalized = true; // prevent further changes to query
        $this->paramQuery       = $preparedQuery;
        $this->bindValues       = $bindValues;

        // return prepared query
        return $this->paramQuery;
    }

    /**
     * @throws DBException
     */
    public function getBindValues(): array
    {
        // call getParamQuery() if needed to generate bindValues
        if (!isset($this->bindValues)) {
            $this->getParamQuery();
        }
        return $this->bindValues;
    }

    #endregion
    #region Generate Query Internals

    /**
     * @return string
     */
    private function getPlaceholderRegexp(): string
    {
        $placeholderRx = implode("|", [
            "\?",                 // match ?
            "`\?`",               // match `?`
            "`:_\?`",             // match `:_?`
            "`::\?`",             // match `::?`
            ":[a-zA-Z]\w*\b",     // match :name
            "`:[a-zA-Z]\w*\b`",   // match `:name`
            "`:_:[a-zA-Z]\w*\b`", // match `:_:name`
            "`:::[a-zA-Z]\w*\b`", // match `:::name`
            ":_",                 // match :_
            "::",                 // match ::
        ]);
        return "/$placeholderRx/";
    }

    /**
     * @param $matchedString
     * @param $positionalCount
     *
     * @return string|int|float|bool|RawSql|null
     * @throws DBException
     */
    private function getPlaceholderValue($matchedString, &$positionalCount): string|int|float|bool|null|RawSql {

        $addTablePrefix = str_starts_with($matchedString, "`:_") || str_starts_with($matchedString, "`::"); // only supported for backtick placeholders
        $placeholder    = trim($matchedString, '`'); // unwrap backticks
        $placeholder    = preg_replace("/^(:_|::)/", "", $placeholder); // remove table prefix placeholder
        $isPositional   = ($placeholder === '?');

        // handle special case for :_ and :: placeholders
        if ($matchedString === ':_' || $matchedString === '::') {
            return DB::rawSql(DB::$tablePrefix); // return table prefix as a RawSql object so it's not quoted
        }

        // get placeholder value
        $mapKey = $isPositional ? ':'.++$positionalCount : $placeholder; // e.g., :1 or :name
        if (!array_key_exists($mapKey, $this->paramMap)) {
            match (true) {
                $isPositional => throw new DBException("Missing value for ? parameter at position $positionalCount"),
                default       => throw new DBException("Missing value for '$mapKey' parameter"),
            };
        }
        $value = $this->paramMap[$mapKey];

        // params in backticks, add table prefix if needed
        if ($addTablePrefix) {
            $value = DB::$tablePrefix . $value;
        }

        // return value
        return $value;
    }

    /**
     * @param $value
     * @param $bindValues
     *
     * @return string
     */
    private static function replaceParam($value, &$bindValues): string
    {
        // Inject raw unquoted sql values.  e.g., DB::rawSql("NOW()") becomes NOW()
        if (DB::isRawSql($value)) {
            return (string)$value;
        }

        // Add value to bindValues
        $bindValues[] = $value;

        // return placeholder
        return "?";                                                      // replace all placeholders with positional placeholders
    }

    /**
     * `backtick_placeholders` Special case to allow inserting identifiers (table names, column names, etc.).
     * Instead of "quoting \& escaping" values or passing them as parameters, we check they're safe and allow them to be inserted
     *
     * PHP's MySQLi and PDO prepared statements are limited to DML queries (INSERT, UPDATE, DELETE, SELECT) and only support data value parameters.
     * They don't support placeholders for database objects like tables or columns due to the need for a pre-defined query structure.  For this
     * reason we need to escape and surround identifiers with backticks manually.
     *
     * @param $replacementValue
     *
     * @return string
     * @throws DBException
     */
    private static function replaceParamInBackticks($replacementValue): string
    {
        if (is_string($replacementValue) && !preg_match('/[^a-zA-Z0-9_-]/', $replacementValue)) {
            // we can assume $value is safe at this point as it only contains letters, numbers, and underscores
            return "`$replacementValue`";
        }

        // throw exception if $value contains invalid characters
        $replacementExport = is_scalar($replacementValue) ? "`$replacementValue`" : var_export($replacementValue, true);
        throw new DBException("Invalid identifier $replacementExport.  Placeholders in backticks can only contain letters, numbers, dashes and underscores.");
    }

    #endregion
    #region Utilities

    private bool $isQueryFinalized = false;

    private function throwIfQueryFinalized(): void
    {
        if ($this->isQueryFinalized) {
            throw new RuntimeException("Query has already been finalized.  Cannot add more parameters or modify query");
        }
    }

    /**
     * Emulate prepared statements for non-DML (Data Manipulation Language) queries
     *
     * @return bool
     */
    public function isDmlQuery(): bool
    {
        return (bool) preg_match("/^\s*(INSERT|UPDATE|DELETE|SELECT)\b/i", $this->sqlTemplate);
    }

    /**
     * @return string
     */
    public function getSqlTemplate(): string
    {
        return $this->sqlTemplate;
    }

    #endregion
}
