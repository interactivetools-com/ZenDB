<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArray;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;

/**
 * Params class for ZenDB
 *
 * Manages SQL parameters for database queries, supporting both named and positional parameters.
 */
class Params
{
    /**
     * Represents a collection of SQL parameters for database queries, both named and positional.
     * All parameter keys start with ':', e.g. ':namedParam' or ':1' for positional parameters.
     *
     * @var array $paramMap Associative array of positional and named parameters, e.g., [':1' => 'Bob', ':name' => 'Tom']
     */
    public array $paramMap = [];

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
     * @return self Returns $this for method chaining
     *
     * Usage examples:
     *   - query($sql, 'Bob', 'Tom', 'Soccer');                           // Positional
     *   - query($sql, ['name' => 'Bob', 'age' => 45]);                   // Named
     *   - query($sql, ['Bob', 'Tom', 'Soccer', ':city' => 'Vancouver']); // Mixed
     *
     * @throws InvalidArgumentException If parameters do not meet expected criteria.
     */
    public function addParamsFromArgs(array $methodParams): self
    {
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

        return $this;
    }

    /**
     * Adds a positional parameter, automatically assigning it a name with next available number (e.g., ':1', ':2')
     * that comes after the highest existing number. Supports various value types, including RawSql object for raw SQL.
     *
     * @param string|int|float|bool|null|RawSql $value The value of the positional parameter to add.
     * @return self Returns $this for method chaining
     */
    public function addPositionalParam(string|int|float|bool|null|RawSql|SmartArray|SmartString|SmartNull $value): self
    {
        // error checking
        match (true) {
            $value instanceof SmartArray => throw new InvalidArgumentException("Positional parameters cannot be SmartArray"),
            default                      => null,
        };
        // get unused number of highest positional parameter
        $positionalNums = preg_filter('/^:(\d+)$/', '$1', array_keys($this->paramMap));  // extracts numbers from keys matching pattern
        $nextUnusedNum  = $positionalNums ? max($positionalNums) + 1 : 1;                // get next number to use

        // add parameter
        $name  = ":$nextUnusedNum";
        $value = $value instanceof SmartString ? $value->value() : $value;
        $this->addParam($name, $value);

        return $this;
    }

    /**
     * Adds a named SQL parameter. Ensures parameter names are unique, start with ':', and conform to naming rules.
     * Rejects names starting with 'zdb_' (reserved for internal use). Supports various value types, including RawSql object for raw SQL.
     *
     * @param string $name The parameter name, starting with ':'.
     * @param string|int|float|bool|null|RawSql $value The value to be bound to the named parameter.
     * @return self Returns $this for method chaining
     *
     * @throws InvalidArgumentException For invalid or duplicate parameter names.
     */
    public function addNamedParam(string $name, string|int|float|bool|null|RawSql|SmartString $value): self
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

        return $this;
    }

    /**
     * Adds an internal parameter with the reserved 'zdb_' prefix.
     *
     * @param string $name The parameter name, starting with ':zdb_'.
     * @param string|int|float|bool|null|RawSql|SmartString $value The value to be bound to the parameter.
     * @return self Returns $this for method chaining
     * @throws InvalidArgumentException For invalid parameter names.
     */
    public function addInternalParam(string $name, string|int|float|bool|null|RawSql|SmartString $value): self
    {
        // error checking
        if (!preg_match("/^:zdb_\w+$/", $name)) {
            throw new InvalidArgumentException("Invalid key name '$name'. Must start with ':zdb_' followed by (a-z, A-Z, 0-9, _)");
        }

        // add parameter
        $this->addParam($name, $value);

        return $this;
    }

    /**
     * Private helper to add a parameter to the paramMap.
     *
     * @param string $name
     * @param string|int|float|bool|RawSql|SmartString|null $value
     *
     * @return void
     * @noinspection PhpDuplicateMatchArmBodyInspection
     */
    private function addParam(string $name, string|int|float|bool|null|RawSql|SmartString|SmartNull $value): void
    {
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

    /**
     * Finalizes the query - does nothing in the base class
     *
     * @return self Returns $this for method chaining
     */
    public function finalizeQuery(): self
    {
        return $this;
    }
}
