<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
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
    use DBInternals;

    //region Public Properties

    /**
     * The raw mysqli connection instance for direct database access.
     */
    public static ?MysqliWrapper $mysqli = null;

    /**
     * Table prefix prepended to table names (e.g., 'cms_')
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
     * @param array{
     *     hostname:               string,      // Database server hostname
     *     username:               string,      // Database username
     *     password:               string,      // Database password (use '' for none)
     *     database:               string,      // Database name
     *     tablePrefix?:           string,      // Prefix for table names (default: '')
     *     useSmartJoins?:         bool,        // Add `table.column` keys to JOIN results, first-wins on duplicate columns (default: true)
     *     useSmartStrings?:       bool,        // Wrap values in SmartString objects (default: true)
     *     usePhpTimezone?:        bool,        // Sync MySQL timezone with PHP (default: true)
     *     loadHandler?: callable,    // Custom result loading handler
     *     versionRequired?:       string,      // Minimum MySQL version (default: '5.7.32')
     *     requireSSL?:            bool,        // Require SSL connection (default: false)
     *     databaseAutoCreate?:    bool,        // Create database if missing (default: false)
     *     connectTimeout?:        int,         // Connection timeout in seconds (default: 3)
     *     readTimeout?:           int,         // Read timeout in seconds (default: 60)
     *     queryLogger?:           callable,    // fn(string $query, float $secs, ?Throwable $exception)
     *     sqlMode?:               string,      // MySQL SQL mode
     *     encryptionKey?:         string,      // AES encryption key, sets MySQL @ek session variable on first use
     * } $config
     * @throws RuntimeException If already connected
     * @see Connection::__construct()
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
     * @param bool $ping Whether to ping the server to verify connection
     */
    public static function isConnected(bool $ping = false): bool
    {
        return self::$db !== null && self::$db->isConnected($ping);
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
     *
     * @param array{
     *     tablePrefix?:     string,    // Prefix for table names
     *     useSmartJoins?:   bool,      // Add `table.column` keys to JOIN results, first-wins on duplicate columns
     *     useSmartStrings?: bool,      // Wrap values in SmartString objects
     * } $config Configuration overrides
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
    public static function insert(string $baseTable, array $values): int
    {
        return self::db()->insert($baseTable, $values);
    }

    /**
     * Wrapper for {@see Connection::update()}
     */
    public static function update(string $baseTable, array $values, int|array|string $whereEtc, ...$params): int
    {
        return self::db()->update($baseTable, $values, $whereEtc, ...$params);
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
    //region Encryption

    /**
     * @see Connection::encryptValue()
     */
    public static function encryptValue(string|int|float|null|SmartString $value): string|null
    {
        return self::db()->encryptValue($value);
    }

    /**
     * Returns the SQL expression to decrypt an encrypted column using the session encryption key (@ek).
     * Used internally by the {{column}} template syntax and available for custom query building.
     *
     *     DB::decryptExpr('email')       // "AES_DECRYPT(`email`, @ek)"
     *     DB::decryptExpr('blog.title')  // "AES_DECRYPT(`blog`.`title`, @ek)"
     *
     * @param string $column Column name, optionally dot-qualified (e.g., "table.column")
     * @return string SQL expression
     * @see Connection::encryptValue() for the write side (AES_ENCRYPT)
     */
    public static function decryptExpr(string $column): string
    {
        $column = str_replace('.', '`.`', $column); // "blog.title" => "blog`.`title"
        return "AES_DECRYPT(`$column`, @ek)";       // => AES_DECRYPT(`blog`.`title`, @ek)
    }

    //endregion

}
