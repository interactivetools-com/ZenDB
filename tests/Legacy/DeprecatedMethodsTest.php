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

    //region DB::like() / DB::escapeLikeWildcards() - removed

    public function testLikeMethodThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has been removed');
        DB::like('100%');
    }

    public function testEscapeLikeWildcardsMethodThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has been removed');
        DB::escapeLikeWildcards('a%b_c');
    }

    //endregion
    //region DB::get() - deprecated, use selectOne()

    public function testGetMethodWorks(): void
    {
        $result = @DB::get('users', ['num' => 1]);
        $this->assertSame('John Doe', $result->get('name')->value());
    }

    public function testGetMethodTriggersDeprecation(): void
    {
        DB::get('users', ['num' => 1]);

        $this->assertCount(1, self::$deprecations);
        $this->assertStringContainsString('DB::get()', self::$deprecations[0]);
        $this->assertStringContainsString('deprecated', self::$deprecations[0]);
        $this->assertStringContainsString('selectOne()', self::$deprecations[0]);
    }

    public function testGetRejectsLimitLikeSelectOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        @DB::get('users', 'LIMIT 5');
    }

    //endregion
    //region DB::identifier() - removed for security

    public function testIdentifierMethodThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB::identifier() has been removed');

        DB::identifier('users');
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
        // All case variations should resolve to the same method
        $result1 = @DB::raw('NOW()');
        $result2 = @DB::RAW('NOW()');
        $result3 = @DB::RaW('NOW()');

        $this->assertSame((string)$result1, (string)$result2);
        $this->assertSame((string)$result2, (string)$result3);
    }

    //endregion
}
