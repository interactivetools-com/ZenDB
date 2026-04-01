<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\SmartFeatures;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for self-join alias handling in Smart Joins
 *
 * @covers \Itools\ZenDB\ConnectionInternals::fetchMappedRows
 */
class SelfJoinsTest extends BaseTestCase
{
    private static bool $permanentTablesCreated = false;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();

        // MySQL doesn't allow self-joins on TEMPORARY tables ("Can't reopen table" error)
        // So we need to use a permanent table for self-join tests
        // Use raw mysqli to bypass template validation for DDL with numbers
        if (!self::$permanentTablesCreated) {
            $mysqli = DB::$mysqli;
            $mysqli->query("DROP TABLE IF EXISTS test_employees_perm");
            $mysqli->query("CREATE TABLE test_employees_perm (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100),
                manager_id INT NULL,
                department VARCHAR(50)
            )");
            DB::insert('employees_perm', ['id' => 1, 'name' => 'CEO', 'manager_id' => null, 'department' => 'Executive']);
            DB::insert('employees_perm', ['id' => 2, 'name' => 'VP Engineering', 'manager_id' => 1, 'department' => 'Engineering']);
            DB::insert('employees_perm', ['id' => 3, 'name' => 'VP Sales', 'manager_id' => 1, 'department' => 'Sales']);
            DB::insert('employees_perm', ['id' => 4, 'name' => 'Developer 1', 'manager_id' => 2, 'department' => 'Engineering']);
            DB::insert('employees_perm', ['id' => 5, 'name' => 'Developer 2', 'manager_id' => 2, 'department' => 'Engineering']);
            DB::insert('employees_perm', ['id' => 6, 'name' => 'Sales Rep 1', 'manager_id' => 3, 'department' => 'Sales']);
            self::$permanentTablesCreated = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::$mysqli->query("DROP TABLE IF EXISTS test_employees_perm");
        } catch (\Exception) {
            // Ignore cleanup errors
        }
    }

    public function testSelfJoinBasic(): void
    {
        $result = DB::query(
            "SELECT e.name as employee_name, m.name as manager_name
             FROM ::employees_perm e
             LEFT JOIN ::employees_perm m ON e.manager_id = m.id
             WHERE e.id = ?",
            2
        );

        $row = $result->first();
        $this->assertSame('VP Engineering', $row->get('employee_name')->value());
        $this->assertSame('CEO', $row->get('manager_name')->value());
    }

    public function testAliasBasedNamesForSelfJoin(): void
    {
        // In self-joins, alias-based names (e.g., 'e.name', 'm.name') are important
        $result = DB::query(
            "SELECT e.id, e.name, m.id as manager_id_check, m.name as manager_name
             FROM ::employees_perm e
             LEFT JOIN ::employees_perm m ON e.manager_id = m.id
             WHERE e.id = ?",
            4
        );

        $row = $result->first();

        // Verify employee info
        $this->assertSame(4, $row->get('id')->value());
        $this->assertSame('Developer 1', $row->get('name')->value());

        // Verify manager info via alias
        $this->assertSame(2, $row->get('manager_id_check')->value());
        $this->assertSame('VP Engineering', $row->get('manager_name')->value());
    }

    public function testSelfJoinWithDifferentAliases(): void
    {
        // Use different aliases to distinguish the same table
        $result = DB::query(
            "SELECT
                emp.name as emp_name,
                mgr.name as mgr_name,
                ceo.name as ceo_name
             FROM ::employees_perm emp
             LEFT JOIN ::employees_perm mgr ON emp.manager_id = mgr.id
             LEFT JOIN ::employees_perm ceo ON mgr.manager_id = ceo.id
             WHERE emp.id = ?",
            4
        );

        $row = $result->first();

        // Developer 1 -> VP Engineering -> CEO
        $this->assertSame('Developer 1', $row->get('emp_name')->value());
        $this->assertSame('VP Engineering', $row->get('mgr_name')->value());
        $this->assertSame('CEO', $row->get('ceo_name')->value());
    }

    public function testSelfJoinAllEmployeesWithManagers(): void
    {
        $result = DB::query(
            "SELECT e.id, e.name, e.department, m.name as manager_name
             FROM ::employees_perm e
             LEFT JOIN ::employees_perm m ON e.manager_id = m.id
             ORDER BY e.id"
        );

        $this->assertCount(6, $result);

        // CEO has no manager
        $ceo = $result->first();
        $this->assertSame('CEO', $ceo->get('name')->value());
        $this->assertNull($ceo->get('manager_name')->value());

        // VP Engineering reports to CEO
        $vp = $result->nth(1);
        $this->assertSame('VP Engineering', $vp->get('name')->value());
        $this->assertSame('CEO', $vp->get('manager_name')->value());
    }

    public function testSelfJoinCountByManager(): void
    {
        // Count employees per manager
        $result = DB::query(
            "SELECT m.name as manager_name, COUNT(e.id) as direct_reports
             FROM ::employees_perm e
             JOIN ::employees_perm m ON e.manager_id = m.id
             GROUP BY m.id, m.name
             ORDER BY direct_reports DESC, m.id ASC"
        );

        $this->assertCount(3, $result);

        // Both CEO and VP Engineering have 2 direct reports
        // With ORDER BY m.id ASC as secondary sort, CEO (id=1) comes first
        $first = $result->first();
        $this->assertSame(2, (int) $first->get('direct_reports')->value());
        // CEO has 2: VP Engineering, VP Sales
        // VP Engineering has 2: Developer 1, Developer 2
    }

    public function testSelfJoinWithFilter(): void
    {
        // Find all employees in Engineering and their managers
        $result = DB::query(
            "SELECT e.name as employee, m.name as manager
             FROM ::employees_perm e
             LEFT JOIN ::employees_perm m ON e.manager_id = m.id
             WHERE e.department = ?
             ORDER BY e.id",
            'Engineering'
        );

        // VP Engineering, Developer 1, Developer 2
        $this->assertCount(3, $result);
    }

    //region Edge Cases

    public function testSelfJoinWithNoMatch(): void
    {
        // CEO has no manager
        $result = DB::query(
            "SELECT e.name, m.name as manager_name
             FROM ::employees_perm e
             LEFT JOIN ::employees_perm m ON e.manager_id = m.id
             WHERE e.manager_id IS NULL"
        );

        $this->assertCount(1, $result);
        $this->assertSame('CEO', $result->first()->get('name')->value());
        $this->assertNull($result->first()->get('manager_name')->value());
    }

    public function testSelfJoinWithSelectStar(): void
    {
        // This is tricky - both e.* and m.* will have same column names
        $result = DB::query(
            "SELECT e.*, m.name as manager_name
             FROM ::employees_perm e
             LEFT JOIN ::employees_perm m ON e.manager_id = m.id
             WHERE e.id = ?",
            4
        );

        $row = $result->first();

        // First occurrence wins for duplicate columns
        $this->assertSame(4, $row->get('id')->value());
        $this->assertSame('Developer 1', $row->get('name')->value());
        $this->assertSame('VP Engineering', $row->get('manager_name')->value());
    }

    //endregion
}
