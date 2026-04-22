<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Encryption;

use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Regression tests for auto-decryption when SmartJoins adds qualified
 * (`table.column`) and self-join alias (`alias.column`) keys to each row.
 *
 * Bug: decryptRows() only mutates the bare column key, leaving qualified
 * and alias keys holding raw AES ciphertext.
 *
 * @covers \Itools\ZenDB\Connection::decryptRows
 * @covers \Itools\ZenDB\ConnectionInternals::fetchMappedRows
 */
class EncryptedSmartJoinsTest extends BaseTestCase
{
    //region Setup

    protected static Connection $conn;
    private static bool $permanentTableCreated = false;

    public static function setUpBeforeClass(): void
    {
        self::$conn = self::createDefaultConnection(['encryptionKey' => 'test-secret-key']);
        self::resetTempTestTables();

        $m = self::$conn->mysqli;

        // Two temp tables with encrypted MEDIUMBLOB `token` columns (same name on purpose)
        $m->query("DROP TEMPORARY TABLE IF EXISTS test_enc_accounts");
        $m->query("CREATE TEMPORARY TABLE test_enc_accounts (num INT PRIMARY KEY, token MEDIUMBLOB, label VARCHAR(50))");
        $m->query("DROP TEMPORARY TABLE IF EXISTS test_enc_sessions");
        $m->query("CREATE TEMPORARY TABLE test_enc_sessions (num INT PRIMARY KEY, account_id INT, token MEDIUMBLOB)");

        self::$conn->insert('enc_accounts', ['num' => 1, 'token' => 'account-token-1', 'label' => 'alpha']);
        self::$conn->insert('enc_accounts', ['num' => 2, 'token' => 'account-token-2', 'label' => 'beta']);
        self::$conn->insert('enc_sessions', ['num' => 10, 'account_id' => 1, 'token' => 'session-token-10']);
        self::$conn->insert('enc_sessions', ['num' => 11, 'account_id' => 2, 'token' => null]);

        // Self-joins don't work on TEMPORARY tables in MySQL, need a real table
        if (!self::$permanentTableCreated) {
            $m->query("DROP TABLE IF EXISTS test_enc_refs_perm");
            $m->query("CREATE TABLE test_enc_refs_perm (id INT PRIMARY KEY, parent_id INT NULL, token MEDIUMBLOB)");
            self::$conn->insert('enc_refs_perm', ['id' => 1, 'parent_id' => null, 'token' => 'root-token']);
            self::$conn->insert('enc_refs_perm', ['id' => 2, 'parent_id' => 1,    'token' => 'child-token']);
            self::$permanentTableCreated = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::$conn->mysqli->query("DROP TABLE IF EXISTS test_enc_refs_perm");
        } catch (\Exception) {
            // ignore cleanup errors
        }
    }

    //endregion
    //region Cross-Table Joins

    public function testSmartJoinQualifiedKeyDecrypted(): void
    {
        $row = self::$conn->query(
            "SELECT a.token, s.account_id FROM ::enc_accounts a
             JOIN ::enc_sessions s ON a.num = s.account_id
             WHERE a.num = ?",
            1
        )->first();

        $this->assertSame('account-token-1', $row->get('token')->value(),              'Bare key should decrypt');
        $this->assertSame('account-token-1', $row->get('enc_accounts.token')->value(), 'Qualified key should decrypt');
    }

    public function testJoinedTablesWithSameEncryptedColumnName(): void
    {
        $row = self::$conn->query(
            "SELECT a.token, s.token FROM ::enc_accounts a
             JOIN ::enc_sessions s ON a.num = s.account_id
             WHERE a.num = ?",
            1
        )->first();

        // First-wins on bare key (documented SmartJoins behavior); both qualified keys must decrypt
        $this->assertSame('account-token-1',  $row->get('token')->value(),              'Bare key uses first column (accounts.token)');
        $this->assertSame('account-token-1',  $row->get('enc_accounts.token')->value(), 'Accounts qualified key should decrypt');
        $this->assertSame('session-token-10', $row->get('enc_sessions.token')->value(), 'Sessions qualified key should decrypt');
    }

    public function testNullEncryptedColumnInSmartJoin(): void
    {
        $row = self::$conn->query(
            "SELECT a.token, s.token FROM ::enc_accounts a
             JOIN ::enc_sessions s ON a.num = s.account_id
             WHERE a.num = ?",
            2
        )->first();

        $this->assertSame('account-token-2', $row->get('enc_accounts.token')->value());
        $this->assertNull($row->get('enc_sessions.token')->value(), 'NULL should stay NULL through qualified key');
    }

    //endregion
    //region Self-Joins

    public function testSelfJoinAliasKeyDecrypted(): void
    {
        $row = self::$conn->query(
            "SELECT c.id, c.token, p.token AS parent_token
             FROM ::enc_refs_perm c
             LEFT JOIN ::enc_refs_perm p ON c.parent_id = p.id
             WHERE c.id = ?",
            2
        )->first();

        // Bare keys already decrypt via existing decryptRows path (regression guard)
        $this->assertSame('child-token', $row->get('token')->value());
        $this->assertSame('root-token',  $row->get('parent_token')->value());

        // Qualified and alias keys currently hold ciphertext
        $this->assertSame('child-token', $row->get('enc_refs_perm.token')->value(), 'Qualified key should decrypt');
        $this->assertSame('child-token', $row->get('c.token')->value(),             'Child alias key should decrypt');
        $this->assertSame('root-token',  $row->get('p.token')->value(),             'Parent alias key should decrypt');
    }

    //endregion
}
