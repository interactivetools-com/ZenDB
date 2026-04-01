<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Exception;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::getFullTable() method
 *
 * @covers \Itools\ZenDB\Connection::getFullTable
 */
class GetFullTableTest extends BaseTestCase
{
    //region Setup & Teardown

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();

        // Create permanent table for checkDb-mode tests
        DB::$mysqli->query("DROP TABLE IF EXISTS test_verify_full");
        DB::$mysqli->query("CREATE TABLE test_verify_full (id INT PRIMARY KEY)");
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::$mysqli->query("DROP TABLE IF EXISTS test_verify_full");
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    //endregion
    //region Tests

    /**
     * @dataProvider provideGetFullTableScenarios
     */
    public function testGetFullTable(string $input, bool $checkDb, string $expected): void
    {
        $result = DB::getFullTable($input, $checkDb);
        $this->assertSame($expected, $result);
    }

    public function testWithDifferentPrefix(): void
    {
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

    //endregion
    //region Data Providers

    public static function provideGetFullTableScenarios(): array
    {
        // [input, checkDb, expected output]
        return [
            // Without checkDb - adds prefix or returns already-prefixed as-is
            'adds prefix'                                        => ['users',             false, 'test_users'],
            'already prefixed not doubled'                       => ['test_users',        false, 'test_users'],
            'adds prefix with multiple underscores'              => ['order_details',     false, 'test_order_details'],
            'empty table name'                                   => ['',                  false, 'test_'],
            'adds prefix to underscore table'                    => ['_private',          false, 'test__private'],
            'different prefix treated as base name'              => ['cms_pages',         false, 'test_cms_pages'],

            // With checkDb - checks database when input starts with prefix
            'checkDb adds prefix for unprefixed name'            => ['users',             true,  'test_users'],
            'checkDb keeps existing table as-is'                 => ['test_verify_full',  true,  'test_verify_full'],
            'checkDb keeps existing temp table as-is'            => ['test_users',        true,  'test_users'],
            'checkDb adds prefix when prefixed name not found'   => ['test_nonexistent',  true,  'test_test_nonexistent'],
            'checkDb adds prefix for nonexistent unprefixed'     => ['nonexistent',       true,  'test_nonexistent'],
            'checkDb adds prefix for prefix-only when not found' => ['test_',             true,  'test_test_'],
        ];
    }

    //endregion
}
