<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use mysqli_stmt;
use Throwable;

class MysqliStmtWrapper extends mysqli_stmt
{
    private string $query;
    private float  $startTime;
    private string $boundParamsString = "[]"; // json encoded empty param array for logging

    public function __construct(MysqliWrapper $mysqliWrapper, string $query, float $startTime)
    {
        $this->query     = $query;
        $this->startTime = $startTime;

        parent::__construct($mysqliWrapper, $query);
    }

    public function bind_param($types, &...$params): bool
    {
        if (MysqliWrapper::$queryLogger) {
            $this->boundParamsString = json_encode($params, JSON_THROW_ON_ERROR);
        }

        try {
            $result = parent::bind_param($types, ...$params);
        } catch (Throwable $e) {
            MysqliWrapper::log("$this->query /* params: $this->boundParamsString */", $this->startTime, $e);
            throw $e;
        }

        // log errors if mysqli_report is off
        if ($result === false) {
            MysqliWrapper::log("$this->query /* params: $this->boundParamsString */", $this->startTime, new \RuntimeException($this->error, $this->errno));
        }

        return $result;
    }

    public function execute(?array $params = null): bool
    {
        try {
            $result = parent::execute($params);
        } catch (Throwable $e) {
            MysqliWrapper::log("$this->query /* params: $this->boundParamsString */", $this->startTime, $e);
            throw $e;
        }

        // log query (with error if failed without throwing)
        $error = ($result === false && $this->errno) ? new \RuntimeException($this->error, $this->errno) : null;
        MysqliWrapper::log("$this->query /* params: $this->boundParamsString */", $this->startTime, $error);

        return $result;
    }

    /**
     * Get result set from prepared statement.
     * Falls back to MysqliResultPolyfill if mysqlnd is not available.
     */
    public function get_result(): \mysqli_result|false
    {
        // get_result() requires mysqlnd (mandatory in PHP 8.2+, optional in 8.1)
        if (method_exists(parent::class, 'get_result')) {
            return parent::get_result();
        }
        return new MysqliResultPolyfill($this);
    }
}
