<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::getBaseTable() method
 *
 * @covers \Itools\ZenDB\Connection::getBaseTable
 */
class GetBaseTableTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    public function testStripsPrefix(): void
    {
        // test_users -> users
        $result = DB::getBaseTable('test_users');
        $this->assertSame('users', $result);
    }

    public function testWithoutPrefixReturnsUnchanged(): void
    {
        // 'users' without prefix returns as-is
        $result = DB::getBaseTable('users');
        $this->assertSame('users', $result);
    }

    public function testStripsPrefixFromMultipleUnderscores(): void
    {
        // test_order_details -> order_details
        $result = DB::getBaseTable('test_order_details');
        $this->assertSame('order_details', $result);
    }

    public function testEmptyTableName(): void
    {
        $result = DB::getBaseTable('');
        $this->assertSame('', $result);
    }

    public function testPrefixOnlyReturnsEmpty(): void
    {
        // test_ -> '' (empty after stripping prefix)
        $result = DB::getBaseTable('test_');
        $this->assertSame('', $result);
    }

    public function testStrictModeExistingTable(): void
    {
        // With strict=true, the logic checks if the base table exists
        // If the table starts with prefix and the base (with prefix) exists, return as-is
        // But test_users is a TEMPORARY table so it won't be found
        $result = DB::getBaseTable('test_users', true);
        // Without the table existing, it just strips the prefix
        $this->assertSame('users', $result);
    }

    public function testStrictModeNonExistingTableStripsPrefix(): void
    {
        // Non-existing table with strict=true, strips prefix
        $result = DB::getBaseTable('test_nonexistent', true);
        $this->assertSame('nonexistent', $result);
    }

    public function testTableNameNotStartingWithPrefix(): void
    {
        $result = DB::getBaseTable('other_table');
        $this->assertSame('other_table', $result);
    }

    public function testWithDifferentPrefix(): void
    {
        // Create connection with different prefix
        $conn = DB::clone(['tablePrefix' => 'cms_']);

        $result = $conn->getBaseTable('cms_pages');
        $this->assertSame('pages', $result);

        // Original prefix tables unaffected
        $result = $conn->getBaseTable('test_users');
        $this->assertSame('test_users', $result);
    }

    //region Data Provider

    /**
     * @dataProvider provideGetBaseTableScenarios
     */
    public function testGetBaseTableScenarios(string $input, string $expected): void
    {
        $result = DB::getBaseTable($input);
        $this->assertSame($expected, $result);
    }

    public static function provideGetBaseTableScenarios(): array
    {
        return [
            'with prefix'         => ['test_users', 'users'],
            'without prefix'      => ['users', 'users'],
            'multiple underscores'=> ['test_order_details', 'order_details'],
            'empty'               => ['', ''],
            'prefix only'         => ['test_', ''],
            'different prefix'    => ['cms_users', 'cms_users'], // not matching
            'underscore table'    => ['test__private', '_private'],
        ];
    }

    //endregion
}
