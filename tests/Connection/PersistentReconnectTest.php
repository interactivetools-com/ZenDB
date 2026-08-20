<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli;

/**
 * Reconnecting on a pooled connection whose server thread died must hand back a
 * working connection with nothing reaching PHP's error display or log.
 *
 * The raw-mysqli behavior matrix (docs/internal/db-behavior-matrix.md, PERSISTENT
 * entries) shows MySQL/Percona 8.0+ raise a mysqlnd E_WARNING ("Packets out of
 * order") while replacing a slot the server closed for wait_timeout; MariaDB and
 * 5.7 raise nothing, and replacement after KILL is silent everywhere. ZenDB's
 * connect call is @-suppressed (MysqliWrapper::real_connect), so through
 * DB::connect any such warning must arrive suppressed on every server: a custom
 * error handler still sees it, PHP's own display and log never do. Real connect
 * failures still throw (MYSQLI_REPORT_STRICT).
 *
 * @covers \Itools\ZenDB\Connection::connect
 * @group slow
 */
class PersistentReconnectTest extends BaseTestCase
{
    private static function connectPooled(): Connection
    {
        return self::createDefaultConnection(['hostname' => 'p:' . self::$configDefaults['hostname']]);
    }

    /**
     * Run $fn recording every PHP diagnostic raised, with whether it was
     * @-suppressed at the moment it fired. Returns [result, events].
     *
     * captureErrors() doesn't fit here: it collects messages but drops the
     * suppression state, which is the behavior under test. Registering for every
     * level is deliberate for the same reason as there - PHP routes levels outside
     * the handler's mask to its own internal handler, not to PHPUnit's.
     *
     * @return array{0: mixed, 1: array<array{errno: int, message: string, suppressed: bool}>}
     */
    private function recordDiagnostics(callable $fn): array
    {
        $events = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$events): bool {
            $events[] = ['errno' => $errno, 'message' => $errstr, 'suppressed' => (error_reporting() & $errno) === 0];
            return true;
        });
        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }
        return [$result, $events];
    }

    public function testReconnectAfterWaitTimeoutExpirySuppressesDiagnostics(): void
    {
        self::requiresLiveMysql();

        if (!ini_get('mysqli.allow_persistent')) {
            $this->markTestSkipped('mysqli.allow_persistent is off');
        }

        // Pool a connection the server will close for idling, then let it die
        $conn            = self::connectPooled();
        $expiredThreadId = $conn->mysqli->thread_id;
        DB::query("SET SESSION wait_timeout = :n1", [':n1' => 1]);
        DB::disconnect();   // the slot goes back to the pool and idles there
        sleep(2);           // past wait_timeout, so the server has closed it

        // MySQL/Percona 8.0+ raise the "Packets out of order" E_WARNING here;
        // MariaDB and 5.7 raise nothing. Either way nothing may escape suppression.
        [$conn, $events] = $this->recordDiagnostics(fn(): Connection => self::connectPooled());

        $this->assertTrue(DB::isConnected(true), 'reconnect must hand back a working connection');
        $this->assertNotSame($expiredThreadId, $conn->mysqli->thread_id, 'the dead slot must be replaced with a fresh connection');
        foreach ($events as $event) {
            $this->assertTrue($event['suppressed'], "diagnostic escaped suppression: [{$event['errno']}] {$event['message']}");
        }

        // Leave a clean default connection for later test classes
        self::createDefaultConnection();
    }

    public function testReconnectAfterKillIsSilent(): void
    {
        self::requiresLiveMysql();

        if (!ini_get('mysqli.allow_persistent')) {
            $this->markTestSkipped('mysqli.allow_persistent is off');
        }

        $conn           = self::connectPooled();
        $victimThreadId = $conn->mysqli->thread_id;
        DB::disconnect();

        // KILL the sleeping slot from a plain-hostname connection (a different pool
        // key, so the pool itself is untouched)
        $killer = new mysqli(
            self::$configDefaults['hostname'],
            self::$configDefaults['username'],
            self::$configDefaults['password'],
            self::$configDefaults['database'],
        );
        $killer->query("KILL $victimThreadId");
        $killer->close();
        usleep(100_000);   // KILL returns after marking the thread; give the server a moment to close the socket

        [$conn, $events] = $this->recordDiagnostics(fn(): Connection => self::connectPooled());

        $this->assertTrue(DB::isConnected(true), 'reconnect must hand back a working connection');
        $this->assertNotSame($victimThreadId, $conn->mysqli->thread_id, 'the killed slot must be replaced with a fresh connection');
        $this->assertSame([], $events, 'reuse after KILL is silent on every server in the behavior matrix');

        // Leave a clean default connection for later test classes
        self::createDefaultConnection();
    }

    public function testAllowPersistentOffDowngradesWithSuppressedWarning(): void
    {
        self::requiresLiveMysql();

        // mysqli raises "Persistent connections are disabled. Downgrading to normal"
        // inside the same suppressed connect call. Unlike the reconnect warnings it
        // fires on every server, so this pins the suppression even where the tests
        // above record nothing. A child process is the only way to run with the ini
        // setting off.
        [$stdout, $stderr, $exitCode] = $this->runCommand([
            PHP_BINARY, '-d', 'mysqli.allow_persistent=0',
            dirname(__DIR__) . '/Support/bin/pconnect-downgrade.php',
            self::$configDefaults['hostname'],
            self::$configDefaults['username'],
            self::$configDefaults['password'],
            self::$configDefaults['database'],
        ]);

        $this->assertSame(0, $exitCode, "child exited with $exitCode: $stderr$stdout");
        $lines  = array_values(array_filter(explode("\n", trim($stdout))));
        $events = array_map(static fn(string $line): ?array => json_decode($line, true), array_slice($lines, 0, -1));

        $this->assertSame('CONNECTED', end($lines), "child output: $stdout");
        $this->assertCount(1, $events, "expected only the downgrade warning: $stdout");
        $this->assertSame(E_WARNING, $events[0]['errno']);
        $this->assertStringContainsString('Downgrading to normal', $events[0]['message']);
        $this->assertTrue($events[0]['suppressed'], 'the downgrade warning must be @-suppressed');
    }
}
