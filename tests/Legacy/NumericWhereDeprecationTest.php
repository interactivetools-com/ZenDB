<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Legacy;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for deprecated numeric WHERE clause handling
 *
 * @covers \Itools\ZenDB\ConnectionInternals::warnDeprecatedNumericWhere
 */
class NumericWhereDeprecationTest extends BaseTestCase
{
    private static array $deprecations = [];

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();

        // Capture deprecation warnings
        set_error_handler(function($errno, $errstr) {
            if ($errno === E_USER_DEPRECATED) {
                self::$deprecations[] = $errstr;
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
        self::resetTempTestTables();

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

    //endregion
    //region Numeric String WHERE

    public function testNumericStringThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Numeric string");

        DB::select('users', '5');
    }

    public function testNumericStringWithSpacesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Numeric string");

        DB::select('users', '  5  ');
    }

    public function testNumericStringInUpdateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Numeric string");

        DB::update('users', ['city' => 'Test'], '1');
    }

    public function testNumericStringInDeleteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Numeric string");

        DB::delete('users', '1');
    }

    //endregion
    //region Modern Alternatives

    public function testArrayWhereDoesNotTriggerDeprecation(): void
    {
        DB::select('users', ['num' => 5]);

        // Should not have deprecation warnings
        $this->assertStringNotContainsString('Numeric WHERE', implode("\n", self::$deprecations));
    }

    public function testStringWhereWithPlaceholderDoesNotTriggerDeprecation(): void
    {
        DB::select('users', 'num = ?', 5);

        // Should not have deprecation warnings
        $this->assertStringNotContainsString('Numeric WHERE', implode("\n", self::$deprecations));
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
