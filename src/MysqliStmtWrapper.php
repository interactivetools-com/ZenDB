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
    private bool   $debugMode = false;

    public function __construct(MysqliWrapper $mysqliWrapper, string $query, float $startTime, bool $debugMode = false) {
        $this->query     = $query;
        $this->startTime = $startTime;
        $this->debugMode = $debugMode;

        parent::__construct($mysqliWrapper, $query);
    }

    public function bind_param($types, &...$params): bool
    {
        if (MySQLiWrapper::$enableLogging) {
            $this->boundParamsString = json_encode($params, JSON_THROW_ON_ERROR);
        }

        try {
            $result = parent::bind_param($types, ...$params);
        } catch (Throwable $e) {
            MySQLiWrapper::logQuery($this->startTime, "$this->query /* params: $this->boundParamsString */ ");
            MySQLiWrapper::logError($e->getMessage(), $e->getCode(), $e);
            throw $e; // rethrow exception
        }

        // log errors if mysqli_report is off
        if ($result === false) {
            MySQLiWrapper::logError($this->error, $this->errno);
        }

        return $result;
    }

    public function execute(?array $params = null): bool
    {
        try {
            $result = parent::execute($params);
        } catch (Throwable $e) {
            MySQLiWrapper::logQuery($this->startTime, "$this->query /* params: $this->boundParamsString */ ");
            MySQLiWrapper::logError($e->getMessage(), $e->getCode(), $e);
            throw $e; // rethrow exception
        }

        // log errors if mysqli_report is off
        MySQLiWrapper::logQuery($this->startTime, "$this->query /* params: $this->boundParamsString */ ");
        if ($result === false) {
            MySQLiWrapper::logError($this->error, $this->errno);
        }

        // track query for debug footer
        if ($this->debugMode) {
            MySQLiWrapper::trackQuery($this->startTime, "$this->query /* params: $this->boundParamsString */ ");
        }

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
