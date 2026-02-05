<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Logging;

use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli_sql_exception;

/**
 * Tests for query logging functionality
 *
 * @covers \Itools\ZenDB\MysqliWrapper::log
 * @covers \Itools\ZenDB\Connection::__construct
 */
class QueryLoggerTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        // Don't use default connection - tests create their own with loggers
    }

    protected function setUp(): void
    {
        DB::disconnect();
    }

    protected function tearDown(): void
    {
        DB::disconnect();
    }

    public function testLoggerCallbackInvoked(): void
    {
        $logs = [];
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$logs) {
                $logs[] = ['query' => $query, 'duration' => $duration, 'error' => $error];
            }
        ]));

        // Create table for test
        $conn->query("DROP TEMPORARY TABLE IF EXISTS test_logger_test");
        $conn->query("CREATE TEMPORARY TABLE test_logger_test (id INT)");
        $conn->query("SELECT * FROM test_logger_test LIMIT 1");

        // Should have logged: real_connect + DROP + CREATE + SELECT (at least 4)
        $this->assertGreaterThanOrEqual(4, count($logs));
        $selectLog = null;
        foreach ($logs as $log) {
            if (str_contains($log['query'], 'SELECT')) {
                $selectLog = $log;
                break;
            }
        }
        $this->assertNotNull($selectLog);
        $this->assertNull($selectLog['error']);
    }

    public function testLoggerReceivesQueryString(): void
    {
        $capturedQuery = null;
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$capturedQuery) {
                if (str_contains($query, 'SELECT') && str_contains($query, 'test_q')) {
                    $capturedQuery = $query;
                }
            }
        ]));

        $conn->query("DROP TEMPORARY TABLE IF EXISTS test_q_table");
        $conn->query("CREATE TEMPORARY TABLE test_q_table (num INT)");
        $conn->query("SELECT * FROM test_q_table WHERE num = ?", 1);

        $this->assertNotNull($capturedQuery);
        $this->assertStringContainsString('test_q', $capturedQuery);
        $this->assertStringContainsString('num', $capturedQuery);
    }

    public function testLoggerReceivesDuration(): void
    {
        $capturedDuration = null;
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$capturedDuration) {
                if (str_contains($query, 'SELECT') && str_contains($query, 'duration_test')) {
                    $capturedDuration = $duration;
                }
            }
        ]));

        $conn->query("DROP TEMPORARY TABLE IF EXISTS duration_test");
        $conn->query("CREATE TEMPORARY TABLE duration_test (id INT)");
        $conn->query("SELECT * FROM duration_test LIMIT 1");

        $this->assertNotNull($capturedDuration);
        $this->assertIsFloat($capturedDuration);
        $this->assertGreaterThanOrEqual(0, $capturedDuration);
    }

    public function testLoggerReceivesNullOnSuccess(): void
    {
        $capturedError = 'not_set';
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$capturedError) {
                if (str_contains($query, 'SELECT') && str_contains($query, 'null_test')) {
                    $capturedError = $error;
                }
            }
        ]));

        $conn->query("DROP TEMPORARY TABLE IF EXISTS null_test");
        $conn->query("CREATE TEMPORARY TABLE null_test (id INT)");
        $conn->query("SELECT * FROM null_test LIMIT 1");

        $this->assertNull($capturedError);
    }

    public function testLoggerReceivesErrorOnFailure(): void
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
        $this->assertInstanceOf(\Throwable::class, $capturedError);
    }

    public function testLoggerNullDisables(): void
    {
        // Create fresh connection with temp tables
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => null
        ]));

        // Create test table using raw mysqli (bypasses template validation for DDL)
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_logger_null_test");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_logger_null_test (id INT)");

        // Should not call logger (null disables it)
        $conn->query("SELECT * FROM ::logger_null_test LIMIT 1");

        // Just verify query succeeded
        $this->assertTrue($conn->isConnected());
    }

    public function testLoggerMultipleQueries(): void
    {
        $queries = [];
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$queries) {
                $queries[] = $query;
            }
        ]));

        // Create test tables
        $conn->query("DROP TEMPORARY TABLE IF EXISTS multi_test1");
        $conn->query("CREATE TEMPORARY TABLE multi_test1 (id INT)");
        $conn->query("DROP TEMPORARY TABLE IF EXISTS multi_test2");
        $conn->query("CREATE TEMPORARY TABLE multi_test2 (id INT)");
        $conn->query("DROP TEMPORARY TABLE IF EXISTS multi_test3");
        $conn->query("CREATE TEMPORARY TABLE multi_test3 (id INT)");

        $conn->query("SELECT * FROM multi_test1 LIMIT 1");
        $conn->query("SELECT * FROM multi_test2 LIMIT 1");
        $conn->query("SELECT * FROM multi_test3 LIMIT 1");

        $selectQueries = array_filter($queries, fn($q) => str_contains($q, 'SELECT') && str_contains($q, 'multi_test'));
        $this->assertCount(3, $selectQueries);
    }

    public function testLoggerWithInsert(): void
    {
        $capturedQuery = null;
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$capturedQuery) {
                if (str_contains($query, 'INSERT') && str_contains($query, 'insert_log_test')) {
                    $capturedQuery = $query;
                }
            }
        ]));

        // Use raw mysqli for DDL (bypasses template validation for numbers)
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_insert_log_test");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_insert_log_test (name VARCHAR(255), status VARCHAR(50), city VARCHAR(100))");
        $conn->insert('insert_log_test', ['name' => 'Logger Test', 'status' => 'Active', 'city' => 'Test']);

        $this->assertNotNull($capturedQuery);
        $this->assertStringContainsString('INSERT', $capturedQuery);
        $this->assertStringContainsString('Logger Test', $capturedQuery);
    }

    public function testLoggerWithUpdate(): void
    {
        $capturedQuery = null;
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$capturedQuery) {
                if (str_contains($query, 'UPDATE') && str_contains($query, 'update_log_test')) {
                    $capturedQuery = $query;
                }
            }
        ]));

        // Use raw mysqli for DDL
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_update_log_test");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_update_log_test (num INT PRIMARY KEY, city VARCHAR(100))");
        $conn->mysqli->query("INSERT INTO test_update_log_test VALUES (1, 'Old City')");
        $conn->update('update_log_test', ['city' => 'Logged City'], ['num' => 1]);

        $this->assertNotNull($capturedQuery);
        $this->assertStringContainsString('UPDATE', $capturedQuery);
    }

    public function testLoggerWithDelete(): void
    {
        $capturedQuery = null;
        $conn = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$capturedQuery) {
                if (str_contains($query, 'DELETE') && str_contains($query, 'delete_log_test')) {
                    $capturedQuery = $query;
                }
            }
        ]));

        // Use raw mysqli for DDL
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_delete_log_test");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_delete_log_test (name VARCHAR(255), status VARCHAR(50), city VARCHAR(100))");
        $conn->insert('delete_log_test', ['name' => 'To Delete', 'status' => 'Active', 'city' => 'Test']);
        $conn->delete('delete_log_test', ['name' => 'To Delete']);

        $this->assertNotNull($capturedQuery);
        $this->assertStringContainsString('DELETE', $capturedQuery);
    }
}
