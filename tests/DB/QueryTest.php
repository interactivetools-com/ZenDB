<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\DBException;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;

/**
 * Tests for DB::query() static method
 */
class QueryTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        new Connection(self::$configDefaults, default: true);
        self::resetTempTestTables();
    }

    public function testQuerySelect(): void
    {
        $result = DB::query("SELECT * FROM `:_users` WHERE num = ?", 1);
        $this->assertSame(1, $result->count());
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testQueryWithNamedParams(): void
    {
        $result = DB::query("SELECT * FROM `:_users` WHERE age > :age", [':age' => 40]);
        $this->assertGreaterThan(0, $result->count());
    }

    public function testQueryShowTables(): void
    {
        $result = DB::query("SHOW TABLES LIKE ?", 'test_%');
        $this->assertGreaterThan(0, $result->count());
    }

    public function testQueryInvalidSqlThrows(): void
    {
        $this->expectException(DBException::class);
        DB::query("INVALID SQL STATEMENT");
    }

    public function testQueryMustStartWithKeyword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::query("* FROM users");
    }
}
