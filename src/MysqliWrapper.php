<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use RuntimeException;
use Throwable;

/**
 * Class MysqliWrapper
 *
 * Extends mysqli to add query logging.
 */
class MysqliWrapper extends mysqli
{
    //region Main

    /**
     * Last query executed (for error debugging)
     */
    public static string $lastQuery = '';

    /**
     * Force execute_query() to use polyfill instead of native (for testing)
     */
    public static bool $forcePolyfill = false;

    /**
     * Keeps last statement alive to preserve affected_rows/insert_id (polyfill only)
     */
    private ?\mysqli_stmt $lastStmt = null;

    /**
     * Query logger callback: fn(string $query, float $durationSecs, ?Throwable $error): void
     */
    public static $queryLogger = null;

    //endregion
    //region Overridden Methods

    /**
     * @param callable|null $queryLogger Query logger: fn(string $query, float $durationSecs, ?Throwable $error): void
     */
    public function __construct(?callable $queryLogger = null)
    {
        self::$queryLogger = $queryLogger;

        // Initialize the parent mysqli object
        parent::__construct();
    }

    public function real_connect(
        #[\SensitiveParameter] ?string $hostname = null,
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        ?string $database = null,
        ?int $port = null,
        ?string $socket = null,
        int $flags = 0
    ): bool {

        // connect
        $startTime = microtime(true);
        $result = @parent::real_connect($hostname, $username, $password, $database, $port, $socket, $flags); // hide php hostname lookup warnings (catch block will show them)

        // log connection
        self::log("real_connect[$this->thread_id]: " . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''), $startTime);

        return $result;
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        self::$lastQuery = $query;
        $startTime       = microtime(true);

        // execute query
        try {
            $result = parent::query($query, $result_mode);
        } catch (Throwable $e) {
            self::log($query, $startTime, $e);
            throw $e;
        }

        // log query (pass mysqli error as exception if query failed without throwing)
        $error = ($result === false && $this->errno) ? new RuntimeException($this->error, $this->errno) : null;
        self::log($query, $startTime, $error);

        return $result;
    }

    public function real_query(string $query): bool
    {
        self::$lastQuery = $query;
        $startTime       = microtime(true);

        $result = parent::real_query($query);

        self::log("real_query: $query", $startTime);

        return $result;
    }

    public function multi_query(string $query): bool
    {
        self::$lastQuery = $query;
        $startTime       = microtime(true);

        $result = parent::multi_query($query);

        self::log("multi_query: $query", $startTime);

        return $result;
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        self::$lastQuery = $query;
        $startTime       = microtime(true);

        try {
            $result = new MysqliStmtWrapper($this, $query, $startTime);
        } catch (Throwable $e) {
            self::log($query, $startTime, $e);
            throw $e;
        }

        return $result;
    }

    /**
     * Wrapper/polyfill for mysqli::execute_query() (native in PHP 8.2+)
     * Prepares, binds parameters, executes, and returns result in one call.
     *
     * @param string $query SQL query with ? placeholders
     * @param array|null $params Parameters to bind (null or empty for no parameters)
     * @return mysqli_result|bool Result set for SELECT, or true/false for other queries
     */
    public function execute_query(string $query, ?array $params = null): mysqli_result|bool
    {
        // Use native execute_query() if available (PHP 8.2+) and not forcing polyfill
        if (PHP_VERSION_ID >= 80200 && !self::$forcePolyfill) {
            self::$lastQuery = $query;
            $startTime       = microtime(true);

            $result = parent::execute_query($query, $params);

            self::log($query, $startTime);

            return $result;
        }

        // Polyfill for PHP 8.1 - always use prepare/execute for consistent type handling
        // Destroy previous statement first (its destructor resets affected_rows)
        $this->lastStmt = null;

        $stmt = $this->prepare($query);
        if ($stmt === false) {
            return false;
        }
        $stmt->execute($params ?? []);

        // Keep stmt alive so affected_rows/insert_id remain accessible after return
        $this->lastStmt = $stmt;

        return $stmt->get_result() ?: true;
    }

    //endregion
    //region Logging

    /**
     * Call the query logger callback if set.
     */
    public static function log(string $query, float $startTime, ?Throwable $error = null): void
    {
        if (self::$queryLogger) {
            $duration = microtime(true) - $startTime;
            (self::$queryLogger)($query, $duration, $error);
        }
    }

    //endregion
}
