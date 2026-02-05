<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpDeprecationInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Legacy;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\RawSql;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for deprecated static methods via __callStatic
 *
 * @covers \Itools\ZenDB\DB::__callStatic
 */
class DeprecatedMethodsTest extends BaseTestCase
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

    //region DB::like() / DB::escapeLikeWildcards()

    public function testLikeMethodWorks(): void
    {
        $result = @DB::like('100%');
        $this->assertSame('100\%', $result);
    }

    public function testLikeMethodTriggersDeprecation(): void
    {
        DB::like('test');

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('DB::like()', self::$deprecations[0]);
        $this->assertStringContainsString('deprecated', self::$deprecations[0]);
    }

    public function testEscapeLikeWildcardsMethodWorks(): void
    {
        $result = @DB::escapeLikeWildcards('a%b_c');
        $this->assertSame('a\%b\_c', $result);
    }

    public function testEscapeLikeWildcardsTriggersDeprecation(): void
    {
        DB::escapeLikeWildcards('test');

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('deprecated', self::$deprecations[0]);
    }

    //endregion
    //region DB::identifier()

    public function testIdentifierMethodWorks(): void
    {
        $result = @DB::identifier('users');
        $this->assertInstanceOf(RawSql::class, $result);
        $this->assertSame('`users`', (string) $result);
    }

    public function testIdentifierMethodTriggersDeprecation(): void
    {
        DB::identifier('table');

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('DB::identifier()', self::$deprecations[0]);
        $this->assertStringContainsString('deprecated', self::$deprecations[0]);
    }

    //endregion
    //region DB::getTablePrefix()

    public function testGetTablePrefixMethodWorks(): void
    {
        $result = @DB::getTablePrefix();
        $this->assertSame('test_', $result);
    }

    public function testGetTablePrefixTriggersDeprecation(): void
    {
        DB::getTablePrefix();

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('DB::getTablePrefix()', self::$deprecations[0]);
        $this->assertStringContainsString('deprecated', self::$deprecations[0]);
    }

    //endregion
    //region DB::raw()

    public function testRawMethodWorks(): void
    {
        $result = @DB::raw('NOW()');
        $this->assertInstanceOf(RawSql::class, $result);
        $this->assertSame('NOW()', (string) $result);
    }

    public function testRawMethodTriggersDeprecation(): void
    {
        DB::raw('CURRENT_TIMESTAMP');

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('DB::raw()', self::$deprecations[0]);
        $this->assertStringContainsString('deprecated', self::$deprecations[0]);
    }

    //endregion
    //region DB::datetime()

    public function testDatetimeMethodWorks(): void
    {
        $result = @DB::datetime();
        // Should return current datetime in Y-m-d H:i:s format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    public function testDatetimeMethodTriggersDeprecation(): void
    {
        DB::datetime();

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('DB::datetime()', self::$deprecations[0]);
        $this->assertStringContainsString('deprecated', self::$deprecations[0]);
    }

    public function testDatetimeWithTimestamp(): void
    {
        $timestamp = strtotime('2024-01-15 12:30:45');
        $result = @DB::datetime($timestamp);
        $this->assertSame('2024-01-15 12:30:45', $result);
    }

    public function testDatetimeWithZeroTimestamp(): void
    {
        $result = @DB::datetime(0);
        // Timestamp 0 = 1970-01-01 00:00:00 (adjusted for timezone)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    //endregion
    //region Unknown Method

    public function testUnknownMethodThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown static method: nonExistentMethod");

        /** @noinspection PhpUndefinedMethodInspection */
        DB::nonExistentMethod();
    }

    public function testUnknownMethodWithArgsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown static method");

        /** @noinspection PhpUndefinedMethodInspection */
        DB::unknownMethod('arg1', 'arg2');
    }

    //endregion
    //region Case Insensitivity

    public function testMethodNamesCaseInsensitive(): void
    {
        // lowercase
        $result1 = @DB::like('test');
        $this->assertSame('test', $result1);

        // uppercase
        $result2 = @DB::LIKE('test');
        $this->assertSame('test', $result2);

        // mixed case
        $result3 = @DB::LiKe('test');
        $this->assertSame('test', $result3);
    }

    //endregion
}
