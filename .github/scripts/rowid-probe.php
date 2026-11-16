#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Check where one database server accepts `_rowid`, the name MySQL and MariaDB take in
 * place of a table's primary key column when that key is one integer column.
 *
 *     php .github/scripts/rowid-probe.php --json=probe.json           # run the checks on one server
 *     php .github/scripts/rowid-probe.php --bench --json=probe.json   # the checks, then the speed test
 *     php .github/scripts/rowid-probe.php --bench-only --lookups=5000 # a short speed test, no checks
 *     php .github/scripts/rowid-probe.php --merge probes/*.json       # one report for every server
 *
 * CMS Builder section tables have one auto-increment integer primary key named `num` or
 * `id`. A helper that can't tell which could write `_rowid = '123'` for both, if every
 * supported server accepts that. This probe records what each server does.
 *
 * Most checks run one statement twice, once written with `_rowid` and once with the real
 * column name, and pass when both give the same rows, the same changed rows and the same
 * warnings. "fail" means the server refused `_rowid` or gave a different answer. Some
 * checks are expected to fail (a table with no primary key, say); the "expect" value says
 * which. A check is "n/a" when the server refuses the statement with the real column name
 * too, or can't create the table the check needs.
 *
 * The CI workflow (.github/workflows/rowid-matrix.yml) runs this against every database
 * image in the matrix.
 *
 * Every table is named zdbrowid_*. With DB_DATABASE set they're created in that database
 * and dropped on exit; without it the probe creates and drops the zdb_rowid_probe
 * database. Section 7 loads the library, so it needs composer install; without it those
 * checks report n/a. Connects with the same DB_* env vars as the test suite (see
 * phpunit.xml.dist). DB_LABEL names the server in the JSON output, e.g. "mariadb:10.6".
 */

require __DIR__ . '/ci-lib.php';

$opts = getopt('', ['json:', 'merge', 'bench', 'bench-only', 'lookups:', 'passes:'], $restIndex);
if (isset($opts['merge'])) {
    mergeReports(array_slice($argv, $restIndex));
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$hostname = (getenv('DB_HOSTNAME') ?: '127.0.0.1') . (getenv('DB_PORT') ? ':' . getenv('DB_PORT') : '');
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_DATABASE') ?: '';
$label    = getenv('DB_LABEL') ?: 'unlabeled';
$jsonPath = $opts['json'] ?? null;
$scratch  = $database === '' ? 'zdb_rowid_probe' : null; // only created and dropped when no database was named

$mysqli = new mysqli($hostname, $username, $password);
$mysqli->set_charset('utf8mb4');
if ($scratch !== null) {
    $mysqli->query("DROP DATABASE IF EXISTS `$scratch`");
    $mysqli->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4");
    $database = $scratch;
}
$mysqli->select_db($database);

$probe  = new RowidProbe($mysqli, 'zdbrowid_', ['hostname' => $hostname, 'username' => $username, 'password' => $password, 'database' => $database]);
$report = ['server' => $label, 'version' => $mysqli->query("SELECT VERSION()")->fetch_row()[0], 'checks' => &$probe->checks, 'bench' => &$probe->bench];

// Drop the tables and write the JSON on every exit path, including a fatal partway
// through, so a broken probe still reports what it collected
register_shutdown_function(static function () use ($mysqli, $probe, $scratch, $jsonPath, &$report) {
    try {
        $probe->dropTables();
        if ($scratch !== null) {
            $mysqli->query("DROP DATABASE IF EXISTS `$scratch`");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the probe tables: {$e->getMessage()}\n");
    }
    if ($jsonPath !== null) {
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n");
        fwrite(STDERR, "Wrote " . count($report['checks']) . " checks to $jsonPath\n");
    }
});

$probe->dropTables();
if (!isset($opts['bench-only'])) {
    $probe->keyShapes();
    $probe->placement();
    $probe->realColumnNamedRowid();
    $probe->values();
    $probe->enginesAndModes();
    $probe->queryPlans();
    $probe->throughZenDB();
}
if (isset($opts['bench']) || isset($opts['bench-only'])) {
    $probe->benchmark(100_000, (int)($opts['lookups'] ?? 100_000), (int)($opts['passes'] ?? 3));
}

echo "## $label ({$report['version']})\n\n";
foreach ($probe->checks as $name => $check) {
    echo "- $name: {$check['status']}" . ($check['detail'] !== '' ? " - {$check['detail']}" : '') . "\n";
}
foreach ($probe->bench['cases'] ?? [] as $case => $arms) {
    echo "- speed, $case: " . benchLine($arms) . "\n";
}

/**
 * The check needs something this server doesn't have. Recorded as "n/a" with the reason.
 */
final class NotApplicable extends RuntimeException
{
}

/**
 * The server accepted the statement but the answer is wrong. Recorded as "fail".
 */
final class CheckFailed extends RuntimeException
{
    /**
     * @param string $detail What happened, for the details list
     * @param string $short  A few characters for the grid cell, e.g. "differs"
     */
    public function __construct(string $detail, public string $short = '')
    {
        parent::__construct($detail);
    }
}

/**
 * The checks and the speed test for one server. Each public method is one section of the report.
 *
 * In SQL passed to this class, `::` before a table name becomes the table prefix, and
 * `{num}` becomes `_rowid` in one run and `num` in the other.
 */
final class RowidProbe
{
    /**
     * ZenDB's default `sqlMode`, which CMS Builder runs under. Every check runs under it unless it says otherwise.
     */
    public const STRICT = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /** @var array<string, array{expect: string, status: string, short: string, detail: string}> check name => result */
    public array $checks = [];

    /** @var array{rows?: int, lookups?: int, passes?: int, queryCache?: string, cases?: array<string, array<string, list<float>>>} */
    public array $bench = [];

    /** @var string|null While set, every check is recorded as n/a with this reason and doesn't run */
    private ?string $skipReason = null;

    /** @var array<string, string> table => the INSERT that fills it, so a check that changes rows can put them back */
    private array $refill = [];

    private int $section  = 0;
    private int $checkNum = 0;

    /**
     * @param mysqli $db        Connection with the probe's database selected
     * @param string $prefix    Start of every table name the probe creates
     * @param array  $zenConfig hostname, username, password and database for DB::connect()
     */
    public function __construct(private mysqli $db, private string $prefix, private array $zenConfig)
    {
        $this->setMode(self::STRICT);
    }

    //region Section 1: key shapes

    /**
     * Which tables have a `_rowid`, and which column it is.
     */
    public function keyShapes(): void
    {
        $this->startSection(1);
        $this->baseTables();

        $this->resolves('primary key `num` INT UNSIGNED AUTO_INCREMENT (the usual CMS Builder table)', 'num', ['num', 'hits'], 'pass');
        $this->resolves('primary key `id` INT UNSIGNED AUTO_INCREMENT', 'id', ['id', 'parentNum'], 'pass');

        foreach (['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'] as $type) {
            $this->check("primary key $type: signed and unsigned, with and without AUTO_INCREMENT", 'pass', function () use ($type) {
                foreach (["$type NOT NULL", "$type NOT NULL AUTO_INCREMENT", "$type UNSIGNED NOT NULL", "$type UNSIGNED NOT NULL AUTO_INCREMENT"] as $definition) {
                    $this->fixture('shape', "CREATE TABLE ::shape (a $definition, b INT NOT NULL, PRIMARY KEY (a)) ENGINE=InnoDB", "INSERT INTO ::shape (a, b) VALUES (1, 10), (2, 20), (3, 30)");
                    try {
                        $column = $this->resolvedColumn('shape', ['a', 'b']);
                    } catch (mysqli_sql_exception $e) {
                        throw new CheckFailed("`a $definition`: error {$e->getCode()}: {$e->getMessage()}", (string)$e->getCode());
                    }
                    if ($column !== 'a') {
                        throw new CheckFailed("`a $definition`: resolves to `$column`", "→ $column");
                    }
                }
                return ['all four resolve to the primary key', ''];
            });
        }

        $this->shape('no primary key and no unique index', 'a INT NOT NULL, b INT NOT NULL', 'fail');
        $this->shape('no primary key, one UNIQUE index on a NOT NULL integer (`b`)', 'a INT NOT NULL, b INT NOT NULL, UNIQUE KEY ub (b)', 'pass');
        $this->shape('no primary key, two UNIQUE NOT NULL integer indexes: columns in the order a, b; indexes created on `b` then `a`', 'a INT NOT NULL, b INT NOT NULL, UNIQUE KEY ub (b), UNIQUE KEY ua (a)', 'pass');
        $this->shape('no primary key, UNIQUE index on a nullable integer', 'a INT NULL, b INT NOT NULL, UNIQUE KEY ua (a)', 'fail');
        $this->shape('no primary key, plain (not unique) index on a NOT NULL integer', 'a INT NOT NULL, b INT NOT NULL, KEY ia (a)', 'fail');
        $this->shape('primary key `a` and a UNIQUE NOT NULL integer index on `b`', 'a INT NOT NULL, b INT NOT NULL, PRIMARY KEY (a), UNIQUE KEY ub (b)', 'pass');
        $this->shape('composite primary key (a, b)', 'a INT NOT NULL, b INT NOT NULL, PRIMARY KEY (a, b)', 'fail');
        $this->shape('composite primary key (a, b) and a UNIQUE NOT NULL integer index on `c`', 'a INT NOT NULL, b INT NOT NULL, c INT NOT NULL, PRIMARY KEY (a, b), UNIQUE KEY uc (c)', 'fail', ['a', 'b', 'c']);
        $this->shape('no primary key, first UNIQUE NOT NULL index is (a, b), second is on integer `c`', 'a INT NOT NULL, b INT NOT NULL, c INT NOT NULL, UNIQUE KEY uab (a, b), UNIQUE KEY uc (c)', 'fail', ['a', 'b', 'c']);
        $this->shape('primary key VARCHAR(50)', 'a VARCHAR(50) NOT NULL, b INT NOT NULL, PRIMARY KEY (a)', 'fail');
        $this->shape('primary key CHAR(36)', 'a CHAR(36) NOT NULL, b INT NOT NULL, PRIMARY KEY (a)', 'fail');
        $this->shape('primary key DECIMAL(10,0)', 'a DECIMAL(10,0) NOT NULL, b INT NOT NULL, PRIMARY KEY (a)', 'fail');
        $this->shape('primary key BINARY(16)', 'a BINARY(16) NOT NULL, b INT NOT NULL, PRIMARY KEY (a)', 'fail');
        $this->shape('primary key VARCHAR(50) and a UNIQUE NOT NULL integer index on `b`', 'a VARCHAR(50) NOT NULL, b INT NOT NULL, PRIMARY KEY (a), UNIQUE KEY ub (b)', 'fail');

        // MySQL 8.0.23+ and MariaDB 10.3+; older servers refuse the CREATE TABLE and report n/a
        $this->skipReason = $this->setupError(fn() => $this->fixture('invisible', "CREATE TABLE ::invisible (a INT INVISIBLE NOT NULL AUTO_INCREMENT, b INT NOT NULL, PRIMARY KEY (a)) ENGINE=InnoDB", "INSERT INTO ::invisible (a, b) VALUES (1, 10), (2, 20), (3, 30)"));
        $this->resolves('primary key is an INVISIBLE column', 'invisible', ['a', 'b'], '');
        $this->same('primary key is an INVISIBLE column: WHERE `_rowid` = 2', "SELECT b FROM ::invisible WHERE {a} = 2", '');
        $this->skipReason = null;

        // MySQL 8.0.30+ adds an invisible `my_row_id` primary key to a table created without one
        $this->skipReason = $this->setupError(function () {
            $this->exec("SET SESSION sql_generate_invisible_primary_key = ON");
            try {
                $this->fixture('gipk', "CREATE TABLE ::gipk (a INT NOT NULL, b INT NOT NULL) ENGINE=InnoDB", "INSERT INTO ::gipk (a, b) VALUES (7, 70), (8, 80), (9, 90)");
            } finally {
                $this->exec("SET SESSION sql_generate_invisible_primary_key = OFF");
            }
        });
        $this->resolves('table created with sql_generate_invisible_primary_key=ON', 'gipk', ['my_row_id', 'a', 'b'], '');
        $this->skipReason = null;
    }

    /**
     * One key-shape check: create table `shape` with these columns and keys, then see which column `_rowid` is.
     */
    private function shape(string $name, string $columnsAndKeys, string $expect, array $columns = ['a', 'b']): void
    {
        $values = ['(1, 10, 100)', '(2, 20, 200)', '(3, 30, 300)'];
        $rows   = implode(', ', array_map(static fn($row) => count($columns) === 3 ? $row : substr($row, 0, strrpos($row, ',')) . ')', $values)); // drop the third value for two columns
        $this->skipReason = $this->setupError(fn() => $this->fixture('shape', "CREATE TABLE ::shape ($columnsAndKeys) ENGINE=InnoDB", "INSERT INTO ::shape (" . implode(', ', $columns) . ") VALUES $rows"));
        $this->resolves($name, 'shape', $columns, $expect);
        $this->skipReason = null;
    }

    //endregion
    //region Section 2: where in a statement it's accepted

    /**
     * Every place in a statement `_rowid` could be written, each compared with the real column name.
     */
    public function placement(): void
    {
        $this->startSection(2);
        $this->baseTables();
        $database = $this->rows("SELECT DATABASE()")[0][0];

        // SELECT
        $this->same('SELECT list', "SELECT {num}, title FROM ::num ORDER BY num");
        $this->check('SELECT list: name of the result column', '', function () {
            $field = $this->exec("SELECT _rowid FROM ::num LIMIT 1")->fetch_fields()[0];
            return ["the result column is named `$field->name` (original column `$field->orgname`)", $field->name];
        });
        $this->same('SELECT list with an alias', "SELECT {num} AS k FROM ::num ORDER BY k");
        $this->same('WHERE, number', "SELECT title FROM ::num WHERE {num} = 3");
        $this->same('WHERE, quoted number', "SELECT title FROM ::num WHERE {num} = '3'");
        $this->same('WHERE, comparison operators', "SELECT title FROM ::num WHERE {num} >= 2 AND {num} < 5 AND {num} <> 3");
        $this->same('ORDER BY', "SELECT title FROM ::num ORDER BY {num} DESC");
        $this->same('WHERE, ORDER BY and LIMIT together', "SELECT title FROM ::num WHERE {num} > 2 ORDER BY {num} LIMIT 2");
        $this->same('GROUP BY', "SELECT {num}, COUNT(*) FROM ::num GROUP BY {num}");
        $this->same('HAVING', "SELECT {num}, COUNT(*) FROM ::num GROUP BY {num} HAVING {num} > 3");
        $this->same('aggregates: MAX, MIN, COUNT, SUM', "SELECT MAX({num}), MIN({num}), COUNT({num}), SUM({num}) FROM ::num");
        $this->same('inside an expression', "SELECT {num} + 1, CONCAT({num}, title) FROM ::num ORDER BY {num}");
        $this->same('IN (...)', "SELECT title FROM ::num WHERE {num} IN (1, 3, 5)");
        $this->same('BETWEEN', "SELECT title FROM ::num WHERE {num} BETWEEN 2 AND 4");
        $this->same('IS NULL', "SELECT title FROM ::num WHERE {num} IS NULL");
        $this->same('IS NOT NULL', "SELECT COUNT(*) FROM ::num WHERE {num} IS NOT NULL");

        // Joins and qualified names
        $this->same('JOIN ... ON, one side: b.parentNum = a._rowid', "SELECT a.title, b.title FROM ::num a LEFT JOIN ::id b ON b.parentNum = a.{num} ORDER BY a.num, b.id");
        $this->same('JOIN ... ON, both sides: a._rowid = b._rowid', "SELECT a.title, b.title FROM ::num a JOIN ::id b ON a.{num} = b.{id} ORDER BY a.num");
        $this->same('table._rowid', "SELECT ::num.{num} FROM ::num WHERE ::num.{num} = 2");
        $this->same('database.table._rowid', "SELECT `$database`.::num.{num} FROM ::num WHERE `$database`.::num.{num} = 2");
        $this->same('alias._rowid', "SELECT a.{num} FROM ::num a WHERE a.{num} = 2");
        $this->same('backticked: `_rowid`', "SELECT `{num}` FROM ::num WHERE `{num}` = 2");
        $this->same('backticked with an alias: a.`_rowid`', "SELECT a.`{num}` FROM ::num a WHERE a.`{num}` = 2");
        $this->same('upper case: _ROWID', "SELECT {num} FROM ::num WHERE {num} = 2", spelling: '_ROWID');
        $this->same('mixed case: _RowId', "SELECT {num} FROM ::num WHERE {num} = 2", spelling: '_RowId');
        $this->works('unqualified `_rowid` in a join of two tables that each have one', "SELECT a.title FROM ::num a JOIN ::id b ON b.parentNum = a.num WHERE _rowid = 2", 'fail');
        $this->same('unqualified `_rowid` in a join where the other table has no key', "SELECT title FROM ::num JOIN ::nokey ON ::nokey.a = ::num.num WHERE {num} = 2", '');

        // Subqueries, derived tables, UNION, CTE, views
        $this->same('subquery in WHERE, `_rowid` inside and outside', "SELECT title FROM ::num WHERE {num} IN (SELECT parentNum FROM ::id WHERE {id} < 3)");
        $this->same('correlated subquery in the SELECT list', "SELECT b.title, (SELECT a.title FROM ::num a WHERE a.{num} = b.parentNum) FROM ::id b ORDER BY b.id");
        $this->same('unqualified `_rowid` inside a subquery on a table with no key: reads the outer table', "SELECT a.title FROM ::num a WHERE EXISTS (SELECT 1 FROM ::nokey n WHERE n.a = {num}) ORDER BY a.num", '');
        $this->check('unqualified `_rowid` inside a subquery on a table that has its own: which table it reads', '', function () {
            // ::id has one row with parentNum = 3, and its id is 3. Read as the inner key the EXISTS is true for all 5 outer rows; read as the outer key, for 1
            $count = count($this->rows("SELECT a.num FROM ::num a WHERE EXISTS (SELECT 1 FROM ::id b WHERE b.parentNum = 3 AND _rowid = 3)"));
            return match ($count) {
                5       => ['reads the inner table, the one in the subquery\'s own FROM', 'inner table'],
                1       => ['reads the outer table', 'outer table'],
                default => throw new CheckFailed("unexpected row count: $count", 'unexpected'),
            };
        });
        $this->same('derived table, `_rowid` inside it', "SELECT d.k, d.title FROM (SELECT {num} AS k, title FROM ::num WHERE {num} > 1) d ORDER BY d.k");
        $this->same('derived table, `_rowid` in the outer query: (SELECT num, title FROM t) d WHERE d._rowid = 2', "SELECT d.title FROM (SELECT num, title FROM ::num) d WHERE d.{num} = 2", 'fail');
        $this->works('derived table that selects `_rowid` with no alias, read as d._rowid outside', "SELECT d._rowid FROM (SELECT _rowid, title FROM ::num) d WHERE d._rowid = 2", '');
        $this->same('UNION branches', "(SELECT {num}, title FROM ::num WHERE {num} = 1) UNION ALL (SELECT {id}, title FROM ::id WHERE {id} = 2)");
        $this->same('CTE (MySQL 8.0+, MariaDB 10.2.1+)', "WITH c AS (SELECT {num} AS k, title FROM ::num WHERE {num} < 3) SELECT k, title FROM c ORDER BY k");
        $this->check('view whose definition uses `_rowid`', '', function () {
            $this->exec("DROP VIEW IF EXISTS ::view_def");
            $this->exec("CREATE VIEW ::view_def AS SELECT _rowid AS k, title FROM ::num WHERE _rowid > 1");
            $viewRows = $this->rows("SELECT k, title FROM ::view_def ORDER BY k");
            if ($viewRows !== $this->rows("SELECT num, title FROM ::num WHERE num > 1 ORDER BY num")) {
                throw new CheckFailed("the view returns different rows: " . json_encode($viewRows, JSON_THROW_ON_ERROR), 'differs');
            }
            $definition = $this->rows("SHOW CREATE VIEW ::view_def")[0][1];
            $storedAs   = stripos($definition, '_rowid') !== false ? '_rowid' : 'num';
            return ["the view returns the right rows; SHOW CREATE VIEW prints the column as `$storedAs`", "stored as $storedAs"];
        });
        $this->exec("DROP VIEW IF EXISTS ::view_plain");
        $this->exec("CREATE VIEW ::view_plain AS SELECT num, title FROM ::num");
        $this->same('`_rowid` on a view: view is SELECT num, title FROM t', "SELECT title FROM ::view_plain WHERE {num} = 2", 'fail');

        // UPDATE and DELETE conditions
        $this->same('UPDATE ... WHERE _rowid = 3', "UPDATE ::num SET title = 'changed' WHERE {num} = 3", changes: 'num');
        $this->same("UPDATE ... WHERE _rowid = '3'", "UPDATE ::num SET title = 'changed' WHERE {num} = '3'", changes: 'num');
        $this->same('UPDATE ... ORDER BY _rowid DESC LIMIT 2', "UPDATE ::num SET hits = hits + 1 ORDER BY {num} DESC LIMIT 2", changes: 'num');
        $this->same('UPDATE with a join: ON b.parentNum = a._rowid ... WHERE b._rowid = 3', "UPDATE ::num a JOIN ::id b ON b.parentNum = a.{num} SET a.hits = 99 WHERE b.{id} = 3", changes: 'num');
        $this->same('DELETE ... WHERE _rowid = 3', "DELETE FROM ::num WHERE {num} = 3", changes: 'num');
        $this->same("DELETE ... WHERE _rowid = '3'", "DELETE FROM ::num WHERE {num} = '3'", changes: 'num');
        $this->same('DELETE ... ORDER BY _rowid DESC LIMIT 1', "DELETE FROM ::num ORDER BY {num} DESC LIMIT 1", changes: 'num');
        $this->same('DELETE with a join: ON b.parentNum = a._rowid ... WHERE b._rowid = 3', "DELETE a FROM ::num a JOIN ::id b ON b.parentNum = a.{num} WHERE b.{id} = 3", changes: 'num');

        // Writing to it
        $this->same('UPDATE ... SET _rowid = 50', "UPDATE ::num SET {num} = 50 WHERE num = 5", '', changes: 'num');
        $this->same('INSERT (_rowid, title) VALUES (...)', "INSERT INTO ::num ({num}, title) VALUES (60, 'sixty')", '', changes: 'num');
        $this->same('INSERT ... SET _rowid = 61', "INSERT INTO ::num SET {num} = 61, title = 'sixty-one'", '', changes: 'num');

        // Prepared statements
        $this->same('prepared SELECT, integer parameter, run twice', "SELECT title FROM ::num WHERE {num} = ?", bind: ['i', [3], [4]]);
        $this->same('prepared SELECT, string parameter', "SELECT title FROM ::num WHERE {num} = ?", bind: ['s', ['3']]);
        $this->same('prepared UPDATE ... WHERE _rowid = ?', "UPDATE ::num SET title = ? WHERE {num} = ?", bind: ['si', ['changed', 3]], changes: 'num');
        $this->same('prepared DELETE ... WHERE _rowid = ?', "DELETE FROM ::num WHERE {num} = ?", bind: ['i', [3]], changes: 'num');

        // Not a listed column
        $this->check('SELECT * leaves `_rowid` out', 'pass', function () {
            $names = array_column($this->exec("SELECT * FROM ::num LIMIT 1")->fetch_fields(), 'name');
            if (in_array('_rowid', array_map('strtolower', $names), true)) {
                throw new CheckFailed("SELECT * returned: " . implode(', ', $names), 'listed');
            }
            return ['SELECT * returns ' . implode(', ', $names), ''];
        });
        $this->check('SHOW COLUMNS and information_schema.COLUMNS leave `_rowid` out', 'pass', function () {
            $shown  = array_column($this->rows("SHOW COLUMNS FROM ::num"), 0);
            $listed = array_column($this->rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '::num' ORDER BY ORDINAL_POSITION"), 0);
            if (in_array('_rowid', array_map('strtolower', [...$shown, ...$listed]), true)) {
                throw new CheckFailed("SHOW COLUMNS: " . implode(', ', $shown) . "; information_schema: " . implode(', ', $listed), 'listed');
            }
            return ['both list ' . implode(', ', $shown), ''];
        });
    }

    //endregion
    //region Section 3: a real column named _rowid

    /**
     * Whether a table can have its own column named `_rowid`, and which one a query then reads.
     */
    public function realColumnNamedRowid(): void
    {
        $this->startSection(3);

        $accepted = static fn() => ['accepted', ''];
        $this->exec("DROP TABLE IF EXISTS ::real1, ::real2, ::real3, ::real4, ::real5");
        $this->works('CREATE TABLE with an ordinary column named _rowid, beside primary key `num`', "CREATE TABLE ::real1 (num INT UNSIGNED NOT NULL AUTO_INCREMENT, _rowid INT NOT NULL, PRIMARY KEY (num)) ENGINE=InnoDB", '', $accepted);
        $this->works('CREATE TABLE with the same column, name in backticks', "CREATE TABLE ::real2 (num INT UNSIGNED NOT NULL AUTO_INCREMENT, `_rowid` INT NOT NULL, PRIMARY KEY (num)) ENGINE=InnoDB", '', $accepted);
        $this->exec("CREATE TABLE ::real3 (num INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(50), PRIMARY KEY (num)) ENGINE=InnoDB");
        $this->works('ALTER TABLE ADD COLUMN _rowid INT, on a table with primary key `num`', "ALTER TABLE ::real3 ADD COLUMN _rowid INT NOT NULL DEFAULT 0", '', $accepted);
        $this->works('CREATE TABLE with _rowid as the AUTO_INCREMENT primary key', "CREATE TABLE ::real4 (_rowid INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(50), PRIMARY KEY (_rowid)) ENGINE=InnoDB", '', $accepted);
        $this->exec("CREATE TABLE ::real5 (title VARCHAR(50)) ENGINE=InnoDB");
        $this->works('ALTER TABLE ADD COLUMN _rowid ... AUTO_INCREMENT PRIMARY KEY, on a table with no key', "ALTER TABLE ::real5 ADD COLUMN _rowid INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY", '', $accepted);

        // Row num=5 holds 99 in the real column, and row num=7 holds 5 there
        $this->skipReason = $this->setupError(fn() => $this->fixture('real', "CREATE TABLE ::real (num INT UNSIGNED NOT NULL AUTO_INCREMENT, `_rowid` INT NOT NULL, PRIMARY KEY (num)) ENGINE=InnoDB", "INSERT INTO ::real (num, `_rowid`) VALUES (5, 99), (7, 5)"));
        $whichOne = function (string $sql, string $ifPrimaryKey, string $ifRealColumn): array {
            $answer = (string)($this->rows($sql)[0][0] ?? 'no row');
            return match ($answer) {
                $ifPrimaryKey => ['uses the primary key `num`, not the real `_rowid` column', 'primary key'],
                $ifRealColumn => ['uses the real `_rowid` column, not the primary key `num`', 'real column'],
                default       => throw new CheckFailed("unexpected answer: $answer", 'unexpected'),
            };
        };
        $this->check('real `_rowid` column beside primary key `num`: WHERE _rowid = 5', '', fn() => $whichOne("SELECT num FROM ::real WHERE _rowid = 5", '5', '7'));
        $this->check('same table: WHERE `_rowid` = 5, in backticks', '', fn() => $whichOne("SELECT num FROM ::real WHERE `_rowid` = 5", '5', '7'));
        $this->check('same table: SELECT _rowid ... WHERE num = 5', '', fn() => $whichOne("SELECT _rowid FROM ::real WHERE num = 5", '5', '99'));
        $this->check('same table: UPDATE ... SET _rowid = 1 WHERE num = 7', '', function () use ($whichOne) {
            $this->exec("UPDATE ::real SET _rowid = 1 WHERE num = 7");
            return $whichOne("SELECT CONCAT(num, '/', `_rowid`) FROM ::real ORDER BY num LIMIT 1", '1/5', '5/99'); // the first row is 1/5 if the key changed from 7 to 1
        });
        $this->skipReason = null;
    }

    //endregion
    //region Section 4: values compared against it

    /**
     * What each kind of value does when compared with `_rowid`, against the same comparison with the real column.
     */
    public function values(): void
    {
        $this->startSection(4);
        $this->baseTables();

        $statements = [
            'SELECT' => ["SELECT title FROM ::num WHERE {num} = {value}", null],
            'UPDATE' => ["UPDATE ::num SET hits = hits + 1 WHERE {num} = {value}", 'num'],
            'DELETE' => ["DELETE FROM ::num WHERE {num} = {value}", 'num'],
        ];
        $literals = ['5', "'5'", "'5abc'", "''", "'abc'", 'NULL', '-1', "'-1'", '9999999999', "'9999999999'"];
        foreach ($literals as $literal) {
            $this->check("_rowid = $literal: SELECT, UPDATE and DELETE, strict and non-strict sql_mode", 'pass', function () use ($statements, $literal) {
                $outcomes = []; // verb => [mode name => what happened]
                try {
                    foreach (['strict' => self::STRICT, 'non-strict' => ''] as $modeName => $mode) {
                        $this->setMode($mode);
                        foreach ($statements as $verb => [$template, $changes]) {
                            try {
                                $outcomes[$verb][$modeName] = $this->compare(str_replace('{value}', $literal, $template), 'behavior', null, $changes);
                            } catch (CheckFailed $e) {
                                throw new CheckFailed("$modeName $verb: {$e->getMessage()}", $e->short);
                            }
                        }
                    }
                } finally {
                    $this->setMode(self::STRICT);
                }
                $lines = [];
                foreach ($outcomes as $verb => $byMode) {
                    $lines[] = "$verb: " . ($byMode['strict'] === $byMode['non-strict'] ? $byMode['strict'] : "strict: {$byMode['strict']}, non-strict: {$byMode['non-strict']}");
                }
                return ['same as the real column. ' . implode('; ', $lines), ''];
            });
        }

        // CMS Builder uses 9999999999 as a "no such record" number. It must match nothing and raise no error.
        $this->fixture('signed', "CREATE TABLE ::signed (num INT NOT NULL AUTO_INCREMENT, title VARCHAR(50) NOT NULL, hits INT NOT NULL DEFAULT 0, PRIMARY KEY (num)) ENGINE=InnoDB", "INSERT INTO ::signed (num, title) VALUES (1, 'one'), (2, 'two'), (3, 'three')");
        foreach (['num' => 'INT UNSIGNED', 'signed' => 'INT'] as $table => $type) {
            $this->check("_rowid = '9999999999' against an $type key: zero rows and no error, as a plain and a prepared statement, strict and non-strict", 'pass', function () use ($table) {
                $runs     = 0;
                $warnings = [];
                try {
                    foreach (['strict' => self::STRICT, 'non-strict' => ''] as $modeName => $mode) {
                        $this->setMode($mode);
                        $cases = [
                            'SELECT, quoted'                   => ["SELECT title FROM ::$table WHERE _rowid = '9999999999'", null],
                            'SELECT, unquoted'                 => ["SELECT title FROM ::$table WHERE _rowid = 9999999999", null],
                            'SELECT, prepared, string'         => ["SELECT title FROM ::$table WHERE _rowid = ?", ['s', ['9999999999']]],
                            'SELECT, prepared, integer'        => ["SELECT title FROM ::$table WHERE _rowid = ?", ['i', [9999999999]]],
                            'UPDATE, quoted'                   => ["UPDATE ::$table SET hits = hits + 1 WHERE _rowid = '9999999999'", null],
                            'UPDATE, prepared, string'         => ["UPDATE ::$table SET hits = hits + 1 WHERE _rowid = ?", ['s', ['9999999999']]],
                            'DELETE, quoted'                   => ["DELETE FROM ::$table WHERE _rowid = '9999999999'", null],
                            'DELETE, prepared, string'         => ["DELETE FROM ::$table WHERE _rowid = ?", ['s', ['9999999999']]],
                        ];
                        foreach ($cases as $caseName => [$sql, $bind]) {
                            $outcome = $this->outcome($sql, $bind);
                            $count   = isset($outcome['rows']) ? count($outcome['rows']) : ($outcome['affected'] ?? null);
                            if (isset($outcome['error']) || $count !== 0) {
                                throw new CheckFailed("$modeName, $caseName: " . $this->describe($outcome), isset($outcome['error']) ? (string)$outcome['error'] : 'rows');
                            }
                            foreach ($outcome['warnings'] as $warning) {
                                $warnings["$modeName, $caseName: warning $warning"] = true;
                            }
                            $runs++;
                        }
                    }
                } finally {
                    $this->setMode(self::STRICT);
                }
                return ["zero rows and no error in all $runs statements" . ($warnings ? '; ' . implode('; ', array_keys($warnings)) : ', no warnings'), ''];
            });
        }
    }

    //endregion
    //region Section 5: engines and modes

    /**
     * Storage engines, sql_mode settings, and partitioned and temporary tables.
     */
    public function enginesAndModes(): void
    {
        $this->startSection(5);
        $this->baseTables();

        $columns = "(num INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(50) NOT NULL, hits INT NOT NULL DEFAULT 0, PRIMARY KEY (num))";
        $insert  = "INSERT INTO ::engine (num, title, hits) VALUES (1, 'one', 10), (2, 'two', 20), (3, 'three', 30)";
        foreach (['InnoDB', 'MyISAM', 'MEMORY', 'Aria'] as $engine) {
            $this->skipReason = $this->setupError(function () use ($engine, $columns, $insert) {
                $this->fixture('engine', "CREATE TABLE ::engine $columns ENGINE=$engine", $insert);
                $actual = $this->rows("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '::engine'")[0][0];
                if (strcasecmp($actual, $engine) !== 0) {
                    throw new NotApplicable("no $engine engine: the server created the table as $actual");
                }
            });
            $this->tableWorks("$engine table: resolves, and SELECT, UPDATE and DELETE by `_rowid` match the real column", 'engine');
        }

        $this->skipReason = $this->setupError(fn() => $this->fixture('engine', "CREATE TABLE ::engine $columns ENGINE=InnoDB PARTITION BY HASH (num) PARTITIONS 4", $insert));
        $this->tableWorks('partitioned table (HASH on the key, 4 partitions): resolves, and SELECT, UPDATE and DELETE match', 'engine');
        $this->samePlan('partitioned table: EXPLAIN reads the same partitions', "SELECT title FROM ::engine WHERE {num} = 2", partitions: true);

        $this->exec("DROP TABLE IF EXISTS ::engine");
        $this->skipReason = $this->setupError(fn() => $this->fixture('engine', "CREATE TEMPORARY TABLE ::engine $columns ENGINE=InnoDB", $insert));
        $this->tableWorks('temporary table: resolves, and SELECT, UPDATE and DELETE match', 'engine');
        $this->skipReason = null;
        $this->exec("DROP TEMPORARY TABLE IF EXISTS ::engine");

        try {
            $this->setMode(self::STRICT . ',ANSI_QUOTES');
            $this->same('sql_mode ANSI_QUOTES: WHERE _rowid = 2', "SELECT title FROM ::num WHERE {num} = 2");
            $this->same('sql_mode ANSI_QUOTES: WHERE "_rowid" = 2, in double quotes', 'SELECT title FROM ::num WHERE "{num}" = 2');

            $this->setMode(self::STRICT . ',ONLY_FULL_GROUP_BY');
            $this->same('sql_mode ONLY_FULL_GROUP_BY: SELECT _rowid, COUNT(*) ... GROUP BY _rowid', "SELECT {num}, COUNT(*) FROM ::num GROUP BY {num}");
            $this->same('sql_mode ONLY_FULL_GROUP_BY: SELECT num, COUNT(*) ... GROUP BY _rowid', "SELECT num, COUNT(*) FROM ::num GROUP BY {num}");
            $this->same('sql_mode ONLY_FULL_GROUP_BY: SELECT _rowid, COUNT(*) ... GROUP BY num', "SELECT {num}, COUNT(*) FROM ::num GROUP BY num");
            $this->same('sql_mode ONLY_FULL_GROUP_BY: SELECT _rowid, title ... GROUP BY _rowid (title depends on the key)', "SELECT {num}, title FROM ::num GROUP BY {num}");

            $this->setMode('');
            $this->same("non-strict sql_mode (''): WHERE _rowid = '2'", "SELECT title FROM ::num WHERE {num} = '2'");
            $this->setMode('TRADITIONAL');
            $this->same("sql_mode TRADITIONAL: WHERE _rowid = '2'", "SELECT title FROM ::num WHERE {num} = '2'");
        } finally {
            $this->setMode(self::STRICT);
        }
    }

    /**
     * One check that a table has a `_rowid` and that SELECT, UPDATE and DELETE by it match the real column `num`.
     */
    private function tableWorks(string $name, string $table): void
    {
        $this->check($name, 'pass', function () use ($table) {
            $column = $this->resolvedColumn($table, ['num', 'hits']);
            if ($column !== 'num') {
                throw new CheckFailed("resolves to `$column`", "→ $column");
            }
            $this->compare("SELECT title FROM ::$table WHERE {num} = 2");
            $this->compare("UPDATE ::$table SET title = 'changed' WHERE {num} = 2", changes: $table);
            $this->compare("DELETE FROM ::$table WHERE {num} = 2", changes: $table);
            return ['', ''];
        });
    }

    //endregion
    //region Section 6: query plans

    /**
     * EXPLAIN for the same statement written with `_rowid` and with the real column, on a 10,000 row table.
     */
    public function queryPlans(): void
    {
        $this->startSection(6);
        $this->baseTables();
        $this->bigTable('plan', 10_000);

        $this->samePlan('EXPLAIN point lookup: WHERE _rowid = 5000', "SELECT title FROM ::plan WHERE {num} = 5000");
        $this->samePlan("EXPLAIN point lookup, quoted: WHERE _rowid = '5000'", "SELECT title FROM ::plan WHERE {num} = '5000'");
        $this->samePlan("EXPLAIN number no INT can hold: WHERE _rowid = '9999999999'", "SELECT title FROM ::plan WHERE {num} = '9999999999'");
        $this->samePlan('EXPLAIN IN list: WHERE _rowid IN (10, 500, 9000)', "SELECT title FROM ::plan WHERE {num} IN (10, 500, 9000)");
        $this->samePlan('EXPLAIN range: WHERE _rowid BETWEEN 100 AND 200', "SELECT title FROM ::plan WHERE {num} BETWEEN 100 AND 200");
        $this->samePlan('EXPLAIN range: WHERE _rowid > 9990', "SELECT title FROM ::plan WHERE {num} > 9990");
        $this->samePlan('EXPLAIN ORDER BY _rowid DESC LIMIT 10', "SELECT title FROM ::plan ORDER BY {num} DESC LIMIT 10");
        $this->samePlan('EXPLAIN WHERE _rowid > 5000 ORDER BY _rowid LIMIT 10', "SELECT title FROM ::plan WHERE {num} > 5000 ORDER BY {num} LIMIT 10");
        $this->samePlan('EXPLAIN MAX(_rowid)', "SELECT MAX({num}) FROM ::plan");
        $this->samePlan('EXPLAIN join: ON b.parentNum = a._rowid', "SELECT a.title FROM ::id b JOIN ::plan a ON b.parentNum = a.{num}");
        $this->samePlan('EXPLAIN UPDATE ... WHERE _rowid = 5000', "UPDATE ::plan SET hits = hits + 1 WHERE {num} = 5000");
        $this->samePlan('EXPLAIN DELETE ... WHERE _rowid = 5000', "DELETE FROM ::plan WHERE {num} = 5000");

        // EXPLAIN is the plan; the Handler_read counters are what the server did when it ran the query
        $this->check('rows read when the query runs (Handler_read counters): point lookup, range, ORDER BY ... LIMIT', 'pass', function () {
            $templates = [
                'point lookup' => "SELECT title FROM ::plan WHERE {num} = '5000'",
                'range'        => "SELECT title FROM ::plan WHERE {num} BETWEEN 100 AND 200",
                'ORDER BY'     => "SELECT title FROM ::plan ORDER BY {num} DESC LIMIT 10",
            ];
            $lines = [];
            foreach ($templates as $label => $template) {
                $real  = $this->handlerReads($this->spell($template));
                $rowid = $this->handlerReads($this->spell($template, '_rowid'));
                if ($rowid !== $real) {
                    throw new CheckFailed("$label: `_rowid` $rowid; real column $real", 'differs');
                }
                $lines[] = "$label: $rowid";
            }
            return [implode('; ', $lines), ''];
        });
    }

    //endregion
    //region Section 7: through ZenDB

    /**
     * Whether ZenDB's own methods take `_rowid` as a column name. Each call runs with `_rowid` and with `num`.
     */
    public function throughZenDB(): void
    {
        $this->startSection(7);
        $this->baseTables();

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
            \Itools\ZenDB\DB::connect($this->zenConfig + ['tablePrefix' => $this->prefix, 'connectTimeout' => 5]);
        } else {
            $this->skipReason = 'vendor/autoload.php not found (composer install has not run)';
        }

        $this->check('DB::isIdentifier() accepts the name', 'pass', static function () {
            if (!\Itools\ZenDB\DB::isIdentifier('_rowid')) {
                throw new CheckFailed('isIdentifier() returned false', 'rejected');
            }
            return ['', ''];
        });

        // Conditions
        $this->zen("select(), array where: ['_rowid' => 3]", static fn($col) => \Itools\ZenDB\DB::select('num', [$col => 3]));
        $this->zen("select(), array where, string value: ['_rowid' => '3']", static fn($col) => \Itools\ZenDB\DB::select('num', [$col => '3']));
        $this->zen("select(), array where, IN list: ['_rowid' => [1, 3]]", static fn($col) => \Itools\ZenDB\DB::select('num', [$col => [1, 3]]));
        $this->zen("select(), string where with ?: '_rowid = ?'", static fn($col) => \Itools\ZenDB\DB::select('num', "$col = ?", 3));
        $this->zen("select(), string where with a named placeholder: '_rowid = :num'", static fn($col) => \Itools\ZenDB\DB::select('num', "$col = :num", [':num' => 3]));
        $this->zen("select(), backtick placeholder: '`?` = ?', '_rowid', 3", static fn($col) => \Itools\ZenDB\DB::select('num', "`?` = ?", $col, 3));
        $this->zen("select(), 'ORDER BY _rowid DESC'", static fn($col) => \Itools\ZenDB\DB::select('num', "ORDER BY $col DESC"));
        $this->zen("select(), 'ORDER BY `:col` DESC' with [':col' => '_rowid']", static fn($col) => \Itools\ZenDB\DB::select('num', "ORDER BY `:col` DESC", [':col' => $col]));
        $this->zen("selectOne(), array where", static fn($col) => \Itools\ZenDB\DB::selectOne('num', [$col => 3]));
        $this->zen("count(), array where", static fn($col) => \Itools\ZenDB\DB::count('num', [$col => 3]));
        $this->zen("select(), array where, the CMS marker: ['_rowid' => '9999999999']", static fn($col) => \Itools\ZenDB\DB::select('num', [$col => '9999999999']));
        $this->zen("update(), array where: ['_rowid' => 3]", static fn($col) => \Itools\ZenDB\DB::update('num', ['title' => 'changed'], [$col => 3]), 'num');
        $this->zen("update(), string where: '_rowid = ?'", static fn($col) => \Itools\ZenDB\DB::update('num', ['title' => 'changed'], "$col = ?", 3), 'num');
        $this->zen("delete(), array where: ['_rowid' => 3]", static fn($col) => \Itools\ZenDB\DB::delete('num', [$col => 3]), 'num');
        $this->zen("delete(), string where: '_rowid = ?'", static fn($col) => \Itools\ZenDB\DB::delete('num', "$col = ?", 3), 'num');
        $this->zen("query() with a join: ON b.parentNum = a._rowid", static fn($col) => \Itools\ZenDB\DB::query("SELECT a.title, b.title AS child FROM ::num a JOIN ::id b ON b.parentNum = a.$col ORDER BY b.id"));

        // Writing to it
        $this->zen("insert(), values: ['_rowid' => 70, ...]", static fn($col) => \Itools\ZenDB\DB::insert('num', [$col => 70, 'title' => 'seventy']), 'num', '');
        $this->zen("update(), values: ['_rowid' => 50, 'title' => ...]", static fn($col) => \Itools\ZenDB\DB::update('num', [$col => 50, 'title' => 'fifty'], ['num' => 5]), 'num', '');
        $this->check("update(), values: ['_rowid' => 50] alone (ZenDB refuses a lone `num` or `id` there)", '', function () {
            $this->reset('num');
            try {
                \Itools\ZenDB\DB::update('num', ['_rowid' => 50], ['num' => 5]);
            } catch (Throwable $e) {
                return ['threw ' . get_class($e) . ": {$e->getMessage()}", 'throws'];
            } finally {
                $changed = $this->rows("SELECT COUNT(*) FROM ::num WHERE num = 50")[0][0];
                $this->reset('num');
            }
            return ["ran: " . \Itools\ZenDB\DB::$mysqli->lastQuery . "; rows with num = 50 afterwards: $changed", 'runs'];
        });

        // Result keys
        $this->check('query(): result keys for SELECT _rowid, title', '', static function () {
            $row = \Itools\ZenDB\DB::query("SELECT _rowid, title FROM ::num WHERE _rowid = ?", 3)->first()->toArray();
            return ['keys: ' . implode(', ', array_keys($row)), implode(',', array_keys($row))];
        });
        $this->check('query() with a join: result keys for SELECT a._rowid, b._rowid, with smart joins on', '', static function () {
            $row = \Itools\ZenDB\DB::query("SELECT a._rowid, b._rowid, a.title FROM ::num a JOIN ::id b ON b.parentNum = a._rowid ORDER BY b.id")->first()->toArray();
            return ['keys: ' . implode(', ', array_keys($row)), ''];
        });

        $this->skipReason = null;
    }

    /**
     * One ZenDB check: run the call with `num` and with `_rowid`, and pass when both return the same thing.
     *
     * @param callable(string): mixed $call    Gets the column name to use
     * @param string|null             $changes Table the call changes; refilled before each run and compared after
     */
    private function zen(string $name, callable $call, ?string $changes = null, string $expect = 'pass'): void
    {
        $this->check($name, $expect, function () use ($call, $changes) {
            $run = function (string $column) use ($call, $changes): array {
                if ($changes !== null) {
                    $this->reset($changes);
                }
                try {
                    $result = $call($column);
                    $out    = ['result' => is_object($result) ? $result->toArray() : $result, 'query' => \Itools\ZenDB\DB::$mysqli->lastQuery];
                } catch (Throwable $e) {
                    $out = ['threw' => get_class($e) . ": {$e->getMessage()}"];
                }
                if ($changes !== null) {
                    $out['state'] = $this->rows("SELECT * FROM ::$changes ORDER BY 1");
                    $this->reset($changes);
                }
                return $out;
            };
            $real  = $run('num');
            $rowid = $run('_rowid');
            if (isset($real['threw'])) {
                throw new NotApplicable("the same call with `num` also throws: {$real['threw']}");
            }
            if (isset($rowid['threw'])) {
                throw new CheckFailed("threw {$rowid['threw']}", 'throws');
            }
            if ($rowid['result'] !== $real['result'] || ($rowid['state'] ?? null) !== ($real['state'] ?? null)) {
                throw new CheckFailed("`_rowid` returned " . json_encode($rowid['result'], JSON_THROW_ON_ERROR) . "; `num` returned " . json_encode($real['result'], JSON_THROW_ON_ERROR), 'differs');
            }
            return ["ran: {$rowid['query']}", ''];
        });
    }

    //endregion
    //region Speed test

    /**
     * Time point lookups written with `_rowid` and with the real column name, each bare and in backticks.
     *
     * Every case has several arms that run the same record numbers. The first arm is the
     * baseline, and the last arm is the baseline's statement a second time ("control").
     * The control shows how far two identical queries differ on this machine, which is the
     * size a difference in another arm has to beat to mean anything.
     * The arms take turns in blocks of 500 lookups and change order every block, so slow
     * and fast moments on the server are shared between them.
     *
     * The last case selects a real column named `_label`, to show whether a bare name that
     * starts with an underscore costs more for any column or only for `_rowid`.
     *
     * @param int $rowCount Rows in the table
     * @param int $lookups  Lookups per arm in each pass
     * @param int $passes   Timed passes; each case also runs one shorter pass first that isn't counted
     */
    public function benchmark(int $rowCount, int $lookups, int $passes): void
    {
        $this->bigTable('bench', $rowCount, withLabel: true);
        $table = $this->prefix . 'bench';
        $db    = $this->db;

        mt_srand(20261116); // the same record numbers on every server
        $ids = [];
        while (count($ids) < $lookups) {
            $ids[] = mt_rand(1, $rowCount);
        }

        // Three ways to run a statement. Each takes the SQL and returns a function that looks up one record
        $plain = static fn(string $sql): Closure => static function (int $id) use ($db, $sql): void {
            $result = $db->query(sprintf($sql, $id));
            $result->fetch_row();
            $result->free();
        };
        $preparedOnce = static function (string $sql) use ($db): Closure {
            $id   = 0;
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $id);
            return static function (int $next) use ($stmt, &$id): void {
                $id = $next;
                $stmt->execute();
                $result = $stmt->get_result();
                $result->fetch_row();
                $result->free();
            };
        };
        $preparedEachTime = static fn(string $sql): Closure => static function (int $id) use ($db, $sql): void {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $result->fetch_row();
            $result->free();
            $stmt->close();
        };

        // arm name => SQL. The arm name is the part of the statement that differs
        $byKey = static fn(string $value): array => [
            'num'           => "SELECT title FROM $table WHERE num = $value",
            '`num`'         => "SELECT title FROM $table WHERE `num` = $value",
            '_rowid'        => "SELECT title FROM $table WHERE _rowid = $value",
            '`_rowid`'      => "SELECT title FROM $table WHERE `_rowid` = $value",
            'table._rowid'  => "SELECT title FROM $table WHERE $table._rowid = $value",
            'num (control)' => "SELECT title FROM $table WHERE num = $value",
        ];
        $selectLabel = [
            'title'           => "SELECT title FROM $table WHERE num = '%d'",
            '_label'          => "SELECT _label FROM $table WHERE num = '%d'",
            '`_label`'        => "SELECT `_label` FROM $table WHERE num = '%d'",
            'table._label'    => "SELECT $table._label FROM $table WHERE num = '%d'",
            'title (control)' => "SELECT title FROM $table WHERE num = '%d'",
        ];
        $cases = [
            'plain query'                                                               => [$plain, $byKey("'%d'")],
            'prepared statement, prepared once and run for every lookup'                => [$preparedOnce, $byKey('?')],
            'prepared statement, prepared again for every lookup'                       => [$preparedEachTime, $byKey('?')],
            'plain query that selects a real column named `_label` (same text as title)' => [$plain, $selectLabel],
        ];

        // MariaDB images thru 10.4 start with the query cache on. A cached result comes back before the
        // statement is parsed, so the arms would time the cache and not the spelling
        try {
            [$cacheType, $cacheSize] = $this->rows("SELECT @@query_cache_type, @@query_cache_size")[0];
            $this->exec("SET SESSION query_cache_type = OFF");
            $queryCache = $cacheType === 'OFF' || (int)$cacheSize === 0 ? 'off' : "on ($cacheSize bytes), turned off for this session";
        } catch (mysqli_sql_exception) {
            $queryCache = 'none'; // MySQL 8.0+ has no query cache
        }

        $this->bench = ['rows' => $rowCount, 'lookups' => $lookups, 'passes' => $passes, 'queryCache' => $queryCache, 'cases' => []];
        foreach ($cases as $caseName => [$build, $sqlByArm]) {
            $lookup = array_map($build, $sqlByArm);
            $arms   = array_keys($lookup);
            $turn   = count($arms);
            for ($pass = 0; $pass <= $passes; $pass++) {
                $passIds = $pass === 0 ? array_slice($ids, 0, max(500, intdiv($lookups, 20))) : $ids; // pass 0 is the uncounted first pass
                $ns      = array_fill_keys($arms, 0);
                foreach (array_chunk($passIds, 500) as $blockNum => $block) {
                    $order = [...array_slice($arms, $blockNum % $turn), ...array_slice($arms, 0, $blockNum % $turn)];
                    foreach ($order as $arm) {
                        $run   = $lookup[$arm];
                        $start = hrtime(true);
                        foreach ($block as $id) {
                            $run($id);
                        }
                        $ns[$arm] += hrtime(true) - $start;
                    }
                }
                if ($pass > 0) {
                    foreach ($arms as $arm) {
                        $this->bench['cases'][$caseName][$arm][] = round($ns[$arm] / count($passIds) / 1000, 2); // µs per lookup
                    }
                }
            }
        }
    }

    //endregion
    //region Check helpers

    /**
     * Start a numbered section. Check names get the section and a counter in front: "2.07 ORDER BY".
     */
    private function startSection(int $section): void
    {
        $this->section  = $section;
        $this->checkNum = 0;
    }

    /**
     * Run one check and record pass, fail or n/a.
     *
     * @param string   $expect 'pass', 'fail', or '' when the manual doesn't say
     * @param callable $run    Returns [detail, short text for the grid]; throws CheckFailed, NotApplicable, or the server's error
     */
    private function check(string $name, string $expect, callable $run): void
    {
        $name = sprintf('%d.%02d %s', $this->section, ++$this->checkNum, $name);
        try {
            if ($this->skipReason !== null) {
                throw new NotApplicable($this->skipReason);
            }
            [$detail, $short] = $run();
            $status = 'pass';
        } catch (NotApplicable $e) {
            [$status, $detail, $short] = ['n/a', $e->getMessage(), ''];
        } catch (CheckFailed $e) {
            [$status, $detail, $short] = ['fail', $e->getMessage(), $e->short];
        } catch (mysqli_sql_exception $e) {
            [$status, $detail, $short] = ['fail', "error {$e->getCode()}: {$e->getMessage()}", (string)$e->getCode()];
        }
        $this->checks[$name] = ['expect' => $expect, 'status' => $status, 'short' => $short, 'detail' => $detail];
    }

    /**
     * Check that a statement gives the same answer written with `_rowid` and with the real column name.
     *
     *     $this->same('WHERE', "SELECT title FROM ::num WHERE {num} = 3");
     *     $this->same('DELETE', "DELETE FROM ::num WHERE {num} = 3", changes: 'num');
     *     $this->same('prepared', "SELECT title FROM ::num WHERE {num} = ?", bind: ['i', [3]]);
     *
     * @param string      $template SQL with the real column name in braces
     * @param array|null  $bind     For a prepared statement: the bind_param() types, then one list of values per run
     * @param string|null $changes  Table the statement changes; refilled before each run and compared after
     * @param string      $spelling What to write in place of the real column name
     */
    private function same(string $name, string $template, string $expect = 'pass', ?array $bind = null, ?string $changes = null, string $spelling = '_rowid'): void
    {
        $this->check($name, $expect, fn() => [$this->compare($template, 'syntax', $bind, $changes, $spelling), '']);
    }

    /**
     * Check that a statement written with `_rowid` runs, for statements with no real-column twin.
     *
     * @param callable|null $describe Gets the outcome, returns [detail, short text for the grid]
     */
    private function works(string $name, string $sql, string $expect, ?callable $describe = null): void
    {
        $this->check($name, $expect, function () use ($sql, $describe) {
            $outcome = $this->outcome($sql);
            if (isset($outcome['error'])) {
                throw new CheckFailed($this->describe($outcome), (string)$outcome['error']);
            }
            return $describe ? $describe($outcome) : [$this->describe($outcome), ''];
        });
    }

    /**
     * Check which column `_rowid` is on a table.
     */
    private function resolves(string $name, string $table, array $columns, string $expect): void
    {
        $this->check($name, $expect, function () use ($table, $columns) {
            $column = $this->resolvedColumn($table, $columns);
            return ["resolves to `$column`", "→ $column"];
        });
    }

    /**
     * Check that EXPLAIN prints the same plan for a statement written both ways.
     */
    private function samePlan(string $name, string $template, bool $partitions = false): void
    {
        $this->check($name, 'pass', function () use ($template, $partitions) {
            try {
                $real = $this->plan($this->spell($template), $partitions);
            } catch (mysqli_sql_exception $e) {
                throw new NotApplicable("EXPLAIN with the real column name also fails: error {$e->getCode()}: {$e->getMessage()}");
            }
            $rowid = $this->plan($this->spell($template, '_rowid'), $partitions);
            if ($rowid !== $real) {
                throw new CheckFailed("`_rowid`: $rowid; real column: $real", 'differs');
            }
            return [$rowid, ''];
        });
    }

    /**
     * Run a statement both ways and return what happened, or throw when the two differ.
     *
     * @param string $mode 'syntax': an error with the real column name means n/a, and an error with `_rowid` is a fail.
     *                     'behavior': errors are compared like any other outcome, so the same error both ways passes
     */
    private function compare(string $template, string $mode = 'syntax', ?array $bind = null, ?string $changes = null, string $spelling = '_rowid'): string
    {
        try {
            $real = $this->outcome($this->spell($template), $bind, $changes);
            if ($mode === 'syntax' && isset($real['error'])) {
                throw new NotApplicable("the same statement with the real column name also fails: " . $this->describe($real));
            }
            $rowid = $this->outcome($this->spell($template, $spelling), $bind, $changes);
        } finally {
            if ($changes !== null) {
                $this->reset($changes);
            }
        }
        if ($mode === 'syntax' && isset($rowid['error'])) {
            throw new CheckFailed($this->describe($rowid), (string)$rowid['error']);
        }

        $comparable = static fn(array $o) => [$o['error'] ?? null, $o['rows'] ?? null, $o['affected'] ?? null, array_map('intval', $o['warnings'] ?? []), $o['state'] ?? null]; // warnings compare by number: the text can name the column
        if ($comparable($rowid) !== $comparable($real)) {
            throw new CheckFailed("`$spelling`: " . $this->describe($rowid, true) . "; real column: " . $this->describe($real, true), isset($rowid['error']) ? (string)$rowid['error'] : 'differs');
        }
        return $this->describe($rowid);
    }

    /**
     * Find which of the listed columns `_rowid` is, by comparing values on every row.
     *
     * @throws mysqli_sql_exception When the server doesn't accept `_rowid` on this table
     */
    private function resolvedColumn(string $table, array $columns): string
    {
        $list = implode(', ', array_map(static fn($column) => "`$column`", $columns));
        $rows = $this->rows("SELECT _rowid, $list FROM ::$table");
        foreach ($columns as $i => $column) {
            if (array_column($rows, 0) === array_column($rows, $i + 1)) {
                return $column;
            }
        }
        throw new CheckFailed("accepted, but its values match none of $list: " . json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), 'no match');
    }

    //endregion
    //region Statement helpers

    /**
     * Run one statement and return what happened as an array:
     *
     *     rows      // SELECT: every row, as lists
     *     affected  // other statements: affected_rows
     *     warnings  // list of "1292: Truncated incorrect ..." from SHOW WARNINGS
     *     error     // the error number, when the server refused; then message has the text and the keys above are missing
     *     state     // every row of $changes afterwards, when $changes was given
     *
     * @param array|null  $bind    For a prepared statement: the bind_param() types, then one list of values per run
     * @param string|null $changes Table to refill first and read back afterwards
     */
    private function outcome(string $sql, ?array $bind = null, ?string $changes = null): array
    {
        if ($changes !== null) {
            $this->reset($changes);
        }
        $out = ['warnings' => []];
        try {
            if ($bind === null) {
                $result = $this->exec($sql);
                $out   += $result instanceof mysqli_result ? ['rows' => $result->fetch_all(MYSQLI_NUM)] : ['affected' => $this->db->affected_rows];
                $out['warnings'] = $this->warnings();
            } else {
                $types = array_shift($bind);
                $stmt  = $this->db->prepare($this->sql($sql));
                foreach ($bind as $values) {
                    $stmt->bind_param($types, ...$values);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result instanceof mysqli_result) {
                        $out['rows'] = [...$out['rows'] ?? [], ...$result->fetch_all(MYSQLI_NUM)];
                    } else {
                        $out['affected'] = ($out['affected'] ?? 0) + $stmt->affected_rows;
                    }
                    $out['warnings'] = [...$out['warnings'], ...$this->warnings()];
                }
                $stmt->close();
            }
        } catch (mysqli_sql_exception $e) {
            $out = ['error' => $e->getCode(), 'message' => $e->getMessage()];
        }
        if ($changes !== null) {
            $out['state'] = $this->rows("SELECT * FROM ::$changes ORDER BY 1");
        }
        return $out;
    }

    /**
     * Describe an outcome in a few words: "1 row", "0 rows changed, warning 1292: ...", "error 1054: ...".
     *
     * @param bool $withRows Also print the rows, for a message about two outcomes that differ
     */
    private function describe(array $outcome, bool $withRows = false): string
    {
        if (isset($outcome['error'])) {
            return "error {$outcome['error']}: {$outcome['message']}";
        }
        $count = isset($outcome['rows']) ? count($outcome['rows']) : $outcome['affected'];
        $text  = $count . ($count === 1 ? ' row' : ' rows') . (isset($outcome['rows']) ? '' : ' changed');
        foreach (array_unique($outcome['warnings']) as $warning) {
            $text .= ", warning $warning";
        }
        if ($withRows) {
            $text .= ' ' . json_encode($outcome['state'] ?? $outcome['rows'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }
        return $text;
    }

    /**
     * The warnings from the last statement, as "number: text".
     */
    private function warnings(): array
    {
        if ($this->db->warning_count === 0) {
            return [];
        }
        return array_map(static fn($row) => "$row[1]: $row[2]", $this->db->query("SHOW WARNINGS")->fetch_all(MYSQLI_NUM));
    }

    /**
     * One line per EXPLAIN row, with every column the server prints: "id=1 select_type=SIMPLE ... rows=1 Extra=".
     *
     * @param bool $partitions Ask for the partitions column. MySQL 8.0+ always prints it and refuses the PARTITIONS keyword
     */
    private function plan(string $sql, bool $partitions = false): string
    {
        $result = null;
        if ($partitions) {
            try {
                $result = $this->exec("EXPLAIN PARTITIONS $sql");
            } catch (mysqli_sql_exception) {
                $result = null; // MySQL 8.0+: the plain EXPLAIN below has the column
            }
        }
        $result ??= $this->exec("EXPLAIN $sql");

        $lines = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $lines[] = implode(' ', array_map(static fn($column, $value) => "$column=" . ($value ?? 'NULL'), array_keys($row), $row));
        }
        return implode(' / ', $lines);
    }

    /**
     * Run a query and return the Handler_read counters it moved: "Handler_read_key=1".
     */
    private function handlerReads(string $sql): string
    {
        try {
            $this->exec("FLUSH STATUS");
        } catch (mysqli_sql_exception $e) {
            throw new NotApplicable("FLUSH STATUS refused: error {$e->getCode()}: {$e->getMessage()}");
        }
        $this->rows($sql);
        $moved = array_filter(array_column($this->rows("SHOW SESSION STATUS LIKE 'Handler_read%'"), 1, 0));
        return $moved ? implode(' ', array_map(static fn($name, $value) => "$name=$value", array_keys($moved), $moved)) : 'no rows read';
    }

    /**
     * Replace each {column} in a template with the real column name, or with `_rowid` or another spelling of it.
     */
    private function spell(string $template, ?string $spelling = null): string
    {
        return preg_replace('/\{(\w+)\}/', $spelling ?? '$1', $template); // {num} → num, or → _rowid
    }

    /**
     * Put the table prefix in place of each `::`.
     */
    private function sql(string $sql): string
    {
        return str_replace('::', $this->prefix, $sql);
    }

    /**
     * Run a statement, with `::` as the table prefix.
     */
    private function exec(string $sql): mysqli_result|bool
    {
        return $this->db->query($this->sql($sql));
    }

    /**
     * Run a query and return every row as a list.
     */
    private function rows(string $sql): array
    {
        return $this->exec($sql)->fetch_all(MYSQLI_NUM);
    }

    /**
     * Set the session sql_mode.
     */
    private function setMode(string $mode): void
    {
        $this->db->query("SET SESSION sql_mode = '$mode'");
    }

    //endregion
    //region Tables

    /**
     * Create and fill a table, replacing any earlier one of that name.
     *
     * @throws NotApplicable When the server refuses the CREATE TABLE or the INSERT
     */
    private function fixture(string $table, string $createSql, string $insertSql): void
    {
        $this->exec("DROP TABLE IF EXISTS ::$table");
        foreach (['CREATE TABLE' => $createSql, 'INSERT' => $insertSql] as $step => $sql) {
            try {
                $this->exec($sql);
            } catch (mysqli_sql_exception $e) {
                throw new NotApplicable("the server refused the $step this check needs: error {$e->getCode()}: {$e->getMessage()}");
            }
        }
        $this->refill[$table] = $insertSql;
    }

    /**
     * Run a setup step and return the reason it couldn't run, or null when it did.
     *
     *     $this->skipReason = $this->setupError(fn() => $this->fixture(...)); // the checks that follow report n/a
     */
    private function setupError(callable $setup): ?string
    {
        try {
            $setup();
            return null;
        } catch (NotApplicable $e) {
            return $e->getMessage();
        } catch (mysqli_sql_exception $e) {
            return "the server refused the setup this check needs: error {$e->getCode()}: {$e->getMessage()}";
        }
    }

    /**
     * Put a table's rows back to what fixture() inserted.
     */
    private function reset(string $table): void
    {
        $this->exec("DELETE FROM ::$table");
        $this->exec($this->refill[$table]);
    }

    /**
     * Create the three tables most checks read:
     *
     *     num    // primary key `num`, 5 rows: the usual CMS Builder table
     *     id     // primary key `id`, 4 rows; parentNum points at num
     *     nokey  // no primary key and no index
     */
    private function baseTables(): void
    {
        $this->fixture('num', "CREATE TABLE ::num (num INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(50) NOT NULL, hits INT NOT NULL DEFAULT 0, PRIMARY KEY (num)) ENGINE=InnoDB", "INSERT INTO ::num (num, title, hits) VALUES (1, 'one', 10), (2, 'two', 20), (3, 'three', 30), (4, 'four', 40), (5, 'five', 50)");
        $this->fixture('id', "CREATE TABLE ::id (id INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(50) NOT NULL, parentNum INT UNSIGNED, PRIMARY KEY (id)) ENGINE=InnoDB", "INSERT INTO ::id (id, title, parentNum) VALUES (1, 'child a', 1), (2, 'child b', 1), (3, 'child c', 3), (4, 'child d', NULL)");
        $this->fixture('nokey', "CREATE TABLE ::nokey (a INT NOT NULL, b INT NOT NULL) ENGINE=InnoDB", "INSERT INTO ::nokey (a, b) VALUES (1, 10), (2, 20), (3, 30)");
    }

    /**
     * Create an InnoDB table with primary key `num` and fill it with rows 1 to $rowCount.
     *
     * @param bool $withLabel Add a `_label` column that holds the same text as title
     */
    private function bigTable(string $table, int $rowCount, bool $withLabel = false): void
    {
        $label = $withLabel ? "`_label` VARCHAR(100) NOT NULL, " : '';
        $this->exec("DROP TABLE IF EXISTS ::$table");
        $this->exec("CREATE TABLE ::$table (num INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(100) NOT NULL, {$label}hits INT NOT NULL DEFAULT 0, PRIMARY KEY (num)) ENGINE=InnoDB");
        for ($first = 1; $first <= $rowCount; $first += 1000) {
            $values = [];
            for ($num = $first; $num < $first + 1000 && $num <= $rowCount; $num++) {
                $values[] = $withLabel ? "($num, 'Record title $num', 'Record title $num')" : "($num, 'Record title $num')";
            }
            $this->exec("INSERT INTO ::$table (num, title" . ($withLabel ? ', `_label`' : '') . ") VALUES " . implode(', ', $values));
        }
        $this->rows("ANALYZE TABLE ::$table");
    }

    /**
     * Drop every table and view the probe created.
     */
    public function dropTables(): void
    {
        $this->exec("DROP TEMPORARY TABLE IF EXISTS ::engine");
        $like  = str_replace('_', '\_', $this->prefix) . '%'; // an unescaped _ would match any character
        $found = $this->rows("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '$like'");
        foreach ($found as [$name, $type]) {
            $this->db->query(($type === 'VIEW' ? 'DROP VIEW' : 'DROP TABLE') . " `$name`");
        }
    }

    //endregion
}

//region Report

/**
 * The middle value of a list of numbers.
 */
function median(array $numbers): float
{
    sort($numbers);
    $count = count($numbers);
    return $count % 2 ? $numbers[intdiv($count, 2)] : ($numbers[$count / 2 - 1] + $numbers[$count / 2]) / 2;
}

/**
 * One speed-test case as text: each arm's median pass, its difference from the first arm, and how far passes moved.
 *
 * @param array<string, list<float>> $arms arm name => µs per lookup for each pass; the first arm is the baseline
 */
function benchLine(array $arms): string
{
    $parts = [];
    foreach (benchCells($arms) as $arm => $cell) {
        $parts[] = "$arm $cell";
    }
    return implode('; ', $parts);
}

/**
 * The numbers for one speed-test case, formatted for a table row.
 *
 *     ['num' => '31.53', '_rowid' => '37.17 (+5.64, +17.9%)', ..., 'passes moved' => '0.14 (0.4%)']
 *
 * @param array<string, list<float>> $arms arm name => µs per lookup for each pass; the first arm is the baseline
 * @return array<string, string> arm name => median µs, with the difference from the baseline for every arm after it; then 'passes moved'
 */
function benchCells(array $arms): array
{
    $baseline = median(reset($arms));
    $percent  = static fn(float $µs) => $baseline > 0 ? $µs / $baseline * 100 : 0;
    $cells    = [];
    foreach ($arms as $arm => $passes) {
        $µs          = median($passes);
        $cells[$arm] = $cells ? sprintf('%.2f (%+.2f, %+.1f%%)', $µs, $µs - $baseline, $percent($µs - $baseline)) : sprintf('%.2f', $µs);
    }
    $widest                = max(array_map(static fn(array $passes) => max($passes) - min($passes), $arms));
    $cells['passes moved'] = sprintf('%.2f (%.1f%%)', $widest, $percent($widest));
    return $cells;
}

/**
 * Server label for a table heading: "percona/percona-server:8.0" → "Percona 8.0".
 */
function shortServer(string $server): string
{
    return str_replace(['percona/percona-server:', 'mariadb:', 'mysql:'], ['Percona ', 'MariaDB ', 'MySQL '], $server);
}

/**
 * Merge the JSON files written by --json into one markdown report: a grid of every check
 * on every server, the details behind each cell, and the speed numbers.
 */
function mergeReports(array $paths): void
{
    $reports = []; // server => report
    foreach ($paths as $path) {
        $report = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (isset($report['server'], $report['checks'])) {
            $reports[$report['server']] = $report;
        } else {
            fwrite(STDERR, "Skipping $path: not a rowid-probe JSON file\n");
        }
    }
    uksort($reports, static fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));

    echo "# `_rowid` Matrix\n\n";
    if (!$reports) {
        echo "No probe data found.\n";
        return;
    }
    $servers = array_keys($reports);
    echo "Where every supported database server accepts `_rowid`, the name MySQL and MariaDB take in place of a table's primary key column when that key is one integer column. ";
    echo "Generated by the \"Rowid Matrix\" workflow (.github/workflows/rowid-matrix.yml); regenerate with:\n\n";
    echo "    gh workflow run rowid-matrix.yml   # wait for the run to finish, then:\n";
    echo "    gh run download --dir probes       # pick the latest Rowid Matrix run\n";
    echo "    php .github/scripts/rowid-probe.php --merge probes/*/rowid-probe-*.json > docs/internal/rowid-matrix.md\n\n";
    echo "Last generated: " . date('Y-m-d') . " from " . count($reports) . " servers. Generated file - don't hand-edit. ";
    echo "The \"Verdicts\" and \"Findings\" sections below are maintained in the heredoc in .github/scripts/rowid-probe.php; edit them there.\n\n";
    echo "Servers: " . implode(', ', array_map(static fn($s) => "$s ({$reports[$s]['version']})", $servers)) . "\n\n";
    echo <<<'__FINDINGS__'
        ## Verdicts

        For a table with one integer primary key, on every server in the matrix (MySQL 5.7 to 9.7, MariaDB 10.2.6 to 12.3, Percona Server 5.7 to 8.4):

        | Use | Verdict |
        |---|---|
        | SELECT where | Yes, when the statement reads one table. Write it in backticks: `` `_rowid` = '123' `` |
        | UPDATE where | Yes, same condition. Works on every server; the MySQL manual only promises SELECT |
        | DELETE where | Yes, same as UPDATE |
        | JOIN condition | Yes, only with the table name or alias in front: `alias._rowid` |
        | ORDER BY | Yes, when the statement reads one table; `alias._rowid` in a join |

        ## Findings

        Every finding below is the same on all 19 servers unless it names versions. Three full runs gave the same answer for all 150 checks.

        Most serious first:

        - `_rowid` with no table name in front fails in any statement that reads two or more tables: `error 1054: Unknown column '_rowid' in 'where clause'` (the text ends `in 'WHERE'` on MariaDB 10.6+). It fails even when only one of the tables has a key, where an unqualified `num` works. `table._rowid` and `alias._rowid` work. The server only looks the name up when it has a table name in front or the FROM list has one table (`allow_rowid` in `sql_base.cc`, both servers)
        - Inside a subquery, an unqualified `_rowid` is the key of the subquery's own table when that table has one, with no error. It is the outer table's key only when the subquery's table has none
        - Views and derived tables have no `_rowid`: error 1054 for `WHERE _rowid = 2` on a view and for `d._rowid` on `(SELECT num, title FROM t) d`. A view whose own definition uses `_rowid` works, and is stored with the real column name
        - A real column named `_rowid` takes priority over the primary key, in WHERE, in the SELECT list and in `UPDATE ... SET`. Every server accepts one in CREATE TABLE and ALTER TABLE, as an ordinary column or as the primary key
        - Only a single integer column counts. Composite, VARCHAR, CHAR(36), DECIMAL and BINARY(16) primary keys have no `_rowid` (error 1054), and a UNIQUE NOT NULL integer index beside them doesn't give them one. TINYINT thru BIGINT, signed and unsigned, with and without AUTO_INCREMENT all work
        - A table with no primary key but a UNIQUE NOT NULL integer index has a `_rowid`: that column. With two such indexes it is the one created first, not the first column. A nullable or non-unique index doesn't count
        - `SELECT _rowid` names the result column `_rowid` (original column `num`), so a ZenDB row has the key `_rowid`, not `num`. `SELECT *`, `SHOW COLUMNS` and `information_schema.COLUMNS` don't list it
        - Writing to it works: `UPDATE ... SET _rowid = 50`, `INSERT (_rowid, ...)` and `INSERT ... SET _rowid = 61` change the primary key. ZenDB's `update()` refuses a lone `num`, `id` or `ID` in the values as a reversed-argument mistake, and doesn't refuse a lone `_rowid`
        - An INVISIBLE primary key (MySQL 8.0.23+, MariaDB 10.3+) has a `_rowid`. On a table created with `sql_generate_invisible_primary_key=ON` (MySQL and Percona 8.0.30+), `_rowid` is `my_row_id`
        - Values compare the same as with the real column: same rows, same warnings and the same errors, strict and non-strict. `'9999999999'` against an INT or INT UNSIGNED key returns zero rows with no error and no warning, as a plain or a prepared statement, for SELECT, UPDATE and DELETE
        - InnoDB, MyISAM, MEMORY, Aria, partitioned and temporary tables all behave the same, as do `ANSI_QUOTES`, `ONLY_FULL_GROUP_BY` and `TRADITIONAL`
        - EXPLAIN is identical for `_rowid` and the real column in every case tried (point lookup, IN list, range, ORDER BY ... LIMIT, MAX, join, UPDATE, DELETE, partition pruning), and so are the Handler_read counters when the query runs
        - ZenDB accepts `_rowid` everywhere it accepts a column name: array where, string where with `?` and `:name`, backtick placeholders, ORDER BY, `update()` and `delete()` conditions, `insert()` values. `DB::isIdentifier('_rowid')` is true. Array where and backtick placeholders write it in backticks

        Speed:

        - A bare `_rowid` costs more each time a statement is parsed. In backticks, or with a table name in front, it costs the same as `num`. Difference from `num` per plain query, over three CI runs: MySQL and Percona 5.7 +1.6 to +2.6 µs, with one run at -0.1; MySQL and Percona 8.0 to 9.7 0.0 to +1.4 µs; MariaDB 10.2 to 10.6 +0.5 to +3.8 µs; MariaDB 10.11 to 12.3 +3.4 to +6.7 µs, with one run at +10.8. CI runners are noisy: the control moved by -1.0 to +2.0 µs, and `_rowid` in backticks by -1.6 to +2.0 µs. On a quiet dedicated server (MariaDB 12.3.3, five runs) the bare cost was +5.1 to +5.8 µs on a 31 µs query, backticks -0.04 to +0.15 µs, control within 0.1 µs
        - A prepared statement that is prepared once and run many times shows no difference for any spelling. One that is prepared again for every lookup shows the same cost as a plain query
        - The cost is the leading underscore, not `_rowid`. The lexer checks whether an unquoted word that starts with `_` is a charset name, as in `_utf8'text'` (`get_charset_by_csname()` in `sql_lex.cc`, both servers). MariaDB and MySQL 5.7 walk the whole charset list to find out it isn't; MySQL 8.0+ look it up in a hash map. A real column named `_label` costs the same as `_rowid` bare and nothing extra in backticks. A backticked name and a name after a dot don't go through that check
        - `` `num` `` and `num` cost the same on every server (-0.6 to +1.3 µs, inside the control's range). Backticks don't make an ordinary column faster
        - A column whose name is an underscore plus a charset name (`_latin1`, `_utf8`, `_utf8mb4`, `_binary`) is a syntax error when written bare (error 1064), in SELECT, WHERE, ORDER BY, UPDATE, INSERT and CREATE TABLE. It works in backticks or with the table name in front. Checked by hand on MariaDB 11.3.2 and MySQL 8.3.0, not in this matrix
        - The MariaDB 10.2, 10.3 and 10.4 images start with the query cache on. A cached result comes back before the statement is parsed, so on a cache hit no spelling costs anything. The speed test turns the cache off

        Looked up, not tested:

        - The MySQL manual defines `_rowid` under [CREATE INDEX, Unique Indexes](https://dev.mysql.com/doc/refman/8.4/en/create-index.html), with a pointer from CREATE TABLE. The wording is the same in the 5.7, 8.0, 8.4 and 9.7 manuals and only promises SELECT: "you can use _rowid to refer to the indexed column in SELECT statements". No deprecation or removal notice was found in those manuals; the What Is New pages for 9.0 to 9.6 weren't read
        - MariaDB documents it as a [pseudo column](https://mariadb.com/docs/server/reference/sql-functions/pseudo-columns/_rowid), with an `UPDATE ... WHERE _rowid=2` example and no deprecation notice
        - No bug report of wrong results, a crash or a replication problem from using `_rowid` was found on bugs.mysql.com, jira.mariadb.org or Percona's Jira. MySQL bugs [42539](https://bugs.mysql.com/bug.php?id=42539) and [88098](https://bugs.mysql.com/bug.php?id=88098) are documentation fixes; the second is the composite-key rule above
        - TiDB doesn't support it: [pingcap/tidb issue 3635](https://github.com/pingcap/tidb/issues/3635) is open, and the last comment that confirms the error is from August 2020. TiDB's own `_tidb_rowid` is a different thing
        - Amazon Aurora, RDS, Vitess and PlanetScale: unknown, nothing found


        __FINDINGS__;

    // Every check any server ran, in the order the probe runs them
    $checkNames = [];
    foreach ($reports as $report) {
        $checkNames += array_fill_keys(array_keys($report['checks']), true);
    }
    $checkNames = array_keys($checkNames);
    sort($checkNames, SORT_NATURAL);

    echo "## Checks\n\n";
    echo "Most checks run one statement twice, written with `_rowid` and with the real column name. ";
    echo "**pass**: the server took `_rowid` and gave the same rows, changed rows and warnings as the real column. ";
    echo "**fail**: the server refused `_rowid` (the cell has the error number) or gave a different answer. ";
    echo "**n/a**: the server refuses that statement with the real column name too, or can't create the table the check needs. ";
    echo "\"Expected\" is what the MySQL manual says should happen; blank where it doesn't say. A cell in bold differs from it. ";
    echo "The error text and the detail behind each cell are in the next section.\n\n";
    echo "| Check | Expected | " . implode(' | ', array_map('shortServer', $servers)) . " |\n";
    echo "|---|---|" . str_repeat('---|', count($servers)) . "\n";
    foreach ($checkNames as $name) {
        $expect = '';
        $cells  = [];
        foreach ($reports as $report) {
            $check = $report['checks'][$name] ?? null;
            if ($check === null) {
                $cells[] = 'no data';
                continue;
            }
            $expect = $check['expect'];
            $cell   = trim("{$check['status']} {$check['short']}");
            $cells[] = $expect !== '' && $check['status'] !== 'n/a' && $check['status'] !== $expect ? "**$cell**" : $cell;
        }
        echo "| " . str_replace('|', '\|', $name) . " | $expect | " . implode(' | ', $cells) . " |\n";
    }

    echo "\n## Details\n\n";
    $families = serverFamilies($servers);
    foreach ($checkNames as $name) {
        $serversByAnswer = [];
        foreach ($reports as $server => $report) {
            $check  = $report['checks'][$name] ?? null;
            $answer = $check === null ? '(no data)' : $check['status'] . ($check['detail'] !== '' ? ": {$check['detail']}" : '');
            $serversByAnswer[$answer][] = $server;
        }
        echo "### $name\n\n";
        foreach ($serversByAnswer as $answer => $group) {
            $who = count($serversByAnswer) === 1 ? 'all servers' : (serverGroupLabel($group, $families) ?? implode(', ', $group));
            echo "- $who: " . str_replace(["\r", "\n"], ' ', (string)$answer) . "\n";
        }
        echo "\n";
    }

    $benched = array_filter($reports, static fn($report) => !empty($report['bench']['cases']));
    if (!$benched) {
        return;
    }
    $first = reset($benched)['bench'];
    echo "## Speed\n\n";
    echo "Point lookups by primary key on a " . number_format($first['rows']) . " row InnoDB table. Each column is one spelling of the same statement; the heading is the part that differs. ";
    echo "Each pass runs " . number_format($first['lookups']) . " lookups per column. The numbers are microseconds per lookup, the median of {$first['passes']} passes, ";
    echo "followed by the difference from the first column in µs and percent. ";
    echo "The \"(control)\" column is the first column's statement timed a second time, which shows how far two identical queries differ on that runner; a difference no larger than that isn't a real difference. ";
    echo "\"Passes moved\" is the largest gap between the fastest and slowest pass of any column.\n\n";
    $cacheWasOn = array_keys(array_filter($benched, static fn($report) => str_starts_with($report['bench']['queryCache'] ?? '', 'on')));
    if ($cacheWasOn) {
        echo "The query cache is off for the test. A cached result comes back before the statement is parsed, so with the cache on every column times the cache. ";
        echo "These images start with it on: " . implode(', ', $cacheWasOn) . ".\n\n";
    }
    $armsByCase = []; // case name => arm names, from the first server that ran it
    foreach ($benched as $report) {
        foreach ($report['bench']['cases'] as $caseName => $arms) {
            $armsByCase[$caseName] ??= array_keys($arms);
        }
    }
    foreach ($armsByCase as $caseName => $armNames) {
        echo "### " . ucfirst($caseName) . "\n\n";
        echo "| Server | " . implode(' | ', array_map('mdValue', $armNames)) . " | Passes moved |\n";
        echo "|---|" . str_repeat('---|', count($armNames) + 1) . "\n";
        foreach ($benched as $server => $report) {
            if (isset($report['bench']['cases'][$caseName])) {
                echo "| $server | " . implode(' | ', benchCells($report['bench']['cases'][$caseName])) . " |\n";
            }
        }
        echo "\n";
    }
}

//endregion
