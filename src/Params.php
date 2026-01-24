<?php

declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayBase;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;

/**
 * Params - SQL parameter collection for ZenDB queries.
 *
 * Manages named and positional parameters for database queries.
 * All parameter keys start with ':', e.g. ':namedParam' or ':1' for positional parameters.
 */
class Params
{
    //region Data

    /**
     * Parameter values keyed by name, e.g., [':1' => 'Bob', ':name' => 'Tom']
     */
    public array $values = [];

    //endregion
    //region Add Methods

    /**
     * Adds parameters from variadic ...$params arguments passed to database query methods.
     *
     * Supports multiple positional params ($param1, $param2, $param3) or a single array of named
     * and positional parameters, allowing flexibility in how parameters are specified.
     *
     * Restrictions:
     *   - Named parameters cannot start with 'zdb_' (reserved prefix).
     *   - Up to three positional arguments unless provided within an array.
     *
     * @param array $args Variadic arguments, which can be:
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
    public function addFromArgs(array $args): void
    {
        // error checking
        $passedAsArray  = count($args) === 1 && is_array($args[0]);     // query($sql, ['a', 'b', 'c'])
        $passedAsValues = empty(array_filter($args, 'is_array'));       // query($sql, 'a', 'b', 'c')
        $isValidFormat  = $passedAsArray || $passedAsValues;
        match (true) {
            !$isValidFormat       => throw new InvalidArgumentException("Param args must be either a single array or multiple non-array values"),
            count($args) > 3      => throw new InvalidArgumentException("Max 3 positional arguments allowed.  If you need more pass an array instead"),
            !empty($this->values) => throw new InvalidArgumentException("Params already exist, be sure to call addFromArgs() before setting params"),
            default               => null,
        };

        // Add params based on key type:
        //  - Positional: query($sql, 'Bob', 'Tom')       → [0 => 'Bob', 1 => 'Tom'] → :1, :2
        //  - Named:      query($sql, [':name' => 'Bob']) → [':name' => 'Bob']       → :name
        $inputParams = $passedAsArray ? $args[0] : $args;
        foreach ($inputParams as $key => $value) {
            if (is_int($key)) {
                $this->addPositional($value);
            } else {
                $this->addNamed($key, $value);
            }
        }
    }

    /**
     * Adds a positional parameter, automatically assigning it the next available number (e.g., ':1', ':2').
     */
    public function addPositional(string|int|float|bool|null|RawSql|SmartArrayBase|SmartString|SmartNull $value): void
    {
        $name = ":" . $this->nextPositionalNum++;
        $this->add($name, $value);
    }

    /**
     * Adds a named SQL parameter. Ensures parameter names are unique, start with ':', and conform to naming rules.
     * Rejects names starting with 'zdb_' (reserved for internal use).
     *
     * @param string                                                                 $name The parameter name, starting with ':'.
     * @param string|int|float|bool|null|RawSql|SmartArrayBase|SmartString|SmartNull $value
     *
     * @throws InvalidArgumentException For invalid or duplicate parameter names.
     */
    public function addNamed(string $name, string|int|float|bool|null|RawSql|SmartArrayBase|SmartString|SmartNull $value): void
    {
        // error checking
        match (true) {
            !preg_match("/^:\w+$/", $name)  => throw new InvalidArgumentException("Invalid key name '$name'. Must start with ':' followed by (a-z, A-Z, 0-9, _)"),
            str_starts_with($name, ':zdb_') => throw new InvalidArgumentException("Invalid key name '$name'.  Named keys can't start with zdb_ (internal prefix)"),
            default                         => null,
        };

        // add parameter
        $this->add($name, $value);
    }

    /**
     * Adds an internal parameter (reserved for ZenDB internals).
     *
     * @param string                                        $name Must start with ':zdb_'
     * @param string|int|float|bool|null|RawSql|SmartString $value
     */
    public function addInternal(string $name, string|int|float|bool|null|RawSql|SmartString $value): void
    {
        // error checking
        if (!preg_match("/^:zdb_\w+$/", $name)) {
            throw new InvalidArgumentException("Invalid key name '$name'. Must start with ':zdb_' followed by (a-z, A-Z, 0-9, _)");
        }

        // add parameter
        $this->add($name, $value);
    }

    //endregion
    //region Internals

    private int $nextPositionalNum = 1;

    /**
     * Internal method to add a parameter to the values array.
     */
    private function add(string $name, string|int|float|bool|null|RawSql|SmartArrayBase|SmartString|SmartNull $value): void
    {
        // error checking
        match (true) {
            !str_starts_with($name, ':')           => throw new InvalidArgumentException("Invalid key name '$name'. Must start with ':'"),
            array_key_exists($name, $this->values) => throw new InvalidArgumentException("Duplicate key name '$name'"),
            default                                => null,
        };

        // set value (RawSql kept as-is, SmartString/SmartNull unwrapped)
        $this->values[$name] = match (true) {
            !is_object($value)            => $value,            // string, int, float, bool, null
            $value instanceof RawSql      => $value,            // pass through RawSql objects as-is
            $value instanceof SmartString => $value->value(),
            $value instanceof SmartNull   => null,
            default                       => throw new InvalidArgumentException("Parameters cannot be " . get_debug_type($value) . "\n" . DB::occurredInFile()),  // e.g., SmartArray
        };
    }

    //endregion
}
