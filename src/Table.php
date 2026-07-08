<?php
declare(strict_types=1);

namespace Itools\ZenDB;

/**
 * EXPERIMENTAL: New API, still settling - method names and return values may change between
 * releases.
 *
 * Static front door to {@see TableInfo}, which reads the MySQL-level facts about a table:
 * whether it exists, its columns, its CREATE TABLE statement, its primary key, its indexes,
 * and its FOREIGN KEY constraints. Every method here runs on the default connection; for any
 * other connection call the same methods on its own instance:
 *
 *     Table::exists('users');                                        // default connection
 *     DB::clone(['tablePrefix' => 'cms_'])->table->exists('pages');  // clone with its own prefix
 */
class Table
{
    //region Tables

    /** Wrapper for {@see TableInfo::exists()} */
    public static function exists(string $baseTable): bool
    {
        return DB::connection()->table->exists($baseTable);
    }

    /** Wrapper for {@see TableInfo::existsFull()} */
    public static function existsFull(string $fullTable): bool
    {
        return DB::connection()->table->existsFull($fullTable);
    }

    /** Wrapper for {@see TableInfo::names()} */
    public static function names(): array
    {
        return DB::connection()->table->names();
    }

    /** Wrapper for {@see TableInfo::namesFull()} */
    public static function namesFull(): array
    {
        return DB::connection()->table->namesFull();
    }

    //endregion
    //region Columns

    /** Wrapper for {@see TableInfo::hasColumn()} */
    public static function hasColumn(string $baseTable, string $columnName): bool
    {
        return DB::connection()->table->hasColumn($baseTable, $columnName);
    }

    /** Wrapper for {@see TableInfo::columns()} */
    public static function columns(string $baseTable): array
    {
        return DB::connection()->table->columns($baseTable);
    }

    /** Wrapper for {@see TableInfo::columnNames()} */
    public static function columnNames(string $baseTable): array
    {
        return DB::connection()->table->columnNames($baseTable);
    }

    /** Wrapper for {@see TableInfo::columnDefinitions()} */
    public static function columnDefinitions(string $baseTable): array
    {
        return DB::connection()->table->columnDefinitions($baseTable);
    }

    /**
     * Extract a column's default value from its columnDefinitions() SQL, as a plain value.
     *
     *     Table::defaultFromDefinition("varchar(255) NOT NULL DEFAULT 'draft'");           // 'draft'
     *     Table::defaultFromDefinition("varchar(50) NOT NULL DEFAULT 'O''Brien'");         // "O'Brien"
     *     Table::defaultFromDefinition("int NOT NULL DEFAULT 0");                          // '0'
     *     Table::defaultFromDefinition("timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");   // 'CURRENT_TIMESTAMP'
     *     Table::defaultFromDefinition("datetime DEFAULT NULL");                           // null
     *     Table::defaultFromDefinition("mediumtext NOT NULL");                             // null
     *
     * This is the one cross-server way to read a default: information_schema's COLUMN_DEFAULT reports
     * in incompatible forms (MariaDB returns DDL text, MySQL raw values), while SHOW CREATE TABLE is
     * identical in meaning everywhere. Quoted literals are unquoted and unescaped; expression defaults
     * (current_timestamp(), MySQL 8's parenthesized expressions) return as their SQL text. Returns null
     * for both DEFAULT NULL and no DEFAULT clause: MySQL treats those the same on nullable columns.
     *
     * Lives here rather than on TableInfo because it only parses the string you pass it:
     * no connection involved.
     *
     * Generated columns return null: they can't have a DEFAULT clause, so any DEFAULT text in
     * their definition is inside the expression. DEFAULT only counts as the keyword outside
     * quotes; comment text, enum values, and defaults can all contain it as plain text.
     *
     * @param string $definitionSql One column's definition SQL, as returned by columnDefinitions()
     * @return string|null The default value, or null when the column has none
     */
    public static function defaultFromDefinition(string $definitionSql): ?string
    {
        $quotedRx = '\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'';

        // scan left to right, skipping quoted literals (enum values, comment text), so only the
        // structural keywords steer the parse
        $valueOffset = null;
        preg_match_all("~$quotedRx|\\bGENERATED ALWAYS AS\\b|\\bDEFAULT ~", $definitionSql, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$token, $offset]) {
            if ($token[0] === "'") { // quoted literal: text, not structure
                continue;
            }
            if ($token === 'GENERATED ALWAYS AS') { // generated columns can't have a DEFAULT clause
                return null;
            }
            $valueOffset = $offset + strlen($token);
            break;
        }
        if ($valueOffset === null) {
            return null;
        }

        // the value is a quoted literal ('' or backslash escaping), a parenthesized expression
        // (parens balanced, quote-aware so a ')' inside a string literal doesn't end it), or a bare
        // token (0, NULL, current_timestamp())
        if (!preg_match("~\\G($quotedRx|(\\((?:[^()']++|$quotedRx|(?2))*+\\))|\\S+)~", $definitionSql, $match, 0, $valueOffset)) {
            return null; // nothing after DEFAULT: not a shape any server prints
        }
        $default = $match[1];

        if ($default === 'NULL') {
            return null;
        }
        if ($default[0] === "'") { // unquote and unescape the SQL string literal
            return strtr(substr($default, 1, -1), [
                "''"   => "'",
                "\\\\" => "\\",
                "\\'"  => "'",
                '\\"'  => '"',
                '\\n'  => "\n",
                '\\r'  => "\r",
                '\\t'  => "\t",
                '\\0'  => "\0",
                '\\Z'  => "\x1a",
            ]);
        }
        return $default;
    }

    //endregion
    //region Create Table

    /** Wrapper for {@see TableInfo::showCreateTable()} */
    public static function showCreateTable(string $baseTable): string
    {
        return DB::connection()->table->showCreateTable($baseTable);
    }

    /**
     * Wrapper for {@see TableInfo::normalizeCreateTable()}. Like defaultFromDefinition(),
     * it only transforms the string you pass it: no connection involved.
     */
    public static function normalizeCreateTable(string $createTableSql): string
    {
        return TableInfo::normalizeCreateTable($createTableSql);
    }

    /**
     * Wrapper for {@see TableInfo::maskStringLiterals()}, for callers writing their own
     * transforms over CREATE TABLE text. Pure string transform: no connection involved.
     */
    public static function maskStringLiterals(string $sql): array
    {
        return TableInfo::maskStringLiterals($sql);
    }

    //endregion
    //region Indexes

    /** Wrapper for {@see TableInfo::primaryKey()} */
    public static function primaryKey(string $baseTable): string
    {
        return DB::connection()->table->primaryKey($baseTable);
    }

    /** Wrapper for {@see TableInfo::indexes()} */
    public static function indexes(string $baseTable, ?string $columnName = null): array
    {
        return DB::connection()->table->indexes($baseTable, $columnName);
    }

    //endregion
    //region Foreign Keys

    /** Wrapper for {@see TableInfo::foreignKeys()} */
    public static function foreignKeys(string $baseTable, ?string $columnName = null): array
    {
        return DB::connection()->table->foreignKeys($baseTable, $columnName);
    }

    /** Wrapper for {@see TableInfo::foreignKeysReferencing()} */
    public static function foreignKeysReferencing(string $baseTable): array
    {
        return DB::connection()->table->foreignKeysReferencing($baseTable);
    }

    //endregion
}
