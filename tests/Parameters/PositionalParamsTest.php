<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Parameters;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for positional parameter handling (? placeholders)
 *
 * @covers \Itools\ZenDB\Connection::query
 * @covers \Itools\ZenDB\ConnectionInternals::parseParams
 * @covers \Itools\ZenDB\ConnectionInternals::replacePlaceholders
 */
class PositionalParamsTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Basic Positional Parameters

    public function testSinglePositionalParam(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = ?", 1);
        $this->assertCount(1, $result);
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testTwoPositionalParams(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num >= ? AND num <= ?", 1, 3);
        $this->assertCount(3, $result);
    }

    public function testThreePositionalParams(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = ? OR num = ? OR num = ?", 1, 2, 3);
        $this->assertCount(3, $result);
    }

    public function testPositionalParamsViaArray(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = ?", [1]);
        $this->assertCount(1, $result);
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testMoreThanThreeViaArray(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = ? OR num = ? OR num = ? OR num = ?", [1, 2, 3, 4]);
        $this->assertCount(4, $result);
    }

    //endregion
    //region Edge Cases

    public function testPositionalParamWithNull(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE isAdmin IS NULL OR isAdmin = ?", null);
        // NULL = NULL is always false in SQL, but IS NULL should still work
        $this->assertCount(4, $result);
    }

    public function testPositionalParamWithEmptyString(): void
    {
        // Insert a record with empty string
        DB::insert('users', ['name' => '', 'status' => 'Active', 'city' => 'Test']);

        $result = DB::query("SELECT * FROM ::users WHERE name = ?", '');
        $this->assertCount(1, $result);

        // Clean up
        DB::delete('users', ['name' => '']);
    }

    public function testPositionalParamWithZero(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE isAdmin = ?", 0);
        $this->assertCount(8, $result);
        foreach ($result as $row) {
            $this->assertSame(0, $row->get('isAdmin')->value());
        }
    }

    public function testPositionalParamWithBoolean(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE isAdmin = ?", true);
        $this->assertCount(8, $result);
    }

    public function testPositionalParamWithFloat(): void
    {
        $result = DB::query("SELECT * FROM ::orders WHERE total_amount > ?", 100.00);
        $this->assertCount(3, $result);
    }

    //endregion
    //region Error Conditions

    public function testFourPositionalArgsWithoutArrayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Max 3 positional arguments allowed");

        DB::query("SELECT * FROM ::users WHERE num = ? OR num = ? OR num = ? OR num = ?", 1, 2, 3, 4);
    }

    public function testMissingPositionalParamThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing value for ? parameter at position 2");

        DB::query("SELECT * FROM ::users WHERE num = ? AND status = ?", 1);
    }

    public function testMixedArraysAndScalarsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must be either a single array or multiple non-array values");

        DB::query("SELECT * FROM ::users WHERE num = ?", [1], 2);
    }

    //endregion
    //region Data Provider Tests

    /**
     * @dataProvider providePositionalParamScenarios
     */
    public function testPositionalParamScenarios(string $description, string $sql, array $params, int $expectedCount): void
    {
        $result = DB::query($sql, ...$params);
        $this->assertSame($expectedCount, $result->count(), "Failed: $description");
    }

    public static function providePositionalParamScenarios(): array
    {
        return [
            'single integer match' => [
                'description'   => 'Single integer parameter matching one row',
                'sql'           => 'SELECT * FROM ::users WHERE num = ?',
                'params'        => [5],
                'expectedCount' => 1,
            ],
            'string comparison' => [
                'description'   => 'String parameter for name match',
                'sql'           => 'SELECT * FROM ::users WHERE name = ?',
                'params'        => ['John Doe'],
                'expectedCount' => 1,
            ],
            'multiple conditions same type' => [
                'description'   => 'Two integer parameters',
                'sql'           => 'SELECT * FROM ::users WHERE num >= ? AND num <= ?',
                'params'        => [1, 5],
                'expectedCount' => 5,
            ],
            'mixed type params' => [
                'description'   => 'Integer and string parameters',
                'sql'           => 'SELECT * FROM ::users WHERE num > ? AND status = ?',
                'params'        => [10, 'Active'],
                'expectedCount' => 5, // Users 11, 13, 15, 17, 19 are Active
            ],
            'no matches' => [
                'description'   => 'Parameters matching no rows',
                'sql'           => 'SELECT * FROM ::users WHERE num = ?',
                'params'        => [9999],
                'expectedCount' => 0,
            ],
        ];
    }

    //endregion
}
