<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Escaping;

use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\RawSql;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for castBinary(), which casts a value to a binary string so only the identical string matches after `=`
 *
 * @covers \Itools\ZenDB\Connection::castBinary
 */
class CastBinaryTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTestTables();
    }

    //region SQL Written

    public function testStringIsCastToBinary(): void
    {
        $result = DB::castBinary('abc');
        $this->assertInstanceOf(RawSql::class, $result);
        $this->assertSame("CAST('abc' AS BINARY)", (string)$result);
    }

    public function testQuotesAndBackslashesAreEscaped(): void
    {
        $this->assertSame("CAST('O\\'Brien' AS BINARY)", (string)DB::castBinary("O'Brien"));
        $this->assertSame("CAST('a\\\\b' AS BINARY)", (string)DB::castBinary('a\\b'));
    }

    public function testLikeWildcardsAreNotEscaped(): void
    {
        $this->assertSame("CAST('50%_' AS BINARY)", (string)DB::castBinary('50%_'));
    }

    public function testIntIsQuotedSoAStringColumnComparesItAsText(): void
    {
        $this->assertSame("CAST('42' AS BINARY)", (string)DB::castBinary(42));
    }

    public function testSmartStringIsUnwrapped(): void
    {
        $this->assertSame("CAST('abc' AS BINARY)", (string)DB::castBinary(new SmartString('abc')));
    }

    public function testEmptyStringIsCastNotNull(): void
    {
        $this->assertSame("CAST('' AS BINARY)", (string)DB::castBinary(''));
    }

    /**
     * @dataProvider provideNullValues
     */
    public function testNullValuesBecomeNull(mixed $value): void
    {
        $this->assertSame('NULL', (string)DB::castBinary($value));
    }

    public static function provideNullValues(): array
    {
        return [
            'null'                 => [null],
            'SmartNull'            => [new SmartNull()],
            'SmartString of null'  => [new SmartString(null)],
        ];
    }

    //endregion
    //region Rows Matched

    public function testWhereArrayMatchesTheIdenticalValue(): void
    {
        $this->assertSame(1, DB::count('users', ['name' => DB::castBinary('John Doe')]));
    }

    public function testPlaceholderMatchesTheIdenticalValue(): void
    {
        $this->assertSame(1, DB::count('users', "name = ?", DB::castBinary('John Doe')));
    }

    /**
     * @dataProvider provideValuesThatAreNotIdentical
     */
    public function testValueThatIsNotIdenticalMatchesNoRows(string $value): void
    {
        $this->assertSame(0, DB::count('users', ['name' => DB::castBinary($value)]));
    }

    public static function provideValuesThatAreNotIdentical(): array
    {
        return [
            'different case' => ['john doe'],
            'trailing space' => ['John Doe '],
            'accent'         => ['Jöhn Doe'],
        ];
    }

    public function testNullMatchesNoRows(): void
    {
        $this->assertSame(0, DB::count('users', ['isAdmin' => DB::castBinary(null)])); // three rows have isAdmin NULL
    }

    public function testOneCallPerValueInAnInList(): void
    {
        $count = DB::count('users', "name IN (?, ?)", DB::castBinary('John Doe'), DB::castBinary('alice smith'));
        $this->assertSame(1, $count);
    }

    //endregion
}
