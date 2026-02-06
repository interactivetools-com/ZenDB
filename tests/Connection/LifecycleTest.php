<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tests for Connection lifecycle: connect, disconnect, isConnected
 * @group slow
 */
class LifecycleTest extends BaseTestCase
{
    protected function setUp(): void
    {
        DB::disconnect();
    }

    protected function tearDown(): void
    {
        DB::disconnect();
    }

    public function testNotConnectedWhenStartingTests(): void
    {
        $this->assertFalse(DB::isConnected());
    }

    public function testDBConnectSetsDefault(): void
    {
        DB::connect(self::$configDefaults);
        $this->assertTrue(DB::isConnected());
    }

    public function testNewConnectionDoesNotSetDefault(): void
    {
        $conn = new Connection(self::$configDefaults);
        $this->assertTrue($conn->isConnected());
        $this->assertNull(DB::$mysqli);
    }

    public function testConnectionBackwardsCompatMysqli(): void
    {
        DB::connect(self::$configDefaults);
        $this->assertInstanceOf(\mysqli::class, DB::$mysqli);
    }

    public function testConnectionBackwardsCompatTablePrefix(): void
    {
        DB::connect(self::$configDefaults);
        $this->assertSame('test_', DB::$tablePrefix);
    }

    public function testConnectWithInvalidHostname(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("MySQL Error");
        $config = array_merge(self::$configDefaults, ['hostname' => 'invalid_value']);
        DB::connect($config);
    }

    public function testConnectWithInvalidUsername(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("MySQL Error");
        $config = array_merge(self::$configDefaults, ['username' => 'invalid_value']);
        DB::connect($config);
    }

    public function testConnectWithInvalidPassword(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("MySQL Error");
        $config = array_merge(self::$configDefaults, ['password' => 'invalid_value']);
        DB::connect($config);
    }

    public function testConnectWithMissingCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required database credentials");
        $conn           = new Connection();
        $conn->hostname = null;
        $conn->username = null;
        $conn->connect();
    }

    public function testConnectWithAutoCreateDatabase(): void
    {
        $database = "testplan_test_auto_create_database";
        $config   = array_merge(self::$configDefaults, ['database' => $database]);
        DB::connect($config);

        $selectedDatabase = DB::$mysqli->query("SELECT DATABASE() as db")->fetch_assoc()['db'];
        $this->assertSame($database, $selectedDatabase);

        DB::$mysqli->query("DROP DATABASE `$database`") or throw new RuntimeException("Error dropping database");
    }

    public function testConnectWithRequiredVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires MySQL v100.100.100 or newer");
        $config = array_merge(self::$configDefaults, ['versionRequired' => '100.100.100']);
        DB::connect($config);
    }

    public function testUnknownConfigKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown configuration key: 'invalidKey'");
        $config = array_merge(self::$configDefaults, ['invalidKey' => 'value']);
        DB::connect($config);
    }

    public function testDisconnect(): void
    {
        DB::connect(self::$configDefaults);
        $this->assertTrue(DB::isConnected());

        DB::disconnect();
        $this->assertFalse(DB::isConnected());
    }

    public function testIndependentConnectionHasOwnMysqli(): void
    {
        DB::connect(self::$configDefaults);
        $defaultMysqli = DB::$mysqli;

        $independent = new Connection(self::$configDefaults);

        $this->assertNotSame($defaultMysqli, $independent->mysqli);
    }

    public function testIndependentConnectionDestructorClosesConnection(): void
    {
        DB::connect(self::$configDefaults);

        $independent = new Connection(self::$configDefaults);
        unset($independent);

        $this->assertTrue(DB::isConnected(true));
    }

    //region Additional Lifecycle Tests

    public function testDoubleConnectThrows(): void
    {
        DB::connect(self::$configDefaults);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Already connected");

        DB::connect(self::$configDefaults);
    }

    public function testConnectWithQueryLogger(): void
    {
        $logs = [];
        $config = array_merge(self::$configDefaults, [
            'queryLogger' => function($query, $duration, $error) use (&$logs) {
                $logs[] = $query;
            }
        ]);

        DB::connect($config);

        // Should have logged the connection
        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('real_connect', $logs[0]);
    }

    public function testConnectSetsSqlMode(): void
    {
        $sqlMode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE';
        $config = array_merge(self::$configDefaults, ['sqlMode' => $sqlMode]);

        DB::connect($config);

        // Verify SQL mode was set
        $result = DB::$mysqli->query("SELECT @@sql_mode as mode");
        $actualMode = $result->fetch_assoc()['mode'];

        $this->assertStringContainsString('STRICT_ALL_TABLES', $actualMode);
        $this->assertStringContainsString('NO_ZERO_IN_DATE', $actualMode);
    }

    public function testConnectSetsPhpTimezone(): void
    {
        $config = array_merge(self::$configDefaults, ['usePhpTimezone' => true]);

        DB::connect($config);

        // Get MySQL's time zone
        $result = DB::$mysqli->query("SELECT @@session.time_zone as tz");
        $mysqlTz = $result->fetch_assoc()['tz'];

        // Should match PHP's timezone offset
        $phpOffset = date('P'); // e.g., +00:00, -05:00
        $this->assertSame($phpOffset, $mysqlTz);
    }

    public function testConnectWithoutPhpTimezone(): void
    {
        $config = array_merge(self::$configDefaults, ['usePhpTimezone' => false]);

        DB::connect($config);

        // Verify connection works and timezone is NOT set to PHP offset
        $this->assertTrue(DB::isConnected());
        $result = DB::$mysqli->query("SELECT @@session.time_zone as tz");
        $mysqlTz = $result->fetch_assoc()['tz'];
        $this->assertSame('SYSTEM', $mysqlTz);
    }

    public function testConnectTimeoutOption(): void
    {
        // connectTimeout is not verifiable via MySQL session variables — it only affects the TCP connection phase
        $config = array_merge(self::$configDefaults, ['connectTimeout' => 5]);

        DB::connect($config);

        $this->assertTrue(DB::isConnected());
    }

    public function testReadTimeoutOption(): void
    {
        // readTimeout sets MYSQLI_OPT_READ_TIMEOUT (client-side), not the MySQL session variable
        $config = array_merge(self::$configDefaults, ['readTimeout' => 120]);

        DB::connect($config);

        $this->assertTrue(DB::isConnected());
    }

    public function testVersionRequiredPasses(): void
    {
        // Use a very low version that will always pass
        $config = array_merge(self::$configDefaults, ['versionRequired' => '5.0.0']);

        DB::connect($config);

        $this->assertTrue(DB::isConnected());
        $this->assertTrue(version_compare(DB::$mysqli->server_info, '5.0.0', '>='));
    }

    public function testRequireSSLOption(): void
    {
        // Note: This may fail on systems without SSL configured
        // We just test that the option is accepted
        $config = array_merge(self::$configDefaults, ['requireSSL' => false]);

        DB::connect($config);

        $this->assertTrue(DB::isConnected());
    }

    public function testDatabaseAutoCreateOption(): void
    {
        $config = array_merge(self::$configDefaults, ['databaseAutoCreate' => false]);

        DB::connect($config);

        $this->assertTrue(DB::isConnected());
        $selectedDatabase = DB::$mysqli->query("SELECT DATABASE() as db")->fetch_assoc()['db'];
        $this->assertSame(self::$configDefaults['database'], $selectedDatabase);
    }

    public function testIsConnectedWithPing(): void
    {
        DB::connect(self::$configDefaults);

        // Without ping
        $this->assertTrue(DB::isConnected(false));

        // With ping
        $this->assertTrue(DB::isConnected(true));
    }

    public function testIsConnectedAfterServerGone(): void
    {
        DB::connect(self::$configDefaults);

        // isConnected without ping just checks mysqli exists
        $this->assertTrue(DB::isConnected(false));

        // Kill the connection's own thread to simulate a dead connection
        $threadId = DB::$mysqli->thread_id;
        $killer   = new \mysqli(
            self::$configDefaults['hostname'],
            self::$configDefaults['username'],
            self::$configDefaults['password'],
            self::$configDefaults['database']
        );
        $killer->query("KILL $threadId");
        $killer->close();

        // isConnected without ping still returns true (just checks mysqli exists)
        $this->assertTrue(DB::isConnected(false));

        // isConnected with ping detects the dead connection
        $this->assertFalse(DB::isConnected(true));
    }

    public function testReconnectAfterDisconnect(): void
    {
        DB::connect(self::$configDefaults);
        $this->assertTrue(DB::isConnected());

        DB::disconnect();
        $this->assertFalse(DB::isConnected());

        // Can reconnect
        DB::connect(self::$configDefaults);
        $this->assertTrue(DB::isConnected());
    }

    public function testConnectionInstanceConnect(): void
    {
        $conn = new Connection();
        $conn->hostname = self::$configDefaults['hostname'];
        $conn->username = self::$configDefaults['username'];
        $conn->password = self::$configDefaults['password'];
        $conn->database = self::$configDefaults['database'];

        $this->assertFalse($conn->isConnected());

        $conn->connect();

        $this->assertTrue($conn->isConnected());
    }

    public function testConnectionInstanceDoubleConnectThrows(): void
    {
        $conn = new Connection(self::$configDefaults);
        $this->assertTrue($conn->isConnected());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Already connected");

        $conn->connect();
    }

    public function testMultipleIndependentConnections(): void
    {
        $conn1 = new Connection(self::$configDefaults);
        $conn2 = new Connection(self::$configDefaults);
        $conn3 = new Connection(self::$configDefaults);

        $this->assertTrue($conn1->isConnected());
        $this->assertTrue($conn2->isConnected());
        $this->assertTrue($conn3->isConnected());

        // All have different mysqli instances
        $this->assertNotSame($conn1->mysqli, $conn2->mysqli);
        $this->assertNotSame($conn2->mysqli, $conn3->mysqli);

        // Disconnecting one doesn't affect others
        $conn2->disconnect();
        $this->assertFalse($conn2->isConnected());
        $this->assertTrue($conn1->isConnected());
        $this->assertTrue($conn3->isConnected());
    }

    //endregion
}
