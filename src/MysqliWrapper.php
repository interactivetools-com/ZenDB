<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Closure;
use InvalidArgumentException;
use ReturnTypeWillChange;
use Throwable;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function get_object_vars, microtime, str_contains;
use const MYSQLI_STORE_RESULT, PHP_VERSION_ID;

/**
 * Class MysqliWrapper
 *
 * Extends mysqli to add query logging and automatic @ek encryption key setup.
 */
class MysqliWrapper extends mysqli
{
    //region Main

    /**
     * Last query executed (for error debugging)
     */
    public string $lastQuery = '';

    /**
     * Whether a transaction is currently active on this connection.
     * Used by Connection::transaction() to detect accidental nesting.
     *
     * @internal API may change between releases
     */
    public bool $inTransaction = false;

    /**
     * Force execute_query() to use polyfill instead of native (for testing)
     */
    public static bool $forceExecuteQueryPolyfill = false;

    /**
     * Keep-alive reference to the last prepared statement (for execute_query polyfill).
     * Writing to this property holds the mysqli_stmt open so affected_rows and insert_id stay available
     * after the method returns; without it, the destructor runs and resets both to zero.
     *
     * @disregard P1003 keep-alive reference, write-only on purpose (intelephense)
     */
    private ?mysqli_stmt $stmtKeepAlive = null;

    /**
     * Query logger callback: fn(string $query, float $duration, ?Throwable $exception): void
     *
     * Runs mid-query, before the calling code has read its results, so a logger must not
     * query this connection. Doing so overwrites what ZenDB is about to read: insert()
     * returns 0 instead of the new id, update() and delete() report the logger's row
     * count, and joined queries lose their table-qualified keys. To log to MySQL, give
     * the logger its own connection. Reading properties like thread_id is fine.
     */
    public mixed $queryLogger = null;

    /**
     * Callback that returns the encryption key. Called once on first query containing @ek.
     * @var (Closure(): string)|null
     */
    private ?Closure $getEncryptionKey = null;

    /**
     * Whether @ek has been SET on this MySQL session.
     */
    private bool $encryptionKeySet = false;

    //endregion
    //region Overridden Methods

    /**
     * @param callable|null $queryLogger Query logger: fn(string $query, float $duration, ?Throwable $exception): void
     *                                   Logged queries have values inlined, so redacting sensitive data is the callback's job.
     */
    public function __construct(?callable $queryLogger = null)
    {
        $this->queryLogger = $queryLogger;

        // Initialize the parent mysqli object
        parent::__construct();
    }

    /** @noinspection PhpFullyQualifiedNameUsageInspection - FQN required until PHP 8.2 minimum (can't import) */
    public function real_connect(
        #[\SensitiveParameter] ?string $hostname = null,
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        ?string                        $database = null,
        ?int                           $port = null,
        ?string                        $socket = null,
        int                            $flags = 0,
    ): bool {
        // connect
        $startTime = $this->queryLogger ? microtime(true) : 0.0;   // only needed for logger
        $request   = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '');

        try {
            $result = @parent::real_connect($hostname, $username, $password, $database, $port, $socket, $flags); // hide php hostname lookup warnings (Connection::connect() reports the failure)
        } catch (mysqli_sql_exception $e) {
            // thread_id and host_info throw "Property access is not allowed yet" on an unconnected handle, so name the target instead
            $this->logQuery("real_connect ($hostname): $request", $startTime, $e);
            throw $e;
        }

        // log connection
        if ($this->queryLogger) {
            $this->logQuery("real_connect[$this->thread_id] ($this->host_info): $request", $startTime);
        }

        return $result;
    }

    /**
     * mysqli::query() wrapper with logging and automatic &#64;ek encryption-key setup. Throws on failure.
     *
     * @see mysqli::query()
     *
     * @param string $query       SQL to execute
     * @param int    $result_mode MYSQLI_STORE_RESULT, MYSQLI_USE_RESULT, or MYSQLI_ASYNC
     * @return mysqli_result|true mysqli_result for queries that return rows, true otherwise; throws on failure
     * @throws mysqli_sql_exception On query failure
     */
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->lastQuery = $query;
        DB::$queryCount++;
        $this->ensureEncryptionKey($query);
        $startTime = $this->queryLogger ? microtime(true) : 0.0;   // only needed for logger

        // execute query
        try {
            $result = parent::query($query, $result_mode);
        } catch (mysqli_sql_exception $e) {
            $this->logQuery($query, $startTime, $e);
            throw $e;
        }

        if ($this->queryLogger) {
            $this->logQuery($query, $startTime);
        }

        return $result;
    }

    /**
     * mysqli::set_charset() restricted to utf8mb4. ZenDB connections are always utf8mb4:
     * escaping, SmartString encoding, and backups all assume it, and a changed charset
     * survives pooled-connection reuse into later requests. Throws on any other charset
     * so an accidental change fails loudly instead of corrupting data.
     *
     * This only covers the method. "SET NAMES" through query() switches the server while
     * mysqli keeps escaping for utf8mb4, which allows SQL injection; see docs/security-gotchas.md.
     *
     * @see mysqli::set_charset()
     *
     * @param string $charset Must be 'utf8mb4'
     * @return bool True on success; throws on any other charset
     * @throws InvalidArgumentException When $charset isn't utf8mb4
     */
    public function set_charset(string $charset): bool
    {
        if ($charset !== 'utf8mb4') {
            $h = DB::h(...); // SECURITY: charset comes from the caller, encode before it can reach page output
            throw new InvalidArgumentException("ZenDB connections are always utf8mb4; set_charset('{$h($charset)}') is not supported.");
        }
        return parent::set_charset($charset);
    }

    /**
     * mysqli::real_query() wrapper with logging and automatic &#64;ek encryption-key setup. Throws on failure.
     *
     * Unlike query(), does not fetch the result; call store_result() or use_result() afterward to retrieve rows.
     *
     * @see mysqli::real_query()
     *
     * @param string $query SQL to execute
     * @return true Always true on success; throws on failure
     * @throws mysqli_sql_exception On query failure
     */
    public function real_query(string $query): bool
    {
        $this->lastQuery = $query;
        DB::$queryCount++;
        $this->ensureEncryptionKey($query);
        $startTime = $this->queryLogger ? microtime(true) : 0.0;   // only needed for logger

        try {
            $result = parent::real_query($query);
        } catch (mysqli_sql_exception $e) {
            if ($this->queryLogger) {
                $this->logQuery("real_query: $query", $startTime, $e);
            }
            throw $e;
        }

        if ($this->queryLogger) {
            $this->logQuery("real_query: $query", $startTime);
        }

        return $result;
    }

    /**
     * mysqli::multi_query() wrapper with logging and automatic &#64;ek encryption-key setup. Throws on failure.
     *
     * Executes multiple semicolon-separated statements. Advance with next_result() and fetch each via store_result()
     * or use_result(); errors in statements after the first surface through next_result(), not as throws here.
     *
     * @see mysqli::multi_query()
     *
     * @param string $query One or more SQL statements separated by semicolons
     * @return true Always true if the first statement started; throws only on failure of the first statement
     * @throws mysqli_sql_exception On failure of the first statement
     */
    public function multi_query(string $query): bool
    {
        $this->lastQuery = $query;
        DB::$queryCount++;
        $this->ensureEncryptionKey($query);
        $startTime = $this->queryLogger ? microtime(true) : 0.0;   // only needed for logger

        try {
            $result = parent::multi_query($query);
        } catch (mysqli_sql_exception $e) {
            if ($this->queryLogger) {
                $this->logQuery("multi_query: $query", $startTime, $e);
            }
            throw $e;
        }

        if ($this->queryLogger) {
            $this->logQuery("multi_query: $query", $startTime);
        }

        return $result;
    }

    /**
     * mysqli::prepare() wrapper with logging and automatic &#64;ek encryption-key setup. Throws on failure.
     *
     * Returns a prepared statement; bind parameters and call execute() to run it.
     *
     * @see mysqli::prepare()
     *
     * @param string $query SQL with ? placeholders
     * @return mysqli_stmt Prepared statement; throws on failure
     * @throws mysqli_sql_exception On prepare failure
     */
    public function prepare(string $query): mysqli_stmt
    {
        $this->lastQuery = $query;
        $this->ensureEncryptionKey($query);
        $startTime = $this->queryLogger ? microtime(true) : 0.0;   // only needed for logger

        try {
            $result = new MysqliStmtWrapper($this, $query, $startTime);
        } catch (mysqli_sql_exception $e) {
            $this->logQuery($query, $startTime, $e);
            throw $e;
        }

        return $result;
    }

    /**
     * mysqli::execute_query() wrapper/polyfill with logging and automatic &#64;ek encryption-key setup. Throws on failure.
     *
     * Prepares, binds parameters, and executes in one call. Native in PHP 8.2+; polyfilled via prepare()/execute() on 8.1.
     * Without mysqlnd, SELECTs return a MysqliResultPolyfill emulation (a mysqli_result subclass; see that class for its limitations).
     *
     * @see mysqli::execute_query()
     *
     * @param string     $query  SQL with ? placeholders
     * @param array|null $params Parameters to bind (null or empty for none)
     * @return mysqli_result|true result for queries that return rows, true otherwise; throws on failure
     * @throws mysqli_sql_exception On query failure
     */
    public function execute_query(string $query, ?array $params = null): mysqli_result|bool
    {
        // Use native execute_query() if available (PHP 8.2+) and not forcing polyfill
        if (PHP_VERSION_ID >= 80200 && !self::$forceExecuteQueryPolyfill) {
            $this->lastQuery = $query;
            DB::$queryCount++;
            $this->ensureEncryptionKey($query);
            $startTime = $this->queryLogger ? microtime(true) : 0.0;   // only needed for logger

            try {
                $result = parent::execute_query($query, $params);
            } catch (mysqli_sql_exception $e) {
                $this->logQuery($query, $startTime, $e);
                throw $e;
            }

            if ($this->queryLogger) {
                $this->logQuery($query, $startTime);
            }

            return $result;
        }

        // Polyfill for PHP 8.1 - always use prepare/execute for consistent type handling
        // TODO-PHP82: Remove this polyfill branch (and the force flag); execute_query() is native from 8.2
        // Destroy previous statement first (its destructor resets affected_rows)
        $this->stmtKeepAlive = null;

        $stmt = $this->prepare($query);
        $stmt->execute($params ?? []);

        // Keep stmt alive so affected_rows/insert_id remain accessible after return
        $this->stmtKeepAlive = $stmt;

        return $stmt->get_result() ?: true;
    }

    /**
     * Close the connection and clean up resources.
     */
    #[ReturnTypeWillChange]
    public function close(): bool
    {
        $this->stmtKeepAlive = null;
        return parent::close();
    }

    //endregion
    //region Logging

    /**
     * Call the query logger callback if set.
     */
    public function logQuery(string $query, float $startTime, ?Throwable $exception = null): void
    {
        if ($this->queryLogger) {
            $duration = microtime(true) - $startTime;
            ($this->queryLogger)($query, $duration, $exception);
        }
    }

    //endregion
    //region Encryption

    /**
     * Register a callback that returns the encryption key.
     * Called once on first query containing @ek to SET the MySQL session variable.
     */
    public function setEncryptionKeyCallback(Closure $callback): void
    {
        $this->getEncryptionKey = $callback;
        $this->encryptionKeySet = false;
    }

    /**
     * Lazily SET the MySQL @ek session variable on the first query that uses it.
     *
     * Sent as a prepared statement so the key travels as bound data, not query text.
     * Where it can still be read on the server:
     *
     * - General query log: Execute lines inline bound values, so general_log=ON
     *   records the key, along with every other query value on that server.
     * - performance_schema.user_variables_by_thread: holds the derived key for the
     *   life of the connection, readable by any account with access. On by default
     *   in MySQL, off by default in MariaDB.
     *
     * Where it doesn't appear: SHOW PROCESSLIST and performance_schema statement
     * history see only "SET @ek = UNHEX(SHA2(?, 512))", and the binary log gets no
     * User_var event because @ek is only ever read (AES_DECRYPT) - writes encrypt
     * in PHP.
     *
     * Uses parent::prepare() to bypass the logging wrapper and avoid recursion.
     */
    private function ensureEncryptionKey(string $sql): void
    {
        if ($this->encryptionKeySet || !$this->getEncryptionKey || !str_contains($sql, '@ek')) {
            return;
        }

        DB::$queryCount++;
        $startTime = $this->queryLogger ? microtime(true) : 0.0;   // only needed for logger
        $stmt      = parent::prepare("SET @ek = UNHEX(SHA2(?, 512))");
        $stmt->execute([($this->getEncryptionKey)()]);
        $stmt->close();
        $this->encryptionKeySet = true;
        $this->logQuery("SET @ek = UNHEX(SHA2(?, 512)) /* params: [\"********\"] */", $startTime);
    }

    //endregion
    //region Debug

    public function __debugInfo(): array
    {
        $props                     = get_object_vars($this);
        $props['getEncryptionKey'] = $this->getEncryptionKey !== null ? '(set)' : null;
        return $props;
    }

    //endregion
}
