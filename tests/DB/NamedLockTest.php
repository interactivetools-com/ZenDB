<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection SqlResolve */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use InvalidArgumentException;
use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Tests for DB::getLock(), DB::releaseLock() and DB::withLock()
 *
 * A lock name is shared by the whole MySQL server, so every test uses a name of its own.
 * Each test releases what it took: a lock left held makes the next DB::disconnect() throw.
 */
class NamedLockTest extends BaseTestCase
{
    /**
     * A second connection: it asks for locks the default connection holds, and reads who holds a lock
     */
    private static Connection $other;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::$other = new Connection(self::$configDefaults);
    }

    public static function tearDownAfterClass(): void
    {
        self::$other->disconnect();
    }

    protected function setUp(): void
    {
        self::requiresLiveMysql();
    }

    protected function tearDown(): void
    {
        // A failed test can leave a lock held
        foreach ([DB::$mysqli, self::$other->mysqli] as $mysqli) {
            if ($mysqli instanceof MysqliWrapper) {
                $mysqli->releaseHeldLocks();
            }
        }
    }

    //region Helpers

    /**
     * The name ZenDB sends to the server for $name: "database.tablePrefix.name" in lowercase.
     */
    private static function serverName(string $name, string $tablePrefix = 'test_'): string
    {
        return strtolower(self::$configDefaults['database'] . ".$tablePrefix.$name");
    }

    /**
     * Connection id that holds a server-side lock name, or null when the lock is free.
     */
    private static function holderOf(string $lockName): ?int
    {
        $escapedName = self::$other->mysqli->real_escape_string($lockName);
        return self::$other->mysqli->query("SELECT IS_USED_LOCK('$escapedName')")->fetch_row()[0];
    }

    /**
     * KILL a connection from a new one, and wait until the server has closed it.
     */
    private static function killConnection(int $threadId): void
    {
        $config = self::$configDefaults;
        $killer = new mysqli($config['hostname'], $config['username'], $config['password'], $config['database']);
        $killer->query("KILL $threadId");

        // KILL returns before the thread is gone
        for ($i = 0; $i < 100; $i++) {
            if (!$killer->query("SELECT 1 FROM information_schema.processlist WHERE id = $threadId")->num_rows) {
                break;
            }
            usleep(10_000);
        }
        $killer->close();
    }

    /**
     * Run tests/Support/bin/named-lock-shutdown.php. Returns [stdout, stderr, exit code].
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function runShutdownChild(string $scenario, string $hostnamePrefix): array
    {
        return $this->runCommand([
            PHP_BINARY,
            dirname(__DIR__) . '/Support/bin/named-lock-shutdown.php',
            $scenario,
            $hostnamePrefix . self::$configDefaults['hostname'],
            self::$configDefaults['username'],
            self::$configDefaults['password'],
            self::$configDefaults['database'],
        ]);
    }

    //endregion
    //region getLock() and releaseLock()

    public function testGetLockTakesAFreeNameAndRefusesOneAnotherConnectionHolds(): void
    {
        $this->assertTrue(DB::getLock('free_name'));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('free_name')));
        $this->assertFalse(self::$other->getLock('free_name'), "A second connection must not get a held lock");

        $this->assertTrue(DB::releaseLock('free_name'));
        $this->assertNull(self::holderOf(self::serverName('free_name')));
    }

    #[Group('slow')]
    public function testGetLockWaitsWaitSecondsForAHeldName(): void
    {
        self::$other->getLock('wait_held');

        $start = microtime(true);
        $this->assertFalse(DB::getLock('wait_held', 1));
        $waited = microtime(true) - $start;
        $this->assertGreaterThan(0.9, $waited, "A held name must wait about a second");
        $this->assertLessThan(3.0, $waited);

        $start = microtime(true);
        $this->assertTrue(DB::getLock('wait_free', 1));
        $this->assertLessThan(0.9, microtime(true) - $start, "A free name must not wait");

        DB::releaseLock('wait_free');
        self::$other->releaseLock('wait_held');
    }

    public function testReleaseLockReturnsTrueThenFalse(): void
    {
        DB::getLock('release_once');
        $this->assertTrue(DB::releaseLock('release_once'));
        $this->assertFalse(DB::releaseLock('release_once'), "Nobody holds it now");

        self::$other->getLock('release_other');
        $this->assertFalse(DB::releaseLock('release_other'), "Another connection holds it");
        $this->assertSame(self::$other->mysqli->thread_id, self::holderOf(self::serverName('release_other')), "The other connection must still hold it");
        self::$other->releaseLock('release_other');
    }

    public function testSameNameTakenTwiceNeedsTwoReleases(): void
    {
        $this->assertTrue(DB::getLock('twice'));
        $this->assertTrue(DB::getLock('twice'));
        $this->assertSame(['name' => 'twice', 'count' => 2], DB::$mysqli->heldLocks[self::serverName('twice')]);

        $this->assertTrue(DB::releaseLock('twice'));
        $this->assertFalse(self::$other->getLock('twice'), "One release of two must leave the lock held");
        $this->assertSame(['name' => 'twice', 'count' => 1], DB::$mysqli->heldLocks[self::serverName('twice')], "The list must still have the take that is left");

        $this->assertTrue(DB::releaseLock('twice'));
        $this->assertSame([], DB::$mysqli->heldLocks);
        $this->assertTrue(self::$other->getLock('twice'));
        self::$other->releaseLock('twice');
    }

    public function testTwoNamesHeldAtOnce(): void
    {
        $this->assertTrue(DB::getLock('first_of_two'));
        $this->assertTrue(DB::getLock('second_of_two'));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('first_of_two')));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('second_of_two')));

        $this->assertTrue(DB::releaseLock('first_of_two'));
        $this->assertNull(self::holderOf(self::serverName('first_of_two')));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('second_of_two')), "Releasing one name must leave the other held");

        $this->assertTrue(DB::releaseLock('second_of_two'));
    }

    #[DataProvider('invalidArgumentsProvider')]
    public function testInvalidArgumentsThrowBeforeAnyQuery(callable $call, string $expectedMessage): void
    {
        $queriesBefore = DB::$queryCount;

        try {
            $call();
            $this->fail("Expected InvalidArgumentException: $expectedMessage");
        } catch (InvalidArgumentException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }

        $this->assertSame($queriesBefore, DB::$queryCount, "No query may be sent");
        $this->assertSame([], DB::$mysqli->heldLocks);
    }

    public static function invalidArgumentsProvider(): array
    {
        $mustNotRun   = static fn() => self::fail("The callback must not run");
        $emptyName    = "Lock name can't be empty";
        $negativeWait = "\$waitSeconds can't be negative, got -1";
        $waitTooLong  = "Can't wait 60 seconds for a lock: `readTimeout` is 60 seconds and ends the connection first. Wait less, or raise `readTimeout` when connecting.";

        $closureName  = "Lock name `%s` isn't unique: __FUNCTION__ returns it for every closure. Use __FUNCTION__ in a named function, or pass a name of your own.";

        return [
            'getLock(): empty name'                   => [static fn() => DB::getLock(''), $emptyName],
            'releaseLock(): empty name'               => [static fn() => DB::releaseLock(''), $emptyName],
            'withLock(): empty name'                  => [static fn() => DB::withLock('', $mustNotRun), $emptyName],
            'getLock(): closure name'                 => [static fn() => DB::getLock('{closure}'), sprintf($closureName, '{closure}')],
            'releaseLock(): closure name'             => [static fn() => DB::releaseLock('{closure}'), sprintf($closureName, '{closure}')],
            'withLock(): closure name in a namespace' => [static fn() => DB::withLock('Acme\Jobs\{closure}', $mustNotRun), sprintf($closureName, 'Acme\Jobs\{closure}')],
            'getLock(): closure name with HTML'       => [static fn() => DB::getLock('<b>{closure}'), sprintf($closureName, '&lt;b&gt;{closure}')],
            'getLock(): negative wait'                => [static fn() => DB::getLock('invalid_args', -1), $negativeWait],
            'withLock(): negative wait'               => [static fn() => DB::withLock('invalid_args', $mustNotRun, -1), $negativeWait],
            'getLock(): wait as long as readTimeout'  => [static fn() => DB::getLock('invalid_args', 60), $waitTooLong],
            'withLock(): wait as long as readTimeout' => [static fn() => DB::withLock('invalid_args', $mustNotRun, 60), $waitTooLong],
        ];
    }

    public function testClosureNameWithFunctionAndLineIsAllowed(): void
    {
        $name = '{closure:Acme\Jobs\sendQueuedEmails():6}'; // what __FUNCTION__ returns inside a closure from PHP 8.4

        $this->assertTrue(DB::getLock($name));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName($name)));
        $this->assertTrue(DB::releaseLock($name));
    }

    public function testWaitOneSecondShorterThanReadTimeoutIsAllowed(): void
    {
        $this->assertTrue(DB::getLock('wait_under_read_timeout', 59)); // a free name returns at once
        DB::releaseLock('wait_under_read_timeout');
    }

    #[DataProvider('noReadTimeoutProvider')]
    public function testReadTimeoutOfZeroOrLessAllowsAnyWait(int $readTimeout): void
    {
        $db = new Connection(['readTimeout' => $readTimeout] + self::$configDefaults);

        $this->assertTrue($db->getLock('no_read_timeout', 3600));
        $db->releaseLock('no_read_timeout');
        $db->disconnect();
    }

    public static function noReadTimeoutProvider(): array
    {
        return [
            'readTimeout 0'  => [0],
            'readTimeout -1' => [-1],
        ];
    }

    //endregion
    //region Lock Names

    public function testServerSideNameIsDatabaseTablePrefixAndName(): void
    {
        DB::getLock('server_name');

        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::$configDefaults['database'] . '.test_.server_name'));
        DB::releaseLock('server_name');
    }

    public function testUpperAndLowerCaseAreTheSameLock(): void
    {
        $this->assertTrue(DB::getLock('Import_Products'));
        $this->assertFalse(self::$other->getLock('import_products'));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::$configDefaults['database'] . '.test_.import_products'));

        $this->assertTrue(DB::releaseLock('IMPORT_PRODUCTS'));
    }

    public function testDatabaseNameInCapitalsIsTheSameLock(): void
    {
        // databaseAutoCreate is off so a server with case-sensitive database names refuses the connection
        try {
            $upper = new Connection(['database' => strtoupper(self::$configDefaults['database']), 'databaseAutoCreate' => false] + self::$configDefaults);
        } catch (RuntimeException) {
            $this->markTestSkipped('Database names are case-sensitive on this server');
        }

        $this->assertTrue($upper->getLock('database_case'));
        $this->assertSame($upper->mysqli->thread_id, self::holderOf(self::serverName('database_case')));
        $this->assertFalse(DB::getLock('database_case'));

        $upper->releaseLock('database_case');
        $upper->disconnect();
    }

    public function testNameOver64CharactersIsCutAndHashed(): void
    {
        $name     = 'nlb_mailer_queueScheduledMessages_forEveryMailingList';
        $fullName = self::serverName($name);
        $expected = substr($fullName, 0, 47) . '~' . substr(md5($fullName), 0, 16);
        $this->assertGreaterThan(64, strlen($fullName));
        $this->assertSame(64, strlen($expected));

        $this->assertTrue(DB::getLock($name));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf($expected));

        // Same first 47 characters, different ending: a different lock
        $this->assertTrue(self::$other->getLock($name . '_b'));
        $this->assertFalse(self::$other->getLock($name));

        $this->assertTrue(DB::releaseLock($name));
        $this->assertNull(self::holderOf($expected));
        self::$other->releaseLock($name . '_b');
    }

    public function testNameOf64BytesIsSentWholeAndOneOf65BytesIsCut(): void
    {
        $name64 = str_repeat('a', 64 - strlen(self::serverName('')));
        $name65 = $name64 . 'b';
        $full65 = self::serverName($name65);
        $this->assertSame(65, strlen($full65));

        $this->assertTrue(DB::getLock($name64));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName($name64)));

        $this->assertTrue(DB::getLock($name65)); // MySQL throws error 4163 for a name of 65 characters
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(substr($full65, 0, 47) . '~' . substr(md5($full65), 0, 16)));

        DB::releaseLock($name64);
        DB::releaseLock($name65);
    }

    #[DataProvider('multibyteCutProvider')]
    public function testLongNameIsNotCutInsideAMultibyteCharacter(string $character, int $asciiLength, int $expectedKeep): void
    {
        $name     = str_repeat('x', $asciiLength) . str_repeat($character, 30);
        $fullName = self::serverName($name);
        $this->assertSame($character, substr($fullName, $expectedKeep, strlen($character)), "A character must start at byte $expectedKeep for this test");
        $expected = substr($fullName, 0, $expectedKeep) . '~' . substr(md5($fullName), 0, 16);

        $this->assertTrue(DB::getLock($name)); // MySQL throws error 4163 for a name that ends inside a character
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf($expected));

        $this->assertTrue(DB::releaseLock($name));
    }

    public static function multibyteCutProvider(): array
    {
        $prefixLength = strlen(self::serverName('')); // "database.tablePrefix."

        return [
            '2-byte character, 1 byte before the cut'  => ["\u{e9}", 46 - $prefixLength, 46],
            '3-byte character, 2 bytes before the cut' => ["\u{20ac}", 45 - $prefixLength, 45],
            '4-byte character, 3 bytes before the cut' => ["\u{1f600}", 44 - $prefixLength, 44],
            '2-byte character that ends at the cut'    => ["\u{e9}", 45 - $prefixLength, 45], // a whole character at the end is removed too
        ];
    }

    public function testLongNameKeepsMultibyteCharactersThatAreNotAtTheCut(): void
    {
        $name     = str_repeat("\u{e9}", 5) . str_repeat('x', 60);
        $fullName = self::serverName($name);
        $expected = substr($fullName, 0, 47) . '~' . substr(md5($fullName), 0, 16);

        $this->assertTrue(DB::getLock($name));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf($expected), "Only non-ASCII bytes at the end of the first 47 are removed");
        $this->assertTrue(DB::releaseLock($name));
    }

    public function testCloneWithAnotherTablePrefixGetsADifferentLock(): void
    {
        $otherPrefix = self::$other->clone(['tablePrefix' => 'other_']);

        $this->assertTrue(DB::getLock('per_prefix'));
        $this->assertFalse(self::$other->getLock('per_prefix'), "Same prefix: the same lock");
        $this->assertTrue($otherPrefix->getLock('per_prefix'), "Another prefix: a different lock");
        $this->assertSame(self::$other->mysqli->thread_id, self::holderOf(self::serverName('per_prefix', 'other_')));

        $otherPrefix->releaseLock('per_prefix');
        DB::releaseLock('per_prefix');
    }

    public function testNameWithBackslashesColonsAndQuotes(): void
    {
        $name = "Acme\\Jobs\\Import::run's \"queue\"";

        $this->assertTrue(DB::getLock($name));
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName($name)));
        $this->assertFalse(self::$other->getLock($name));

        $this->assertTrue(DB::releaseLock($name));
        $this->assertNull(self::holderOf(self::serverName($name)));
    }

    //endregion
    //region withLock()

    public function testWithLockRunsTheCallbackAndReleases(): void
    {
        $holderInside = null;
        $ran          = DB::withLock('with_lock', function () use (&$holderInside) {
            $holderInside = self::holderOf(self::serverName('with_lock'));
            return 'dropped';
        });

        $this->assertTrue($ran, "withLock() returns true, not the callback's value");
        $this->assertSame(DB::$mysqli->thread_id, $holderInside, "The lock must be held while the callback runs");
        $this->assertNull(self::holderOf(self::serverName('with_lock')));
        $this->assertSame([], DB::$mysqli->heldLocks);
    }

    public function testWithLockSkipsTheCallbackWhenAnotherConnectionHoldsTheLock(): void
    {
        self::$other->getLock('with_lock_held');

        $called = false;
        $ran    = DB::withLock('with_lock_held', function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($ran);
        $this->assertFalse($called);
        self::$other->releaseLock('with_lock_held');
    }

    public function testWithLockPassesWaitSecondsToGetLock(): void
    {
        $queries = [];
        $db      = new Connection(['queryLogger' => function (string $query) use (&$queries) {
            $queries[] = $query;
        }] + self::$configDefaults);

        $db->withLock('with_lock_wait', fn() => null, 3);
        $db->disconnect();

        $this->assertContains("SELECT GET_LOCK('" . self::serverName('with_lock_wait') . "', 3)", $queries);
    }

    public function testWithLockReleasesAndRethrowsWhenTheCallbackThrows(): void
    {
        $thrown = new RuntimeException("callback failed");

        try {
            DB::withLock('with_lock_throws', fn() => throw $thrown);
            $this->fail("withLock() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }

        $this->assertNull(self::holderOf(self::serverName('with_lock_throws')));
        $this->assertSame([], DB::$mysqli->heldLocks);
    }

    public function testWithLockThrowsWhenTheCallbackReleasedTheLock(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("withLock(): lock `with_lock_gone` was no longer held when the callback finished, so another copy may have run at the same time");

        DB::withLock('with_lock_gone', fn() => DB::releaseLock('with_lock_gone'));
    }

    public function testWithLockKeepsTheCallbacksExceptionWhenTheLockIsAlreadyGone(): void
    {
        $thrown = new RuntimeException("callback failed");

        try {
            DB::withLock('with_lock_both', function () use ($thrown) {
                DB::releaseLock('with_lock_both');
                throw $thrown;
            });
            $this->fail("withLock() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }
    }

    public function testWithLockThrowsAndKeepsTheLockWhenTheCallbackLeavesResultsUnread(): void
    {
        $thrown = new RuntimeException("callback failed");
        $caught = null;

        try {
            DB::withLock('unread_results', function () use ($thrown) {
                DB::$mysqli->multi_query("SELECT 1; SELECT 2"); // no query can be sent until both results are read
                throw $thrown;
            });
        } catch (mysqli_sql_exception $e) {
            $caught = $e;
        } finally {
            // Read both results, so the connection takes queries again for the rest of this test and the next ones
            do {
                if ($result = DB::$mysqli->store_result()) {
                    $result->free();
                }
            } while (DB::$mysqli->more_results() && DB::$mysqli->next_result());
        }

        $this->assertSame(2014, $caught?->getCode(), "Expected 'Commands out of sync' from the release query");
        $this->assertSame($thrown, $caught->getPrevious(), "The callback's exception must be the previous one");
        $this->assertSame(['name' => 'unread_results', 'count' => 1], DB::$mysqli->heldLocks[self::serverName('unread_results')] ?? null, "The connection is open, so the lock must stay on the list");
        $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('unread_results')));

        $this->assertTrue(DB::releaseLock('unread_results'), "With the results read, the release must work");
        $this->assertNull(self::holderOf(self::serverName('unread_results')));
    }

    public function testWithLockNestsWithTheSameNameAndWithTwoNames(): void
    {
        $steps = [];
        $ran   = DB::withLock('nest_outer', function () use (&$steps) {
            $steps[] = DB::withLock('nest_outer', fn() => null) ? 'same name ran' : 'same name skipped';
            $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('nest_outer')), "The inner release must leave the outer take held");

            $steps[] = DB::withLock('nest_inner', function () {
                $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('nest_outer')));
                $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('nest_inner')));
            }) ? 'second name ran' : 'second name skipped';
        });

        $this->assertTrue($ran);
        $this->assertSame(['same name ran', 'second name ran'], $steps);
        $this->assertNull(self::holderOf(self::serverName('nest_outer')));
        $this->assertNull(self::holderOf(self::serverName('nest_inner')));
    }

    public function testWithLockThrowsWhenTheConnectionIsKilledDuringTheCallback(): void
    {
        try {
            DB::withLock('with_lock_killed', fn() => self::killConnection(DB::$mysqli->thread_id));
            $this->fail("withLock() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertSame("withLock(): lock `with_lock_killed` was no longer held when the callback finished, so another copy may have run at the same time", $e->getMessage());
            $this->assertSame([], DB::$mysqli->heldLocks, "A lock the server no longer has must be off the list");
        } finally {
            DB::$mysqli->releaseHeldLocks(); // empties the list when the assertion above failed; disconnect() throws while a lock is listed
            self::createDefaultConnection(); // the killed connection is unusable; reconnect for the remaining tests
        }
    }

    public function testTransactionInsideWithLockCommitsBeforeTheRelease(): void
    {
        DB::$mysqli->query("CREATE TABLE IF NOT EXISTS test_lock_tx (num INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255)) ENGINE=InnoDB");
        DB::$mysqli->query("TRUNCATE TABLE test_lock_tx");

        try {
            $ran = DB::withLock('with_lock_tx', function () {
                DB::transaction(fn() => DB::insert('lock_tx', ['name' => 'inside the lock']));

                $this->assertSame(1, self::$other->count('lock_tx'), "The second connection must see the committed row");
                $this->assertSame(DB::$mysqli->thread_id, self::holderOf(self::serverName('with_lock_tx')), "The lock must still be held after the commit");
            });

            $this->assertTrue($ran);
            $this->assertNull(self::holderOf(self::serverName('with_lock_tx')));
        } finally {
            DB::$mysqli->query("DROP TABLE IF EXISTS test_lock_tx");
        }
    }

    public function testLockNamesAreHtmlEncodedInMessages(): void
    {
        $name = '<b>"import"</b>';

        try {
            DB::withLock($name, fn() => DB::releaseLock($name));
            $this->fail("withLock() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lock `&lt;b&gt;&quot;import&quot;&lt;/b&gt;` was no longer held', $e->getMessage());
        }

        DB::getLock($name);
        try {
            DB::disconnect();
            $this->fail("disconnect() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('holding lock `&lt;b&gt;&quot;import&quot;&lt;/b&gt;`,', $e->getMessage());
        }
        DB::releaseLock($name);
    }

    //endregion
    //region disconnect() and close()

    public function testDisconnectThrowsWhileALockIsHeld(): void
    {
        $db = new Connection(self::$configDefaults);
        $db->getLock('disconnect_held');

        try {
            $db->disconnect();
            $this->fail("disconnect() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertSame("disconnect(): can't disconnect while holding lock `disconnect_held`, a new connection wouldn't hold it. Call releaseLock() first, on the `Connection` that took it.", $e->getMessage());
        }
        $this->assertTrue($db->isConnected(true), "The connection must still run queries");
        $this->assertSame($db->mysqli->thread_id, self::holderOf(self::serverName('disconnect_held')), "The lock must still be held");

        $this->assertTrue($db->releaseLock('disconnect_held'));
        $db->disconnect();
        $this->assertFalse($db->isConnected());
    }

    public function testLockTakenThroughACloneBlocksDisconnectOnTheOriginal(): void
    {
        $db    = new Connection(self::$configDefaults);
        $clone = $db->clone(['tablePrefix' => 'other_']);
        $clone->getLock('clone_held');

        try {
            $db->disconnect();
            $this->fail("disconnect() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertSame("disconnect(): can't disconnect while holding lock `clone_held`, a new connection wouldn't hold it. Call releaseLock() first, on the `Connection` that took it.", $e->getMessage());
        }
        $this->assertFalse($db->releaseLock('clone_held'), "The original asks for the name with its own tablePrefix, so only the clone can release it");

        // A second connection has a list of its own
        $unrelated = new Connection(self::$configDefaults);
        $unrelated->disconnect();
        $this->assertFalse($unrelated->isConnected());

        $clone->releaseLock('clone_held');
        $db->disconnect();
        $this->assertFalse($db->isConnected());
    }

    public function testDbDisconnectLeavesTheDefaultConnectionInPlaceWhenItThrows(): void
    {
        $mysqliBefore = DB::$mysqli;
        DB::getLock('db_disconnect_held');

        try {
            DB::disconnect();
            $this->fail("DB::disconnect() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("holding lock `db_disconnect_held`", $e->getMessage());
        }

        $this->assertSame($mysqliBefore, DB::$mysqli);
        $this->assertTrue(DB::isConnected(true));
        $this->assertSame('test_', DB::$tablePrefix);
        $this->assertTrue(DB::releaseLock('db_disconnect_held'));
    }

    public function testKilledConnectionDisconnectsAfterReleaseLock(): void
    {
        $db = new Connection(self::$configDefaults);
        $db->getLock('killed_held');
        self::killConnection($db->mysqli->thread_id);

        try {
            $db->disconnect();
            $this->fail("disconnect() should have thrown");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("holding lock `killed_held`", $e->getMessage());
        }

        $this->assertFalse($db->releaseLock('killed_held'), "The server freed the lock when the connection ended");
        $db->disconnect();
        $this->assertFalse($db->isConnected());
    }

    #[DataProvider('plainAndPooledProvider')]
    public function testClosingTheMysqliObjectReleasesHeldLocksFirst(string $hostnamePrefix): void
    {
        if ($hostnamePrefix === 'p:' && !ini_get('mysqli.allow_persistent')) {
            $this->markTestSkipped('mysqli.allow_persistent is off');
        }

        $name = "closed's \\ held"; // a quote and a backslash, which the release query has to escape
        $db   = new Connection(['hostname' => $hostnamePrefix . self::$configDefaults['hostname']] + self::$configDefaults);
        $db->getLock($name);
        $db->getLock($name);
        $threadId = $db->mysqli->thread_id;

        $db->mysqli->close();

        $this->assertNull(self::holderOf(self::serverName($name)));
        $this->assertSame([], $db->mysqli->heldLocks);
        if ($hostnamePrefix === 'p:') {
            $stillOpen = self::$other->mysqli->query("SELECT 1 FROM information_schema.processlist WHERE id = $threadId")->num_rows;
            $this->assertSame(1, $stillOpen, "The pooled connection stays open on the server, so only the release can have freed the lock");
        }

        $db->disconnect();
        $this->assertFalse($db->isConnected());
    }

    public static function plainAndPooledProvider(): array
    {
        return [
            'plain hostname' => [''],
            'p: hostname'    => ['p:'],
        ];
    }

    public function testConnectionThatTookALockIsClosedWhenItsObjectIsFreed(): void
    {
        $db = new Connection(self::$configDefaults);
        $db->withLock('freed_after_lock', fn() => null);
        $threadId = $db->mysqli->thread_id;

        unset($db);
        gc_collect_cycles();

        // The server closes the connection a moment after PHP does
        $isOpen = static fn(): int => self::$other->mysqli->query("SELECT 1 FROM information_schema.processlist WHERE id = $threadId")->num_rows;
        for ($i = 0; $i < 100 && $isOpen(); $i++) {
            usleep(10_000);
        }
        $this->assertSame(0, $isOpen(), "Taking a lock must not keep the connection open after its object is freed");
    }

    public function testPooledConnectionReleasesItsLocksWhenItsObjectIsFreed(): void
    {
        if (!ini_get('mysqli.allow_persistent')) {
            $this->markTestSkipped('mysqli.allow_persistent is off');
        }

        $db = new Connection(['hostname' => 'p:' . self::$configDefaults['hostname']] + self::$configDefaults);
        $db->getLock('freed_while_held');
        $threadId = $db->mysqli->thread_id;

        unset($db);
        gc_collect_cycles();

        $this->assertNull(self::holderOf(self::serverName('freed_while_held')));
        $stillOpen = self::$other->mysqli->query("SELECT 1 FROM information_schema.processlist WHERE id = $threadId")->num_rows;
        $this->assertSame(1, $stillOpen, "The pooled connection stays open on the server, so only the release can have freed the lock");
    }

    //endregion
    //region Release at Shutdown

    /**
     * The child script prints who holds the lock from a shutdown function of its own, registered
     * after the lock was taken, and again after ZenDB's release.
     */
    #[DataProvider('shutdownReleaseProvider')]
    public function testHeldLocksAreReleasedWhenTheScriptEnds(string $scenario, string $hostnamePrefix, bool $heldInShutdownFunction, int $releaseQueries, int $releaseCalls): void
    {
        [$stdout, $stderr,] = $this->runShutdownChild($scenario, $hostnamePrefix);
        $reports            = array_values(array_filter(array_map(
            static fn(string $line): ?array => json_decode($line, true),  // null for the fatal error's own lines
            explode("\n", $stdout),
        )));

        $this->assertSame(
            [
                ['when' => 'shutdown function', 'held' => $heldInShutdownFunction],
                ['when' => 'after release', 'held' => false, 'queries' => $releaseQueries, 'releaseCalls' => $releaseCalls],
            ],
            $reports,
            "child output: $stdout$stderr",
        );
    }

    public static function shutdownReleaseProvider(): array
    {
        return [
            'exit inside withLock()'                  => ['exit', '', true, 1, 1],
            'exit inside withLock(), pooled'          => ['exit', 'p:', true, 1, 1],
            'out of memory inside withLock()'         => ['out-of-memory', '', true, 1, 1],
            'out of memory inside withLock(), pooled' => ['out-of-memory', 'p:', true, 1, 1],
            'getLock() with no release'               => ['no-release', '', true, 1, 1],
            'getLock() with no release, pooled'       => ['no-release', 'p:', true, 1, 1],
            'one name taken three times'              => ['taken-three-times', '', true, 1, 1],
            'every lock already released'             => ['released', '', false, 0, 1],
            'a lock on each of two connections'       => ['two-connections', '', true, 2, 2],
        ];
    }

    #[Group('slow')]
    #[DataProvider('connectionGoneProvider')]
    public function testReleasePrintsNothingWhenTheServerClosedTheConnection(string $scenario): void
    {
        // MySQL 8.0+ makes PHP print "Packets out of order" for a query on a connection closed for wait_timeout
        [$stdout, $stderr, $exitCode] = $this->runShutdownChild($scenario, 'p:');

        $this->assertSame(['', '', 0], [$stdout, $stderr, $exitCode]);
    }

    public static function connectionGoneProvider(): array
    {
        return [
            'release at shutdown' => ['connection-gone'],
            'releaseLock()'       => ['connection-gone-release'],
        ];
    }

    //endregion
}
