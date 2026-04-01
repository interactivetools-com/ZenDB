<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Encryption;

use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use ReflectionProperty;

/**
 * Tests for the encryptionKey config option and lazy @ek session variable.
 */
class EncryptionKeyTest extends BaseTestCase
{
    //region Setup

    protected static Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = self::createDefaultConnection(['encryptionKey' => 'test-secret-key']);
        self::resetTempTestTables();
    }

    /**
     * Helper: read @ek directly, bypassing ensureEncryptionKey by temporarily
     * setting the flag to true so the str_contains check short-circuits.
     */
    private static function getEkDirect(Connection $conn): ?string
    {
        $flagProp = new ReflectionProperty($conn->mysqli, 'encryptionKeySet');
        $original = $flagProp->getValue($conn->mysqli);
        $flagProp->setValue($conn->mysqli, true);  // bypass ensureEncryptionKey

        $result = $conn->mysqli->query("SELECT HEX(@ek) AS ek_hex");

        $flagProp->setValue($conn->mysqli, $original);  // restore
        $row = $result->fetch_assoc();
        $result->free();
        return $row['ek_hex'];
    }

    /**
     * Helper: reset the @ek session variable and MysqliWrapper flag for a clean test.
     */
    private static function resetEk(Connection $conn): void
    {
        $flagProp = new ReflectionProperty($conn->mysqli, 'encryptionKeySet');
        $flagProp->setValue($conn->mysqli, true);  // bypass so "SET @ek = NULL" doesn't trigger ensureEncryptionKey
        $conn->mysqli->query("SET @ek = NULL");
        $flagProp->setValue($conn->mysqli, false);
    }

    //endregion
    //region @ek Not Set Until Needed

    public function testEkIsNullBeforeFirstUse(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'fresh-key']));

        // Check directly via mysqli - bypasses ensureEncryptionKey
        $this->assertNull(self::getEkDirect($conn), '@ek should be NULL before any query references it');
        $conn->disconnect();
    }

    public function testEkNotSetByQueriesWithoutIt(): void
    {
        self::resetEk(self::$conn);

        // Run queries that don't reference @ek
        self::$conn->select('users', ['num' => 1]);
        self::$conn->count('users');
        self::$conn->query("SELECT * FROM `test_users` LIMIT 1");

        // @ek should still be NULL
        $this->assertNull(self::getEkDirect(self::$conn), '@ek should remain NULL when no query uses it');
    }

    public function testEkThrowsWhenNoKeyConfigured(): void
    {
        $conn = new Connection(self::$configDefaults);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no encryptionKey is configured');
        $conn->query("SELECT @ek AS ek");
    }

    public function testDoubleBraceThrowsWhenNoKeyConfigured(): void
    {
        $conn = new Connection(self::$configDefaults);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no encryptionKey is configured');
        $conn->query("SELECT {{city}} AS city FROM `test_users` LIMIT 1");
    }

    //endregion
    //region @ek Set On First Use

    public function testEkSetOnFirstQueryContainingIt(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'my-key']));

        // Verify @ek is NULL before
        $this->assertNull(self::getEkDirect($conn));

        // This query references @ek, triggering the lazy SET
        $conn->query("SELECT @ek AS ek");

        // Verify @ek is now set
        $this->assertNotNull(self::getEkDirect($conn), '@ek should be set after query referencing it');
        $conn->disconnect();
    }

    public function testEkValueMatchesExpectedHash(): void
    {
        $key  = 'deterministic-test-key';
        $conn = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => $key]));

        // Trigger @ek SET
        $conn->query("SELECT @ek AS ek");

        // Compare with expected SHA-512 hash
        $actual   = self::getEkDirect($conn);
        $expected = strtoupper(hash('sha512', $key));
        $this->assertSame($expected, $actual, '@ek should equal UNHEX(SHA2(key, 512))');
        $conn->disconnect();
    }

    //endregion
    //region @ek Only Set Once (Idempotent)

    public function testEkNotResetOnSubsequentQueries(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'once-only']));

        // First query triggers SET @ek
        $conn->query("SELECT @ek AS ek");
        $value1 = self::getEkDirect($conn);
        $this->assertNotNull($value1);

        // Second query should NOT re-SET @ek (same value proves it)
        $conn->query("SELECT @ek AS ek");
        $value2 = self::getEkDirect($conn);
        $this->assertSame($value1, $value2);

        $conn->disconnect();
    }

    public function testChangingCallbackAfterFirstUseHasNoEffect(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'original-key']));

        // Trigger @ek SET
        $conn->query("SELECT @ek AS ek");
        $originalValue = self::getEkDirect($conn);

        // Change the callback via reflection (bypasses setter which resets flag)
        $keyProp = new ReflectionProperty($conn->mysqli, 'getEncryptionKey');
        $keyProp->setValue($conn->mysqli, fn() => 'different-key');

        // Run another @ek query - should NOT re-SET because encryptionKeySet is still true
        $conn->query("SELECT @ek AS ek");
        $this->assertSame($originalValue, self::getEkDirect($conn), '@ek should not change after first SET');

        $conn->disconnect();
    }

    public function testResettingFlagCausesReSet(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'original-key']));

        // Trigger @ek SET
        $conn->query("SELECT @ek AS ek");
        $originalValue = self::getEkDirect($conn);

        // Change the callback AND reset the flag
        $keyProp = new ReflectionProperty($conn->mysqli, 'getEncryptionKey');
        $keyProp->setValue($conn->mysqli, fn() => 'new-key');

        $flagProp = new ReflectionProperty($conn->mysqli, 'encryptionKeySet');
        $flagProp->setValue($conn->mysqli, false);

        // Now @ek should be re-SET with the new key
        $conn->query("SELECT @ek AS ek");
        $newValue = self::getEkDirect($conn);

        $this->assertNotSame($originalValue, $newValue, '@ek should change when flag is reset');
        $this->assertSame(strtoupper(hash('sha512', 'new-key')), $newValue);

        $conn->disconnect();
    }

    //endregion
    //region @ek Works Across Query Methods

    public function testEkTriggeredBySelectWithRawSqlWhere(): void
    {
        self::resetEk(self::$conn);

        // @ek in a string WHERE clause via select()
        self::$conn->select('users', "name = AES_DECRYPT(AES_ENCRYPT(name, @ek), @ek)");

        // Verify @ek was set
        $this->assertNotNull(self::getEkDirect(self::$conn));
    }

    //endregion
    //region AES Encrypt/Decrypt Round Trip

    public function testAesEncryptDecryptRoundTrip(): void
    {
        self::resetEk(self::$conn);

        // Round-trip entirely in SQL - no table writes needed
        $plaintext = 'sensitive-api-token-12345';
        $result    = self::$conn->query("SELECT AES_DECRYPT(AES_ENCRYPT(:val, @ek), @ek) AS decrypted", [':val' => $plaintext]);
        $decrypted = $result->first()->get('decrypted')->value();

        $this->assertSame($plaintext, $decrypted);
    }

    //endregion
    //region decryptRows()

    public function testDecryptRowsWithFetchFields(): void
    {
        // Create table with MEDIUMBLOB, insert encrypted data via auto-encrypt
        self::$conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_encrypted");
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_encrypted (num INT PRIMARY KEY, secret MEDIUMBLOB, label VARCHAR(50))");

        self::$conn->insert('encrypted', [
            'num'    => 1,
            'secret' => 'my-secret-value',
            'label'  => 'test',
        ]);

        // Fetch raw (encrypted) rows bypassing auto-decrypt
        $result = self::$conn->mysqli->query("SELECT * FROM test_encrypted WHERE num = 1");
        $fields = $result->fetch_fields();
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $this->assertNotSame('my-secret-value', $rows[0]['secret'], 'Should be encrypted in raw fetch');

        // Decrypt with field metadata
        self::$conn->decryptRows($rows, $fields);
        $this->assertSame('my-secret-value', $rows[0]['secret'], 'Should be decrypted after decryptRows');
        $this->assertSame('test', $rows[0]['label'], 'Non-encrypted column should be untouched');
    }

    public function testDecryptRowsLeavesNonEncryptedBlobAlone(): void
    {
        self::$conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_raw_blob");
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_raw_blob (num INT PRIMARY KEY, data MEDIUMBLOB)");

        // Insert raw binary (not encrypted) directly via mysqli
        $binaryData = random_bytes(32);
        self::$conn->mysqli->query("INSERT INTO test_raw_blob VALUES (1, x'" . bin2hex($binaryData) . "')");

        // Fetch with field metadata and try to decrypt
        $result = self::$conn->mysqli->query("SELECT * FROM test_raw_blob WHERE num = 1");
        $fields = $result->fetch_fields();
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        self::$conn->decryptRows($rows, $fields);
        $this->assertSame($binaryData, $rows[0]['data'], 'Non-encrypted binary data should be left untouched');
    }

    public function testDecryptRowsSkipsNullValues(): void
    {
        self::$conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_null_blob");
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_null_blob (num INT PRIMARY KEY, secret MEDIUMBLOB)");
        self::$conn->mysqli->query("INSERT INTO test_null_blob VALUES (1, NULL)");

        $result = self::$conn->mysqli->query("SELECT * FROM test_null_blob WHERE num = 1");
        $fields = $result->fetch_fields();
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        self::$conn->decryptRows($rows, $fields);
        $this->assertNull($rows[0]['secret'], 'NULL should remain NULL');
    }

    public function testDecryptRowsNoOpWithoutEncryptionKey(): void
    {
        // Connection without encryption key
        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_no_key_blob");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_no_key_blob (num INT PRIMARY KEY, secret MEDIUMBLOB)");

        $rows = [['secret' => 'plaintext']];
        $result = $conn->mysqli->query("SELECT * FROM test_no_key_blob LIMIT 0");
        $fields = $result->fetch_fields();
        $result->free();

        $conn->decryptRows($rows, $fields);
        $this->assertSame('plaintext', $rows[0]['secret'], 'Should be untouched without encryption key');
        $conn->disconnect();
    }

    //endregion
    //region Debug Info Masking

    public function testEncryptionKeyMaskedInDebugInfo(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'secret-key']));
        $debug = $conn->__debugInfo();
        $this->assertSame('********', $debug['encryptionKey']);
        $conn->disconnect();
    }

    public function testEncryptionKeyCallbackHiddenInMysqliWrapperDebugInfo(): void
    {
        $conn  = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'secret-key']));
        $debug = $conn->mysqli->__debugInfo();
        $this->assertSame('(set)', $debug['getEncryptionKey']);
        $conn->disconnect();
    }

    //endregion
}
