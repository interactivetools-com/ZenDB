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

    public function testStrictModeExistingTable(): void
    {
        // With strict=true and table exists, returns prefixed name
        $result = DB::getFullTable('users', true);
        $this->assertSame('test_users', $result);
    }

    public function testStrictModeNonExistingTable(): void
    {
        // With strict=true and table doesn't exist, still adds prefix
        $result = DB::getFullTable('nonexistent', true);
        $this->assertSame('test_nonexistent', $result);
    }

    public function testStrictModeAlreadyPrefixed(): void
    {
        // If already prefixed and exists, returns as-is
        $result = DB::getFullTable('test_users', true);
        $this->assertSame('test_users', $result);
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
