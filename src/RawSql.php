<?php
declare(strict_types=1);

namespace Itools\ZenDB;

/**
 * Marks a value as raw SQL that should be inserted as-is, without escaping or quoting.
 *
 * Use this for SQL functions, expressions, or pre-escaped values:
 *
 *     DB::insert('users', [
 *         'name'       => $userName,              // Escaped: "O'Brien"
 *         'created_at' => DB::rawSql('NOW()'),    // Raw: NOW()
 *         'sort_order' => DB::rawSql('num + 1'),  // Raw: num + 1
 *     ]);
 *
 * WARNING: Never pass user input to rawSql() - it bypasses all escaping.
 */
class RawSql
{
    public function __construct(private readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
