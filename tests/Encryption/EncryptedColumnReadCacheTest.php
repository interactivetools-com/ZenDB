<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Encryption;

use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use ReflectionMethod;

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

        // The cache is a static WeakMap inside fetchMappedRows: connection => [table => columns]
        $cache   = (new ReflectionMethod(Connection::class, 'fetchMappedRows'))->getStaticVariables()['tableCache'];
        $entries = $cache[self::$conn] ?? [];
        $this->assertSame(['secret'], $entries['test_enc_cache_a'] ?? null, "First select must cache the encrypted-column list");

        // Poison the cached list; a repeated harvest would decrypt anyway and fail this
        $entries['test_enc_cache_a'] = [];
        $cache[self::$conn]          = $entries;
        $poisoned = self::$conn->selectOne('enc_cache_a', ['num' => 1])->toArray();
        $this->assertNotSame('alpha-secret', $poisoned['secret'], "Later selects must use the cached list, not re-harvest field metadata");

        // Restore the real list for the remaining tests
        $entries['test_enc_cache_a'] = ['secret'];
        $cache[self::$conn]          = $entries;
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
