<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\MysqliWrapper;

use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\MysqliStmtWrapper;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli_sql_exception;

/**
 * Tests for MysqliWrapper class
 *
 * @covers \Itools\ZenDB\MysqliWrapper
 */
class MysqliWrapperTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region lastQuery

    public function testLastQuerySet(): void
    {
        DB::query("SELECT * FROM ::users LIMIT 1");

        $this->assertStringContainsString('SELECT', DB::$mysqli->lastQuery);
        $this->assertStringContainsString('test_users', DB::$mysqli->lastQuery);
    }

    public function testLastQueryUpdatedOnEachQuery(): void
    {
        DB::query("SELECT * FROM ::users LIMIT 1");
        $first = DB::$mysqli->lastQuery;

        DB::query("SELECT * FROM ::orders LIMIT 1");
        $second = DB::$mysqli->lastQuery;

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('orders', $second);
    }

    public function testLastQueryOnInsert(): void
    {
        DB::insert('users', ['name' => 'LastQuery Test', 'status' => 'Active', 'city' => 'Test']);

        $this->assertStringContainsString('INSERT', DB::$mysqli->lastQuery);
        $this->assertStringContainsString('LastQuery Test', DB::$mysqli->lastQuery);

        // Clean up
        DB::delete('users', ['name' => 'LastQuery Test']);
    }

    public function testLastQueryOnUpdate(): void
    {
        self::resetTempTestTables();

        DB::update('users', ['city' => 'Query Test City'], ['num' => 1]);

        $this->assertStringContainsString('UPDATE', DB::$mysqli->lastQuery);
        $this->assertStringContainsString('Query Test City', DB::$mysqli->lastQuery);
    }

    public function testLastQueryOnDelete(): void
    {
        // Insert first
        $id = DB::insert('users', ['name' => 'Delete Query Test', 'status' => 'Active', 'city' => 'Test']);

        DB::delete('users', ['num' => $id]);

        $this->assertStringContainsString('DELETE', DB::$mysqli->lastQuery);
    }

    //endregion
    //region lastQuery on early throw

    public static function lastQueryOnEarlyThrowProvider(): array
    {
        return [
            'query with quotes' => [
                fn() => DB::query("SELECT * FROM ::users WHERE city = 'Vancouver'"),
                "SELECT * FROM ::users WHERE city = 'Vancouver'",
            ],
            'query with number' => [
                fn() => DB::query("SELECT * FROM ::users WHERE num = 5"),
                "SELECT * FROM ::users WHERE num = 5",
            ],
            'select with quotes' => [
                fn() => DB::select('users', "city = 'Vancouver'"),
                "SELECT * FROM `test_users` WHERE city = 'Vancouver'",
            ],
            'select with number' => [
                fn() => DB::select('users', "num = 5"),
                "SELECT * FROM `test_users` WHERE num = 5",
            ],
        ];
    }

    /**
     * @dataProvider lastQueryOnEarlyThrowProvider
     */
    public function testLastQueryOnEarlyThrow(callable $action, string $expectedLastQuery): void
    {
        DB::query("SELECT * FROM ::users LIMIT 1"); // set a known lastQuery

        try {
            $action();
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame($expectedLastQuery, DB::$mysqli->lastQuery);
    }

    //endregion
    //region Query Logging

    public function testQueryLoggingCallback(): void
    {
        $logs = [];
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$logs) {
                $logs[] = compact('query', 'duration', 'error');
            }
        ]));

        // Create temp table and query it
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_logger_cb");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_logger_cb (id INT)");
        $conn->query("SELECT * FROM ::logger_cb LIMIT 1");

        // Should have at least connect + query
        $this->assertGreaterThanOrEqual(2, count($logs));

        // Find the SELECT query log
        $selectLog = null;
        foreach ($logs as $log) {
            if (str_contains($log['query'], 'SELECT')) {
                $selectLog = $log;
                break;
            }
        }

        $this->assertNotNull($selectLog);
        $this->assertIsFloat($selectLog['duration']);
        $this->assertNull($selectLog['error']);
    }

    public function testRealQueryLogging(): void
    {
        $logs = [];
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$logs) {
                $logs[] = $query;
            }
        ]));

        // real_query is used internally for some operations
        // Trigger it via multi-statement or specific operations

        $conn->mysqli->real_query("SET @test = 1");

        $found = false;
        foreach ($logs as $log) {
            if (str_contains($log, 'real_query:')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    //endregion
    //region execute_query Native (PHP 8.2+)

    public function testNativeExecuteQuerySelect(): void
    {
        // Test native execute_query path (forcePolyfill=false, PHP 8.2+)
        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_native_eq");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_native_eq (id INT, name VARCHAR(50))");
        $conn->mysqli->query("INSERT INTO test_native_eq VALUES (1, 'Alice'), (2, 'Bob')");

        MysqliWrapper::$forceExecuteQueryPolyfill = false;
        $result = $conn->mysqli->execute_query("SELECT * FROM test_native_eq WHERE id = ?", [1]);

        $this->assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $this->assertSame('Alice', $row['name']);
    }

    public function testNativeExecuteQueryInsert(): void
    {
        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_native_eq2");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_native_eq2 (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))");

        MysqliWrapper::$forceExecuteQueryPolyfill = false;
        $result = $conn->mysqli->execute_query("INSERT INTO test_native_eq2 (name) VALUES (?)", ['Native Test']);

        $this->assertTrue($result);
        $this->assertSame(1, $conn->mysqli->insert_id);
    }

    //endregion
    //region execute_query Polyfill

    public function testForcePolyfillFlag(): void
    {
        // Save original state
        $original = MysqliWrapper::$forceExecuteQueryPolyfill;

        try {
            // Enable polyfill
            MysqliWrapper::$forceExecuteQueryPolyfill = true;

            $conn = new Connection(self::$configDefaults);
            // Create temp table and query it
            $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_polyfill");
            $conn->mysqli->query("CREATE TEMPORARY TABLE test_polyfill (id INT)");
            $result = $conn->mysqli->execute_query("SELECT * FROM test_polyfill LIMIT 1");

            $this->assertInstanceOf(\mysqli_result::class, $result);
        } finally {
            // Restore
            MysqliWrapper::$forceExecuteQueryPolyfill = $original;
        }
    }

    public function testForcePolyfillWithParams(): void
    {
        // Save original state
        $original = MysqliWrapper::$forceExecuteQueryPolyfill;

        try {
            // Enable polyfill - this exercises MysqliStmtWrapper
            MysqliWrapper::$forceExecuteQueryPolyfill = true;

            $conn = new Connection(self::$configDefaults);
            $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_polyfill_params");
            $conn->mysqli->query("CREATE TEMPORARY TABLE test_polyfill_params (id INT, name VARCHAR(50))");
            $conn->mysqli->query("INSERT INTO test_polyfill_params VALUES (1, 'Test')");

            // Execute with params - goes through MysqliStmtWrapper
            $result = $conn->mysqli->execute_query(
                "SELECT * FROM test_polyfill_params WHERE id = ?",
                [1]
            );

            $this->assertInstanceOf(\mysqli_result::class, $result);
            $row = $result->fetch_assoc();
            $this->assertSame('Test', $row['name']);
        } finally {
            MysqliWrapper::$forceExecuteQueryPolyfill = $original;
        }
    }

    public function testForcePolyfillInsert(): void
    {
        $original = MysqliWrapper::$forceExecuteQueryPolyfill;

        try {
            MysqliWrapper::$forceExecuteQueryPolyfill = true;

            $conn = new Connection(self::$configDefaults);
            $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_polyfill_insert");
            $conn->mysqli->query("CREATE TEMPORARY TABLE test_polyfill_insert (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))");

            // Insert with params - exercises MysqliStmtWrapper execute()
            $result = $conn->mysqli->execute_query(
                "INSERT INTO test_polyfill_insert (name) VALUES (?)",
                ['Polyfill Test']
            );

            $this->assertTrue($result);
            $this->assertSame(1, $conn->mysqli->insert_id);
        } finally {
            MysqliWrapper::$forceExecuteQueryPolyfill = $original;
        }
    }

    public function testForcePolyfillWithError(): void
    {
        $original = MysqliWrapper::$forceExecuteQueryPolyfill;

        try {
            MysqliWrapper::$forceExecuteQueryPolyfill = true;

            $conn = new Connection(self::$configDefaults);

            $this->expectException(mysqli_sql_exception::class);
            $this->expectExceptionMessage("doesn't exist");

            // This should fail and exercise MysqliStmtWrapper error handling
            $conn->mysqli->execute_query(
                "SELECT * FROM nonexistent_table_for_polyfill WHERE id = ?",
                [1]
            );
        } finally {
            MysqliWrapper::$forceExecuteQueryPolyfill = $original;
        }
    }

    public function testForcePolyfillMultipleRows(): void
    {
        $original = MysqliWrapper::$forceExecuteQueryPolyfill;

        try {
            MysqliWrapper::$forceExecuteQueryPolyfill = true;

            $conn = new Connection(self::$configDefaults);
            $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_polyfill_multi");
            $conn->mysqli->query("CREATE TEMPORARY TABLE test_polyfill_multi (id INT, val VARCHAR(20))");
            $conn->mysqli->query("INSERT INTO test_polyfill_multi VALUES (1, 'One'), (2, 'Two'), (3, 'Three')");

            $result = $conn->mysqli->execute_query(
                "SELECT * FROM test_polyfill_multi WHERE id > ?",
                [0]
            );

            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $this->assertCount(3, $rows);
        } finally {
            MysqliWrapper::$forceExecuteQueryPolyfill = $original;
        }
    }

    //endregion
    //region Prepare/Statement Wrapper

    public function testPrepareReturnsStmtWrapper(): void
    {
        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_prepare");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_prepare (id INT, name VARCHAR(50))");
        $conn->mysqli->query("INSERT INTO test_prepare VALUES (1, 'Test')");

        // prepare() should return MysqliStmtWrapper
        $stmt = $conn->mysqli->prepare("SELECT * FROM test_prepare WHERE id = ?");

        $this->assertInstanceOf(MysqliStmtWrapper::class, $stmt);

        // Execute and get results
        $stmt->execute([1]);
        $result = $stmt->get_result();

        $this->assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $this->assertSame('Test', $row['name']);
    }

    public function testPrepareWithError(): void
    {
        $conn = new Connection(self::$configDefaults);

        $this->expectException(mysqli_sql_exception::class);
        $this->expectExceptionMessage("doesn't exist");

        // Invalid table should throw exception
        $conn->mysqli->prepare("SELECT * FROM nonexistent_prepare_table");
    }

    //endregion
    //region Error Handling

    public function testErrorLoggedOnFailure(): void
    {
        $capturedError = null;
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$capturedError) {
                if ($error !== null) {
                    $capturedError = $error;
                }
            }
        ]));

        try {
            $conn->query("SELECT * FROM nonexistent_table_xyz");
        } catch (mysqli_sql_exception) {
            // Expected
        }

        $this->assertNotNull($capturedError);
        $this->assertInstanceOf(\mysqli_sql_exception::class, $capturedError);
    }

    //endregion
}
