<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Table;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Table;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli_sql_exception;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * DB-backed tests for Table, the halves the pure TableTest can't reach. Each test runs against
 * one throwaway InnoDB table built in setUp and dropped in tearDown.
 *
 * The fixture table (prefix + "ztest_mysqltable_<pid>") is designed to hit every classification branch:
 *
 *     num          INT  PRIMARY KEY                         -> PRIMARY
 *     title        ...  INDEX _auto_title (title)           -> isAuto
 *                       INDEX idx_single (sort_date)        -> isCustom, single column
 *                       UNIQUE idx_ab (title, sort_date)    -> isCustom + isUnique, composite
 *     parent_num   INT  INDEX idx_fkcomposite (parent_num, sort_date) + FK fk_parent(parent_num)->num
 *                       MySQL uses the composite (leftmost prefix) to enforce the FK, so no auto index.
 *     owner_num    INT  FK fk_owner(owner_num)->num, no covering index
 *                       MySQL auto-creates a single index to back it -> isFk. The auto index's NAME is
 *                       server-dependent (the constraint name "fk_owner" here, the column name on other
 *                       servers), so tests find it by the isFk flag, not by a hard-coded name.
 *
 * Cases the main fixture can't host (no PRIMARY KEY, a composite one, a real child table, DDL that
 * needs literal numbers or quotes) get a side table via createSideTable(). dropFixture() finds fixture
 * tables by name pattern, so it also sweeps leftovers from crashed runs under other pids: their fixed
 * constraint names (fk_parent, fk_owner) would otherwise collide with setUp's ADD CONSTRAINT forever.
 */
class TableIntegrationTest extends BaseTestCase
{
    private string $baseTable = '';
    private string $fullTable = '';

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
    }

    protected function setUp(): void
    {
        // Fixed name, not a per-run suffix: record/replay needs identical SQL across runs,
        // and dropFixture() below already handles leftovers from a dead prior run
        $this->baseTable = 'ztest_mysqltable_fixture';
        $this->fullTable = DB::$tablePrefix . $this->baseTable;

        $this->dropFixture(); // in case a prior run died before tearDown

        // InnoDB is required for FOREIGN KEY support. Column widths are omitted (CHAR = CHAR(1)) because
        // the test never inserts rows and ZenDB's query guard rejects standalone numbers in the template.
        DB::query("CREATE TABLE `?` (
                     num        INT NOT NULL,
                     title      CHAR NOT NULL DEFAULT '',
                     sort_date  DATE NULL,
                     parent_num INT NULL,
                     owner_num  INT NULL,
                     PRIMARY KEY (num),
                     INDEX _auto_title (title),
                     INDEX idx_single (sort_date),
                     UNIQUE INDEX idx_ab (title, sort_date),
                     INDEX idx_fkcomposite (parent_num, sort_date)
                   ) ENGINE=InnoDB", $this->fullTable);

        // add FKs after the table exists. fk_parent is covered by idx_fkcomposite (no auto index created);
        // fk_owner has no covering index, so MySQL auto-creates a single index (its name varies by server).
        // fk_owner gets an explicit ON DELETE rule so foreignKeys() tests can assert an exact value;
        // fk_parent keeps the default, which reports as RESTRICT (MariaDB) or NO ACTION (MySQL).
        DB::query("ALTER TABLE `?` ADD CONSTRAINT fk_parent FOREIGN KEY (parent_num) REFERENCES `?` (num)", $this->fullTable, $this->fullTable);
        DB::query("ALTER TABLE `?` ADD CONSTRAINT fk_owner  FOREIGN KEY (owner_num)  REFERENCES `?` (num) ON DELETE CASCADE", $this->fullTable, $this->fullTable);
    }

    protected function tearDown(): void
    {
        if ($this->fullTable !== '') {
            $this->dropFixture();
        }
    }

    private function dropFixture(): void
    {
        // every fixture table, ours and any pid's: leftovers from a crashed run block all future
        // runs because the fixed constraint names collide with setUp's ADD CONSTRAINT.
        // TABLE_TYPE picks the right DROP statement: DROP TABLE refuses to drop a view
        $pattern       = str_replace('_', '\\_', DB::$tablePrefix . 'ztest_mysqltable_') . '%';
        $fixtureTables = DB::query(
            "SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE :pattern",
            [':pattern' => $pattern],
        )->toArray();

        // side tables first (longer names): _child's FK constraint would block dropping its main table
        usort($fixtureTables, fn($a, $b) => strlen($b['TABLE_NAME']) <=> strlen($a['TABLE_NAME']));
        foreach ($fixtureTables as $table) {
            try {
                $dropSql = $table['TABLE_TYPE'] === 'VIEW' ? "DROP VIEW IF EXISTS `?`" : "DROP TABLE IF EXISTS `?`";
                DB::query($dropSql, $table['TABLE_NAME']);
            } catch (Throwable) {
                // best-effort cleanup; a failure here shouldn't mask the test result
            }
        }
    }

    /**
     * Create an extra table for one test and return its base name (what Table methods take).
     * Runs through DB::$mysqli->query() because ZenDB's template guard rejects the literal numbers
     * and quotes DDL sometimes needs, e.g. VARCHAR(50) or COMMENT 'text'. The name is fixture name +
     * suffix, so dropFixture() cleans it up along with the main table.
     */
    private function createSideTable(string $suffix, string $columnsSql): string
    {
        DB::$mysqli->query("CREATE TABLE `$this->fullTable$suffix` ($columnsSql) ENGINE=InnoDB");
        return $this->baseTable . $suffix;
    }

    //region exists()

    #[Test]
    public function existsReportsRealTablesAndRejectsUnknownOnes(): void
    {
        $this->assertTrue(Table::exists($this->baseTable));
        $this->assertFalse(Table::exists($this->baseTable . '_no_such_table'));
    }

    #[Test]
    public function existsSeesViewsAndTemporaryTables(): void
    {
        // both exist to a live SELECT probe; information_schema would miss the temporary table
        // everywhere except MariaDB 11.4+ (see docs/internal/db-behavior-matrix.md, 2026-07)
        DB::$mysqli->query("CREATE VIEW `{$this->fullTable}_view` AS SELECT num FROM `$this->fullTable`");
        DB::$mysqli->query("CREATE TEMPORARY TABLE `{$this->fullTable}_temp` (num INT)");

        $this->assertTrue(Table::exists($this->baseTable . '_view'), 'views count as existing');
        $this->assertTrue(Table::exists($this->baseTable . '_temp'), 'temporary tables count as existing');

        // the view is swept by dropFixture(); the temporary table needs an explicit drop here -
        // dropFixture()'s information_schema sweep can't see it except on MariaDB 11.4+, and
        // this process's connection is shared
        DB::$mysqli->query("DROP TEMPORARY TABLE `{$this->fullTable}_temp`");
    }

    #[Test]
    public function existsThrowsForInvalidNames(): void
    {
        // same rule as every other method that takes a table name; the deprecated
        // hasTable()/tableExists() shims keep answering false (HasTableTest pins that)
        $badNames = ['bad`name', 'bad name', 'users; DROP TABLE users', '', 'no.dots'];
        foreach ($badNames as $badName) {
            try {
                Table::exists($badName);
                $this->fail("Table::exists('$badName') should throw");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid table name', $e->getMessage());
            }
        }
    }

    #[Test]
    public function existsFullTakesTheExactMysqlName(): void
    {
        $this->assertTrue(Table::existsFull($this->fullTable), 'the real full name matches');
        $this->assertFalse(Table::existsFull($this->fullTable . '_no_such_table'));
        if (DB::$tablePrefix !== '') {
            $this->assertFalse(Table::existsFull($this->baseTable), 'the base name alone is not the MySQL name');
        }
    }

    #[Test]
    public function existsFullThrowsForInvalidNames(): void
    {
        // dots included: ZenDB can't create a dotted name, so asking about one is a coding
        // mistake; the existsFull() docblock shows the information_schema query for tables
        // other tools created
        $badNames = ['bad`name', 'no_such.dotted_table', ''];
        foreach ($badNames as $badName) {
            try {
                Table::existsFull($badName);
                $this->fail("Table::existsFull('$badName') should throw");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid table name', $e->getMessage());
            }
        }
    }

    #[Test]
    public function cloneChecksTablesUnderItsOwnPrefix(): void
    {
        // the fixture's full name is prefix + "ztest_mysqltable_fixture"; a clone with the longer
        // prefix finds it under the shorter base name, while the default connection doesn't
        $clone     = DB::clone(['tablePrefix' => DB::$tablePrefix . 'ztest_']);
        $shortBase = 'mysqltable_fixture';

        $this->assertTrue($clone->table->exists($shortBase), "clone's exists() uses the clone's prefix");
        $this->assertFalse(Table::exists($shortBase), "default connection's exists() still uses its own prefix");
        $this->assertContains($this->fullTable, $clone->table->namesFull(), "clone's namesFull() lists under its prefix");
    }

    #[Test]
    public function existsTakesABaseNameNotAPrefixedOne(): void
    {
        if (DB::$tablePrefix === '') {
            $this->markTestSkipped('base and full names are identical without a table prefix');
        }
        $this->assertFalse(Table::exists($this->fullTable), 'a prefixed name gets prefixed again and misses');
    }

    #[Test]
    public function existsAnswersFalseWhenTheServerRejectsTheTable(): void
    {
        // a view whose base table was dropped fails with error 1356, not 1146; the server
        // answered "you can't use that table", so exists() reports false instead of throwing
        // (dropFixture() sweeps the broken view: information_schema still lists it as a VIEW)
        $this->createSideTable('_vbase', 'num INT');
        DB::$mysqli->query("CREATE VIEW `{$this->fullTable}_vbroken` AS SELECT num FROM `{$this->fullTable}_vbase`");
        DB::$mysqli->query("DROP TABLE `{$this->fullTable}_vbase`");

        $this->assertFalse(Table::exists($this->baseTable . '_vbroken'));
    }

    //endregion
    //region names() / namesFull()

    #[Test]
    public function namesAndNamesFullListTheSameTablesWithAndWithoutPrefix(): void
    {
        $names     = Table::names();
        $namesFull = Table::namesFull();

        $this->assertContains($this->fullTable, $namesFull, 'fixture table is listed');
        $this->assertSame($namesFull, array_map(fn($name) => DB::$tablePrefix . $name, $names), 'same list, prefix on or off');
    }

    #[Test]
    public function namesSortSystemTablesAfterContentTablesEachGroupAlphabetical(): void
    {
        // The suite fixture has no _system tables, so without probes the system half
        // asserts over an empty array; create two in reverse alphabetical order
        DB::$mysqli->query("CREATE TABLE `" . DB::$tablePrefix . "_zz_probe` (num INT)");
        DB::$mysqli->query("CREATE TABLE `" . DB::$tablePrefix . "_aa_probe` (num INT)");
        try {
            $names = Table::names();
        } finally {
            DB::$mysqli->query("DROP TABLE IF EXISTS `" . DB::$tablePrefix . "_zz_probe`");
            DB::$mysqli->query("DROP TABLE IF EXISTS `" . DB::$tablePrefix . "_aa_probe`");
        }
        $content   = array_values(array_filter($names, fn($name) => !str_starts_with($name, '_')));
        $system    = array_values(array_filter($names, fn($name) => str_starts_with($name, '_')));

        $this->assertContains('_aa_probe', $system);
        $this->assertContains('_zz_probe', $system);
        $this->assertSame([...$content, ...$system], $names, 'content tables first, then _system tables');

        $sortedContent = $content;
        sort($sortedContent);
        $this->assertSame($sortedContent, $content, 'content group is alphabetical');

        $sortedSystem = $system;
        sort($sortedSystem);
        $this->assertSame($sortedSystem, $system, 'system group is alphabetical');
    }

    #[Test]
    public function namesExcludeViewsThatExistsCounts(): void
    {
        DB::$mysqli->query("CREATE VIEW `{$this->fullTable}_view` AS SELECT num FROM `$this->fullTable`");

        $this->assertTrue(Table::exists($this->baseTable . '_view'), 'exists() counts the view');
        $this->assertNotContains($this->fullTable . '_view', Table::namesFull(), 'namesFull() lists real tables only');
    }

    //endregion
    //region hasColumn()

    #[Test]
    public function hasColumnFindsColumnsCaseInsensitively(): void
    {
        $this->assertTrue(Table::hasColumn($this->baseTable, 'title'));
        $this->assertTrue(Table::hasColumn($this->baseTable, 'TITLE'), 'column names compare case-insensitively, like MySQL');
        $this->assertFalse(Table::hasColumn($this->baseTable, 'no_such_column'));
    }

    #[Test]
    public function hasColumnReturnsFalseForUnknownTableInsteadOfThrowing(): void
    {
        $this->assertFalse(Table::hasColumn($this->baseTable . '_no_such_table', 'num'), 'no table, no column');
    }

    //endregion
    //region columns()

    #[Test]
    public function columnsThrowsForInvalidNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name');

        Table::columns('bad`name');
    }

    #[Test]
    public function foreignKeysThrowsForInvalidNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name');

        Table::foreignKeys('users; DROP TABLE users');
    }

    #[Test]
    public function columnsReportsEveryColumnInTableOrder(): void
    {
        $columns = Table::columns($this->baseTable);

        $this->assertSame(['num', 'title', 'sort_date', 'parent_num', 'owner_num'], array_keys($columns), 'keyed by name, in table order');
        $this->assertSame(array_keys($columns), Table::columnNames($this->baseTable), 'columnNames() is the same list');

        $num = $columns['num'];
        $this->assertSame('num', $num['name']);
        $this->assertSame('int', $num['type'], "deprecated display widths are cropped, so 'int(11)' servers report plain 'int' too");
        $this->assertFalse($num['isNullable']);
        $this->assertNull($num['charset'], 'non-text columns have no charset');

        $title = $columns['title'];
        $this->assertSame('char(1)', $title['type'], 'only INT display widths are cropped; char(1) is a real length');
        $this->assertFalse($title['isNullable']);
        $this->assertNotEmpty($title['charset'], 'text columns report their charset');

        $this->assertTrue($columns['sort_date']['isNullable']);
    }

    #[Test]
    public function columnsMarksGeneratedColumnsAndKeepsTinyint1Width(): void
    {
        $genTable = $this->createSideTable('_extra', 'num INT NOT NULL, flag TINYINT(1) NOT NULL DEFAULT 0, twice INT AS (num * 2) STORED');
        $columns  = Table::columns($genTable);

        $this->assertStringContainsString('GENERATED', $columns['twice']['extra'], "extra marks generated columns, e.g. 'STORED GENERATED'");
        $this->assertSame('int', $columns['twice']['type'], 'a generated column still reports its plain type');
        $this->assertSame('tinyint(1)', $columns['flag']['type'], 'tinyint(1) keeps its width; connectors treat it as boolean');
    }

    #[Test]
    public function columnNamesReportsAnAllDigitColumnNameAsAString(): void
    {
        $yearsTable = $this->createSideTable('_years', 'num INT NOT NULL, `2024` INT NULL');

        $this->assertSame(['num', '2024'], Table::columnNames($yearsTable));
    }

    #[Test]
    public function columnsReturnsEmptyArrayForUnknownTable(): void
    {
        $this->assertSame([], Table::columns($this->baseTable . '_no_such_table'));
    }

    //endregion
    //region columnDefinitions()

    #[Test]
    public function columnDefinitionsRoundTripThroughModifyColumn(): void
    {
        $definitions = Table::columnDefinitions($this->baseTable);

        $this->assertSame(array_keys(Table::columns($this->baseTable)), array_keys($definitions), 'same columns, same order as columns()');
        $this->assertSame('int NOT NULL', $definitions['num'], 'display width cropped, table-default charset stripped');
        $this->assertSame("char(1) NOT NULL DEFAULT ''", $definitions['title']);

        // and defaults read the same on every server when extracted from these definitions
        $this->assertSame('', Table::defaultFromDefinition($definitions['title']));
        $this->assertNull(Table::defaultFromDefinition($definitions['sort_date']), 'nullable with no default');

        // the contract: every definition works in MODIFY COLUMN. Run one with a quoted default through
        // the server and confirm nothing changed.
        DB::$mysqli->query("ALTER TABLE `$this->fullTable` MODIFY COLUMN `title` {$definitions['title']}");
        $this->assertSame($definitions, Table::columnDefinitions($this->baseTable), 'MODIFY with its own definition is a no-op');
    }

    #[Test]
    public function numericDefaultsReadQuotedLikeMysqlAndSurviveModify(): void
    {
        // MariaDB prints these bare (DEFAULT 0); the parser quotes them the way MySQL prints them,
        // so both vendors return identical strings. MODIFY accepts the quoted form and re-reading
        // yields the same string again (the server re-prints bare, the parser re-quotes)
        $extraBase   = $this->createSideTable('_extra', 'num INT NOT NULL, hits INT NOT NULL DEFAULT 0, price DECIMAL(10,2) NOT NULL DEFAULT 1.50, offset_val INT NOT NULL DEFAULT -1');
        $definitions = Table::columnDefinitions($extraBase);

        $this->assertSame("int NOT NULL DEFAULT '0'", $definitions['hits']);
        $this->assertSame("decimal(10,2) NOT NULL DEFAULT '1.50'", $definitions['price']);
        $this->assertSame("int NOT NULL DEFAULT '-1'", $definitions['offset_val']);
        $this->assertSame('0', Table::defaultFromDefinition($definitions['hits']), 'extracted value is unaffected by the quoting');

        DB::$mysqli->query("ALTER TABLE `" . DB::$tablePrefix . "$extraBase` MODIFY COLUMN `price` {$definitions['price']}");
        $this->assertSame($definitions, Table::columnDefinitions($extraBase), 'MODIFY with its own definition is a no-op');
    }

    #[Test]
    public function columnDefinitionsReportsGeneratedColumnsAsExecutableSql(): void
    {
        $genTable = $this->createSideTable('_extra', "num INT NOT NULL, label VARCHAR(20) AS (IF(num > 0, 'yes', 'no')) STORED");

        $label = Table::columnDefinitions($genTable)['label'];
        $this->assertStringContainsString('GENERATED ALWAYS AS', $label);
        $this->assertStringContainsString('STORED', $label);

        // generated columns go through MODIFY like any other column; prove that works
        DB::$mysqli->query("ALTER TABLE `" . DB::$tablePrefix . "$genTable` MODIFY COLUMN `label` $label");
        $this->assertSame($label, Table::columnDefinitions($genTable)['label'], 'MODIFY with its own definition is a no-op');
    }

    //endregion
    //region showCreateTable()

    #[Test]
    public function showCreateTableReturnsTheVerbatimCreateStatement(): void
    {
        $sql = Table::showCreateTable($this->baseTable);

        $this->assertStringStartsWith("CREATE TABLE `$this->fullTable`", $sql);
        foreach (['num', 'title', 'sort_date', 'parent_num', 'owner_num'] as $column) {
            $this->assertStringContainsString("`$column`", $sql);
        }
        $this->assertStringContainsString('PRIMARY KEY (`num`)', $sql);
        $this->assertStringContainsString('CONSTRAINT `fk_owner`', $sql, 'keys and constraints are part of the statement, unlike columnDefinitions()');
    }

    #[Test]
    public function showCreateTableThrowsForUnknownTables(): void
    {
        $this->expectException(mysqli_sql_exception::class);
        Table::showCreateTable($this->baseTable . '_no_such_table');
    }

    //endregion
    //region indexes()

    #[Test]
    public function classifiesEveryIndexKind(): void
    {
        $indexes = Table::indexes($this->baseTable);

        // the five indexes with fixed names, plus one FK auto-index for owner_num that the server names
        foreach (['PRIMARY', '_auto_title', 'idx_single', 'idx_ab', 'idx_fkcomposite'] as $name) {
            $this->assertArrayHasKey($name, $indexes, "$name should be reported");
        }

        $this->assertFlags($indexes['PRIMARY'],     ['isPrimary' => true,  'isUnique' => true,  'isCustom' => false, 'isFk' => false, 'isAuto' => false]);
        $this->assertFlags($indexes['_auto_title'], ['isAuto'    => true,  'isCustom' => false, 'isFk'     => false]);
        $this->assertFlags($indexes['idx_single'],  ['isCustom'  => true,  'isUnique' => false, 'isFk'     => false]);
        $this->assertSame('BTREE', $indexes['idx_single']['indexType'], 'a plain InnoDB index reports BTREE');
        $this->assertTrue($indexes['idx_single']['isVisible']);
        $this->assertSame('', $indexes['idx_single']['comment']);
        $this->assertFlags($indexes['idx_ab'],      ['isCustom'  => true,  'isUnique' => true,  'isFk'     => false]);
        $this->assertSame(['title', 'sort_date'], $indexes['idx_ab']['cols']);
        $this->assertSame('title, sort_date', $indexes['idx_ab']['colsCsv']);

        // the FK auto-index for owner_num is detected, so it won't be mistaken for a user-added index.
        // Its name is server-dependent, so find it by the column it covers, not by name.
        $ownerIndexes = array_filter($indexes, fn($i) => $i['cols'] === ['owner_num']);
        $this->assertCount(1, $ownerIndexes, 'exactly one single-column index backs the owner_num FK');
        $this->assertFlags(reset($ownerIndexes), ['isFk' => true, 'isCustom' => false, 'isAuto' => false]);

        // BY DESIGN (see TableTest::compositeSupersetOfForeignKeyColumnsIsClassifiedCustom):
        // idx_fkcomposite enforces fk_parent (parent_num is its leftmost prefix), but the admin added the
        // composite on purpose, so it reports isFk=false / isCustom=true and stays visible as the admin's
        // own. This pins the classification against a real MySQL, not just fixture rows.
        $this->assertFlags($indexes['idx_fkcomposite'], ['isFk' => false, 'isCustom' => true]);
    }

    #[Test]
    public function filtersToIndexesCoveringOneColumn(): void
    {
        // SHOW INDEX order isn't the CREATE order, so compare as sets
        $this->assertEqualsCanonicalizing(
            ['_auto_title', 'idx_ab'],
            array_keys(Table::indexes($this->baseTable, 'title')),
            'only indexes that include title'
        );

        // case-insensitive, like MySQL
        $this->assertEqualsCanonicalizing(
            ['_auto_title', 'idx_ab'],
            array_keys(Table::indexes($this->baseTable, 'TITLE'))
        );

        $this->assertSame([], Table::indexes($this->baseTable, 'no_such_column'), 'a column with no indexes returns none');
    }

    #[Test]
    public function reportsIndexTypePrefixLengthAndCommentFromTheServer(): void
    {
        // the unit test covers these fields with fixture rows; this pins the real SHOW INDEX column
        // names (Index_type, Sub_part, Index_comment) so a server rename can't slip through
        $extraBase = $this->createSideTable('_extra', "
            email VARCHAR(50) NOT NULL,
            body  TEXT NOT NULL,
            INDEX idx_email (email(10)) COMMENT 'covering email lookups',
            FULLTEXT INDEX ft_body (body)
        ");

        $indexes = Table::indexes($extraBase);
        $this->assertSame('FULLTEXT',               $indexes['ft_body']['indexType']);
        $this->assertSame(['email'],                $indexes['idx_email']['cols'], 'cols stays plain column names');
        $this->assertSame('email(10)',              $indexes['idx_email']['colsCsv'], 'the prefix length comes through Sub_part');
        $this->assertSame('covering email lookups', $indexes['idx_email']['comment']);
    }

    #[Test]
    public function hiddenIndexReportsIsVisibleFalse(): void
    {
        // MySQL 8 calls a hidden index INVISIBLE, MariaDB 10.6+ calls it IGNORED; older servers
        // have neither and answer 1064 (parse error). Anything else is a real failure.
        try {
            DB::query("ALTER TABLE `?` ALTER INDEX idx_single INVISIBLE", $this->fullTable);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1064) {
                throw $e;
            }
            try {
                DB::query("ALTER TABLE `?` ALTER INDEX idx_single IGNORED", $this->fullTable);
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() !== 1064) {
                    throw $e;
                }
                $this->markTestSkipped('Server supports neither INVISIBLE (MySQL 8) nor IGNORED (MariaDB 10.6+) indexes.');
            }
        }

        $indexes = Table::indexes($this->baseTable);
        $this->assertFalse($indexes['idx_single']['isVisible']);
        $this->assertTrue($indexes['_auto_title']['isVisible'], 'other indexes stay visible');
    }

    #[Test]
    public function functionalIndexColsShowTheExpression(): void
    {
        // functional index parts are MySQL 8.0.13+ only; MariaDB and older MySQL answer
        // 1064 (parse error). Anything else is a real failure.
        $extraBase = $this->createSideTable('_extra', 'email VARCHAR(50) NOT NULL');
        try {
            DB::query("ALTER TABLE `?` ADD INDEX idx_lower ((lower(email)))", DB::$tablePrefix . $extraBase);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1064) {
                throw $e;
            }
            $this->markTestSkipped('Server does not support functional index parts (MySQL 8.0.13+ only).');
        }

        $cols = Table::indexes($extraBase)['idx_lower']['cols'];
        $this->assertCount(1, $cols);
        $this->assertStringStartsWith('(', $cols[0], 'expression parts are parenthesized, not column names');
        $this->assertStringContainsStringIgnoringCase('lower', $cols[0]);
    }

    //endregion
    //region primaryKey()

    #[Test]
    public function primaryKeyReturnsTheColumnName(): void
    {
        $this->assertSame('num', Table::primaryKey($this->baseTable));
    }

    #[Test]
    public function primaryKeyReturnsEmptyStringForTableWithNoPrimaryKey(): void
    {
        // callers rely on '' to skip their ORDER BY clause
        $noPkTable = $this->createSideTable('_nopk', 'num INT NOT NULL');

        $this->assertSame('', Table::primaryKey($noPkTable));
    }

    #[Test]
    public function primaryKeyReturnsFirstColumnOfCompositePrimaryKeyInKeyOrder(): void
    {
        // key order (beta, alpha) deliberately differs from column order (alpha, beta): "first" means
        // first in the KEY, Seq_in_index = 1, not the leftmost column in the table definition
        $cpkTable = $this->createSideTable('_cpk', 'alpha INT NOT NULL, beta INT NOT NULL, PRIMARY KEY (beta, alpha)');

        $this->assertSame('beta', Table::primaryKey($cpkTable));
    }

    //endregion
    //region foreignKeys()

    #[Test]
    public function listsEveryConstraintOrderedByName(): void
    {
        $foreignKeys = Table::foreignKeys($this->baseTable);

        $this->assertSame(['fk_owner', 'fk_parent'], array_column($foreignKeys, 'name'), 'a plain list, ordered by name');
        $fkOwner = $foreignKeys[0];
        $this->assertSame(['owner_num'],     $fkOwner['cols']);
        $this->assertSame($this->currentDatabase(), $fkOwner['refDatabase'], 'refDatabase is where the referenced table is');
        $this->assertSame($this->fullTable,  $fkOwner['refTable'], 'refTable is the real MySQL name, prefix included');
        $this->assertSame(['num'],           $fkOwner['refCols']);
        $this->assertSame('CASCADE',         $fkOwner['onDelete'], 'explicit rule reports as written');
        $this->assertContains($fkOwner['onUpdate'], ['RESTRICT', 'NO ACTION'], 'unspecified rule reports as the server default');
    }

    #[Test]
    public function filtersToConstraintsIncludingOneColumn(): void
    {
        $this->assertSame(['fk_parent'], array_column(Table::foreignKeys($this->baseTable, 'parent_num'), 'name'));
        $this->assertSame(['fk_parent'], array_column(Table::foreignKeys($this->baseTable, 'PARENT_NUM'), 'name'), 'case-insensitive, like MySQL');
        $this->assertSame([0],           array_keys(Table::foreignKeys($this->baseTable, 'parent_num')), 'still a plain list: fk_parent is second before the filter');
        $this->assertSame([],            Table::foreignKeys($this->baseTable, 'title'), 'no FK on a plain column');
    }

    #[Test]
    public function reportsAnAllDigitConstraintNameAsAString(): void
    {
        // MariaDB 12.1+ names unnamed keys 1, 2, 3; every server accepts 1 as a name that's written out
        $numberedBase = $this->createSideTable('_numbered', "num INT NOT NULL PRIMARY KEY, fixture_num INT NULL, CONSTRAINT `1` FOREIGN KEY (fixture_num) REFERENCES `$this->fullTable` (num)");

        $foreignKeys = Table::foreignKeys($numberedBase);
        $this->assertSame([0],   array_keys($foreignKeys), 'a list position, not the name');
        $this->assertSame(['1'], array_column($foreignKeys, 'name'));
    }

    #[Test]
    public function groupsCompositeConstraintColumnsInOrder(): void
    {
        // a composite FK needs an index on the referenced columns (num, sort_date), and MySQL 8.4+
        // requires it to be UNIQUE (restrict_fk_on_non_standard_key; MariaDB and older MySQL also
        // accept a plain index). The child side (parent_num, sort_date) is already covered by the
        // fixture's idx_fkcomposite
        DB::query("ALTER TABLE `?` ADD UNIQUE INDEX idx_ref (num, sort_date)", $this->fullTable);
        DB::query("ALTER TABLE `?` ADD CONSTRAINT fk_composite FOREIGN KEY (parent_num, sort_date) REFERENCES `?` (num, sort_date)", $this->fullTable, $this->fullTable);

        $fk = array_column(Table::foreignKeys($this->baseTable), null, 'name')['fk_composite'];
        $this->assertSame(['parent_num', 'sort_date'], $fk['cols'], 'constraint columns in ordinal order');
        $this->assertSame(['num', 'sort_date'],        $fk['refCols'], 'referenced columns align with cols by position');

        // filtering by a non-lead member column still finds it
        $this->assertSame(['fk_composite'], array_column(Table::foreignKeys($this->baseTable, 'sort_date'), 'name'));
    }

    #[Test]
    public function reportsTheDatabaseOfATableInAnotherDatabase(): void
    {
        $otherDb = $this->currentDatabase() . '_fk_other';
        DB::$mysqli->query("DROP DATABASE IF EXISTS `$otherDb`");
        DB::$mysqli->query("CREATE DATABASE `$otherDb`");
        DB::$mysqli->query("CREATE TABLE `$otherDb`.`target` (num INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        try {
            $outboundBase = $this->createSideTable('_outbound', "num INT NOT NULL PRIMARY KEY, target_num INT NULL, CONSTRAINT fk_outbound FOREIGN KEY (target_num) REFERENCES `$otherDb`.`target` (num)");

            $fk = Table::foreignKeys($outboundBase)[0];
            $this->assertSame($otherDb, $fk['refDatabase']);
            $this->assertSame('target', $fk['refTable']);
        } finally {
            // the side table first: its key would block dropping the table it references
            DB::$mysqli->query("DROP TABLE IF EXISTS `{$this->fullTable}_outbound`");
            DB::$mysqli->query("DROP DATABASE IF EXISTS `$otherDb`");
        }
    }

    #[Test]
    public function foreignKeysReferencingFindsInboundConstraints(): void
    {
        // the fixture's FKs are self-referencing (they point at the fixture table's own num column),
        // so both show up as inbound constraints on the same table
        $inbound = Table::foreignKeysReferencing($this->baseTable);

        $this->assertSame(['fk_owner', 'fk_parent'], array_column($inbound, 'name'), 'a plain list, ordered by name within one table');
        $fkOwner = $inbound[0];
        $this->assertSame($this->currentDatabase(), $fkOwner['database'], 'database is where the referencing table is');
        $this->assertSame($this->fullTable,      $fkOwner['fullTable'], "fullTable is the referencing table's real MySQL name, prefix included");
        $this->assertSame(['owner_num'],         $fkOwner['cols']);
        $this->assertSame(['num'],               $fkOwner['refCols']);
    }

    #[Test]
    public function foreignKeysReferencingDistinguishesInboundFromOutbound(): void
    {
        // a self-referencing FK looks identical from both directions, so only a separate child table
        // proves foreignKeysReferencing() reads the referencing side and foreignKeys() the owning side
        $childBase = $this->createSideTable('_child', "
            num         INT NOT NULL PRIMARY KEY,
            fixture_num INT NULL,
            CONSTRAINT fk_child FOREIGN KEY (fixture_num) REFERENCES `$this->fullTable` (num)
        ");

        $fkChild = array_column(Table::foreignKeysReferencing($this->baseTable), null, 'name')['fk_child'] ?? null;
        $this->assertNotNull($fkChild, "the child's constraint shows as inbound on the fixture");
        $this->assertSame(DB::$tablePrefix . $childBase, $fkChild['fullTable'], 'fullTable names the child, not the fixture');
        $this->assertSame(['fixture_num'],               $fkChild['cols']);
        $this->assertSame(['num'],                       $fkChild['refCols']);

        $this->assertNotContains('fk_child', array_column(Table::foreignKeys($this->baseTable), 'name'), "the fixture's own constraints don't include the child's");
        $this->assertSame($this->fullTable, Table::foreignKeys($childBase)[0]['refTable'], "from the child's side the same constraint is outbound");
        $this->assertSame([], Table::foreignKeysReferencing($childBase), 'nothing references the child');
    }

    #[Test]
    public function foreignKeysReferencingKeepsUnnamedKeysFromTwoTablesApart(): void
    {
        // MariaDB 12.1+ names both of these `1`; every other server names them <table>_ibfk_1
        $eventBase    = $this->createSideTable('_event', "num INT NOT NULL PRIMARY KEY, event_item INT NULL, FOREIGN KEY (event_item) REFERENCES `$this->fullTable` (num)");
        $warrantyBase = $this->createSideTable('_warranty', "num INT NOT NULL PRIMARY KEY, warranty_item INT NULL, FOREIGN KEY (warranty_item) REFERENCES `$this->fullTable` (num)");

        $this->assertSame([
            DB::$tablePrefix . $eventBase    => ['event_item'],
            DB::$tablePrefix . $warrantyBase => ['warranty_item'],
        ], $this->inboundColsByTable(['_event', '_warranty']), 'one entry per table, each with its own column');

        // foreignKeys() reads one table at a time, so a shared name can't mix the two
        $this->assertSame([['event_item']],    array_column(Table::foreignKeys($eventBase), 'cols'));
        $this->assertSame([['warranty_item']], array_column(Table::foreignKeys($warrantyBase), 'cols'));
    }

    #[Test]
    public function foreignKeysReferencingKeepsSameNamedKeysFromTwoTablesApart(): void
    {
        $eventBase = $this->createSideTable('_event', "num INT NOT NULL PRIMARY KEY, event_item INT NULL, CONSTRAINT fk_same FOREIGN KEY (event_item) REFERENCES `$this->fullTable` (num)");
        try {
            $warrantyBase = $this->createSideTable('_warranty', "num INT NOT NULL PRIMARY KEY, warranty_item INT NULL, CONSTRAINT fk_same FOREIGN KEY (warranty_item) REFERENCES `$this->fullTable` (num)");
        } catch (mysqli_sql_exception) {
            $this->markTestSkipped('Server needs foreign key names to be unique in the database (every server except MariaDB 12.1+).');
        }

        $this->assertSame([
            DB::$tablePrefix . $eventBase    => ['event_item'],
            DB::$tablePrefix . $warrantyBase => ['warranty_item'],
        ], $this->inboundColsByTable(['_event', '_warranty']));
    }

    #[Test]
    public function foreignKeysReferencingKeepsCompositeKeysFromTwoTablesInOrder(): void
    {
        // unnamed, so MariaDB 12.1+ gives both the name `1` and returns their four rows mixed together
        DB::query("ALTER TABLE `?` ADD UNIQUE INDEX idx_ref (num, sort_date)", $this->fullTable); // MySQL 8.4+ needs UNIQUE here
        $eventBase    = $this->createSideTable('_event', "num INT NOT NULL PRIMARY KEY, e_num INT NULL, e_date DATE NULL, FOREIGN KEY (e_num, e_date) REFERENCES `$this->fullTable` (num, sort_date)");
        $warrantyBase = $this->createSideTable('_warranty', "num INT NOT NULL PRIMARY KEY, w_num INT NULL, w_date DATE NULL, FOREIGN KEY (w_num, w_date) REFERENCES `$this->fullTable` (num, sort_date)");

        $this->assertSame([
            DB::$tablePrefix . $eventBase    => ['e_num', 'e_date'],
            DB::$tablePrefix . $warrantyBase => ['w_num', 'w_date'],
        ], $this->inboundColsByTable(['_event', '_warranty']), "each key's columns in ordinal order, none from the other table");

        foreach (Table::foreignKeysReferencing($this->baseTable) as $fk) {
            if ($fk['fullTable'] !== $this->fullTable) {
                $this->assertSame(['num', 'sort_date'], $fk['refCols'], 'refCols align with cols by position');
            }
        }
    }

    #[Test]
    public function foreignKeysReferencingIncludesKeysFromOtherDatabases(): void
    {
        // a second database can hold a key with the same name, on every server
        $thisDb  = $this->currentDatabase();
        $otherDb = $thisDb . '_fk_other';
        DB::$mysqli->query("DROP DATABASE IF EXISTS `$otherDb`");
        DB::$mysqli->query("CREATE DATABASE `$otherDb`");
        try {
            $childBase  = $this->createSideTable('_child', "num INT NOT NULL PRIMARY KEY, here_num INT NULL, CONSTRAINT fk_cross FOREIGN KEY (here_num) REFERENCES `$this->fullTable` (num)");
            $childTable = DB::$tablePrefix . $childBase;
            DB::$mysqli->query("CREATE TABLE `$otherDb`.`$childTable` (num INT NOT NULL PRIMARY KEY, other_num INT NULL, CONSTRAINT fk_cross FOREIGN KEY (other_num) REFERENCES `$thisDb`.`$this->fullTable` (num)) ENGINE=InnoDB");

            $fkCross = array_values(array_filter(Table::foreignKeysReferencing($this->baseTable), static fn($fk) => $fk['name'] === 'fk_cross'));
            $this->assertSame([
                ['name' => 'fk_cross', 'database' => $thisDb,  'fullTable' => $childTable, 'cols' => ['here_num'],  'refCols' => ['num']],
                ['name' => 'fk_cross', 'database' => $otherDb, 'fullTable' => $childTable, 'cols' => ['other_num'], 'refCols' => ['num']],
            ], $fkCross, 'same name and table name, told apart by database');
        } finally {
            DB::$mysqli->query("DROP DATABASE IF EXISTS `$otherDb`"); // also drops the key, so tearDown can drop the fixture
        }
    }

    //endregion
    //region Column collations after a replay

    #[Test]
    public function replayedColumnsKeepTheServerDefaultInATableWithAChosenCollation(): void
    {
        // changing only the table's default leaves existing columns on the collation the server gave
        // them, and the server now prints it on each one (MySQL 5.7 prints CHARACTER SET alone)
        $sourceBase = $this->baseTable . '_collation';
        $source     = $this->fullTable . '_collation';
        $replay     = $this->fullTable . '_collation_replay';
        DB::$mysqli->query("CREATE TABLE `$source` (name VARCHAR(20) NULL, pinned VARCHAR(20) COLLATE utf8mb4_unicode_ci NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::$mysqli->query("ALTER TABLE `$source` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
        DB::$mysqli->query("ALTER TABLE `$source` ADD COLUMN added_later VARCHAR(20) NULL");
        $serverDefault = $this->columnCollations($source)['name'];

        $normalized = Table::normalizeCreateTable(Table::showCreateTable($sourceBase));
        DB::$mysqli->query(str_replace("`$source`", "`$replay`", $normalized));

        $this->assertSame(
            ['name' => $serverDefault, 'pinned' => $serverDefault, 'added_later' => 'utf8mb4_bin'],
            $this->columnCollations($replay),
            "name and pinned get the server's default, not the table's utf8mb4_bin; added_later inherits the table's",
        );

        // MODIFY COLUMN with the columnDefinitions() string leaves the column on the collation it had
        $nameDefinition = Table::columnDefinitions($sourceBase)['name'];
        DB::$mysqli->query("ALTER TABLE `$source` MODIFY COLUMN name $nameDefinition");
        $this->assertSame($serverDefault, $this->columnCollations($source)['name']);
    }

    //endregion
    //region NOT COVERED (flagged so it isn't assumed tested)

    // exists() name-case behavior is untestable portably: MySQL compares table names
    // case-insensitively or not depending on the server's lower_case_table_names / filesystem,
    // so an assertion either way would fail somewhere. The docblock documents it instead.

    //endregion
    //region Helpers

    /**
     * Each column's collation, as columnName => collation, in table order.
     */
    private function columnCollations(string $fullTable): array
    {
        $rows = DB::$mysqli->query(
            "SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$fullTable'
              ORDER BY ORDINAL_POSITION",
        )->fetch_all(MYSQLI_ASSOC);
        return array_column($rows, 'COLLATION_NAME', 'COLUMN_NAME');
    }

    /**
     * The database the test connection is using.
     */
    private function currentDatabase(): string
    {
        return DB::$mysqli->query("SELECT DATABASE()")->fetch_row()[0];
    }

    /**
     * foreignKeysReferencing() on the fixture, as fullTable => cols, for the side tables with these
     * suffixes only. Fails when the number of entries isn't one per side table.
     */
    private function inboundColsByTable(array $suffixes): array
    {
        $sideTables = array_map(fn($suffix) => $this->fullTable . $suffix, $suffixes);
        $inbound    = array_filter(Table::foreignKeysReferencing($this->baseTable), static fn($fk) => in_array($fk['fullTable'], $sideTables, true));
        $this->assertCount(count($suffixes), $inbound, 'one entry per foreign key');
        return array_column($inbound, 'cols', 'fullTable');
    }

    /**
     * Assert a subset of an index's boolean flags, naming the index in each failure message.
     *
     * @param array<string, mixed> $index
     * @param array<string, bool>  $expected flag name => expected value
     */
    private function assertFlags(array $index, array $expected): void
    {
        foreach ($expected as $flag => $want) {
            $this->assertSame($want, $index[$flag], "{$index['name']}.$flag");
        }
    }

    //endregion
}
