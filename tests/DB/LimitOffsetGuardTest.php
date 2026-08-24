<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlIdentifier */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Closure;
use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * selectOne(), queryOne() and count() reject LIMIT/OFFSET because they append their own,
 * and selectOne()/queryOne() reject row-locking clauses that MySQL requires after LIMIT.
 * Those guards must only fire on a real top-level clause: the same keyword inside a
 * backtick identifier, a comment or a parenthesised subquery is valid SQL and must pass.
 */
class LimitOffsetGuardTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTestTables();

        // a table whose columns are named after the guarded keywords
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_keywords");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_keywords (id INT PRIMARY KEY, `offset` INT, `limit` INT)");
        DB::$mysqli->query("INSERT INTO test_keywords (id, `offset`, `limit`) VALUES (1, 0, 10), (2, 5, 20), (3, 9, 30)");
    }

    public static function tearDownAfterClass(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_keywords");
    }

    //region keyword below the top level is allowed

    public function testSelectOneAllowsColumnNamedOffset(): void
    {
        $row = DB::selectOne('keywords', "`offset` = ?", 5);
        $this->assertSame(['id' => 2, 'offset' => 5, 'limit' => 20], $row->toArray());
    }

    public function testCountAllowsColumnNamedOffset(): void
    {
        $this->assertSame(2, DB::count('keywords', "`offset` > ?", 0));
    }

    public function testSelectOneAllowsColumnNamedLimitInOrderBy(): void
    {
        $row = DB::selectOne('keywords', "id > ? ORDER BY `limit` DESC", 0);
        $this->assertSame(3, $row->toArray()['id']);
    }

    public function testQueryOneAllowsAliasNamedOffset(): void
    {
        $row = DB::queryOne("SELECT num AS `offset` FROM ::users WHERE num = ?", 1);
        $this->assertSame(['offset' => 1], $row->toArray());
    }

    public function testQueryOneAllowsLimitInsideDerivedTable(): void
    {
        // the LIMIT belongs to the derived table; the outer query has none, so the appended LIMIT 1 is valid
        $row = DB::queryOne("SELECT * FROM (SELECT num, status FROM ::users ORDER BY num DESC LIMIT ?) AS t WHERE t.status = ? ORDER BY t.num DESC", 5, 'Active');
        $this->assertSame(['num' => 19, 'status' => 'Active'], $row->toArray());
    }

    public function testSelectOneAllowsLimitInsideNestedSubquery(): void
    {
        // inner table is test_products: a regular table, so it can be reopened inside a derived table
        $row = DB::selectOne('keywords', "id IN (SELECT id FROM (SELECT product_id AS id FROM ::products ORDER BY product_id LIMIT ?) AS x) ORDER BY id DESC", 2);
        $this->assertSame(['id' => 2, 'offset' => 5, 'limit' => 20], $row->toArray());
    }

    public function testCountAllowsKeywordInsideBlockComment(): void
    {
        $this->assertSame(10, DB::count('users', "status = ? /* no LIMIT here */", 'Active'));
    }

    public function testCountAllowsKeywordInsideTrailingLineComment(): void
    {
        // count() appends nothing, so a trailing line comment is harmless here
        $this->assertSame(10, DB::count('users', "status = ? -- LIMIT", 'Active'));
    }

    public function testQueryOneAllowsLockingKeywordInsideComment(): void
    {
        $row = DB::queryOne("SELECT num FROM ::users WHERE num = ? /* FOR UPDATE */", 1);
        $this->assertSame(['num' => 1], $row->toArray());
    }

    //endregion
    //region keyword at the top level is still rejected

    /**
     * @dataProvider provideTopLevelLimitOrOffset
     */
    public function testTopLevelLimitOrOffsetIsStillRejected(Closure $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("This method doesn't support LIMIT or OFFSET");
        $call();
    }

    public static function provideTopLevelLimitOrOffset(): array
    {
        return [
            'selectOne: LIMIT after a keyword column' => [fn() => DB::selectOne('keywords', "`offset` = ? LIMIT 5", 5)],
            'selectOne: LIMIT after a subquery'       => [fn() => DB::selectOne('users', "num IN (SELECT user_id FROM ::orders) LIMIT ?", 5)],
            'queryOne: LIMIT and OFFSET'              => [fn() => DB::queryOne("SELECT * FROM ::users ORDER BY num LIMIT 5 OFFSET 1")],
            'count: LIMIT'                            => [fn() => DB::count('users', "ORDER BY num LIMIT 5")],
        ];
    }

    public function testSelectOneStillRejectsTopLevelForUpdateAfterSubquery(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support FOR UPDATE");
        DB::selectOne('users', "num IN (SELECT user_id FROM ::orders) FOR UPDATE");
    }

    //endregion
    //region quoted text: the template guard's error wins, not a misleading keyword error

    public function testQuotedKeywordReportsQuotesErrorNotLimitError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Quotes not allowed in template");
        DB::selectOne('users', "name = 'no limit'");
    }

    public function testQuotedLockingKeywordReportsQuotesErrorNotForUpdateError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Quotes not allowed in template");
        DB::selectOne('users', "name = 'for update'");
    }

    //endregion
}
