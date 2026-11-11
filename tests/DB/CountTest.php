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
        self::resetTestTables();
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
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        DB::count('users', 'LIMIT 5');
    }

    public function testCountWithPagingValuePastPageOneThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        DB::count('users', "status = ? ?", 'Active', DB::pagingSql(2, 5));
    }

    public function testCountWithLimitZeroPassedAsValueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        DB::count('users', "status = ? ?", 'Active', DB::rawSql('LIMIT 0'));
    }

    public function testCountWithPagingValueOnPageOneReturnsTheCount(): void
    {
        $count = DB::count('users', "status = ? ?", 'Active', DB::pagingSql(1, 5));
        $this->assertSame(10, $count);
    }

    /**
     * @dataProvider noGroupLeftProvider
     */
    public function testCountReturnsZeroWithoutWarningWhenNoGroupIsLeft(string $whereEtc, array $params): void
    {
        [$count, $warnings] = $this->captureErrors(fn() => DB::count('users', $whereEtc, ...$params), E_WARNING);

        $this->assertSame(0, $count);
        $this->assertSame([], $warnings);
    }

    public static function noGroupLeftProvider(): array
    {
        return [
            'GROUP BY, no rows match' => ["status = ? GROUP BY status", ['Nope']],
            'HAVING excludes the row' => ["status = ? HAVING COUNT(*) > ?", ['Active', 100]],
        ];
    }
}
