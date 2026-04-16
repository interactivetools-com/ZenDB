<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Closure;
use ReturnTypeWillChange;
use Throwable;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;

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
        $startTime = microtime(true);
        $result    = @parent::real_connect($hostname, $username, $password, $database, $port, $socket, $flags); // hide php hostname lookup warnings (catch block will show them)

        // log connection
        $this->logQuery("real_connect[$this->thread_id]: " . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''), $startTime);

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
        $this->ensureEncryptionKey($query);
        $startTime = microtime(true);

        // execute query
        try {
            $result = parent::query($query, $result_mode);
        } catch (mysqli_sql_exception $e) {
            $this->logQuery($query, $startTime, $e);
            throw $e;
        }

        $this->logQuery($query, $startTime);

        return $result;
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
        $this->ensureEncryptionKey($query);
        $startTime = microtime(true);

        try {
            $result = parent::real_query($query);
        } catch (mysqli_sql_exception $e) {
            $this->logQuery("real_query: $query", $startTime, $e);
            throw $e;
        }

        $this->logQuery("real_query: $query", $startTime);

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
        $this->ensureEncryptionKey($query);
        $startTime = microtime(true);

        try {
            $result = parent::multi_query($query);
        } catch (mysqli_sql_exception $e) {
            $this->logQuery("multi_query: $query", $startTime, $e);
            throw $e;
        }

        $this->logQuery("multi_query: $query", $startTime);

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
        $startTime = microtime(true);

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
     *
     * @see mysqli::execute_query()
     *
     * @param string     $query  SQL with ? placeholders
     * @param array|null $params Parameters to bind (null or empty for none)
     * @return mysqli_result|true mysqli_result for queries that return rows, true otherwise; throws on failure
     * @throws mysqli_sql_exception On query failure
     */
    public function execute_query(string $query, ?array $params = null): mysqli_result|bool
    {
        // Use native execute_query() if available (PHP 8.2+) and not forcing polyfill
        if (PHP_VERSION_ID >= 80200 && !self::$forceExecuteQueryPolyfill) {
            $this->lastQuery = $query;
            $this->ensureEncryptionKey($query);
            $startTime = microtime(true);

            try {
                $result = parent::execute_query($query, $params);
            } catch (mysqli_sql_exception $e) {
                $this->logQuery($query, $startTime, $e);
                throw $e;
            }

            $this->logQuery($query, $startTime);

            return $result;
        }

        // Polyfill for PHP 8.1 - always use prepare/execute for consistent type handling
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
     * Lazily SET the MySQL @ek session variable on first query that uses it.
     * Uses parent::prepare() to bypass the logging wrapper and avoid recursion.
     */
    private function ensureEncryptionKey(string $sql): void
    {
        if ($this->encryptionKeySet || !str_contains($sql, '@ek')) {
            return;
        }

        if ($this->getEncryptionKey === null) {
            throw new RuntimeException("Query uses @ek but no encryptionKey is configured. Add 'encryptionKey' to your connection config.");
        }

        $key = ($this->getEncryptionKey)();
        if ($key === '') {
            throw new RuntimeException("Query uses @ek but encryptionKey is empty. Add 'encryptionKey' to your connection config.");
        }

        $startTime = microtime(true);
        $stmt      = parent::prepare("SET @ek = UNHEX(SHA2(?, 512))");
        $stmt->execute([$key]);
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
