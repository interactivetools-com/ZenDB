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
    private static bool $originalForcePolyfill;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::$originalForcePolyfill = MysqliWrapper::$forcePolyfill;
    }

    public static function tearDownAfterClass(): void
    {
        MysqliWrapper::$forcePolyfill = self::$originalForcePolyfill;
        MysqliStmtWrapper::enableTestResultPolyfill(false);
    }

    public function testExecuteWithErrorLogsAndThrows(): void
    {
        MysqliWrapper::$forcePolyfill = true;

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
        MysqliWrapper::$forcePolyfill = true;
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
        MysqliWrapper::$forcePolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test3");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test3 (id INT, name VARCHAR(50))");
        $conn->mysqli->query("INSERT INTO stmt_test3 VALUES (1, 'test')");

        $stmt = $conn->mysqli->prepare("SELECT id, name FROM stmt_test3");
        $stmt->execute();
        $result = $stmt->get_result();

        $this->assertInstanceOf(MysqliResultPolyfill::class, $result);

        // Call __get directly to bypass PHP's internal checks on the parent object
        $fieldCount = $result->__get('field_count');
        $this->assertSame(2, $fieldCount);
    }

    public function testPolyfillNumRowsViaDirectCall(): void
    {
        MysqliWrapper::$forcePolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test4");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test4 (id INT)");
        $conn->mysqli->query("INSERT INTO stmt_test4 VALUES (1), (2), (3)");

        $stmt = $conn->mysqli->prepare("SELECT * FROM stmt_test4");
        $stmt->execute();
        $result = $stmt->get_result();

        $this->assertInstanceOf(MysqliResultPolyfill::class, $result);

        // Call __get directly
        $numRows = $result->__get('num_rows');
        $this->assertSame(3, $numRows);
    }

    public function testPolyfillInvalidPropertyThrows(): void
    {
        MysqliWrapper::$forcePolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS stmt_test5");
        $conn->mysqli->query("CREATE TEMPORARY TABLE stmt_test5 (id INT)");
        $conn->mysqli->query("INSERT INTO stmt_test5 VALUES (1)");

        $stmt = $conn->mysqli->prepare("SELECT * FROM stmt_test5");
        $stmt->execute();
        $result = $stmt->get_result();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Property invalid_property is not accessible");

        // Call __get directly for invalid property
        $result->__get('invalid_property');
    }

    public function testPolyfillUnimplementedMethodThrows(): void
    {
        MysqliWrapper::$forcePolyfill = true;
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

        // Call __call directly to test unimplemented method handling
        $result->__call('data_seek', [0]);
    }

    public function testPolyfillFetchArrayWithNoFields(): void
    {
        // Test the edge case where fieldObjects is empty (non-SELECT query)
        MysqliWrapper::$forcePolyfill = true;
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
