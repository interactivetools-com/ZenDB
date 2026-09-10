<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Escaping;

use InvalidArgumentException;
use Itools\SmartArray\SmartArray;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::whereSql() - turns a WHERE array into a WHERE clause string
 *
 * @covers \Itools\ZenDB\ConnectionInternals::whereSql
 */
class WhereSqlTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTestTables();
    }

    //region Output Shape

    public function testHasNoWhereKeyword(): void
    {
        $this->assertSame("`status` = 'Active'", DB::whereSql(['status' => 'Active']));
    }

    public function testJoinsConditionsWithAnd(): void
    {
        $sql = DB::whereSql(['status' => 'Active', 'city' => 'Vancouver', 'age' => 25]);
        $this->assertSame("`status` = 'Active' AND `city` = 'Vancouver' AND `age` = 25", $sql);
    }

    public function testEmptyArrayReturnsTrue(): void
    {
        $this->assertSame('TRUE', DB::whereSql([]));
    }

    //endregion
    //region Value Types

    public function testStringIsEscapedAndQuoted(): void
    {
        $this->assertSame("`name` = 'O\\'Brien'", DB::whereSql(['name' => "O'Brien"]));
    }

    public function testIntIsNotQuoted(): void
    {
        $this->assertSame("`num` = 42", DB::whereSql(['num' => 42]));
    }

    public function testFloatIsNotQuoted(): void
    {
        $this->assertSame("`price` = 19.99", DB::whereSql(['price' => 19.99]));
    }

    public function testBoolBecomesKeyword(): void
    {
        $this->assertSame("`isAdmin` = TRUE", DB::whereSql(['isAdmin' => true]));
        $this->assertSame("`isAdmin` = FALSE", DB::whereSql(['isAdmin' => false]));
    }

    public function testNullBecomesIsNull(): void
    {
        $this->assertSame("`deleted` IS NULL", DB::whereSql(['deleted' => null]));
    }

    public function testSmartNullBecomesIsNull(): void
    {
        $this->assertSame("`deleted` IS NULL", DB::whereSql(['deleted' => new SmartNull()]));
    }

    public function testArrayBecomesInList(): void
    {
        $this->assertSame("`num` IN (1,2,3)", DB::whereSql(['num' => [1, 2, 3]]));
    }

    public function testSmartArrayBecomesInList(): void
    {
        $this->assertSame("`status` IN ('a','b')", DB::whereSql(['status' => new SmartArray(['a', 'b'])]));
    }

    public function testRawSqlInsertedAsIs(): void
    {
        $this->assertSame("`created` = NOW()", DB::whereSql(['created' => DB::rawSql('NOW()')]));
    }

    public function testSmartStringIsUnwrappedAndEscaped(): void
    {
        $this->assertSame("`name` = 'O\\'Brien'", DB::whereSql(['name' => new SmartString("O'Brien")]));
    }

    //endregion
    //region Validation

    public function testNonStringKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column names must be strings');
        DB::whereSql(['Active']);
    }

    public function testUnsafeColumnNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::whereSql(['name; DROP TABLE users' => 'x']);
    }

    //endregion
    //region Integration

    public function testMatchesSelectArrayForm(): void
    {
        $where       = ['num' => [1, 2], 'name' => "O'Brien"];
        DB::select('users', $where);
        $selectQuery = DB::$mysqli->lastQuery;
        $this->assertStringEndsWith("WHERE " . DB::whereSql($where), $selectQuery);
    }

    public function testEmptyArrayMeansAllRowsInSelect(): void
    {
        DB::select('users', []);
        $this->assertStringNotContainsString('TRUE', DB::$mysqli->lastQuery, 'select() adds no WHERE for an empty array');
    }

    public function testWorksInTemplateThroughRawSql(): void
    {
        $expected = DB::select('users', ['num' => [1, 2, 3]])->count();
        $where    = DB::rawSql(DB::whereSql(['num' => [1, 2, 3]]));
        $actual   = DB::query("SELECT * FROM ::users WHERE :where", [':where' => $where])->count();
        $this->assertSame($expected, $actual);
        $this->assertGreaterThan(0, $actual);
    }

    //endregion
}
