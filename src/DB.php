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
    //region Internal State

    /**
     * The default Connection instance
     */
    private static ?Connection $db = null;

    /**
     * For backwards compatibility - references the default connection's mysqli
     */
    public static ?MysqliWrapper $mysqli = null;

    /**
     * For backwards compatibility - mirrors the default connection's tablePrefix
     */
    public static string $tablePrefix = '';

    /**
     * Last instance created (for testing/debugging)
     */
    public static ?Connection $lastInstance = null;

    /**
     * Last exception thrown (for testing/debugging)
     */
    public static ?Throwable $lastException = null;

    //endregion
    //region Connection Management

    /**
     * Set the default Connection instance.
     * Called automatically by new Connection($config, default: true).
     *
     * @internal
     */
    public static function setDefault(Connection $conn): void
    {
        self::$db           = $conn;
        self::$mysqli       = $conn->mysqli;
        self::$tablePrefix  = $conn->tablePrefix;
        self::$lastInstance = $conn;
    }

    /**
     * Get the default Connection instance.
     *
     * @throws RuntimeException If no default connection is set
     */
    public static function getDefault(): Connection
    {
        if (self::$db === null) {
            throw new RuntimeException("No default database connection. Create one with: new Connection(\$config, default: true)");
        }
        return self::$db;
    }

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

    //endregion
    //region Factory Methods

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
        return self::getDefault()->clone($config);
    }

    //endregion
    //region Query Methods (delegate to $db)

    /**
     * Execute a raw SQL query.
     *
     * @param string $sqlTemplate SQL statement with placeholders
     * @param mixed  ...$params   Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws DBException
     * @throws Throwable
     */
    public static function query(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        try {
            return self::getDefault()->query($sqlTemplate, ...$params);
        } catch (Throwable $e) {
            self::$lastException = $e;
            throw $e;
        }
    }

    /**
     * Select rows from a table.
     *
     * @param string       $baseTable Table name (without prefix)
     * @param int|array|string $where     WHERE condition
     * @param mixed            ...$params Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws DBException
     */
    public static function select(string $baseTable, int|array|string $where = [], ...$params): SmartArrayHtml
    {
        return self::getDefault()->select($baseTable, $where, ...$params);
    }

    /**
     * Get a single row from a table.
     *
     * @param string       $baseTable Table name (without prefix)
     * @param int|array|string $where     WHERE condition
     * @param mixed            ...$params Parameters to bind
     * @return SmartArrayHtml Single row or empty SmartArrayHtml
     * @throws DBException
     */
    public static function get(string $baseTable, int|array|string $where = [], ...$params): SmartArrayHtml
    {
        return self::getDefault()->get($baseTable, $where, ...$params);
    }

    /**
     * Insert a row into a table.
     *
     * @param string $baseTable    Table name (without prefix)
     * @param array  $colsToValues Column => value pairs
     * @return int Insert ID
     * @throws DBException
     */
    public static function insert(string $baseTable, array $colsToValues): int
    {
        return self::getDefault()->insert($baseTable, $colsToValues);
    }

    /**
     * Update rows in a table.
     *
     * @param string       $baseTable    Table name (without prefix)
     * @param array        $colsToValues Column => value pairs to update
     * @param int|array|string $where        WHERE condition (required)
     * @param mixed            ...$params    Parameters to bind
     * @return int Number of affected rows
     * @throws DBException
     */
    public static function update(string $baseTable, array $colsToValues, int|array|string $where, ...$params): int
    {
        return self::getDefault()->update($baseTable, $colsToValues, $where, ...$params);
    }

    /**
     * Delete rows from a table.
     *
     * @param string       $baseTable Table name (without prefix)
     * @param int|array|string $where     WHERE condition (required)
     * @param mixed            ...$params Parameters to bind
     * @return int Number of affected rows
     * @throws DBException
     */
    public static function delete(string $baseTable, int|array|string $where, ...$params): int
    {
        return self::getDefault()->delete($baseTable, $where, ...$params);
    }

    /**
     * Count rows in a table.
     *
     * @param string       $baseTable Table name (without prefix)
     * @param int|array|string $where     WHERE condition
     * @param mixed            ...$params Parameters to bind
     * @return int Row count
     * @throws DBException
     */
    public static function count(string $baseTable, int|array|string $where = [], ...$params): int
    {
        return self::getDefault()->count($baseTable, $where, ...$params);
    }

    //endregion
    //region Table Helpers (delegate to $db)

    /**
     * Get base table name (without prefix).
     */
    public static function getBaseTable(string $table, bool $strict = false): string
    {
        return self::getDefault()->getBaseTable($table, $strict);
    }

    /**
     * Get full table name (with prefix).
     */
    public static function getFullTable(string $table, bool $strict = false): string
    {
        return self::getDefault()->getFullTable($table, $strict);
    }

    /**
     * Check if a table exists.
     */
    public static function tableExists(string $table, bool $isFullTable = false): bool
    {
        return self::getDefault()->tableExists($table, $isFullTable);
    }

    /**
     * Get list of table names.
     */
    public static function getTableNames(bool $includePrefix = false): array
    {
        return self::getDefault()->getTableNames($includePrefix);
    }

    /**
     * Get column definitions for a table.
     */
    public static function getColumnDefinitions(string $baseTable): array
    {
        return self::getDefault()->getColumnDefinitions($baseTable);
    }

    //endregion
    //region SQL Generation (delegate to $db)

    /**
     * Escape a string for safe inclusion in raw SQL.
     */
    public static function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        return self::getDefault()->escape($input, $escapeLikeWildcards);
    }

    /**
     * Escapes and quotes values, inserting them into a format string with ? placeholders.
     *
     * @param string $format    Format string with ? placeholders
     * @param mixed  ...$values Values to escape and insert
     * @return string SQL-safe string
     * @throws DBException
     */
    public static function escapef(string $format, mixed ...$values): string
    {
        self::$mysqli || throw new DBException(__METHOD__ . "() called before DB connection established");

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

    //endregion
    //region Static Utility Methods (no connection needed)

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

    /**
     * Converts array values to a safe CSV string for use in MySQL IN clauses.
     *
     * @param array $array Array of values to convert
     * @return RawSql SQL-safe comma-separated list
     * @throws DBException
     */
    public static function escapeCSV(array $array): RawSql
    {
        self::$mysqli || throw new DBException(__METHOD__ . "() called before DB connection established");

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

    /**
     * Creates a MySQL LIKE pattern for "contains" searches.
     */
    public static function likeContains(string|int|float|null|SmartString $input): RawSql
    {
        return self::getDefault()->likeContains($input);
    }

    /**
     * Creates a MySQL LIKE pattern for tab-delimited column searches.
     */
    public static function likeContainsTSV(string|int|float|null|SmartString $input): RawSql
    {
        return self::getDefault()->likeContainsTSV($input);
    }

    /**
     * Creates a MySQL LIKE pattern for "starts with" searches.
     */
    public static function likeStartsWith(string|int|float|null|SmartString $input): RawSql
    {
        return self::getDefault()->likeStartsWith($input);
    }

    /**
     * Creates a MySQL LIKE pattern for "ends with" searches.
     */
    public static function likeEndsWith(string|int|float|null|SmartString $input): RawSql
    {
        return self::getDefault()->likeEndsWith($input);
    }

    //endregion
    //region Utility Methods

    /**
     * Show debug information about the last query.
     */
    public static function debug(): void
    {
        /** @noinspection ForgottenDebugOutputInspection */
        print_r(self::$lastInstance);
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

    //endregion
    //region Legacy Support

    /**
     * Handle legacy static method calls.
     */
    public static function __callStatic(string $name, array $args)
    {
        return match ($name) {
            'like', 'escapeLikeWildcards' => addcslashes((string)($args[0] ?? ''), '%_'),
            'identifier'                  => self::rawSql("`" . self::$mysqli->real_escape_string(...$args) . "`"),
            'getTablePrefix'              => self::$tablePrefix,
            'raw'                         => self::rawSql(...$args),
            'datetime'                    => date('Y-m-d H:i:s', ($args[0] ?? time())),
            default                       => throw new InvalidArgumentException("Unknown static method: $name"),
        };
    }

    //endregion
}
