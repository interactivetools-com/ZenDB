<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayHtml;
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
    public static function escapeCSV(array $values): RawSql
    {
        return self::db()->escapeCSV($values);
    }

    /**
     * @see Connection::decryptRows()
     */
    public static function decryptRows(array &$rows, array $fetchFields): void
    {
        self::db()->decryptRows($rows, $fetchFields);
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
     * @return array<string> Column names of detected encrypted columns
     */
    public static function getEncryptedColumns(array $fetchFields): array
    {
        $encrypted = [];
        foreach ($fetchFields as $field) {
            $isMediumBlob = $field->type === MYSQLI_TYPE_BLOB && $field->charsetnr === 63 && $field->length === 16_777_215;
            if ($isMediumBlob) {
                $encrypted[] = $field->name;
            }
        }
        return $encrypted;
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
