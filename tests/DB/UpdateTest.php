<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;

/**
 * Tests for DB::update() static method
 */
class UpdateTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTempTestTables();
    }

    protected function setUp(): void
    {
        self::resetTempTestTables();
    }

    public function testUpdateSingleRow(): void
    {
        $affected = DB::update('users', ['name' => 'Updated Name'], ['num' => 1]);
        $this->assertSame(1, $affected);

        $result = DB::selectOne('users', ['num' => 1]);
        $this->assertSame('Updated Name', $result->get('name')->value());
    }

    public function testUpdateWithArrayCondition(): void
    {
        $affected = DB::update('users', ['city' => 'NewCity'], ['name' => 'John Doe']);
        $this->assertSame(1, $affected);

        // Read back and verify
        $result = DB::selectOne('users', ['name' => 'John Doe']);
        $this->assertSame('NewCity', $result->get('city')->value());
    }

    public function testUpdateMultipleRows(): void
    {
        $affected = DB::update('users', ['city' => 'SameCity'], 'status = ?', 'Active');
        $this->assertSame(10, $affected);

        // Verify all Active users have the new city
        $activeUsers = DB::select('users', ['status' => 'Active']);
        foreach ($activeUsers as $row) {
            $this->assertSame('SameCity', $row->get('city')->value());
        }

        // Verify no non-Active users were changed
        $nonActiveChanged = DB::query("SELECT * FROM ::users WHERE status != ? AND city = ?", ['Active', 'SameCity']);
        $this->assertCount(0, $nonActiveChanged);
    }

    public function testUpdateRequiresWhereCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");
        DB::update('users', ['name' => 'Test'], '');
    }

    public function testUpdateWithNoChangesReturnsZero(): void
    {
        $this->assertSame('John Doe', DB::selectOne('users', ['num' => 1])->get('name')->value());
        $affected = DB::update('users', ['name' => 'John Doe'], ['num' => 1]);
        $this->assertSame(0, $affected);
    }
}
