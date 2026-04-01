<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Exception;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::hasTable() method
 *
 * @covers \Itools\ZenDB\Connection::hasTable
 */
class HasTableTest extends BaseTestCase
{
    //region Setup & Teardown

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();

        // Create permanent test table
        DB::$mysqli->query("DROP TABLE IF EXISTS test_exists_check");
        DB::$mysqli->query("CREATE TABLE test_exists_check (id INT PRIMARY KEY)");

        // Create a view
        DB::$mysqli->query("DROP VIEW IF EXISTS test_exists_view");
        DB::$mysqli->query("CREATE VIEW test_exists_view AS SELECT 1 AS val");

        // Create a temp table
        DB::$mysqli->query("CREATE TEMPORARY TABLE _temp_exists_check (id INT)");

        // Create a real table then shadow it with a temp table
        DB::$mysqli->query("DROP TABLE IF EXISTS test_exists_shadowed");
        DB::$mysqli->query("CREATE TABLE test_exists_shadowed (id INT PRIMARY KEY, extra INT)");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_exists_shadowed (id INT)");
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS _temp_exists_check");
            DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_exists_shadowed");
            DB::$mysqli->query("DROP TABLE IF EXISTS test_exists_shadowed");
            DB::$mysqli->query("DROP TABLE IF EXISTS test_exists_check");
            DB::$mysqli->query("DROP VIEW IF EXISTS test_exists_view");
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    //endregion
    //region Tests

    /**
     * @dataProvider provideHasTableScenarios
     */
    public function testHasTableScenarios(string $table, bool $isPrefixed, bool $expected): void
    {
        $result = DB::hasTable($table, $isPrefixed);
        $this->assertSame($expected, $result);
    }

    public function testWithDifferentPrefix(): void
    {
        $conn = DB::clone(['tablePrefix' => 'other_']);

        // test_exists_check exists but with wrong prefix
        $result = $conn->hasTable('exists_check');
        $this->assertFalse($result); // other_exists_check doesn't exist
    }

    public function testWithEmptyPrefix(): void
    {
        $conn = DB::clone(['tablePrefix' => '']);

        $result = $conn->hasTable('test_exists_check');
        $this->assertTrue($result);
    }

    //endregion
    //region Data Providers

    public static function provideHasTableScenarios(): array
    {
        // [table, isPrefixed, expected]
        return [
            // Base name (prefix added automatically)
            'base exists'                   => ['exists_check',        false, true],
            'base not exists'               => ['nonexistent_xyz',     false, false],
            'empty string'                  => ['',                    false, false],

            // Full table name (no prefix added)
            'full table exists'             => ['test_exists_check',   true,  true],
            'full table not exists'         => ['test_nonexistent',    true,  false],
            'base name as full table'       => ['exists_check',        true,  false],

            // Views
            'view detected'                 => ['exists_view',         false, true],
            'view with prefix detected'     => ['test_exists_view',    true,  true],

            // Temp tables
            'temp table detected'           => ['_temp_exists_check',  true,  true],

            // Temp shadowing real table - real table still detected
            'shadowed table still found'    => ['exists_shadowed',     false, true],

            // Wildcards treated as literals (not LIKE patterns)
            'percent is literal'            => ['nonexistent%table',   false, false],
            'underscore is literal'         => ['exists_check_',       false, false],

            // SQL injection attempt
            'injection returns false'       => ["' OR 1=1 --",        false, false],
        ];
    }

    //endregion
}
