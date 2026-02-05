<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\ValueTypes;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for boolean value handling
 *
 * @covers \Itools\ZenDB\ConnectionInternals::getPlaceholderValue
 */
class BooleanValuesTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Boolean to Integer Conversion

    public function testTrueBecomesOne(): void
    {
        $result = DB::query("SELECT ? as value", true);
        $this->assertSame(1, $result->first()->get('value')->value());
    }

    public function testFalseBecomesZero(): void
    {
        $result = DB::query("SELECT ? as value", false);
        $this->assertSame(0, $result->first()->get('value')->value());
    }

    //endregion
    //region Boolean in INSERT

    public function testBooleanInInsert(): void
    {
        $insertId = DB::insert('users', [
            'name' => 'Boolean Test User',
            'isAdmin' => true,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame(1, $row->get('isAdmin')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    public function testFalseInInsert(): void
    {
        $insertId = DB::insert('users', [
            'name' => 'Boolean False User',
            'isAdmin' => false,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame(0, $row->get('isAdmin')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    //endregion
    //region Boolean in WHERE

    public function testBooleanInWhereArray(): void
    {
        // isAdmin = true should match isAdmin = 1
        $result = DB::select('users', ['isAdmin' => true]);

        foreach ($result as $row) {
            $this->assertSame(1, $row->get('isAdmin')->value());
        }
    }

    public function testFalseInWhereArray(): void
    {
        // isAdmin = false should match isAdmin = 0
        $result = DB::select('users', ['isAdmin' => false]);

        foreach ($result as $row) {
            $this->assertSame(0, $row->get('isAdmin')->value());
        }
    }

    public function testBooleanInPlaceholder(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE isAdmin = ?", true);

        $this->assertCount(8, $result);
        foreach ($result as $row) {
            $this->assertSame(1, $row->get('isAdmin')->value());
        }
    }

    //endregion
    //region Boolean in UPDATE

    public function testBooleanInUpdateSet(): void
    {
        $insertId = DB::insert('users', [
            'name' => 'Boolean Update Test',
            'isAdmin' => 0,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        // Update with boolean true
        DB::update('users', ['isAdmin' => true], ['num' => $insertId]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame(1, $row->get('isAdmin')->value());

        // Update with boolean false
        DB::update('users', ['isAdmin' => false], ['num' => $insertId]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame(0, $row->get('isAdmin')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    //endregion
    //region Boolean Escape

    public function testBooleanInEscapef(): void
    {
        $true = DB::escapef("value = ?", true);
        $this->assertSame("value = TRUE", $true);

        $false = DB::escapef("value = ?", false);
        $this->assertSame("value = FALSE", $false);
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider provideBooleanScenarios
     */
    public function testBooleanScenarios(bool $input, int $expectedDbValue): void
    {
        $insertId = DB::insert('users', [
            'name' => 'Bool Scenario Test',
            'isAdmin' => $input,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame($expectedDbValue, $row->get('isAdmin')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    public static function provideBooleanScenarios(): array
    {
        return [
            'true becomes 1'  => [true, 1],
            'false becomes 0' => [false, 0],
        ];
    }

    //endregion
}
