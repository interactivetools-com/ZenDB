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

        // Create permanent tables for verify-mode tests
        DB::$mysqli->query("DROP TABLE IF EXISTS test_verify_base");
        DB::$mysqli->query("CREATE TABLE test_verify_base (id INT PRIMARY KEY)");
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::$mysqli->query("DROP TABLE IF EXISTS test_verify_base");
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    //endregion
    //region Tests

    /**
     * @dataProvider provideGetBaseTableScenarios
     */
    public function testGetBaseTable(string $input, bool $verify, string $expected): void
    {
        $result = DB::getBaseTable($input, $verify);
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
        // [input, verify, expected output]
        return [
            // Without prefix - returned as-is, verify has no effect
            'no prefix returns as-is'                         => ['users',              false, 'users'],
            'no prefix with verify returns as-is'             => ['users',              true,  'users'],
            'different prefix returns as-is'                  => ['cms_users',          false, 'cms_users'],
            'different prefix with verify returns as-is'      => ['cms_users',          true,  'cms_users'],
            'empty string returns as-is'                      => ['',                   false, ''],
            'empty string with verify returns as-is'          => ['',                   true,  ''],

            // With prefix, non-verify - strips prefix without validation
            'strips prefix'                                   => ['test_users',         false, 'users'],
            'strips prefix with multiple underscores'         => ['test_order_details', false, 'order_details'],
            'strips prefix leaving empty string'              => ['test_',              false, ''],
            'strips prefix preserving leading underscore'     => ['test__private',      false, '_private'],
            'strips prefix for nonexistent table'             => ['test_nonexistent',   false, 'nonexistent'],

            // With prefix, verify - validates table exists before stripping
            'verify keeps name when table exists'             => ['test_verify_base',   true,  'test_verify_base'],
            'verify keeps temp table when it exists'          => ['test_users',         true,  'test_users'],
            'verify strips prefix when table does not exist'  => ['test_nonexistent',   true,  'nonexistent'],
            'verify strips prefix-only when not exists'       => ['test_',              true,  ''],
        ];
    }

    //endregion
}
