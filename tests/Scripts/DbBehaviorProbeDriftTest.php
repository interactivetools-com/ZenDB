<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Scripts;

use Itools\ZenDB\Tests\BaseTestCase;
use ReflectionMethod;

/**
 * .github/scripts/db-behavior-probe.php duplicates three ZenDB internals as literals so its
 * probes show what raw servers do with the exact values ZenDB uses. These tests fail
 * when either side changes, so the probe script can't silently drift from the library.
 */
class DbBehaviorProbeDriftTest extends BaseTestCase
{
    private static function toolSource(): string
    {
        return file_get_contents(__DIR__ . '/../../.github/scripts/db-behavior-probe.php');
    }

    public function testSqlModeProbeMatchesConnectionDefault(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/ConnectionInternals.php');
        preg_match("~private string \\\$sqlMode\\s+= '([^']+)'~", $src, $srcMode);
        preg_match("~SET sql_mode = '([^']+)'~", self::toolSource(), $toolMode);

        $this->assertNotEmpty($srcMode[1] ?? '', 'sqlMode default not found in ConnectionInternals.php');
        $this->assertSame($srcMode[1], $toolMode[1] ?? null, 'db-behavior-probe.php probes a different sql_mode than ZenDB sets');
    }

    public function testVersionParseProbeMatchesServer(): void
    {
        $serverSource = file_get_contents(__DIR__ . '/../../src/Server.php');
        foreach (["preg_replace('/^5\\.5\\.5-(?=\\d)/'", "preg_match('/^[\\d.]+/'"] as $regexCall) {
            $this->assertStringContainsString($regexCall, $serverSource, 'version parse changed in Server.php - update the probe in db-behavior-probe.php');
            $this->assertStringContainsString($regexCall, self::toolSource());
        }
    }

    public function testKeyFoldingProbeMatchesAesKey(): void
    {
        // The probe XOR-folds a SHA-512 hash into a 16-byte AES key the same way
        // AES_ENCRYPT does; aesKey() must produce the identical fold
        $keyBytes = hash('sha512', 'zendb probe key', true);
        $folded   = substr($keyBytes, 0, 16) ^ substr($keyBytes, 16, 16) ^ substr($keyBytes, 32, 16) ^ substr($keyBytes, 48, 16);

        $conn = self::createDefaultConnection(['encryptionKey' => 'zendb probe key']);
        $this->assertSame($folded, (new ReflectionMethod($conn, 'aesKey'))->invoke($conn));
    }
}
