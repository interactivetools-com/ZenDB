<?php
declare(strict_types=1);

/**
 * The previous release versus the working copy, on the pages from
 * docs/performance.md, timed in one process.
 *
 *     php .github/scripts/version-probe.php --baseline=/tmp/baseline [--json=out.json] [--filter=id1,id2] [--scale=1.0]
 *
 * Both versions are loaded at once and their pages run interleaved, one after
 * the other in every round. Separate processes drift by up to 20% between runs
 * on the same machine, which is larger than the difference being measured.
 *
 * --baseline points at a directory holding the old release with its namespaces
 * suffixed "Old", which is what lets both versions coexist:
 *
 *     mkdir -p /tmp/baseline && cd /tmp/baseline
 *     composer require itools/zendb:0.9.1 --no-interaction --prefer-dist
 *     find vendor/itools -name '*.php' -print0 \
 *       | xargs -0 perl -pi -e 's/Itools(\\+)(ZenDB|SmartArray|SmartString)\b/Itools${1}${2}Old/g'
 *
 * That pulls the SmartArray and SmartString the old release resolves to, so
 * each side is the whole stack as it shipped, which is what an install gets.
 *
 * Scenarios and corpus match escape-zendb-probe.php: a news table of 1,000 rows
 * with a 60-byte title, a 300-byte summary, and a 5 KB body, read as a detail
 * page (1 row), a widget (5), and list pages (25 and 100), each consumed as
 * HTML output or as raw arrays. Two extra cells read the listing pages the way
 * listings usually query, selecting the columns they print instead of the body.
 *
 * Each page also runs in hand-written mysqli, which is not a candidate but a
 * reference: it shows what the query costs on the machine of the day, so the
 * library's own share of a page can be read off the table.
 *
 * Two gates run before any timing: both versions must produce byte-identical
 * page output, and both must send the same number of statements per call, so a
 * difference in the table is PHP-side work and never a round trip.
 *
 * Same DB_* env vars as the other probes.
 */

$opts     = getopt('', ['baseline:', 'json::', 'filter::', 'scale::']);
$baseline = rtrim((string)($opts['baseline'] ?? ''), '/');
$filter   = isset($opts['filter']) ? array_flip(array_map('trim', explode(',', (string)$opts['filter']))) : null;
$scale    = isset($opts['scale']) ? max(0.01, (float)$opts['scale']) : 1.0;

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found - run: composer install\n");
    exit(1);
}
require $autoload;

if ($baseline === '' || !is_dir("$baseline/vendor/itools/zendb/src")) {
    fwrite(STDERR, "--baseline must name a directory holding the old release; see this file's header\n");
    exit(1);
}

// The old release, namespaces already suffixed "Old" so both versions coexist
spl_autoload_register(static function (string $class) use ($baseline): void {
    $dirs = [
        'Itools\\ZenDBOld\\'       => "$baseline/vendor/itools/zendb/src/",
        'Itools\\SmartArrayOld\\'  => "$baseline/vendor/itools/smartarray/src/",
        'Itools\\SmartStringOld\\' => "$baseline/vendor/itools/smartstring/src/",
    ];
    foreach ($dirs as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Itools\ZenDB\DB;
use Itools\ZenDBOld\DB as OldDB;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$probeStart = hrtime(true);

/** Version strings for the report, read from what composer actually installed */
function installed_versions(string $baseline): array
{
    $file = "$baseline/vendor/composer/installed.json";
    $json = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    $out  = [];
    foreach ($json['packages'] ?? [] as $package) {
        if (str_starts_with($package['name'] ?? '', 'itools/')) {
            $out[$package['name']] = $package['version'];
        }
    }
    return $out;
}

$config = [
    'hostname'           => (getenv('DB_HOSTNAME') ?: '127.0.0.1') . (getenv('DB_PORT') ? ':' . getenv('DB_PORT') : ''),
    'username'           => getenv('DB_USERNAME') ?: 'root',
    'password'           => getenv('DB_PASSWORD') ?: '',
    'database'           => getenv('DB_DATABASE') ?: 'phpunit_test_db',
    'tablePrefix'        => 'zenbench_',
    'databaseAutoCreate' => true,
    'connectTimeout'     => 5,
];

DB::connect($config);
OldDB::connect($config);
$mysqli = DB::$mysqli;   // the working copy's connection doubles as the raw-side handle

//region Environment facts

$pings = [];
for ($i = 0; $i < 200; $i++) {
    $t = hrtime(true);
    $mysqli->query('SELECT 1')->fetch_row();
    $pings[] = hrtime(true) - $t;
}
sort($pings);

$baselineVersions = installed_versions($baseline);

$out = [
    'baseline'     => $baselineVersions['itools/zendb'] ?? 'unknown',
    'baseline_all' => $baselineVersions,
    'php'          => PHP_VERSION,
    'os'           => PHP_OS_FAMILY,
    'arch'         => php_uname('m'),
    'zts'          => ZEND_THREAD_SAFE,
    'opcache'      => (bool)ini_get('opcache.enable_cli'),
    'jit'          => (int)ini_get('opcache.jit_buffer_size') > 0 ? (string)ini_get('opcache.jit') : 'off',
    'xdebug'       => extension_loaded('xdebug'),
    'server'       => $mysqli->server_info,
    'ping_us'      => round($pings[100] / 1000, 1),
    'gates'        => [],
    'tests'        => [],
];

//endregion
//region Test data - news corpus (same as escape-zendb-probe.php)

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

/** The reference output call: the standard safe helper wrapped once per project */
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
//region Gates - identical output, identical round trips

$pageBytes = static function (string $db): string {
    $html = '';
    foreach ($db::select('news', "category = ? ORDER BY created_at DESC LIMIT 25", 'news') as $row) {
        $html .= $row->title . $row->summary;
    }
    return $html;
};
if ($pageBytes(OldDB::class) !== $pageBytes(DB::class)) {
    fwrite(STDERR, "GATE_FAIL: the two versions print different page bytes\n");
    exit(1);
}

// The reference htmlspecialchars() output, so a page that changed both sides equally still fails
$referenceHtml = '';
foreach ($mysqli->query("SELECT * FROM zenbench_news WHERE category = 'news' ORDER BY created_at DESC LIMIT 25")->fetch_all(MYSQLI_ASSOC) as $row) {
    $referenceHtml .= e($row['title']) . e($row['summary']);
}
if ($pageBytes(DB::class) !== $referenceHtml) {
    fwrite(STDERR, "GATE_FAIL: page output differs from htmlspecialchars() with the same flags\n");
    exit(1);
}
$out['gates']['output'] = 'PASS';

/** Statements this connection has sent, from its own session counter */
$questions = static fn(mysqli $c): int => (int)$c->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];

$roundTrips = [];
foreach (['selectOne' => static fn(string $db) => $db::selectOne('news', ['id' => 3]),
          'select'    => static fn(string $db) => $db::select('news', "category = ? ORDER BY id LIMIT 25", 'news'),
          'query'     => static fn(string $db) => $db::query("SELECT * FROM ::news WHERE category = ? ORDER BY id LIMIT 25", 'news'),
         ] as $call => $fn) {
    foreach (['old' => OldDB::class, 'new' => DB::class] as $side => $db) {
        $fn($db);                                                    // warm anything cached on first use
        $before = $questions($db::$mysqli);
        $fn($db);
        $roundTrips[$call][$side] = $questions($db::$mysqli) - $before - 1;   // less the SHOW STATUS call itself
    }
    if ($roundTrips[$call]['old'] !== $roundTrips[$call]['new']) {
        fwrite(STDERR, "GATE_FAIL: $call sends {$roundTrips[$call]['old']} statements on the baseline and {$roundTrips[$call]['new']} on the working copy\n");
        exit(1);
    }
}
$out['gates']['round_trips'] = $roundTrips;

//endregion
//region Page scenarios

/**
 * Interleaved n-way benchmark: every version of a page runs once per round, so
 * a slow patch of machine reaches all of them. Returns best-of-reps per
 * version, in microseconds per page.
 */
function bench(array $versions, int $iters, int $reps = 9): array
{
    $best   = array_fill_keys(array_keys($versions), INF);
    $warmup = max(3, intdiv($iters, 20));
    foreach ($versions as $fn) {
        $fn($warmup);
    }
    for ($r = 0; $r < $reps; $r++) {
        foreach ($versions as $name => $fn) {
            $t = hrtime(true);
            $fn($iters);
            $best[$name] = min($best[$name], (hrtime(true) - $t) / 1000 / $iters);
        }
    }
    return $best;
}

$GLOBALS['sink'] = 0;

// --- Detail page: one record by id ---
$detailHtml = static fn(string $db) => static function (int $iters) use ($db): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row  = $db::selectOne('news', ['id' => $i % 1000 + 1]);
        $acc += strlen((string)$row->title) + strlen((string)$row->content);
    }
    $GLOBALS['sink'] += $acc;
};
$detailArray = static fn(string $db) => static function (int $iters) use ($db): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row  = $db::selectOne('news', ['id' => $i % 1000 + 1])->toArray();
        $acc += count($row);
    }
    $GLOBALS['sink'] += $acc;
};
$rawDetailHtml = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row  = $mysqli->query('SELECT * FROM zenbench_news WHERE id = ' . ($i % 1000 + 1))->fetch_assoc();
        $acc += strlen(e($row['title'])) + strlen(e($row['content']));
    }
    $GLOBALS['sink'] += $acc;
};
$rawDetailArray = static function (int $iters) use ($mysqli): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $row  = $mysqli->query('SELECT * FROM zenbench_news WHERE id = ' . ($i % 1000 + 1))->fetch_assoc();
        $acc += count($row);
    }
    $GLOBALS['sink'] += $acc;
};

// --- List pages: N newest in a category ---
$listHtml = static fn(string $db, int $limit, bool $withSummary) => static function (int $iters) use ($db, $limit, $withSummary): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = $db::query("SELECT * FROM ::news WHERE category = ? ORDER BY created_at DESC LIMIT $limit", CATEGORIES[$i % 10]);
        foreach ($rows as $row) {
            $acc += strlen((string)$row->title) + ($withSummary ? strlen((string)$row->summary) : 0);
        }
    }
    $GLOBALS['sink'] += $acc;
};
$listArray = static fn(string $db, int $limit) => static function (int $iters) use ($db, $limit): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = $db::query("SELECT * FROM ::news WHERE category = ? ORDER BY created_at DESC LIMIT $limit", CATEGORIES[$i % 10])->toArray();
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};
$rawListHtml = static fn(int $limit, bool $withSummary) => static function (int $iters) use ($mysqli, $limit, $withSummary): void {
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
$rawListArray = static fn(int $limit) => static function (int $iters) use ($mysqli, $limit): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $category = $mysqli->real_escape_string(CATEGORIES[$i % 10]);
        $rows     = $mysqli->query("SELECT * FROM zenbench_news WHERE category = '$category'
            ORDER BY created_at DESC LIMIT $limit")->fetch_all(MYSQLI_ASSOC);
        $acc += count($rows);
    }
    $GLOBALS['sink'] += $acc;
};

// --- Listing pages that select the columns they print, not the 5 KB body ---
$leanHtml = static fn(string $db, int $limit) => static function (int $iters) use ($db, $limit): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $rows = $db::query("SELECT id, title, summary, created_at FROM ::news WHERE category = ? ORDER BY created_at DESC LIMIT $limit", CATEGORIES[$i % 10]);
        foreach ($rows as $row) {
            $acc += strlen((string)$row->title) + strlen((string)$row->summary);
        }
    }
    $GLOBALS['sink'] += $acc;
};
$rawLeanHtml = static fn(int $limit) => static function (int $iters) use ($mysqli, $limit): void {
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $category = $mysqli->real_escape_string(CATEGORIES[$i % 10]);
        $rows     = $mysqli->query("SELECT id, title, summary, created_at FROM zenbench_news WHERE category = '$category'
            ORDER BY created_at DESC LIMIT $limit")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $acc += strlen(e($row['title'])) + strlen(e($row['summary']));
        }
    }
    $GLOBALS['sink'] += $acc;
};

//endregion
//region Run cells

$OLD = OldDB::class;
$NEW = DB::class;

// [id, base iters, page label, raw mysqli fn, baseline fn, working-copy fn]
$tests = [
    ['selftie',       300, 'Calibration, working copy twice', $listHtml($NEW, 25, true), $listHtml($NEW, 25, true),  $listHtml($NEW, 25, true)],
    ['detail-html',   800, 'Detail, 1 article',               $rawDetailHtml,            $detailHtml($OLD),          $detailHtml($NEW)],
    ['widget5-html',  500, 'Widget, 5 rows',                  $rawListHtml(5, false),    $listHtml($OLD, 5, false),  $listHtml($NEW, 5, false)],
    ['list25-html',   300, 'List, 25 rows',                   $rawListHtml(25, true),    $listHtml($OLD, 25, true),  $listHtml($NEW, 25, true)],
    ['list100-html',  150, 'List, 100 rows',                  $rawListHtml(100, true),   $listHtml($OLD, 100, true), $listHtml($NEW, 100, true)],
    ['detail-array',  800, 'Detail, 1 article, toArray()',    $rawDetailArray,           $detailArray($OLD),         $detailArray($NEW)],
    ['list25-array',  300, 'List, 25 rows, toArray()',        $rawListArray(25),         $listArray($OLD, 25),       $listArray($NEW, 25)],
    ['list100-array', 150, 'List, 100 rows, toArray()',       $rawListArray(100),        $listArray($OLD, 100),      $listArray($NEW, 100)],
    ['lean25-html',   400, 'List, 25 rows, no body column',   $rawLeanHtml(25),          $leanHtml($OLD, 25),        $leanHtml($NEW, 25)],
    ['lean100-html',  200, 'List, 100 rows, no body column',  $rawLeanHtml(100),         $leanHtml($OLD, 100),       $leanHtml($NEW, 100)],
];

foreach ($tests as [$id, $baseIters, $label, $rawFn, $oldFn, $newFn]) {
    if ($filter !== null && !isset($filter[$id])) {
        continue;
    }
    fwrite(STDERR, "benchmarking $id ...\n");
    $iters = max(20, (int)($baseIters * $scale));
    $times = bench(['raw' => $rawFn, 'old' => $oldFn, 'new' => $newFn], $iters);
    $out['tests'][$id] = [
        'label'   => $label,
        'raw_us'  => round($times['raw'], 1),
        'old_us'  => round($times['old'], 1),
        'new_us'  => round($times['new'], 1),
        'ratio'   => round($times['old'] / $times['new'], 3),
        // The library's own share of the page: page time less the same page in raw mysqli
        'lib_old' => round($times['old'] - $times['raw'], 1),
        'lib_new' => round($times['new'] - $times['raw'], 1),
    ];
}

$mysqli->query('DROP TABLE IF EXISTS zenbench_news');

//endregion
//region Report

printf("### ZenDB %s vs working copy | PHP %s%s | %s | JIT %s | ping %sus%s\n\n",
    $out['baseline'], $out['php'], $out['zts'] ? ' ZTS' : '', $out['server'], $out['jit'], $out['ping_us'],
    $out['xdebug'] ? ' **XDEBUG LOADED - RESULTS INVALID**' : '');

$baselineList = [];
foreach ($out['baseline_all'] as $name => $version) {
    $baselineList[] = "$name $version";
}
printf("Baseline: %s. Both versions print identical page bytes and send the same statements per call.\n\n",
    implode(', ', $baselineList) ?: 'unknown');

$printTable = static function (array $rows) use ($out): void {
    printf("| %-32s | %10s | %10s | %10s | %9s |\n", 'Page', 'raw mysqli', $out['baseline'], 'working', 'faster by');
    echo "|:---------------------------------|-----------:|-----------:|-----------:|----------:|\n";
    foreach ($rows as $id) {
        if (!isset($out['tests'][$id])) {
            continue;
        }
        $t = $out['tests'][$id];
        printf("| %-32s | %7.0f us | %7.0f us | %7.0f us | %8.2fx |\n",
            $t['label'], $t['raw_us'], $t['old_us'], $t['new_us'], $t['ratio']);
    }
};

echo "Pages that HTML-encode their output, the common case:\n\n";
$printTable(['detail-html', 'widget5-html', 'list25-html', 'list100-html']);

echo "\nListing pages that select the columns they print, leaving the 5 KB body behind:\n\n";
$printTable(['lean25-html', 'lean100-html']);

echo "\nData processing with no HTML output, via `->toArray()`:\n\n";
$printTable(['detail-array', 'list25-array', 'list100-array']);

echo "\nThe library's own share of each page: page time less the same page in raw mysqli.\n\n";
printf("| %-32s | %10s | %10s |\n", 'Page', $out['baseline'], 'working');
echo "|:---------------------------------|-----------:|-----------:|\n";
foreach ($out['tests'] as $id => $t) {
    if ($id !== 'selftie') {
        printf("| %-32s | %7.0f us | %7.0f us |\n", $t['label'], $t['lib_old'], $t['lib_new']);
    }
}

if (isset($out['tests']['selftie'])) {
    printf("\nCalibration cell (the working copy against itself) read %.2fx; readings within a few\n"
         . "percent of 1.00x are noise on this machine.\n", $out['tests']['selftie']['ratio']);
}

$out['runtime_seconds'] = (int)round((hrtime(true) - $probeStart) / 1e9);
printf("\nTotal runtime: %ds\n", $out['runtime_seconds']);

if (isset($opts['json'])) {
    file_put_contents((string)$opts['json'], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
exit(0);

//endregion
