<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\ValueTypes;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for numeric value handling (integers and floats)
 *
 * @covers \Itools\ZenDB\ConnectionInternals::getPlaceholderValue
 */
class NumericValuesTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Integer Handling

    public function testIntegerNotQuoted(): void
    {
        // Verify integer is not wrapped in quotes
        $sql = DB::escapef("value = ?", 42);
        $this->assertSame("value = 42", $sql);
    }

    public function testIntegerInWhere(): void
    {
        $result = DB::select('users', ['num' => 1]);
        $this->assertCount(1, $result);
        $this->assertSame(1, $result->first()->get('num')->value());
    }

    public function testIntegerInPlaceholder(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = ?", 5);
        $this->assertCount(1, $result);
        $this->assertSame(5, $result->first()->get('num')->value());
    }

    public function testNegativeInteger(): void
    {
        $sql = DB::escapef("value = ?", -42);
        $this->assertSame("value = -42", $sql);
    }

    public function testZeroInteger(): void
    {
        $sql = DB::escapef("value = ?", 0);
        $this->assertSame("value = 0", $sql);

        // Query test
        $result = DB::select('users', ['isAdmin' => 0]);
        $this->assertCount(8, $result);
    }

    public function testLargeInteger(): void
    {
        $large = PHP_INT_MAX;
        $sql = DB::escapef("value = ?", $large);
        $this->assertSame("value = $large", $sql);
    }

    //endregion
    //region Float Handling

    public function testFloatNotQuoted(): void
    {
        $sql = DB::escapef("value = ?", 3.14);
        $this->assertSame("value = 3.14", $sql);
    }

    public function testFloatInWhere(): void
    {
        $result = DB::query("SELECT * FROM ::orders WHERE total_amount > ?", 100.00);
        $this->assertCount(3, $result);
    }

    public function testNegativeFloat(): void
    {
        $sql = DB::escapef("value = ?", -3.14);
        $this->assertSame("value = -3.14", $sql);
    }

    public function testZeroFloat(): void
    {
        $sql = DB::escapef("value = ?", 0.0);
        $this->assertSame("value = 0", $sql);
    }

    public function testSmallFloat(): void
    {
        $small = 0.00001;
        $sql = DB::escapef("value = ?", $small);
        $this->assertSame("value = 1.0E-5", $sql);
    }

    //endregion
    //region Numeric Comparisons

    public function testNumericRangeQuery(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users WHERE age BETWEEN ? AND ?",
            [30, 40]
        );
        $this->assertCount(9, $result);

        foreach ($result as $row) {
            $age = $row->get('age')->value();
            $this->assertGreaterThanOrEqual(30, $age);
            $this->assertLessThanOrEqual(40, $age);
        }
    }

    public function testNumericComparisonOperators(): void
    {
        // Greater than
        $gt = DB::query("SELECT * FROM ::users WHERE age > ?", 40);
        foreach ($gt as $row) {
            $this->assertGreaterThan(40, $row->get('age')->value());
        }

        // Less than
        $lt = DB::query("SELECT * FROM ::users WHERE age < ?", 30);
        foreach ($lt as $row) {
            $this->assertLessThan(30, $row->get('age')->value());
        }
    }

    //endregion
    //region Numeric in INSERT/UPDATE

    public function testNumericInInsert(): void
    {
        $insertId = DB::insert('users', [
            'name' => 'Numeric Test',
            'age' => 99,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame(99, $row->get('age')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    public function testNumericInUpdate(): void
    {
        $insertId = DB::insert('users', [
            'name' => 'Numeric Update',
            'age' => 25,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        DB::update('users', ['age' => 50], ['num' => $insertId]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame(50, $row->get('age')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    public function testDecimalInInsert(): void
    {
        DB::insert('orders', [
            'user_id' => 1,
            'order_date' => '2024-01-01',
            'total_amount' => 123.45
        ]);

        $row = DB::get('orders', "total_amount = ?", 123.45);
        $this->assertSame('123.45', $row->get('total_amount')->value());

        // Clean up
        DB::delete('orders', "total_amount = ?", 123.45);
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider provideNumericScenarios
     */
    public function testNumericScenarios(mixed $value, string $expectedSql): void
    {
        $sql = DB::escapef("value = ?", $value);
        $this->assertSame($expectedSql, $sql);
    }

    public static function provideNumericScenarios(): array
    {
        return [
            'positive int'   => [42, "value = 42"],
            'negative int'   => [-42, "value = -42"],
            'zero int'       => [0, "value = 0"],
            'positive float' => [3.14, "value = 3.14"],
            'negative float' => [-3.14, "value = -3.14"],
            'zero float'     => [0.0, "value = 0"],
            'one'            => [1, "value = 1"],
        ];
    }

    //endregion
}
