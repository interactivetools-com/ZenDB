<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Error, Exception, Throwable;
use mysqli, mysqli_result, mysqli_stmt;

/**
 * Class MysqliWrapper
 *
 * Extends mysqli to add query logging and debug tracking.
 */
class MysqliWrapper extends mysqli
{
    //region Main

    /**
     * Records the last query.  Recorded just before the query is executed so that it can be accessed in case of an error.
     */
    private static string $lastQuery = "Unknown"; // use setLastQuery() to set this from outside this class

    /**
     * Query timing data for debug footer
     * Only populated when debug mode is enabled
     * Each entry: [webPathAndLine, seconds, query]
     */
    public static array $debugData = [];

    /**
     * Force execute_query() to use polyfill instead of native (for testing)
     */
    public static bool $forcePolyfill = false;

    /**
     * Keeps last statement alive to preserve affected_rows/insert_id (polyfill only)
     */
    private ?\mysqli_stmt $lastStmt = null;

    /**
     * Whether debug mode is enabled (tracks queries for debug footer)
     */
    private bool $debugMode = false;

    /**
     * Callback to get web root for relative paths in debug output
     * Signature: fn(): string
     */
    private $webRootCallback = null;

    public static function setLastQuery(string $query): void {
        self::$lastQuery = $query;
    }

    public static function getLastQuery(): string {
        return self::hideSensitiveData(self::$lastQuery);
    }

    //endregion
    //region Overridden Methods

    /**
     * @param bool $enableLogging Whether to enable query logging to file
     * @param string $logFile Path to log file
     * @param bool $debugMode Whether to track queries for debug footer
     * @param callable|null $webRootCallback Optional callback that returns web root path for relative file paths
     */
    public function __construct(
        bool $enableLogging = false,
        string $logFile = '_mysql_query_log.php',
        bool $debugMode = false,
        ?callable $webRootCallback = null
    ) {
        $this->debugMode       = $debugMode;
        $this->webRootCallback = $webRootCallback;

        self::initializeLogging($enableLogging, $logFile);

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

        // track connection for debug footer
        if ($this->debugMode) {
            $duration    = microtime(true) - $startTime;
            $fileAndLine = $this->getCallerWebPathAndLine();

            // Build connection info string with persistent indicator
            $hostInfo       = $this->host_info ?? 'unknown';                            // e.g. "localhost via TCP/IP"
            $persistent     = str_starts_with((string)$hostname, 'p:') ? 'p:' : '';     // Add 'p:' prefix if persistent connection
            $connectionInfo = sprintf("* MySQL Connection (%s)", $persistent . $hostInfo);

            self::$debugData[] = [$fileAndLine, $duration, $connectionInfo];
        }

        // log query
        $logEntry = sprintf("real_connect[%s]: %s %s", $this->thread_id, $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '');
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
            // track query for debug footer before logging (to get accurate timing)
            if ($this->debugMode) {
                $this->trackQuery($startTime, $query);
            }
            self::logQuery($startTime, $query);
            self::logError($e->getMessage(), $e->getCode(), $e);
            throw $e; // rethrow exception
        }

        // track query for debug footer
        if ($this->debugMode) {
            $this->trackQuery($startTime, $query);
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

        // track query for debug footer
        if ($this->debugMode) {
            $this->trackQuery($startTime, $query);
        }

        self::logQuery($startTime, "real_query: $query");

        return $result;
    }

    public function multi_query(string $query): bool
    {
        self::$lastQuery = $query;
        $startTime = microtime(true);

        $result = parent::multi_query($query);

        // track query for debug footer
        if ($this->debugMode) {
            $this->trackQuery($startTime, $query);
        }

        self::logQuery($startTime, "multi_query: $query");

        return $result;
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        self::$lastQuery = $query;
        $startTime = microtime(true);

        try {
            $result = new MysqliStmtWrapper($this, $query, $startTime, $this->debugMode);
        } catch (Throwable $e) {
            self::logError($e->getMessage(), $e->getCode(), $e);
            throw $e; // rethrow exception
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

            if ($this->debugMode) {
                $this->trackQuery($startTime, $query);
            }
            self::logQuery($startTime, $query);

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
    //region Logging Methods

    public static string $logFile       = '_mysql_query_log.php';
    public static bool   $enableLogging = false;

    public static function initializeLogging(bool $enableLogging, string $logFile): void
    {
        // set properties
        self::$logFile       = $logFile;
        self::$enableLogging = $enableLogging;

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
        if ($mysqli && $mysqli->errno) {
            $error .= "MySQL Error($mysqli->errno): $mysqli->error\n";
        }

        // Add exception info if available
        $isPreparedStmtFail = $e instanceof Error && preg_match("/is not fully initialized/i", $message);
        if ($mysqli && !$mysqli->errno && $isPreparedStmtFail) { // only report if we don't have a MySQL error
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
        $threadId  = DB::$mysqli->thread_id ?? 0;                                        // get thread id
        $ipAddr    = $_SERVER['REMOTE_ADDR'] ?? "";
        $ajaxOrCli = match (true) {
            self::isAjaxRequest() => "AJAX|",
            self::inCLI()         => "CLI|",
            default               => "",
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

    //endregion
    //region Query Tracking Methods

    /**
     * Track query execution for debug footer
     * NOTE: Caller must check $this->debugMode before calling for performance
     *
     * @param float $startTime Microtime when query started
     * @param string $query The SQL query that was executed
     */
    public function trackQuery(float $startTime, string $query): void
    {
        // do this first
        $duration = microtime(true) - $startTime;

        $fileAndLine = $this->getCallerWebPathAndLine();

        // Format raw query for display in debug footer comment
        $displayQuery = preg_replace('/[ \r\n]+/', ' ', trim($query));  // collapse spaces and newlines
        $displayQuery = str_replace("\t", '\\t', $displayQuery);        // replace tabs with \t for better readability

        // Store: [$fileAndLine, duration, displayQuery]
        self::$debugData[] = [$fileAndLine, $duration, $displayQuery];
    }

    /**
     * Get the calling location outside of ZenDB
     *
     * @return string File path and line number (e.g., "/cmsb/lib/User.php:123")
     */
    public function getCallerWebPathAndLine(): string
    {
        $trace    = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);    // Increase depth if you're getting unknown:0
        array_shift($trace); // drop our own frame

        // Skip database library frames so we get to the actual caller
        $skipStrings = [
            '/SmartArray/',
            '/SmartString/',
            '/lib/ZenDB/',
            '/src/',
            '/lib/mysql_functions.php',
        ];

        // Get the current file to skip it as well
        static $currentFile = null;
        $currentFile ??= str_replace('\\', '/', __FILE__);

        // Find the first frame that is not in the skip list
        foreach ($trace as $frame) {
            $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : '';
            if ($file === '') {
                continue;
            }

            // if any of the skip-strings appear in the path, skip this frame
            $skip = false;
            foreach ($skipStrings as $skipString) {
                if (str_contains($file, $skipString)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $line = $frame['line'] ?? 0;

            // make it relative to webRoot if applicable (remove webRoot prefix)
            if ($this->webRootCallback) {
                $webRoot = ($this->webRootCallback)();
                if (str_starts_with($file, $webRoot)) {
                    $file = substr($file, strlen($webRoot));
                }
            }

            return "$file:$line";
        }

        return 'unknown:0';
    }

    //endregion
    //region Utility Methods

    /**
     * Check if current request is an AJAX request
     */
    private static function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Check if running from command line
     */
    private static function inCLI(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    //endregion
}
