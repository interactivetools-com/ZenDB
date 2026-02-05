<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Escaping;

use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\RawSql;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for LIKE pattern helper methods
 *
 * @covers \Itools\ZenDB\Connection::likeContains
 * @covers \Itools\ZenDB\Connection::likeContainsTSV
 * @covers \Itools\ZenDB\Connection::likeStartsWith
 * @covers \Itools\ZenDB\Connection::likeEndsWith
 */
class LikePatternTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region likeContains

    public function testLikeContainsPattern(): void
    {
        $result = DB::likeContains('test');
        $this->assertInstanceOf(RawSql::class, $result);
        $this->assertSame("'%test%'", (string) $result);
    }

    public function testLikeContainsEscapesPercent(): void
    {
        $result = DB::likeContains('100%');
        $this->assertSame("'%100\\%%'", (string) $result);
    }

    public function testLikeContainsEscapesUnderscore(): void
    {
        $result = DB::likeContains('user_name');
        $this->assertSame("'%user\\_name%'", (string) $result);
    }

    public function testLikeContainsWithSpecialChars(): void
    {
        $result = DB::likeContains("O'Brien");
        $this->assertSame("'%O\\'Brien%'", (string) $result);
    }

    public function testLikeContainsInteger(): void
    {
        $result = DB::likeContains(42);
        $this->assertSame("'%42%'", (string) $result);
    }

    public function testLikeContainsFloat(): void
    {
        $result = DB::likeContains(3.14);
        $this->assertSame("'%3.14%'", (string) $result);
    }

    //endregion
    //region likeContainsTSV

    public function testLikeContainsTSVPattern(): void
    {
        $result = DB::likeContainsTSV('value');
        $this->assertSame("'%\\tvalue\\t%'", (string) $result);
    }

    public function testLikeContainsTSVEscapesSpecialChars(): void
    {
        $result = DB::likeContainsTSV("O'Brien");
        $this->assertSame("'%\\tO\\'Brien\\t%'", (string) $result);
    }

    public function testLikeContainsTSVEscapesWildcards(): void
    {
        $result = DB::likeContainsTSV('100%');
        $this->assertSame("'%\\t100\\%\\t%'", (string) $result);
    }

    //endregion
    //region likeStartsWith

    public function testLikeStartsWithPattern(): void
    {
        $result = DB::likeStartsWith('test');
        $this->assertSame("'test%'", (string) $result);
    }

    public function testLikeStartsWithEscapesWildcards(): void
    {
        $result = DB::likeStartsWith('100%_start');
        $this->assertSame("'100\\%\\_start%'", (string) $result);
    }

    public function testLikeStartsWithSpecialChars(): void
    {
        $result = DB::likeStartsWith("O'");
        $this->assertSame("'O\\'%'", (string) $result);
    }

    //endregion
    //region likeEndsWith

    public function testLikeEndsWithPattern(): void
    {
        $result = DB::likeEndsWith('test');
        $this->assertSame("'%test'", (string) $result);
    }

    public function testLikeEndsWithEscapesWildcards(): void
    {
        $result = DB::likeEndsWith('_end%');
        $this->assertSame("'%\\_end\\%'", (string) $result);
    }

    public function testLikeEndsWithSpecialChars(): void
    {
        $result = DB::likeEndsWith("'Brien");
        $this->assertSame("'%\\'Brien'", (string) $result);
    }

    //endregion
    //region Common Tests

    public function testLikePatternsReturnRawSql(): void
    {
        $this->assertInstanceOf(RawSql::class, DB::likeContains('test'));
        $this->assertInstanceOf(RawSql::class, DB::likeContainsTSV('test'));
        $this->assertInstanceOf(RawSql::class, DB::likeStartsWith('test'));
        $this->assertInstanceOf(RawSql::class, DB::likeEndsWith('test'));
    }

    public function testLikePatternsWithSmartString(): void
    {
        $smart = new SmartString('test');

        $this->assertSame("'%test%'", (string) DB::likeContains($smart));
        $this->assertSame("'%\\ttest\\t%'", (string) DB::likeContainsTSV($smart));
        $this->assertSame("'test%'", (string) DB::likeStartsWith($smart));
        $this->assertSame("'%test'", (string) DB::likeEndsWith($smart));
    }

    public function testLikePatternsWithNull(): void
    {
        $this->assertSame("'%%'", (string) DB::likeContains(null));
        $this->assertSame("'%\\t\\t%'", (string) DB::likeContainsTSV(null));
        $this->assertSame("'%'", (string) DB::likeStartsWith(null));
        $this->assertSame("'%'", (string) DB::likeEndsWith(null));
    }

    public function testLikePatternsWithEmptyString(): void
    {
        $this->assertSame("'%%'", (string) DB::likeContains(''));
        $this->assertSame("'%\\t\\t%'", (string) DB::likeContainsTSV(''));
        $this->assertSame("'%'", (string) DB::likeStartsWith(''));
        $this->assertSame("'%'", (string) DB::likeEndsWith(''));
    }

    //endregion
    //region Integration Tests

    public function testLikeContainsInRealQuery(): void
    {
        $pattern = DB::likeContains('Doe');
        $result = DB::query("SELECT * FROM ::users WHERE name LIKE ?", $pattern);
        $this->assertCount(2, $result); // John Doe, Jane Janey Doe
    }

    public function testLikeStartsWithInRealQuery(): void
    {
        $pattern = DB::likeStartsWith('John');
        $result = DB::query("SELECT * FROM ::users WHERE name LIKE ?", $pattern);
        $this->assertCount(1, $result); // John Doe only
    }

    public function testLikeEndsWithInRealQuery(): void
    {
        $pattern = DB::likeEndsWith('Doe');
        $result = DB::query("SELECT * FROM ::users WHERE name LIKE ?", $pattern);
        $this->assertCount(2, $result); // John Doe, Jane Janey Doe
    }

    public function testLikeContainsWithPlaceholder(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE name LIKE ?", DB::likeContains('Brown'));
        $this->assertCount(1, $result); // Charlie Brown
    }

    public function testLikeStartsWithNoFalsePositives(): void
    {
        // Create a user with percent sign in name
        DB::insert('users', ['name' => '100% Complete', 'status' => 'Active', 'city' => 'Test']);

        // Search for '100%' should only match that user, not '100' followed by anything
        $pattern = DB::likeStartsWith('100%');
        $result = DB::query("SELECT * FROM ::users WHERE name LIKE ?", $pattern);

        $this->assertCount(1, $result);
        $this->assertSame('100% Complete', $result->first()->get('name')->value());

        // Clean up
        DB::delete('users', ['name' => '100% Complete']);
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider provideLikePatternScenarios
     */
    public function testLikePatternScenarios(string $method, mixed $input, string $expected): void
    {
        $result = DB::$method($input);
        $this->assertSame($expected, (string) $result);
    }

    public static function provideLikePatternScenarios(): array
    {
        return [
            ['likeContains', 'test', "'%test%'"],
            ['likeContains', 'a%b', "'%a\\%b%'"],
            ['likeContains', 'a_b', "'%a\\_b%'"],
            ['likeStartsWith', 'test', "'test%'"],
            ['likeStartsWith', 'a%', "'a\\%%'"],
            ['likeEndsWith', 'test', "'%test'"],
            ['likeEndsWith', '%end', "'%\\%end'"],
            ['likeContainsTSV', 'val', "'%\\tval\\t%'"],
        ];
    }

    //endregion
}
