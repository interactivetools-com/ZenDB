#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Check how each way of writing an exact string comparison behaves on one database server:
 * which values match, which forms warn or error, which keep an index lookup, how many rows
 * the server reads, how fast each one is, and what the column types that make `col = ?`
 * exact on their own cost in index size.
 *
 *     php .github/scripts/exact-match-probe.php --json=probe.json                 # one server
 *     php .github/scripts/exact-match-probe.php --rows=20000 --json=probe.json    # smaller speed test
 *     php .github/scripts/exact-match-probe.php --merge probes/*.json             # one report for every server
 *
 * `col = ?` ignores trailing spaces under a PAD SPACE collation and ignores case under a _ci
 * collation, so a lookup on a machine-made value (temp id, token, code) can match rows it
 * shouldn't: `preSaveTempId = ' '` matches every row where it's ''. This probe compares the
 * other ways of writing it with `col = ?` on every server in the matrix.
 *
 * Values are sent the way ZenDB sends a placeholder value: a string escaped with
 * real_escape_string() and put in quotes, an int as bare digits, over the text protocol.
 *
 * Every table is named zdbexact_*. With DB_DATABASE set they're created in that database
 * and dropped on exit; without it the probe creates and drops the zdb_exact_probe
 * database. Connects with the same DB_* env vars as the test suite (see phpunit.xml.dist).
 * DB_LABEL names the server in the JSON output, e.g. "mariadb:10.6".
 */

require __DIR__ . '/ci-lib.php';

$opts = getopt('', ['json:', 'merge', 'rows:'], $restIndex);
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
$scratch  = $database === '' ? 'zdb_exact_probe' : null; // only created and dropped when no database was named

$mysqli = new mysqli($hostname, $username, $password);
$mysqli->set_charset('utf8mb4');
if ($scratch !== null) {
    $mysqli->query("DROP DATABASE IF EXISTS `$scratch`");
    $mysqli->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4");
    $database = $scratch;
}
$mysqli->select_db($database);
try {
    $mysqli->query("SET SESSION query_cache_type = OFF"); // a cached result would skip the lookup being timed
} catch (mysqli_sql_exception) {
    // MySQL 8.0+ has no query cache
}

$probe  = new ExactMatchProbe($mysqli, $database);
$report = [
    'server'  => $label,
    'version' => $mysqli->query("SELECT VERSION()")->fetch_row()[0],
    'facts'   => &$probe->facts,
    'grids'   => &$probe->grids,
    'bench'   => &$probe->bench,
    'reads'   => &$probe->reads,
    'sizes'   => &$probe->sizes,
];

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
        fwrite(STDERR, "Wrote " . count($report['facts']) . " facts and " . count($report['grids']) . " grids to $jsonPath\n");
    }
});

$probe->dropTables();
$probe->binaryOperator();
$probe->comparisons();
$probe->uniqueKeys();
$probe->numbers();
$rowCount = (int)($opts['rows'] ?? 100_000);
$probe->indexUse($rowCount);
$probe->compositeIndex($rowCount);

echo "## $label ({$report['version']})\n\n";
foreach ($probe->facts as $name => $value) {
    echo "- $name: $value\n";
}
foreach ($probe->grids as $name => $grid) {
    echo "\n### $name\n\n" . gridTable($grid) . "\n";
}

/**
 * The checks for one server. Each public method is one section of the report.
 */
final class ExactMatchProbe
{
    /** @var array<string, string> name => one-line answer */
    public array $facts = [];

    /** @var array<string, array{cols: list<string>, rows: array<string, array<string, string>>}> */
    public array $grids = [];

    /** @var array<string, array<string, float>> "column type, index" => form => µs per lookup */
    public array $bench = [];

    /** @var array<string, array<string, int>> "column type, index" => form => Handler_read rows per lookup */
    public array $reads = [];

    /** @var array<string, array<string, int>> column type => index => KB */
    public array $sizes = [];

    /**
     * Column types, `%d` for the length. A type is skipped on a server that doesn't have
     * its collation.
     */
    private const COLUMN_TYPES = [
        'utf8mb4_unicode_ci'      => 'VARCHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'utf8mb4_general_ci'      => 'VARCHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
        'utf8mb4_bin'             => 'VARCHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
        'ascii_general_ci'        => 'VARCHAR(%d) CHARACTER SET ascii COLLATE ascii_general_ci',
        'ascii_bin'               => 'VARCHAR(%d) CHARACTER SET ascii COLLATE ascii_bin',
        'latin1_swedish_ci'       => 'VARCHAR(%d) CHARACTER SET latin1 COLLATE latin1_swedish_ci',
        'utf8mb4_0900_ai_ci'      => 'VARCHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',     // MySQL 8.0+ default, NO PAD
        'utf8mb4_uca1400_ai_ci'   => 'VARCHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci',  // MariaDB 11.5+ default
        'utf8mb4_0900_bin'        => 'VARCHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin',       // MySQL 8.0.17+, NO PAD
        'utf8mb4_nopad_bin'       => 'VARCHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin',      // MariaDB, NO PAD
        'VARBINARY'               => 'VARBINARY(%d)',
        'CHAR utf8mb4_unicode_ci' => 'CHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'TEXT utf8mb4_unicode_ci' => 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    ];

    /**
     * The column types in the speed test: the ones CMS Builder tables have now, then the
     * ones a schema change could switch a machine-made value to.
     */
    private const INDEX_TYPES = [
        'utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_ai_ci', 'ascii_general_ci', 'latin1_swedish_ci',
        'utf8mb4_bin', 'ascii_bin', 'VARBINARY',
    ];

    /**
     * Stored value, sent value. The last two pairs send the stored value back, so a
     * form that misses them can't be used at all on that column.
     */
    private const PAIRS = [
        'case: abc / ABC'       => ['abc', 'ABC'],
        'trailing space sent'   => ['abc', 'abc '],
        'trailing space stored' => ['abc ', 'abc'],
        'leading space sent'    => ['abc', ' abc'],
        "'' / one space"        => ['', ' '],
        "'' / U+200B"           => ['', "\u{200B}"],
        "'' / NUL"              => ['', "\0"],
        'e / é'                 => ['e', 'é'],
        'ss / ß'                => ['ss', 'ß'],
        'same: abc / abc'       => ['abc', 'abc'],
        'same non-ASCII: é / é' => ['é', 'é'],
    ];

    /** @var array<string, string> the COLUMN_TYPES this server has */
    private array $types;
    private int $handlerBaseline;

    public function __construct(private mysqli $mysqli, private string $database)
    {
        $this->types = array_filter(self::COLUMN_TYPES, fn($type) => !preg_match('/COLLATE (\w+)/', $type, $m) || $this->hasCollation($m[1]));

        $this->facts['connection collation'] = $this->mysqli->query("SELECT @@collation_connection")->fetch_row()[0];
        $this->facts['server sql_mode']      = $this->mysqli->query("SELECT @@GLOBAL.sql_mode")->fetch_row()[0];
        $this->facts['column types tested']  = implode(', ', array_keys($this->types));

        // What SHOW STATUS adds to the Handler_read counters by itself, so it can be subtracted
        $this->mysqli->query("FLUSH STATUS");
        $this->handlerBaseline = $this->handlerReads();
    }

    /**
     * The ways of writing the comparison, as SQL for a column and one quoted value.
     * The NO PAD collations are null where the server doesn't have them.
     *
     * @return array<string, ?Closure(string, string): string>
     */
    private function forms(): array
    {
        $nopad = fn(string $collation) => $this->hasCollation($collation)
            ? static fn($col, $v) => "$col = $v COLLATE $collation"
            : null;
        return [
            'a'  => static fn($col, $v) => "$col = $v",
            'b1' => static fn($col, $v) => "$col = CAST($v AS BINARY)",
            'b2' => static fn($col, $v) => "$col = BINARY $v",
            'c'  => static fn($col, $v) => "CAST($col AS BINARY) = $v",
            'd'  => static fn($col, $v) => "$col = $v COLLATE utf8mb4_bin",
            'e'  => static fn($col, $v) => "$col LIKE $v",
            'f'  => static fn($col, $v) => "$col = $v AND $col = CAST($v AS BINARY)",
            'g1' => $nopad('utf8mb4_0900_bin'),
            'g2' => $nopad('utf8mb4_0900_as_cs'),
            'g3' => $nopad('utf8mb4_nopad_bin'),
        ];
    }

    /**
     * The same forms for an IN list. LIKE has no IN form.
     *
     * @return array<string, ?Closure(string, list<string>): string>
     */
    private function inForms(): array
    {
        $each  = static fn(array $vs, string $pattern) => implode(', ', array_map(static fn($v) => sprintf($pattern, $v), $vs));
        $nopad = fn(string $collation) => $this->hasCollation($collation)
            ? static fn($col, $vs) => "$col IN (" . $each($vs, "%s COLLATE $collation") . ")"
            : null;
        return [
            'a'  => static fn($col, $vs) => "$col IN (" . implode(', ', $vs) . ")",
            'b1' => static fn($col, $vs) => "$col IN (" . $each($vs, 'CAST(%s AS BINARY)') . ")",
            'b2' => static fn($col, $vs) => "$col IN (" . $each($vs, 'BINARY %s') . ")",
            'c'  => static fn($col, $vs) => "CAST($col AS BINARY) IN (" . implode(', ', $vs) . ")",
            'd'  => static fn($col, $vs) => "$col IN (" . $each($vs, '%s COLLATE utf8mb4_bin') . ")",
            'f'  => static fn($col, $vs) => "$col IN (" . implode(', ', $vs) . ") AND $col IN (" . $each($vs, 'CAST(%s AS BINARY)') . ")",
            'g1' => $nopad('utf8mb4_0900_bin'),
            'g2' => $nopad('utf8mb4_0900_as_cs'),
            'g3' => $nopad('utf8mb4_nopad_bin'),
        ];
    }

    private function hasCollation(string $collation): bool
    {
        static $cache = [];
        return $cache[$collation] ??= (bool)$this->mysqli->query("SELECT 1 FROM information_schema.COLLATIONS WHERE COLLATION_NAME = '$collation'")->fetch_row();
    }

    /**
     * A value as ZenDB writes it into a statement.
     */
    private function quote(string $value): string
    {
        return "'" . $this->mysqli->real_escape_string($value) . "'";
    }

    #region Sections

    /**
     * Section 1: whether the BINARY operator and CAST(... AS BINARY) are accepted, and
     * with what warnings, as text-protocol literals and as a prepared statement.
     */
    public function binaryOperator(): void
    {
        $checks = [
            "BINARY 'a' = 'A'"                 => "SELECT BINARY 'a' = 'A'",
            "'a' = BINARY 'a '"                => "SELECT 'a' = BINARY 'a '",
            "'a' = CAST('a ' AS BINARY)"       => "SELECT 'a' = CAST('a ' AS BINARY)",
            "CAST('a' AS BINARY) = 'A'"        => "SELECT CAST('a' AS BINARY) = 'A'",
            "'a' = CONVERT('a ' USING binary)" => "SELECT 'a' = CONVERT('a ' USING binary)",
            "'a' = _binary'a '"                => "SELECT 'a' = _binary'a '",
            "'a' = 'a '"                       => "SELECT 'a' = 'a '",
            "CAST(NULL AS BINARY) IS NULL"     => "SELECT CAST(NULL AS BINARY) IS NULL",
        ];
        foreach ($checks as $name => $sql) {
            $result = $this->run($sql);
            $this->facts["literal: $name"] = $this->cell($result, static fn($rows) => (string)$rows[0][0]);
        }

        foreach (['BINARY ?' => "SELECT 'a' = BINARY ?", 'CAST(? AS BINARY)' => "SELECT 'a' = CAST(? AS BINARY)"] as $name => $sql) {
            try {
                $stmt  = $this->mysqli->prepare($sql);
                $value = 'a ';
                $stmt->bind_param('s', $value);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $this->facts["prepared: 'a' = $name with 'a '"] = (string)$row[0];
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                $this->facts["prepared: 'a' = $name with 'a '"] = "E{$e->getCode()} {$e->getMessage()}";
            }
        }

        // The warning text, once, so the report can quote it
        $this->mysqli->query("SELECT BINARY 'a'")->free();
        $text = $this->mysqli->query("SHOW WARNINGS")->fetch_all();
        $this->facts['BINARY warning text'] = $text ? $text[0][1] . ' ' . $text[0][2] : '(none)';
    }

    /**
     * Section 2: which stored/sent pairs each form matches, one grid per column type.
     * Values are stored under strict mode and read back as hex, so a value the column
     * can't hold, or changes on the way in (CHAR drops trailing spaces), is "n/a".
     */
    public function comparisons(): void
    {
        $forms = $this->forms();
        foreach ($this->types as $typeName => $type) {
            $table = $this->create('cmp', "id INT PRIMARY KEY, col " . sprintf($type, 32) . " NOT NULL");
            $this->mysqli->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

            $rows = [];
            $id   = 0;
            foreach (self::PAIRS as $pairName => [$stored, $sent]) {
                $id++;
                $storedAsSent = false;
                try {
                    $this->mysqli->query("INSERT INTO $table VALUES ($id, {$this->quote($stored)})");
                    $hex          = $this->mysqli->query("SELECT HEX(col) FROM $table WHERE id = $id")->fetch_row()[0];
                    $want         = $this->mysqli->query("SELECT HEX(CONVERT({$this->quote($stored)} USING " . $this->charsetOf($type) . "))")->fetch_row()[0];
                    $storedAsSent = $hex === $want;
                } catch (mysqli_sql_exception) {
                }
                if (!$storedAsSent) {
                    $rows[$pairName] = array_fill_keys(array_keys($forms), 'n/a');
                    continue;
                }
                foreach ($forms as $form => $sql) {
                    if ($sql === null) {
                        $rows[$pairName][$form] = 'n/a';
                        continue;
                    }
                    $result                 = $this->run("SELECT id FROM $table WHERE id = $id AND " . $sql('col', $this->quote($sent)));
                    $rows[$pairName][$form] = $this->cell($result, static fn($r) => $r ? 'yes' : 'no');
                }
            }
            $this->mysqli->query("SET SESSION sql_mode = DEFAULT");
            $this->dropTable($table);
            $this->grids["compare: $typeName column"] = ['cols' => array_keys($forms), 'rows' => $rows];
        }
    }

    /**
     * Section 6: whether two values collide in a unique index, per column type.
     */
    public function uniqueKeys(): void
    {
        $pairs = [
            "a / 'a '" => ['a', 'a '],
            'a / A'    => ['a', 'A'],
            "'' / ' '" => ['', ' '],
            "'' / NUL" => ['', "\0"],
            'e / é'    => ['e', 'é'],
            'ss / ß'   => ['ss', 'ß'],
        ];
        $rows = [];
        foreach ($this->types as $typeName => $type) {
            $keyPart = str_starts_with($type, 'TEXT') ? 'col(10)' : 'col';
            foreach ($pairs as $pairName => [$first, $second]) {
                $table = $this->create('uniq', "col " . sprintf($type, 10) . " NOT NULL, UNIQUE KEY col ($keyPart)");
                $this->mysqli->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
                try {
                    $this->mysqli->query("INSERT INTO $table VALUES ({$this->quote($first)})");
                } catch (mysqli_sql_exception) {
                    $rows[$typeName][$pairName] = 'n/a';
                    continue;
                }
                try {
                    $this->mysqli->query("INSERT INTO $table VALUES ({$this->quote($second)})");
                    $rows[$typeName][$pairName] = 'distinct';
                } catch (mysqli_sql_exception $e) {
                    $rows[$typeName][$pairName] = $e->getCode() === 1062 ? 'collide' : "E{$e->getCode()}";
                }
            }
        }
        $this->mysqli->query("SET SESSION sql_mode = DEFAULT");
        $this->grids['unique index'] = ['cols' => array_keys($pairs), 'rows' => $rows];
    }

    /**
     * Section 5: strings against an INT column and numbers against a VARCHAR column, for
     * SELECT, UPDATE and DELETE, under ZenDB's default sql_mode and with no strict mode.
     */
    public function numbers(): void
    {
        $table = $this->create('num', "id INT PRIMARY KEY, i INT NOT NULL, v VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, touched INT NOT NULL DEFAULT 0");
        $this->mysqli->query("INSERT INTO $table (id, i, v) VALUES (1, 0, 'abc'), (2, 5, '0'), (3, 10, '1'), (4, 1, '1abc'), (5, 2, ''), (6, 3, ' 1'), (7, 4, 'x1')");

        $conditions = [
            "i = '5abc'"                    => "i = {$this->quote('5abc')}",
            "i = ' 5'"                      => "i = {$this->quote(' 5')}",
            "i = '5 '"                      => "i = {$this->quote('5 ')}",
            "i = ''"                        => "i = {$this->quote('')}",
            "i = 'abc'"                     => "i = {$this->quote('abc')}",
            "i = '1e1'"                     => "i = {$this->quote('1e1')}",
            "i = CAST('5' AS BINARY)"       => "i = CAST({$this->quote('5')} AS BINARY)",
            "i = CAST('5abc' AS BINARY)"    => "i = CAST({$this->quote('5abc')} AS BINARY)",
            "i = CAST('' AS BINARY)"        => "i = CAST({$this->quote('')} AS BINARY)",
            'v = 0'                         => 'v = 0',
            'v = 1'                         => 'v = 1',
            'v = TRUE'                      => 'v = TRUE',
            "v = CAST('1' AS BINARY)"       => "v = CAST({$this->quote('1')} AS BINARY)",
        ];
        $modes = [
            'ZenDB default' => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
            'not strict'    => 'NO_ENGINE_SUBSTITUTION',
        ];
        $rows = [];
        foreach ($modes as $modeName => $mode) {
            $this->mysqli->query("SET SESSION sql_mode = '$mode'");
            foreach ($conditions as $name => $condition) {
                $select = $this->run("SELECT id FROM $table WHERE $condition ORDER BY id");
                $rows["$name SELECT"][$modeName] = $this->cell($select, static fn($r) => $r ? 'ids ' . implode(',', array_column($r, 0)) : 'none');

                foreach (['UPDATE' => "UPDATE $table SET touched = touched + 1 WHERE $condition", 'DELETE' => "DELETE FROM $table WHERE $condition"] as $verb => $sql) {
                    $this->mysqli->begin_transaction();
                    $result = $this->run($sql);
                    $this->mysqli->rollback();
                    $rows["$name $verb"][$modeName] = $this->cell($result, static fn() => "{$result['affected']} rows");
                }
            }
        }
        $this->mysqli->query("SET SESSION sql_mode = DEFAULT");
        $this->dropTable($table);
        $this->grids['numbers'] = ['cols' => array_keys($modes), 'rows' => $rows];
    }

    /**
     * Section 3: EXPLAIN, rows read and lookup speed for each form, on a plain, a unique and
     * a prefix index, for a single value and an IN list of five, and the size of each index.
     * The columns are VARCHAR(255), like CMS Builder's `tableName` and `preSaveTempId`.
     */
    public function indexUse(int $rowCount): void
    {
        $values  = $this->tokens($rowCount);
        $lookups = array_slice($values, 0, 2000);

        foreach (array_intersect_key($this->types, array_flip(self::INDEX_TYPES)) as $typeName => $type) {
            $type  = sprintf($type, 255) . ' NOT NULL';
            $table = $this->create('idx', "id INT PRIMARY KEY, plain_col $type, unique_col $type, prefix_col $type, KEY plain_col (plain_col), UNIQUE KEY unique_col (unique_col), KEY prefix_col (prefix_col(10))");
            $this->load($table, $values, static fn($i, $q) => "($i, $q, $q, $q)");
            $this->sizes[$typeName] = $this->indexSizes($table);

            $planRows = [];
            foreach (['plain_col' => 'plain', 'unique_col' => 'unique', 'prefix_col' => 'prefix'] as $col => $index) {
                foreach ($this->forms() as $form => $sql) {
                    $planRows[$form][$index] = $this->measure("$typeName, $index", $form, $sql === null ? null : fn($v) => "SELECT id FROM $table WHERE " . $sql($col, $this->quote($v)), $lookups);
                }
                foreach ($this->inForms() as $form => $sql) {
                    $offset = 0;  // each lookup asks for the next five values
                    $planRows["$form IN"][$index] = $this->measure("$typeName, $index", "$form IN", $sql === null ? null : fn() => "SELECT id FROM $table WHERE " . $sql($col, array_map(fn($w) => $this->quote($w), array_slice($lookups, $offset++ % 1995, 5))), $lookups);
                }
            }
            $this->grids["plan: $typeName"] = ['cols' => ['plain', 'unique', 'prefix'], 'rows' => $planRows];
            $this->dropTable($table);
        }
        $this->facts['speed test rows'] = (string)$rowCount;
    }

    /**
     * Section 4: a two-column index shaped like the uploads table's lookup, `tableName`
     * (ascii_general_ci) then `preSaveTempId` (utf8mb4_unicode_ci). key_len in the plan
     * shows whether the lookup uses both columns of the index or only the first.
     */
    public function compositeIndex(int $rowCount): void
    {
        $tableNames = ['news', 'products', 'blog_posts', 'events', 'pages', 'faqs', 'staff', 'locations', 'jobs', 'testimonials'];
        $values     = $this->tokens($rowCount);
        $lookups    = array_slice($values, 0, 2000, true);
        $table      = $this->create('composite', "id INT PRIMARY KEY, tableName VARCHAR(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL, preSaveTempId VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, KEY lookup (tableName, preSaveTempId)");
        $this->load($table, $values, fn($i, $q) => "($i, '{$tableNames[$i % 10]}', $q)");

        $forms = [
            'a both'            => static fn($t, $v) => "tableName = $t AND preSaveTempId = $v",
            'b1 both'           => static fn($t, $v) => "tableName = CAST($t AS BINARY) AND preSaveTempId = CAST($v AS BINARY)",
            'b1 preSaveTempId'  => static fn($t, $v) => "tableName = $t AND preSaveTempId = CAST($v AS BINARY)",
            'f both'            => static fn($t, $v) => "tableName = $t AND tableName = CAST($t AS BINARY) AND preSaveTempId = $v AND preSaveTempId = CAST($v AS BINARY)",
            'd both'            => static fn($t, $v) => "tableName = $t COLLATE utf8mb4_bin AND preSaveTempId = $v COLLATE utf8mb4_bin",
        ];
        $index = array_flip($lookups);  // value => row number, which picks the row's tableName
        $rows  = [];
        foreach ($forms as $form => $sql) {
            $sqlFor = fn($v) => "SELECT id FROM $table WHERE " . $sql("'{$tableNames[$index[$v] % 10]}'", $this->quote($v));
            $rows[$form]['plan'] = $this->measure('composite (tableName, preSaveTempId)', $form, $sqlFor, array_values($lookups), keyLen: true);
        }
        $this->grids['plan: composite (tableName, preSaveTempId)'] = ['cols' => ['plan'], 'rows' => $rows];
        $this->dropTable($table);
    }

    #endregion
    #region Helpers

    /**
     * EXPLAIN, rows read and timing for one form on one index. Returns the plan cell.
     */
    private function measure(string $case, string $form, ?Closure $sqlFor, array $lookups, bool $keyLen = false): string
    {
        if ($sqlFor === null) {
            return 'n/a';
        }
        $plan = $this->plan($sqlFor($lookups[0]), $keyLen);
        if (!str_starts_with($plan, 'E')) {
            $this->reads[$case][$form] = $this->rowsRead($sqlFor($lookups[1]));
            $this->bench[$case][$form] = $this->time($sqlFor, $lookups);
        }
        return $plan;
    }

    /**
     * EXPLAIN as "type key rows", e.g. "ref plain_col 1" or "ALL - 99812", plus a W or E code.
     * With $keyLen, the key_len goes after the key: "ref lookup/1279 1".
     */
    private function plan(string $sql, bool $keyLen = false): string
    {
        $result = $this->run("EXPLAIN $sql", assoc: true);
        if ($result['error'] !== null) {
            return "E{$result['error']}";
        }
        $row = $result['rows'][0];
        $key = ($row['key'] ?? '-') . ($keyLen && $row['key'] !== null ? "/{$row['key_len']}" : '');
        return "{$row['type']} $key {$row['rows']}" . ($result['warnings'] ? ' W' . implode(',', $result['warnings']) : '');
    }

    /**
     * Rows the storage engine handed the server for one statement: the Handler_read
     * counters, less what SHOW STATUS adds itself.
     */
    private function rowsRead(string $sql): int
    {
        $this->mysqli->query("FLUSH STATUS");
        $this->mysqli->query($sql)->free();
        return $this->handlerReads() - $this->handlerBaseline;
    }

    private function handlerReads(): int
    {
        $total = 0;
        foreach ($this->mysqli->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetch_all() as [, $value]) {
            $total += (int)$value;
        }
        return $total;
    }

    /**
     * Index sizes in KB from the persistent statistics, after ANALYZE TABLE.
     *
     * @return array<string, int> index name => KB
     */
    private function indexSizes(string $quotedTable): array
    {
        $this->mysqli->query("ANALYZE TABLE $quotedTable")->fetch_all();
        $pageKb = (int)$this->mysqli->query("SELECT @@innodb_page_size")->fetch_row()[0] / 1024;
        $sizes  = [];
        try {
            $rows = $this->mysqli->query("SELECT index_name, stat_value FROM mysql.innodb_index_stats WHERE database_name = {$this->quote($this->database)} AND table_name = {$this->quote(trim($quotedTable, '`'))} AND stat_name = 'size'")->fetch_all();
            foreach ($rows as [$index, $pages]) {
                $sizes[$index] = (int)($pages * $pageKb);
            }
        } catch (mysqli_sql_exception) {
            // no persistent statistics on this server
        }
        return $sizes;
    }

    /**
     * Median µs per statement over three passes. A pass stops after 0.2 s, so a full scan
     * gets fewer lookups than an index lookup but still a stable average.
     */
    private function time(Closure $sqlForValue, array $lookups): float
    {
        $passes = [];
        for ($pass = 0; $pass < 3; $pass++) {
            $start = hrtime(true);
            $count = 0;
            foreach ($lookups as $value) {
                $this->mysqli->query($sqlForValue($value))->free();
                $count++;
                if ($count >= 20 && hrtime(true) - $start > 200_000_000) {
                    break;
                }
            }
            $passes[] = (hrtime(true) - $start) / 1000 / $count;
        }
        sort($passes);
        return round($passes[1], 1);
    }

    /**
     * Unique random tokens shaped like a CMS temp id, the same list on every server.
     *
     * @return list<string>
     */
    private function tokens(int $count): array
    {
        mt_srand(1);
        $values = [];
        while (count($values) < $count) {
            $values[sprintf('tmp%012x%08x', mt_rand(), mt_rand())] = true;
        }
        return array_keys($values);
    }

    /**
     * Insert the values 2,000 rows at a time. $tuple builds one row from the row number
     * and the quoted value.
     */
    private function load(string $table, array $values, Closure $tuple): void
    {
        foreach (array_chunk($values, 2000, true) as $chunk) {
            $tuples = [];
            foreach ($chunk as $i => $value) {
                $tuples[] = $tuple($i, $this->quote($value));
            }
            $this->mysqli->query("INSERT INTO $table VALUES " . implode(', ', $tuples));
        }
    }

    private function charsetOf(string $type): string
    {
        return preg_match('/CHARACTER SET (\w+)/', $type, $m) ? $m[1] : 'binary';
    }

    /**
     * Run one statement and collect its rows, warning codes, affected rows and error code.
     *
     * @return array{rows: ?array, warnings: list<int>, affected: int, error: ?int}
     */
    private function run(string $sql, bool $assoc = false): array
    {
        try {
            $result   = $this->mysqli->query($sql);
            $affected = $this->mysqli->affected_rows;
            $rows     = $result instanceof mysqli_result ? $result->fetch_all($assoc ? MYSQLI_ASSOC : MYSQLI_NUM) : null;
            return ['rows' => $rows, 'warnings' => $this->warnings(), 'affected' => (int)$affected, 'error' => null];
        } catch (mysqli_sql_exception $e) {
            return ['rows' => null, 'warnings' => [], 'affected' => 0, 'error' => $e->getCode()];
        }
    }

    /**
     * Warning codes from the last statement. EXPLAIN's own note (1003) is left out.
     *
     * @return list<int>
     */
    private function warnings(): array
    {
        if ($this->mysqli->warning_count === 0) {
            return [];
        }
        $codes = array_map('intval', array_column($this->mysqli->query("SHOW WARNINGS")->fetch_all(), 1));
        return array_values(array_unique(array_filter($codes, static fn($code) => $code !== 1003)));
    }

    /**
     * A grid cell: the error code, or the answer plus any warning codes.
     */
    private function cell(array $result, Closure $answer): string
    {
        if ($result['error'] !== null) {
            return "E{$result['error']}";
        }
        return $answer($result['rows']) . ($result['warnings'] ? ' W' . implode(',', $result['warnings']) : '');
    }

    private function create(string $name, string $columns): string
    {
        $table = "zdbexact_$name";
        $this->mysqli->query("DROP TABLE IF EXISTS `$table`");
        $this->mysqli->query("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
        return "`$table`";
    }

    private function dropTable(string $quotedTable): void
    {
        $this->mysqli->query("DROP TABLE IF EXISTS $quotedTable");
    }

    public function dropTables(): void
    {
        foreach ($this->mysqli->query("SHOW TABLES LIKE 'zdbexact\\_%'")->fetch_all() as [$table]) {
            $this->mysqli->query("DROP TABLE IF EXISTS `$table`");
        }
    }

    #endregion
}

/**
 * A grid as a markdown table: one row per row label, one column per grid column.
 */
function gridTable(array $grid): string
{
    $out = "|  | " . implode(' | ', $grid['cols']) . " |\n|---|" . str_repeat('---|', count($grid['cols'])) . "\n";
    foreach ($grid['rows'] as $label => $cells) {
        $out .= "| " . str_replace('|', '\|', (string)$label) . " | " . implode(' | ', array_map(static fn($c) => $cells[$c] ?? '', $grid['cols'])) . " |\n";
    }
    return $out;
}

/**
 * One report for every server: each fact and grid once, with the servers that gave each
 * answer, and the speed test as the median over servers.
 */
function mergeReports(array $paths): void
{
    $reports = [];
    foreach ($paths as $path) {
        $report = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (isset($report['server'], $report['grids'])) {
            $reports[$report['server']] = $report;
        } else {
            fwrite(STDERR, "Skipping $path: not an exact-match-probe JSON file\n");
        }
    }
    uksort($reports, static fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));
    $servers  = array_keys($reports);
    $families = serverFamilies($servers);
    $who      = static fn(array $list) => count($list) === count($servers) ? 'all servers' : (serverGroupLabel($list, $families) ?? implode(', ', $list));

    echo "# Exact Match Matrix\n\n";
    echo "Servers: " . implode(', ', array_map(static fn($s) => "$s ({$reports[$s]['version']})", $servers)) . "\n\n";
    echo <<<'__LEGEND__'
        Forms, with `?` sent the way ZenDB sends it (escaped, in quotes, text protocol). `a` is
        what ZenDB's where arrays and CMS Builder write now:

            a   col = ?
            b1  col = CAST(? AS BINARY)
            b2  col = BINARY ?
            c   CAST(col AS BINARY) = ?
            d   col = ? COLLATE utf8mb4_bin
            e   col LIKE ?
            f   col = ? AND col = CAST(? AS BINARY)
            g1  col = ? COLLATE utf8mb4_0900_bin     // NO PAD, MySQL 8.0.17+
            g2  col = ? COLLATE utf8mb4_0900_as_cs   // NO PAD, MySQL 8.0+
            g3  col = ? COLLATE utf8mb4_nopad_bin    // NO PAD, MariaDB

        Cells: yes/no = matched or not, E1234 = error code, W1234 = warning code, n/a = the
        server has no such collation, or the column can't hold the stored value as sent.
        Plans: EXPLAIN type, key, estimated rows. Rows read: the Handler_read counters for one
        lookup.


        __LEGEND__;

    // Facts: one line per answer
    $factNames = array_unique(array_merge(...array_map(static fn($r) => array_keys($r['facts']), array_values($reports))));
    echo "## Facts\n\n";
    foreach ($factNames as $name) {
        $byAnswer = [];
        foreach ($reports as $server => $report) {
            $byAnswer[$report['facts'][$name] ?? '(missing)'][] = $server;
        }
        if (count($byAnswer) === 1) {
            echo "- $name: " . mdValue((string)array_key_first($byAnswer)) . "\n";
            continue;
        }
        echo "- $name:\n";
        foreach ($byAnswer as $answer => $list) {
            echo "  - " . mdValue((string)$answer) . ": {$who($list)}\n";
        }
    }

    // Grids: one table per distinct grid, headed by the servers that gave it
    $gridNames = array_unique(array_merge(...array_map(static fn($r) => array_keys($r['grids']), array_values($reports))));
    foreach ($gridNames as $name) {
        echo "\n## $name\n\n";
        $byGrid = [];
        foreach ($reports as $server => $report) {
            if (isset($report['grids'][$name])) {
                $grid                         = str_starts_with($name, 'plan:') ? planShape($report['grids'][$name]) : $report['grids'][$name];
                $byGrid[json_encode($grid)][] = $server;
            }
        }
        foreach ($byGrid as $key => $list) {
            echo "On {$who($list)}" . (count($list) === count($reports) ? '' : " (" . count($list) . " servers)") . ":\n\n";
            echo gridTable(json_decode($key, true)) . "\n";
        }
    }

    // Index sizes: median KB over servers
    $sizes = [];
    foreach ($reports as $report) {
        foreach ($report['sizes'] ?? [] as $type => $indexes) {
            foreach ($indexes as $index => $kb) {
                $sizes[$type][$index][] = $kb;
            }
        }
    }
    if ($sizes) {
        $indexNames = ['PRIMARY', 'plain_col', 'unique_col', 'prefix_col'];
        echo "\n## Index size (KB, median over servers)\n\n|  | " . implode(' | ', $indexNames) . " |\n|---|" . str_repeat('---|', count($indexNames)) . "\n";
        foreach ($sizes as $type => $byIndex) {
            echo "| $type | " . implode(' | ', array_map(static fn($i) => isset($byIndex[$i]) ? (string)round(median($byIndex[$i])) : '', $indexNames)) . " |\n";
        }
    }

    // Speed and rows read: median over servers, and how each form compares with a
    echo "\n## Speed and rows read (median over servers)\n\n";
    $cases = [];
    foreach ($reports as $server => $report) {
        foreach ($report['bench'] as $case => $forms) {
            foreach ($forms as $form => $us) {
                $cases[$case][$form]['us'][$server]   = $us;
                $cases[$case][$form]['read'][$server] = $report['reads'][$case][$form] ?? null;
            }
        }
    }
    foreach ($cases as $case => $forms) {
        echo "### $case\n\n| form | µs per lookup | vs a | rows read | fastest server | slowest server |\n|---|---|---|---|---|---|\n";
        $baseline = [
            ''    => isset($forms['a']) ? median(array_values($forms['a']['us'])) : null,
            ' IN' => isset($forms['a IN']) ? median(array_values($forms['a IN']['us'])) : null,
        ];
        foreach ($forms as $form => $data) {
            $byServer = $data['us'];
            asort($byServer);
            $us    = median(array_values($byServer));
            $base  = $baseline[str_ends_with($form, ' IN') ? ' IN' : ''] ?? $baseline[''];
            $reads = array_filter($data['read'], static fn($r) => $r !== null);
            echo "| $form | " . round($us, 1) . " | " . ($base ? round($us / $base, 2) . 'x' : '') . " | " . ($reads ? round(median(array_values($reads))) : '') . " | " . reset($byServer) . " (" . key($byServer) . ") | " . end($byServer) . " (" . key($byServer) . ") |\n";
        }
        echo "\n";
    }
}

/**
 * A plan grid with the row estimates dropped, so servers whose statistics differ by a few
 * rows group together. Keeps the type, the key and any error or warning.
 */
function planShape(array $grid): array
{
    foreach ($grid['rows'] as $label => $cells) {
        foreach ($cells as $col => $cell) {
            $grid['rows'][$label][$col] = preg_replace('/^(\S+ \S+) \d+/', '$1', $cell);  // "ref plain_col 1 W1287" → "ref plain_col W1287"
        }
    }
    return $grid;
}

function median(array $numbers): float
{
    sort($numbers);
    $count = count($numbers);
    return $count % 2 ? (float)$numbers[intdiv($count, 2)] : ($numbers[$count / 2 - 1] + $numbers[$count / 2]) / 2;
}
