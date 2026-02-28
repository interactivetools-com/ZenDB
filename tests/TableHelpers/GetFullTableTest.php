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

        // Create permanent table for verify-mode tests
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
    public function testGetFullTable(string $input, bool $verify, string $expected): void
    {
        $result = DB::getFullTable($input, $verify);
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
        // [input, verify, expected output]
        return [
            // Without verify - adds prefix or returns already-prefixed as-is
            'adds prefix'                                      => ['users',             false, 'test_users'],
            'already prefixed not doubled'                     => ['test_users',        false, 'test_users'],
            'adds prefix with multiple underscores'            => ['order_details',     false, 'test_order_details'],
            'empty table name'                                 => ['',                  false, 'test_'],
            'adds prefix to underscore table'                  => ['_private',          false, 'test__private'],
            'different prefix treated as base name'            => ['cms_pages',         false, 'test_cms_pages'],

            // With verify - checks database when input starts with prefix
            'verify adds prefix for unprefixed name'           => ['users',             true,  'test_users'],
            'verify keeps existing table as-is'                => ['test_verify_full',  true,  'test_verify_full'],
            'verify keeps existing temp table as-is'           => ['test_users',        true,  'test_users'],
            'verify adds prefix when prefixed name not found'  => ['test_nonexistent',  true,  'test_test_nonexistent'],
            'verify adds prefix for nonexistent unprefixed'    => ['nonexistent',       true,  'test_nonexistent'],
            'verify adds prefix for prefix-only when not found'=> ['test_',             true,  'test_test_'],
        ];
    }

    //endregion
}
