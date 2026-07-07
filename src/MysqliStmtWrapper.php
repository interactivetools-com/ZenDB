<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Wraps mysqli_stmt to add query logging support.
 */
class MysqliStmtWrapper extends mysqli_stmt
{
    private MysqliWrapper $mysqliWrapper;
    private string        $query;
    private float         $startTime;

    /** @internal For testing only - forces MysqliResultPolyfill even when mysqlnd is available */
    private static bool $forceResultPolyfill = false;

    /**
     * Enable result polyfill for testing purposes.
     * Only works when PHPUnit is loaded (test environment).
     */
    public static function enableTestResultPolyfill(bool $enable): void
    {
        if (!class_exists(TestCase::class, false)) {
            throw new RuntimeException("forceResultPolyfill can only be set in test environment");
        }
        self::$forceResultPolyfill = $enable;
    }

    public function __construct(MysqliWrapper $mysqliWrapper, string $query, float $startTime)
    {
        $this->mysqliWrapper = $mysqliWrapper;
        $this->query         = $query;
        $this->startTime     = $startTime;

        parent::__construct($mysqliWrapper, $query);
    }

    public function execute(?array $params = null): bool
    {
        $paramsJson = $params ? json_encode($params) : '[]';

        try {
            $result = parent::execute($params);
        } catch (mysqli_sql_exception $e) {
            $this->mysqliWrapper->logQuery("$this->query /* params: $paramsJson */", $this->startTime, $e);
            throw $e;
        }

        $this->mysqliWrapper->logQuery("$this->query /* params: $paramsJson */", $this->startTime);

        return $result;
    }

    /**
     * Get result set from prepared statement. Native with the mysqlnd driver, PHP's
     * default since 5.4 and mandatory since 8.2; a PHP 8.1 build without it has no
     * native get_result(), so SELECTs get a MysqliResultPolyfill emulation instead
     * (a mysqli_result subclass; see that class for its limitations).
     *
     * TODO-PHP82: Remove the polyfill fallback below (and the force flag); mysqlnd is always present from 8.2
     */
    public function get_result(): mysqli_result|false
    {
        if (!self::$forceResultPolyfill && method_exists(parent::class, 'get_result')) {
            return parent::get_result();
        }

        // A write (INSERT/UPDATE/DELETE) has no result columns. Native get_result()
        // returns false here, so callers get false and can fall through to true.
        if ($this->field_count === 0) {
            return false;
        }
        return new MysqliResultPolyfill($this);
    }
}
