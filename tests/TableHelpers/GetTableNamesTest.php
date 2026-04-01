<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Exception;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::getTableNames() method
 *
 * @covers \Itools\ZenDB\Connection::getTableNames
 */
class GetTableNamesTest extends BaseTestCase
{
    //region Setup & Teardown

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();

        // Create test tables
        DB::$mysqli->query("DROP TABLE IF EXISTS test_get_tables_a");
        DB::$mysqli->query("DROP TABLE IF EXISTS test_get_tables_b");
        DB::$mysqli->query("DROP TABLE IF EXISTS test__underscore");
        DB::$mysqli->query("CREATE TABLE test_get_tables_a (id INT PRIMARY KEY)");
        DB::$mysqli->query("CREATE TABLE test_get_tables_b (id INT PRIMARY KEY)");
        DB::$mysqli->query("CREATE TABLE test__underscore (id INT PRIMARY KEY)");

        // Create a view
        DB::$mysqli->query("DROP VIEW IF EXISTS test_get_tables_view");
        DB::$mysqli->query("CREATE VIEW test_get_tables_view AS SELECT 1 AS val");

        // Create a temp table
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_get_tables_temp (id INT)");

        // Create a real table then shadow it with a temp table
        DB::$mysqli->query("DROP TABLE IF EXISTS test_get_tables_shadowed");
        DB::$mysqli->query("CREATE TABLE test_get_tables_shadowed (id INT PRIMARY KEY)");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_get_tables_shadowed (id INT)");

        // Create a table without our prefix
        DB::$mysqli->query("DROP TABLE IF EXISTS other_table_xyz");
        DB::$mysqli->query("CREATE TABLE other_table_xyz (id INT)");
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_get_tables_temp");
            DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_get_tables_shadowed");
            DB::$mysqli->query("DROP TABLE IF EXISTS test_get_tables_shadowed");
            DB::$mysqli->query("DROP TABLE IF EXISTS test_get_tables_a");
            DB::$mysqli->query("DROP TABLE IF EXISTS test_get_tables_b");
            DB::$mysqli->query("DROP TABLE IF EXISTS test__underscore");
            DB::$mysqli->query("DROP VIEW IF EXISTS test_get_tables_view");
            DB::$mysqli->query("DROP TABLE IF EXISTS other_table_xyz");
            DB::$mysqli->query("DROP TABLE IF EXISTS cms_pages");
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    //endregion
    //region Tests

    /**
     * @dataProvider provideInclusionScenarios
     */
    public function testInclusionScenarios(string $baseName, bool $expectedInList): void
    {
        $tables = DB::getTableNames();

        if ($expectedInList) {
            $this->assertContains($baseName, $tables, "'$baseName' should be in getTableNames()");
        } else {
            $this->assertNotContains($baseName, $tables, "'$baseName' should NOT be in getTableNames()");
        }
    }

    public function testReturnsArray(): void
    {
        $tables = DB::getTableNames();
        $this->assertIsArray($tables);
    }

    public function testIncludePrefixOption(): void
    {
        $withPrefix    = DB::getTableNames(true);
        $withoutPrefix = DB::getTableNames(false);

        // Both should have same count
        $this->assertCount(count($withoutPrefix), $withPrefix);

        // With prefix: all entries start with test_
        foreach ($withPrefix as $table) {
            $this->assertStringStartsWith('test_', $table);
        }

        // Without prefix: base names returned
        $this->assertContains('get_tables_a', $withoutPrefix);
        $this->assertContains('get_tables_b', $withoutPrefix);
    }

    public function testSortsUnderscoreTablesToBottom(): void
    {
        $tables = DB::getTableNames();

        $underscoreIndex = array_search('_underscore', $tables);
        $aIndex          = array_search('get_tables_a', $tables);

        $this->assertNotFalse($underscoreIndex, '_underscore should be in list');
        $this->assertNotFalse($aIndex, 'get_tables_a should be in list');
        $this->assertGreaterThan($aIndex, $underscoreIndex, '_underscore should sort after get_tables_a');
    }

    public function testWithDifferentPrefix(): void
    {
        DB::$mysqli->query("DROP TABLE IF EXISTS cms_pages");
        DB::$mysqli->query("CREATE TABLE cms_pages (id INT)");

        $conn   = DB::clone(['tablePrefix' => 'cms_']);
        $tables = $conn->getTableNames();

        $this->assertContains('pages', $tables);
        $this->assertNotContains('get_tables_a', $tables);
    }

    public function testWithEmptyPrefix(): void
    {
        $conn   = DB::clone(['tablePrefix' => '']);
        $tables = $conn->getTableNames();

        // Should see all tables in the database
        $this->assertContains('test_get_tables_a', $tables);
        $this->assertContains('other_table_xyz', $tables);
    }

    //endregion
    //region Data Providers

    public static function provideInclusionScenarios(): array
    {
        // [baseName, expectedInList]
        return [
            // Tables with matching prefix - included
            'table a'             => ['get_tables_a', true],
            'table b'             => ['get_tables_b', true],
            'double underscore'   => ['_underscore', true],

            // Shadowed table - real table still listed
            'shadowed by temp'    => ['get_tables_shadowed', true],

            // Views - excluded (BASE TABLE only)
            'view excluded'       => ['get_tables_view', false],

            // Temp tables - excluded (not in INFORMATION_SCHEMA)
            'temp table excluded' => ['get_tables_temp', false],

            // Wrong prefix - excluded
            'wrong prefix'        => ['other_table_xyz', false],
        ];
    }

    //endregion
}
