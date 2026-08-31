<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use InvalidArgumentException;
use RuntimeException;
use mysqli_sql_exception;

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
        self::requiresLiveMysql();

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
        self::requiresLiveMysql();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("MySQL Error");
        $config = array_merge(self::$configDefaults, ['hostname' => 'invalid_value']);
        DB::connect($config);
    }

    public function testConnectWithInvalidUsername(): void
    {
        self::requiresLiveMysql();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("MySQL Error");
        $config = array_merge(self::$configDefaults, ['username' => 'invalid_value']);
        DB::connect($config);
    }

    public function testConnectWithInvalidPassword(): void
    {
        self::requiresLiveMysql();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("MySQL Error");
        $config = array_merge(self::$configDefaults, ['password' => 'invalid_value']);
        DB::connect($config);
    }

    public function testConnectWithMissingCredentials(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Missing required config: 'hostname'");
        new Connection();
    }

    public function testConnectWithNonStringEncryptionKey(): void
    {
        // getenv() returns false when the env var is unset; fail with the config key's
        // name instead of a TypeError from the vault that mentions neither
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Config 'encryptionKey' must be a string, got bool");
        $config = array_merge(self::$configDefaults, ['encryptionKey' => false]);
        DB::connect($config);
    }

    public function testConnectWithAutoCreateDatabase(): void
    {
        $database = "testplan_test_auto_create_database";
        $config   = array_merge(self::$configDefaults, ['database' => $database]);
        DB::connect($config);

        $selectedDatabase = DB::$mysqli->query("SELECT DATABASE() as db")->fetch_assoc()['db'];
        $this->assertSame($database, $selectedDatabase);

        // utf8mb4 with no COLLATE pinned: the collation is the server's own utf8mb4 default
        [$charset, $collation] = DB::$mysqli->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$database'")->fetch_row();
        $this->assertSame('utf8mb4', $charset);
        $this->assertStringStartsWith('utf8mb4_', $collation);

        DB::$mysqli->query("DROP DATABASE `$database`") or throw new RuntimeException("Error dropping database");
    }

    public function testConnectWithRequiredVersion(): void
    {
        // connect once to learn what this server calls itself, so we can assert the
        // failure message names the actual product (MariaDB, Percona) instead of "MySQL"
        DB::connect(self::$configDefaults);
        $vendorName = DB::$server->vendorName();
        $version    = DB::$server->version();
        DB::disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires MySQL v100.100.100+ or compatible. This server has $vendorName v$version installed");
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

    /**
     * Config keys are checked against an explicit list, not property_exists(), which is true
     * for private properties too. Setting hasEncryptionKey desyncs encrypt from decrypt: writes
     * encrypt, reads hand back raw ciphertext, and saving that value back double-encrypts it.
     */
    #[DataProvider('provideInternalPropertyNames')]
    public function testInternalPropertiesRejectedAsConfigKeys(string $key, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown configuration key: '$key'");
        $config = array_merge(self::$configDefaults, [$key => $value]);
        DB::connect($config);
    }

    public static function provideInternalPropertyNames(): array
    {
        return [
            'private flag'   => ['hasEncryptionKey', false],
            'private state'  => ['decryptWarned', true],
            'private params' => ['paramValues', []],
            'public mysqli'  => ['mysqli', null],
            'public table'   => ['table', null],
        ];
    }

    public function testDisconnect(): void
    {
        DB::connect(self::$configDefaults);
        $this->assertTrue(DB::isConnected());

        DB::disconnect();
        $this->assertFalse(DB::isConnected());
    }

    /**
     * connect() assigns $this->mysqli before real_connect() runs. If the attempt fails, that
     * never-connected handle must not be left behind: isConnected() would report true and a
     * retry connect() would throw "Already connected" until the caller disconnect()s again.
     */
    public function testFailedReconnectLeavesConnectionDisconnected(): void
    {
        $conn = new Connection(self::$configDefaults);
        $conn->disconnect();

        // Make the next connect() fail the way an unreachable server does: real_connect() throws
        $factory = Connection::$mysqliWrapperFactory;
        Connection::$mysqliWrapperFactory = static fn(?callable $queryLogger): MysqliWrapper => new class($queryLogger) extends MysqliWrapper {
            public function real_connect(?string $hostname = null, ?string $username = null, ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null, int $flags = 0): bool
            {
                throw new mysqli_sql_exception("Connection refused", 2002);
            }
        };
        try {
            $conn->connect();
            $this->fail("connect() should throw when real_connect() fails");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("MySQL Error", $e->getMessage());
        } finally {
            Connection::$mysqliWrapperFactory = $factory;
        }

        $this->assertFalse($conn->isConnected(), "A failed connect() must not leave a never-connected handle behind");
        $this->assertFalse($conn->isConnected(true));

        // Server reachable again: reconnect must work without another disconnect()
        $conn->connect();
        $this->assertTrue($conn->isConnected(true));
    }

    /**
     * stat() throws Error (not mysqli_sql_exception) when the handle was closed behind ZenDB's
     * back, e.g. DB::$mysqli->close(). A liveness probe must answer false, not throw.
     */
    public function testIsConnectedPingReturnsFalseAfterExternalClose(): void
    {
        self::requiresLiveMysql(); // replay's close() and stat() are no-ops

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->close();

        $this->assertFalse($conn->isConnected(true));
    }

    /**
     * disconnect() must release what's left and never throw, even when the handle was already
     * closed behind ZenDB's back: a second close() raises Error "mysqli object is already closed".
     */
    public function testDisconnectAfterExternalCloseDoesNotThrow(): void
    {
        self::requiresLiveMysql(); // replay's close() is a no-op

        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->close();

        $conn->disconnect();

        $this->assertFalse($conn->isConnected());
    }

    /**
     * DB::$mysqli and DB::$server are snapshots taken by DB::connect(). An instance-level
     * disconnect() on the default connection must clear them, not leave a closed handle behind.
     */
    public function testFacadeStaticsClearOnInstanceDisconnect(): void
    {
        DB::connect(self::$configDefaults);

        DB::connection()->disconnect();

        $this->assertNull(DB::$mysqli, "DB::\$mysqli must not point at a closed handle");
        $this->assertNull(DB::$server);
    }

    /**
     * After an instance-level disconnect()/connect() on the default connection, DB::$mysqli
     * and DB::$server must refer to the new handle, not the one connect() replaced.
     */
    public function testFacadeStaticsFollowInstanceReconnect(): void
    {
        DB::connect(self::$configDefaults);
        $conn = DB::connection();

        $conn->disconnect();
        $conn->connect();

        $this->assertSame($conn->mysqli, DB::$mysqli);
        $this->assertSame($conn->server, DB::$server);
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
        self::requiresLiveMysql();

        DB::connect(self::$configDefaults);

        $independent = new Connection(self::$configDefaults);
        $threadId    = $independent->mysqli->thread_id;
        unset($independent);

        // Connection and its TableInfo reference each other, so unset() alone doesn't free
        // the object; the handle closes when the cycle collector runs
        gc_collect_cycles();

        // The independent connection's server thread is gone (allow a brief lag)
        $gone = false;
        for ($i = 0; $i < 100 && !$gone; $i++) {
            $gone = DB::$mysqli->query("SELECT 1 FROM information_schema.processlist WHERE id = $threadId")->num_rows === 0;
            if (!$gone) {
                usleep(10_000);
            }
        }
        $this->assertTrue($gone, "Destructor must close the independent connection");

        // And the default connection is untouched
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
        self::requiresLiveMysql();

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

    public function testPhpTimezoneForMysql(): void
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $this->assertSame('+00:00', DB::phpTimezoneForMysql());

            date_default_timezone_set('Pacific/Kiritimati'); // +14:00 year-round, the bug #63685 case
            $this->assertSame('Etc/GMT-14', DB::phpTimezoneForMysql());

            date_default_timezone_set('Pacific/Chatham');    // +12:45 passes through; +13:45 during DST (Sep-Apr) remaps
            $expected = date('P') === '+12:45' ? '+12:45' : 'Pacific/Chatham';
            $this->assertSame($expected, DB::phpTimezoneForMysql());
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    public function testConnectRemapsOutOfRangeTimezoneOffset(): void
    {
        // The remap sends an IANA name, which only resolves where the mysql.time_zone tables
        // are loaded. Probe that first with an in-range connection.
        DB::disconnect();
        DB::connect(self::$configDefaults);
        $tzTablesLoaded = ((int) DB::$mysqli->query("SELECT COUNT(*) FROM mysql.time_zone_name")->fetch_row()[0]) > 0;
        if (!$tzTablesLoaded) {
            $this->markTestSkipped('Server has no mysql.time_zone tables loaded; named-zone remap cannot be exercised.');
        }

        // PHP offsets past +13:00 (here Kiritimati, +14:00) are rejected as a raw offset with error
        // 1298 on MariaDB and MySQL < 8.0.19; connect() remaps them to the IANA name (bug #63685).
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        try {
            DB::disconnect();
            DB::connect(self::$configDefaults); // raw '+14:00' would throw 1298; 'Etc/GMT-14' connects
            $this->assertTrue(DB::isConnected());
            $sessionTz = DB::$mysqli->query("SELECT @@session.time_zone as tz")->fetch_assoc()['tz'];
            $this->assertSame('Etc/GMT-14', $sessionTz, 'connect() should send the IANA name, not the +14:00 offset');
        } finally {
            date_default_timezone_set($originalTz);
            DB::disconnect();
        }
    }

    public function testConnectWithoutPhpTimezone(): void
    {
        self::requiresLiveMysql();

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
        // connectTimeout is not verifiable via MySQL session variables - it only affects the TCP connection phase
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
        $this->assertTrue(version_compare(DB::$server->version(), '5.0.0', '>='));  // same normalized value the versionRequired check compares
    }

    public function testRequireSSLConnectsEncrypted(): void
    {
        self::requiresLiveMysql();

        try {
            $conn = new Connection(array_merge(self::$configDefaults, ['requireSSL' => true]));
        } catch (RuntimeException $e) {
            // A server built without SSL refuses MYSQLI_CLIENT_SSL outright; if the flag
            // silently regressed to 0 we would connect in plaintext and fail below instead
            $this->markTestSkipped("Server refused SSL connection: {$e->getMessage()}");
        }

        $cipher = $conn->mysqli->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch_row()[1];
        $this->assertNotSame('', $cipher, "requireSSL => true must negotiate an encrypted connection");
    }

    public function testDatabaseAutoCreateOption(): void
    {
        self::requiresLiveMysql();

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
        self::requiresLiveMysql();

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
        $conn = new Connection([
            'hostname' => self::$configDefaults['hostname'],
            'username' => self::$configDefaults['username'],
            'password' => self::$configDefaults['password'],
            'database' => self::$configDefaults['database'],
        ]);

        // Constructor auto-connects when hostname is provided
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
