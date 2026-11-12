<?php
declare(strict_types=1);

/**
 * Child for NamedLockTest: take a named lock, end the script the way <scenario> says, and
 * print what the server shows before and after ZenDB releases the lock at shutdown, one
 * JSON line each:
 *
 *     {"when":"shutdown function","held":true}
 *     {"when":"after release","held":false,"queries":1,"releaseCalls":1}
 *
 * "queries" is how many queries the release sent, and "releaseCalls" is how many times
 * releaseHeldLocks() ran. The two connection-gone scenarios print nothing: the test checks
 * that the release prints nothing either.
 *
 *     php named-lock-shutdown.php <scenario> <hostname> <username> <password> <database>
 *
 * Scenarios: exit, fatal-error, out-of-memory, no-release, taken-three-times, released,
 * two-connections, connection-gone, connection-gone-release.
 * <hostname> can start with p: for a pooled connection.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\MysqliWrapper;

error_reporting(E_ALL);
ini_set('display_errors', '1'); // a warning the release doesn't hide shows up in the output

[, $scenario, $hostname, $username, $password, $database] = $argv;

/**
 * Counts releaseHeldLocks() calls
 */
final class CountingMysqliWrapper extends MysqliWrapper
{
    public static int $releaseCalls = 0;

    public function releaseHeldLocks(): void
    {
        self::$releaseCalls++;
        parent::releaseHeldLocks();
    }
}

Connection::$mysqliWrapperFactory = static fn(?callable $queryLogger): MysqliWrapper => new CountingMysqliWrapper($queryLogger);
$config = [
    'hostname'       => $hostname,
    'username'       => $username,
    'password'       => $password,
    'database'       => $database,
    'connectTimeout' => 10, // same as BaseTestCase: local MySQL can take over a second to accept a connection under load
];
DB::connect($config);

// A plain mysqli connection reads the locks from outside, and its queries aren't in DB::$queryCount.
// Only the two-connections scenario takes the second name.
$name     = "shutdown_$scenario";
$lockName = strtolower("$database..$name"); // database, an empty tablePrefix, the name
$watcher  = new mysqli(preg_replace('/^p:/', '', $hostname), $username, $password, $database);
$isHeld   = static fn(): bool => (bool)$watcher->query("SELECT IS_USED_LOCK('$lockName') IS NOT NULL OR IS_USED_LOCK('{$lockName}_second') IS NOT NULL")->fetch_row()[0];

// Each scenario registers this after it takes the lock. ZenDB's release runs after it,
// and the function it registers here runs after the release.
$report = static function () use ($isHeld): void {
    echo json_encode(['when' => 'shutdown function', 'held' => $isHeld()]), "\n";
    $queriesBefore = DB::$queryCount;
    register_shutdown_function(static function () use ($isHeld, $queriesBefore): void {
        echo json_encode([
            'when'         => 'after release',
            'held'         => $isHeld(),
            'queries'      => DB::$queryCount - $queriesBefore,
            'releaseCalls' => CountingMysqliWrapper::$releaseCalls,
        ]), "\n";
    });
};

match ($scenario) {
    'exit' => DB::withLock($name, static function () use ($report): void {
        register_shutdown_function($report);
        exit;
    }),
    'fatal-error' => DB::withLock($name, static function () use ($report): void {
        register_shutdown_function($report);
        eval('function declared_twice() {} function declared_twice() {}'); // "Cannot redeclare" is a fatal error, not an exception: withLock()'s finally doesn't run
    }),
    'out-of-memory' => DB::withLock($name, static function () use ($report): void {
        register_shutdown_function($report);
        ini_set('memory_limit', '32M');
        str_repeat('x', 64 * 1024 * 1024);
    }),
    'no-release' => (static function () use ($name, $report): void {
        DB::getLock($name);
        register_shutdown_function($report);
    })(),
    'taken-three-times' => (static function () use ($name, $report): void {
        DB::getLock($name);
        DB::getLock($name);
        DB::getLock($name);
        register_shutdown_function($report);
    })(),
    'released' => (static function () use ($name, $report): void {
        DB::getLock($name);
        DB::releaseLock($name);
        DB::getLock($name);
        DB::releaseLock($name);
        register_shutdown_function($report);
    })(),
    'two-connections' => (static function () use ($name, $report, $config): void {
        DB::getLock($name);
        $GLOBALS['secondConnection'] = new Connection($config); // a global, so the object is still there at shutdown
        $GLOBALS['secondConnection']->getLock("{$name}_second");
        register_shutdown_function($report);
    })(),
    'connection-gone', 'connection-gone-release' => (static function () use ($name, $scenario): void {
        DB::getLock($name);
        DB::$mysqli->query("SET SESSION wait_timeout = 1");
        sleep(2); // past wait_timeout, so the server has closed the connection
        if ($scenario === 'connection-gone-release') {
            DB::releaseLock($name); // the first query on the closed connection; without it the shutdown release is
        }
    })(),
};
