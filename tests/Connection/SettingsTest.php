<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for Connection settings: useSmartJoins, showSqlInErrors, etc.
 */
class SettingsTest extends BaseTestCase
{
    protected static Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = self::createDefaultConnection();
        self::resetTempTestTables();
    }

    protected function tearDown(): void
    {
        // Reset settings after each test
        self::$conn->useSmartJoins   = true;
        self::$conn->useSmartStrings = true;
        self::$conn->showSqlInErrors = false;
    }

    //region useSmartJoins Setting

    public function testUseSmartJoinsDefaultValue(): void
    {
        $this->assertTrue(self::$conn->useSmartJoins);
    }

    public function testUseSmartJoinsCanBeDisabled(): void
    {
        self::$conn->useSmartJoins = false;
        $this->assertFalse(self::$conn->useSmartJoins);
    }

    //endregion
    //region useSmartStrings Setting

    public function testUseSmartStringsDefaultValue(): void
    {
        $this->assertTrue(self::$conn->useSmartStrings);
    }

    public function testUseSmartStringsCanBeDisabled(): void
    {
        self::$conn->useSmartStrings = false;
        $this->assertFalse(self::$conn->useSmartStrings);
    }

    //endregion
    //region showSqlInErrors Setting

    public function testShowSqlInErrorsDefaultValue(): void
    {
        $this->assertFalse(self::$conn->showSqlInErrors);
    }

    public function testShowSqlInErrorsCanBeEnabled(): void
    {
        self::$conn->showSqlInErrors = true;
        $this->assertTrue(self::$conn->showSqlInErrors);
    }

    //endregion
    //region tablePrefix Setting

    public function testTablePrefixDefaultValue(): void
    {
        $this->assertSame('test_', self::$conn->tablePrefix);
    }

    //endregion
    //region Settings Applied to Queries

    public function testSmartStringsEnabledReturnsSmartStringValues(): void
    {
        self::$conn->useSmartStrings = true;
        $result = self::$conn->get('users', ['num' => 1]);

        $this->assertInstanceOf(\Itools\SmartString\SmartString::class, $result->get('name'));
    }

    public function testSmartStringsCannotBeDisabledWithSmartArrayHtml(): void
    {
        // SmartArrayHtml requires useSmartStrings=true
        // This test verifies the expected exception is thrown
        self::$conn->useSmartStrings = false;

        $this->expectException(\InvalidArgumentException::class);
        self::$conn->get('users', ['num' => 1]);
    }

    //endregion
}
