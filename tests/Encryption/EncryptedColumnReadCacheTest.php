<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Encryption;

use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli_sql_exception;
use ReflectionProperty;

/**
 * Tests the per-connection cache of encrypted-column lists used by select()/selectOne():
 * the first select on a table harvests the MEDIUMBLOB column list from the result's field
 * metadata, later selects reuse the cached list instead of calling fetch_fields().
 */
class EncryptedColumnReadCacheTest extends BaseTestCase
{
    protected static Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = self::createDefaultConnection(['encryptionKey' => 'test-secret-key']);

        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_a (num INT PRIMARY KEY, secret MEDIUMBLOB, label VARCHAR(50))");
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_b (num INT PRIMARY KEY, notes VARCHAR(100), token MEDIUMBLOB)");
        self::$conn->insert('enc_cache_a', ['num' => 1, 'secret' => 'alpha-secret', 'label' => 'alpha']);
        self::$conn->insert('enc_cache_b', ['num' => 1, 'notes' => 'plain', 'token' => 'bravo-token']);
    }

    public function testRepeatedSelectsDecrypt(): void
    {
        // First select harvests the column list, second and third hit the cache
        for ($i = 1; $i <= 3; $i++) {
            $row = self::$conn->selectOne('enc_cache_a', ['num' => 1])->toArray();
            $this->assertSame('alpha-secret', $row['secret'], "Select #$i should decrypt");
            $this->assertSame('alpha', $row['label'], "Select #$i should leave VARCHAR untouched");
        }
    }

    public function testLaterSelectsReadFromCache(): void
    {
        // Prime the cache
        $row = self::$conn->selectOne('enc_cache_a', ['num' => 1])->toArray();
        $this->assertSame('alpha-secret', $row['secret']);

        // The cache is on the wrapper that clones share: [table => columns]
        $this->assertSame(['secret'], self::$conn->mysqli->encryptedColumnsCache['test_enc_cache_a'] ?? null, "Reads must cache the encrypted-column list");

        // Poison the cached list; a repeated harvest would decrypt anyway and fail this
        self::$conn->mysqli->encryptedColumnsCache['test_enc_cache_a'] = [];
        $poisoned = self::$conn->selectOne('enc_cache_a', ['num' => 1])->toArray();
        $this->assertNotSame('alpha-secret', $poisoned['secret'], "Later selects must use the cached list, not re-harvest field metadata");

        // Restore the real list for the remaining tests
        self::$conn->mysqli->encryptedColumnsCache['test_enc_cache_a'] = ['secret'];
    }

    public function testEmptyFirstSelectStillHarvestsMetadata(): void
    {
        // Field metadata exists even on a zero-row result, so a miss-first sequence
        // must cache the right column list for the selects that follow
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_c (num INT PRIMARY KEY, payload MEDIUMBLOB)");
        self::$conn->insert('enc_cache_c', ['num' => 1, 'payload' => 'charlie-payload']);

        $this->assertCount(0, self::$conn->select('enc_cache_c', ['num' => 999]), 'First select matches nothing');

        $row = self::$conn->selectOne('enc_cache_c', ['num' => 1])->toArray();
        $this->assertSame('charlie-payload', $row['payload'], 'Second select should decrypt via the list harvested from the empty result');
    }

    public function testTablesCacheSeparateColumnLists(): void
    {
        // Different tables, different encrypted columns in different positions
        $rowA = self::$conn->selectOne('enc_cache_a', ['num' => 1])->toArray();
        $rowB = self::$conn->selectOne('enc_cache_b', ['num' => 1])->toArray();
        $this->assertSame('alpha-secret', $rowA['secret']);
        $this->assertSame('bravo-token', $rowB['token']);
        $this->assertSame('plain', $rowB['notes']);
    }

    public function testTableWithNoEncryptedColumns(): void
    {
        // Keyed connection, table with no MEDIUMBLOB: cache stores an empty list
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_plain (num INT PRIMARY KEY, name VARCHAR(50))");
        self::$conn->mysqli->query("INSERT INTO test_enc_cache_plain VALUES (1, 'delta')");

        for ($i = 1; $i <= 2; $i++) {
            $row = self::$conn->selectOne('enc_cache_plain', ['num' => 1])->toArray();
            $this->assertSame('delta', $row['name'], "Select #$i should pass plain columns through");
        }
    }

    public function testDdlThroughQueryRefreshesCache(): void
    {
        // BLOB isn't auto-encrypted; the first insert caches "no encrypted columns"
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_ddl (num INT PRIMARY KEY, secret BLOB)");
        self::$conn->insert('enc_cache_ddl', ['num' => 1, 'secret' => 'plain-while-blob']);

        $raw = self::$conn->mysqli->query("SELECT secret FROM test_enc_cache_ddl WHERE num = 1")->fetch_row()[0];
        $this->assertSame('plain-while-blob', $raw, 'BLOB column stores plaintext');

        // DDL through query() must drop the cached classification so the next write re-probes
        self::$conn->query("ALTER TABLE ::enc_cache_ddl MODIFY secret MEDIUMBLOB");
        self::$conn->insert('enc_cache_ddl', ['num' => 2, 'secret' => 'encrypted-after-alter']);

        $raw = self::$conn->mysqli->query("SELECT secret FROM test_enc_cache_ddl WHERE num = 2")->fetch_row()[0];
        $this->assertNotSame('encrypted-after-alter', $raw, 'Write after ALTER must encrypt');

        $row = self::$conn->selectOne('enc_cache_ddl', ['num' => 2])->toArray();
        $this->assertSame('encrypted-after-alter', $row['secret'], 'Read after ALTER must decrypt');
    }

    public function testDdlThroughCloneRefreshesCacheForAll(): void
    {
        // Same flow as testDdlThroughQueryRefreshesCache, with the ALTER run through a clone
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_clone (num INT PRIMARY KEY, secret BLOB)");
        self::$conn->insert('enc_cache_clone', ['num' => 1, 'secret' => 'plain-while-blob']);
        self::$conn->selectOne('enc_cache_clone', ['num' => 1]);

        self::$conn->clone()->query("ALTER TABLE ::enc_cache_clone MODIFY secret MEDIUMBLOB");
        self::$conn->insert('enc_cache_clone', ['num' => 2, 'secret' => 'encrypted-after-alter']);

        $raw = self::$conn->mysqli->query("SELECT secret FROM test_enc_cache_clone WHERE num = 2")->fetch_row()[0];
        $this->assertNotSame('encrypted-after-alter', $raw, 'Write after a clone ran ALTER must encrypt');

        $row = self::$conn->selectOne('enc_cache_clone', ['num' => 2])->toArray();
        $this->assertSame('encrypted-after-alter', $row['secret'], 'Read after a clone ran ALTER must decrypt');
    }

    /**
     * @dataProvider provideDdlBehindComments
     */
    public function testDdlBehindCommentRefreshesCache(string $suffix, string $ddlTemplate): void
    {
        // Same flow as testDdlThroughQueryRefreshesCache, with the ALTER behind a comment
        $table = "test_enc_cache_$suffix";
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE $table (num INT PRIMARY KEY, secret BLOB)");
        self::$conn->insert("enc_cache_$suffix", ['num' => 1, 'secret' => 'plain-while-blob']);

        self::$conn->query(str_replace('{table}', $table, $ddlTemplate));
        self::$conn->insert("enc_cache_$suffix", ['num' => 2, 'secret' => 'encrypted-after-alter']);

        $raw = self::$conn->mysqli->query("SELECT secret FROM $table WHERE num = 2")->fetch_row()[0];
        $this->assertNotSame('encrypted-after-alter', $raw, 'Write after ALTER must encrypt');
    }

    public function testInvisibleColumnIsEncrypted(): void
    {
        // SELECT * leaves out INVISIBLE columns (MySQL 8.0.23+, MariaDB 10.3+), so writes look columns up by name
        try {
            self::$conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_inv (num INT PRIMARY KEY, token MEDIUMBLOB INVISIBLE)");
        } catch (mysqli_sql_exception) {
            $this->markTestSkipped('This server has no invisible columns');
        }

        self::$conn->insert('enc_cache_inv', ['num' => 1, 'token' => 'inserted-secret']);
        $raw = self::$conn->mysqli->query("SELECT token FROM test_enc_cache_inv WHERE num = 1")->fetch_row()[0];
        $this->assertNotSame('inserted-secret', $raw, 'Insert into an invisible MEDIUMBLOB must encrypt');

        self::$conn->update('enc_cache_inv', ['Token' => 'updated-secret'], ['num' => 1]);
        $raw = self::$conn->mysqli->query("SELECT token FROM test_enc_cache_inv WHERE num = 1")->fetch_row()[0];
        $this->assertNotSame('updated-secret', $raw, 'Update with a different-case key must encrypt too');

        $row = self::$conn->queryOne("SELECT token FROM ::enc_cache_inv WHERE num = :num", [':num' => 1])->toArray();
        $this->assertSame('updated-secret', $row['token'], 'Naming the column in a query decrypts it');
    }

    public static function provideDdlBehindComments(): array
    {
        // Full table names, not ::, since a placeholder inside /* */ throws
        return [
            'block comment first'  => ['ddl_block', "/* migration */ ALTER TABLE {table} MODIFY secret MEDIUMBLOB"],
            'line comment first'   => ['ddl_dash', "-- migration\nALTER TABLE {table} MODIFY secret MEDIUMBLOB"],
            'hash comment first'   => ['ddl_hash', "# migration\nALTER TABLE {table} MODIFY secret MEDIUMBLOB"],
            'versioned comment'    => ['ddl_versioned', "/*! ALTER TABLE {table} MODIFY secret MEDIUMBLOB */"],
        ];
    }

    public function testReconnectClearsCacheAndRearmsDecryptWarning(): void
    {
        $conn = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'lifecycle-key']));
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_life (num INT PRIMARY KEY, secret MEDIUMBLOB)");
        $conn->insert('enc_cache_life', ['num' => 1, 'secret' => 'cached']);

        $conn->selectOne('enc_cache_life', ['num' => 1]);

        $warnProp = new ReflectionProperty(Connection::class, 'decryptWarned');
        $this->assertNotSame([], $conn->mysqli->encryptedColumnsCache, 'Select must populate the read cache');
        $this->assertNotSame([], $conn->mysqli->writeColumnsCache, 'Insert must populate the write cache');
        $warnProp->setValue($conn, true);

        // Schema can change while disconnected, so all three must reset for a clean reconnect
        $conn->disconnect();
        $this->assertFalse($warnProp->getValue($conn), 'Disconnect must re-arm the decrypt warning');
        $conn->connect();
        $this->assertSame([], $conn->mysqli->encryptedColumnsCache, 'Reconnect must start with no cached column lists');
        $this->assertSame([], $conn->mysqli->writeColumnsCache, 'Reconnect must start with no cached write columns');
    }

    public function testUnkeyedConnectionPassesRawBytesThrough(): void
    {
        // Without encryptionKey the cache never engages and MEDIUMBLOB values come back as stored
        $conn = new Connection(self::$configDefaults);
        $conn->mysqli->query("CREATE TEMPORARY TABLE test_enc_cache_nokey (num INT PRIMARY KEY, data MEDIUMBLOB)");
        $conn->mysqli->query("INSERT INTO test_enc_cache_nokey VALUES (1, 'raw-bytes-as-stored')");

        try {
            for ($i = 1; $i <= 2; $i++) {
                $row = $conn->selectOne('enc_cache_nokey', ['num' => 1])->toArray();
                $this->assertSame('raw-bytes-as-stored', $row['data'], "Select #$i should return stored bytes unchanged");
            }
        } finally {
            $conn->disconnect();
        }
    }
}
