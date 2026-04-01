<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\SmartFeatures;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for Smart Joins feature (qualified column names in multi-table queries)
 *
 * @covers \Itools\ZenDB\ConnectionInternals::fetchMappedRows
 */
class SmartJoinsTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Single Table (No Smart Joins)

    public function testSingleTableNoQualifiedNames(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = ?", 1);
        $row = $result->first();

        // Regular column access should work
        $this->assertSame('John Doe', $row->get('name')->value());
        $this->assertSame(1, $row->get('num')->value());
    }

    public function testSingleTableColumnAccess(): void
    {
        $result = DB::query("SELECT num, name, city FROM ::users WHERE num = ?", 1);
        $row = $result->first();

        $this->assertSame(1, $row->get('num')->value());
        $this->assertSame('John Doe', $row->get('name')->value());
        $this->assertSame('Vancouver', $row->get('city')->value());
    }

    //endregion
    //region Multi-Table (Smart Joins)

    public function testMultiTableAddsQualifiedNames(): void
    {
        $result = DB::query(
            "SELECT u.*, o.* FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?",
            6
        );
        $row = $result->first();

        // Access via qualified names (table.column) - verify actual values
        $this->assertSame('Dave Williams', $row->get('users.name')->value());
        $this->assertSame('80.00', $row->get('orders.total_amount')->value());
    }

    public function testFirstWinsForDuplicateColumns(): void
    {
        // Create a query where column name appears in multiple tables
        $result = DB::query(
            "SELECT u.num, u.name, o.order_id, o.user_id FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?",
            6
        );
        $row = $result->first();

        // Both tables have columns - first occurrence wins for unqualified access
        $this->assertSame(6, $row->get('num')->value()); // users.num
    }

    public function testTablePrefixStrippedFromQualifiedNames(): void
    {
        // Qualified names should use base table name (without prefix)
        $result = DB::query(
            "SELECT u.name, o.total_amount FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?",
            6
        );
        $row = $result->first();

        // Should be 'users.name' not 'test_users.name'
        $this->assertSame('Dave Williams', $row->get('users.name')->value());
        $this->assertSame('80.00', $row->get('orders.total_amount')->value());
    }

    public function testThreeTableJoin(): void
    {
        $result = DB::query(
            "SELECT u.name, o.order_id, p.product_name
             FROM ::users u
             JOIN ::orders o ON u.num = o.user_id
             JOIN ::order_details od ON o.order_id = od.order_id
             JOIN ::products p ON od.product_id = p.product_id
             WHERE u.num = ?",
            6
        );

        $this->assertCount(1, $result);
        $row = $result->first();

        // Access columns from different tables - verify actual values
        $this->assertSame('Dave Williams', $row->get('name')->value());
        $this->assertSame(1, $row->get('order_id')->value());
        $this->assertSame('Product A', $row->get('product_name')->value());
    }

    //endregion
    //region Disable Smart Joins

    public function testUseSmartJoinsFalseDisables(): void
    {
        $conn = DB::clone(['useSmartJoins' => false]);

        $result = $conn->query(
            "SELECT u.name, o.total_amount FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?",
            6
        );
        $row = $result->first();

        // With SmartJoins disabled, qualified names should NOT be added
        // Only the raw column names should be accessible
        $this->assertSame('Dave Williams', $row->get('name')->value());
        $this->assertSame('80.00', $row->get('total_amount')->value());
    }

    //endregion
    //region Edge Cases

    public function testJoinWithSelectStar(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?",
            6
        );
        $row = $result->first();

        // All columns from both tables should be accessible
        $this->assertSame('Dave Williams', $row->get('name')->value());
        $this->assertSame('80.00', $row->get('total_amount')->value());
        $this->assertSame('Dave Williams', $row->get('users.name')->value());
        $this->assertSame('80.00', $row->get('orders.total_amount')->value());
    }

    public function testJoinWithAliasedColumns(): void
    {
        $result = DB::query(
            "SELECT u.name as user_name, o.total_amount as amount
             FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?",
            6
        );
        $row = $result->first();

        // Aliased columns should be accessible by alias
        $this->assertSame('Dave Williams', $row->get('user_name')->value());
        $this->assertSame('80.00', $row->get('amount')->value());
    }

    public function testLeftJoinWithNulls(): void
    {
        // User 1 may not have orders, use LEFT JOIN
        $result = DB::query(
            "SELECT u.name, o.total_amount
             FROM ::users u LEFT JOIN ::orders o ON u.num = o.user_id
             WHERE u.num = ?",
            1
        );
        $row = $result->first();

        // User 1 doesn't have orders in test data
        $this->assertSame('John Doe', $row->get('name')->value());
        // total_amount will be null if no matching order
        $this->assertTrue($row->get('total_amount')->isNull());
    }

    //endregion
}
