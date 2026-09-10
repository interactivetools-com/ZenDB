<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for passing a RawSql object as the WHERE argument of select(), selectOne(),
 * update(), delete(), and count(). Undocumented on purpose; internal code builds its
 * own WHERE with DB::whereSql() and hands it over without a placeholder.
 *
 * @covers \Itools\ZenDB\ConnectionInternals::whereFromArgs
 */
class RawSqlWhereTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
    }

    protected function setUp(): void
    {
        self::resetTestTables();
    }

    //region Each Method

    public function testSelectAcceptsRawSql(): void
    {
        $expected = DB::select('users', ['num' => [1, 2, 3]])->count();
        $actual   = DB::select('users', DB::rawSql("num IN (1,2,3)"))->count();
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, $actual);
        $this->assertSame("SELECT * FROM `test_users` WHERE num IN (1,2,3)", DB::$mysqli->lastQuery);
    }

    public function testSelectOneAcceptsRawSql(): void
    {
        $row = DB::selectOne('users', DB::rawSql("num = 2"));
        $this->assertSame(2, $row->num->value());
    }

    public function testCountAcceptsRawSql(): void
    {
        $this->assertSame(DB::count('users', ['num' => [1, 2]]), DB::count('users', DB::rawSql("num IN (1,2)")));
    }

    public function testUpdateAcceptsRawSql(): void
    {
        $affected = DB::update('users', ['city' => 'Raw City'], DB::rawSql("num = 1"));
        $this->assertSame(1, $affected);
        $this->assertSame('Raw City', DB::selectOne('users', ['num' => 1])->city->value());
    }

    public function testDeleteAcceptsRawSql(): void
    {
        $before = DB::count('users');
        $this->assertSame(1, DB::delete('users', DB::rawSql("num = 1")));
        $this->assertSame($before - 1, DB::count('users'));
    }

    //endregion
    //region SQL Handling

    public function testWhereSqlOutputPassesStraightThrough(): void
    {
        $where = DB::rawSql(DB::whereSql(['num' => [1, 2], 'city' => "O'Brien"]));
        DB::select('users', $where);
        $this->assertSame("SELECT * FROM `test_users` WHERE `num` IN (1,2) AND `city` = 'O\\'Brien'", DB::$mysqli->lastQuery);
    }

    public function testLeadingKeywordIsKept(): void
    {
        DB::select('users', DB::rawSql("WHERE num = 1"));
        $this->assertSame("SELECT * FROM `test_users` WHERE num = 1", DB::$mysqli->lastQuery);

        DB::select('users', DB::rawSql("ORDER BY num DESC LIMIT 2"));
        $this->assertSame("SELECT * FROM `test_users` ORDER BY num DESC LIMIT 2", DB::$mysqli->lastQuery);
    }

    public function testQuotesAndNumbersAreAllowed(): void
    {
        // a string template would throw on the quotes and the number; RawSql skips that check
        $rows = DB::select('users', DB::rawSql("city = 'Vancouver' AND num > 0"));
        $this->assertSame(DB::count('users', "city = ? AND num > ?", 'Vancouver', 0), $rows->count());
    }

    public function testPlaceholdersAreNotReplaced(): void
    {
        DB::select('users', DB::rawSql("num = 1 -- :name ?"));
        $this->assertStringEndsWith("WHERE num = 1 -- :name ?", DB::$mysqli->lastQuery);
    }

    public function testKeywordsInsideValuesAreNotMistakenForClauses(): void
    {
        // the LIMIT/comment/lock checks on selectOne() and count() must only see the SQL around the values
        foreach (['Speed Limit', 'no offset', 'trailing --', '#1 fan', 'ready FOR UPDATE', "it's; done", 'say "LIMIT"'] as $value) {
            $where = DB::rawSql(DB::whereSql(['name' => $value]));
            $this->assertSame(0, DB::count('users', $where), "count() with value '$value'");
            $this->assertTrue(DB::selectOne('users', $where)->isEmpty(), "selectOne() with value '$value'");
        }
    }

    public function testEmptyRawSqlMeansAllRowsForSelect(): void
    {
        $this->assertSame(DB::count('users'), DB::select('users', DB::rawSql(''))->count());
        $this->assertSame("SELECT * FROM `test_users` ", DB::$mysqli->lastQuery);
    }

    //endregion
    //region Template Bans Don't Apply

    /**
     * Every pattern assertSafeTemplate() refuses, with the row count it matches in test_users.
     */
    public static function bannedTemplateProvider(): array
    {
        return [
            'single quotes'        => ["name = 'John Doe'", 1],
            'double quotes'        => ['name = "John Doe"', 1],
            'empty quotes'         => ["name = '' OR num = 2", 1],
            'standalone number'    => ["num = 1", 1],
            'number list'          => ["num IN (1, 2, 3)", 3],
            'hex literal'          => ["num = 0x1", 1],
            'binary literal'       => ["num = 0b1", 1],
            'binary string'        => ["num = b'1'", 1],
            'scientific literal'   => ["num = 1e0", 1],
            'backslash escape'     => ["name = 'O\\'Brien'", 0],
            'double backslash'     => ["name = 'a\\\\b'", 0],
            'escaped null byte'    => ["name = 'a\\0b'", 0],
            'escaped ctrl-z'       => ["name = 'a\\Zb'", 0],
            'raw null byte'        => ["name = 'a\0b'", 0],
            'raw ctrl-z'           => ["name = 'a\x1ab'", 0],
            'everything at once'   => ["num IN (1,2) AND name != 'x' AND city LIKE \"%an%\" AND age > 0x0", 1],
            'whereSql() shape'     => ["`name` = 'O\\'Brien \\\\ \\\"quoted\\\"' AND `num` IN (1,2)", 0],  // what whereSql() produces for that value
        ];
    }

    #[DataProvider('bannedTemplateProvider')]
    public function testBannedTemplatePatternThrowsAsString(string $sql): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::select('users', $sql);
    }

    #[DataProvider('bannedTemplateProvider')]
    public function testBannedTemplatePatternRunsAsRawSql(string $sql, int $expectedRows): void
    {
        $where = DB::rawSql($sql);
        $this->assertSame($expectedRows, DB::select('users', $where)->count(), 'select()');
        $this->assertSame($expectedRows, DB::count('users', $where), 'count()');
        $this->assertSame($expectedRows === 0, DB::selectOne('users', $where)->isEmpty(), 'selectOne()');
    }

    #[DataProvider('bannedTemplateProvider')]
    public function testBannedTemplatePatternRunsAsRawSqlForUpdateAndDelete(string $sql, int $expectedRows): void
    {
        $where = DB::rawSql($sql);
        $this->assertSame($expectedRows, DB::update('users', ['dob' => '2001-01-01'], $where), 'update()');  // dob: no case filters on it
        $this->assertSame($expectedRows, DB::count('users', ['dob' => '2001-01-01']), 'rows updated');
        $this->assertSame($expectedRows, DB::delete('users', $where), 'delete()');
        $this->assertSame(0, DB::count('users', $where), 'rows deleted');
    }

    //endregion
    //region Validation

    public function testParamsWithRawSqlThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("can't be combined with a RawSql WHERE");
        DB::select('users', DB::rawSql("num = ?"), 1);
    }

    public function testNamedParamsWithRawSqlThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("can't be combined with a RawSql WHERE");
        DB::select('users', DB::rawSql("num = :num"), [':num' => 1]);
    }

    public function testEmptyRawSqlRejectedForUpdate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");
        DB::update('users', ['city' => 'x'], DB::rawSql('  '));
    }

    public function testZeroIsAConditionNotAnEmptyWhere(): void
    {
        // PHP treats "0" as falsy; the empty check must not
        $this->assertSame(0, DB::delete('users', DB::rawSql('0')));
        $this->assertSame(0, DB::update('users', ['dob' => '2001-01-01'], DB::rawSql('0')));
        $this->assertStringEndsWith('WHERE 0', DB::$mysqli->lastQuery);
    }

    public function testClauseOnlyRawSqlRejectedForDelete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DELETE requires a WHERE condition");
        DB::delete('users', DB::rawSql("ORDER BY num LIMIT 1"));
    }

    public function testLimitInRawSqlRejectedForSelectOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        DB::selectOne('users', DB::rawSql("num > 0 LIMIT 5"));
    }

    public function testTrailingCommentInRawSqlRejectedForSelectOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("trailing '--' or '#' comment");
        DB::selectOne('users', DB::rawSql("num > 0 -- all"));
    }

    public function testLimitInRawSqlRejectedForCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        DB::count('users', DB::rawSql("num > 0 LIMIT 5"));
    }

    //endregion
}
