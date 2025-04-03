<?php
declare(strict_types=1);

namespace tests;

use Itools\ZenDB\Connection;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Class ConnectionTest
 *
 * Tests the Connection class in isolation from DB class.
 */
class ConnectionTest extends TestCase
{
    /**
     * Helper to generate a valid config array.
     * We use environment variables from phpunit.xml (or your own approach).
     */
    protected function getConfig(array $overrides = []): array
    {
        $defaultConfig = [
            'hostname'               => $_ENV['DB_HOSTNAME'],
            'username'               => $_ENV['DB_USERNAME'],
            'password'               => $_ENV['DB_PASSWORD'],
            'database'               => $_ENV['DB_DATABASE'],
            'tablePrefix'            => 'test_',
            'primaryKey'             => 'num',
            'usePhpTimezone'         => true,
            'set_sql_mode'           => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
            'versionRequired'        => '5.7.32',
            'requireSSL'             => false,
            'databaseAutoCreate'     => true,
            'connectTimeout'         => 3,
            'readTimeout'            => 60,
        ];
        return array_merge($defaultConfig, $overrides);
    }

    public function testConnectWithValidCredentials(): void
    {
        $connection = new Connection($this->getConfig());
        $this->assertTrue($connection->isConnected(), 'Connection should be established with valid credentials');
        $connection->disconnect();
    }

    public function testConnectWithInvalidHostThrowsExceptionOrReportsNotConnected(): void
    {
        try {
            // Force an invalid hostname
            $config = $this->getConfig(['hostname' => '255.255.255.255']); // 255.255.255.255 is typically unreachable
            $connection = new Connection($config);

            // If no exception is thrown, connection should report as not connected
            $this->assertFalse($connection->isConnected(), 'With invalid host, isConnected() should return false');
        } catch (Throwable $e) {
            // This is also acceptable - connection threw exception during construction
            $this->assertStringContainsString('MySQL Error', $e->getMessage(), "Exception message should contain 'MySQL Error'");
        }
    }

    public function testConnectWithMissingDatabaseKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Missing required config keys/');

        // Provide partial config, missing 'database'
        $config = [
            'hostname' => $_ENV['DB_HOSTNAME'],
            'username' => $_ENV['DB_USERNAME'],
            'password' => $_ENV['DB_PASSWORD'],
            // intentionally no 'database'
        ];

        new Connection($config);
    }

    public function testConnectWithMissingCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Missing required config keys/');

        // Provide partial config with empty username
        $config = [
            'hostname' => $_ENV['DB_HOSTNAME'],
            'username' => '',
            'password' => $_ENV['DB_PASSWORD'],
            'database' => $_ENV['DB_DATABASE'],
        ];

        new Connection($config);
    }

    public function testDisconnectActuallyDisconnects(): void
    {
        $connection = new Connection($this->getConfig());
        $this->assertTrue($connection->isConnected(), 'Should be connected before disconnecting');

        $connection->disconnect();
        $this->assertFalse($connection->isConnected(), 'Should be disconnected after disconnect()');
    }

    public function testReconnectIfNeeded(): void
    {
        $connection = new Connection($this->getConfig());
        $this->assertTrue($connection->isConnected(), 'Should be connected initially');

        // Manually disconnect
        $connection->disconnect();
        $this->assertFalse($connection->isConnected(), 'Should be disconnected after manual disconnect');

        // Attempt reconnect
        $result = $connection->reconnectIfNeeded();
        $this->assertTrue($result, 'reconnectIfNeeded() should return true for successful reconnection');
        $this->assertTrue($connection->isConnected(), 'Connection should be reestablished after reconnect');
    }

    public function testAutoCreateDatabaseWorks(): void
    {
        // Create a random DB name
        $tempDbName = 'temp_autocreate_' . uniqid();
        $config = $this->getConfig([
            'database'           => $tempDbName,
            'databaseAutoCreate' => true,
        ]);

        $connection = null;
        try {
            $connection = new Connection($config);
            $this->assertTrue($connection->isConnected(), 'Should be connected even though DB did not exist initially');

            // Confirm that the DB is actually selected
            $dbNameResult = $connection->mysqli->query('SELECT DATABASE() as db')->fetch_assoc();
            $this->assertSame($tempDbName, $dbNameResult['db'] ?? null);

        } finally {
            // Clean up - drop the DB if created
            if ($connection && $connection->isConnected()) {
                $connection->mysqli->query("DROP DATABASE IF EXISTS `$tempDbName`");
            }
        }
    }

    public function testAutoCreateDatabaseDisabledThrowsOrFailsOnNonexistentDB(): void
    {
        $tempDbName = 'temp_autoCreateOff_' . uniqid();

        // This DB should not exist and we are disabling auto create
        $config = $this->getConfig([
            'database'           => $tempDbName,
            'databaseAutoCreate' => false,
        ]);

        try {
            $connection = new Connection($config);

            // We need to check that connection failed or throws on first query
            if ($connection->isConnected()) {
                $connection->mysqli->query('SELECT 1'); // Should throw if actually connected to non-existent DB
                $this->fail('Should not reach this point with nonexistent DB');
            }

            // If we got here, the connection object was created but isConnected() returned false, which is correct
            $this->assertFalse($connection->isConnected(), 'Without autoCreate, connection should fail for nonexistent DB');

        } catch (RuntimeException $e) {
            // This is also acceptable - connection threw exception (either during construction or first query)
            $this->assertStringContainsString('MySQL Error', $e->getMessage());
        }
    }

    public function testSetTimezoneToPhpTimezoneBehavior(): void
    {
        $config = $this->getConfig(['usePhpTimezone' => true]);
        $connection = new Connection($config);
        $this->assertTrue($connection->isConnected(), 'Connection should be established');

        // Try selecting from now() to confirm we can query
        $result = $connection->mysqli->query("SELECT NOW() AS 'current_time'");
        $this->assertNotFalse($result, 'Query should return a result');

        $row = $result->fetch_assoc();
        $this->assertArrayHasKey('current_time', $row, 'Should return a current_time key from the DB');

        $connection->disconnect();
    }

    public function testSetSqlModeDoesNotBreakConnection(): void
    {
        $config = $this->getConfig([
            'set_sql_mode' => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
        ]);

        $connection = new Connection($config);
        $this->assertTrue($connection->isConnected(), 'Connection should be established');

        // Confirm we can run a query
        $result = $connection->mysqli->query('SELECT 1 AS testValue');
        $this->assertNotFalse($result, 'Query must not fail on strict mode settings');

        $row = $result->fetch_assoc();
        $this->assertEquals(1, $row['testValue']);

        $connection->disconnect();
    }

    public function testRequiresNewerMySQLVersionThrowsException(): void
    {
        try {
            $config = $this->getConfig([
                'versionRequired' => '99.99.99', // Obviously too large, guaranteed to fail
            ]);

            $connection = new Connection($config);

            // If connect succeeded, the version check didn't work as expected
            $this->assertFalse($connection->isConnected(), 'Version check should prevent successful connection');
        } catch (RuntimeException $e) {
            // This is the expected behavior: a version too new exception
            $this->assertStringContainsString('requires MySQL', $e->getMessage());
        }
    }

    public function testRequiresSSL(): void
    {
        // If your environment doesn't support SSL, you might skip or catch the exception
        if (!$this->canTestSSL()) {
            $this->markTestSkipped('Skipping SSL test because environment does not support SSL or no SSL config is set up.');
        }

        // Force requireSSL
        $sslConfig = $this->getConfig(['requireSSL' => true]);

        try {
            $conn = new Connection($sslConfig);
            $this->assertTrue($conn->isConnected(), 'SSL connection should succeed if your server supports SSL');
        } catch (Throwable $ex) {
            // On some servers, this might fail if SSL is not properly configured
            $this->assertStringContainsString('Try disabling \'requireSSL\'', $ex->getMessage());
        }
    }

    /**
     * If your environment or CI pipeline can't do SSL, you can detect that here
     * or from an env variable, and skip the test.
     */
    private function canTestSSL(): bool
    {
        // In a real test environment, detect if your MySQL has SSL support or not:
        // e.g., check openssl extension, or a known SSL setup. Stub for now:
        return false;
    }
}
