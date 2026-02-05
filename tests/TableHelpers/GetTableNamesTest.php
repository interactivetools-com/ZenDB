<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::getTableNames() method
 *
 * Note: TEMPORARY tables don't show up in SHOW TABLES, so we test with
 * permanent test tables.
 *
 * @covers \Itools\ZenDB\Connection::getTableNames
 */
class GetTableNamesTest extends BaseTestCase
{
    private static bool $permanentTablesCreated = false;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();

        // Create permanent test tables for getTableNames tests
        if (!self::$permanentTablesCreated) {
            DB::query("DROP TABLE IF EXISTS test_gettables_a");
            DB::query("DROP TABLE IF EXISTS test_gettables_b");
            DB::query("DROP TABLE IF EXISTS test__underscore");
            DB::query("CREATE TABLE test_gettables_a (id INT PRIMARY KEY)");
            DB::query("CREATE TABLE test_gettables_b (id INT PRIMARY KEY)");
            DB::query("CREATE TABLE test__underscore (id INT PRIMARY KEY)");
            self::$permanentTablesCreated = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up permanent tables
        try {
            DB::query("DROP TABLE IF EXISTS test_gettables_a");
            DB::query("DROP TABLE IF EXISTS test_gettables_b");
            DB::query("DROP TABLE IF EXISTS test__underscore");
            DB::query("DROP TABLE IF EXISTS cms_pages");
        } catch (\Exception) {
            // Ignore cleanup errors
        }
    }

    public function testReturnsBaseNames(): void
    {
        $tables = DB::getTableNames();

        // Should contain base names without prefix
        $this->assertContains('gettables_a', $tables);
        $this->assertContains('gettables_b', $tables);
    }

    public function testIncludePrefixOption(): void
    {
        $tables = DB::getTableNames(true);

        // Should contain full names with prefix
        $this->assertContains('test_gettables_a', $tables);
        $this->assertContains('test_gettables_b', $tables);
    }

    public function testSortsUnderscoreTablesToBottom(): void
    {
        $tables = DB::getTableNames();

        // _underscore should be near the end
        $underscoreIndex = array_search('_underscore', $tables);
        $aIndex = array_search('gettables_a', $tables);

        if ($underscoreIndex !== false && $aIndex !== false) {
            $this->assertGreaterThan($aIndex, $underscoreIndex, '_underscore should come after gettables_a');
        } else {
            // At least verify underscore table is present
            $this->assertContains('_underscore', $tables);
        }
    }

    public function testFiltersNonPrefixedTables(): void
    {
        // Create a table without our prefix
        DB::query("DROP TABLE IF EXISTS other_table_xyz");
        DB::query("CREATE TABLE other_table_xyz (id INT)");

        $tables = DB::getTableNames();

        // other_table_xyz should NOT appear (doesn't have test_ prefix)
        $this->assertNotContains('other_table_xyz', $tables);

        // Clean up
        DB::query("DROP TABLE IF EXISTS other_table_xyz");
    }

    public function testReturnsArray(): void
    {
        $tables = DB::getTableNames();
        $this->assertIsArray($tables);
    }

    public function testWithIncludePrefixReturnsFullNames(): void
    {
        $withPrefix = DB::getTableNames(true);
        $withoutPrefix = DB::getTableNames(false);

        // Both should have same count
        $this->assertCount(count($withoutPrefix), $withPrefix);

        // Each withPrefix entry should start with test_
        foreach ($withPrefix as $table) {
            $this->assertStringStartsWith('test_', $table);
        }
    }

    //region With Clone

    public function testWithDifferentPrefix(): void
    {
        // Create a table with different prefix
        DB::query("DROP TABLE IF EXISTS cms_pages");
        DB::query("CREATE TABLE cms_pages (id INT)");

        $conn = DB::clone(['tablePrefix' => 'cms_']);
        $tables = $conn->getTableNames();

        $this->assertContains('pages', $tables);
        $this->assertNotContains('gettables_a', $tables); // test_ prefixed tables excluded

        // Clean up
        DB::query("DROP TABLE IF EXISTS cms_pages");
    }

    //endregion
}
