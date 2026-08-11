<?php
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection SqlResolve */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Encryption;

use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests the one-warning-per-connection signal when MEDIUMBLOB values fail to decrypt.
 */
class DecryptWarningTest extends BaseTestCase
{
    private static Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = self::createDefaultConnection(['encryptionKey' => 'decrypt-warning-key']);

        // 'raw-bytes' is 9 bytes, not a multiple of the 16-byte AES block, so decryption always fails
        self::$conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_decrypt_warn");
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_decrypt_warn (num INT PRIMARY KEY, token MEDIUMBLOB)");
        self::$conn->mysqli->query("INSERT INTO test_decrypt_warn VALUES (1, 'raw-bytes'), (2, 'more-raw-bytes')");
    }

    public function testDecryptFailureWarnsOncePerConnection(): void
    {
        [[$rows, $rowsAgain], $warnings] = $this->captureErrors(fn() => [
            self::$conn->select('decrypt_warn'),
            self::$conn->select('decrypt_warn'),
        ], E_USER_WARNING);

        // Fail-open: raw bytes pass through unchanged
        $this->assertCount(2, $rows);
        $this->assertSame('raw-bytes', $rows->first()->get('token')->value());
        $this->assertCount(2, $rowsAgain);

        // Four failed decrypts (2 rows x 2 queries), one warning
        $this->assertCount(1, $warnings, "One warning per connection, not one per row or per query");
        $this->assertStringContainsString("can't decrypt MEDIUMBLOB column 'token'", $warnings[0]);
    }

    public function testWarningFlagIsPerConnectionObject(): void
    {
        // Two independent connections; each gets its own copy of the TEMPORARY table
        $config = array_merge(self::$configDefaults, ['encryptionKey' => 'decrypt-warning-key']);
        $connA  = new Connection($config);
        $connB  = new Connection($config);
        foreach ([$connA, $connB] as $conn) {
            $conn->mysqli->query("CREATE TEMPORARY TABLE test_decrypt_warn2 (num INT PRIMARY KEY, token MEDIUMBLOB)");
            $conn->mysqli->query("INSERT INTO test_decrypt_warn2 VALUES (1, 'raw-bytes')");
        }

        [, $warningsA] = $this->captureErrors(fn() => $connA->select('decrypt_warn2'), E_USER_WARNING);
        [, $warningsB] = $this->captureErrors(fn() => $connB->select('decrypt_warn2'), E_USER_WARNING);

        // If the once-flag were shared (static), connB would warn zero times
        $this->assertCount(1, $warningsA);
        $this->assertCount(1, $warningsB, "A fresh Connection object warns again: the flag is per Connection object");
    }
}
