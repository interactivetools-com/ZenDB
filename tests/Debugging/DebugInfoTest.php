<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Debugging;

use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for __debugInfo() method (var_dump output)
 *
 * @covers \Itools\ZenDB\ConnectionInternals::__debugInfo
 */
class DebugInfoTest extends BaseTestCase
{
    public function testDebugInfoMasksPassword(): void
    {
        // Create connection instance without connecting, then set password
        $conn = new Connection();
        $conn->password = 'secret_password_123';

        $debugInfo = $conn->__debugInfo();

        $this->assertArrayHasKey('password', $debugInfo);
        $this->assertSame('********', $debugInfo['password']);
    }

    public function testDebugInfoIncludesOtherProperties(): void
    {
        $conn = new Connection(self::$configDefaults);

        $debugInfo = $conn->__debugInfo();

        $this->assertArrayHasKey('hostname', $debugInfo);
        $this->assertArrayHasKey('username', $debugInfo);
        $this->assertArrayHasKey('database', $debugInfo);
        $this->assertArrayHasKey('tablePrefix', $debugInfo);

        // Values should be visible (not masked)
        $this->assertSame(self::$configDefaults['hostname'], $debugInfo['hostname']);
        $this->assertSame(self::$configDefaults['username'], $debugInfo['username']);
    }

    public function testDebugInfoWithEmptyPassword(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, [
            'password' => ''
        ]));

        $debugInfo = $conn->__debugInfo();

        // Empty password should NOT be masked
        $this->assertArrayHasKey('password', $debugInfo);
        $this->assertSame('', $debugInfo['password']);
    }

    public function testDebugInfoWithNullPassword(): void
    {
        // Create connection without setting password
        $config = self::$configDefaults;
        $config['password'] = null;

        $conn = new Connection($config);
        $debugInfo = $conn->__debugInfo();

        // Null password should not be masked
        $this->assertArrayHasKey('password', $debugInfo);
        $this->assertNull($debugInfo['password']);
    }

    public function testVarDumpShowsMaskedPassword(): void
    {
        // Create connection instance without connecting, then set password
        $conn = new Connection();
        $conn->password = 'my_secret_pass';

        // Capture var_dump output
        ob_start();
        var_dump($conn);
        $output = ob_get_clean();

        // Password should be masked
        $this->assertStringContainsString('********', $output);
        $this->assertStringNotContainsString('my_secret_pass', $output);
    }

    public function testDebugInfoIncludesMysqli(): void
    {
        $conn = new Connection(self::$configDefaults);

        $debugInfo = $conn->__debugInfo();

        $this->assertArrayHasKey('mysqli', $debugInfo);
        $this->assertInstanceOf(\Itools\ZenDB\MysqliWrapper::class, $debugInfo['mysqli']);
    }

    public function testDebugInfoIncludesSettings(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, [
            'useSmartJoins' => false,
            'useSmartStrings' => true,
        ]));

        $debugInfo = $conn->__debugInfo();

        $this->assertArrayHasKey('useSmartJoins', $debugInfo);
        $this->assertFalse($debugInfo['useSmartJoins']);

        $this->assertArrayHasKey('useSmartStrings', $debugInfo);
        $this->assertTrue($debugInfo['useSmartStrings']);
    }

    public function testDebugInfoBeforeConnection(): void
    {
        // Create connection instance without connecting
        $conn = new Connection();

        $debugInfo = $conn->__debugInfo();

        // Should still return properties
        $this->assertIsArray($debugInfo);
        $this->assertNull($debugInfo['hostname']);
        $this->assertNull($debugInfo['mysqli']);
    }

    public function testDebugInfoAfterDisconnect(): void
    {
        $conn = new Connection(self::$configDefaults);
        $this->assertTrue($conn->isConnected());

        $conn->disconnect();
        $this->assertFalse($conn->isConnected());

        $debugInfo = $conn->__debugInfo();

        // mysqli should be null after disconnect
        $this->assertArrayHasKey('mysqli', $debugInfo);
        $this->assertNull($debugInfo['mysqli']);
    }
}
