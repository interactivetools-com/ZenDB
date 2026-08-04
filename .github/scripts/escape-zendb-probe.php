<?php
declare(strict_types=1);

/**
 * ZenDB versus raw mysqli: DB::select()/selectOne()/query() and hand-written
 * mysqli doing the identical work, timed as whole queries against a live server.
 *
 *     php .github/scripts/escape-zendb-probe.php [--json=out.json] [--filter=id1,id2] [--scale=1.0]
 *
 * Unlike the other probes this one loads the real library (composer install
 * required), because the question is how ZenDB's full pipeline compares to
 * raw mysqli: placeholder parse + escaping on the way in, round trip, and
 * SmartArray/SmartString wrapping on the way out. Point queries measure the
 * per-query difference; the 1000-row fetch cells isolate the
 * per-row wrapping cost, with and without touching a field (SmartString
 * encoding is paid at output time, so both numbers matter). The raw side that
 * touches fields calls htmlspecialchars() - the work SmartString's encoding
 * replaces - so both sides end XSS-safe; raw fetch-only rows are labeled as
 * doing less (no output encoding).
 *
 * Same paired A/B design and DB_* env vars as escape-e2e-probe.php.
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found - run: composer install\n");
    exit(1);
}
require $autoload;
require __DIR__ . '/escape-corpus.php';

use Itools\ZenDB\DB;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$opts    = getopt('', ['json::', 'filter::', 'scale::']);
$filter  = isset($opts['filter']) ? array_flip(array_map('trim', explode(',', (string)$opts['filter']))) : null;
$scale   = isset($opts['scale']) ? max(0.01, (float)$opts['scale']) : 1.0;
$rtIters = max(50, (int)(1000 * $scale));    // point-query cells
$fetchIters = max(20, (int)(200 * $scale));  // 1000-row fetch cells

$hostname = getenv('DB_HOSTNAME') ?: '127.0.0.1';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_DATABASE') ?: 'phpunit_test_db';

DB::connect([
    'hostname'           => $hostname . (getenv('DB_PORT') ? ':' . getenv('DB_PORT') : ''),
    'username'           => $username,
    'password'           => $password,
    'database'           => $database,
    'tablePrefix'        => 'zenbench_',
    'databaseAutoCreate' => true,
    'connectTimeout'     => 5,
]);
$mysqli = DB::$mysqli;   // ZenDB's own connection doubles as the raw-side handle

//region Environment facts and scratch data

$pings = [];
for ($i = 0; $i < 200; $i++) {
    $t = hrtime(true);
    $mysqli->query('SELECT 1')->fetch_row();
    $pings[] = hrtime(true) - $t;
}
sort($pings);

$out = [
    'server_label' => 'zendb-vs-raw php' . preg_replace('/^(\d+\.\d+)\..*$/', '$1', PHP_VERSION),
    'php'          => PHP_VERSION,
    'os'           => PHP_OS_FAMILY,
    'arch'         => php_uname('m'),
    'zts'          => ZEND_THREAD_SAFE,
    'opcache'      => (bool)ini_get('opcache.enable_cli'),
    'jit'          => (int)ini_get('opcache.jit_buffer_size') > 0 ? (string)ini_get('opcache.jit') : 'off',
    'xdebug'       => extension_loaded('xdebug'),
    'mysqlnd'      => mysqli_get_client_info(),
    'server'       => $mysqli->server_info,
    'ping_us'      => round($pings[100] / 1000, 1),
    'corpus'       => null,
    'tests'        => [],
];

/** Deterministic pseudo-random ASCII word soup, no escapables. */
function build_clean(int $len, int $seed): string
{
    mt_srand($seed);
    $words = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'fox', 'golf', 'hotel', 'india', 'kilo'];
    $s = '';
    while (strlen($s) < $len) {
        $s .= $words[mt_rand(0, 9)] . ' ';
    }
    return substr($s, 0, $len);
}

$mysqli->query('DROP TABLE IF EXISTS zenbench_kv');
$mysqli->query('CREATE TABLE zenbench_kv (id INT PRIMARY KEY AUTO_INCREMENT,
    a VARCHAR(255) NOT NULL, b VARCHAR(255) NOT NULL, num INT NOT NULL,
    KEY idx_a (a)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// 1000 rows, some values with apostrophes, for point queries and full fetches
$pool = [];
for ($i = 0; $i < 64; $i++) {
    $v = build_clean(24, 100 + $i);
    $pool[] = $i % 3 === 0 ? substr($v, 0, 8) . "'" . substr($v, 9) : $v;
}
$poolN = count($pool);
$stmt  = $mysqli->prepare('INSERT INTO zenbench_kv (a, b, num) VALUES (?, ?, ?)');
for ($i = 0; $i < 1000; $i++) {
    $num = $i % 250;
    $stmt->bind_param('ssi', $pool[$i % $poolN], $pool[($i + 1) % $poolN], $num);
    $stmt->execute();
}
$stmt->close();

//endregion
//region Cells

/** Interleaved paired benchmark; returns [a_ns, b_ns] per-op bests. */
function ab_bench_rt(callable $a, callable $b, int $iters, int $reps = 7): array
{
    $bestA  = INF;
    $bestB  = INF;
    $warmup = max(5, intdiv($iters, 20));
    $a($warmup);
    $b($warmup);
    for ($r = 0; $r < $reps; $r++) {
        $t = hrtime(true);
        $a($iters);
        $ns = (hrtime(true) - $t) / $iters;
        if ($ns < $bestA) {
            $bestA = $ns;
        }
        $t = hrtime(true);
        $b($iters);
        $ns = (hrtime(true) - $t) / $iters;
        if ($ns < $bestB) {
            $bestB = $ns;
        }
    }
    return [$bestA, $bestB];
}

$GLOBALS['sink'] = 0;

// --- Point query by string (the everyday WHERE shape) ---
$rawSelectByA = static function (int $iters) use ($mysqli, $pool, $poolN): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = $mysqli->query("SELECT * FROM zenbench_kv WHERE a = '"
            . $mysqli->real_escape_string($pool[$i % $poolN]) . "' LIMIT 10")->fetch_all(MYSQLI_ASSOC);
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};
$zenSelectByA = static function (int $iters) use ($pool, $poolN): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = DB::select('kv', 'a = ? LIMIT 10', $pool[$i % $poolN]);
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};

// --- Point query by int (WHERE id = number: no escaping on either side) ---
$rawSelectById = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row = $mysqli->query('SELECT * FROM zenbench_kv WHERE id = ' . ($i % 1000 + 1))->fetch_assoc();
        $acc += $row === null ? 0 : 1;
    }
    $GLOBALS['sink'] += $acc;
};
$zenSelectById = static function (int $iters): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row = DB::selectOne('kv', $i % 1000 + 1);
        $acc += count($row) > 0 ? 1 : 0;
    }
    $GLOBALS['sink'] += $acc;
};

// --- Raw SQL with placeholders vs hand-escaped raw SQL ---
$zenQuery = static function (int $iters) use ($pool, $poolN): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = DB::query('SELECT * FROM ::kv WHERE a = ? OR num = ? LIMIT 10',
            $pool[$i % $poolN], $i % 250);
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};
$rawQuery = static function (int $iters) use ($mysqli, $pool, $poolN): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = $mysqli->query("SELECT * FROM zenbench_kv WHERE a = '"
            . $mysqli->real_escape_string($pool[$i % $poolN]) . "' OR num = " . ($i % 250)
            . ' LIMIT 10')->fetch_all(MYSQLI_ASSOC);
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};

// --- 1000-row fetch: wrapping cost, fetch-only (raw side does less: no output encoding) ---
$rawFetchAll = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = $mysqli->query('SELECT * FROM zenbench_kv')->fetch_all(MYSQLI_ASSOC);
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};
$zenFetchAll = static function (int $iters): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = DB::select('kv');
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};

// --- 1000-row fetch + output one field per row, both sides XSS-safe ---
$rawFetchTouch = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = $mysqli->query('SELECT * FROM zenbench_kv')->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $acc += strlen(htmlspecialchars($row['a'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
    }
    $GLOBALS['sink'] += $acc;
};
$zenFetchTouch = static function (int $iters): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        foreach (DB::select('kv') as $row) {
            $acc += strlen((string)$row->a);
        }
    }
    $GLOBALS['sink'] += $acc;
};

$tests = [
    ['zvr-selftie', 'rt', 'raw select by string', 'raw select by string (same)', $rawSelectByA, $rawSelectByA],
    ['zvr-select-string', 'rt', 'raw mysqli + real_escape + fetch_all', 'DB::select(kv, a = ?)', $rawSelectByA, $zenSelectByA],
    ['zvr-select-int', 'rt', 'raw mysqli WHERE id = int', 'DB::selectOne(kv, id)', $rawSelectById, $zenSelectById],
    ['zvr-query-raw-sql', 'rt', 'raw mysqli hand-escaped SQL', 'DB::query with placeholders', $rawQuery, $zenQuery],
    ['zvr-fetch-1000', 'fetch', 'raw fetch_all assoc (no encoding)', 'DB::select full table', $rawFetchAll, $zenFetchAll],
    ['zvr-fetch-touch-1000', 'fetch', 'raw fetch_all + htmlspecialchars per row', 'DB::select + SmartString output per row', $rawFetchTouch, $zenFetchTouch],
];

foreach ($tests as [$id, $class, $aLabel, $bLabel, $aFn, $bFn]) {
    if ($filter !== null && !isset($filter[$id])) {
        continue;
    }
    [$aNs, $bNs] = ab_bench_rt($aFn, $bFn, $class === 'rt' ? $rtIters : $fetchIters);
    $ratio = $aNs / $bNs; // > 1: B (ZenDB side) faster
    $out['tests'][$id] = [
        'a_label' => $aLabel, 'b_label' => $bLabel, 'sink' => 'wall',
        'a_ns'    => round($aNs, 0), 'b_ns' => round($bNs, 0),
        'a_us'    => round($aNs / 1000, 1), 'b_us' => round($bNs / 1000, 1),
        'ratio'   => round($ratio, 3),
        'verdict' => $ratio >= 1.05 ? 'B_FASTER' : ($ratio <= 0.952 ? 'A_FASTER' : 'TIE'),
    ];
}

$mysqli->query('DROP TABLE IF EXISTS zenbench_kv');

//endregion
//region Report

printf("### %s | PHP %s%s | %s | ping %sus%s\n\n",
    $out['server_label'], $out['php'], $out['zts'] ? ' ZTS' : '', $out['server'], $out['ping_us'],
    $out['xdebug'] ? ' **XDEBUG LOADED - RESULTS INVALID**' : '');
echo "Ratios read as B vs A: >1.00 means the ZenDB side is faster; <1.00 measures what ZenDB's extra work (placeholders, XSS-safe results) currently costs.\n\n";
echo "| test | A | B | A us | B us | B vs A | verdict |\n|---|---|---|---|---|---|---|\n";
foreach ($out['tests'] as $id => $t) {
    printf("| %s | %s | %s | %.1f | %.1f | %.2fx | %s |\n",
        $id, $t['a_label'], $t['b_label'], $t['a_us'], $t['b_us'], $t['ratio'], $t['verdict']);
}

if (isset($opts['json'])) {
    file_put_contents((string)$opts['json'], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
exit(0);

//endregion
