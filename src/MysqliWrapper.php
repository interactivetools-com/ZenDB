<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Closure;
use ReturnTypeWillChange;
use RuntimeException;
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
     * Keeps last statement alive to preserve affected_rows/insert_id (for execute_query polyfill)
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

    public function prepare(string $query): mysqli_stmt|false
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
     * Wrapper/polyfill for mysqli::execute_query() (native in PHP 8.2+)
     * Prepares, binds parameters, executes, and returns result in one call.
     *
     * @param string     $query  SQL query with ? placeholders
     * @param array|null $params Parameters to bind (null or empty for no parameters)
     * @return mysqli_result|bool Result set for SELECT, or true/false for other queries
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
