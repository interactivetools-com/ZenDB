<?php
declare(strict_types=1);

/**
 * ZenDB versus raw mysqli versus fresh-prepared mysqli, on real-world page
 * scenarios, timed as whole queries against a live server.
 *
 *     php .github/scripts/escape-zendb-probe.php [--json=out.json] [--filter=id1,id2] [--scale=1.0]
 *
 * Unlike the other probes this one loads the real library (composer install
 * required), because the question is what each data-access style costs on the
 * pages websites actually serve. Scenarios cover the common shapes: load one
 * record (detail page), a handful (widget), 25 (list page), and 100 (the
 * largest listing worth optimizing for), each consumed the way pages consume
 * them - HTML-encoded output or raw arrays. Every scenario pairs the same
 * raw-mysqli baseline (interpolated SQL + real_escape_string, htmlspecialchars
 * on output) against one candidate:
 *
 *   *-zendb     DB::selectOne()/query(), SmartString output or ->toArray()
 *   *-prepared  fresh mysqli prepare/bind/execute per query (the PHP-FPM
 *               reality: statement handles don't outlive the request), same
 *               output work as raw
 *
 * Test data is the news corpus from SmartArray/benchmarks/news-page.php:
 * title ~60 B with an apostrophe, summary ~300 B and content ~5 KB of prose at
 * corpus-measured special-character density (~1.3% apostrophes, & < > absent).
 * Before any timing, ZenDB's HTML output is verified byte-identical to the
 * baseline's htmlspecialchars() loop.
 *
 * Prepared statements pay a real extra round trip for prepare(), so they read
 * worse as ping grows; each cell reports whole-query wall time on loopback,
 * where round trips are cheapest and every difference is at its largest.
 *
 * Same paired A/B design and DB_* env vars as escape-e2e-probe.php.
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found - run: composer install\n");
    exit(1);
}
require $autoload;

use Itools\ZenDB\DB;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$probeStart = hrtime(true);

$opts   = getopt('', ['json::', 'filter::', 'scale::']);
$filter = isset($opts['filter']) ? array_flip(array_map('trim', explode(',', (string)$opts['filter']))) : null;
$scale  = isset($opts['scale']) ? max(0.01, (float)$opts['scale']) : 1.0;

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

//region Environment facts

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

//endregion
//region Test data - news corpus (from SmartArray/benchmarks/news-page.php)

// Headline with one apostrophe (quotes in headlines are common; 50 bytes)
const UNIT_TITLE = "Mayor Says 'No' to Downtown Towers Plan This Year ";
// Prose with a quoted phrase and an apostrophe per ~220 chars (~1.3% specials)
const UNIT_PROSE = "The company's third-quarter report shows steady growth in every region, and the board called the results \"very encouraging\" in its letter to shareholders. Management expects the same pace next year as new locations open. ";

const CATEGORIES = ['news', 'sports', 'business', 'tech', 'arts', 'travel', 'health', 'science', 'opinion', 'local'];

/** ~$bytes of text from $unit, rotated by $shift chars so each record differs */
function from_unit(string $unit, int $bytes, int $shift): string
{
    $shift = $shift % strlen($unit);
    $rot   = substr($unit, $shift) . substr($unit, 0, $shift);
    return substr(str_repeat($rot, intdiv($bytes, strlen($rot)) + 1), 0, $bytes);
}

/** The baseline output call: the standard safe helper wrapped once per project */
function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8');
}

$mysqli->query('DROP TABLE IF EXISTS zenbench_news');
$mysqli->query('CREATE TABLE zenbench_news (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    category   VARCHAR(30)  NOT NULL,
    title      VARCHAR(255) NOT NULL,
    summary    TEXT         NOT NULL,
    content    MEDIUMTEXT   NOT NULL,
    created_at DATETIME     NOT NULL,
    KEY idx_category_created (category, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// 1000 rows: 100 per category, staggered timestamps, every row's text distinct
fwrite(STDERR, "building news corpus: 1,000 rows ...\n");
$stmt = $mysqli->prepare('INSERT INTO zenbench_news (category, title, summary, content, created_at) VALUES (?, ?, ?, ?, ?)');
for ($i = 0; $i < 1000; $i++) {
    $category  = CATEGORIES[$i % 10];
    $title     = from_unit(UNIT_TITLE, 60, $i * 3);
    $summary   = from_unit(UNIT_PROSE, 300, $i * 7);
    $content   = from_unit(UNIT_PROSE, 5000, $i * 13);
    $createdAt = gmdate('Y-m-d H:i:s', 1767225600 + $i * 3600);   // 2026-01-01 00:00:00 UTC + 1h per row
    $stmt->bind_param('sssss', $category, $title, $summary, $content, $createdAt);
    $stmt->execute();
}
$stmt->close();

//endregion
//region Scenario cells

/** Interleaved paired benchmark; returns [a_ns, b_ns] per-op bests. */
function ab_bench(callable $a, callable $b, int $iters, int $reps = 7): array
{
    $bestA  = INF;
    $bestB  = INF;
    $warmup = max(3, intdiv($iters, 20));
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

// --- Detail page: one record by id, output title + content as HTML ---
$rawDetailHtml = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row = $mysqli->query('SELECT * FROM zenbench_news WHERE id = ' . ($i % 1000 + 1))->fetch_assoc();
        $acc += strlen(e($row['title'])) + strlen(e($row['content']));
    }
    $GLOBALS['sink'] += $acc;
};
$preparedDetailHtml = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $id   = $i % 1000 + 1;
        $stmt = $mysqli->prepare('SELECT * FROM zenbench_news WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $acc += strlen(e($row['title'])) + strlen(e($row['content']));
    }
    $GLOBALS['sink'] += $acc;
};
$zenDetailHtml = static function (int $iters): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row = DB::selectOne('news', ['id' => $i % 1000 + 1]);
        $acc += strlen((string)$row->title) + strlen((string)$row->content);
    }
    $GLOBALS['sink'] += $acc;
};

// --- Detail page: one record by id, raw array out (logic/API use) ---
$rawDetailRaw = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row = $mysqli->query('SELECT * FROM zenbench_news WHERE id = ' . ($i % 1000 + 1))->fetch_assoc();
        $acc += count($row);
    }
    $GLOBALS['sink'] += $acc;
};
$preparedDetailRaw = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $id   = $i % 1000 + 1;
        $stmt = $mysqli->prepare('SELECT * FROM zenbench_news WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $acc += count($row);
    }
    $GLOBALS['sink'] += $acc;
};
$zenDetailRaw = static function (int $iters): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row = DB::selectOne('news', ['id' => $i % 1000 + 1])->toArray();
        $acc += count($row);
    }
    $GLOBALS['sink'] += $acc;
};

// --- List pages: N newest in a category, output title (+summary) as HTML ---
$rawList = static function (int $iters, int $limit, bool $withSummary) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $category = $mysqli->real_escape_string(CATEGORIES[$i % 10]);
        $rows     = $mysqli->query("SELECT * FROM zenbench_news WHERE category = '$category'
            ORDER BY created_at DESC LIMIT $limit")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $acc += strlen(e($row['title'])) + ($withSummary ? strlen(e($row['summary'])) : 0);
        }
    }
    $GLOBALS['sink'] += $acc;
};
$preparedList = static function (int $iters, int $limit, bool $withSummary) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $category = CATEGORIES[$i % 10];
        $stmt     = $mysqli->prepare("SELECT * FROM zenbench_news WHERE category = ? ORDER BY created_at DESC LIMIT $limit");
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $row) {
            $acc += strlen(e($row['title'])) + ($withSummary ? strlen(e($row['summary'])) : 0);
        }
    }
    $GLOBALS['sink'] += $acc;
};
$zenList = static function (int $iters, int $limit, bool $withSummary): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = DB::query("SELECT * FROM ::news WHERE category = ? ORDER BY created_at DESC LIMIT $limit", CATEGORIES[$i % 10]);
        foreach ($rows as $row) {
            $acc += strlen((string)$row->title) + ($withSummary ? strlen((string)$row->summary) : 0);
        }
    }
    $GLOBALS['sink'] += $acc;
};

// --- List page, raw arrays out (JSON/API use, no HTML) ---
$rawListRaw = static function (int $iters, int $limit) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $category = $mysqli->real_escape_string(CATEGORIES[$i % 10]);
        $rows     = $mysqli->query("SELECT * FROM zenbench_news WHERE category = '$category'
            ORDER BY created_at DESC LIMIT $limit")->fetch_all(MYSQLI_ASSOC);
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};
$preparedListRaw = static function (int $iters, int $limit) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $category = CATEGORIES[$i % 10];
        $stmt     = $mysqli->prepare("SELECT * FROM zenbench_news WHERE category = ? ORDER BY created_at DESC LIMIT $limit");
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};
$zenListRaw = static function (int $iters, int $limit): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = DB::query("SELECT * FROM ::news WHERE category = ? ORDER BY created_at DESC LIMIT $limit", CATEGORIES[$i % 10])->toArray();
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};

//endregion
//region Correctness gate - ZenDB HTML output must match full-flag htmlspecialchars() byte for byte
// SmartString encodes with ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5; the timed
// baseline's e() helper uses the same flags, so the outputs must match byte for byte.

$gateRaw = '';
$rows    = $mysqli->query("SELECT * FROM zenbench_news WHERE category = 'news' ORDER BY created_at DESC LIMIT 25")->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $row) {
    $gateRaw .= htmlspecialchars($row['title'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8')
              . htmlspecialchars($row['summary'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8');
}
$gateZen = '';
foreach (DB::select('news', "category = ? ORDER BY created_at DESC LIMIT 25", 'news') as $row) {
    $gateZen .= $row->title . $row->summary;
}
if ($gateRaw !== $gateZen) {
    fwrite(STDERR, "CORPUS_FAIL: ZenDB HTML output differs from baseline htmlspecialchars()\n");
    exit(1);
}
$out['corpus'] = 'PASS';

//endregion
//region Run cells

// [id, base iters, A label, B label, A fn, B fn]
$e = 'htmlspecialchars';   // keep labels short
$tests = [
    ['zvr-selftie',            300, 'raw list25 + ' . $e,        'raw list25 + ' . $e . ' (same)', fn($n) => $rawList($n, 25, true),      fn($n) => $rawList($n, 25, true)],
    ['zvr-detail-html-zendb',  800, 'raw mysqli + ' . $e,        'DB::selectOne + SmartString',    $rawDetailHtml,                        $zenDetailHtml],
    ['zvr-detail-html-prepared', 800, 'raw mysqli + ' . $e,      'fresh prepared + ' . $e,         $rawDetailHtml,                        $preparedDetailHtml],
    ['zvr-detail-raw-zendb',   800, 'raw mysqli fetch_assoc',    'DB::selectOne + toArray()',      $rawDetailRaw,                         $zenDetailRaw],
    ['zvr-detail-raw-prepared', 800, 'raw mysqli fetch_assoc',   'fresh prepared fetch_assoc',     $rawDetailRaw,                         $preparedDetailRaw],
    ['zvr-widget5-html-zendb', 500, 'raw mysqli + ' . $e,        'DB::query + SmartString',       fn($n) => $rawList($n, 5, false),      fn($n) => $zenList($n, 5, false)],
    ['zvr-widget5-html-prepared', 500, 'raw mysqli + ' . $e,     'fresh prepared + ' . $e,         fn($n) => $rawList($n, 5, false),      fn($n) => $preparedList($n, 5, false)],
    ['zvr-list25-html-zendb',  300, 'raw mysqli + ' . $e,        'DB::query + SmartString',       fn($n) => $rawList($n, 25, true),      fn($n) => $zenList($n, 25, true)],
    ['zvr-list25-html-prepared', 300, 'raw mysqli + ' . $e,      'fresh prepared + ' . $e,         fn($n) => $rawList($n, 25, true),      fn($n) => $preparedList($n, 25, true)],
    ['zvr-list25-raw-zendb',   300, 'raw mysqli fetch_all',      'DB::query + toArray()',         fn($n) => $rawListRaw($n, 25),         fn($n) => $zenListRaw($n, 25)],
    ['zvr-list25-raw-prepared', 300, 'raw mysqli fetch_all',     'fresh prepared fetch_all',       fn($n) => $rawListRaw($n, 25),         fn($n) => $preparedListRaw($n, 25)],
    ['zvr-list100-html-zendb', 150, 'raw mysqli + ' . $e,        'DB::query + SmartString',       fn($n) => $rawList($n, 100, true),     fn($n) => $zenList($n, 100, true)],
    ['zvr-list100-html-prepared', 150, 'raw mysqli + ' . $e,     'fresh prepared + ' . $e,         fn($n) => $rawList($n, 100, true),     fn($n) => $preparedList($n, 100, true)],
    ['zvr-list100-raw-zendb',  150, 'raw mysqli fetch_all',      'DB::query + toArray()',         fn($n) => $rawListRaw($n, 100),        fn($n) => $zenListRaw($n, 100)],
    ['zvr-list100-raw-prepared', 150, 'raw mysqli fetch_all',    'fresh prepared fetch_all',       fn($n) => $rawListRaw($n, 100),        fn($n) => $preparedListRaw($n, 100)],
];

foreach ($tests as [$id, $baseIters, $aLabel, $bLabel, $aFn, $bFn]) {
    if ($filter !== null && !isset($filter[$id])) {
        continue;
    }
    fwrite(STDERR, "benchmarking $id ...\n");
    $iters = max(20, (int)($baseIters * $scale));
    [$aNs, $bNs] = ab_bench($aFn, $bFn, $iters);
    $ratio = $aNs / $bNs; // > 1: B (the candidate) faster
    $out['tests'][$id] = [
        'a_label' => $aLabel, 'b_label' => $bLabel, 'sink' => 'wall',
        'a_ns'    => round($aNs, 0), 'b_ns' => round($bNs, 0),
        'a_us'    => round($aNs / 1000, 1), 'b_us' => round($bNs / 1000, 1),
        'ratio'   => round($ratio, 3),
        'verdict' => $ratio >= 1.05 ? 'B_FASTER' : ($ratio <= 0.952 ? 'A_FASTER' : 'TIE'),
    ];
}

$mysqli->query('DROP TABLE IF EXISTS zenbench_news');

//endregion
//region Report

printf("### %s | PHP %s%s | %s | ping %sus%s\n\n",
    $out['server_label'], $out['php'], $out['zts'] ? ' ZTS' : '', $out['server'], $out['ping_us'],
    $out['xdebug'] ? ' **XDEBUG LOADED - RESULTS INVALID**' : '');
echo "Ratios read as B vs A: >1.00 means the candidate beats raw mysqli; <1.00 measures what the candidate's extra work currently costs.\n\n";
echo "| test                       | A                              | B                                    |     A us |     B us | B vs A | verdict  |\n";
echo "|:---------------------------|:-------------------------------|:-------------------------------------|---------:|---------:|-------:|:---------|\n";
foreach ($out['tests'] as $id => $t) {
    printf("| %-26s | %-30s | %-36s | %8.1f | %8.1f | %5.2fx | %-8s |\n",
        $id, $t['a_label'], $t['b_label'], $t['a_us'], $t['b_us'], $t['ratio'], $t['verdict']);
}

$out['runtime_seconds'] = (int)round((hrtime(true) - $probeStart) / 1e9);
printf("\nTotal runtime: %ds\n", $out['runtime_seconds']);

if (isset($opts['json'])) {
    file_put_contents((string)$opts['json'], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
exit(0);

//endregion
