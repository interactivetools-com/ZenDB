<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;

/**
 * Tests for DB::insert() static method
 */
class InsertTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTempTestTables();
    }

    public function testInsertSequence(): void
    {
        $baseTable    = "users";
        $values = [
            'num'     => 212,
            'name'    => 'Jillian Ty lair',
            'isAdmin' => 1,
            'status'  => 'Active',
            'city'    => 'New York',
            'dob'     => '1989-01-02',
            'age'     => 35,
        ];

        // Test new record number is returned and has expected values
        $insertId = DB::insert($baseTable, $values);
        $this->assertSame(expected: 212, actual: $insertId, message: "insertId should be 212");
        $this->assertSame(
            expected: $values,
            actual:   DB::selectOne($baseTable, ['num' => $insertId])->toArray(),
            message:  "Inserted record should have expected values"
        );

        // Test inserting record with duplicate primary key throws exception
        $this->expectException(\mysqli_sql_exception::class);
        $this->expectExceptionMessage("Duplicate entry");
        DB::insert($baseTable, $values);
    }

    public function testInsertReturnsAutoIncrement(): void
    {
        self::resetTempTestTables();

        $insertId = DB::insert('users', [
            'name'    => 'New User',
            'isAdmin' => 0,
            'status'  => 'Active',
            'city'    => 'TestCity',
            'dob'     => '2000-01-01',
            'age'     => 24,
        ]);

        $this->assertSame(21, $insertId);

        // Read back and verify inserted data
        $row = DB::selectOne('users', ['num' => 21]);
        $this->assertSame('New User', $row->get('name')->value());
        $this->assertSame('TestCity', $row->get('city')->value());
    }

    public function testInsertEmptyArrayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("No values provided");
        DB::insert('users', []);
    }

    public function testInsertRejectsArrayValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported value type for column 'city'");
        DB::insert('users', [
            'name'    => 'Array Test',
            'isAdmin' => 0,
            'status'  => 'Active',
            'city'    => ['NYC', 'LA'],
            'dob'     => '2000-01-01',
            'age'     => 24,
        ]);
    }
}
