<?php
declare(strict_types=1);

namespace Itools\ZenDB\Internal;

use Exception, mysqli_stmt, mysqli_result;
use RuntimeException;

/**
 * Class MysqliStmtResultEmulator
 * Emulates a mysqli_result object for systems that don't have mysqlnd and therefore don't have mysqli_stmt::get_result().
 * This class fetches data directly from mysqli_stmt.
 */
class MysqliStmtResultEmulator
{
    private mysqli_stmt          $stmt;
    private mysqli_result|false $meta;
    private array                $fieldObjects;

    /**
     * Returns object that emulates mysqli_result.
     *
     * @param mysqli_stmt $stmt The mysqli statement from which results are to be fetched.
     */
    public function __construct(mysqli_stmt $stmt) {
        $this->stmt         = $stmt;
        $this->meta         = $stmt->result_metadata();
        $this->fieldObjects = $this->meta ? $this->meta->fetch_fields() : [];

        // store results so row count can be determined
        $stmt->store_result();
    }

    /**
     * Returns an array of objects representing the fields in a result set
     */
    public function fetch_fields(): array {
        return $this->fieldObjects;
    }

    /**
     * Fetch the next row of a result set as an associative, a numeric array, or both
     * @throws Exception
     */
    public function fetch_array(int $mode = MYSQLI_BOTH): array|null|false {
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
        $bindResult = $this->stmt->bind_result(...$params);
        if (!$bindResult) {
            throw new RuntimeException("Failed to bind result");
        }

        if ($this->stmt->fetch()) {
            $result = [];
            foreach ($row as $key => $val) {
                $addNumericKey = $mode & MYSQLI_NUM;
                $assNamedKey   = $mode & MYSQLI_ASSOC;

                if ($addNumericKey) {
                    $result[] = $val;
                }
                if ($assNamedKey) {
                    $result[$key] = $val;
                }
            }

            return $result;
        }

        return null; // No more rows
    }

    /**
     * Fetch the next row of a result set as an associative array
     * @throws Exception
     */
    public function fetch_assoc(): array|null|false {
        return $this->fetch_array(MYSQLI_ASSOC);
    }

    /**
     * Fetch the next row of a result set as a numeric array
     * @throws Exception
     */
    public function fetch_row(): array|null|false {
        return $this->fetch_array(MYSQLI_NUM);
    }

    /**
     * Frees the memory associated with a result
     */
    public function free(): void {
        $this->meta->free();  // Free the metadata
    }

    /**
     * Emulate properties
     * @throws Exception
     */
    public function __get(string $name) {
        return match ($name) {
            'field_count' => $this->stmt->field_count,
            'num_rows'    => $this->stmt->num_rows,
            default => throw new Exception("Property $name is not accessible or does not exist."),
        };
    }

    /**
     * Throw exception for unimplemented methods
     * @throws Exception
     */
    public function __call($name, $arguments) {
        throw new RuntimeException("Mysqlnd isn't installed and $name() is not implemented in polyfill.");
    }
}
