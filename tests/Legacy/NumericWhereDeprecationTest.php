<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Legacy;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for deprecated numeric WHERE clause handling
 *
 * @covers \Itools\ZenDB\ConnectionInternals::whereFromArgs
 */
class NumericWhereDeprecationTest extends BaseTestCase
{
    private static array $deprecations = [];
    private static array $warnings     = [];

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTestTables();

        // Capture deprecation and warning messages
        set_error_handler(function($errno, $errstr) {
            if ($errno === E_USER_DEPRECATED) {
                self::$deprecations[] = $errstr;
                return true;
            }
            if ($errno === E_USER_WARNING) {
                self::$warnings[] = $errstr;
                return true;
            }
            return false;
        });
    }

    public static function tearDownAfterClass(): void
    {
        restore_error_handler();
    }

    protected function setUp(): void
    {
        self::$deprecations = [];
        self::$warnings     = [];
    }

    //region Integer WHERE

    public function testIntegerWhereTriggersDeprecation(): void
    {
        DB::select('users', 5);

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('Numeric WHERE', self::$deprecations[0]);
        $this->assertStringContainsString('deprecated', self::$deprecations[0]);
    }

    public function testIntegerWhereStillWorks(): void
    {
        $result = @DB::select('users', 1);

        $this->assertCount(1, $result);
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testIntegerWhereInSelectOne(): void
    {
        $result = @DB::selectOne('users', 5);

        $this->assertFalse($result->isEmpty());
        $this->assertSame('Charlie Brown', $result->get('name')->value());
    }

    public function testIntegerWhereInUpdate(): void
    {
        self::resetTestTables();

        @DB::update('users', ['city' => 'Int Where City'], 1);

        $this->assertStringContainsString('Numeric WHERE', implode("\n", self::$deprecations));

        // Verify update worked
        $row = DB::selectOne('users', ['num' => 1]);
        $this->assertSame('Int Where City', $row->get('city')->value());
    }

    public function testIntegerWhereInDelete(): void
    {
        // Insert a row to delete
        $insertId = DB::insert('users', ['name' => 'To Delete Int', 'status' => 'Active', 'city' => 'Test']);

        self::$deprecations = [];
        @DB::delete('users', $insertId);

        $this->assertStringContainsString('Numeric WHERE', implode("\n", self::$deprecations));

        // Verify delete worked
        $result = DB::selectOne('users', ['num' => $insertId]);
        $this->assertTrue($result->isEmpty());
    }

    public function testIntegerWhereInCount(): void
    {
        $count = @DB::count('users', 1);

        $this->assertSame(1, $count);

        $this->assertStringContainsString('Numeric WHERE', implode("\n", self::$deprecations));
    }

    public function testIntegerWhereDoesNotAlsoLogWarning(): void
    {
        @DB::select('users', 5);

        $this->assertCount(0, self::$warnings);
    }

    //endregion
    //region Numeric String WHERE

    public function testNumericStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Numeric string `5` used as record number");

        DB::select('users', '5');
    }

    public function testNumericStringWithSpacesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Numeric string `5` used as record number");

        DB::select('users', '  5  ');
    }

    public function testNumericStringInSelectOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Numeric string `5` used as record number");

        DB::selectOne('users', '5');
    }

    public function testNumericStringInCountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Numeric string `1` used as record number");

        DB::count('users', '1');
    }

    public function testNumericStringInUpdateThrowsAndChangesNothing(): void
    {
        self::resetTestTables();

        try {
            DB::update('users', ['city' => 'String Where City'], '1');
            $this->fail('Expected InvalidArgumentException for a numeric string');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Numeric string `1`', $e->getMessage());
        }

        $this->assertSame(0, DB::count('users', ['city' => 'String Where City']));
    }

    public function testNumericStringInDeleteThrowsAndDeletesNothing(): void
    {
        self::resetTestTables();
        $rowsBefore = DB::count('users');

        try {
            DB::delete('users', '1');
            $this->fail('Expected InvalidArgumentException for a numeric string');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Numeric string `1`', $e->getMessage());
        }

        $this->assertSame($rowsBefore, DB::count('users'));
    }

    public function testNumericStringErrorSuggestsCastAndArraySyntax(): void
    {
        try {
            DB::select('users', '5');
            $this->fail('Expected InvalidArgumentException for a numeric string');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('(int)', $e->getMessage());
            $this->assertStringContainsString('array syntax', $e->getMessage());
        }

        $this->assertCount(0, self::$deprecations);
        $this->assertCount(0, self::$warnings);
    }

    //endregion
    //region Primary Key Not Named num

    public function testIntegerWhereOnTableKeyedById(): void
    {
        $row = @DB::selectOne('employees', 2);

        $this->assertSame('VP Engineering', $row->name->value());
    }

    public function testIntegerWhereOnTableKeyedByOrderId(): void
    {
        $row = @DB::selectOne('orders', 3);

        $this->assertSame('2023-08-15', $row->order_date->value());
    }

    public function testIntegerWhereInUpdateOnTableKeyedById(): void
    {
        self::resetTestTables();

        $changed = @DB::update('employees', ['department' => 'Int Where Dept'], 4);

        $this->assertSame(1, $changed);
        $this->assertSame('Int Where Dept', DB::selectOne('employees', ['id' => 4])->department->value());
        $this->assertSame(1, DB::count('employees', ['department' => 'Int Where Dept']));
    }

    public function testIntegerWhereInDeleteOnTableKeyedById(): void
    {
        self::resetTestTables();

        $deleted = @DB::delete('employees', 6);

        $this->assertSame(1, $deleted);
        $this->assertTrue(DB::selectOne('employees', ['id' => 6])->isEmpty());
        $this->assertSame(5, DB::count('employees'));
    }

    public function testIntegerWhereOnTableWithoutIntegerKeyThrows(): void
    {
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_rowid_none (code VARCHAR(10) PRIMARY KEY, title VARCHAR(50))");

        try {
            @DB::selectOne('rowid_none', 1);
            $this->fail('Expected the server to refuse `_rowid` on a table with no integer key');
        } catch (\mysqli_sql_exception $e) {
            $this->assertStringContainsString("Unknown column '_rowid'", $e->getMessage());
        } finally {
            DB::$mysqli->query("DROP TEMPORARY TABLE test_rowid_none");
        }
    }

    //endregion
    //region Modern Alternatives

    public function testArrayWhereDoesNotTriggerDeprecation(): void
    {
        DB::select('users', ['num' => 5]);

        $this->assertCount(0, self::$deprecations);
        $this->assertCount(0, self::$warnings);
    }

    public function testStringWhereWithPlaceholderDoesNotTriggerDeprecation(): void
    {
        DB::select('users', 'num = ?', 5);

        $this->assertCount(0, self::$deprecations);
        $this->assertCount(0, self::$warnings);
    }

    //endregion
    //region Deprecation Message Content

    public function testDeprecationMessageSuggestsArraySyntax(): void
    {
        @DB::select('users', 1);

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('array syntax', self::$deprecations[0]);
    }

    //endregion
}
