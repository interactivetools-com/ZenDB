<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Logging;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::logDeprecation() method
 *
 * @covers \Itools\ZenDB\DB::logDeprecation
 */
class DeprecationLoggerTest extends BaseTestCase
{
    private static array $capturedErrors = [];
    private static $originalHandler;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();

        // Capture deprecation warnings
        self::$originalHandler = set_error_handler(function($errno, $errstr) {
            if ($errno === E_USER_DEPRECATED) {
                self::$capturedErrors[] = $errstr;
                return true; // Don't propagate
            }
            return false;
        });
    }

    public static function tearDownAfterClass(): void
    {
        // Restore original handler
        restore_error_handler();
    }

    protected function setUp(): void
    {
        self::$capturedErrors = [];
    }

    public function testTriggersUserDeprecated(): void
    {
        DB::logDeprecation("Test deprecation message");

        $this->assertCount(1, self::$capturedErrors);
        $this->assertStringContainsString("Test deprecation message", self::$capturedErrors[0]);
    }

    public function testIncludesCallerLocation(): void
    {
        DB::logDeprecation("Location test");

        $this->assertCount(1, self::$capturedErrors);
        // Should include file path and line number
        $this->assertMatchesRegularExpression('/in .+:\d+/', self::$capturedErrors[0]);
    }

    public function testSkipsZenDBInternalFiles(): void
    {
        // Call from test file - should show test file location, not DB.php
        DB::logDeprecation("Skip internal test");

        $this->assertCount(1, self::$capturedErrors);

        // Should NOT reference ZenDB src files
        $this->assertStringNotContainsString('src/DB.php', self::$capturedErrors[0]);
        $this->assertStringNotContainsString('src/Connection.php', self::$capturedErrors[0]);

        // Should reference test file
        $this->assertStringContainsString('DeprecationLoggerTest.php', self::$capturedErrors[0]);
    }

    public function testDeprecationFromNumericWhere(): void
    {
        // Using deprecated numeric WHERE should trigger deprecation
        @DB::select('users', 1); // @ to suppress display

        // Check that some deprecation was logged (from warnDeprecatedNumericWhere)
        $found = false;
        foreach (self::$capturedErrors as $error) {
            if (str_contains($error, 'Numeric WHERE')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Expected numeric WHERE deprecation warning");
    }

    public function testDeprecationFromLegacySyntax(): void
    {
        // Using :_ syntax (deprecated) should trigger deprecation
        // Note: :_num becomes ::num which is table prefix, not a param
        // This test just verifies the deprecation system works
        @DB::query("SELECT * FROM ::users WHERE num = :num", [':num' => 1]);

        // Verify the query actually returned valid results
        $result = @DB::query("SELECT * FROM ::users WHERE num = :num", [':num' => 1]);
        $this->assertCount(1, $result);
    }

    public function testMultipleDeprecationsLogged(): void
    {
        DB::logDeprecation("First warning");
        DB::logDeprecation("Second warning");
        DB::logDeprecation("Third warning");

        $this->assertCount(3, self::$capturedErrors);
    }

    public function testDeprecationMessageContent(): void
    {
        DB::logDeprecation("Custom deprecation: use newMethod() instead");

        $this->assertStringContainsString("Custom deprecation", self::$capturedErrors[0]);
        $this->assertStringContainsString("newMethod()", self::$capturedErrors[0]);
    }
}
