<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli_sql_exception;

/**
 * Tests for DB::$queryCount (internal per-request query counter)
 */
class QueryCountTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTestTables();
    }

    public function testCountsEachQuery(): void
    {
        $before = DB::$queryCount;
        DB::query("SELECT * FROM `:_users` WHERE num = ?", 1);
        DB::query("SELECT * FROM `:_users` WHERE num = ?", 2);
        $this->assertSame($before + 2, DB::$queryCount);
    }

    public function testCountsFailedQueries(): void
    {
        $before = DB::$queryCount;
        try {
            DB::query("INVALID SQL STATEMENT");
        } catch (mysqli_sql_exception) {
            // expected; the failed round trip should still count
        }
        $this->assertSame($before + 1, DB::$queryCount);
    }

    public function testConnectSetupQueriesCount(): void
    {
        DB::disconnect();
        $before = DB::$queryCount;
        DB::connect(self::$configDefaults);
        $this->assertGreaterThan($before, DB::$queryCount); // sqlMode/timezone SETs count, the connect itself doesn't
        self::resetTestTables(); // leave fixtures in place for tests that run after this one
    }
}
