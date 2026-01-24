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

    public function testNewConnectionSetsDefault(): void
    {
        new Connection(self::$configDefaults, default: true);
        $this->assertTrue(DB::isConnected());
    }

    public function testNewConnectionWithoutDefaultDoesNotSetDefault(): void
    {
        $conn = new Connection(self::$configDefaults);
        $this->assertTrue($conn->isConnected());
        $this->assertNull(DB::$mysqli);
    }

    public function testConnectionBackwardsCompatMysqli(): void
    {
        new Connection(self::$configDefaults, default: true);
        $this->assertNotNull(DB::$mysqli);
        $this->assertInstanceOf(\mysqli::class, DB::$mysqli);
    }

    public function testConnectionBackwardsCompatTablePrefix(): void
    {
        new Connection(self::$configDefaults, default: true);
        $this->assertSame('test_', DB::$tablePrefix);
    }

    public function testConnectWithInvalidHostname(): void
    {
        $this->expectException(RuntimeException::class);
        $config = array_merge(self::$configDefaults, ['hostname' => 'invalid_value']);
        new Connection($config, default: true);
    }

    public function testConnectWithInvalidUsername(): void
    {
        $this->expectException(RuntimeException::class);
        $config = array_merge(self::$configDefaults, ['username' => 'invalid_value']);
        new Connection($config, default: true);
    }

    public function testConnectWithInvalidPassword(): void
    {
        $this->expectException(RuntimeException::class);
        $config = array_merge(self::$configDefaults, ['password' => 'invalid_value']);
        new Connection($config, default: true);
    }

    public function testConnectWithMissingCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $conn           = new Connection();
        $conn->hostname = null;
        $conn->username = null;
        $conn->connect();
    }

    public function testConnectWithAutoCreateDatabase(): void
    {
        $database = "testplan_test_auto_create_database";
        $config   = array_merge(self::$configDefaults, ['database' => $database]);
        new Connection($config, default: true);

        $selectedDatabase = DB::$mysqli->query("SELECT DATABASE() as db")->fetch_assoc()['db'];
        $this->assertSame($database, $selectedDatabase);

        DB::$mysqli->query("DROP DATABASE `$database`") or throw new RuntimeException("Error dropping database");
    }

    public function testConnectWithRequiredVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $config = array_merge(self::$configDefaults, ['versionRequired' => '100.100.100']);
        new Connection($config, default: true);
    }

    public function testUnknownConfigKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown configuration key: 'invalidKey'");
        $config = array_merge(self::$configDefaults, ['invalidKey' => 'value']);
        new Connection($config, default: true);
    }

    public function testDisconnect(): void
    {
        new Connection(self::$configDefaults, default: true);
        $this->assertTrue(DB::isConnected());

        DB::disconnect();
        $this->assertFalse(DB::isConnected());
    }

    public function testIndependentConnectionHasOwnMysqli(): void
    {
        new Connection(self::$configDefaults, default: true);
        $defaultMysqli = DB::$mysqli;

        $independent = new Connection(self::$configDefaults);

        $this->assertNotSame($defaultMysqli, $independent->mysqli);
    }

    public function testIndependentConnectionDestructorClosesConnection(): void
    {
        new Connection(self::$configDefaults, default: true);

        $independent = new Connection(self::$configDefaults);
        unset($independent);

        $this->assertTrue(DB::isConnected(true));
    }
}
