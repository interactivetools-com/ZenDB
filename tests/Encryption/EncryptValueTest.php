<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Encryption;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use Itools\SmartString\SmartString;

/**
 * Tests for encryptValue(), auto-encrypt/decrypt, {{column}} template syntax, and encrypted round-trips.
 */
class EncryptValueTest extends BaseTestCase
{
    //region Setup

    protected static Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = self::createDefaultConnection(['encryptionKey' => 'test-encrypt-value-key']);
        self::resetTempTestTables();

        // Create a table with MEDIUMBLOB columns for encryption testing
        self::$conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_enc_users");
        self::$conn->mysqli->query("
            CREATE TEMPORARY TABLE test_enc_users (
                num INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255),
                city VARCHAR(255),
                token MEDIUMBLOB,
                ssn MEDIUMBLOB
            )
        ");
        self::$conn->mysqli->query("
            INSERT INTO test_enc_users (num, name, city) VALUES
                (1, 'John Doe', 'Vancouver'),
                (2, 'Jane Doe', 'Toronto'),
                (3, 'Alice Smith', 'Montreal'),
                (4, 'Bob Johnson', 'Calgary'),
                (5, 'Charlie Brown', 'Edmonton'),
                (6, 'Dave Williams', 'Ottawa'),
                (7, 'Erin Davis', 'Quebec'),
                (8, 'Frank Miller', 'Winnipeg'),
                (9, 'George Wilson', 'Halifax'),
                (10, 'Helen Clark', 'Saskatoon')
        ");
    }

    //endregion
    //region encryptValue() - Return Type and Values

    public function testStringValueReturnsEncryptedBinary(): void
    {
        $result = self::$conn->encryptValue('hello');
        $this->assertIsString($result);
        $this->assertNotSame('hello', $result, 'Should be encrypted, not plaintext');
        $this->assertSame(16, strlen($result), 'AES-128-ECB output for short string is one 16-byte block');
    }

    public function testIntegerValueIsEncrypted(): void
    {
        $result = self::$conn->encryptValue(42);
        $this->assertIsString($result);
        $this->assertSame(16, strlen($result));
    }

    public function testFloatValueIsEncrypted(): void
    {
        $result = self::$conn->encryptValue(3.14);
        $this->assertIsString($result);
        $this->assertSame(16, strlen($result));
    }

    public function testNullValueReturnsNull(): void
    {
        $result = self::$conn->encryptValue(null);
        $this->assertNull($result);
    }

    public function testSmartStringValueIsUnwrapped(): void
    {
        $smart  = new SmartString('secret-data');
        $result = self::$conn->encryptValue($smart);
        $this->assertIsString($result);
        $this->assertNotSame('secret-data', $result);
    }

    public function testDeterministicEncryption(): void
    {
        // Same plaintext + same key = same ciphertext (ECB mode)
        $a = self::$conn->encryptValue('hello');
        $b = self::$conn->encryptValue('hello');
        $this->assertSame($a, $b, 'ECB mode should produce identical ciphertext for identical plaintext');
    }

    public function testDifferentPlaintextProducesDifferentCiphertext(): void
    {
        $a = self::$conn->encryptValue('hello');
        $b = self::$conn->encryptValue('world');
        $this->assertNotSame($a, $b);
    }

    //endregion
    //region Auto-Encrypt on Insert/Update

    public function testAutoEncryptOnInsert(): void
    {
        self::$conn->insert('enc_users', [
            'num'   => 100,
            'name'  => 'Test User',
            'token' => 'my-secret-token',
        ]);

        // Read raw from MySQL to verify it's encrypted (bypass auto-decrypt)
        $result = self::$conn->mysqli->query("SELECT token FROM test_enc_users WHERE num = 100");
        $row    = $result->fetch_assoc();
        $result->free();

        $this->assertNotSame('my-secret-token', $row['token'], 'Token should be encrypted in database');
        $this->assertNotEmpty($row['token']);
    }

    public function testAutoEncryptOnUpdate(): void
    {
        self::$conn->update('enc_users', ['token' => 'updated-token'], ['num' => 1]);

        // Read raw
        $result = self::$conn->mysqli->query("SELECT token FROM test_enc_users WHERE num = 1");
        $row    = $result->fetch_assoc();
        $result->free();

        $this->assertNotSame('updated-token', $row['token'], 'Token should be encrypted after update');
    }

    public function testAutoEncryptSkipsNonMediumBlobColumns(): void
    {
        self::$conn->update('enc_users', [
            'name'  => 'Plain Name',
            'token' => 'encrypted-value',
        ], ['num' => 2]);

        // Name (VARCHAR) should be plaintext, token (MEDIUMBLOB) should be encrypted
        $result = self::$conn->mysqli->query("SELECT name, token FROM test_enc_users WHERE num = 2");
        $row    = $result->fetch_assoc();
        $result->free();

        $this->assertSame('Plain Name', $row['name'], 'VARCHAR column should NOT be encrypted');
        $this->assertNotSame('encrypted-value', $row['token'], 'MEDIUMBLOB column should be encrypted');
    }

    public function testAutoEncryptPreservesNull(): void
    {
        self::$conn->update('enc_users', ['token' => null], ['num' => 3]);

        $result = self::$conn->mysqli->query("SELECT token FROM test_enc_users WHERE num = 3");
        $row    = $result->fetch_assoc();
        $result->free();

        $this->assertNull($row['token'], 'NULL should remain NULL, not be encrypted');
    }

    //endregion
    //region Auto-Decrypt on Read

    public function testAutoDecryptOnSelect(): void
    {
        // Insert encrypted data
        self::$conn->insert('enc_users', [
            'num'   => 101,
            'name'  => 'Decrypt Test',
            'token' => 'auto-decrypt-me',
        ]);

        // Read back via select - should be auto-decrypted
        $row = self::$conn->selectOne('enc_users', ['num' => 101]);
        $this->assertSame('auto-decrypt-me', $row->get('token')->value());
    }

    public function testAutoDecryptOnQuery(): void
    {
        self::$conn->insert('enc_users', [
            'num'   => 102,
            'name'  => 'Query Test',
            'token' => 'query-decrypt-me',
        ]);

        $row = self::$conn->queryOne("SELECT * FROM `test_enc_users` WHERE num = ?", 102);
        $this->assertSame('query-decrypt-me', $row->get('token')->value());
    }

    public function testAutoDecryptLeavesVarcharAlone(): void
    {
        self::$conn->insert('enc_users', [
            'num'   => 103,
            'name'  => 'Plain Name',
            'city'  => 'Vancouver',
            'token' => 'secret',
        ]);

        $row = self::$conn->selectOne('enc_users', ['num' => 103]);
        $this->assertSame('Plain Name', $row->get('name')->value());
        $this->assertSame('Vancouver', $row->get('city')->value());
        $this->assertSame('secret', $row->get('token')->value());
    }

    public function testAutoDecryptSkipsNull(): void
    {
        self::$conn->insert('enc_users', ['num' => 104, 'name' => 'Null Token']);

        $row = self::$conn->selectOne('enc_users', ['num' => 104]);
        $this->assertNull($row->get('token')->value());
    }

    //endregion
    //region Round Trip

    public function testInsertAndSelectRoundTrip(): void
    {
        $plaintext = 'sensitive-token-abc123';

        self::$conn->insert('enc_users', [
            'num'   => 105,
            'name'  => 'Round Trip',
            'token' => $plaintext,
        ]);

        $row = self::$conn->selectOne('enc_users', ['num' => 105]);
        $this->assertSame($plaintext, $row->get('token')->value());
    }

    public function testUpdateAndSelectRoundTrip(): void
    {
        self::$conn->update('enc_users', ['token' => 'updated-secret'], ['num' => 4]);

        $row = self::$conn->selectOne('enc_users', ['num' => 4]);
        $this->assertSame('updated-secret', $row->get('token')->value());
    }

    public function testMultipleEncryptedColumnsRoundTrip(): void
    {
        self::$conn->insert('enc_users', [
            'num'   => 106,
            'name'  => 'Multi Column',
            'token' => 'my-token',
            'ssn'   => '123-45-6789',
        ]);

        $row = self::$conn->selectOne('enc_users', ['num' => 106]);
        $this->assertSame('my-token', $row->get('token')->value());
        $this->assertSame('123-45-6789', $row->get('ssn')->value());
    }

    //endregion
    //region Exact Match Search

    public function testExactMatchSearchWithEncryptValue(): void
    {
        self::$conn->insert('enc_users', [
            'num'   => 107,
            'name'  => 'Search Target',
            'token' => 'unique-search-token',
        ]);

        $results = self::$conn->select('enc_users', [
            'token' => DB::encryptValue('unique-search-token'),
        ]);

        $this->assertCount(1, $results);
        $this->assertSame(107, $results->first()->get('num')->value());
    }

    public function testExactMatchSearchWithWrongValueFindsNothing(): void
    {
        $results = self::$conn->select('enc_users', [
            'token' => DB::encryptValue('nonexistent-token'),
        ]);

        $this->assertCount(0, $results);
    }

    //endregion
    //region {{column}} Template Expansion

    public function testDoubleBraceExpandsToAesDecrypt(): void
    {
        self::$conn->insert('enc_users', [
            'num'   => 108,
            'name'  => 'Brace Test',
            'token' => 'decrypt-expansion-test',
        ]);

        $row = self::$conn->query(
            "SELECT {{token}} AS token_decrypted FROM `test_enc_users` WHERE `num` = ?",
            108
        )->first();

        $this->assertSame('decrypt-expansion-test', $row->get('token_decrypted')->value());
    }

    public function testDoubleBraceInWhereClause(): void
    {
        self::$conn->insert('enc_users', [
            'num'   => 109,
            'name'  => 'Where Test',
            'token' => 'where-clause-test',
        ]);

        $results = self::$conn->query(
            "SELECT * FROM `test_enc_users` WHERE {{token}} = ?",
            'where-clause-test'
        );

        $this->assertCount(1, $results);
        $this->assertSame(109, $results->first()->get('num')->value());
    }

    public function testDoubleBraceInOrderBy(): void
    {
        self::$conn->insert('enc_users', ['num' => 110, 'name' => 'A', 'token' => 'AAA-first']);
        self::$conn->insert('enc_users', ['num' => 111, 'name' => 'B', 'token' => 'ZZZ-last']);

        $results = self::$conn->query(
            "SELECT `num`, {{token}} AS token_dec FROM `test_enc_users` WHERE `num` IN (:nums) ORDER BY {{token}} ASC",
            [':nums' => [110, 111]]
        );

        $this->assertCount(2, $results);
        $this->assertSame('AAA-first', $results->first()->get('token_dec')->value());
        $this->assertSame('ZZZ-last', $results->last()->get('token_dec')->value());
    }

    //endregion
    //region getEncryptedColumns()

    public function testGetEncryptedColumnsDetectsMediumBlob(): void
    {
        $result = self::$conn->mysqli->query("SELECT * FROM test_enc_users LIMIT 0");
        $cols   = DB::getEncryptedColumns($result->fetch_fields());
        $result->free();

        $this->assertContains('token', $cols);
        $this->assertContains('ssn', $cols);
        $this->assertNotContains('name', $cols);
        $this->assertNotContains('city', $cols);
        $this->assertNotContains('num', $cols);
        $this->assertCount(2, $cols);
    }

    public function testGetEncryptedColumnsReturnsEmptyForNoBlobs(): void
    {
        $result = self::$conn->mysqli->query("SELECT * FROM test_users LIMIT 0");
        $cols   = DB::getEncryptedColumns($result->fetch_fields());
        $result->free();

        $this->assertEmpty($cols);
    }

    public function testGetEncryptedColumnsExcludesPlainBlob(): void
    {
        self::$conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_blob_types");
        self::$conn->mysqli->query("
            CREATE TEMPORARY TABLE test_blob_types (
                id INT PRIMARY KEY,
                tiny TINYBLOB,
                regular BLOB,
                medium MEDIUMBLOB,
                big LONGBLOB
            )
        ");

        $result = self::$conn->mysqli->query("SELECT * FROM test_blob_types LIMIT 0");
        $cols   = DB::getEncryptedColumns($result->fetch_fields());
        $result->free();

        $this->assertContains('medium', $cols, 'MEDIUMBLOB should be detected');
        $this->assertNotContains('tiny', $cols, 'TINYBLOB should not be detected');
        $this->assertNotContains('regular', $cols, 'BLOB should not be detected');
        $this->assertNotContains('big', $cols, 'LONGBLOB should not be detected');
        $this->assertCount(1, $cols);
    }

    //endregion
    //region Edge Cases

    public function testEmptyStringRoundTrip(): void
    {
        self::$conn->insert('enc_users', [
            'num'   => 120,
            'name'  => 'Empty Token',
            'token' => '',
        ]);

        $row = self::$conn->selectOne('enc_users', ['num' => 120]);
        $this->assertSame('', $row->get('token')->value(), 'Empty string should survive encrypt/decrypt round-trip');
    }

    public function testMultiRowSelectRoundTrip(): void
    {
        self::$conn->insert('enc_users', ['num' => 121, 'name' => 'Multi A', 'token' => 'token-aaa']);
        self::$conn->insert('enc_users', ['num' => 122, 'name' => 'Multi B', 'token' => 'token-bbb']);
        self::$conn->insert('enc_users', ['num' => 123, 'name' => 'Multi C', 'token' => 'token-ccc']);

        $results = self::$conn->select('enc_users', "num IN (:nums) ORDER BY num", [':nums' => [121, 122, 123]]);

        $this->assertCount(3, $results);
        $tokens = $results->pluck('token')->toArray();
        $this->assertSame(['token-aaa', 'token-bbb', 'token-ccc'], array_map(fn($t) => (string) $t, $tokens));
    }

    public function testSpecialCharactersRoundTrip(): void
    {
        $values = [
            "O'Brien",
            "line1\nline2",
            "tab\there",
            "\x00\x01\x02 null bytes",
            str_repeat('x', 1000),  // multi-block AES (>16 bytes)
        ];

        foreach ($values as $i => $value) {
            self::$conn->insert('enc_users', [
                'num'   => 130 + $i,
                'name'  => "Special $i",
                'token' => $value,
            ]);

            $row = self::$conn->selectOne('enc_users', ['num' => 130 + $i]);
            $this->assertSame($value, $row->get('token')->value(), "Round-trip failed for: " . addcslashes($value, "\x00..\x1f"));
        }
    }

    public function testDoubleEncryptionIsRecoverable(): void
    {
        // If a user mistakenly calls encryptValue() AND insert() auto-encrypts,
        // the data gets double-encrypted. Auto-decrypt peels one layer, leaving
        // the still-encrypted value. Verify the original is recoverable.
        $plaintext = 'double-encrypt-test';
        $manuallyEncrypted = self::$conn->encryptValue($plaintext);

        self::$conn->insert('enc_users', [
            'num'   => 140,
            'name'  => 'Double Encrypt',
            'token' => $manuallyEncrypted,  // auto-encrypt will encrypt this again
        ]);

        // Auto-decrypt peels one layer, leaving the manually-encrypted value
        $row = self::$conn->selectOne('enc_users', ['num' => 140]);
        $afterAutoDecrypt = $row->get('token')->value();
        $this->assertNotSame($plaintext, $afterAutoDecrypt, 'Double-encrypted value should not auto-decrypt to plaintext');

        // Verify the original is recoverable by decrypting the raw value twice
        $result = self::$conn->mysqli->query("SELECT token FROM test_enc_users WHERE num = 140");
        $raw    = $result->fetch_assoc()['token'];
        $result->free();

        // Decrypt twice manually
        $aesKey    = self::getAesKey();
        $once      = openssl_decrypt($raw, 'aes-128-ecb', $aesKey, OPENSSL_RAW_DATA);
        $twice     = openssl_decrypt($once, 'aes-128-ecb', $aesKey, OPENSSL_RAW_DATA);
        $this->assertSame($plaintext, $twice, 'Double-encrypted data should be recoverable by decrypting twice');
    }

    public function testEncryptValueWithoutKeyThrows(): void
    {
        $conn = new Connection(self::$configDefaults); // no encryptionKey

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('encryptionKey');
        $conn->encryptValue('test');
    }

    public function testAutoEncryptNoOpWithoutKey(): void
    {
        // Connection without encryption key - MEDIUMBLOB columns should store plaintext
        $conn = new Connection(array_merge(self::$configDefaults, ['databaseAutoCreate' => true]));
        $conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_nokey_enc");
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_nokey_enc (num INT PRIMARY KEY, secret MEDIUMBLOB)");

        $conn->insert('nokey_enc', ['num' => 1, 'secret' => 'plaintext-value']);

        // Read raw - should be plaintext (not encrypted)
        $result = $conn->mysqli->query("SELECT secret FROM test_nokey_enc WHERE num = 1");
        $row    = $result->fetch_assoc();
        $result->free();

        $this->assertSame('plaintext-value', $row['secret'], 'Without encryption key, MEDIUMBLOB should store plaintext');
        $conn->disconnect();
    }

    public function testPhpEncryptMatchesMysqlEncrypt(): void
    {
        // Verify PHP-side encryptValue() produces identical ciphertext to MySQL's AES_ENCRYPT(@ek)
        $plaintext    = 'compatibility-test-value';
        $phpEncrypted = self::$conn->encryptValue($plaintext);

        $row = self::$conn->query(
            "SELECT AES_ENCRYPT(:val, @ek) AS mysql_encrypted",
            [':val' => $plaintext]
        )->first();

        $mysqlEncrypted = $row->get('mysql_encrypted')->value();
        $this->assertSame($phpEncrypted, $mysqlEncrypted, 'PHP encryptValue() should produce same ciphertext as MySQL AES_ENCRYPT(@ek)');
    }

    public function testCloneConnectionEncryptsAndDecrypts(): void
    {
        $clone = self::$conn->clone();

        $clone->insert('enc_users', [
            'num'   => 150,
            'name'  => 'Clone Test',
            'token' => 'cloned-secret',
        ]);

        $row = $clone->selectOne('enc_users', ['num' => 150]);
        $this->assertSame('cloned-secret', $row->get('token')->value(), 'Cloned connection should encrypt/decrypt with same key');
    }

    /**
     * Helper: derive the AES key for manual decrypt verification
     */
    private static function getAesKey(): string
    {
        $keyBytes = hash('sha512', 'test-encrypt-value-key', true);
        $chunks   = str_split($keyBytes, 16);
        return $chunks[0] ^ $chunks[1] ^ $chunks[2] ^ $chunks[3];
    }

    //endregion
}
