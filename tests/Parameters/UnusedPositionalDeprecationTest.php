<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Parameters;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for the positional-value deprecations (both will throw in a future version):
 * - Positional values passed as a single array. Valid forms are up to 3 direct
 *   values for ? placeholders, or an array of named params.
 * - Direct positional values the query doesn't use (more values than ? placeholders).
 * A call only ever logs one of the two. Unused named parameters stay allowed.
 *
 * @covers \Itools\ZenDB\ConnectionInternals::parseParams
 * @covers \Itools\ZenDB\ConnectionInternals::replacePlaceholders
 */
class UnusedPositionalDeprecationTest extends BaseTestCase
{
    private static array $deprecations = [];

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();

        // Capture deprecation warnings
        set_error_handler(function ($errno, $errstr) {
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

    //region Positional values in an array warn

    public function testPositionalArrayWithMatchingCountTriggersDeprecation(): void
    {
        DB::query("SELECT * FROM ::users WHERE num = ? OR num = ?", [1, 2]);

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('Positional values in an array are deprecated', self::$deprecations[0]);
    }

    public function testArrayIntoSinglePlaceholderTriggersOneDeprecation(): void
    {
        DB::query("SELECT * FROM ::users WHERE num IN (?)", [1, 2, 3]);

        // The positional-array warning covers it; the unused-values check stays silent
        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('Positional values in an array are deprecated', self::$deprecations[0]);
    }

    public function testQueryStillRunsWithPositionalArray(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num IN (?)", [1, 2, 3]);

        $this->assertCount(1, $result);   // only the first value is used
    }

    //endregion
    //region Unused direct positional values warn

    public function testExtraDirectArgTriggersDeprecation(): void
    {
        DB::query("SELECT * FROM ::users WHERE num = ?", 1, 999);

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('1 positional (?) placeholder(s) but 2 values', self::$deprecations[0]);
        $this->assertStringContainsString("':ids' => [...]", self::$deprecations[0]);
    }

    //endregion
    //region Valid forms stay silent

    public function testDirectArgsNoDeprecation(): void
    {
        DB::query("SELECT * FROM ::users WHERE num = ? OR num = ?", 1, 2);

        $this->assertCount(0, self::$deprecations);
    }

    public function testEmptyParamsArrayNoDeprecation(): void
    {
        DB::query("SELECT * FROM ::users", []);

        $this->assertCount(0, self::$deprecations);
    }

    public function testUnusedNamedParamsNoDeprecation(): void
    {
        DB::query("SELECT * FROM ::users WHERE num = :num", [':num' => 1, ':unused' => 2]);

        $this->assertCount(0, self::$deprecations);
    }

    public function testNamedInListNoDeprecation(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids)", [':ids' => [1, 2, 3]]);

        $this->assertCount(0, self::$deprecations);
        $this->assertCount(3, $result);
    }

    //endregion
}
