<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;

/**
 * Tests for DB::count() static method
 */
class CountTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTempTestTables();
    }

    public function testCountAllRows(): void
    {
        $count = DB::count('users');
        $this->assertSame(20, $count);
    }

    public function testCountWithCondition(): void
    {
        $count = DB::count('users', ['status' => 'Active']);
        $this->assertSame(10, $count);
    }

    public function testCountWithSqlCondition(): void
    {
        $count = DB::count('users', 'age > ?', 30);
        $this->assertSame(14, $count);
    }

    public function testCountReturnsZeroForNoMatch(): void
    {
        $count = DB::count('users', ['name' => 'NonExistentName123']);
        $this->assertSame(0, $count);
    }

    public function testCountWithLimitThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::count('users', 'LIMIT 5');
    }
}
