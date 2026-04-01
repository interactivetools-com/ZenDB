<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Debugging;

use InvalidArgumentException;
use Itools\ZenDB\Connection;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Tests\BaseTestCase;
use ReflectionProperty;
use WeakMap;

/**
 * Tests for __debugInfo() method (var_dump output)
 *
 * @covers \Itools\ZenDB\ConnectionInternals::__debugInfo
 */
class DebugInfoTest extends BaseTestCase
{
    /**
     * Inject a password into a Connection's credential vault for testing.
     */
    private function injectVaultPassword(Connection $conn, string $password): void
    {
        $prop = new ReflectionProperty($conn, 'secrets');
        /** @var WeakMap $secrets */
        $secrets                    = $prop->getValue();
        $entry                      = $secrets[$conn];
        $entry['password']          = $password;
        $secrets[$conn]             = $entry;
    }

    public function testDebugInfoMasksPassword(): void
    {
        // configDefaults has password '' which is empty and won't be masked
        // Use a separate connection with a real password to test masking
        $conn = new Connection(self::$configDefaults);

        // Inject a non-empty password into the vault to test masking
        $debugInfo = $conn->__debugInfo();
        $this->assertArrayHasKey('password', $debugInfo);

        // With empty password, should NOT be masked
        $this->assertSame('', $debugInfo['password']);
    }

    public function testDebugInfoMasksNonEmptyPassword(): void
    {
        // Connect normally, then inject a non-empty password into the vault to test masking
        $conn = new Connection(self::$configDefaults);
        $this->injectVaultPassword($conn, 'secret_password_123');

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

        // Database should be visible, credentials should be masked
        $this->assertSame(self::$configDefaults['database'], $debugInfo['database']);
        $this->assertSame('********', $debugInfo['hostname']);
        $this->assertSame('********', $debugInfo['username']);
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

    public function testVarDumpShowsMaskedPassword(): void
    {
        // Connect normally, then inject a non-empty password into the vault to test masking
        $conn = new Connection(self::$configDefaults);
        $this->injectVaultPassword($conn, 'my_secret_pass');

        // Capture var_dump output
        ob_start();
        var_dump($conn);
        $output = ob_get_clean();

        // Password should be masked
        $this->assertStringContainsString('********', $output);
        $this->assertStringNotContainsString('my_secret_pass', $output);
    }

    public function testCloneRejectsCredentialOverrides(): void
    {
        $conn = new Connection(self::$configDefaults);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("clone() only supports:");
        $conn->clone(['password' => 'sneaky']);
    }

    public function testDebugInfoIncludesMysqli(): void
    {
        $conn = new Connection(self::$configDefaults);

        $debugInfo = $conn->__debugInfo();

        $this->assertArrayHasKey('mysqli', $debugInfo);
        $this->assertInstanceOf(MysqliWrapper::class, $debugInfo['mysqli']);
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
