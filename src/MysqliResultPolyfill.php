<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use BadMethodCallException;
use mysqli_result;
use mysqli_stmt;
use ValueError;

/**
 * Class MysqliResultPolyfill
 *
 * Emulates the mysqli_result returned by mysqli_stmt::get_result() for PHP 8.1 systems
 * without mysqlnd, where get_result() doesn't exist. Rows come from the classic
 * bind_result()/fetch() pattern; num_rows and field_count are real properties populated
 * from the buffered statement.
 *
 * Standalone class, not a mysqli_result subclass: mysqli_result's num_rows/field_count
 * properties are read-only at the C level and throw on an object with no underlying
 * result, so a subclass can't emulate them. On PHP 8.1 without mysqlnd, code receiving
 * results from prepare()->get_result() or execute_query() must not type-hint or
 * instanceof-check mysqli_result.
 *
 * TODO-PHP82: Delete this class. From PHP 8.2 mysqli always builds with mysqlnd, so get_result() is always native.
 */
class MysqliResultPolyfill
{
    public int $num_rows;
    public int $field_count;

    private mysqli_stmt         $stmt;
    private mysqli_result|false $meta;
    private array               $fieldObjects;

    /**
     * Returns object that emulates mysqli_result.
     *
     * @param mysqli_stmt $stmt The mysqli statement from which results are to be fetched.
     */
    public function __construct(mysqli_stmt $stmt)
    {
        $this->stmt         = $stmt;
        $this->meta         = $stmt->result_metadata();
        $this->fieldObjects = $this->meta ? $this->meta->fetch_fields() : [];

        // Buffer rows client-side, matching native get_result(); makes num_rows and data_seek() valid
        $stmt->store_result();
        $this->num_rows    = (int)$stmt->num_rows;
        $this->field_count = $stmt->field_count;
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
        // Match native mysqli, which validates the mode before touching the result
        if (!in_array($mode, [MYSQLI_NUM, MYSQLI_ASSOC, MYSQLI_BOTH], true)) {
            throw new ValueError('mysqli_result::fetch_array(): Argument #1 ($mode) must be one of MYSQLI_NUM, MYSQLI_ASSOC, or MYSQLI_BOTH');
        }

        // If there are no fields, return null
        if (!$this->fieldObjects) {
            return null;
        }

        // Bind by position, one slot per column. A JOIN can select two columns with the
        // same name (SELECT a.id, b.id), so name-keyed slots would collapse them and drop
        // a column. Positional slots keep every column; we build the keyed row afterward.
        // Unpacking into bind_result()'s by-reference variadic binds the slots directly.
        $values = array_fill(0, count($this->fieldObjects), null);
        $this->stmt->bind_result(...$values);

        if (!$this->stmt->fetch()) {
            return null; // No more rows
        }

        $result     = [];
        $addNumeric = $mode & MYSQLI_NUM;
        $addNamed   = $mode & MYSQLI_ASSOC;
        foreach ($this->fieldObjects as $i => $column) {
            if ($addNumeric) {
                $result[$i] = $values[$i];
            }
            if ($addNamed) {
                // Duplicate names are last-wins, matching native mysqli_result
                $result[$column->name] = $values[$i];
            }
        }

        return $result;
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
            return (object)$row;
        }

        // For custom classes, create instance and set properties
        $obj = new $class(...$constructor_args);
        foreach ($row as $key => $value) {
            $obj->$key = $value;
        }
        return $obj;
    }

    /**
     * Adjust the result pointer to an arbitrary row, like native mysqli_result::data_seek()
     */
    public function data_seek(int $offset): bool
    {
        if ($offset < 0) {
            throw new ValueError('mysqli_result::data_seek(): Argument #1 ($offset) must be greater than or equal to 0');
        }
        if ($offset >= $this->num_rows) {
            return false;
        }
        $this->stmt->data_seek($offset);
        return true;
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
     * Throw exception for unimplemented methods
     * @throws BadMethodCallException
     */
    public function __call(string $name, array $arguments): never
    {
        throw new BadMethodCallException("Mysqlnd isn't installed and $name() is not implemented in polyfill.");
    }
}
