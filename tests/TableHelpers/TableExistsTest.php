<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::tableExists() method
 *
 * Note: tableExists() queries INFORMATION_SCHEMA.TABLES with TABLE_TYPE = 'BASE TABLE',
 * so temporary tables are not detected. We test with permanent tables.
 *
 * @covers \Itools\ZenDB\Connection::tableExists
 */
class TableExistsTest extends BaseTestCase
{
    private static bool $permanentTablesCreated = false;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();

        // Create permanent test tables for tableExists tests
        if (!self::$permanentTablesCreated) {
            DB::query("DROP TABLE IF EXISTS test_exists_check");
            DB::query("CREATE TABLE test_exists_check (id INT PRIMARY KEY)");
            self::$permanentTablesCreated = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up permanent tables
        try {
            DB::query("DROP TABLE IF EXISTS test_exists_check");
        } catch (\Exception) {
            // Ignore cleanup errors
        }
    }

    public function testExistingTableBaseName(): void
    {
        // 'exists_check' exists as test_exists_check
        $result = DB::tableExists('exists_check');
        $this->assertTrue($result);
    }

    public function testExistingTableFullName(): void
    {
        // test_exists_check exists
        $result = DB::tableExists('test_exists_check', true);
        $this->assertTrue($result);
    }

    public function testNonExistingTable(): void
    {
        $result = DB::tableExists('nonexistent_table_xyz');
        $this->assertFalse($result);
    }

    public function testNonExistingTableFullName(): void
    {
        $result = DB::tableExists('test_nonexistent_xyz', true);
        $this->assertFalse($result);
    }

    public function testIsFullTableOptionTrue(): void
    {
        // With isFullTable=true, doesn't add prefix
        $result = DB::tableExists('exists_check', true);
        $this->assertFalse($result); // 'exists_check' without prefix doesn't exist
    }

    public function testIsFullTableOptionFalse(): void
    {
        // With isFullTable=false (default), adds prefix
        $result = DB::tableExists('exists_check', false);
        $this->assertTrue($result); // test_exists_check exists
    }

    public function testEmptyTableName(): void
    {
        // Empty string with prefix becomes 'test_'
        $result = DB::tableExists('');
        $this->assertFalse($result);
    }

    public function testSpecialCharsInTableName(): void
    {
        // Table names with special characters that don't exist should return false
        // Note: If LIKE wildcards are not escaped, 'foo%' might incorrectly match tables
        // But this test uses a pattern that shouldn't match any table
        $result = DB::tableExists('nonexistent%table');
        $this->assertFalse($result);
    }

    //region With Clone

    public function testWithDifferentPrefix(): void
    {
        $conn = DB::clone(['tablePrefix' => 'other_']);

        // test_exists_check exists but with wrong prefix
        $result = $conn->tableExists('exists_check');
        $this->assertFalse($result); // other_exists_check doesn't exist
    }

    public function testWithEmptyPrefix(): void
    {
        $conn = DB::clone(['tablePrefix' => '']);

        // Check if 'test_exists_check' exists as full table
        $result = $conn->tableExists('test_exists_check');
        $this->assertTrue($result);
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider provideTableExistsScenarios
     */
    public function testTableExistsScenarios(string $table, bool $isFullTable, bool $expected): void
    {
        $result = DB::tableExists($table, $isFullTable);
        $this->assertSame($expected, $result);
    }

    public static function provideTableExistsScenarios(): array
    {
        return [
            'base exists'        => ['exists_check', false, true],
            'base not exists'    => ['nonexistent_xyz', false, false],
            'full exists'        => ['test_exists_check', true, true],
            'full not exists'    => ['test_nonexistent_xyz', true, false],
            'base as full'       => ['exists_check', true, false], // 'exists_check' without prefix
        ];
    }

    //endregion
}
