<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Escaping;

use InvalidArgumentException;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\RawSql;
use Itools\ZenDB\Tests\BaseTestCase;
use RuntimeException;

/**
 * Tests for DB::escapeCSV() method
 *
 * @covers \Itools\ZenDB\Connection::escapeCSV
 */
class EscapeCSVTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Basic Types

    public function testEscapeCSVIntegers(): void
    {
        $result = DB::escapeCSV([1, 2, 3]);
        $this->assertInstanceOf(RawSql::class, $result);
        $this->assertSame('1,2,3', (string) $result);
    }

    public function testEscapeCSVStrings(): void
    {
        $result = DB::escapeCSV(['Alice', 'Bob', 'Charlie']);
        $this->assertSame("'Alice','Bob','Charlie'", (string) $result);
    }

    public function testEscapeCSVMixedTypes(): void
    {
        $result = DB::escapeCSV([1, 'two', 3.5]);
        $this->assertSame("1,'two',3.5", (string) $result);
    }

    public function testEscapeCSVFloats(): void
    {
        $result = DB::escapeCSV([1.5, 2.5, 3.5]);
        $this->assertSame('1.5,2.5,3.5', (string) $result);
    }

    //endregion
    //region Empty and Special Cases

    public function testEscapeCSVEmptyArrayReturnsNull(): void
    {
        $result = DB::escapeCSV([]);
        $this->assertSame('NULL', (string) $result);
    }

    public function testEscapeCSVSingleElement(): void
    {
        $result = DB::escapeCSV([42]);
        $this->assertSame('42', (string) $result);
    }

    public function testEscapeCSVDeduplicates(): void
    {
        $result = DB::escapeCSV([1, 2, 2, 3, 3, 3]);
        $this->assertSame('1,2,3', (string) $result);
    }

    //endregion
    //region Null and Boolean

    public function testEscapeCSVWithNulls(): void
    {
        $result = DB::escapeCSV([1, null, 3]);
        $this->assertSame('1,NULL,3', (string) $result);
    }

    public function testEscapeCSVWithBooleans(): void
    {
        $result = DB::escapeCSV([true, false]);
        $this->assertSame('TRUE,FALSE', (string) $result);
    }

    public function testEscapeCSVWithMixedNullsAndBooleans(): void
    {
        // Note: array_unique with booleans/nulls may behave unexpectedly
        $result = DB::escapeCSV([true, null, false, 2]);
        $this->assertSame('TRUE,NULL,2', (string) $result);
    }

    //endregion
    //region String Escaping

    public function testEscapeCSVWithSpecialCharsInStrings(): void
    {
        $result = DB::escapeCSV(["O'Brien", 'Smith']);
        $this->assertSame("'O\\'Brien','Smith'", (string) $result);
    }

    public function testEscapeCSVWithDoubleQuotes(): void
    {
        $result = DB::escapeCSV(['Say "Hello"', 'World']);
        $this->assertSame("'Say \\\"Hello\\\"','World'", (string) $result);
    }

    //endregion
    //region SmartString

    public function testEscapeCSVWithSmartString(): void
    {
        $result = DB::escapeCSV([new SmartString('Alice'), new SmartString('Bob')]);
        $this->assertSame("'Alice','Bob'", (string) $result);
    }

    public function testEscapeCSVWithMixedSmartString(): void
    {
        $result = DB::escapeCSV([1, new SmartString('two'), 3]);
        $this->assertSame("1,'two',3", (string) $result);
    }

    //endregion
    //region Return Type

    public function testEscapeCSVReturnsRawSql(): void
    {
        $result = DB::escapeCSV([1, 2, 3]);
        $this->assertInstanceOf(RawSql::class, $result);
    }

    public function testEscapeCSVCanBeUsedInQuery(): void
    {
        // Test that the result can be used directly in a query via placeholder
        $csv = DB::escapeCSV([1, 2, 3]);
        $result = DB::query("SELECT * FROM ::users WHERE num IN (?)", $csv);
        $this->assertCount(3, $result);
    }

    //endregion
    //region Error Conditions

    public function testEscapeCSVBeforeConnectionThrows(): void
    {
        $conn = new \Itools\ZenDB\Connection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("called before DB connection established");

        $conn->escapeCSV([1, 2, 3]);
    }

    public function testEscapeCSVWithUnsupportedTypeThrows(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("could not be converted to string");

        DB::escapeCSV([1, new \stdClass(), 3]);
    }

    public function testEscapeCSVWithNestedArrayFails(): void
    {
        // array_unique with nested arrays triggers "Array to string conversion" warning
        // Then later throws InvalidArgumentException for unsupported type 'array'
        // We need to suppress the warning to see the actual exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported value type: array");

        @DB::escapeCSV([[1, 2], [3, 4]]);
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider provideEscapeCSVScenarios
     */
    public function testEscapeCSVScenarios(array $input, string $expected): void
    {
        $result = DB::escapeCSV($input);
        $this->assertSame($expected, (string) $result);
    }

    public static function provideEscapeCSVScenarios(): array
    {
        return [
            'integers'       => [[1, 2, 3], '1,2,3'],
            'strings'        => [['a', 'b', 'c'], "'a','b','c'"],
            'mixed'          => [[1, 'two', 3], "1,'two',3"],
            'empty'          => [[], 'NULL'],
            'single int'     => [[42], '42'],
            'single string'  => [['test'], "'test'"],
            'with null'      => [[1, null, 2], '1,NULL,2'],
            'with bool'      => [[true, false], 'TRUE,FALSE'],
            'duplicates'     => [[1, 1, 2], '1,2'],
            'floats'         => [[1.1, 2.2], '1.1,2.2'],
        ];
    }

    //endregion
}
