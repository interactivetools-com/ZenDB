<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;

/**
 * Wraps mysqli_stmt to add query logging support.
 */
class MysqliStmtWrapper extends mysqli_stmt
{
    private string $query;
    private float  $startTime;

    /** @internal For testing only - forces MysqliResultPolyfill even when mysqlnd is available */
    private static bool $forceResultPolyfill = false;

    /**
     * Enable result polyfill for testing purposes.
     * Only works when PHPUnit is loaded (test environment).
     */
    public static function enableTestResultPolyfill(bool $enable): void
    {
        if (!class_exists(\PHPUnit\Framework\TestCase::class, false)) {
            throw new \RuntimeException("forceResultPolyfill can only be set in test environment");
        }
        self::$forceResultPolyfill = $enable;
    }

    public function __construct(MysqliWrapper $mysqliWrapper, string $query, float $startTime)
    {
        $this->query     = $query;
        $this->startTime = $startTime;

        parent::__construct($mysqliWrapper, $query);
    }

    public function execute(?array $params = null): bool
    {
        $paramsJson = $params ? json_encode($params) : '[]';

        try {
            $result = parent::execute($params);
        } catch (mysqli_sql_exception $e) {
            MysqliWrapper::log("$this->query /* params: $paramsJson */", $this->startTime, $e);
            throw $e;
        }

        MysqliWrapper::log("$this->query /* params: $paramsJson */", $this->startTime);

        return $result;
    }

    /**
     * Get result set from prepared statement.
     * Falls back to MysqliResultPolyfill if mysqlnd is not available.
     */
    public function get_result(): mysqli_result|false
    {
        // get_result() requires mysqlnd (mandatory in PHP 8.2+, optional in 8.1)
        if (!self::$forceResultPolyfill && method_exists(parent::class, 'get_result')) {
            return parent::get_result();
        }
        return new MysqliResultPolyfill($this);
    }
}
