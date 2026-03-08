<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Exception;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::getBaseTable() method
 *
 * @covers \Itools\ZenDB\Connection::getBaseTable
 */
class GetBaseTableTest extends BaseTestCase
{
    //region Setup & Teardown

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();

        // Create double-prefixed tables for checkDb-mode tests.
        // These simulate base names that happen to start with the prefix string.
        // e.g., base name "test_cities" with prefix "test_" becomes "test_test_cities"
        DB::$mysqli->query("DROP TABLE IF EXISTS test_test_cities");
        DB::$mysqli->query("CREATE TABLE test_test_cities (id INT PRIMARY KEY)");
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::$mysqli->query("DROP TABLE IF EXISTS test_test_cities");
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    //endregion
    //region Tests

    /**
     * @dataProvider provideGetBaseTableScenarios
     */
    public function testGetBaseTable(string $input, bool $checkDb, string $expected): void
    {
        $result = DB::getBaseTable($input, $checkDb);
        $this->assertSame($expected, $result);
    }

    public function testWithDifferentPrefix(): void
    {
        $conn = DB::clone(['tablePrefix' => 'cms_']);

        $result = $conn->getBaseTable('cms_pages');
        $this->assertSame('pages', $result);

        // Original prefix tables returned as-is
        $result = $conn->getBaseTable('test_users');
        $this->assertSame('test_users', $result);
    }

    public function testWithEmptyPrefix(): void
    {
        $conn = DB::clone(['tablePrefix' => '']);

        // Empty prefix means nothing to strip
        $result = $conn->getBaseTable('users');
        $this->assertSame('users', $result);

        $result = $conn->getBaseTable('test_users');
        $this->assertSame('test_users', $result);
    }

    //endregion
    //region Data Providers

    public static function provideGetBaseTableScenarios(): array
    {
        // [input, checkDb, expected output]
        return [
            // Without prefix - returned as-is, checkDb has no effect
            'no prefix returns as-is'                           => ['users',              false, 'users'],
            'no prefix with checkDb returns as-is'              => ['users',              true,  'users'],
            'different prefix returns as-is'                    => ['cms_users',          false, 'cms_users'],
            'different prefix with checkDb returns as-is'       => ['cms_users',          true,  'cms_users'],
            'empty string returns as-is'                        => ['',                   false, ''],
            'empty string with checkDb returns as-is'           => ['',                   true,  ''],

            // With prefix, no checkDb - strips prefix without validation
            'strips prefix'                                     => ['test_users',         false, 'users'],
            'strips prefix with multiple underscores'           => ['test_order_details', false, 'order_details'],
            'strips prefix leaving empty string'                => ['test_',              false, ''],
            'strips prefix preserving leading underscore'       => ['test__private',      false, '_private'],
            'strips prefix for nonexistent table'               => ['test_nonexistent',   false, 'nonexistent'],

            // With checkDb - checks if input is actually a base name whose prefixed table exists
            // e.g., "test_cities" with prefix "test_" checks if "test_test_cities" exists
            'checkDb keeps base name when prefixed table exists'  => ['test_cities',      true,  'test_cities'],
            'checkDb strips prefix when no double-prefixed table' => ['test_users',       true,  'users'],
            'checkDb strips prefix for nonexistent table'         => ['test_nonexistent', true,  'nonexistent'],
            'checkDb strips prefix-only when not exists'          => ['test_',            true,  ''],
        ];
    }

    //endregion
}
