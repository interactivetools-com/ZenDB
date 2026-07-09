<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use RuntimeException;
use mysqli_sql_exception;

/**
 * EXPERIMENTAL: New API, still settling - method names and return values may change between
 * releases.
 *
 * Reads the MySQL-level facts about a connection's tables: whether a table exists, its columns,
 * its CREATE TABLE statement, its primary key, its indexes, and its FOREIGN KEY constraints.
 * names() and namesFull() list the tables themselves.
 *
 * Every connection has one, bound to its table prefix. Set at connect, null when disconnected.
 * The Table class is the static front door for the default connection; reach an instance
 * directly for any other connection:
 *
 *     Table::exists('users');                                        // default connection
 *     DB::clone(['tablePrefix' => 'cms_'])->table->exists('pages');  // clone with its own prefix
 *
 * exists() reports whether a table is there, names()/namesFull() list what's there, and
 * the per-table methods expect an existing table: columnDefinitions(), primaryKey(), and
 * indexes() throw MySQL's "table doesn't exist" error for unknown tables, while columns(),
 * hasColumn(), and the FOREIGN KEY methods query information_schema and return [] or false
 * for them.
 *
 * Queries run on raw mysqli, not the query pipeline: results are plain arrays, so behavior
 * never varies with connection settings like `useSmartStrings`, and the pipeline can call
 * these methods without recursing into itself.
 */
class TableInfo
{
    /**
     * Collations that are some server version's built-in default, stripped from normalized output
     * (columnDefinitions() and normalizeCreateTable()): servers disagree on whether the default is
     * printed at all and on which collation it is, so on replay each server applies its own.
     * Deliberate collations like utf8mb4_bin aren't in the list and survive. utf8mb3_* spellings
     * never appear here: rewriteUtf8mb3ToUtf8() runs before the strip.
     */
    private const DEFAULT_COLLATIONS = [
        'utf8_general_ci',       // legacy utf8 (3-byte) default on every server except MariaDB 11.8+
        'utf8_uca1400_ai_ci',    // legacy utf8 default on MariaDB 11.8+
        'utf8mb4_general_ci',    // utf8mb4 default: MySQL/Percona 5.7, MariaDB thru 10.11
        'utf8mb4_0900_ai_ci',    // utf8mb4 default: MySQL/Percona 8.0+ (unknown to MariaDB before 11.4)
        'utf8mb4_uca1400_ai_ci', // utf8mb4 default: MariaDB 11.4+ (unknown to MySQL, and MariaDB before 10.10)
        'utf8mb4_unicode_ci',    // no server's default; the usual explicit pin, chosen because every server has it
        'latin1_swedish_ci',     // latin1 default on every server
        'ascii_general_ci',      // ascii default on every server
    ];

    //region Tables

    /**
     * Check whether a table exists. Any name is a fair question, including one MySQL wouldn't
     * accept as an identifier: "no such table" answers false. Failures that aren't about the
     * table (dead connection, missing privilege) throw instead of passing as false.
     *
     *     Table::exists('articles');       // true
     *     Table::exists('no_such_table');  // false
     *
     * The check probes the table with a zero-row SELECT instead of reading information_schema,
     * so views and this connection's temporary tables count as existing (information_schema
     * can't see temporary tables). Name matching is MySQL's own: case-insensitive on Windows
     * and macOS servers, case-sensitive on most Linux servers (lower_case_table_names).
     *
     * For a name that already includes the prefix use existsFull().
     *
     * @param string $baseTable Table name without prefix
     * @return bool True when a table, view, or temporary table by that name exists
     */
    public function exists(string $baseTable): bool
    {
        return $this->existsFull($this->db->tablePrefix . $baseTable);
    }

    /**
     * Check whether a table exists under its exact MySQL name - nothing is prepended.
     * Same check as exists(), for names that already include the prefix, e.g. the
     * refTable values foreignKeys() returns.
     *
     *     Table::existsFull('cms_articles');  // true
     *     Table::existsFull('articles');      // false (the real name is cms_articles)
     *
     * @param string $fullTable Table name exactly as MySQL knows it
     * @return bool True when a table, view, or temporary table by that name exists
     */
    public function existsFull(string $fullTable): bool
    {
        try {
            DB::assertIdentifier($fullTable, 'table name');
            $escapedFullTable = $this->mysqli->real_escape_string($fullTable);
            $this->mysqli->query("SELECT 1 FROM `$escapedFullTable` LIMIT 0")->free();
            return true;
        } catch (InvalidArgumentException) {
            return false; // a name MySQL wouldn't accept can't exist
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1146) { // ER_NO_SUCH_TABLE
                return false;
            }
            throw $e; // anything else (dead connection, missing privilege) is an error, not a "no"
        }
    }

    /**
     * Get every table's base name (prefix stripped): content tables first, then system
     * tables (leading underscore), each group alphabetical.
     *
     *     Table::names(); // ['accounts', 'articles', ..., '_cron_log', '_error_log', ...]
     *
     * Same list as namesFull(), just without the prefix.
     *
     * @return list<string> Base table names, prefix stripped
     */
    public function names(): array
    {
        $prefixLength = strlen($this->db->tablePrefix);
        return array_map(fn(string $name) => substr($name, $prefixLength), $this->namesFull());
    }

    /**
     * Get every table's full MySQL name (prefix included): content tables first, then
     * system tables (underscore after the prefix), each group alphabetical.
     *
     *     Table::namesFull(); // ['cms_accounts', 'cms_articles', ..., 'cms__cron_log', ...]
     *
     * Only real tables whose names start with `tablePrefix` are listed; views and temporary
     * tables are not (exists() counts those). The list comes from information_schema rather
     * than SHOW TABLES, whose LIKE pattern MariaDB can ignore while temporary tables exist
     * (MDEV-32973). The TABLE_TYPE filter also keeps out temporary tables on MariaDB 11.4+,
     * the first server to list them in information_schema.
     *
     * @return list<string> Full table names, prefix included
     */
    public function namesFull(): array
    {
        $prefix        = $this->db->tablePrefix;
        $prefixLength  = strlen($prefix);
        $escapedPrefix = $this->mysqli->real_escape_string($prefix);
        $result        = $this->mysqli->query(
            "SELECT TABLE_NAME
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, $prefixLength) = '$escapedPrefix' AND TABLE_TYPE = 'BASE TABLE'",
        );
        $names         = array_column($result->fetch_all(), 0);
        $result->free();

        // content tables first, system tables (underscore after the prefix) last, alphabetical within each group
        $isSystemTable = fn(string $name) => ($name[$prefixLength] ?? '') === '_';
        usort($names, fn($a, $b) => $isSystemTable($a) <=> $isSystemTable($b) ?: $a <=> $b);

        return $names;
    }

    //endregion
    //region Columns

    /**
     * Check whether a table has a column. Case-insensitive, like MySQL column names.
     * Returns false for an unknown table: no table, no column.
     *
     *     Table::hasColumn('articles', 'title');           // true
     *     Table::hasColumn('articles', 'TITLE');           // true (case-insensitive)
     *     Table::hasColumn('articles', 'no_such_column');  // false
     *
     * @param string $baseTable  Table name without prefix
     * @param string $columnName Column to look for
     * @return bool True when the column exists on the table
     */
    public function hasColumn(string $baseTable, string $columnName): bool
    {
        $namesLower = array_map('strtolower', $this->columnNames($baseTable));
        return in_array(strtolower($columnName), $namesLower, true);
    }

    /**
     * Get every column on a table, keyed by column name, in table order.
     *
     *     Table::columns('articles');
     *     // [
     *     //     'num'   => ['name' => 'num',   'type' => 'int unsigned', 'isNullable' => false, 'extra' => 'auto_increment', 'charset' => null],
     *     //     'title' => ['name' => 'title', 'type' => 'varchar(255)', 'isNullable' => true,  'extra' => '',               'charset' => 'utf8mb4'],
     *     // ]
     *
     * For just the names use columnNames(); for a name => type map use array_column($columns, 'type', 'name').
     *
     * Field notes:
     *   - type: the full column type, e.g. 'varchar(255)' or 'decimal(10,2) unsigned'. Deprecated int display
     *     widths are cropped ('int(11)' → 'int') so type reads the same on every server; tinyint(1) (boolean
     *     marker) and zerofill columns (width sets the padding) keep theirs, matching MySQL 8's output
     *   - extra: e.g. 'auto_increment', 'on update CURRENT_TIMESTAMP', 'VIRTUAL GENERATED', 'STORED GENERATED'
     *   - charset: null for non-text columns
     *   - default values are deliberately not returned: servers report information_schema's COLUMN_DEFAULT
     *     in incompatible forms (MariaDB returns DDL text, MySQL raw values). Use columnDefinitions() with
     *     defaultFromDefinition() instead
     *
     * @param string $baseTable Table name without prefix
     * @return array<string, array{name: string, type: string, isNullable: bool, extra: string, charset: ?string}> columnName => column details
     */
    public function columns(string $baseTable): array
    {
        $escapedFullTable = $this->mysqli->real_escape_string($this->db->tablePrefix . $baseTable);

        // SELECT * because not every server has every field (e.g. no GENERATION_EXPRESSION before MariaDB 10.2)
        $result = $this->mysqli->query(
            "SELECT *
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$escapedFullTable'
              ORDER BY ORDINAL_POSITION",
        );
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['COLUMN_NAME']] = [
                'name'       => $row['COLUMN_NAME'],
                'type'       => self::cropIntDisplayWidth($row['COLUMN_TYPE']),
                'isNullable' => $row['IS_NULLABLE'] === 'YES',
                'extra'      => $row['EXTRA'],
                'charset'    => $row['CHARACTER_SET_NAME'],
                // no 'default' on purpose: COLUMN_DEFAULT is inconsistent across servers, use
                // columnDefinitions() with defaultFromDefinition() instead
                // no callers need these yet; uncomment when one does
                //'key'                  => $row['COLUMN_KEY'],                             // 'PRI', 'UNI', 'MUL', or ''
                //'collation'            => $row['COLLATION_NAME'],
                //'comment'              => $row['COLUMN_COMMENT'],
                //'generationExpression' => (string) ($row['GENERATION_EXPRESSION'] ?? ''), // informational only, not valid DDL
            ];
        }

        return $columns;
    }

    /**
     * Get a table's column names, in table order.
     *
     *     Table::columnNames('articles'); // ['num', 'createdDate', 'title', ...]
     *
     * To check for a single column use hasColumn().
     *
     * @param string $baseTable Table name without prefix
     * @return list<string> Column names
     */
    public function columnNames(string $baseTable): array
    {
        return array_keys($this->columns($baseTable));
    }

    /**
     * Get every column's definition SQL, keyed by column name, in table order.
     *
     *     Table::columnDefinitions('articles');
     *     // [
     *     //     'num'       => 'int NOT NULL AUTO_INCREMENT',
     *     //     'title'     => "varchar(255) NOT NULL DEFAULT ''",
     *     //     'full_name' => "varchar(101) GENERATED ALWAYS AS (concat(`last_name`,', ',`first_name`)) STORED",
     *     // ]
     *
     * Definitions are parsed from SHOW CREATE TABLE, the only source that reports a column complete and
     * in executable form (a generated column's expression, for example, appears nowhere else as valid SQL).
     * Every string works in ALTER TABLE ... MODIFY COLUMN, and identical schemas return identical strings
     * on MySQL and MariaDB thanks to these normalizations (MODIFY still accepts every result):
     *   - CHARACTER SET / COLLATE clauses matching the table's own defaults are removed as noise: a column
     *     without them inherits those same defaults back. A column with a different charset keeps it
     *   - the utf8mb3 charset rename is spelled the old way ('CHARACTER SET utf8mb3' → 'CHARACTER SET utf8',
     *     'utf8mb3_*' collations → 'utf8_*'): same charset either way, and utf8 is the only spelling every
     *     supported server accepts in DDL (MySQL 5.7 rejects utf8mb3)
     *   - collations that are some server version's built-in default (utf8mb4_general_ci, utf8mb4_0900_ai_ci,
     *     utf8mb4_uca1400_ai_ci, latin1_swedish_ci, ...) are stripped: servers disagree on whether the
     *     default is printed at all and on which collation it is, so on replay each server applies its own.
     *     Deliberate collations like utf8mb4_bin survive. For the server's verbatim DDL use showCreateTable()
     *   - deprecated display widths are cropped ('int(11)' → 'int', 'year(4)' → 'year'), same as columns();
     *     plain signed tinyint(1) (boolean marker) and zerofill columns (width sets the padding) keep theirs
     *   - MariaDB's default spelling current_timestamp() is normalized to MySQL's CURRENT_TIMESTAMP
     *   - MySQL's redundant outer parens on generated-column expressions are stripped
     *     ('AS ((`num` * 2))' → 'AS (`num` * 2)'), matching MariaDB; both accept the single pair
     *   - MariaDB's bare expression defaults gain the parens MySQL's DDL grammar requires
     *     ('DEFAULT uuid()' → 'DEFAULT (uuid())'); CURRENT_TIMESTAMP stays bare per the rule above
     *   - MariaDB's bare numeric defaults are quoted the way MySQL prints them ('DEFAULT 0' → "DEFAULT '0'");
     *     both servers accept either form in DDL, the quoting is spelling, not type
     *
     * COMMENT text is never modified: it is split off before normalizing and reattached after.
     * See tools/db-behavior-report.md (2026-07).
     *
     * Known limit: a column COMMENT containing a newline breaks the line-based parse for that column.
     *
     * @param string $baseTable Table name without prefix
     * @return array<string, string> columnName => definition SQL
     */
    public function columnDefinitions(string $baseTable): array
    {
        return self::parseCreateTableColumns($this->showCreateTable($baseTable));
    }

    /**
     * Extract column definitions from SHOW CREATE TABLE output.
     * Separate from columnDefinitions() so tests can feed it fixture DDL without a database.
     *
     * @param string $createTableSql Full SHOW CREATE TABLE statement
     * @return array<string, string> columnName => definition SQL
     */
    private static function parseCreateTableColumns(string $createTableSql): array
    {
        $createTableSql      = self::stripRedundantCharsetCollate($createTableSql);
        $defaultCollationsRx = '(?:' . implode('|', self::DEFAULT_COLLATIONS) . ')';

        // column lines start with a backtick-quoted name; PRIMARY KEY, KEY, and CONSTRAINT lines don't
        $definitions = [];
        foreach (explode("\n", $createTableSql) as $line) {
            if (!preg_match('/^\s*`([^`]+)` (.*?),?$/', $line, $match)) {
                continue;
            }
            [, $columnName, $definition] = $match;
            [$definition, $literals]     = self::maskStringLiterals($definition);

            $definition = self::rewriteUtf8mb3ToUtf8($definition);

            // server-default collations are noise: servers disagree on whether they're printed at
            // all and on which collation is the default, so on replay each server applies its own
            $definition = preg_replace("/ COLLATE $defaultCollationsRx\\b/", '', $definition);

            $definition = self::cropIntDisplayWidth($definition);

            // MariaDB 10.2+ spells defaults current_timestamp(); MySQL spells them CURRENT_TIMESTAMP
            $definition = preg_replace('/\b(DEFAULT|ON UPDATE) current_timestamp(?:\(\))?(\(\d+\))?/i', '$1 CURRENT_TIMESTAMP$2', $definition);

            // vendors disagree on expression parens both ways: MySQL wraps generated-column
            // expressions in a redundant extra pair, and MariaDB prints expression defaults
            // without the parens MySQL's DDL grammar requires
            $definition = self::stripRedundantGeneratedParens($definition);
            $definition = self::parenthesizeExpressionDefault($definition);

            // MariaDB prints numeric-typed defaults bare (DEFAULT 0); MySQL prints them quoted (DEFAULT '0').
            // Quote bare numeric literals to match MySQL. Numbers only: other bare tokens are keywords (NULL)
            // or expressions (CURRENT_TIMESTAMP, uuid()) and must stay bare to keep their meaning, and string
            // defaults are quoted, so they're masked above and can never match
            $definition = preg_replace("/\bDEFAULT (-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=,| |$)/", "DEFAULT '$1'", $definition);

            $definitions[$columnName] = strtr($definition, $literals);
        }

        return $definitions;
    }

    //endregion
    //region Create Table

    /**
     * Get a table's CREATE TABLE statement, verbatim as SHOW CREATE TABLE returns it.
     *
     *     Table::showCreateTable('articles');
     *     // CREATE TABLE `cms_articles` (
     *     //   `num` int NOT NULL AUTO_INCREMENT,
     *     //   ...
     *     // ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
     *
     * The output is this server's own formatting, so the same schema prints differently
     * across servers (int display widths, default collations, default spellings). For text
     * that reads the same everywhere and replays cleanly on any supported server, pass the
     * result through normalizeCreateTable().
     *
     * Expects an existing table: unknown tables throw MySQL's "table doesn't exist" error,
     * like columnDefinitions().
     *
     * @param string $baseTable Table name without prefix
     * @return string The CREATE TABLE statement
     */
    public function showCreateTable(string $baseTable): string
    {
        $fullTable = $this->db->tablePrefix . $baseTable;
        DB::assertIdentifier($fullTable, 'table name');
        $escapedFullTable = $this->mysqli->real_escape_string($fullTable);

        $result = $this->mysqli->query("SHOW CREATE TABLE `$escapedFullTable`");
        $row    = $result->fetch_row();
        $result->free();

        return $row[1] ?? ''; // column 1: 'Create Table'
    }

    /**
     * Normalize a CREATE TABLE statement for cross-server portability. String in, string out:
     * no queries run, and text inside quotes (COMMENT, DEFAULT, enum values) is never modified.
     *
     *     $sql = Table::normalizeCreateTable(Table::showCreateTable('articles'));
     *
     * Servers print the same schema differently, and some print DDL other servers reject, so
     * statements saved for replay elsewhere (backups, schema exports) are normalized first:
     *   - deprecated int/year display widths are cropped ('int(11)' → 'int'), matching MySQL 8's
     *     own output; plain signed tinyint(1) (boolean marker) and zerofill columns (the width
     *     sets the padding) keep theirs
     *   - column-level CHARACTER SET / COLLATE clauses matching the table's own defaults are
     *     removed as noise: a column without them inherits those same defaults back
     *   - collations that are some server version's built-in default (utf8mb4_general_ci,
     *     utf8mb4_0900_ai_ci, utf8mb4_uca1400_ai_ci, ...) are removed from columns and table
     *     options, so each server applies its own default on replay and the statement never
     *     names a collation the target server doesn't have (MariaDB's uca1400 names don't
     *     exist on MySQL). Intentional collations like utf8mb4_bin are kept
     *   - the utf8mb3 charset rename is spelled the old way on columns and table options
     *     ('CHARACTER SET utf8mb3' / 'CHARSET=utf8mb3' → utf8, 'utf8mb3_*' collations →
     *     'utf8_*'): same charset either way, and MySQL 5.7 rejects the utf8mb3 spelling in DDL
     *   - MySQL's redundant outer parens on generated-column expressions are stripped
     *     ('AS ((`num` * 2))' → 'AS (`num` * 2)'): both vendors accept the single pair
     *   - bare expression defaults gain parens ('DEFAULT uuid()' → 'DEFAULT (uuid())'): MariaDB
     *     prints them bare but MySQL's DDL grammar rejects that form, so the bare spelling
     *     doesn't replay there; current_timestamp defaults keep their printed form
     *
     * Engine, charset, and everything else replay as-is: this removes server-version noise,
     * it doesn't upgrade schemas. See tools/db-behavior-report.md (2026-07).
     *
     * @param string $createTableSql Full CREATE TABLE statement, as returned by showCreateTable()
     * @return string The normalized statement
     */
    public static function normalizeCreateTable(string $createTableSql): string
    {
        // COLUMN CHARSET/COLLATE NOISE - drop clauses that just restate the table's own defaults
        $createTableSql = self::stripRedundantCharsetCollate($createTableSql);

        // SERVER-DEFAULT COLLATIONS - stripped everywhere below, so each server applies its own
        // default on replay and a statement never names a collation the target doesn't have
        $defaultCollationsRx = '(?:' . implode('|', self::DEFAULT_COLLATIONS) . ')';

        $lines = explode("\n", $createTableSql);
        foreach ($lines as &$line) {
            // TABLE OPTIONS LINE ") ENGINE=... CHARSET=... COLLATE=..." - rewrite the charset
            // spelling and drop a server-default COLLATE=, keep everything else
            if (str_starts_with($line, ')')) {
                $line = self::rewriteUtf8mb3ToUtf8($line);
                $line = preg_replace("/\s*COLLATE=$defaultCollationsRx\b/", '', $line);
                continue;
            }

            // COLUMN LINES "`name` ..." - KEY, CONSTRAINT, and CREATE lines pass through untouched
            if (!preg_match('/^(\s*`[^`]+` )(.*)$/', $line, $match)) {
                continue;
            }
            [, $namePart, $definition] = $match;
            [$definition, $literals]   = self::maskStringLiterals($definition); // COMMENT/DEFAULT/enum text stays byte-identical

            $definition = self::rewriteUtf8mb3ToUtf8($definition);
            $definition = preg_replace("/ COLLATE $defaultCollationsRx\\b/", '', $definition);
            $definition = self::cropIntDisplayWidth($definition);

            // vendors disagree on expression parens both ways: MySQL's redundant pair on generated
            // columns is noise, and MariaDB's bare expression defaults don't replay on MySQL
            $definition = self::stripRedundantGeneratedParens($definition);
            $definition = self::parenthesizeExpressionDefault($definition);

            $line = $namePart . strtr($definition, $literals);
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * Hide quoted text behind placeholders so normalization regexes can't touch it, then
     * restore it afterward with strtr():
     *
     *     [$sql, $literals] = self::maskStringLiterals("varchar(255) DEFAULT 'a, b' COMMENT 'not int(11)'");
     *     // $sql:      "varchar(255) DEFAULT \x000\x00 COMMENT \x001\x00"
     *     // $literals: ["\x000\x00" => "'a, b'", "\x001\x00" => "'not int(11)'"]
     *
     *     // ... run regexes on $sql; the int(11) in the COMMENT can't match ...
     *
     *     $sql = strtr($sql, $literals);  // puts the original quoted text back
     *
     * Each placeholder is a counter wrapped in NUL bytes: the counter keeps placeholders
     * unique, and NUL never appears in SHOW CREATE output or matches a word/digit pattern,
     * so no transform can touch the token. Quotes inside a literal are handled in both
     * forms: doubled ('') and backslash-escaped.
     *
     * @return array{string, array<string, string>} [masked SQL, placeholder => original literal]
     */
    public static function maskStringLiterals(string $sql): array
    {
        $literals = [];
        $masked   = preg_replace_callback("/'(?:[^'\\\\]++|\\\\.|'')*+'/", static function (array $match) use (&$literals): string {
            $placeholder            = "\x00" . count($literals) . "\x00"; // counter wrapped in NULs to ensure it's unique
            $literals[$placeholder] = $match[0];
            return $placeholder;
        }, $sql);
        return [$masked, $literals];
    }

    /**
     * Remove column-level CHARACTER SET / COLLATE clauses that are redundant: ones spelling
     * out exactly what the column would inherit from the table's own defaults anyway.
     *
     *     `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL   // before
     *     `body` mediumtext NOT NULL                                             // after: same column
     *     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin            // the defaults they restate
     *
     * A column with a DIFFERENT charset or collation keeps it, and the table-options line
     * itself is never modified.
     *
     * Details: the ") ENGINE=..." line is found by prefix, not position (partitioned tables
     * append PARTITION lines after it); CHARSET and COLLATE are handled separately because
     * COLLATE is often omitted; \w+ captures can't pick up regex syntax from a mangled line;
     * and the trailing \b stops a utf8 default from eating the front of a column's utf8mb4.
     *
     * @param string $createTableSql Full CREATE TABLE statement
     * @return string The statement with redundant column charset/collate clauses removed
     */
    private static function stripRedundantCharsetCollate(string $createTableSql): string
    {
        $lines = explode("\n", $createTableSql);

        $tableLine = implode('', preg_grep('/^\)/', $lines));
        preg_match('/\bCHARSET=(\w+)/', $tableLine, $charsetMatch);
        preg_match('/\bCOLLATE=(\w+)/', $tableLine, $collateMatch);

        $redundantRxs = [];
        if (isset($charsetMatch[1])) {
            $redundantRxs[] = "/ CHARACTER SET $charsetMatch[1]\\b/";
        }
        if (isset($collateMatch[1])) {
            $redundantRxs[] = "/ COLLATE $collateMatch[1]\\b/";
        }
        if (!$redundantRxs) {
            return $createTableSql;
        }

        // strip from column lines only (they start with a backtick-quoted name), with quoted
        // text masked so a COMMENT mentioning a charset can't match
        foreach ($lines as &$line) {
            if (!preg_match('/^(\s*`[^`]+` )(.*)$/', $line, $match)) {
                continue;
            }
            [$definition, $literals] = self::maskStringLiterals($match[2]);
            $definition              = preg_replace($redundantRxs, '', $definition);
            $line                    = $match[1] . strtr($definition, $literals);
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * Rewrite the utf8mb3 spelling to utf8 in masked DDL text: CHARACTER SET / CHARSET= clauses
     * and utf8mb3_* collation names. utf8mb4 can never match, and callers mask string literals
     * first so quoted text is untouched.
     *
     * At a glance (utf8 and utf8mb3 are the same charset, two spellings):
     *
     *   spelling   printed in SHOW CREATE by            accepted in DDL by
     *   utf8       MySQL/Percona 5.7, MariaDB <= 10.5   every supported server  <- we write this
     *   utf8mb3    MySQL/Percona 8.0+, MariaDB 10.6+    everything EXCEPT MySQL/Percona 5.7, MariaDB <= 10.5
     *   utf8mb4    (different charset - never touched by this rewrite)
     *
     * Nobody types utf8mb3 - newer servers print it for any column still on legacy 3-byte utf8.
     * Those columns come from pre-utf8mb4 installs and old backups, so without the rewrite the
     * same column reads differently per server and DDL from new servers fails to replay on old ones.
     *
     * TODO-MYSQL80: dropping MySQL 5.7 doesn't retire this - the renamed servers still print
     * utf8mb3 while older DDL says utf8, so one canonical spelling is still needed. Re-evaluate
     * at the bump anyway.
     *
     * @param string $maskedSql DDL text with string literals already masked
     * @return string The text with utf8mb3 spellings rewritten to utf8
     */
    private static function rewriteUtf8mb3ToUtf8(string $maskedSql): string
    {
        $maskedSql = preg_replace('/\b(CHARACTER SET |CHARSET=)utf8mb3\b/', '$1utf8', $maskedSql);
        return preg_replace('/\b(COLLATE[ =])utf8mb3_/', '$1utf8_', $maskedSql);
    }

    /**
     * Strip the redundant outer paren pair MySQL prints around generated-column expressions.
     * MySQL/Percona print 'GENERATED ALWAYS AS ((`num` * 2))' while MariaDB prints the
     * expression in single parens, and every supported server accepts the single-paren form
     * in DDL, so the extra layer is noise:
     *
     *     int GENERATED ALWAYS AS ((`num` * 2)) VIRTUAL  →  int GENERATED ALWAYS AS (`num` * 2) VIRTUAL
     *
     * Only a pair wrapping the ENTIRE expression is redundant: in AS ((`a` + 1) * (`b` + 2))
     * the leading paren closes mid-expression, so the strip walks parens instead of trusting
     * a regex. Callers mask string literals first, so parens in quoted text can't miscount.
     *
     * @param string $maskedDefinition Column definition with string literals already masked
     * @return string The definition with redundant expression parens removed
     */
    private static function stripRedundantGeneratedParens(string $maskedDefinition): string
    {
        $marker   = 'GENERATED ALWAYS AS (';
        $position = strpos($maskedDefinition, $marker);
        if ($position === false) {
            return $maskedDefinition;
        }

        $open  = $position + strlen($marker) - 1;
        $close = self::matchingParenPos($maskedDefinition, $open);
        if ($close === null) {
            return $maskedDefinition;
        }

        $expression = substr($maskedDefinition, $open + 1, $close - $open - 1);
        while (str_starts_with($expression, '(') && self::matchingParenPos($expression, 0) === strlen($expression) - 1) {
            $expression = substr($expression, 1, -1);
        }

        return substr($maskedDefinition, 0, $open + 1) . $expression . substr($maskedDefinition, $close);
    }

    /**
     * Wrap a bare expression default in the parens MySQL requires. MariaDB prints
     * 'DEFAULT uuid()' while MySQL 8.0+ prints 'DEFAULT (uuid())', and MySQL's DDL grammar
     * rejects the bare spelling, so parenthesized is the one form every server that supports
     * expression defaults replays (MySQL 5.7 supports none, whatever the spelling):
     *
     *     varchar(36) NOT NULL DEFAULT uuid()  →  varchar(36) NOT NULL DEFAULT (uuid())
     *
     * current_timestamp defaults never gain parens: every server prints and accepts them
     * bare, and they're the one function default MySQL 5.7 allows, where the parenthesized
     * form is a syntax error. Callers mask string literals first, so parens in quoted
     * arguments can't miscount.
     *
     * @param string $maskedDefinition Column definition with string literals already masked
     * @return string The definition with a bare expression default parenthesized
     */
    private static function parenthesizeExpressionDefault(string $maskedDefinition): string
    {
        if (!preg_match('/\bDEFAULT (?!CURRENT_TIMESTAMP\b)[a-z_]\w*\(/i', $maskedDefinition, $match, PREG_OFFSET_CAPTURE)) {
            return $maskedDefinition;
        }
        [$matchedText, $matchOffset] = $match[0]; // PREG_OFFSET_CAPTURE: [matched text, byte offset]
        $matchOffset                 = (int)$matchOffset; // already an int; PhpStorm's stubs type all match slots as string

        $open  = $matchOffset + strlen($matchedText) - 1;
        $close = self::matchingParenPos($maskedDefinition, $open);
        if ($close === null) {
            return $maskedDefinition;
        }

        $callStart = $matchOffset + strlen('DEFAULT ');
        return substr($maskedDefinition, 0, $callStart)
            . '(' . substr($maskedDefinition, $callStart, $close - $callStart + 1) . ')'
            . substr($maskedDefinition, $close + 1);
    }

    /**
     * Find the ')' that closes the '(' at $openPos, or null if it never closes.
     * Callers pass masked text, so parens inside string literals can't miscount.
     */
    private static function matchingParenPos(string $maskedText, int $openPos): ?int
    {
        $depth = 0;
        for ($pos = $openPos, $length = strlen($maskedText); $pos < $length; $pos++) {
            if ($maskedText[$pos] === '(') {
                $depth++;
            } elseif ($maskedText[$pos] === ')' && --$depth === 0) {
                return $pos;
            }
        }
        return null;
    }

    //endregion
    //region Indexes

    /**
     * Get the name of a table's PRIMARY KEY column, or '' when the table has no primary key.
     *
     *     Table::primaryKey('articles'); // 'num'
     *
     * A composite primary key spans multiple columns; this returns the first one in key order
     * (Seq_in_index = 1), which isn't always the first in column order. If we ever need the
     * full list, add a primaryKeys() method that returns them all in key order.
     *
     * @param string $baseTable Table name without prefix
     * @return string The first PRIMARY KEY column name, or '' when there's none
     */
    public function primaryKey(string $baseTable): string
    {
        $fullTable = $this->db->tablePrefix . $baseTable;
        DB::assertIdentifier($fullTable, 'table name');
        $escapedFullTable = $this->mysqli->real_escape_string($fullTable);

        $result = $this->mysqli->query("SHOW INDEX FROM `$escapedFullTable` WHERE Key_name = 'PRIMARY' AND Seq_in_index = 1");
        $pkRow  = $result->fetch_assoc();
        $result->free();

        return $pkRow['Column_name'] ?? '';
    }

    /**
     * Get every index on a table, with the columns it spans and how it's classified.
     * Pass a column name to get only the indexes that include that column (case-insensitive, like MySQL).
     *
     *     Table::indexes('articles');
     *     // [
     *     //     '_auto_title'    => ['name' => '_auto_title',    'cols' => ['title'],                 'isAuto' => true,  'isPrimary' => false, 'isUnique' => false, 'indexType' => 'BTREE', 'isVisible' => true, 'comment' => '', 'colsCsv' => 'title',               'isFk' => false, 'isCustom' => false],
     *     //     'idx_title_date' => ['name' => 'idx_title_date', 'cols' => ['title', 'publish_date'], 'isAuto' => false, 'isPrimary' => false, 'isUnique' => true,  'indexType' => 'BTREE', 'isVisible' => true, 'comment' => '', 'colsCsv' => 'title, publish_date', 'isFk' => false, 'isCustom' => true],
     *     // ]
     *     Table::indexes('articles', 'publish_date'); // just the second one
     *
     * How each index is classified:
     *   - isAuto: indexes named with the `_auto_` prefix, the ones CMS Builder's field editor manages
     *   - isFk: the index MySQL creates automatically to back a FOREIGN KEY constraint (the constrained
     *     columns need an index, and MySQL won't let you drop it while the constraint exists). A UNIQUE
     *     index is never isFk, even when its columns match a constraint's: MySQL never auto-creates a
     *     UNIQUE one, so it's something the admin added on purpose, and it counts as custom
     *   - isCustom: everything else except PRIMARY: indexes added manually, e.g. straight through MySQL
     *
     * MySQL-level details, straight from SHOW INDEX:
     *   - indexType: BTREE, FULLTEXT, SPATIAL, or HASH
     *   - isVisible: false when the index is INVISIBLE (MySQL 8) or IGNORED (MariaDB)
     *   - comment: the index's COMMENT text, '' when none
     *
     * cols holds plain column names; colsCsv is the display form and adds prefix lengths, e.g. 'email(10)'.
     * A functional index part (MySQL 8) has no column name, so cols holds the parenthesized expression,
     * e.g. '(lower(`name`))', or '(expression)' on servers that don't report it. That also means the
     * columnName filter can't match a functional part: an index on (lower(`name`)) is NOT returned when
     * filtering by 'name', so callers cleaning up a column's indexes won't see it.
     *
     * @param string      $baseTable  Table name without prefix
     * @param string|null $columnName Only return indexes that include this column
     * @return array<string, array{name: string, cols: list<string>, isAuto: bool, isPrimary: bool, isUnique: bool, indexType: string, isVisible: bool, comment: string, colsCsv: string, isFk: bool, isCustom: bool}> indexName => index details
     */
    public function indexes(string $baseTable, ?string $columnName = null): array
    {
        $fullTable = $this->db->tablePrefix . $baseTable;
        DB::assertIdentifier($fullTable, 'table name');
        $escapedFullTable = $this->mysqli->real_escape_string($fullTable);

        $result = $this->mysqli->query("SHOW INDEX FROM `$escapedFullTable`");
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        // FOREIGN KEY constraint columns: SHOW INDEX doesn't say which indexes back a constraint, so look them up separately
        $fkColumnSets = array_column($this->foreignKeys($baseTable), 'cols');

        $indexes = self::parseShowIndexRows($rows, $fkColumnSets);

        if ($columnName !== null) {
            $colLower = strtolower($columnName);
            $indexes  = array_filter($indexes, fn($index) => in_array($colLower, array_map('strtolower', $index['cols']), true));
        }

        return $indexes;
    }

    /**
     * Group SHOW INDEX rows by index name and classify each index.
     * Separate from indexes() so tests can feed it fixture rows without a database.
     *
     * @param array<array<string, mixed>> $rows         Rows as returned by SHOW INDEX
     * @param list<list<string>>          $fkColumnSets Column lists of the table's FOREIGN KEY constraints, one list per constraint
     * @return array<string, array{name: string, cols: list<string>, isAuto: bool, isPrimary: bool, isUnique: bool, indexType: string, isVisible: bool, comment: string, colsCsv: string, isFk: bool, isCustom: bool}> indexName => index details
     */
    private static function parseShowIndexRows(array $rows, array $fkColumnSets = []): array
    {
        // group rows into indexes
        $indexes     = [];
        $displayCols = []; // cols with prefix lengths, e.g. 'email(10)'; joined into colsCsv below
        foreach ($rows as $row) {
            $name           = $row['Key_name'];
            $isHidden       = ($row['Visible'] ?? '') === 'NO' || ($row['Ignored'] ?? '') === 'YES'; // MySQL 8 INVISIBLE / MariaDB IGNORED
            $indexes[$name] ??= [
                'name'      => $name,
                'cols'      => [],
                'isAuto'    => str_starts_with($name, '_auto_'),
                'isPrimary' => $name === 'PRIMARY',
                'isUnique'  => empty($row['Non_unique']),
                'indexType' => $row['Index_type'] ?? '',
                'isVisible' => !$isHidden,
                'comment'   => $row['Index_comment'] ?? '',
            ];
            // functional index parts have no column name (MySQL 8); show the expression where the server reports it
            $col                      = $row['Column_name'] ?? (isset($row['Expression']) ? "({$row['Expression']})" : '(expression)');
            $indexes[$name]['cols'][] = $col;
            $displayCols[$name][]     = $col . (empty($row['Sub_part']) ? '' : "($row[Sub_part])");
        }

        // classify each index:
        //   - isFk: non-UNIQUE, and columns exactly match an FK constraint's (case-insensitive, like MySQL)
        //   - UNIQUE never counts as isFk: MySQL doesn't auto-create UNIQUE indexes for FKs, so those are the admin's own
        //   - exact match only, no leftmost-prefix, by design: a composite index that merely starts with the
        //     FK columns was added by the admin (MySQL just reuses it), so it stays visible as isCustom.
        //     Don't "fix" this into a leftmost-prefix match; TableTest pins it
        $fkColumnSetsLower = array_map(fn($cols) => array_map('strtolower', $cols), $fkColumnSets);
        foreach ($indexes as $name => &$index) {
            $index['colsCsv']  = implode(', ', $displayCols[$name]);
            $index['isFk']     = !$index['isUnique'] && in_array(array_map('strtolower', $index['cols']), $fkColumnSetsLower, true);
            $index['isCustom'] = !$index['isAuto'] && !$index['isPrimary'] && !$index['isFk'];
        }
        unset($index);

        return $indexes;
    }

    //endregion
    //region Foreign Keys

    /**
     * Get every FOREIGN KEY constraint on a table, keyed by constraint name.
     * Pass a column name to get only the constraints that include that column (case-insensitive, like MySQL).
     *
     *     Table::foreignKeys('articles');
     *     // [
     *     //     'cmsb_articles_ibfk_1' => ['name' => 'cmsb_articles_ibfk_1', 'cols' => ['author_num'], 'refTable' => 'cmsb_accounts', 'refCols' => ['num'], 'onDelete' => 'SET NULL', 'onUpdate' => 'RESTRICT'],
     *     // ]
     *     Table::foreignKeys('articles', 'author_num'); // just that one
     *
     * refTable is the referenced table's real MySQL name, prefix included, since a constraint can reference
     * tables without the prefix. cols and refCols align by position: cols[0] references refCols[0].
     * onDelete/onUpdate are MySQL's rules: RESTRICT, CASCADE, SET NULL, or NO ACTION.
     *
     * @param string      $baseTable  Table name without prefix
     * @param string|null $columnName Only return constraints that include this column
     * @return array<string, array{name: string, cols: list<string>, refTable: string, refCols: list<string>, onDelete: string, onUpdate: string}> constraintName => constraint details
     */
    public function foreignKeys(string $baseTable, ?string $columnName = null): array
    {
        $escapedFullTable = $this->mysqli->real_escape_string($this->db->tablePrefix . $baseTable);
        $result           = $this->mysqli->query(
            "SELECT KCU.CONSTRAINT_NAME, KCU.COLUMN_NAME, KCU.REFERENCED_TABLE_NAME, KCU.REFERENCED_COLUMN_NAME, RC.DELETE_RULE, RC.UPDATE_RULE
               FROM information_schema.KEY_COLUMN_USAGE AS KCU
               JOIN information_schema.REFERENTIAL_CONSTRAINTS AS RC
                 ON RC.CONSTRAINT_SCHEMA = KCU.CONSTRAINT_SCHEMA AND RC.CONSTRAINT_NAME = KCU.CONSTRAINT_NAME AND RC.TABLE_NAME = KCU.TABLE_NAME
              WHERE KCU.TABLE_SCHEMA = DATABASE() AND KCU.TABLE_NAME = '$escapedFullTable'
              ORDER BY KCU.CONSTRAINT_NAME, KCU.ORDINAL_POSITION",
        );
        $rows             = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        $foreignKeys = [];
        foreach ($rows as $row) {
            $name                            = $row['CONSTRAINT_NAME'];
            $foreignKeys[$name]              ??= [
                'name'     => $name,
                'cols'     => [],
                'refTable' => $row['REFERENCED_TABLE_NAME'],
                'refCols'  => [],
                'onDelete' => $row['DELETE_RULE'],
                'onUpdate' => $row['UPDATE_RULE'],
            ];
            $foreignKeys[$name]['cols'][]    = $row['COLUMN_NAME'];
            $foreignKeys[$name]['refCols'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        if ($columnName !== null) {
            $colLower    = strtolower($columnName);
            $foreignKeys = array_filter($foreignKeys, fn($fk) => in_array($colLower, array_map('strtolower', $fk['cols']), true));
        }

        return $foreignKeys;
    }

    /**
     * Get every FOREIGN KEY constraint in the database that references a table, keyed by constraint name.
     * The reverse of foreignKeys(): which tables point at this one.
     *
     *     Table::foreignKeysReferencing('accounts');
     *     // [
     *     //     'cmsb_articles_ibfk_1' => ['name' => 'cmsb_articles_ibfk_1', 'fullTable' => 'cmsb_articles', 'cols' => ['author_num'], 'refCols' => ['num']],
     *     // ]
     *
     * fullTable is the referencing table's real MySQL name, prefix included, since any table in the
     * database can hold the constraint. cols are the referencing table's columns; refCols are this
     * table's columns they point at, aligned by position: cols[0] references refCols[0].
     *
     * @param string $baseTable Table name without prefix
     * @return array<string, array{name: string, fullTable: string, cols: list<string>, refCols: list<string>}> constraintName => constraint details
     */
    public function foreignKeysReferencing(string $baseTable): array
    {
        $escapedFullTable = $this->mysqli->real_escape_string($this->db->tablePrefix . $baseTable);
        $result           = $this->mysqli->query(
            "SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = '$escapedFullTable'
              ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION",
        );
        $rows             = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        $foreignKeys = [];
        foreach ($rows as $row) {
            $name                            = $row['CONSTRAINT_NAME'];
            $foreignKeys[$name]              ??= [
                'name'      => $name,
                'fullTable' => $row['TABLE_NAME'],
                'cols'      => [],
                'refCols'   => [],
            ];
            $foreignKeys[$name]['cols'][]    = $row['COLUMN_NAME'];
            $foreignKeys[$name]['refCols'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        return $foreignKeys;
    }

    //endregion
    //region Internal Helpers

    /**
     * Crop the deprecated display width from the start of a column type or definition,
     * e.g. 'int(11) unsigned NOT NULL' → 'int unsigned NOT NULL', 'year(4)' → 'year'. Widths never
     * affected storage or range, and MySQL 8.0.19+ omits them from its output, so cropping makes
     * types read the same on every server. Two keep their width, matching MySQL 8's own output:
     * plain signed tinyint(1), which connectors treat as boolean (unsigned loses the width, MySQL
     * bugs #100309/#105667), and ZEROFILL columns, where the width sets the zero-padding amount.
     * \w*int matches the five int types; point/multipoint never take parens.
     */
    private static function cropIntDisplayWidth(string $typeSql): string
    {
        if (stripos($typeSql, 'zerofill') !== false) {
            return $typeSql;
        }
        return preg_replace('/^(?!tinyint\(1\)(?! unsigned))(\w*int|year)\(\d+\)/i', '$1', $typeSql);
    }

    //endregion
    //region Internals

    /** The connection whose tablePrefix applies */
    private Connection $db;

    /** Raw handle for this class's queries: plain-array results, immune to connection settings */
    private MysqliWrapper $mysqli;

    public function __construct(Connection $db)
    {
        $this->db     = $db;
        $this->mysqli = $db->mysqli ?? throw new RuntimeException("TableInfo requires a connected Connection.");
    }

    //endregion
}
