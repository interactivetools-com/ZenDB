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
        self::requiresLiveMysql();   // raw wrapper connections in every test
        self::createDefaultConnection();
        self::resetTestTables();
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
        self::resetTestTables();

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

        // ZenDB only calls real_query() for the SET on connect, so call it directly here

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
        // Test native execute_query path (forceExecuteQueryPolyfill=false, PHP 8.2+)
        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_native_eq");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_native_eq (id INT, name VARCHAR(50))");
        $conn->mysqli->query("INSERT INTO test_native_eq VALUES (1, 'Alice'), (2, 'Bob')");

        MysqliWrapper::$forceExecuteQueryPolyfill = false;
        $result = $conn->mysqli->execute_query("SELECT * FROM test_native_eq WHERE id = ?", [1]);

        $this->assertInstanceOf(\mysqli_result::class, $result);
        if (PHP_VERSION_ID >= 80200) {
            $this->assertFalse(self::polyfillRanLast($conn->mysqli), "Flag off on PHP 8.2+: native execute_query() must run, not the polyfill");
        }
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
        if (PHP_VERSION_ID >= 80200) {
            $this->assertFalse(self::polyfillRanLast($conn->mysqli), "Flag off on PHP 8.2+: native execute_query() must run, not the polyfill");
        }
        $this->assertSame(1, $conn->mysqli->insert_id);
    }

    //endregion
    //region execute_query Polyfill

    /**
     * True if the last execute_query() ran the polyfill branch; only that branch
     * assigns the private stmtKeepAlive property, the native branch never touches it.
     */
    private static function polyfillRanLast(MysqliWrapper $mysqli): bool
    {
        $stmt = (new \ReflectionProperty(MysqliWrapper::class, 'stmtKeepAlive'))->getValue($mysqli);
        return $stmt instanceof MysqliStmtWrapper;
    }

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
            $this->assertTrue(self::polyfillRanLast($conn->mysqli), "Flag set: the polyfill branch must run, not native execute_query()");
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
            $this->assertTrue(self::polyfillRanLast($conn->mysqli), "Flag set: the polyfill branch must run, not native execute_query()");
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
    //region set_charset

    public function testSetCharsetAllowsUtf8mb4(): void
    {
        $this->assertTrue(DB::$mysqli->set_charset('utf8mb4'));
    }

    public function testSetCharsetRejectsOtherCharsets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("ZenDB connections are always utf8mb4; set_charset() doesn't accept `latin1`.");

        DB::$mysqli->set_charset('latin1');
    }

    //endregion
    //region mysqli_report() turned off by other code

    /**
     * Recreate test_report_off with one row, id 1. InnoDB so a ROLLBACK removes rows.
     */
    private static function resetReportOffTable(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_report_off");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_report_off (id INT PRIMARY KEY, name VARCHAR(50)) ENGINE=InnoDB");
        DB::$mysqli->query("INSERT INTO test_report_off VALUES (1, 'first')");
    }

    /**
     * Run a call that should fail and return the exception it threw.
     */
    private static function exceptionFrom(callable $failingCall): mysqli_sql_exception
    {
        try {
            $failingCall();
        } catch (mysqli_sql_exception $e) {
            return $e;
        }
        self::fail("The call returned without throwing mysqli_sql_exception");
    }

    /**
     * Same as exceptionFrom(), with mysqli's own exceptions turned off for the call.
     */
    private static function exceptionWithReportingOff(callable $failingCall): mysqli_sql_exception
    {
        mysqli_report(MYSQLI_REPORT_OFF);   // WordPress does this when it connects
        try {
            return self::exceptionFrom($failingCall);
        } finally {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }
    }

    /**
     * Every query method on the wrapper and its statements, then the DB methods built on them.
     * The second value forces the PHP 8.1 execute_query() polyfill.
     */
    public static function failingCallProvider(): array
    {
        $duplicateKey = [1, 'again'];
        return [
            '$mysqli->query()'                               => [fn() => DB::$mysqli->query("SELECT nope FROM test_report_off")],
            '$mysqli->real_query()'                          => [fn() => DB::$mysqli->real_query("SELECT nope FROM test_report_off")],
            '$mysqli->multi_query()'                         => [fn() => DB::$mysqli->multi_query("SELECT nope FROM test_report_off; SELECT 1")],
            '$mysqli->execute_query(), unknown column'       => [fn() => DB::$mysqli->execute_query("SELECT nope FROM test_report_off WHERE id = ?", [1])],
            '$mysqli->execute_query(), duplicate key'        => [fn() => DB::$mysqli->execute_query("INSERT INTO test_report_off VALUES (?, ?)", $duplicateKey)],
            'execute_query() polyfill, unknown column'       => [fn() => DB::$mysqli->execute_query("SELECT nope FROM test_report_off WHERE id = ?", [1]), true],
            'execute_query() polyfill, duplicate key'        => [fn() => DB::$mysqli->execute_query("INSERT INTO test_report_off VALUES (?, ?)", $duplicateKey), true],
            '$mysqli->prepare()'                             => [fn() => DB::$mysqli->prepare("SELECT nope FROM test_report_off WHERE id = ?")],
            '$stmt->execute()'                               => [fn() => DB::$mysqli->prepare("INSERT INTO test_report_off VALUES (?, ?)")->execute($duplicateKey)],
            'DB::query()'                                    => [fn() => DB::query("SELECT nope FROM ::report_off")],
            'DB::select()'                                   => [fn() => DB::select('report_off', "nope = ?", 'x')],
            'DB::count()'                                    => [fn() => DB::count('report_off', "nope = ?", 'x')],
            'DB::insert()'                                   => [fn() => DB::insert('report_off', ['id' => 1, 'name' => 'again'])],
            'DB::update()'                                   => [fn() => DB::update('report_off', ['nope' => 'x'], ['id' => 1])],
            'DB::delete()'                                   => [fn() => DB::delete('report_off', "nope = ?", 'x')],
        ];
    }

    /**
     * @dataProvider failingCallProvider
     */
    public function testFailedCallThrowsTheSameExceptionWithReportingOff(callable $failingCall, bool $forcePolyfill = false): void
    {
        self::resetReportOffTable();
        $original = MysqliWrapper::$forceExecuteQueryPolyfill;

        try {
            MysqliWrapper::$forceExecuteQueryPolyfill = $forcePolyfill;
            $fromMysqli = self::exceptionFrom($failingCall);
            $fromZenDB  = self::exceptionWithReportingOff($failingCall);
        } finally {
            MysqliWrapper::$forceExecuteQueryPolyfill = $original;
        }

        $this->assertSame($fromMysqli->getMessage() . " (mysqli_report() was turned off by other code)", $fromZenDB->getMessage());
        $this->assertSame($fromMysqli->getCode(), $fromZenDB->getCode());
        $this->assertSame($fromMysqli->getSqlState(), $fromZenDB->getSqlState());
    }

    public function testTransactionRollsBackWithReportingOff(): void
    {
        self::resetReportOffTable();

        self::exceptionWithReportingOff(fn() => DB::transaction(static function (): void {
            DB::insert('report_off', ['id' => 2, 'name' => 'second']);
            DB::query("SELECT nope FROM ::report_off");
            DB::insert('report_off', ['id' => 3, 'name' => 'third']);
        }));

        $this->assertSame(1, DB::count('report_off'), "Row 2 was inserted before the failing query, so the ROLLBACK must remove it");
    }

    public function testQueryLoggerGetsTheExceptionWithReportingOff(): void
    {
        $loggedErrors = [];
        $conn         = new Connection(array_merge(self::$configDefaults, [
            'queryLogger' => function ($query, $duration, $error) use (&$loggedErrors) {
                if ($error !== null) {
                    $loggedErrors[] = $error;
                }
            },
        ]));

        $thrown = self::exceptionWithReportingOff(fn() => $conn->query("SELECT nope"));

        $this->assertSame([$thrown], $loggedErrors);
    }

    //endregion
}
