<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayBase;
use Itools\SmartString\SmartString;
use JetBrains\PhpStorm\Deprecated;
use RuntimeException;

/**
 * Internal methods and deprecation support for DB.
 *
 * Handles:
 * - Default connection management
 * - Escape methods (escape, escapef, escapeCSV)
 * - Deprecated method support
 */
trait DBInternals
{
    //region Internals

    /**
     * The default Connection instance
     */
    private static ?Connection $db = null;

    /**
     * Get the default Connection instance, e.g. to pass somewhere a Connection is expected.
     * Throws a RuntimeException when not connected.
     *
     *     DB::connection()->table->exists('users');  // same call Table::exists('users') makes
     */
    public static function connection(): Connection
    {
        return self::$db ?? throw new RuntimeException(
            "No database connection. Call DB::connect() first.",
        );
    }

    /**
     * Wrapper for {@see Connection::escape()}
     *
     * @internal
     */
    public static function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        return self::connection()->escape($input, $escapeLikeWildcards);
    }

    /**
     * Wrapper for {@see Connection::escapef()}
     *
     * @internal
     */
    public static function escapef(string $format, mixed ...$values): string
    {
        return self::connection()->escapef($format, ...$values);
    }

    /**
     * Wrapper for {@see Connection::escapeCSV()}
     *
     * @internal
     */
    public static function escapeCSV(array $values): RawSql
    {
        return self::connection()->escapeCSV($values);
    }

    /**
     * Throw unless a string is a safe SQL identifier: letters, numbers, _ and - only.
     * Runs on every table and column name ZenDB puts between backticks - the check that
     * matters there, since real_escape_string() doesn't escape backticks, so escaping
     * alone can't make an identifier safe. $what names the value in the error message.
     *
     *     DB::assertIdentifier($fullTable, 'table name'); // throws for 'title; DROP TABLE users'
     *
     * @param string $identifier The string to check
     * @param string $what Noun for the error message, e.g. 'table name', 'column name'
     * @throws InvalidArgumentException
     */
    public static function assertIdentifier(string $identifier, string $what = 'identifier'): void
    {
        if (!preg_match('/^[\w-]+\z/', $identifier)) { // \z: $ would also match before a trailing newline
            throw new InvalidArgumentException("Invalid $what '$identifier', allowed characters: a-z, A-Z, 0-9, _, -");
        }
    }

    /**
     * @see Connection::decryptRows()
     */
    public static function decryptRows(array &$rows, array $fetchFields): void
    {
        self::connection()->decryptRows($rows, $fetchFields);
    }

    /**
     * PHP's current timezone expressed as a value MySQL's SET time_zone accepts.
     * Used at connect when `usePhpTimezone` is set; call it yourself after changing
     * PHP's timezone mid-request to bring the database session back in step:
     *
     *     date_default_timezone_set('Pacific/Kiritimati');
     *     DB::query("SET time_zone = ?", DB::phpTimezoneForMysql());
     *
     * Returns PHP's UTC offset (e.g. "-08:00"), except offsets past +13:00 (Kiritimati
     * +14:00, Chatham +13:45 in DST), which MariaDB and MySQL before 8.0.19 reject with
     * error 1298 (bug #63685). Those return an IANA name instead, which needs the
     * mysql.time_zone tables: Linux servers ship them loaded, Windows installs ship them
     * empty and reject the name with "Unknown or incorrect time zone" until they're
     * loaded (import MySQL's downloadable timezone package into the mysql schema and
     * restart: https://dev.mysql.com/downloads/timezones.html).
     *
     * @return string A UTC offset like "+02:00", or an IANA zone name for offsets past +13:00
     */
    public static function phpTimezoneForMysql(): string
    {
        return match ($offset = date('P')) {
            '+14:00' => 'Etc/GMT-14',
            '+13:45' => 'Pacific/Chatham',
            default  => $offset,
        };
    }

    /**
     * Detect encrypted columns from field metadata. Returns column names for MEDIUMBLOB fields,
     * which are the standard storage type for AES_ENCRYPT() data.
     *
     * Called automatically by query methods when an encryption key is configured.
     * You don't normally need to call this directly.
     *
     *     $encryptedCols = DB::getEncryptedColumns($result->fetch_fields());
     *
     * @param array $fetchFields Field objects from fetch_fields()
     * @return array<int, string> Detected encrypted columns, keyed by field index (e.g. [0 => 'token', 3 => 'ssn'])
     */
    public static function getEncryptedColumns(array $fetchFields): array
    {
        $encrypted = [];
        foreach ($fetchFields as $index => $field) {
            $isMediumBlob = $field->type === MYSQLI_TYPE_BLOB && $field->charsetnr === 63 && $field->length === 16_777_215;
            if ($isMediumBlob) {
                $encrypted[$index] = $field->name;
            }
        }
        return $encrypted;
    }

    //endregion
    //region Deprecations

    /**
     * @deprecated Use Table::exists() instead
     * @see        Table::exists()
     * @noinspection PhpDeprecationInspection deliberate delegation, hasTable() keeps the isPrefixed flag working
     */
    #[Deprecated(reason: 'use Table::exists() instead')]
    public static function tableExists(string $table, bool $isPrefixed = false): bool
    {
        self::logDeprecation("DB::tableExists() is deprecated, use Table::exists() instead");
        return self::connection()->hasTable($table, $isPrefixed);
    }

    /**
     * @deprecated Use Table::exists() instead
     * @see        Table::exists()
     * @noinspection PhpDeprecationInspection deliberate delegation, Connection::hasTable() keeps the isPrefixed flag working
     */
    #[Deprecated(reason: 'use Table::exists() instead')]
    public static function hasTable(string $table, bool $isPrefixed = false): bool
    {
        self::logDeprecation("DB::hasTable() is deprecated, use Table::exists() instead");
        return self::connection()->hasTable($table, $isPrefixed);
    }

    /**
     * @deprecated Use Table::names() or Table::namesFull() instead
     * @see        Table::names()
     * @see        Table::namesFull()
     */
    #[Deprecated(reason: 'use Table::names() or Table::namesFull() instead')]
    public static function getTableNames(bool $withPrefix = false): array
    {
        self::logDeprecation("DB::getTableNames() is deprecated, use Table::names() or Table::namesFull() instead");
        return $withPrefix ? Table::namesFull() : Table::names();
    }

    /**
     * @deprecated Use Table::columnDefinitions() instead; note it throws for unknown tables
     *             and invalid names where this returns []
     * @see        TableInfo::columnDefinitions()
     * @noinspection PhpDeprecationInspection deliberate delegation, Connection::getColumnDefinitions() keeps the []-on-error contract
     */
    #[Deprecated(reason: 'use Table::columnDefinitions() instead')]
    public static function getColumnDefinitions(string $baseTable): array
    {
        self::logDeprecation("DB::getColumnDefinitions() is deprecated, use Table::columnDefinitions() instead");
        return self::connection()->getColumnDefinitions($baseTable);
    }

    /**
     * @deprecated Use DB::selectOne() instead
     * @see        DB::selectOne()
     */
    #[Deprecated(replacement: 'DB::selectOne(%parametersList%)')]
    public static function get(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayBase
    {
        self::logDeprecation("DB::get() is deprecated, use DB::selectOne() instead");
        return self::connection()->selectOne($baseTable, $whereEtc, ...$params);
    }

    /**
     * Handle legacy static method calls.
     * @noinspection SpellCheckingInspection for lowercase method names
     */
    public static function __callStatic(string $name, array $args): mixed
    {
        [$replacement, $result] = match (strtolower($name)) {
            'like', 'escapelikewildcards' => throw new InvalidArgumentException("DB::$name() has been removed. Use DB::escape(\$value, true) or DB::likeContains(\$value) instead"),
            'identifier'                  => throw new InvalidArgumentException("DB::identifier() has been removed for security. Use backtick placeholders instead: `?` or `:name`"),
            'gettableprefix'              => ["DB::\$tablePrefix", self::$tablePrefix],
            'israwsql'                    => ["\$value instanceof RawSql", ($args[0] ?? null) instanceof RawSql],
            'raw'                         => ["DB::rawSql()", self::rawSql(...$args)],
            'datetime'                    => ["date('Y-m-d H:i:s', \$time)", date('Y-m-d H:i:s', ($args[0] ?? time()))],
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
