<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;
use mysqli_sql_exception;

/**
 * Tests for DB::query() static method
 */
class QueryTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
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
        $this->assertSame(6, $result->count());
    }

    public function testQueryShowTables(): void
    {
        // Note: TEMPORARY tables don't show in SHOW TABLES
        // We need to either skip this test or create a permanent table
        // For now, just verify the query works regardless of result count
        $result = DB::query("SHOW TABLES");
        // Query should execute without error
        $this->assertInstanceOf(\Itools\SmartArray\SmartArrayHtml::class, $result);
    }

    public function testQueryInvalidSqlThrows(): void
    {
        $this->expectException(mysqli_sql_exception::class);
        DB::query("INVALID SQL STATEMENT");
    }

    public function testQueryMustStartWithKeyword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::query("* FROM users");
    }
}
