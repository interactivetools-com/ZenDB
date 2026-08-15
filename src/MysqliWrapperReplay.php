<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use mysqli_sql_exception;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function array_shift, count, is_array, microtime, strtr;
use const MYSQLI_STORE_RESULT;

/**
 * Class MysqliWrapperReplay
 *
 * A stand-in for MysqliWrapper that serves recorded query outcomes instead of talking
 * to a server, so code built on ZenDB can run and be tested with no MySQL behind it.
 * ZenDB accepts it anywhere it accepts MysqliWrapper; install it through
 * Connection::$mysqliWrapperFactory before connecting.
 *
 *     $corpus = require 'recorded-queries.php';   // written by a recording run
 *     Connection::$mysqliWrapperFactory = fn($queryLogger) => new MysqliWrapperReplay($corpus, $queryLogger);
 *     DB::connect([...]);                         // succeeds without a server
 *
 * The corpus maps each SQL string to the outcomes MySQL returned, in execution order:
 *
 *     [
 *         'meta'    => ['server_info' => '8.0.43'],
 *         'queries' => [
 *             'SELECT id FROM users' => [
 *                 ['type' => 'rows', 'fields' => [['name' => 'id']], 'rows' => [[7]], 'insert_id' => 0, 'affected_rows' => 1],
 *             ],
 *             "INSERT INTO users SET name = 'Sam'" => [
 *                 ['type' => 'ok', 'insert_id' => 8, 'affected_rows' => 1],
 *                 ['type' => 'error', 'errno' => 1062, 'sqlstate' => '23000', 'message' => "Duplicate entry 'Sam' ..."],
 *             ],
 *         ],
 *         'multi'   => ['SELECT 1; SELECT 2' => [[true, false]]],   // next_result() returns, one list per multi_query()
 *     ]
 *
 * The test suite records a corpus from a live run and replays it (see
 * tests/Support/Replay/ReplayHarness.php); a corpus can also be written by hand to
 * stub out the handful of queries an app test needs. Outcomes for one SQL string
 * replay in order, and the last one keeps serving after the list runs out, so a
 * query recorded once can run any number of times.
 *
 * Escaping is a PHP implementation of MySQL's utf8mb4 real_escape_string(), pinned
 * byte-identical to the live one by ReplayTest. prepare() and execute_query() are
 * not supported (ZenDB's own query paths never call them) and throw.
 */
class MysqliWrapperReplay
{
    //region Public Properties

    // The MysqliWrapper/mysqli surface ZenDB and calling code read
    public string     $lastQuery     = '';
    public bool       $inTransaction = false;
    public mixed      $queryLogger   = null;
    public int|string $insert_id     = 0;
    public int|string $affected_rows = 0;
    public string     $server_info;
    public int        $thread_id     = 0;
    public int        $connect_errno = 0;
    public string     $connect_error = '';
    public int        $errno         = 0;
    public string     $error         = '';

    //endregion
    //region Corpus State

    /** sql => list of outcome arrays (type: rows|ok|error) */
    private array $queries;

    /** sql => list of next_result() sequences, one per multi_query() call */
    private array $multi;

    /** Per-sql replay cursors */
    private array $queryCursor = [];
    private array $multiCursor = [];

    /** Remaining next_result() returns for the last multi_query() */
    private array $pendingNextResults = [];

    //endregion
    //region Setup

    /**
     * @param array         $corpus      Recorded outcomes; see the class docblock for the shape
     * @param callable|null $queryLogger Same logger MysqliWrapper takes: fn(string $query, float $duration, ?Throwable $exception): void
     */
    public function __construct(array $corpus, ?callable $queryLogger = null)
    {
        $this->queries     = $corpus['queries'] ?? [];
        $this->multi       = $corpus['multi'] ?? [];
        $this->server_info = $corpus['meta']['server_info'] ?? '8.0.0-replay';
        $this->queryLogger = $queryLogger;
    }

    /** @disregard P1003 signature mirrors mysqli; parameters unused on purpose (intelephense) */
    public function real_connect(?string $hostname = null, ?string $username = null, ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null, int $flags = 0): bool
    {
        return true;
    }

    /** @disregard P1003 signature mirrors mysqli; parameters unused on purpose (intelephense) */
    public function options(int $option, $value): bool
    {
        return true;
    }

    /** @disregard P1003 signature mirrors mysqli; parameters unused on purpose (intelephense) */
    public function select_db(string $database): bool
    {
        return true;
    }

    public function set_charset(string $charset): bool
    {
        // Same guard as MysqliWrapper, minus the server call
        if ($charset !== 'utf8mb4') {
            throw new InvalidArgumentException("ZenDB connections are always utf8mb4; set_charset('$charset') is not supported.");
        }
        return true;
    }

    public function character_set_name(): string
    {
        return 'utf8mb4';
    }

    /** @disregard P1003 signature mirrors MysqliWrapper; parameter unused on purpose (intelephense) */
    public function setEncryptionKeyCallback(callable $callback): void
    {
        // Nothing to SET: @ek only feeds server-side AES_DECRYPT, and replayed rows
        // already hold whatever the server returned. PHP-side decryption reads the
        // key from the connection config as usual.
    }

    public function stat(): string|false
    {
        return 'Replay corpus';
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    //endregion
    //region Queries

    /** @disregard P1003 signature mirrors mysqli; result mode unused on purpose (intelephense) */
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): MysqliResultReplay|bool
    {
        $this->lastQuery = $query;
        $startTime       = $this->queryLogger ? microtime(true) : 0.0;
        $outcome         = $this->nextOutcome($query);

        if ($outcome['type'] === 'error') {
            $e = self::sqlException($outcome);
            $this->logQuery($query, $startTime, $e);
            throw $e;
        }

        $this->insert_id     = $outcome['insert_id'];
        $this->affected_rows = $outcome['affected_rows'];
        $this->logQuery($query, $startTime);

        if ($outcome['type'] === 'rows') {
            return new MysqliResultReplay($outcome['fields'], $outcome['rows']);
        }

        return true;
    }

    /**
     * Always succeeds without a corpus lookup: ZenDB only uses real_query() for
     * connect-time SET statements, whose text varies by machine and timezone.
     */
    public function real_query(string $query): bool
    {
        $this->lastQuery = $query;
        return true;
    }

    public function multi_query(string $query): bool
    {
        $this->lastQuery = $query;
        $sequence        = $this->nextMulti($query);

        // A recorded first-statement failure throws here, like native multi_query()
        if (isset($sequence[0]['throw'])) {
            throw self::sqlException($sequence[0]['throw']);
        }

        $this->pendingNextResults = $sequence;
        return true;
    }

    public function more_results(): bool
    {
        return $this->pendingNextResults !== [];
    }

    public function next_result(): bool
    {
        if ($this->pendingNextResults === []) {
            return false;
        }
        $entry = array_shift($this->pendingNextResults);
        if (is_array($entry)) {
            throw self::sqlException($entry['error']);
        }
        return $entry;
    }

    /**
     * PHP implementation of MySQL's escaping for utf8mb4 (the native method needs a
     * live connection). utf8mb4 never embeds these bytes inside multi-byte characters,
     * so byte-wise replacement matches the native result exactly - ReplayTest pins
     * every byte against a live connection.
     */
    public function real_escape_string(string $string): string
    {
        return strtr($string, [
            "\0"   => "\\0",
            "\n"   => "\\n",
            "\r"   => "\\r",
            "\\"   => "\\\\",
            "'"    => "\\'",
            "\""   => "\\\"",
            "\x1a" => "\\Z",
        ]);
    }

    /** @disregard P1003 signature mirrors mysqli; parameter unused on purpose (intelephense) */
    public function prepare(string $query): never
    {
        throw new RuntimeException("MysqliWrapperReplay doesn't support prepare(); replay recorded outcomes through query() instead.");
    }

    /** @disregard P1003 signature mirrors mysqli; parameters unused on purpose (intelephense) */
    public function execute_query(string $query, ?array $params = null): never
    {
        throw new RuntimeException("MysqliWrapperReplay doesn't support execute_query(); replay recorded outcomes through query() instead.");
    }

    /**
     * Same contract as MysqliWrapper::logQuery(), so query loggers see replayed
     * queries exactly as they'd see live ones.
     */
    public function logQuery(string $query, float $startTime, ?Throwable $exception = null): void
    {
        if ($this->queryLogger) {
            $duration = microtime(true) - $startTime;
            ($this->queryLogger)($query, $duration, $exception);
        }
    }

    //endregion
    //region Corpus Replay Internals

    protected function nextOutcome(string $sql): array
    {
        $outcomes = $this->queries[$sql] ?? throw new RuntimeException("Replay corpus has no recording for query: $sql\nRe-record the corpus or add this query to it.");
        $cursor   = $this->queryCursor[$sql] ?? 0;
        $this->queryCursor[$sql] = $cursor + 1;
        return $outcomes[$cursor] ?? $outcomes[count($outcomes) - 1];   // queue empty: last outcome keeps serving
    }

    protected function nextMulti(string $sql): array
    {
        $sequences = $this->multi[$sql] ?? throw new RuntimeException("Replay corpus has no recording for multi_query: $sql\nRe-record the corpus or add this query to it.");
        $cursor    = $this->multiCursor[$sql] ?? 0;
        $this->multiCursor[$sql] = $cursor + 1;
        return $sequences[$cursor] ?? $sequences[count($sequences) - 1];
    }

    /**
     * Rebuild the recorded mysqli_sql_exception. The sqlstate property is protected
     * with no constructor argument, so it's set via reflection.
     */
    private static function sqlException(array $error): mysqli_sql_exception
    {
        $e = new mysqli_sql_exception($error['message'], $error['errno']);
        if (($error['sqlstate'] ?? '') !== '') {
            (new ReflectionProperty(mysqli_sql_exception::class, 'sqlstate'))->setValue($e, $error['sqlstate']);
        }
        return $e;
    }

    //endregion
}
