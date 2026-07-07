<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\MysqliWrapper;

use Itools\ZenDB\Connection;
use Itools\ZenDB\MysqliResultPolyfill;
use Itools\ZenDB\MysqliStmtWrapper;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Additional tests for MysqliStmtWrapper
 */
class MysqliStmtWrapperTest extends BaseTestCase
{
    private static bool $originalForceExecuteQueryPolyfill;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::$originalForceExecuteQueryPolyfill = MysqliWrapper::$forceExecuteQueryPolyfill;
    }

    public static function tearDownAfterClass(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = self::$originalForceExecuteQueryPolyfill;
        MysqliStmtWrapper::enableTestResultPolyfill(false);
    }

    public function testExecuteWithErrorLogsAndThrows(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = true;

        $conn = new Connection(self::$configDefaults);

        // Create a valid statement first
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test (id INT PRIMARY KEY)");
        $conn->mysqli->query("INSERT INTO stmt_test VALUES (1)");

        $this->expectException(\mysqli_sql_exception::class);
        $this->expectExceptionMessage("Duplicate entry");

        // This will fail because we're inserting a duplicate key - exercises the catch block
        $conn->mysqli->execute_query("INSERT INTO stmt_test VALUES (?)", [1]);
    }

    public function testGetResultWithoutForcePolyfill(): void
    {
        // Test the normal path (not using polyfill)
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(false);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test2");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test2 (id INT)");
        $conn->mysqli->query("INSERT INTO stmt_test2 VALUES (1)");

        $result = $conn->mysqli->execute_query("SELECT * FROM stmt_test2");

        // Should NOT be MysqliResultPolyfill when forceResultPolyfill is false
        $this->assertNotInstanceOf(MysqliResultPolyfill::class, $result);
        $this->assertInstanceOf(\mysqli_result::class, $result);
    }

    public function testPolyfillFieldCountViaDirectCall(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test3");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test3 (id INT, name VARCHAR(50))");
        $conn->mysqli->query("INSERT INTO stmt_test3 VALUES (1, 'test')");

        $stmt = $conn->mysqli->prepare("SELECT id, name FROM stmt_test3");
        $stmt->execute();
        $result = $stmt->get_result();

        $this->assertInstanceOf(MysqliResultPolyfill::class, $result);
        $this->assertSame(2, $result->field_count);
    }

    public function testPolyfillNumRowsViaDirectCall(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test4");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test4 (id INT)");
        $conn->mysqli->query("INSERT INTO stmt_test4 VALUES (1), (2), (3)");

        $stmt = $conn->mysqli->prepare("SELECT * FROM stmt_test4");
        $stmt->execute();
        $result = $stmt->get_result();

        $this->assertInstanceOf(MysqliResultPolyfill::class, $result);
        $this->assertSame(3, $result->num_rows);
    }

    public function testPolyfillNumRowsIsZeroForEmptyResult(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test5");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test5 (id INT)");

        $stmt = $conn->mysqli->prepare("SELECT * FROM stmt_test5");
        $stmt->execute();
        $result = $stmt->get_result();

        $this->assertSame(0, $result->num_rows);
        $this->assertNull($result->fetch_assoc());
    }

    public function testPolyfillDataSeek(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test8");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test8 (id INT)");
        $conn->mysqli->query("INSERT INTO stmt_test8 VALUES (1), (2), (3)");

        $stmt = $conn->mysqli->prepare("SELECT id FROM stmt_test8 ORDER BY id");
        $stmt->execute();
        $result = $stmt->get_result();

        $this->assertTrue($result->data_seek(2));
        $this->assertSame([3], $result->fetch_row());
        $this->assertTrue($result->data_seek(0));
        $this->assertSame([1], $result->fetch_row());
        $this->assertFalse($result->data_seek(99));  // out of range, like native
    }

    public function testDbQuerySelectWorksWithForcedPolyfill(): void
    {
        // Guards the fetchMappedRows() result-type check: if the polyfill stopped being
        // accepted there, every SELECT on PHP 8.1 without mysqlnd would return zero rows
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $rows = $conn->query("SELECT ? AS a UNION SELECT ?", 1, 2);
        $this->assertCount(2, $rows);
    }

    public function testPolyfillUnimplementedMethodThrows(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test6");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test6 (id INT)");
        $conn->mysqli->query("INSERT INTO stmt_test6 VALUES (1)");

        $stmt = $conn->mysqli->prepare("SELECT * FROM stmt_test6");
        $stmt->execute();
        $result = $stmt->get_result();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage("not implemented in polyfill");

        $result->fetch_column();
    }

    public function testPolyfillFetchArrayWithNoFields(): void
    {
        // Test the edge case where fieldObjects is empty (non-SELECT query)
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test7");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test7 (id INT)");

        // Prepare an INSERT statement (no result set, no fields)
        $stmt = $conn->mysqli->prepare("INSERT INTO stmt_test7 VALUES (?)");
        $stmt->execute([1]);

        // get_result on INSERT returns the polyfill but with no fields
        $result = $stmt->get_result();

        if ($result instanceof MysqliResultPolyfill) {
            // fetch_array should return null when there are no fields
            $row = $result->fetch_array();
            $this->assertNull($row);
        } else {
            // If it's false, that's also acceptable for non-SELECT
            $this->assertFalse($result);
        }
    }
}
