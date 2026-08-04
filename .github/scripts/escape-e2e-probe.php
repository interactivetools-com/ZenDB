<?php
declare(strict_types=1);

/**
 * End-to-end escape benchmark: every way values get into queries, timed as whole
 * round trips against a live server, one run per DB image in the matrix.
 *
 *     php .github/scripts/escape-e2e-probe.php [--json=out.json] [--filter=id1,id2] [--scale=1.0]
 *
 * Companion to escape-probe.php (which measures pure CPU): this family measures
 * what round trips, server parse, and protocol shape cost. Sections:
 *
 * 1. Restore-and-compare gate: a corpus sample INSERTed through every channel
 *    into MEDIUMBLOB, SELECTed back, byte-compared to the original. Channels
 *    legitimately produce different SQL bytes (hex, prepared), so stored-value
 *    identity is the only valid cross-channel equivalence. Timings are withheld
 *    for any channel that fails.
 * 2. Round-trip census: SHOW SESSION STATUS deltas per channel per 100 ops
 *    (Questions, Com_stmt_prepare/execute/close) - the measured round-trip
 *    model every wall-time number is interpreted against.
 * 3. One-shot OLTP shootout: paired A/B wall time per query, fresh statement
 *    each time, distinct literals per iteration (defeats any query cache).
 * 4. Statement-reuse crossover: prepare-once-execute-N vs N interpolated
 *    queries, per-query microseconds by N.
 * 5. Bulk grid: 20k-row load per channel inside one transaction (commit
 *    boundaries held constant), CHECKSUM TABLE as the gate.
 *
 * Env facts recorded because they move these numbers: transport (TCP here),
 * durability settings, query cache (MariaDB; asserted off), performance_schema.
 * Uses DB_* env vars like the test suite; DB_LABEL names the cell in the merge.
 *
 * The LOAD DATA channel needs mysqli.allow_local_infile=1 (PHP_INI_SYSTEM, so it
 * must come from the command line or php.ini) plus server local_infile=1, which
 * the probe sets itself. Local run:
 *
 *     php -d mysqli.allow_local_infile=1 .github/scripts/escape-e2e-probe.php
 */

require __DIR__ . '/escape-corpus.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$opts    = getopt('', ['json::', 'filter::', 'scale::']);
$filter  = isset($opts['filter']) ? array_flip(array_map('trim', explode(',', (string)$opts['filter']))) : null;
$scale   = isset($opts['scale']) ? max(0.01, (float)$opts['scale']) : 1.0;
$rtIters = max(50, (int)(1000 * $scale));   // per-side iterations for round-trip cells
$bulkRows = max(1000, (int)(20000 * $scale));

$hostname = getenv('DB_HOSTNAME') ?: '127.0.0.1';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_DATABASE') ?: 'phpunit_test_db';
$port     = (int)(getenv('DB_PORT') ?: 3306);
$label    = getenv('DB_LABEL') ?: 'unlabeled';

$mysqli = escape_corpus_connect();

$pdoDsn      = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $hostname, $port, $database);
$pdoNative   = new PDO($pdoDsn, $username, $password, [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoEmulated = new PDO($pdoDsn, $username, $password, [PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

//region Environment facts

/** Fetch one server variable, null when the server doesn't have it. */
function server_var(mysqli $m, string $name): ?string
{
    try {
        $row = $m->query("SELECT @@$name")->fetch_row();
        return $row[0] === null ? null : (string)$row[0];
    } catch (mysqli_sql_exception) {
        return null;
    }
}

// Round-trip floor: median SELECT 1 wall time (the unit every "extra round trip" costs)
$pings = [];
for ($i = 0; $i < 200; $i++) {
    $t = hrtime(true);
    $mysqli->query('SELECT 1')->fetch_row();
    $pings[] = hrtime(true) - $t;
}
sort($pings);

$queryCache = server_var($mysqli, 'query_cache_type');   // MariaDB only; MySQL 8 removed it
$out = [
    'server_label'   => $label,
    'php'            => PHP_VERSION,
    'os'             => PHP_OS_FAMILY,
    'arch'           => php_uname('m'),
    'zts'            => ZEND_THREAD_SAFE,
    'opcache'        => (bool)ini_get('opcache.enable_cli'),
    // jit_buffer_size=0 means JIT is off no matter what opcache.jit says
    'jit'            => (int)ini_get('opcache.jit_buffer_size') > 0 ? (string)ini_get('opcache.jit') : 'off',
    'xdebug'         => extension_loaded('xdebug'),
    'mysqlnd'        => mysqli_get_client_info(),
    'server'         => $mysqli->server_info,
    'charset'        => $mysqli->character_set_name(),
    'sql_mode'       => server_var($mysqli, 'SESSION.sql_mode'),
    'ping_us'        => round($pings[100] / 1000, 1),
    'execute_query'  => method_exists($mysqli, 'execute_query'),
    'durability'     => [
        'innodb_flush_log_at_trx_commit' => server_var($mysqli, 'innodb_flush_log_at_trx_commit'),
        'sync_binlog'                    => server_var($mysqli, 'sync_binlog'),
        'log_bin'                        => server_var($mysqli, 'log_bin'),
    ],
    'query_cache_type'   => $queryCache,
    'performance_schema' => server_var($mysqli, 'performance_schema'),
    'corpus'         => null,
    'census'         => [],
    'tests'          => [],
    'crossover'      => [],
    'bulk'           => [],
];

// A live MariaDB query cache would turn repeated interpolated SELECTs into cache
// hits (prepared statements bypass it), inverting every comparison below
if ($queryCache !== null && strtoupper($queryCache) === 'ON') {
    fwrite(STDERR, "FATAL: query_cache_type=ON would poison interpolated-vs-prepared comparisons.\n");
    exit(1);
}

//endregion
//region Scratch tables and channels

$mysqli->query('DROP TABLE IF EXISTS escape_e2e_kv, escape_e2e_bin, escape_e2e_bulk');
$mysqli->query('CREATE TABLE escape_e2e_kv (id INT PRIMARY KEY AUTO_INCREMENT,
    a VARCHAR(255) NOT NULL, b VARCHAR(255) NOT NULL, c VARCHAR(255) NOT NULL,
    KEY idx_a (a)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$mysqli->query('CREATE TABLE escape_e2e_bin (id INT PRIMARY KEY AUTO_INCREMENT,
    data MEDIUMBLOB NOT NULL) ENGINE=InnoDB');
$mysqli->query('CREATE TABLE escape_e2e_bulk (id INT PRIMARY KEY,
    title VARCHAR(255) NOT NULL, content TEXT NOT NULL, created DATETIME NOT NULL,
    flag TINYINT NOT NULL, category VARCHAR(50) NULL, price DECIMAL(10,2) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

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

// Value pool for OLTP cells: 64 distinct short values, some with escapables,
// cycled so no two consecutive statements are byte-identical
$pool = [];
for ($i = 0; $i < 64; $i++) {
    $v = build_clean(24, 100 + $i);
    $pool[] = $i % 3 === 0 ? substr($v, 0, 8) . "'" . substr($v, 9) : $v;
}
$poolN = count($pool);

// Seed the kv table with 100 rows so point SELECTs have something to miss/hit
for ($i = 0; $i < 100; $i++) {
    $stmt = $mysqli->prepare('INSERT INTO escape_e2e_kv (a, b, c) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $pool[$i % $poolN], $pool[($i + 1) % $poolN], $pool[($i + 2) % $poolN]);
    $stmt->execute();
    $stmt->close();
}

$fastEscape = static fn(string $s): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);

//endregion
//region 1. Restore-and-compare gate

/**
 * INSERT every sample through the channel into MEDIUMBLOB, SELECT back in id
 * order, byte-compare. Returns [ok(bool), detail(string)].
 */
function restore_gate(mysqli $mysqli, string $channel, callable $insertAll, array $samples): array
{
    $mysqli->query('TRUNCATE escape_e2e_bin');
    try {
        $insertAll($samples);
    } catch (Throwable $e) {
        return [false, "$channel: insert failed: " . $e->getMessage()];
    }
    $back = [];
    $res  = $mysqli->query('SELECT data FROM escape_e2e_bin ORDER BY id');
    while ($row = $res->fetch_row()) {
        $back[] = $row[0];
    }
    if (count($back) !== count($samples)) {
        return [false, "$channel: row count " . count($back) . ' != ' . count($samples)];
    }
    foreach ($samples as $i => $s) {
        if ($back[$i] !== $s) {
            return [false, sprintf('%s: byte mismatch at %d: sent=%s got=%s',
                $channel, $i, bin2hex($s), bin2hex((string)$back[$i]))];
        }
    }
    return [true, ''];
}

// Corpus sample: all 256 single bytes, the escapable pair grid, invalid UTF-8,
// GBK shapes, embedded NUL, plus seeded fuzz - small enough to run per channel
$samples = [''];
for ($b = 0; $b <= 0xFF; $b++) {
    $samples[] = chr($b);
}
$esc = ["\\", "'", "\"", "\n", "\r", "\0", "\x1a"];
foreach ($esc as $x) {
    foreach ($esc as $y) {
        $samples[] = "a{$x}{$y}b";
    }
}
foreach (["\xBF\x27", "\xBF\x5C", "\xC0\xAF", "\xED\xA0\x80", "\xF0\x9F\x98", "\xFF\xFE\xFD"] as $s) {
    $samples[] = $s;
    $samples[] = "text $s text";
}
$samples[] = str_repeat('x', 500) . "\0" . str_repeat('y', 500);
mt_srand(20260802);
for ($i = 0; $i < 500; $i++) {
    $len = mt_rand(0, 64);
    $s   = '';
    for ($j = 0; $j < $len; $j++) {
        $s .= chr(mt_rand(0, 255));
    }
    $samples[] = $s;
}

$channels = [
    'interp-fast' => static function (array $samples) use ($mysqli, $fastEscape): void {
        foreach (array_chunk($samples, 200) as $chunk) {
            $sql = 'INSERT INTO escape_e2e_bin (data) VALUES ';
            foreach ($chunk as $s) {
                $sql .= "('" . $fastEscape($s) . "'),";
            }
            $mysqli->query(substr($sql, 0, -1));
        }
    },
    'interp-real' => static function (array $samples) use ($mysqli): void {
        foreach (array_chunk($samples, 200) as $chunk) {
            $sql = 'INSERT INTO escape_e2e_bin (data) VALUES ';
            foreach ($chunk as $s) {
                $sql .= "('" . $mysqli->real_escape_string($s) . "'),";
            }
            $mysqli->query(substr($sql, 0, -1));
        }
    },
    'mysqli-prepared' => static function (array $samples) use ($mysqli): void {
        $stmt = $mysqli->prepare('INSERT INTO escape_e2e_bin (data) VALUES (?)');
        foreach ($samples as $s) {
            $stmt->bind_param('s', $s);
            $stmt->execute();
        }
        $stmt->close();
    },
    'pdo-native' => static function (array $samples) use ($pdoNative): void {
        $stmt = $pdoNative->prepare('INSERT INTO escape_e2e_bin (data) VALUES (?)');
        foreach ($samples as $s) {
            $stmt->execute([$s]);
        }
    },
    'pdo-emulated' => static function (array $samples) use ($pdoEmulated): void {
        $stmt = $pdoEmulated->prepare('INSERT INTO escape_e2e_bin (data) VALUES (?)');
        foreach ($samples as $s) {
            $stmt->execute([$s]);
        }
    },
    // X'...' form: has a valid empty literal, unlike bare 0x which lexes as an
    // identifier when the value is empty
    'hex-literal' => static function (array $samples) use ($mysqli): void {
        foreach (array_chunk($samples, 200) as $chunk) {
            $sql = 'INSERT INTO escape_e2e_bin (data) VALUES ';
            foreach ($chunk as $s) {
                $sql .= "(X'" . bin2hex($s) . "'),";
            }
            $mysqli->query(substr($sql, 0, -1));
        }
    },
];

$gateStatus = [];
$gateDetail = [];
foreach ($channels as $name => $insertAll) {
    [$ok, $detail] = restore_gate($mysqli, $name, $insertAll, $samples);
    $gateStatus[$name] = $ok;
    if (!$ok) {
        $gateDetail[] = $detail;
        fwrite(STDERR, "RESTORE GATE FAIL: $detail\n");
    }
}
$out['corpus'] = ['entries' => count($samples), 'escapers' => $gateStatus, 'detail' => $gateDetail];

//endregion
//region 2. Round-trip census

/** @return array<string, int> counter => value */
function session_counters(mysqli $m): array
{
    $counters = [];
    $res = $m->query("SHOW SESSION STATUS WHERE Variable_name IN
        ('Questions', 'Com_select', 'Com_insert', 'Com_stmt_prepare', 'Com_stmt_execute', 'Com_stmt_close')");
    while ($row = $res->fetch_row()) {
        $counters[$row[0]] = (int)$row[1];
    }
    return $counters;
}

$censusOps = [
    'interp-fast' => static function () use ($mysqli, $fastEscape, $pool, $poolN): void {
        static $i = 0;
        $i++;
        $mysqli->query("SELECT id FROM escape_e2e_kv WHERE a = '" . $fastEscape($pool[$i % $poolN]) . "' LIMIT 1")->fetch_all();
    },
    'mysqli-prepared-fresh' => static function () use ($mysqli, $pool, $poolN): void {
        static $i = 0;
        $i++;
        $stmt = $mysqli->prepare('SELECT id FROM escape_e2e_kv WHERE a = ? LIMIT 1');
        $stmt->bind_param('s', $pool[$i % $poolN]);
        $stmt->execute();
        $stmt->get_result()->fetch_all();
        $stmt->close();
    },
    'pdo-native-fresh' => static function () use ($pdoNative, $pool, $poolN): void {
        static $i = 0;
        $i++;
        $stmt = $pdoNative->prepare('SELECT id FROM escape_e2e_kv WHERE a = ? LIMIT 1');
        $stmt->execute([$pool[$i % $poolN]]);
        $stmt->fetchAll();
    },
    'pdo-emulated-fresh' => static function () use ($pdoEmulated, $pool, $poolN): void {
        static $i = 0;
        $i++;
        $stmt = $pdoEmulated->prepare('SELECT id FROM escape_e2e_kv WHERE a = ? LIMIT 1');
        $stmt->execute([$pool[$i % $poolN]]);
        $stmt->fetchAll();
    },
];
// PDO sessions have their own counters; census only makes sense per connection,
// so PDO channels are censused against the mysqli session they don't use and
// would read zero. Census PDO channels on their own connections via a raw query.
foreach ($censusOps as $name => $op) {
    $conn = match (true) {
        str_starts_with($name, 'pdo-native')   => $pdoNative,
        str_starts_with($name, 'pdo-emulated') => $pdoEmulated,
        default                                => $mysqli,
    };
    $snapshot = static function () use ($conn): array {
        if ($conn instanceof mysqli) {
            return session_counters($conn);
        }
        $counters = [];
        foreach ($conn->query("SHOW SESSION STATUS WHERE Variable_name IN
            ('Questions', 'Com_select', 'Com_insert', 'Com_stmt_prepare', 'Com_stmt_execute', 'Com_stmt_close')") as $row) {
            $counters[$row[0]] = (int)$row[1];
        }
        return $counters;
    };
    $before = $snapshot();
    for ($i = 0; $i < 100; $i++) {
        $op();
    }
    $after = $snapshot();
    $delta = [];
    foreach ($after as $k => $v) {
        $d = $v - ($before[$k] ?? 0);
        if ($k === 'Questions') {
            $d -= 1;   // the closing SHOW SESSION STATUS counts itself
        }
        if ($d > 0) {
            $delta[$k] = round($d / 100, 2);
        }
    }
    $out['census'][$name] = $delta;
}

//endregion
//region 3. One-shot OLTP shootout (paired A/B)

/** Interleaved paired benchmark over round-trip closures; returns [a_ns, b_ns]. */
function ab_bench_rt(callable $a, callable $b, int $iters, int $reps = 7): array
{
    $bestA  = INF;
    $bestB  = INF;
    $warmup = max(10, intdiv($iters, 20));
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

/** One-shot SELECT via interpolation with the given escaper. */
function rt_interp(mysqli $mysqli, callable $escape, array $pool): callable
{
    $n = count($pool);
    return static function (int $iters) use ($mysqli, $escape, $pool, $n): void {
        for ($i = 0; $i < $iters; $i++) {
            $mysqli->query("SELECT id, a, b FROM escape_e2e_kv WHERE a = '" . $escape($pool[$i % $n])
                . "' OR b = '" . $escape($pool[($i + 1) % $n]) . "' LIMIT 3")->fetch_all();
        }
    };
}

$rtInterpFast = rt_interp($mysqli, $fastEscape, $pool);
$rtInterpReal = rt_interp($mysqli, static fn(string $s): string => $mysqli->real_escape_string($s), $pool);

$rtPreparedFresh = static function (int $iters) use ($mysqli, $pool, $poolN): void {
    for ($i = 0; $i < $iters; $i++) {
        $stmt = $mysqli->prepare('SELECT id, a, b FROM escape_e2e_kv WHERE a = ? OR b = ? LIMIT 3');
        $stmt->bind_param('ss', $pool[$i % $poolN], $pool[($i + 1) % $poolN]);
        $stmt->execute();
        $stmt->get_result()->fetch_all();
        $stmt->close();
    }
};

$stmtReused = $mysqli->prepare('SELECT id, a, b FROM escape_e2e_kv WHERE a = ? OR b = ? LIMIT 3');
$rtPreparedReused = static function (int $iters) use ($stmtReused, $pool, $poolN): void {
    for ($i = 0; $i < $iters; $i++) {
        $stmtReused->bind_param('ss', $pool[$i % $poolN], $pool[($i + 1) % $poolN]);
        $stmtReused->execute();
        $stmtReused->get_result()->fetch_all();
    }
};

$rtPdoNative = static function (int $iters) use ($pdoNative, $pool, $poolN): void {
    for ($i = 0; $i < $iters; $i++) {
        $stmt = $pdoNative->prepare('SELECT id, a, b FROM escape_e2e_kv WHERE a = ? OR b = ? LIMIT 3');
        $stmt->execute([$pool[$i % $poolN], $pool[($i + 1) % $poolN]]);
        $stmt->fetchAll();
    }
};
$rtPdoEmulated = static function (int $iters) use ($pdoEmulated, $pool, $poolN): void {
    for ($i = 0; $i < $iters; $i++) {
        $stmt = $pdoEmulated->prepare('SELECT id, a, b FROM escape_e2e_kv WHERE a = ? OR b = ? LIMIT 3');
        $stmt->execute([$pool[$i % $poolN], $pool[($i + 1) % $poolN]]);
        $stmt->fetchAll();
    }
};

$rtTests = [
    ['rt-selftie', 'interp-fast', 'interp-fast (same)', $rtInterpFast, $rtInterpFast, []],
    ['rt-fast-vs-real', 'interpolate via real_escape', 'interpolate via str_replace', $rtInterpReal, $rtInterpFast, ['interp-real', 'interp-fast']],
    ['rt-interp-vs-prepared', 'interpolate via str_replace', 'mysqli prepared (fresh per query)', $rtInterpFast, $rtPreparedFresh, ['interp-fast', 'mysqli-prepared']],
    ['rt-prepared-fresh-vs-reused', 'mysqli prepared (fresh)', 'mysqli prepared (reused)', $rtPreparedFresh, $rtPreparedReused, ['mysqli-prepared']],
    ['rt-interp-vs-pdo-emulated', 'mysqli interpolate str_replace', 'PDO emulated prepare (fresh)', $rtInterpFast, $rtPdoEmulated, ['interp-fast', 'pdo-emulated']],
    ['rt-pdo-emulated-vs-native', 'PDO emulated prepare', 'PDO native prepare', $rtPdoEmulated, $rtPdoNative, ['pdo-emulated', 'pdo-native']],
];
if (method_exists($mysqli, 'execute_query')) {
    $rtExecuteQuery = static function (int $iters) use ($mysqli, $pool, $poolN): void {
        for ($i = 0; $i < $iters; $i++) {
            $mysqli->execute_query('SELECT id, a, b FROM escape_e2e_kv WHERE a = ? OR b = ? LIMIT 3',
                [$pool[$i % $poolN], $pool[($i + 1) % $poolN]])->fetch_all();
        }
    };
    $rtTests[] = ['rt-interp-vs-execute-query', 'interpolate via str_replace', 'mysqli::execute_query',
        $rtInterpFast, $rtExecuteQuery, ['interp-fast', 'mysqli-prepared']];
}

foreach ($rtTests as [$id, $aLabel, $bLabel, $aFn, $bFn, $gatedBy]) {
    if ($filter !== null && !isset($filter[$id])) {
        continue;
    }
    $withheld = array_values(array_filter($gatedBy, static fn(string $ch): bool => isset($gateStatus[$ch]) && !$gateStatus[$ch]));
    if ($withheld !== []) {
        $out['tests'][$id] = ['a_label' => $aLabel, 'b_label' => $bLabel, 'sink' => 'wall',
                              'verdict' => 'CORPUS_FAIL', 'failed_escapers' => $withheld];
        continue;
    }
    [$aNs, $bNs] = ab_bench_rt($aFn, $bFn, $rtIters);
    $ratio = $aNs / $bNs;
    $out['tests'][$id] = [
        'a_label' => $aLabel, 'b_label' => $bLabel, 'sink' => 'wall',
        'a_ns'    => round($aNs, 0), 'b_ns' => round($bNs, 0),
        'ratio'   => round($ratio, 3),
        'verdict' => $ratio >= 1.05 ? 'B_FASTER' : ($ratio <= 0.952 ? 'A_FASTER' : 'TIE'),
    ];
}
$stmtReused->close();

//endregion
//region 4. Statement-reuse crossover

if ($filter === null || isset($filter['crossover'])) {
    foreach ([1, 2, 5, 10, 100] as $n) {
        $bestInterp = INF;
        $bestReuse  = INF;
        $rounds     = max(3, (int)(20 * $scale));
        for ($r = 0; $r < $rounds; $r++) {
            $t = hrtime(true);
            for ($i = 0; $i < $n; $i++) {
                $mysqli->query("SELECT id FROM escape_e2e_kv WHERE a = '" . $fastEscape($pool[($r + $i) % $poolN]) . "' LIMIT 1")->fetch_all();
            }
            $bestInterp = min($bestInterp, (hrtime(true) - $t) / $n);

            $t = hrtime(true);
            $stmt = $mysqli->prepare('SELECT id FROM escape_e2e_kv WHERE a = ? LIMIT 1');
            for ($i = 0; $i < $n; $i++) {
                $stmt->bind_param('s', $pool[($r + $i) % $poolN]);
                $stmt->execute();
                $stmt->get_result()->fetch_all();
            }
            $stmt->close();
            $bestReuse = min($bestReuse, (hrtime(true) - $t) / $n);
        }
        $out['crossover'][] = [
            'n'                  => $n,
            'interp_us_per_q'    => round($bestInterp / 1000, 1),
            'prepare_us_per_q'   => round($bestReuse / 1000, 1),
        ];
    }
}

//endregion
//region 5. Bulk grid (commit boundaries held constant: one transaction per load)

/** @return array[] deterministic bench-shaped rows [id, title, content, created, flag, category, price] */
function bulk_rows(int $count): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        mt_srand(500000 + $i);
        $rows[] = [
            $i,
            sprintf('Widget %d "deluxe" O\'Brien edition', $i),
            str_repeat("Lorem ipsum d'olor sit amet consectetur $i adipiscing elit sed do eiusmod tempor. ", 3),
            sprintf('2026-%02d-%02d %02d:%02d:%02d', mt_rand(1, 12), mt_rand(1, 28), mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59)),
            $i % 7,
            $i % 10 === 0 ? null : 'category-' . ($i % 50),
            round($i * 1.37, 2),
        ];
    }
    return $rows;
}

/** Run one bulk channel: truncate, one transaction, load, commit; returns [seconds, checksum]. */
function bulk_run(mysqli $mysqli, callable $load, array $rows): array
{
    $mysqli->query('TRUNCATE escape_e2e_bulk');
    $mysqli->query('SET autocommit = 0');
    $t = hrtime(true);
    $load($rows);
    $mysqli->query('COMMIT');
    $secs = (hrtime(true) - $t) / 1e9;
    $mysqli->query('SET autocommit = 1');
    $checksum = $mysqli->query('CHECKSUM TABLE escape_e2e_bulk')->fetch_row()[1];
    return [$secs, (string)$checksum];
}

if ($filter === null || isset($filter['bulk'])) {
    $rows = bulk_rows($bulkRows);

    $bulkChannels = [
        'multirow-interp-fast' => static function (array $rows) use ($mysqli, $fastEscape): void {
            foreach (array_chunk($rows, 100) as $chunk) {
                $sql = 'INSERT INTO escape_e2e_bulk VALUES ';
                foreach ($chunk as $r) {
                    $sql .= '(' . $r[0] . ",'" . $fastEscape($r[1]) . "','" . $fastEscape($r[2]) . "','" . $r[3] . "',"
                        . $r[4] . ',' . ($r[5] === null ? 'NULL' : "'" . $fastEscape($r[5]) . "'") . ',' . $r[6] . '),';
                }
                $mysqli->query(substr($sql, 0, -1));
            }
        },
        'multirow-interp-real' => static function (array $rows) use ($mysqli): void {
            foreach (array_chunk($rows, 100) as $chunk) {
                $sql = 'INSERT INTO escape_e2e_bulk VALUES ';
                foreach ($chunk as $r) {
                    $sql .= '(' . $r[0] . ",'" . $mysqli->real_escape_string($r[1]) . "','" . $mysqli->real_escape_string($r[2]) . "','" . $r[3] . "',"
                        . $r[4] . ',' . ($r[5] === null ? 'NULL' : "'" . $mysqli->real_escape_string($r[5]) . "'") . ',' . $r[6] . '),';
                }
                $mysqli->query(substr($sql, 0, -1));
            }
        },
        'prepared-row-reuse' => static function (array $rows) use ($mysqli): void {
            $stmt = $mysqli->prepare('INSERT INTO escape_e2e_bulk VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $stmt->bind_param('isssisd', $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6]);
                $stmt->execute();
            }
            $stmt->close();
        },
    ];

    // LOAD DATA LOCAL needs its own connection with the option set, server
    // local_infile=1, and the 4-pair file escaping (\N for NULL); skip with a
    // recorded reason when the server or build refuses
    $loadDataError = null;
    try {
        $mysqli->query('SET GLOBAL local_infile = 1');
        $li = mysqli_init();
        $li->options(MYSQLI_OPT_LOCAL_INFILE, 1);
        $li->real_connect($hostname, $username, $password, $database, $port);
        $li->set_charset('utf8mb4');
        $bulkChannels['load-data-local'] = static function (array $rows) use ($li): void {
            $file = tempnam(sys_get_temp_dir(), 'esc');
            $fh   = fopen($file, 'wb');
            foreach ($rows as $r) {
                $fields = [];
                foreach ($r as $v) {
                    $fields[] = $v === null ? '\\N'
                        : str_replace(["\\", "\t", "\n", "\0"], ["\\\\", "\\\t", "\\\n", "\\0"], (string)$v);
                }
                fwrite($fh, implode("\t", $fields) . "\n");
            }
            fclose($fh);
            $li->query("LOAD DATA LOCAL INFILE '" . str_replace("\\", "/", $file)
                . "' INTO TABLE escape_e2e_bulk CHARACTER SET utf8mb4"
                . " FIELDS TERMINATED BY '\\t' ESCAPED BY '\\\\' LINES TERMINATED BY '\\n'");
            unlink($file);
        };
    } catch (Throwable $e) {
        $loadDataError = $e->getMessage();
    }

    $referenceChecksum = null;
    foreach ($bulkChannels as $name => $load) {
        try {
            [$secs, $checksum] = bulk_run($mysqli, $load, $rows);
        } catch (Throwable $e) {
            $out['bulk'][$name] = ['error' => $e->getMessage()];
            continue;
        }
        $referenceChecksum ??= $checksum;
        $out['bulk'][$name] = [
            'seconds'     => round($secs, 3),
            'rows_per_s'  => (int)round(count($rows) / max($secs, 1e-9)),
            'checksum_ok' => $checksum === $referenceChecksum,
        ];
    }
    if ($loadDataError !== null) {
        $out['bulk']['load-data-local'] = ['error' => "unavailable: $loadDataError"];
    }
    $out['bulk']['_rows'] = count($rows);
}

$mysqli->query('DROP TABLE IF EXISTS escape_e2e_kv, escape_e2e_bin, escape_e2e_bulk');

//endregion
//region Report

printf("### %s | PHP %s%s | %s | ping %sus | flush=%s binlog=%s%s\n\n",
    $label, $out['php'], $out['zts'] ? ' ZTS' : '', $out['server'], $out['ping_us'],
    $out['durability']['innodb_flush_log_at_trx_commit'] ?? '?',
    $out['durability']['log_bin'] ?? '?',
    $out['xdebug'] ? ' **XDEBUG LOADED - RESULTS INVALID**' : '');

$badGates = array_keys(array_filter($gateStatus, static fn(bool $ok): bool => !$ok));
printf("Restore gate: %d samples through %d channels, %s\n\n", count($samples), count($gateStatus),
    $badGates === [] ? 'all channels store byte-identical values' : 'FAILED: ' . implode(', ', $badGates));

echo "Round trips per operation (SHOW SESSION STATUS deltas per 100 ops):\n\n";
echo "| channel | counters |\n|---|---|\n";
foreach ($out['census'] as $name => $delta) {
    $parts = [];
    foreach ($delta as $k => $v) {
        $parts[] = "$k=$v";
    }
    echo "| $name | " . implode(', ', $parts) . " |\n";
}

echo "\n| test | A | B | A ns | B ns | B vs A | verdict |\n|---|---|---|---|---|---|---|\n";
foreach ($out['tests'] as $id => $t) {
    if (($t['verdict'] ?? '') === 'CORPUS_FAIL') {
        printf("| %s | %s | %s | - | - | - | CORPUS_FAIL |\n", $id, $t['a_label'], $t['b_label']);
        continue;
    }
    printf("| %s | %s | %s | %d | %d | %.2fx | %s |\n",
        $id, $t['a_label'], $t['b_label'], $t['a_ns'], $t['b_ns'], $t['ratio'], $t['verdict']);
}

if ($out['crossover'] !== []) {
    echo "\nStatement-reuse crossover (per-query microseconds):\n\n| N | interpolated | prepare-once + N executes |\n|---|---|---|\n";
    foreach ($out['crossover'] as $row) {
        printf("| %d | %.1f | %.1f |\n", $row['n'], $row['interp_us_per_q'], $row['prepare_us_per_q']);
    }
}

if ($out['bulk'] !== []) {
    printf("\nBulk load, %d rows, one transaction per channel:\n\n| channel | seconds | rows/s | checksum |\n|---|---|---|---|\n", $out['bulk']['_rows'] ?? 0);
    foreach ($out['bulk'] as $name => $b) {
        if ($name === '_rows') {
            continue;
        }
        if (isset($b['error'])) {
            printf("| %s | - | - | %s |\n", $name, $b['error']);
            continue;
        }
        printf("| %s | %.3f | %d | %s |\n", $name, $b['seconds'], $b['rows_per_s'], $b['checksum_ok'] ? 'OK' : 'MISMATCH');
    }
}

if (isset($opts['json'])) {
    file_put_contents((string)$opts['json'], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}

exit($badGates === [] ? 0 : 1);

//endregion
