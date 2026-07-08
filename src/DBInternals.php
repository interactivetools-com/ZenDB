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
     */
    public static function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        return self::connection()->escape($input, $escapeLikeWildcards);
    }

    /**
     * Wrapper for {@see Connection::escapef()}
     */
    public static function escapef(string $format, mixed ...$values): string
    {
        return self::connection()->escapef($format, ...$values);
    }

    /**
     * Wrapper for {@see Connection::escapeCSV()}
     */
    public static function escapeCSV(array $values): RawSql
    {
        return self::connection()->escapeCSV($values);
    }

    /**
     * @see Connection::decryptRows()
     */
    public static function decryptRows(array &$rows, array $fetchFields): void
    {
        self::connection()->decryptRows($rows, $fetchFields);
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
