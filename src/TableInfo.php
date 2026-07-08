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
 * its primary key, its indexes, and its FOREIGN KEY constraints. baseNames() and fullNames()
 * list the tables themselves.
 *
 * Every connection has one, bound to its table prefix. Set at connect, null when disconnected.
 * The Table class is the static front door for the default connection; reach an instance
 * directly for any other connection:
 *
 *     Table::exists('users');                                        // default connection
 *     DB::clone(['tablePrefix' => 'cms_'])->table->exists('pages');  // clone with its own prefix
 *
 * exists() reports whether a table is there, baseNames()/fullNames() list what's there, and
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
            self::assertValidName($fullTable);
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
     *     Table::baseNames(); // ['accounts', 'articles', ..., '_cron_log', '_error_log', ...]
     *
     * Same list as fullNames(), just without the prefix.
     *
     * @return list<string> Base table names, prefix stripped
     */
    public function baseNames(): array
    {
        $prefixLength = strlen($this->db->tablePrefix);
        return array_map(fn(string $name) => substr($name, $prefixLength), $this->fullNames());
    }

    /**
     * Get every table's full MySQL name (prefix included): content tables first, then
     * system tables (underscore after the prefix), each group alphabetical.
     *
     *     Table::fullNames(); // ['cms_accounts', 'cms_articles', ..., 'cms__cron_log', ...]
     *
     * Only real tables whose names start with `tablePrefix` are listed; views and temporary
     * tables are not (exists() counts those). The list comes from information_schema rather
     * than SHOW TABLES, whose LIKE pattern MariaDB can ignore while temporary tables exist
     * (MDEV-32973). The TABLE_TYPE filter also keeps out temporary tables on MariaDB 11.4+,
     * the first server to list them in information_schema.
     *
     * @return list<string> Full table names, prefix included
     */
    public function fullNames(): array
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
     *   - deprecated display widths are cropped ('int(11)' → 'int', 'year(4)' → 'year'), same as columns();
     *     plain signed tinyint(1) (boolean marker) and zerofill columns (width sets the padding) keep theirs
     *   - MariaDB's default spelling current_timestamp() is normalized to MySQL's CURRENT_TIMESTAMP
     *   - MariaDB's bare numeric defaults are quoted the way MySQL prints them ('DEFAULT 0' → "DEFAULT '0'");
     *     both servers accept either form in DDL, the quoting is spelling, not type
     *
     * COMMENT text is never modified: it is split off before normalizing and reattached after.
     *
     * Known limit: a column COMMENT containing a newline breaks the line-based parse for that column.
     *
     * @param string $baseTable Table name without prefix
     * @return array<string, string> columnName => definition SQL
     */
    public function columnDefinitions(string $baseTable): array
    {
        $fullTable = $this->db->tablePrefix . $baseTable;
        self::assertValidName($fullTable);
        $escapedFullTable = $this->mysqli->real_escape_string($fullTable);

        $result = $this->mysqli->query("SHOW CREATE TABLE `$escapedFullTable`");
        $row    = $result->fetch_row();
        $result->free();

        return self::parseCreateTableColumns($row[1] ?? ''); // column 1: 'Create Table'
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
        self::assertValidName($fullTable);
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
        self::assertValidName($fullTable);
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
     * Validate a table name for safe backtick interpolation, same rule as the query
     * pipeline's identifier check. This is the guard that matters in backtick context:
     * real_escape_string() doesn't escape backticks, so escaping alone isn't enough there.
     *
     * @throws InvalidArgumentException
     */
    private static function assertValidName(string $identifier): void
    {
        DB::assertIdentifier($identifier, 'table name');
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
        $lines = explode("\n", $createTableSql);

        // the closing ") ENGINE=... DEFAULT CHARSET=x COLLATE=y" line names the defaults every column
        // inherits; build their column-level spellings so the loop below can remove them as redundant.
        // Partitioned tables append PARTITION clauses after that line, so it isn't always last, and
        // COLLATE is omitted when the table uses the charset's default collation, so handle each
        // separately. The trailing \b matches whole names only, so a utf8 default can't eat the
        // front of a column's utf8mb4
        $tableLine       = implode('', preg_grep('/^\)/', $lines));
        $tableDefaultRxs = [];
        if (preg_match('/\bCHARSET=(\S+)/', $tableLine, $match)) {
            $tableDefaultRxs[] = '/ CHARACTER SET ' . preg_quote($match[1], '/') . '\b/';
        }
        if (preg_match('/\bCOLLATE=(\S+)/', $tableLine, $match)) {
            $tableDefaultRxs[] = '/ COLLATE ' . preg_quote($match[1], '/') . '\b/';
        }

        // column lines start with a backtick-quoted name; PRIMARY KEY, KEY, and CONSTRAINT lines don't
        $definitions = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\s*`([^`]+)` (.*?),?$/', $line, $match)) {
                continue;
            }
            [, $columnName, $definition] = $match;

            // mask quoted string literals (defaults, comments, enum values, generated expressions)
            // so the normalizations below only ever see structural SQL, never text inside quotes;
            // quotes inside a literal are doubled ('') or backslash-escaped
            $literals   = [];
            $definition = preg_replace_callback("/'(?:[^'\\\\]++|\\\\.|'')*+'/", static function (array $match) use (&$literals): string {
                $placeholder            = "\x00" . count($literals) . "\x00";
                $literals[$placeholder] = $match[0];
                return $placeholder;
            }, $definition);

            $definition = preg_replace($tableDefaultRxs, '', $definition);
            $definition = self::cropIntDisplayWidth($definition);

            // MariaDB 10.2+ spells defaults current_timestamp(); MySQL spells them CURRENT_TIMESTAMP
            $definition = preg_replace('/\b(DEFAULT|ON UPDATE) current_timestamp(?:\(\))?(\(\d+\))?/i', '$1 CURRENT_TIMESTAMP$2', $definition);

            // MariaDB prints numeric-typed defaults bare (DEFAULT 0); MySQL prints them quoted (DEFAULT '0').
            // Quote bare numeric literals to match MySQL. Numbers only: other bare tokens are keywords (NULL)
            // or expressions (CURRENT_TIMESTAMP, uuid()) and must stay bare to keep their meaning, and string
            // defaults are quoted, so they're masked above and can never match
            $definition = preg_replace("/\bDEFAULT (-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=,| |$)/", "DEFAULT '$1'", $definition);

            $definitions[$columnName] = strtr($definition, $literals);
        }

        return $definitions;
    }

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
