<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use mysqli_sql_exception;
use RuntimeException;
use Throwable, Exception;

/**
 * DBException
 *
 * Usage:
 * try {
 *     $result = DB::query($query);
 * } catch (DBException $e) {
 *   // $e->getCode() contains MySQL error code
 *   // $e->getMessage() contains MySQL error message (and possibly other details)
 * }
 */
class DBException extends Exception {
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null) {
        $message = trim($message);

        // get exception chain in reverse order (first thrown to last, don't include current exception)
        $exceptionChain   = []; // oldest to newest
        $currentException = $previous;
        while ($currentException) {
            $exceptionChain[] = $currentException;
            $currentException = $currentException->getPrevious();
        }
        $exceptionChain = array_reverse($exceptionChain);

        // default code to MySQL error code from exception (first to last) or DB::$mysqli->errno
        if ($code !== 0) {
            throw new RuntimeException("DBException code must be 0 (got $code), automatically set to MySQL error code");
        }
        foreach ($exceptionChain as $exception) {
            if ($exception instanceof mysqli_sql_exception) {
                $code = $exception->getCode();
                break;
            }
        }
        $code = $code ?: (DB::$mysqli->errno ?? 0); // if no MySQL error code found in exception chain, use last MySQL error code

        // return DBException as-is if we're just rethrowing it
        if ($previous instanceof self) {
            $message .= $previous->getMessage();
            parent::__construct($message, $code, $previous);
            return;
        }

        // add each exception message to the main message - in reverse order so first thrown exception is first
        foreach ($exceptionChain as $exception) {
            // e.g., MySQL Error(1054): Unknown column 'unknown_col' in 'table_name'
            $exMessage = sprintf("\n\n%s(%d): %s", get_class($exception), $exception->getCode(), $exception->getMessage());
            if (!str_contains($message, $exMessage)) { // don't log twice if exception duplicate (such as when rethrowing exceptions)
                $message .= $exMessage;
            }
        }

        // add last MySQL error info if it's not in exception chain
        $lastErrNo       = DB::$mysqli->errno ?? null;
        $unreportedErrno = $lastErrNo && !array_filter($exceptionChain, fn($e) => $e->getCode() === $lastErrNo);
        if ($unreportedErrno) {
            $message .= sprintf("\n\n%s(%d): %s", "MySQL Error", DB::$mysqli->errno, DB::$mysqli->error);
        }

        // add default message
        $message = $message !== '' ? $message : "Unknown database error";

        // Format message (shorten long MySQL error messages)
        $message = str_replace("You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near",
                               "You have an error in your SQL syntax near", $message);
        $message = preg_replace("/ at line \d+/", "", $message); // remove " at line 1" from end of message, refers to line in SQL query itself and typically not useful

        // Add last SQL query based on showSqlInErrors setting
        $dbExceptionAlreadyThrown = (bool)array_filter($exceptionChain, fn($e) => $e instanceof DBException); // so we don't show further "last queries" between rethrows
        $thrownFromZenDB = str_starts_with($this->getTrace()[0]['class'] ?? '', 'Itools\\ZenDB\\'); // if this exception was thrown from ZenDB

        // Get the showSqlInErrors configuration setting
        $showSqlSetting = DB::config('showSqlInErrors');

        // Calculate if SQL should be shown:
        // 1. The exception hasn't already been processed
        // 2. We have a database connection
        // 3. Either:
        //    a. Exception is NOT from internal ZenDB code, OR
        //    b. showSqlInErrors setting explicitly allows it
        $showSql = !$dbExceptionAlreadyThrown && DB::isConnected(true) &&
                  (!$thrownFromZenDB || $showSqlSetting);

        // If it's a callable, let the callback decide
        if ($showSql && is_callable($showSqlSetting)) {
            $showSql = call_user_func($showSqlSetting);
        }

        // Add SQL to message if allowed
        if ($showSql) {
            $sqlMessage = "\n\nLast SQL query:\n" . trim(MysqliWrapper::getLastQuery());
            if (!str_contains($message, $sqlMessage)) { // only add if not already there
                $message .= $sqlMessage;
            }
        }

        //
        parent::__construct($message, $code, $previous);
    }

}
