<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;

/**
 * Tests for DB::insert() static method
 */
class InsertTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        new Connection(self::$configDefaults, default: true);
        self::resetTempTestTables();
    }

    public function testInsertSequence(): void
    {
        $baseTable    = "users";
        $colsToValues = [
            'num'     => 212,
            'name'    => 'Jillian Ty lair',
            'isAdmin' => 1,
            'status'  => 'Active',
            'city'    => 'New York',
            'dob'     => '1989-01-02',
            'age'     => 35,
        ];

        // Test new record number is returned and has expected values
        $insertId = DB::insert($baseTable, $colsToValues);
        $this->assertSame(expected: 212, actual: $insertId, message: "insertId should be 212");
        $this->assertSame(
            expected: $colsToValues,
            actual:   DB::get($baseTable, $insertId)->toArray(),
            message:  "Inserted record should have expected values"
        );

        // Test inserting record with duplicate primary key throws exception
        $exceptionThrown = false;
        try {
            DB::insert($baseTable, $colsToValues);
        } catch (\Exception) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown, "Expected exception thrown");
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
    }

    public function testInsertEmptyArrayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::insert('users', []);
    }
}
