<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\ValueTypes;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for NULL value handling
 *
 * @covers \Itools\ZenDB\ConnectionInternals::getPlaceholderValue
 * @covers \Itools\ZenDB\ConnectionInternals::whereFromArray
 */
class NullValuesTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region NULL in INSERT

    public function testNullInInsert(): void
    {
        $insertId = DB::insert('users', [
            'name' => 'Null Test User',
            'isAdmin' => null,
            'status' => 'Active',
            'city' => 'Test City'
        ]);

        $row = DB::selectOne('users', ['num' => $insertId]);
        $this->assertNull($row->get('isAdmin')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    public function testNullInInsertViaPlaceholder(): void
    {
        DB::query("INSERT INTO ::users (name, isAdmin, status, city) VALUES (?, ?, ?, ?)",
            ['Null Placeholder User', null, 'Active', 'Test']
        );

        $row = DB::selectOne('users', ['name' => 'Null Placeholder User']);
        $this->assertNull($row->get('isAdmin')->value());

        // Clean up
        DB::delete('users', ['name' => 'Null Placeholder User']);
    }

    //endregion
    //region NULL in UPDATE

    public function testNullInUpdateSet(): void
    {
        // Insert a user with isAdmin = 1
        $insertId = DB::insert('users', [
            'name' => 'Update Null Test',
            'isAdmin' => 1,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        // Update to NULL
        DB::update('users', ['isAdmin' => null], ['num' => $insertId]);

        $row = DB::selectOne('users', ['num' => $insertId]);
        $this->assertNull($row->get('isAdmin')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    //endregion
    //region NULL in WHERE

    public function testNullInWhereArrayBecomesIsNull(): void
    {
        // Users with isAdmin = NULL
        $result = DB::select('users', ['isAdmin' => null]);

        // Verify all returned rows have NULL isAdmin
        foreach ($result as $row) {
            $this->assertNull($row->get('isAdmin')->value());
        }

        // Users 2, 7, 14, 19 based on test data
        $this->assertCount(4, $result);
    }

    public function testNullInWherePlaceholder(): void
    {
        // Using placeholder with NULL - note: "= NULL" is different from "IS NULL"
        $result = DB::query("SELECT * FROM ::users WHERE isAdmin = ?", null);

        // "column = NULL" is always false in SQL
        $this->assertCount(0, $result);
    }

    public function testIsNullInWhereString(): void
    {
        // Proper way to check for NULL in string WHERE
        $result = DB::query("SELECT * FROM ::users WHERE isAdmin IS NULL");

        $this->assertCount(4, $result);
        foreach ($result as $row) {
            $this->assertNull($row->get('isAdmin')->value());
        }
    }

    //endregion
    //region NULL from Database

    public function testNullReturnedFromDatabase(): void
    {
        // User 2 has isAdmin = NULL
        $row = DB::selectOne('users', ['num' => 2]);

        $isAdmin = $row->get('isAdmin');
        $this->assertNull($isAdmin->value());
        $this->assertTrue($isAdmin->isNull());
    }

    public function testNullInSelectResults(): void
    {
        $result = DB::query("SELECT num, name, isAdmin FROM ::users WHERE isAdmin IS NULL ORDER BY num LIMIT 1");

        $row = $result->first();
        $this->assertNull($row->get('isAdmin')->value());
    }

    //endregion
    //region NULL Comparisons

    public function testNullNotEqualToZero(): void
    {
        // isAdmin = 0 should NOT match NULL
        $zeros = DB::select('users', ['isAdmin' => 0]);
        $nulls = DB::select('users', ['isAdmin' => null]);

        // No overlap between the two sets
        foreach ($zeros as $row) {
            $this->assertNotNull($row->get('isAdmin')->value());
        }
        foreach ($nulls as $row) {
            $this->assertNull($row->get('isAdmin')->value());
        }
    }

    public function testNullNotEqualToEmptyString(): void
    {
        // Test that empty string and NULL are handled differently
        $insertId = DB::insert('users', [
            'name' => '',  // empty string, not NULL
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $row = DB::selectOne('users', ['num' => $insertId]);
        $this->assertSame('', $row->get('name')->value());
        $this->assertFalse($row->get('name')->isNull());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    //endregion
}
