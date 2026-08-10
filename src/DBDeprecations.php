<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayBase;
use JetBrains\PhpStorm\Deprecated;

/**
 * Old and retired DB method names, phased out in stages.
 *
 * Each deprecation sits at one stage of a five-stage ladder and moves down it in
 * later releases, per method, weighed by real-world usage. The stage names say
 * what a caller experiences:
 *
 *     Silent  - works; PHPStorm strikethrough and one-click rename via #[Deprecated],
 *               static analyzers via @deprecated, no runtime signal
 *     Logged  - works; logs E_USER_DEPRECATED with the caller's file:line
 *               (only error handlers see it, PHP's default display is suppressed)
 *     Visible - works; prints a "Deprecated:" notice to output, plus the log entry
 *     Fatal   - stops; __callStatic() throws naming the replacement
 *     Removed - stops; ordinary unknown-method error
 *
 * Silent, Logged, and Visible methods are real declared methods in their stage's
 * region below: IDE-visible, type-checked, called directly with no __callStatic()
 * overhead. Fatal and Removed methods no longer exist as methods, so calls land
 * in __callStatic(), which throws for both. Removed needs no code of its own: a
 * removed method is an undefined name like any other. Moving a method down the
 * ladder is a cut-paste to the next region plus updating its runtime line
 * (Silent: none, Logged: logDeprecation(), Fatal: a match arm in __callStatic).
 *
 * Deprecations elsewhere: Connection's deprecated instance methods are in
 * ConnectionDeprecations (same ladder), and deprecated argument shapes (e.g.
 * positional values in an array) are logged inline at their parse sites in
 * ConnectionInternals.
 */
trait DBDeprecations
{

    //region Silent Aliases

    /**
     * @deprecated Use Table::exists() instead; note it throws for invalid names where this returns false
     * @see        Table::exists()
     * @noinspection PhpDeprecationInspection deliberate delegation, Connection::hasTable() keeps the isPrefixed flag working
     */
    #[Deprecated(reason: 'use Table::exists() instead')]
    public static function hasTable(string $table, bool $isPrefixed = false): bool
    {
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
        return self::connection()->getColumnDefinitions($baseTable);
    }

    //endregion
    //region Logged Aliases

    /**
     * @deprecated Use Table::exists() instead; note it throws for invalid names where this returns false
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
     * @deprecated Read DB::$tablePrefix directly instead
     */
    #[Deprecated(replacement: 'DB::$tablePrefix')]
    public static function getTablePrefix(): string
    {
        self::logDeprecation("DB::getTablePrefix() is deprecated, use DB::\$tablePrefix instead");
        return DB::$tablePrefix;
    }

    /**
     * @deprecated Use `$value instanceof RawSql` instead
     */
    #[Deprecated(reason: 'use $value instanceof RawSql instead')]
    public static function isRawSql(mixed $value): bool
    {
        self::logDeprecation("DB::isRawSql() is deprecated, use \$value instanceof RawSql instead");
        return $value instanceof RawSql;
    }

    /**
     * @deprecated Use DB::rawSql() instead
     * @see        DB::rawSql()
     */
    #[Deprecated(replacement: 'DB::rawSql(%parametersList%)')]
    public static function raw(string|int|float|null $value): RawSql
    {
        self::logDeprecation("DB::raw() is deprecated, use DB::rawSql() instead");
        return self::rawSql($value);
    }

    /**
     * @deprecated Use date('Y-m-d H:i:s', $time) or date(DB::DATETIME, $time) instead
     */
    #[Deprecated(replacement: 'date(DB::DATETIME, %parametersList%)')]
    public static function datetime(?int $timestamp = null): string
    {
        self::logDeprecation("DB::datetime() is deprecated, use date('Y-m-d H:i:s', \$time) instead");
        return date('Y-m-d H:i:s', $timestamp ?? time());
    }

    //endregion
    //region Visible Notices

    //endregion
    //region Fatal & Undefined

    /**
     * Fatal-stage deprecations: the method no longer exists, so the call lands
     * here and throws with the replacement named. Names that were never methods
     * get an unknown-method error.
     *
     * @noinspection SpellCheckingInspection lowercase method names in match arms
     */
    public static function __callStatic(string $name, array $args): mixed
    {
        throw match (strtolower($name)) {
            'like', 'escapelikewildcards' => new InvalidArgumentException("DB::$name() has been removed. Use DB::escape(\$value, true) or DB::likeContains(\$value) instead"),
            'identifier'                  => new InvalidArgumentException("DB::identifier() has been removed for security. Use backtick placeholders instead: `?` or `:name`"),
            default                       => new InvalidArgumentException("Unknown static method: $name"),
        };
    }

    //endregion
    //region Support

    /**
     * Log a deprecation warning with caller location.
     *
     * @param string $message Deprecation message (caller file:line will be appended)
     * @internal
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
