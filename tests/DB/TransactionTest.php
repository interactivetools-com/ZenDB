<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Error;
use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Tests for DB::transaction()
 */
class TransactionTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTestTables();
    }

    public function testCommitsOnSuccess(): void
    {
        self::resetTestTables();

        $insertId = DB::transaction(function () {
            return DB::insert('users', [
                'name'    => 'Transaction User',
                'isAdmin' => 0,
                'status'  => 'Active',
                'city'    => 'TestCity',
                'dob'     => '2000-01-01',
                'age'     => 24,
            ]);
        });

        $this->assertSame(21, $insertId);
        $row = DB::selectOne('users', ['num' => 21]);
        $this->assertSame('Transaction User', $row->get('name')->value());
    }

    public function testRollbackRevertsData(): void
    {
        DB::$mysqli->query("CREATE TABLE IF NOT EXISTS test_tx_rollback (num INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255)) ENGINE=InnoDB");
        DB::$mysqli->query("TRUNCATE TABLE test_tx_rollback");

        try {
            DB::transaction(function () {
                DB::insert('tx_rollback', ['name' => 'Should Not Exist']);
                throw new RuntimeException("force rollback");
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, DB::count('tx_rollback'), "Row should not exist after rollback");
        DB::$mysqli->query("DROP TABLE IF EXISTS test_tx_rollback");
    }

    public function testRethrowsExceptionAfterRollback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Intentional failure");

        DB::transaction(function () {
            throw new RuntimeException("Intentional failure");
        });
    }

    public function testReturnsCallableResult(): void
    {
        $result = DB::transaction(fn() => 'hello');
        $this->assertSame('hello', $result);
    }

    public function testNestedTransactionThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("cannot be nested");

        DB::transaction(function () {
            DB::transaction(fn() => null);
        });
    }

    public function testFlagResetsAfterSuccess(): void
    {
        DB::transaction(fn() => null);

        // Should not throw - flag was reset
        $result = DB::transaction(fn() => 42);
        $this->assertSame(42, $result);
    }

    public function testFlagResetsAfterException(): void
    {
        try {
            DB::transaction(function () {
                throw new RuntimeException("fail");
            });
        } catch (RuntimeException) {
            // expected
        }

        // Should not throw - flag was reset after rollback
        $result = DB::transaction(fn() => 99);
        $this->assertSame(99, $result);
    }

    public function testRollbackFailureDoesNotMaskClosureException(): void
    {
        self::requiresLiveMysql();

        try {
            DB::transaction(function () {
                // Kill our own connection from a second connection so the ROLLBACK
                // in transaction() throws "server has gone away"
                $threadId = DB::$mysqli->thread_id;
                $config   = self::$configDefaults;
                $killer   = new mysqli($config['hostname'], $config['username'], $config['password'], $config['database']);
                $killer->query("KILL $threadId");

                // KILL is asynchronous: wait until the server thread is gone so the
                // ROLLBACK reliably fails instead of racing the kill
                for ($i = 0; $i < 100; $i++) {
                    $alive = $killer->query("SELECT 1 FROM information_schema.processlist WHERE id = $threadId")->num_rows;
                    if (!$alive) {
                        break;
                    }
                    usleep(10_000);
                }
                $killer->close();

                throw new RuntimeException("original failure");
            });
            $this->fail("transaction() should have thrown");
        } catch (Throwable $e) {
            $this->assertSame(
                [RuntimeException::class, "original failure"],
                [get_class($e), $e->getMessage()],
                "The closure's exception should survive a failing ROLLBACK",
            );
        } finally {
            // The killed connection is unusable; reconnect for the remaining tests
            DB::disconnect();
            DB::connect(self::$configDefaults);
            self::resetTestTables();
        }
    }

    public function testFlagResetsAfterNestedAttempt(): void
    {
        try {
            DB::transaction(function () {
                DB::transaction(fn() => null);
            });
        } catch (RuntimeException) {
            // expected - nested transaction rejected
        }

        // Should not throw - flag was reset by finally block
        $result = DB::transaction(fn() => 'recovered');
        $this->assertSame('recovered', $result);
    }

    private const DISCONNECT_MESSAGE = "disconnect(): can't disconnect inside transaction(), MySQL would roll the transaction back. Disconnect after transaction() returns.";
    private const TEST_USER          = ['name' => 'Inside Transaction', 'isAdmin' => 0, 'status' => 'Active', 'city' => 'TestCity', 'dob' => '2000-01-01', 'age' => 24];

    public function testDisconnectThrowsInsideTransaction(): void
    {
        self::requiresLiveMysql();
        self::resetTestTables();

        try {
            DB::transaction(function () {
                DB::insert('users', self::TEST_USER);
                try {
                    DB::disconnect();
                    $this->fail("DB::disconnect() should have thrown inside transaction()");
                } catch (RuntimeException $e) {
                    $this->assertSame(self::DISCONNECT_MESSAGE, $e->getMessage());
                }
                throw new RuntimeException("roll back");
            });
            $this->fail("transaction() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertSame("roll back", $e->getMessage());
        }

        $this->assertTrue(DB::isConnected(true), "The connection is still open");
        $this->assertSame(0, DB::count('users', ['name' => 'Inside Transaction']), "The transaction was still open, so the insert rolled back");
    }

    /**
     * @dataProvider connectionThatDisconnectsProvider
     */
    public function testDisconnectThrowsInsideTransactionOnAConnectionObject(callable $connectionThatDisconnects): void
    {
        self::requiresLiveMysql();
        $db          = new Connection(self::$configDefaults);
        $disconnects = $connectionThatDisconnects($db);

        // a regular table: the second connection can't see the default connection's temporary tables
        $db->mysqli->query("CREATE TABLE IF NOT EXISTS test_tx_rollback (num INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255)) ENGINE=InnoDB");
        $db->mysqli->query("TRUNCATE TABLE test_tx_rollback");

        try {
            $db->transaction(function () use ($db, $disconnects) {
                $db->insert('tx_rollback', ['name' => 'Inside Transaction']);
                try {
                    $disconnects->disconnect();
                    $this->fail("disconnect() should have thrown inside transaction()");
                } catch (RuntimeException $e) {
                    $this->assertSame(self::DISCONNECT_MESSAGE, $e->getMessage());
                }
                throw new RuntimeException("roll back");
            });
            $this->fail("transaction() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertSame("roll back", $e->getMessage());
        }

        $this->assertTrue($db->isConnected(true), "The connection is still open");
        $this->assertSame(0, $db->count('tx_rollback', ['name' => 'Inside Transaction']), "The transaction was still open, so the insert rolled back");
        $db->disconnect(); // allowed again once transaction() has returned
    }

    public static function connectionThatDisconnectsProvider(): array
    {
        return [
            'new Connection' => [fn(Connection $db): Connection => $db],
            'a clone'        => [fn(Connection $db): Connection => $db->clone()], // clones share the mysqli object
        ];
    }

    /**
     * MySQL rolls back the open transaction when its connection closes, so there is nothing
     * left to commit and transaction() must not return as if it had.
     */
    public function testThrowsAtCommitWhenClosureClosesMysqliDirectly(): void
    {
        self::requiresLiveMysql();

        try {
            DB::transaction(function () {
                DB::$mysqli->close();
                return 'ok';
            });
            $this->fail("transaction() should have thrown, the connection it started on is closed");
        } catch (Error $e) {
            $this->assertStringContainsString("already closed", $e->getMessage());
        } finally {
            DB::disconnect();
            DB::connect(self::$configDefaults);
            self::resetTestTables();
        }
    }
}
