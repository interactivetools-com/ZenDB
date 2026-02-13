<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::getFullTable() method
 *
 * @covers \Itools\ZenDB\Connection::getFullTable
 */
class GetFullTableTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();

        // Create permanent tables for strict-mode tests (temp tables are invisible to tableExists)
        DB::$mysqli->query("DROP TABLE IF EXISTS test_strict_full");
        DB::$mysqli->query("CREATE TABLE test_strict_full (id INT PRIMARY KEY)");
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::$mysqli->query("DROP TABLE IF EXISTS test_strict_full");
        } catch (\Exception) {
            // Ignore cleanup errors
        }
    }

    public function testAddsPrefix(): void
    {
        // users -> test_users
        $result = DB::getFullTable('users');
        $this->assertSame('test_users', $result);
    }

    public function testAlreadyPrefixedNotDoubled(): void
    {
        // test_users -> test_users (not test_test_users)
        $result = DB::getFullTable('test_users');
        $this->assertSame('test_users', $result);
    }

    public function testEmptyTableName(): void
    {
        // '' -> 'test_' (just the prefix)
        $result = DB::getFullTable('');
        $this->assertSame('test_', $result);
    }

    public function testWithUnderscoreTable(): void
    {
        // _private -> test__private
        $result = DB::getFullTable('_private');
        $this->assertSame('test__private', $result);
    }

    public function testStrictModeTempTableNotDetected(): void
    {
        // Temp tables are invisible to tableExists (uses INFORMATION_SCHEMA),
        // so strict mode can't find 'test_users' and falls through to prefix check
        $result = DB::getFullTable('users', true);
        $this->assertSame('test_users', $result);
    }

    public function testStrictModeNonExistingTable(): void
    {
        // With strict=true and table doesn't exist, still adds prefix
        $result = DB::getFullTable('nonexistent', true);
        $this->assertSame('test_nonexistent', $result);
    }

    public function testStrictModeAlreadyPrefixedTempTable(): void
    {
        // Temp table 'test_users' already has the prefix, detected by str_starts_with
        $result = DB::getFullTable('test_users', true);
        $this->assertSame('test_users', $result);
    }

    public function testStrictModeWithRealTable(): void
    {
        // Strict mode with a real (permanent) table.
        // Input 'strict_full' -> prefixed to 'test_strict_full' -> tableExists confirms it -> return prefixed
        $result = DB::getFullTable('strict_full', true);
        $this->assertSame('test_strict_full', $result);
    }

    public function testStrictModeAlreadyPrefixedRealTable(): void
    {
        // Input already has prefix 'test_strict_full' -> str_starts_with detects prefix -> return as-is
        $result = DB::getFullTable('test_strict_full', true);
        $this->assertSame('test_strict_full', $result);
    }

    public function testWithDifferentPrefix(): void
    {
        // Create connection with different prefix
        $conn = DB::clone(['tablePrefix' => 'cms_']);

        $result = $conn->getFullTable('pages');
        $this->assertSame('cms_pages', $result);

        // Already prefixed with cms_ stays same
        $result = $conn->getFullTable('cms_pages');
        $this->assertSame('cms_pages', $result);
    }

    public function testWithEmptyPrefix(): void
    {
        $conn = DB::clone(['tablePrefix' => '']);

        // No prefix to add
        $result = $conn->getFullTable('users');
        $this->assertSame('users', $result);
    }

    //region Data Provider

    /**
     * @dataProvider provideGetFullTableScenarios
     */
    public function testGetFullTableScenarios(string $input, string $expected): void
    {
        $result = DB::getFullTable($input);
        $this->assertSame($expected, $result);
    }

    public static function provideGetFullTableScenarios(): array
    {
        return [
            'base name'           => ['users', 'test_users'],
            'already prefixed'    => ['test_users', 'test_users'],
            'multiple underscores'=> ['order_details', 'test_order_details'],
            'empty'               => ['', 'test_'],
            'underscore table'    => ['_private', 'test__private'],
            'different prefix'    => ['cms_pages', 'test_cms_pages'], // treated as base name
        ];
    }

    //endregion
}
