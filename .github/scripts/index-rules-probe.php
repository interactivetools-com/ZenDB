#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fact-check the index rules CMS Builder uses when it auto-creates an index on a
 * field, on one database server. Plain mysqli, no ZenDB.
 *
 *     php .github/scripts/index-rules-probe.php                     # markdown to stdout
 *     php .github/scripts/index-rules-probe.php --json=probe.json   # markdown to stdout, results to JSON
 *
 * The CI workflow (.github/workflows/index-rules-matrix.yml) runs this against every
 * database image in the matrix; index-rules-merge.php turns the JSON files into one
 * table per question with a row per server.
 *
 * Every probe runs in its own scratch database (zdb_index_probe_<pid>) that is dropped
 * on exit, so nothing is left behind. Tables mirror what the CMS creates:
 * ENGINE=InnoDB ROW_FORMAT=DYNAMIC CHARSET=utf8mb4, no COLLATE. Each case creates
 * table `t` with one column `col` and runs CREATE INDEX `_auto_col` on it, then
 * reads back SHOW INDEX Sub_part and the index line from SHOW CREATE TABLE.
 *
 * Connects with the same DB_* env vars as the test suite (see phpunit.xml.dist).
 * DB_LABEL names the server in the JSON output, e.g. "mariadb:10.6" from the CI matrix.
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$hostname = getenv('DB_HOSTNAME') ?: '127.0.0.1';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$label    = getenv('DB_LABEL') ?: 'unlabeled';
$jsonPath = getopt('', ['json:'])['json'] ?? null;
$scratch  = 'zdb_index_probe_' . getmypid();

$report = ['server' => $label, 'identity' => [], 'questions' => []]; // question => [case label => result]

$mysqli = new mysqli($hostname, $username, $password);
$mysqli->set_charset('utf8mb4');
$mysqli->query("DROP DATABASE IF EXISTS `$scratch`");
$mysqli->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4");
$mysqli->select_db($scratch);

// Drop the scratch database and write the JSON on every exit path, including a fatal
// partway through, so a broken probe still reports what it collected
register_shutdown_function(function () use ($mysqli, $scratch, $jsonPath, &$report) {
    try {
        $mysqli->query("DROP DATABASE IF EXISTS `$scratch`");
    } catch (Throwable) {
        fwrite(STDERR, "Could not drop scratch database $scratch\n");
    }
    if ($jsonPath !== null) {
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        fwrite(STDERR, "Wrote " . count($report['questions']) . " question blocks to $jsonPath\n");
    }
});

//region Helpers

/**
 * Read a server variable, or null when the server doesn't have it.
 */
function serverVariable(mysqli $mysqli, string $name): ?string
{
    $row = $mysqli->query("SHOW GLOBAL VARIABLES LIKE '$name'")->fetch_row();
    return $row === null ? null : $row[1];
}

/**
 * Run one CREATE TABLE + CREATE INDEX case and read back what the server did with it.
 *
 * Result keys: ok (bool), code + error (on failure), warnings (list of "code: message"
 * raised by a successful CREATE INDEX), sub_part (SHOW INDEX Sub_part, 'NULL' when the
 * whole column is indexed), show_create (the KEY line from SHOW CREATE TABLE), and
 * table_error when the CREATE TABLE itself was rejected.
 */
function indexCase(mysqli $mysqli, string $columnType, string $prefix = '', string $tableOptions = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC CHARSET=utf8mb4'): array
{
    $mysqli->query("DROP TABLE IF EXISTS t");
    try {
        $mysqli->query("CREATE TABLE t (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, col $columnType) $tableOptions");
    } catch (mysqli_sql_exception $e) {
        return ['ok' => false, 'table_error' => true, 'code' => $e->getCode(), 'error' => $e->getMessage()];
    }

    try {
        $mysqli->query("CREATE INDEX `_auto_col` ON `t` (`col`$prefix)");
    } catch (mysqli_sql_exception $e) {
        return ['ok' => false, 'code' => $e->getCode(), 'error' => $e->getMessage()];
    }

    $warnings = [];
    if ($mysqli->warning_count > 0) {
        foreach ($mysqli->query("SHOW WARNINGS")->fetch_all(MYSQLI_ASSOC) as $w) {
            $warnings[] = "$w[Code]: $w[Message]";
        }
    }

    return ['ok' => true, 'warnings' => $warnings] + indexDetails($mysqli);
}

/**
 * SHOW INDEX Sub_part and the SHOW CREATE TABLE line for `_auto_col` on table t.
 */
function indexDetails(mysqli $mysqli): array
{
    $index    = $mysqli->query("SHOW INDEX FROM t WHERE Key_name = '_auto_col'")->fetch_assoc();
    $subPart  = $index === null ? '(index missing)' : ($index['Sub_part'] ?? 'NULL');
    $create   = $mysqli->query("SHOW CREATE TABLE t")->fetch_row()[1];
    preg_match('/^\s*(KEY `_auto_col`.*?),?$/m', $create, $m);
    return ['sub_part' => (string)$subPart, 'show_create' => $m[1] ?? '(no KEY line found)'];
}

/**
 * Format a case result as one markdown cell.
 */
function cell(array $r): string
{
    if (!$r['ok']) {
        $where = !empty($r['table_error']) ? 'CREATE TABLE ' : '';
        return "{$where}ERR $r[code]";
    }
    $out = 'OK';
    if (isset($r['sub_part'])) {
        $out .= " Sub_part=$r[sub_part]";
    }
    if (!empty($r['warnings'])) {
        $out .= ' warn ' . implode('; ', array_map(fn($w) => explode(':', $w, 2)[0], $r['warnings']));
    }
    if (isset($r['using_filesort'])) {
        $out .= " key=$r[explain_key] filesort=$r[using_filesort]";
    }
    return $out;
}

/**
 * Print one question as a markdown table (case, result) plus every error and warning
 * message in full below it.
 */
function printQuestion(string $title, array $cases): void
{
    echo "### $title\n\n| case | result |\n|---|---|\n";
    $messages = [];
    foreach ($cases as $name => $r) {
        echo "| $name | " . cell($r) . " |\n";
        if (!$r['ok']) {
            $messages["$r[code]"] = $r['error'];
        }
        foreach ($r['warnings'] ?? [] as $w) {
            [$code, $msg] = explode(': ', $w, 2);
            $messages["warning $code"] = $msg;
        }
        if (isset($r['show_create'])) {
            $messages["SHOW CREATE ($name)"] = $r['show_create'];
        }
        if (isset($r['note'])) {
            $messages["note ($name)"] = $r['note'];
        }
    }
    echo "\n";
    foreach ($messages as $k => $msg) {
        echo "- $k: `$msg`\n";
    }
    echo "\n";
}

//endregion

//
// Server identity: everything that could move the limits
//
[$version, $collationServer, $pageSize] = $mysqli->query("SELECT VERSION(), @@collation_server, @@innodb_page_size")->fetch_row();
$mysqli->query("CREATE TABLE t (col INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC CHARSET=utf8mb4");
$createTail = $mysqli->query("SHOW CREATE TABLE t")->fetch_row()[1];
preg_match('/\) (ENGINE=.*)$/s', $createTail, $tail);
$report['identity'] = [
    'VERSION()'                   => $version,
    '@@innodb_page_size'          => $pageSize,
    '@@innodb_default_row_format' => serverVariable($mysqli, 'innodb_default_row_format') ?? '(missing)',
    '@@innodb_large_prefix'       => serverVariable($mysqli, 'innodb_large_prefix') ?? '(missing)',
    '@@innodb_strict_mode'        => serverVariable($mysqli, 'innodb_strict_mode') ?? '(missing)',
    '@@sql_mode'                  => serverVariable($mysqli, 'sql_mode'),
    '@@collation_server'          => $collationServer,
    'table options as created'    => $tail[1] ?? $createTail,
];
echo "## $label\n\n### Server\n\n| setting | value |\n|---|---|\n";
foreach ($report['identity'] as $k => $v) {
    echo "| $k | `$v` |\n";
}
echo "\n";

//
// Q1. TEXT/BLOB family with no prefix - expect 1170 everywhere
//
$q1 = [];
foreach (['TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB'] as $type) {
    $q1["$type no prefix"] = indexCase($mysqli, $type);
}
$report['questions']['1. Key length required'] = $q1;
printQuestion('1. Key length required', $q1);

//
// Q2. Prefix longer than the column on TINYTEXT/TINYBLOB (255 bytes max)
//
$q2 = [
    'TINYTEXT (768)' => indexCase($mysqli, 'TINYTEXT', '(768)'),
    'TINYBLOB (768)' => indexCase($mysqli, 'TINYBLOB', '(768)'),
    'TINYTEXT (255)' => indexCase($mysqli, 'TINYTEXT', '(255)'),
    'TINYTEXT (63)'  => indexCase($mysqli, 'TINYTEXT', '(63)'),
    'TINYBLOB (255)' => indexCase($mysqli, 'TINYBLOB', '(255)'),
];
$report['questions']['2. Prefix longer than the column'] = $q2;
printQuestion('2. Prefix longer than the column', $q2);

//
// Q3. VARCHAR(255): prefix equal to the column length vs no prefix vs shorter.
// 2000 rows so the optimizer has a reason to walk the index for ORDER BY ... LIMIT
//
$mysqli->query("DROP TABLE IF EXISTS t");
$mysqli->query("CREATE TABLE t (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, col VARCHAR(255) NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC CHARSET=utf8mb4");
for ($batch = 0; $batch < 4; $batch++) {
    $values = [];
    for ($i = 0; $i < 500; $i++) {
        $values[] = "('" . md5((string)($batch * 500 + $i)) . "')";
    }
    $mysqli->query("INSERT INTO t (col) VALUES " . implode(',', $values));
}
$q3 = [];
foreach (['no prefix' => '', '(255)' => '(255)', '(250)' => '(250)'] as $name => $prefix) {
    try {
        $mysqli->query("CREATE INDEX `_auto_col` ON `t` (`col`$prefix)");
    } catch (mysqli_sql_exception $e) {
        $q3["VARCHAR(255) $name"] = ['ok' => false, 'code' => $e->getCode(), 'error' => $e->getMessage()];
        continue;
    }
    $mysqli->query("ANALYZE TABLE t");
    $query   = "SELECT * FROM t ORDER BY col LIMIT 10";
    $explain = $mysqli->query("EXPLAIN $query")->fetch_assoc();
    // MySQL 9.x (hypergraph optimizer) leaves key/Extra empty in the traditional
    // format, so the TREE format decides where the server supports it
    try {
        $tree = str_replace("\n", ' / ', trim($mysqli->query("EXPLAIN FORMAT=TREE $query")->fetch_row()[0]));
    } catch (mysqli_sql_exception) {
        $tree = null;
    }
    $extra       = $explain['Extra'] ?? '';
    $usesIndex   = ($explain['key'] ?? null) === '_auto_col' || ($tree !== null && str_contains($tree, '_auto_col'));
    $usesFilesort = str_contains($extra, 'filesort') || ($tree !== null && str_contains($tree, 'Sort:'));
    $q3["VARCHAR(255) $name"] = ['ok' => true, 'warnings' => []] + indexDetails($mysqli) + [
        'explain_key'    => $usesIndex ? '_auto_col' : 'NULL',
        'using_filesort' => $usesFilesort ? 'yes' : 'no',
        'note'           => "EXPLAIN key=" . ($explain['key'] ?? 'NULL') . " Extra=" . ($extra === '' ? '(empty)' : $extra) . ($tree === null ? '' : "; TREE: $tree"),
    ];
    $mysqli->query("DROP INDEX `_auto_col` ON `t`");
}
$report['questions']['3. VARCHAR prefix equal to the column length'] = $q3;
printQuestion('3. VARCHAR prefix equal to the column length', $q3);

//
// Q4. Where the cap is on ROW_FORMAT=DYNAMIC utf8mb4
//
$q4 = [
    'VARCHAR(768) no prefix'    => indexCase($mysqli, 'VARCHAR(768)'),
    'VARCHAR(769) no prefix'    => indexCase($mysqli, 'VARCHAR(769)'),
    'VARCHAR(2000) no prefix'   => indexCase($mysqli, 'VARCHAR(2000)'),
    'VARBINARY(3072) no prefix' => indexCase($mysqli, 'VARBINARY(3072)'),
    'VARBINARY(3073) no prefix' => indexCase($mysqli, 'VARBINARY(3073)'),
    'VARCHAR(2000) (768)'       => indexCase($mysqli, 'VARCHAR(2000)', '(768)'),
    'VARCHAR(2000) (769)'       => indexCase($mysqli, 'VARCHAR(2000)', '(769)'),
];
$report['questions']['4. Where the cap is (DYNAMIC)'] = $q4;
printQuestion('4. Where the cap is (DYNAMIC)', $q4);

//
// Q5. Other row formats, and innodb_large_prefix where the server still has it
//
$q5 = [];
foreach (['DYNAMIC', 'COMPACT', 'REDUNDANT'] as $format) {
    $options = "ENGINE=InnoDB ROW_FORMAT=$format CHARSET=utf8mb4";
    $q5["$format VARCHAR(768) no prefix"] = indexCase($mysqli, 'VARCHAR(768)', '', $options);
    $q5["$format TEXT (768)"]             = indexCase($mysqli, 'TEXT', '(768)', $options);
}
// MariaDB 10.3-10.5 still list innodb_large_prefix but as a read-only empty variable
$largePrefix = serverVariable($mysqli, 'innodb_large_prefix');
if ($largePrefix === 'ON' || $largePrefix === 'OFF') {
    $flipped = $largePrefix === 'ON' ? 'OFF' : 'ON';
    $mysqli->query("SET GLOBAL innodb_large_prefix = $flipped");
    try {
        foreach (['DYNAMIC', 'COMPACT'] as $format) {
            $options = "ENGINE=InnoDB ROW_FORMAT=$format CHARSET=utf8mb4";
            $q5["large_prefix=$flipped $format VARCHAR(768) no prefix"] = indexCase($mysqli, 'VARCHAR(768)', '', $options);
            $q5["large_prefix=$flipped $format TEXT (768)"]             = indexCase($mysqli, 'TEXT', '(768)', $options);
        }
    } finally {
        $mysqli->query("SET GLOBAL innodb_large_prefix = $largePrefix");
    }
}
$report['questions']['5. Other row formats'] = $q5;
printQuestion('5. Other row formats', $q5);

//
// Q6. Types that can't take a plain index
//
$q6 = [];
foreach (['CHAR(255)', 'BINARY(255)', "ENUM('a','b')", "SET('a','b')", 'DECIMAL(10,2)', 'DATETIME', 'JSON', 'GEOMETRY'] as $type) {
    $q6["$type no prefix"] = indexCase($mysqli, $type);
}
$report['questions']['6. Types that cannot take a plain index'] = $q6;
printQuestion('6. Types that cannot take a plain index', $q6);

//
// Q7. Lowercase type name - same behavior, and how SHOW CREATE TABLE prints the column
//
$q7 = [
    'tinytext no prefix' => indexCase($mysqli, 'tinytext'),
    'tinytext (768)'     => indexCase($mysqli, 'tinytext', '(768)'),
    'tinytext (63)'      => indexCase($mysqli, 'tinytext', '(63)'),
];
$mysqli->query("DROP TABLE IF EXISTS t");
$mysqli->query("CREATE TABLE t (col tinytext, col2 TINYTEXT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC CHARSET=utf8mb4");
preg_match_all('/^\s*(`col2?` .*?),?$/m', $mysqli->query("SHOW CREATE TABLE t")->fetch_row()[1], $m);
$q7['SHOW CREATE TABLE column lines'] = ['ok' => true, 'warnings' => [], 'note' => implode(' / ', $m[1])];
$report['questions']['7. Case of the type name'] = $q7;
printQuestion('7. Case of the type name', $q7);
