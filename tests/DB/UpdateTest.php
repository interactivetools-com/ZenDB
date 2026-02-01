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
 * Tests for DB::update() static method
 */
class UpdateTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        new Connection(self::$configDefaults, default: true);
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

        $result = DB::get('users', ['num' => 1]);
        $this->assertSame('Updated Name', $result->get('name')->value());
    }

    public function testUpdateWithArrayCondition(): void
    {
        $affected = DB::update('users', ['city' => 'NewCity'], ['name' => 'John Doe']);
        $this->assertSame(1, $affected);
    }

    public function testUpdateMultipleRows(): void
    {
        $affected = DB::update('users', ['city' => 'SameCity'], 'status = ?', 'Active');
        $this->assertGreaterThan(1, $affected);
    }

    public function testUpdateRequiresWhereCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::update('users', ['name' => 'Test'], '');
    }

    public function testUpdateWithNoChangesReturnsZero(): void
    {
        $original = DB::get('users', ['num' => 1])->get('name')->value();
        $affected = DB::update('users', ['name' => $original], ['num' => 1]);
        $this->assertSame(0, $affected);
    }
}
