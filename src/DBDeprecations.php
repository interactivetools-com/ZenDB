<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayBase;
use JetBrains\PhpStorm\Deprecated;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function basename, date, debug_backtrace, dirname, strtolower, time, trigger_error;
use const DEBUG_BACKTRACE_IGNORE_ARGS, E_USER_DEPRECATED, E_USER_WARNING;

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
 * ConnectionInternals. If one of those inline chunks grows past a few lines,
 * extract the body to a private method named deprecated*() in the region
 * matching its ladder stage - the live method keeps its signature and a
 * one-line dispatch (see SmartArray's deprecatedWhereArraySyntax()).
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
            // SECURITY: variable-method calls make $name caller data, encode before it can reach page output (match arm, so no room for the $h variable)
            default                       => new InvalidArgumentException("Unknown static method: " . self::h($name)),
        };
    }

    //endregion
    //region Support

    /**
     * Log a deprecation warning with caller location.
     *
     * The @ suppressor mutes PHP's default display and logging. Only a custom error
     * handler (set_error_handler) receives these notices; without one, nothing is
     * shown or logged. This is deliberate: deprecation notices are meant for error
     * handlers that collect them, never for page output.
     *
     * @param string $message Deprecation message (caller file:line will be appended)
     * @internal
     */
    public static function logDeprecation(string $message): void
    {
        [$file, $line] = self::externalCaller();
        @trigger_error("$message in $file:$line", E_USER_DEPRECATED);
    }

    /**
     * Log a warning with caller location.
     *
     * Same delivery as logDeprecation() but at E_USER_WARNING, for deprecated
     * forms that usually signal a latent bug (e.g. a query value used as a
     * record number without a cast). Error handlers that filter out deprecation
     * notices still receive warnings, so these always reach the log.
     *
     * @param string $message Warning message (caller file:line will be appended)
     * @internal
     */
    public static function logWarning(string $message): void
    {
        [$file, $line] = self::externalCaller();
        @trigger_error("$message in $file:$line", E_USER_WARNING);
    }

    /**
     * Returns [file, line] of the first caller outside ZenDB's src directory.
     */
    private static function externalCaller(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $caller) {
            if (!empty($caller['file']) && dirname($caller['file']) !== __DIR__) {
                return [basename($caller['file']), $caller['line'] ?? "unknown"];
            }
        }
        return ["unknown", "unknown"];
    }

    //endregion

}
