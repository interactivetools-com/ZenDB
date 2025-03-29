<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Error, Exception, Throwable;
use mysqli, mysqli_result,  mysqli_stmt;

/**
 * Class MysqliWrapper
 *
 */
class MysqliWrapper extends mysqli
{
    #region Main

    /**
     * Records the last query.  Recorded just before the query is executed so that it can be accessed in case of an error.
     */
    private static string $lastQuery = "Unknown"; // use setLastQuery() to set this from outside this class

    public static function setLastQuery(string $query): void {
        self::$lastQuery = $query;
    }

    public static function getLastQuery(): string {
        return self::hideSensitiveData(self::$lastQuery);
    }

    #endregion
    #region Overridden Methods
    public function __construct() {
        self::initializeLogging();

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

        // log query
        $logEntry = sprintf("real_connect[%s]: %s %s", DB::$mysqli->thread_id, $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '');
        self::logQuery($startTime, $logEntry);

        //
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
            self::logQuery($startTime, $query);
            self::logError($e->getMessage(), $e->getCode(), $e);
            throw $e; // rethrow exception
        }

        // log query
        self::logQuery($startTime, $query);
        if ($result === false) { // log failed queries (for when mysql_report_mode is off and exceptions are not thrown)
            self::logError($this->error, $this->errno);
        }

        //
        return $result;
    }

    public function real_query(string $query): bool
    {
        self::$lastQuery = $query;
        $startTime = microtime(true);

        $result = parent::real_query($query);

        self::logQuery($startTime, "real_query: $query");

        return $result;
    }

    public function multi_query(string $query): bool
    {
        self::$lastQuery = $query;
        $startTime = microtime(true);

        $result = parent::multi_query($query);

        self::logQuery($startTime, "multi_query: $query");

        return $result;
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        self::$lastQuery = $query;
        $startTime = microtime(true);

        try {
            $result = new MysqliStmtWrapper($this, $query, $startTime);
        } catch (Throwable $e) {
            self::logError($e->getMessage(), $e->getCode(), $e);
            throw $e; // rethrow exception
        }

        return $result;
    }

    #endregion
    #region Logging Methods

    public static string $logFile;
    public static bool   $enableLogging = false;

    public static function initializeLogging(): void
    {
        // set properties
        self::$logFile       = DB::config('logFile');
        self::$enableLogging = DB::config('enableLogging');

        // If logging enabled
        if (self::$enableLogging) {
            // If log file is too big, delete it
            if (is_file(self::$logFile) && @filesize(self::$logFile) > 50_000) {
                @unlink(self::$logFile); // hide can't find file warnings
            }

            // Or if log file doesn't exist, create it with a PHP header that prevents direct access
            if (!is_file(self::$logFile)) {
                file_put_contents(self::$logFile, "<?php exit();\n", LOCK_EX);
            }
        }

        // Otherwise, if logging disabled and log file still exists, delete it
        if (!self::$enableLogging && is_file(self::$logFile)) {
            @unlink(self::$logFile); // hide can't find file warnings
        }
    }

    /**
     * Creates formatted error log entry and called logQuery()
     * Usage:
     * logError($e->getMessage(), $e->getCode(), $e);
     * logError($e->getMessage(), $e->getCode(), $e);
     */
    public static function logError(string $message, ?int $code = null, ?Throwable $e = null): void {
        // skip if logging disabled or from live log viewer (to prevent infinite recursion)
        if (!self::$enableLogging || array_key_exists('liveLogViewer', $_REQUEST)) {
            return;
        }

        $mysqli = DB::$mysqli;
        $class  = is_object($e) ? get_class($e) : ""; // get class name if available

        // Add latest MySQL error info if available
        $error = "";
        if ($mysqli->errno) {
            $error .= "MySQL Error($mysqli->errno): $mysqli->error\n";
        }

        // Add exception info if available
        $isPreparedStmtFail = $e instanceof Error && preg_match("/is not fully initialized/i", $message);
        if (!$mysqli->errno && $isPreparedStmtFail) { // only report if we don't have a MySQL error
            // Invalid Prepared MySQL queries sometimes throw Error: MysqliStmtWrapper|stmt object is not fully initialized on bind_param() or execute()
            $error .= "$class Error($code): You have an error in your SQL syntax (prepared statement failed).\n";
        }

        // Add exception info if available
        elseif (!$isPreparedStmtFail && $e !== null) {
            $error .= "$class($code): $message\n";
        }

        // Add last query info
        $error .= "Last Query: " .self::getLastQuery(). "\n";

        self::logQuery(null, $error);
    }

    /**
     * Usage:
     * $startTime = microtime(true);
     * $result    = $mysqli->query($query);
     * MySQLiWrapper::logQuery($startTime, $query);
     */
    public static function logQuery(?float $startTime, string $query): void {
        // skip if logging disabled or from live log viewer (to prevent infinite recursion)
        if (!self::$enableLogging || array_key_exists('liveLogViewer', $_REQUEST)) {
            return;
        }

        //
        $endTime   = microtime(true);  // track end time first before anything else
        $isError   = !$startTime;      // if no start time, this is an error
        $timestamp = date('Y-m-d H:i:s');
        $duration  = sprintf('%.3f', $startTime ? $endTime - $startTime : 0);            // in seconds, e.g. 0.001s
        $query     = !$isError ? preg_replace("/\s+/", " ", trim($query)) : $query;      // remove newlines and multiple spaces
        $query     = self::hideSensitiveData($query);                                    // hide sensitive data
        $threadId  = DB::$mysqli->thread_id;                                             // get thread id
        $ipAddr    = $_SERVER['REMOTE_ADDR'] ?? "";
        $ajaxOrCli = match (true) {
           \Itools\Cmsb\Server::isAjaxRequest() => "AJAX|",
           \Itools\Cmsb\Server::inCLI()         => "CLI|",
            default                              => "",
        };

        // format log entry
        $logEntry = match(true) {
            $isError => "\n$query\n",
            default  => "[$ajaxOrCli$threadId|$ipAddr] $timestamp ({$duration}s) $query\n",
        };

        // Add newline before connection queries
        if (str_starts_with($query, "real_connect")) {
            $logEntry = "\n$logEntry";
        }

        //
        file_put_contents(self::$logFile, $logEntry, FILE_APPEND);
    }

    /**
     * @param string $query
     *
     * @return string
     */
    public static function hideSensitiveData(string $query): string {

        // hide mysql encryption values
        $query = preg_replace("/AES_ENCRYPT\(.*?\)\)\)/i", "AES_ENCRYPT(/*HIDDEN*/)", $query);
        $query = preg_replace("/AES_DECRYPT\(.*?\)\)\)/i", "AES_DECRYPT(/*HIDDEN*/)", $query);

        // hide password hashes
        $query = preg_replace("/(`?password`?\s*=\s*)[^,]+/i", "$1/*HIDDEN*/", $query);

        // hide session data
        if (preg_match("/`[^`]*_sessions`/", $query)) {
            $query = preg_replace("/id = ['\"].*?['\"]/i", "id = /*HIDDEN*/", $query); // hide session data
            $query = preg_replace("|/\* params: .*$|i", "/* params: HIDDEN */", $query); // hide session data
        }

        return $query;
    }

    #endregion
}
