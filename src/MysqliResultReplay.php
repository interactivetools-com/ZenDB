<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use ValueError;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function array_map, count, in_array;
use const MYSQLI_ASSOC, MYSQLI_BOTH, MYSQLI_NUM;

/**
 * Class MysqliResultReplay
 *
 * The result-set half of MysqliWrapperReplay: serves rows from PHP arrays with the
 * same fetch API as mysqli_result, so ZenDB's result handling runs unchanged with
 * no server behind it. Not a mysqli_result subclass - a real one can't exist
 * without a live result, and its C-level properties (field_count, num_rows) can't
 * be populated from PHP - so ZenDB accepts both types side by side.
 *
 *     $result = new MysqliResultReplay(
 *         fields: [['name' => 'id'], ['name' => 'title']],
 *         rows:   [[1, 'First'], [2, 'Second']],   // positional, one entry per field
 *     );
 *     $result->fetch_all(MYSQLI_ASSOC);  // [['id' => 1, 'title' => 'First'], ...]
 *
 * Field descriptors accept every property mysqli's fetch_fields() reports
 * (orgname, table, orgtable, type, flags, ...); only 'name' is required for
 * plain fetches, while ZenDB's smart joins and encryption detection read the
 * others when present.
 */
class MysqliResultReplay
{
    public int $field_count;
    public int $num_rows;

    /** @var object[] Field descriptors, as fetch_fields() returns them */
    private array $fields;

    /** @var array[] Positional row arrays */
    private array $rows;

    private int $cursor = 0;

    /**
     * @param array $fields List of field descriptors (arrays or objects), one per column
     * @param array $rows   List of positional row arrays, one value per field
     */
    public function __construct(array $fields, array $rows)
    {
        $this->fields      = array_map(static fn($field): object => (object)$field, $fields);
        $this->rows        = $rows;
        $this->field_count = count($this->fields);
        $this->num_rows    = count($rows);
    }

    public function fetch_fields(): array
    {
        return $this->fields;
    }

    public function fetch_array(int $mode = MYSQLI_BOTH): array|null|false
    {
        // Match native mysqli, which validates the mode before touching the result
        if (!in_array($mode, [MYSQLI_NUM, MYSQLI_ASSOC, MYSQLI_BOTH], true)) {
            throw new ValueError('mysqli_result::fetch_array(): Argument #1 ($mode) must be one of MYSQLI_NUM, MYSQLI_ASSOC, or MYSQLI_BOTH');
        }

        $values = $this->rows[$this->cursor] ?? null;
        if ($values === null) {
            return null; // No more rows
        }
        $this->cursor++;

        $result     = [];
        $addNumeric = $mode & MYSQLI_NUM;
        $addNamed   = $mode & MYSQLI_ASSOC;
        foreach ($this->fields as $i => $column) {
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

    public function fetch_assoc(): array|null|false
    {
        return $this->fetch_array(MYSQLI_ASSOC);
    }

    public function fetch_row(): array|null|false
    {
        return $this->fetch_array(MYSQLI_NUM);
    }

    public function fetch_all(int $mode = MYSQLI_NUM): array
    {
        $rows = [];
        while ($row = $this->fetch_array($mode)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function fetch_object(?string $class = "stdClass", array $constructor_args = []): object|null|false
    {
        $row = $this->fetch_assoc();
        if ($row === null || $row === false) {
            return $row;
        }

        if ($class === "stdClass") {
            return (object)$row;
        }

        $obj = new $class(...$constructor_args);
        foreach ($row as $key => $value) {
            $obj->$key = $value;
        }
        return $obj;
    }

    public function data_seek(int $offset): bool
    {
        if ($offset < 0) {
            throw new ValueError('mysqli_result::data_seek(): Argument #1 ($offset) must be greater than or equal to 0');
        }
        if ($offset >= $this->num_rows) {
            return false;
        }
        $this->cursor = $offset;
        return true;
    }

    /**
     * Rows live in PHP arrays, so the cleanup methods have nothing to release;
     * they exist so result-handling code can call them on either result type.
     */
    public function free(): void
    {
    }

    public function close(): void
    {
    }

    public function free_result(): void
    {
    }
}
