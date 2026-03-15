<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Exception;
use InvalidArgumentException;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
use JetBrains\PhpStorm\Deprecated;
use RuntimeException;
use Throwable;

/**
 * DB is a static facade for ZenDB that provides convenient static methods for database access.
 *
 * For the common case (single connection), use the static methods:
 *     DB::connect(['hostname' => 'localhost', ...]);
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
     * Connect to the database and set as the default connection.
     * Throws if already connected. To reconnect, call disconnect() first.
     *
     *     DB::connect(['hostname' => 'localhost', 'database' => 'my_db', ...]);
     *     DB::select('users');  // Uses the default connection
     *
     * @param array $config See Connection::__construct() for all supported options
     * @throws RuntimeException If already connected
     */
    public static function connect(array $config = []): void
    {
        if (self::isConnected()) {
            throw new RuntimeException("Already connected. To reconnect, call disconnect() first.");
        }

        $conn = new Connection($config);

        self::$db          = $conn;
        self::$mysqli      = $conn->mysqli;
        self::$tablePrefix = $conn->tablePrefix;
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
            self::$db          = null;
            self::$mysqli      = null;
            self::$tablePrefix = '';
        }
    }

    /**
     * Wrapper for {@see Connection::clone()}
     */
    public static function clone(array $config = []): Connection
    {
        return self::db()->clone($config);
    }

    //endregion
    //region Query Methods

    /**
     * Wrapper for {@see Connection::query()}
     */
    public static function query(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        return self::db()->query($sqlTemplate, ...$params);
    }

    /**
     * Wrapper for {@see Connection::queryOne()}
     */
    public static function queryOne(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        return self::db()->queryOne($sqlTemplate, ...$params);
    }

    /**
     * Wrapper for {@see Connection::select()}
     */
    public static function select(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        return self::db()->select($baseTable, $whereEtc, ...$params);
    }

    /**
     * Wrapper for {@see Connection::selectOne()}
     */
    public static function selectOne(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        return self::db()->selectOne($baseTable, $whereEtc, ...$params);
    }

    /**
     * Wrapper for {@see Connection::insert()}
     */
    public static function insert(string $baseTable, array $colsToValues): int
    {
        return self::db()->insert($baseTable, $colsToValues);
    }

    /**
     * Wrapper for {@see Connection::update()}
     */
    public static function update(string $baseTable, array $colsToValues, int|array|string $whereEtc, ...$params): int
    {
        return self::db()->update($baseTable, $colsToValues, $whereEtc, ...$params);
    }

    /**
     * Wrapper for {@see Connection::delete()}
     */
    public static function delete(string $baseTable, int|array|string $whereEtc, ...$params): int
    {
        return self::db()->delete($baseTable, $whereEtc, ...$params);
    }

    /**
     * Wrapper for {@see Connection::count()}
     */
    public static function count(string $baseTable, int|array|string $whereEtc = [], ...$params): int
    {
        return self::db()->count($baseTable, $whereEtc, ...$params);
    }

    /**
     * Wrapper for {@see Connection::transaction()}
     *
     * @throws Throwable Re-throws any exception after rolling back
     */
    public static function transaction(callable $fn): mixed
    {
        return self::db()->transaction($fn);
    }

    //endregion
    //region Table Helpers

    /**
     * Wrapper for {@see Connection::getBaseTable()}
     */
    public static function getBaseTable(string $table, bool $checkDb = false): string
    {
        return self::db()->getBaseTable($table, $checkDb);
    }

    /**
     * Wrapper for {@see Connection::getFullTable()}
     */
    public static function getFullTable(string $table, bool $checkDb = false): string
    {
        return self::db()->getFullTable($table, $checkDb);
    }

    /**
     * Wrapper for {@see Connection::getTableNames()}
     */
    public static function getTableNames(bool $withPrefix = false): array
    {
        return self::db()->getTableNames($withPrefix);
    }

    /**
     * Wrapper for {@see Connection::getColumnDefinitions()}
     */
    public static function getColumnDefinitions(string $baseTable): array
    {
        return self::db()->getColumnDefinitions($baseTable);
    }

    /**
     * Wrapper for {@see Connection::hasTable()}
     */
    public static function hasTable(string $table, bool $isPrefixed = false): bool
    {
        return self::db()->hasTable($table, $isPrefixed);
    }

    //endregion
    //region SQL Generation

    /**
     * Mark a value as raw SQL (not to be escaped/quoted).
     */
    public static function rawSql(string|int|float|null $value): RawSql
    {
        return new RawSql((string)$value);
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
     * Wrapper for {@see Connection::likeContains()}
     */
    public static function likeContains(string|int|float|null|SmartString $input): RawSql
    {
        return self::db()->likeContains($input);
    }

    /**
     * Wrapper for {@see Connection::likeContainsTSV()}
     */
    public static function likeContainsTSV(string|int|float|null|SmartString $input): RawSql
    {
        return self::db()->likeContainsTSV($input);
    }

    /**
     * Wrapper for {@see Connection::likeStartsWith()}
     */
    public static function likeStartsWith(string|int|float|null|SmartString $input): RawSql
    {
        return self::db()->likeStartsWith($input);
    }

    /**
     * Wrapper for {@see Connection::likeEndsWith()}
     */
    public static function likeEndsWith(string|int|float|null|SmartString $input): RawSql
    {
        return self::db()->likeEndsWith($input);
    }

    //endregion
    //region Internals

    /**
     * The default Connection instance
     */
    private static ?Connection $db = null;

    /**
     * Get the default connection, throwing if not connected.
     */
    private static function db(): Connection
    {
        return self::$db ?? throw new RuntimeException(
            "No database connection. Call DB::connect() first."
        );
    }

    /**
     * Wrapper for {@see Connection::escape()}
     */
    public static function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        return self::db()->escape($input, $escapeLikeWildcards);
    }

    /**
     * Wrapper for {@see Connection::escapef()}
     */
    public static function escapef(string $format, mixed ...$values): string
    {
        return self::db()->escapef($format, ...$values);
    }

    /**
     * Wrapper for {@see Connection::escapeCSV()}
     */
    public static function escapeCSV(array $array): RawSql
    {
        return self::db()->escapeCSV($array);
    }

    //endregion
    //region Deprecations

    /**
     * @deprecated Use DB::hasTable() instead
     * @see DB::hasTable()
     */
    #[Deprecated(replacement: 'DB::hasTable(%parametersList%)')]
    public static function tableExists(string $table, bool $isPrefixed = false): bool
    {
        self::logDeprecation("DB::tableExists() is deprecated, use DB::hasTable() instead");
        return self::db()->hasTable($table, $isPrefixed);
    }

    /**
     * @deprecated Use DB::selectOne() instead
     * @see DB::selectOne()
     */
    #[Deprecated(replacement: 'DB::selectOne(%parametersList%)')]
    public static function get(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        self::logDeprecation("DB::get() is deprecated, use DB::selectOne() instead");
        return self::db()->selectOne($baseTable, $whereEtc, ...$params);
    }

    /**
     * Handle legacy static method calls.
     * @noinspection SpellCheckingInspection for lowercase method names
     */
    public static function __callStatic(string $name, array $args): mixed
    {
        [$replacement, $result] = match (strtolower($name)) {
            'like', 'escapelikewildcards' => ["DB::escape(\$value, true)",       addcslashes((string)($args[0] ?? ''), '%_')],
            'identifier'                  => throw new InvalidArgumentException("DB::identifier() has been removed for security. Use backtick placeholders instead: `?` or `:name`"),
            'gettableprefix'              => ["DB::\$tablePrefix",               self::$tablePrefix],
            'israwsql'                    => ["\$value instanceof RawSql",       ($args[0] ?? null) instanceof RawSql],
            'raw'                         => ["DB::rawSql()",                    self::rawSql(...$args)],
            'datetime'                    => ["date('Y-m-d H:i:s', \$time)",     date('Y-m-d H:i:s', ($args[0] ?? time()))],
            default                       => throw new InvalidArgumentException("Unknown static method: $name"),
        };
        self::logDeprecation("DB::$name() is deprecated, use $replacement instead");

        return $result;
    }

    /**
     * Log a deprecation warning with caller location.
     *
     * @param string $message Deprecation message (caller file:line will be appended)
     */
    public static function logDeprecation(string $message): void
    {
        // Find first caller outside ZenDB src directory
        $file = "unknown";
        $line = "unknown";
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $caller) {
            if (!empty($caller['file']) && dirname($caller['file']) !== __DIR__) {
                $file = basename($caller['file']);
                $line = $caller['line'] ?? "unknown";
                break;
            }
        }
        @trigger_error("$message in $file:$line", E_USER_DEPRECATED);
    }

    //endregion

}
