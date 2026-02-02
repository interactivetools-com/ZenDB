<?php

declare(strict_types=1);

namespace Itools\ZenDB;

use Itools\SmartArray\SmartArrayBase;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
use InvalidArgumentException, RuntimeException;
use Throwable;

/**
 * DB is a static facade for ZenDB that provides convenient static methods for database access.
 *
 * For the common case (single CMS connection), use the static methods:
 *     new Connection(['hostname' => 'localhost', ...], default: true);
 *     $users = DB::select('users');
 *
 * For multiple connections or different settings, use Connection instances directly:
 *     $remote = new Connection(['hostname' => 'remote.example.com', ...]);
 *     $rows = $remote->select('legacy_table');
 */
class DB
{
    //region Public Properties

    /**
     * For backwards compatibility - references the default connection's mysqli
     */
    public static ?MysqliWrapper $mysqli = null;

    /**
     * For backwards compatibility - mirrors the default connection's tablePrefix
     */
    public static string $tablePrefix = '';

    //endregion
    //region Connection

    /**
     * Check if connected to the database.
     *
     * @param bool $doPing Whether to ping the server to verify connection
     */
    public static function isConnected(bool $doPing = false): bool
    {
        return self::$db !== null && self::$db->isConnected($doPing);
    }

    /**
     * Disconnect from the database.
     */
    public static function disconnect(): void
    {
        if (self::$db !== null) {
            self::$db->disconnect();
            self::$mysqli = null;
        }
    }

    /**
     * Clone the default connection with optional config overrides.
     * The clone shares the mysqli connection but has its own settings.
     *
     *     DB::clone()                                    // Clone with same settings
     *     DB::clone(['useSmartJoins' => false])          // Clone with overrides
     *
     * @param array $config Configuration overrides
     * @return Connection New Connection instance sharing the mysqli connection
     */
    public static function clone(array $config = []): Connection
    {
        return self::$db->clone($config);
    }

    //endregion
    //region Query Methods

    /**
     * Execute a raw SQL query.
     *
     * @param string $sqlTemplate SQL statement with placeholders
     * @param mixed  ...$params   Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public static function query(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        try {
            return self::$db->query($sqlTemplate, ...$params);
        } catch (Throwable $e) {
            self::$lastException = $e;
            throw $e;
        }
    }

    /**
     * Select rows from a table.
     *
     * @param string           $baseTable  Table name (without prefix)
     * @param int|array|string $whereEtc   WHERE and other clauses (ORDER BY, LIMIT, etc.)
     * @param mixed            ...$params  Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws InvalidArgumentException
     */
    public static function select(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        return self::$db->select($baseTable, $whereEtc, ...$params);
    }

    /**
     * Get a single row from a table.
     *
     * @param string           $baseTable  Table name (without prefix)
     * @param int|array|string $whereEtc   WHERE and other clauses (ORDER BY, LIMIT, etc.)
     * @param mixed            ...$params  Parameters to bind
     * @return SmartArrayHtml Single row or empty SmartArrayHtml
     * @throws InvalidArgumentException
     */
    public static function get(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        return self::$db->get($baseTable, $whereEtc, ...$params);
    }

    /**
     * Insert a row into a table.
     *
     * @param string $baseTable    Table name (without prefix)
     * @param array  $colsToValues Column => value pairs
     * @return int Insert ID
     * @throws InvalidArgumentException
     */
    public static function insert(string $baseTable, array $colsToValues): int
    {
        return self::$db->insert($baseTable, $colsToValues);
    }

    /**
     * Update rows in a table.
     *
     * @param string           $baseTable    Table name (without prefix)
     * @param array            $colsToValues Column => value pairs to update
     * @param int|array|string $whereEtc     WHERE condition (required), may include ORDER BY, LIMIT
     * @param mixed            ...$params    Parameters to bind
     * @return int Number of affected rows
     * @throws InvalidArgumentException
     */
    public static function update(string $baseTable, array $colsToValues, int|array|string $whereEtc, ...$params): int
    {
        return self::$db->update($baseTable, $colsToValues, $whereEtc, ...$params);
    }

    /**
     * Delete rows from a table.
     *
     * @param string           $baseTable  Table name (without prefix)
     * @param int|array|string $whereEtc   WHERE condition (required), may include ORDER BY, LIMIT
     * @param mixed            ...$params  Parameters to bind
     * @return int Number of affected rows
     * @throws InvalidArgumentException
     */
    public static function delete(string $baseTable, int|array|string $whereEtc, ...$params): int
    {
        return self::$db->delete($baseTable, $whereEtc, ...$params);
    }

    /**
     * Count rows in a table.
     *
     * @param string           $baseTable  Table name (without prefix)
     * @param int|array|string $whereEtc   WHERE and other clauses (but not LIMIT/OFFSET)
     * @param mixed            ...$params  Parameters to bind
     * @return int Row count
     * @throws InvalidArgumentException
     */
    public static function count(string $baseTable, int|array|string $whereEtc = [], ...$params): int
    {
        return self::$db->count($baseTable, $whereEtc, ...$params);
    }

    //endregion
    //region Table Helpers

    /**
     * Get base table name (without prefix).
     */
    public static function getBaseTable(string $table, bool $strict = false): string
    {
        return self::$db->getBaseTable($table, $strict);
    }

    /**
     * Get full table name (with prefix).
     */
    public static function getFullTable(string $table, bool $strict = false): string
    {
        return self::$db->getFullTable($table, $strict);
    }

    /**
     * Check if a table exists.
     */
    public static function tableExists(string $table, bool $isFullTable = false): bool
    {
        return self::$db->tableExists($table, $isFullTable);
    }

    /**
     * Get list of table names.
     */
    public static function getTableNames(bool $includePrefix = false): array
    {
        return self::$db->getTableNames($includePrefix);
    }

    /**
     * Get column definitions for a table.
     */
    public static function getColumnDefinitions(string $baseTable): array
    {
        return self::$db->getColumnDefinitions($baseTable);
    }

    //endregion
    //region SQL Generation

    /**
     * Escape a string for safe inclusion in raw SQL.
     */
    public static function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        return self::$db->escape($input, $escapeLikeWildcards);
    }

    /**
     * Escapes and quotes values, inserting them into a format string with ? placeholders.
     *
     * @param string $format    Format string with ? placeholders
     * @param mixed  ...$values Values to escape and insert
     * @return string SQL-safe string
     * @throws InvalidArgumentException
     */
    public static function escapef(string $format, mixed ...$values): string
    {
        self::$mysqli || throw new RuntimeException(__METHOD__ . "() called before DB connection established");

        return preg_replace_callback('/\?/', function () use (&$values) {
            $value = array_shift($values);

            return match (true) {
                is_string($value)                => "'" . self::$mysqli->real_escape_string($value) . "'",
                is_int($value), is_float($value) => $value,
                is_null($value)                  => 'NULL',
                is_array($value)                 => (string)self::escapeCSV($value),
                $value instanceof SmartArrayBase => (string)self::escapeCSV($value->toArray()),
                $value instanceof SmartString    => "'" . self::$mysqli->real_escape_string((string)$value->value()) . "'",
                is_bool($value)                  => $value ? 'TRUE' : 'FALSE',
                default                          => throw new InvalidArgumentException("Unsupported type: " . get_debug_type($value)),
            };
        }, $format);
    }

    /**
     * Converts array values to a safe CSV string for use in MySQL IN clauses.
     *
     * @param array $array Array of values to convert
     * @return RawSql SQL-safe comma-separated list
     * @throws InvalidArgumentException
     */
    public static function escapeCSV(array $array): RawSql
    {
        self::$mysqli || throw new RuntimeException(__METHOD__ . "() called before DB connection established");

        $safeValues = [];
        foreach (array_unique($array) as $value) {
            $value        = $value instanceof SmartString ? (string)$value->value() : $value;
            $safeValues[] = match (true) {
                is_int($value) || is_float($value) => $value,
                is_null($value)                    => 'NULL',
                is_bool($value)                    => $value ? 'TRUE' : 'FALSE',
                is_string($value)                  => "'" . self::$mysqli->real_escape_string($value) . "'",
                default                            => throw new InvalidArgumentException("Unsupported value type: " . get_debug_type($value)),
            };
        }

        $sqlSafeCSV = $safeValues ? implode(',', $safeValues) : 'NULL';
        return self::rawSql($sqlSafeCSV);
    }

    /**
     * Creates a MySQL LIKE pattern for "contains" searches.
     */
    public static function likeContains(string|int|float|null|SmartString $input): RawSql
    {
        return self::$db->likeContains($input);
    }

    /**
     * Creates a MySQL LIKE pattern for tab-delimited column searches.
     */
    public static function likeContainsTSV(string|int|float|null|SmartString $input): RawSql
    {
        return self::$db->likeContainsTSV($input);
    }

    /**
     * Creates a MySQL LIKE pattern for "starts with" searches.
     */
    public static function likeStartsWith(string|int|float|null|SmartString $input): RawSql
    {
        return self::$db->likeStartsWith($input);
    }

    /**
     * Creates a MySQL LIKE pattern for "ends with" searches.
     */
    public static function likeEndsWith(string|int|float|null|SmartString $input): RawSql
    {
        return self::$db->likeEndsWith($input);
    }

    /**
     * Generates a LIMIT/OFFSET SQL clause for pagination.
     *
     * @param mixed $pageNum The current page number
     * @param mixed $perPage The number of records per page
     * @return RawSql LIMIT/OFFSET clause
     */
    public static function pagingSql(mixed $pageNum, mixed $perPage = 10): RawSql
    {
        $pageNum = abs((int)$pageNum) ?: 1;
        $perPage = abs((int)$perPage) ?: 10;

        $offset = ($pageNum - 1) * $perPage;
        return self::rawSql("LIMIT $perPage OFFSET $offset");
    }

    //endregion
    //region RawSql Helpers

    /**
     * Mark a value as raw SQL (not to be escaped/quoted).
     */
    public static function rawSql(string|int|float|null $value): RawSql
    {
        return new RawSql((string)$value);
    }

    /**
     * Check if a value is a RawSql instance.
     */
    public static function isRawSql(mixed $value): bool
    {
        return $value instanceof RawSql;
    }

    //endregion
    //region Utility Methods

    /**
     * Show debug information about the default connection.
     */
    public static function debug(): void
    {
        /** @noinspection ForgottenDebugOutputInspection */
        print_r(self::$db);
    }

    /**
     * Add "Occurred in file:line" to error messages.
     */
    public static function occurredInFile(bool $addReportedFileLine = false): string
    {
        $file      = "unknown";
        $line      = "unknown";
        $inMethod  = "";
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($backtrace as $index => $caller) {
            if (empty($caller['file']) || dirname($caller['file']) !== __DIR__) {
                $file       = $caller['file'] ?? $file;
                $line       = $caller['line'] ?? $line;
                $prevCaller = $backtrace[$index + 1] ?? [];
                $inMethod   = match (true) {
                    !empty($prevCaller['class'])    => " in {$prevCaller['class']}{$prevCaller['type']}{$prevCaller['function']}()",
                    !empty($prevCaller['function']) => " in {$prevCaller['function']}()",
                    default                         => "",
                };
                break;
            }
        }
        $output = "Occurred in $file:$line$inMethod\nReported";

        if ($addReportedFileLine) {
            $method       = basename($backtrace[1]['class']) . $backtrace[1]['type'] . $backtrace[1]['function'];
            $reportedFile = $backtrace[0]['file'] ?? "unknown";
            $reportedLine = $backtrace[0]['line'] ?? "unknown";
            $output       .= " in $reportedFile:$reportedLine in $method()\n";
        }

        return $output;
    }

    /**
     * Log a deprecation warning with caller location.
     *
     * @param string $message Deprecation message (caller file:line will be appended)
     */
    public static function logDeprecation(string $message): void
    {
        // Find first caller outside ZenDB src directory
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $caller) {
            if (!empty($caller['file']) && dirname($caller['file']) !== __DIR__) {
                $message .= " in {$caller['file']}:{$caller['line']}";
                break;
            }
        }
        @trigger_error($message, E_USER_DEPRECATED);
    }

    //endregion
    //region Legacy Support

    /**
     * Handle legacy static method calls.
     */
    public static function __callStatic(string $name, array $args)
    {
        [$replacement, $result] = match (strtolower($name)) {
            'like', 'escapelikewildcards' => ["DB::escape(\$value, true)",       addcslashes((string)($args[0] ?? ''), '%_')],
            'identifier'                  => ["backtick placeholders: `?` or `:name`", self::rawSql("`" . self::$mysqli->real_escape_string(...$args) . "`")],
            'gettableprefix'              => ["DB::\$tablePrefix",               self::$tablePrefix],
            'raw'                         => ["DB::rawSql()",                    self::rawSql(...$args)],
            'datetime'                    => ["date('Y-m-d H:i:s', \$time)",     date('Y-m-d H:i:s', ($args[0] ?? time()))],
            default                       => throw new InvalidArgumentException("Unknown static method: $name"),
        };
        self::logDeprecation("DB::$name() is deprecated, use $replacement instead");

        return $result;
    }

    //endregion
    //region Internal State

    /**
     * The default Connection instance
     */
    public static ?Connection $db = null;

    /**
     * Last exception thrown (for testing/debugging)
     */
    public static ?Throwable $lastException = null;

    //endregion
}
