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

    public function testNumericStringWarnsAndWorks(): void
    {
        $result = @DB::select('users', '5');

        $this->assertCount(1, self::$warnings);
        $this->assertStringContainsString("Numeric string '5'", self::$warnings[0]);
        $this->assertCount(1, $result);
        $this->assertSame('Charlie Brown', $result->first()->get('name')->value());
    }

    public function testNumericStringWithSpacesWarnsAndWorks(): void
    {
        $result = @DB::select('users', '  5  ');

        $this->assertCount(1, self::$warnings);
        $this->assertCount(1, $result);
        $this->assertSame('Charlie Brown', $result->first()->get('name')->value());
    }

    public function testNumericStringInSelectOneWarnsAndWorks(): void
    {
        $result = @DB::selectOne('users', '5');

        $this->assertCount(1, self::$warnings);
        $this->assertFalse($result->isEmpty());
        $this->assertSame('Charlie Brown', $result->get('name')->value());
    }

    public function testNumericStringInCountWarnsAndWorks(): void
    {
        $count = @DB::count('users', '1');

        $this->assertSame(1, $count);
        $this->assertCount(1, self::$warnings);
    }

    public function testNumericStringInUpdateWarnsAndWorks(): void
    {
        self::resetTestTables();

        @DB::update('users', ['city' => 'String Where City'], '1');

        $this->assertStringContainsString('Numeric string', implode("\n", self::$warnings));

        // Verify update worked
        $row = DB::selectOne('users', ['num' => 1]);
        $this->assertSame('String Where City', $row->get('city')->value());
    }

    public function testNumericStringInDeleteWarnsAndWorks(): void
    {
        // Insert a row to delete
        $insertId = DB::insert('users', ['name' => 'To Delete Str', 'status' => 'Active', 'city' => 'Test']);

        self::$warnings = [];
        @DB::delete('users', (string)$insertId);

        $this->assertStringContainsString('Numeric string', implode("\n", self::$warnings));

        // Verify delete worked
        $result = DB::selectOne('users', ['num' => $insertId]);
        $this->assertTrue($result->isEmpty());
    }

    public function testNumericStringWarningSuggestsCastAndArraySyntax(): void
    {
        @DB::select('users', '5');

        $this->assertCount(1, self::$warnings);
        $this->assertStringContainsString('(int)', self::$warnings[0]);
        $this->assertStringContainsString('array syntax', self::$warnings[0]);
    }

    public function testNumericStringDoesNotAlsoLogDeprecation(): void
    {
        @DB::select('users', '5');

        $this->assertCount(0, self::$deprecations);
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
