<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use BadMethodCallException;
use InvalidArgumentException;
use mysqli_result;
use mysqli_stmt;

/**
 * Class MysqliResultPolyfill
 *
 * Polyfills mysqli_stmt::get_result() for PHP 8.1 systems without mysqlnd.
 * The get_result() method doesn't exist at all without mysqlnd, so this class
 * emulates it using the older bind_result()/fetch() pattern.
 *
 * NOTE: This is only used on PHP 8.1 without mysqlnd (a rare edge case).
 * On PHP 8.2+, mysqlnd is mandatory, so this polyfill is never triggered.
 *
 * Extends mysqli_result for type compatibility (instanceof checks).
 */
class MysqliResultPolyfill extends mysqli_result
{
    private mysqli_stmt         $stmt;
    private mysqli_result|false $meta;
    private array               $fieldObjects;

    /**
     * Returns object that emulates mysqli_result.
     *
     * @param mysqli_stmt $stmt The mysqli statement from which results are to be fetched.
     * @noinspection PhpMissingParentConstructorInspection - Intentionally not calling parent; this polyfill emulates mysqli_result without a real connection
     */
    public function __construct(mysqli_stmt $stmt)
    {
        $this->stmt         = $stmt;
        $this->meta         = $stmt->result_metadata();
        $this->fieldObjects = $this->meta ? $this->meta->fetch_fields() : [];

        // store results so row count can be determined
        $stmt->store_result();
    }

    /**
     * Returns an array of objects representing the fields in a result set
     */
    public function fetch_fields(): array
    {
        return $this->fieldObjects;
    }

    /**
     * Fetch the next row of a result set as an associative, a numeric array, or both
     */
    public function fetch_array(int $mode = MYSQLI_BOTH): array|null|false
    {
        // If there are no fields, return null
        if (!$this->fieldObjects) {
            return null;
        }

        // Prepare an array to hold the row values
        $row = [];
        // Prepare an array to hold references to the row values
        $params = [];
        foreach ($this->fieldObjects as $column) {
            // Each element in $params is a reference to the corresponding element in $row
            $params[] = &$row[$column->name];
        }

        // Dynamically bind the columns to the $row array elements
        $this->stmt->bind_result(...$params);

        if ($this->stmt->fetch()) {
            $result       = [];
            $addNumeric   = $mode & MYSQLI_NUM;
            $addNamed     = $mode & MYSQLI_ASSOC;
            $numericIndex = 0;

            // Build result with interleaved keys to match native mysqli_result behavior
            foreach ($row as $key => $val) {
                if ($addNumeric) {
                    $result[$numericIndex++] = $val;
                }
                if ($addNamed) {
                    $result[$key] = $val;
                }
            }

            return $result;
        }

        return null; // No more rows
    }

    /**
     * Fetch the next row of a result set as an associative array
     */
    public function fetch_assoc(): array|null|false
    {
        return $this->fetch_array(MYSQLI_ASSOC);
    }

    /**
     * Fetch the next row of a result set as a numeric array
     */
    public function fetch_row(): array|null|false
    {
        return $this->fetch_array(MYSQLI_NUM);
    }

    /**
     * Fetch all rows as an array
     */
    public function fetch_all(int $mode = MYSQLI_NUM): array
    {
        $rows = [];
        while ($row = $this->fetch_array($mode)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Fetch the next row as an object
     */
    public function fetch_object(?string $class = "stdClass", array $constructor_args = []): object|null|false
    {
        $row = $this->fetch_assoc();
        if ($row === null || $row === false) {
            return $row;
        }

        if ($class === "stdClass") {
            return (object) $row;
        }

        // For custom classes, create instance and set properties
        $obj = new $class(...$constructor_args);
        foreach ($row as $key => $value) {
            $obj->$key = $value;
        }
        return $obj;
    }

    /**
     * Frees the memory associated with a result
     */
    public function free(): void
    {
        if ($this->meta) {
            $this->meta->free();
        }
    }

    /**
     * Emulate properties
     * @throws InvalidArgumentException
     */
    public function __get(string $name)
    {
        return match ($name) {
            'field_count' => $this->stmt->field_count,
            'num_rows'    => $this->stmt->num_rows,
            default       => throw new InvalidArgumentException("Property $name is not accessible or does not exist."),
        };
    }

    /**
     * Throw exception for unimplemented methods
     * @throws BadMethodCallException
     */
    public function __call($name, $arguments)
    {
        throw new BadMethodCallException("Mysqlnd isn't installed and $name() is not implemented in polyfill.");
    }
}
