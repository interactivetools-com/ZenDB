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
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTestTables();  // test_employees is a regular table, so it can appear twice in one query
    }

    public function testSelfJoinBasic(): void
    {
        $result = DB::query(
            "SELECT e.name as employee_name, m.name as manager_name
             FROM ::employees e
             LEFT JOIN ::employees m ON e.manager_id = m.id
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
             FROM ::employees e
             LEFT JOIN ::employees m ON e.manager_id = m.id
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
             FROM ::employees emp
             LEFT JOIN ::employees mgr ON emp.manager_id = mgr.id
             LEFT JOIN ::employees ceo ON mgr.manager_id = ceo.id
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
             FROM ::employees e
             LEFT JOIN ::employees m ON e.manager_id = m.id
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
             FROM ::employees e
             JOIN ::employees m ON e.manager_id = m.id
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
             FROM ::employees e
             LEFT JOIN ::employees m ON e.manager_id = m.id
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
             FROM ::employees e
             LEFT JOIN ::employees m ON e.manager_id = m.id
             WHERE e.manager_id IS NULL"
        );

        $this->assertCount(1, $result);
        $this->assertSame('CEO', $result->first()->get('name')->value());
        $this->assertNull($result->first()->get('manager_name')->value());
    }

    public function testSelfJoinWithSelectStar(): void
    {
        // e.* and m.* produce duplicate column names; the aliased m.name was no duplicate at all
        $result = DB::query(
            "SELECT e.*, m.*
             FROM ::employees e
             LEFT JOIN ::employees m ON e.manager_id = m.id
             WHERE e.id = ?",
            4
        );

        $row = $result->first();

        // First occurrence (e) wins for plain keys; the manager stays reachable via its alias
        $this->assertSame(4, $row->get('id')->value());
        $this->assertSame('Developer 1', $row->get('name')->value());
        $this->assertSame('VP Engineering', $row->get('m.name')->value());
    }

    //endregion
}
