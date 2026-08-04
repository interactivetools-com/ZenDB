<?php
declare(strict_types=1);

/**
 * MySQL escape speed probe: paired A/B benchmarks of every escaper candidate and
 * calling convention, designed for noisy CI runners.
 *
 *     php .github/scripts/escape-probe.php [--json=out.json] [--filter=id1,id2] [--scale=1.0] [--skip-corpus]
 *
 * Needs a live MySQL/MariaDB (DB_* env vars, same defaults as phpunit.xml.dist):
 * the baseline real_escape_string requires a connection handle, and the corpus
 * gate verifies every candidate against the live escaper before timing. The
 * connection is a prerequisite, not part of the measurement - real_escape_string
 * runs client-side in mysqlnd with no per-call round trip, so every number here
 * is pure CPU. Round-trip comparisons live in escape-e2e-probe.php.
 *
 * The CI matrix (escape-results.md) is the citable source; local runs are for
 * direction checks only. For a clean local run:
 *
 *     php -n -d extension=mysqli -d zend_extension=opcache -d opcache.enable_cli=1 .github/scripts/escape-probe.php
 *
 * (-n skips conf.d, which keeps xdebug out; add -d extension=pdo_mysql for the
 * PDO::quote reference cells.)
 *
 * Design rules (inherited from SmartString's speed-probe.php):
 * - Every number is a ratio from interleaved A/B pairs in one process (A,B,A,B...),
 *   best-of-7 per side, so shared-VM speed wobble cancels out.
 * - Input strings are runtime-built and cycled from pools of >= 64 distinct values
 *   (8 for the 1MB pool); literals are interned, which flatters repeated-literal loops.
 * - Every result is consumed (strlen accumulator) so dead code can't be eliminated.
 * - Candidates must pass the corpus gate BEFORE timing; a failing escaper reports
 *   CORPUS_FAIL and its timings are withheld.
 * - Headline cells use a MATERIALIZING sink: the full "'" . esc($v) . "'" quoted
 *   literal expression, because str_replace/strtr/addslashes return the original
 *   string zero-copy on clean input while real_escape_string always allocates -
 *   a strlen-only sink would overstate every zero-copy candidate. Scan-cost cells
 *   keep the strlen sink and say so in their sink tag.
 * - The selftie cell (A and B identical) must report TIE; anything else measures
 *   harness bias on that platform and widens the trustworthy dead band.
 */

require __DIR__ . '/escape-corpus.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

//region Escaper candidates

// All escapables except the apostrophe; a string clean of these needs only the
// single-pair apostrophe replacement (apostrophes dominate real text)
const ESC_MASK6 = "\\\"\n\r\0\x1a";
// All 7 escapables
const ESC_MASK7 = "\\\"\n\r\0\x1a'";

const ESC_TAIL3_FROM = ["\n", "\r", "\x1a"];
const ESC_TAIL3_TO   = ["\\n", "\\r", "\\Z"];
const ESC_2PAIR_FROM = ["\\", "'"];
const ESC_2PAIR_TO   = ["\\\\", "\\'"];

/** The proven winner from the prior research: 7 sequential pairs, backslash first. */
function esc_str_replace(string $s): string
{
    return str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
}

/** Single-pass scalar scan with bitset + hash lookup (php_strtr_array_ex). */
function esc_strtr(string $s): string
{
    return strtr($s, ZENDB_ESCAPE_MAP);
}

/**
 * addslashes covers 4 of the 7 (backslash, both quotes, NUL as \0) with an
 * SSE4.2/NEON fast path and zero-copy clean return; the 3-pair tail finishes
 * LF, CR, and Ctrl-Z. Ordering is safe: the tail's FROM bytes are never
 * produced by addslashes, and the tail's backslashes land after doubling.
 */
function esc_addslashes_tail(string $s): string
{
    return str_replace(ESC_TAIL3_FROM, ESC_TAIL3_TO, addslashes($s));
}

/**
 * addcslashes with a 5-char list emits valid \n and \r (the octal corruption
 * that disqualified the 7-char list only affects NUL and Ctrl-Z, handled by
 * the 2-pair tail). One scalar pass, no SIMD; prior data (0.471s vs str_replace
 * 0.497s on the 1.5M-value corpus) says one-pass can beat 7 SIMD passes.
 */
function esc_addcslashes_tail(string $s): string
{
    return str_replace(["\0", "\x1a"], ["\\0", "\\Z"], addcslashes($s, "'\"\\\n\r"));
}

/**
 * Quote-only fast path: if nothing but apostrophes needs escaping (the dominant
 * dirty case in prose), run the single-pair replacement; otherwise full 7-pair.
 * Differs from the prior research's failed scan-first tier, which bailed to the
 * FULL escaper on any escapable including apostrophes, so common text paid
 * scan + full price.
 */
function esc_quote_premask(string $s): string
{
    if (strcspn($s, ESC_MASK6) === strlen($s)) {
        return str_replace("'", "\\'", $s);
    }
    return str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
}

/** Full-clean gate: one 7-char scan, return as-is when nothing matches. */
function esc_clean_gate(string $s): string
{
    if (strcspn($s, ESC_MASK7) === strlen($s)) {
        return $s;
    }
    return str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
}

/**
 * Chunked dirty-region rewriter: strcspn hops between escapables, copying clean
 * spans and mapping single dirty bytes. Predicted to win only on long strings
 * with sparse escapables (big TEXT columns in dumps).
 */
function esc_chunked(string $s): string
{
    $len = strlen($s);
    $pos = strcspn($s, ESC_MASK7);
    if ($pos === $len) {
        return $s;
    }
    $out = substr($s, 0, $pos);
    while ($pos < $len) {
        $out .= ZENDB_ESCAPE_MAP[$s[$pos]];
        $next = $pos + 1;
        $span = strcspn($s, ESC_MASK7, $next);
        $out .= substr($s, $next, $span);
        $pos  = $next + $span;
    }
    return $out;
}

/** Single-pass PCRE with C-level per-match map lookup (known-slow reference). */
function esc_preg_callback(string $s): string
{
    return preg_replace_callback('/[\x00\n\r\x{1a}\\\\\'"]/', static fn(array $m): string => ZENDB_ESCAPE_MAP[$m[0]], $s);
}

/** Userland byte loop: the calibration floor for "just write the loop yourself". */
function esc_byte_loop(string $s): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        for ($b = 0; $b <= 255; $b++) {
            $map[chr($b)] = chr($b);
        }
        foreach (ZENDB_ESCAPE_MAP as $from => $to) {
            $map[$from] = $to;
        }
    }
    $out = '';
    for ($i = 0, $len = strlen($s); $i < $len; $i++) {
        $out .= $map[$s[$i]];
    }
    return $out;
}

// --- Query-equivalent tier: NOT byte-identical to real_escape_string, but the
// --- escaped value parses back to identical bytes inside a single-quoted
// --- literal sent over the length-prefixed protocol. Never dump-safe (raw
// --- control bytes in statement text), gated by the parse-back corpus check.

/** Bare addslashes: raw LF/CR/Ctrl-Z pass through as data inside quotes. */
function esc_addslashes_bare(string $s): string
{
    return addslashes($s);
}

/** The strict minimum for single-quoted context: backslash and apostrophe. */
function esc_2pair(string $s): string
{
    return str_replace(ESC_2PAIR_FROM, ESC_2PAIR_TO, $s);
}

/** One-pass PCRE backslash-prefix: \<raw byte> for every escapable. */
function esc_preg_class(string $s): string
{
    return preg_replace('/[\x00\n\r\x{1a}\\\\\'"]/', '\\\\$0', $s);
}

//endregion
//region Calling conventions and ZenDB integration shapes

/** Named-function wrapper for the dispatch ladder. */
function zenesc(string $s): string
{
    return str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
}

final class EscStyles
{
    public const FROM = ["\\", "'", "\"", "\n", "\r", "\0", "\x1a"];
    public const TO   = ["\\\\", "\\'", "\\\"", "\\n", "\\r", "\\0", "\\Z"];

    public static array $fromProp = ["\\", "'", "\"", "\n", "\r", "\0", "\x1a"];
    public static array $toProp   = ["\\\\", "\\'", "\\\"", "\\n", "\\r", "\\0", "\\Z"];

    public bool $fastEscapeOk = true;
    public \Closure $escapeFn;
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli   = $mysqli;
        $this->escapeFn = static fn(string $s): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
    }

    public static function escStatic(string $s): string
    {
        return str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
    }

    public function escInstance(string $s): string
    {
        return str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
    }

    /** Class-const operand variant for the const-table cells. */
    public static function escClassConst(string $s): string
    {
        return str_replace(self::FROM, self::TO, $s);
    }

    /** Static-property operand variant for the const-table cells. */
    public static function escStaticProp(string $s): string
    {
        return str_replace(self::$fromProp, self::$toProp, $s);
    }

    /** Shape C: private method branching on the probe flag internally. */
    public function escGuarded(string $s): string
    {
        if ($this->fastEscapeOk) {
            return str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
        }
        return $this->mysqli->real_escape_string($s);
    }

    public function __call(string $name, array $args): string
    {
        return str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $args[0]);
    }
}

//endregion
//region Input pools (runtime-built, never interned literals)

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

/**
 * @return array<string, array>  pool name -> distinct runtime-built values
 */
function build_pools(): array
{
    $pools = [];
    for ($i = 0; $i < 64; $i++) {
        $pools['clean10'][]  = build_clean(10, 1000 + $i);
        $pools['clean200'][] = build_clean(200, 2000 + $i);
        $pools['clean1k'][]  = build_clean(1024, 3000 + $i);

        // prose1k: typed-prose escapable density - one quoted phrase plus two
        // apostrophes per KB (~0.4%, corpus-measured in SmartString's pools;
        // apostrophe dominates real text, & < > " are rare); the realistic
        // "has escapables" long field
        $prose = build_clean(1020, 4000 + $i);
        $pools['prose1k'][] = substr($prose, 0, 250) . '"' . substr($prose, 250, 50) . '"'
            . substr($prose, 300, 300) . "'" . substr($prose, 600, 250) . "'" . substr($prose, 850);

        // Real DB value sizes: most string values are short. clean32 = typical
        // varchar (name, city, slug); prose100 = short sentence with one
        // apostrophe (O'Brien-density short text)
        $pools['clean32'][] = build_clean(32, 13000 + $i);
        $p100 = build_clean(100, 14000 + $i);
        $pools['prose100'][] = substr($p100, 0, 40) . "'" . substr($p100, 41);

        // text10k: a long article-shaped field at prose density (the 10KB body
        // row from SmartString's performance table)
        $pools['text10k'][] = str_repeat($pools['prose1k'][count($pools['prose1k']) - 1], 10);

        // dirty1k: dense escapables (5%+), the strtr-favoring bound
        $pools['dirty1k'][] = str_replace(['a', 'e', 'o'], ["'", '"', "\\"], build_clean(1024, 5000 + $i));

        // sparse1k: ONE escapable in 1KB - str_replace's best case, chunked's target
        $sparse = build_clean(1024, 6000 + $i);
        $pools['sparse1k'][] = substr($sparse, 0, 512) . "'" . substr($sparse, 513);

        // dirty10: short value with one apostrophe (O'Brien-shaped)
        $d = build_clean(10, 7000 + $i);
        $pools['dirty10'][] = substr($d, 0, 4) . "'" . substr($d, 5);

        // datetime: server-shaped date strings (zero escapables, 19B)
        mt_srand(8000 + $i);
        $pools['datetime'][] = sprintf('20%02d-%02d-%02d %02d:%02d:%02d',
            mt_rand(20, 26), mt_rand(1, 12), mt_rand(1, 28), mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59));

        // accented1k: French prose density (~2.5% accented chars), no escapables -
        // every byte >= 0x80 exercises mysqlnd's multibyte checks (the 8.4 rewrite)
        $accents = 0;
        $pools['accented1k'][] = preg_replace_callback('/a/', static function () use (&$accents): string {
            return ++$accents % 5 === 0 ? "\u{E9}" : 'a';
        }, build_clean(950, 9000 + $i));

        // cjk1k: nearly every byte >= 0x80 - the multibyte-heavy extreme
        mt_srand(10000 + $i);
        $cjk = '';
        $chars = ["\u{4E2D}", "\u{6587}", "\u{6F22}", "\u{5B57}", "\u{8A9E}", "\u{8A00}"];
        while (strlen($cjk) < 1024) {
            $cjk .= $chars[mt_rand(0, 5)];
        }
        $pools['cjk1k'][] = substr($cjk, 0, 1023 - (strlen($cjk) - 1024) % 3);

        // bin1k: random bytes incl NUL and invalid leads (encrypted MEDIUMBLOB shape)
        mt_srand(11000 + $i);
        $bin = '';
        for ($j = 0; $j < 1024; $j++) {
            $bin .= chr(mt_rand(0, 255));
        }
        $pools['bin1k'][] = $bin;

        // ints: DB-shaped numeric values, stay ints, cast per call
        mt_srand(12000 + $i);
        $pools['ints'][] = [1, 42, 999, 100000, 1752934000, PHP_INT_MAX - 1000][$i % 6] + mt_rand(0, 999);
    }

    // cjk pool entries must be valid UTF-8 (substr can split a char); trim to whole chars
    $pools['cjk1k'] = array_map(static function (string $s): string {
        while ($s !== '' && preg_match('//u', $s) !== 1) {
            $s = substr($s, 0, -1);
        }
        return $s;
    }, $pools['cjk1k']);

    // mix: realistic DB row value mix - 60% short clean, 15% datetime,
    // 15% clean-200B, 5% prose-1KB, 5% dirty-short
    for ($i = 0; $i < 64; $i++) {
        $r = $i % 20;
        $pools['mix'][] = match (true) {
            $r < 12 => $pools['clean10'][$i],
            $r < 15 => $pools['datetime'][$i],
            $r < 18 => $pools['clean200'][$i],
            $r < 19 => $pools['prose1k'][$i],
            default => $pools['dirty10'][$i],
        };
    }

    // big1m: 8 distinct 1MB prose-density strings (64 would cost 64MB of pool)
    for ($i = 0; $i < 8; $i++) {
        $base = str_repeat($pools['prose1k'][$i * 8], 1024);
        $pools['big1m'][] = substr($base, 0, 1048576);
    }

    return $pools;
}

//endregion
//region Harness

/**
 * Interleaved paired benchmark: alternate A and B reps, best-of-N each.
 * Callables take only the iteration count (their input pool is bound at build time).
 * Returns [a_ns, b_ns] per-op bests.
 */
function ab_bench(callable $a, callable $b, int $iters, int $reps = 7): array
{
    $bestA  = INF;
    $bestB  = INF;
    $warmup = min($iters, max(100, intdiv($iters, 50)));
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

/** Global sink: consumed results, keeps every benchmarked expression alive */
$GLOBALS['sink'] = 0;

/**
 * Bind a per-string closure to a pool, producing a timed loop body fn(int $iters).
 * The modulo cycle defeats single-zval cache effects (interning, flag caching).
 */
function looped(callable $perString, array $pool): callable
{
    return static function (int $iters) use ($perString, $pool): void {
        $n   = count($pool);
        $acc = 0;
        for ($i = 0; $i < $iters; $i++) {
            $acc += $perString($pool[$i % $n]);
        }
        $GLOBALS['sink'] += $acc;
    };
}

//endregion
//region Test registry

/**
 * Each test: [id, iterations-class, sink-tag, A-label, B-label, A-callable, B-callable, escapers-to-gate]
 *
 * Sink tags: "quoted" = strlen("'" . esc($v) . "'"), the materializing sink that
 * headline ratios must come from; "strlen" = strlen(esc($v)), scan-cost isolation
 * only (flatters zero-copy candidates on clean pools); "expr" = a larger
 * expression described by the labels.
 */
function build_tests(array $pools, mysqli $mysqli, ?PDO $pdo): array
{
    // Headline sinks: full quoted-literal expression, both sides materialize
    $obj = new EscStyles($mysqli);

    $quotedReal = static fn(string $v): int => strlen("'" . $mysqli->real_escape_string($v) . "'");
    $quotedFast = static fn(string $v): int => strlen("'" . str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v) . "'");

    $tests = [
        // --- Harness bias control: A and B are the same callable, must report TIE ---
        ['selftie-short', 'short', 'quoted', 'quoted str_replace', 'quoted str_replace (same)',
            looped($quotedFast, $pools['clean10']),
            looped($quotedFast, $pools['clean10']), []],
        ['selftie-1kb', 'long', 'quoted', 'quoted str_replace', 'quoted str_replace (same)',
            looped($quotedFast, $pools['prose1k']),
            looped($quotedFast, $pools['prose1k']), []],

        // --- Baseline forms: OO vs procedural real_escape_string ---
        ['real-oo-vs-proc', 'short', 'quoted', 'OO real_escape_string', 'procedural mysqli_real_escape_string',
            looped($quotedReal, $pools['clean10']),
            looped(static fn(string $v): int => strlen("'" . mysqli_real_escape_string($mysqli, $v) . "'"), $pools['clean10']), []],

        // --- Headline: real_escape_string vs inline 7-pair str_replace, per pool ---
        ['esc-clean10', 'short', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['clean10']), looped($quotedFast, $pools['clean10']), ['esc_str_replace']],
        ['esc-clean32', 'short', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['clean32']), looped($quotedFast, $pools['clean32']), ['esc_str_replace']],
        ['esc-prose100', 'short', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['prose100']), looped($quotedFast, $pools['prose100']), ['esc_str_replace']],
        ['esc-text10k', 'bulk', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['text10k']), looped($quotedFast, $pools['text10k']), ['esc_str_replace']],
        ['esc-datetime', 'short', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['datetime']), looped($quotedFast, $pools['datetime']), ['esc_str_replace']],
        ['esc-clean1k', 'long', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['clean1k']), looped($quotedFast, $pools['clean1k']), ['esc_str_replace']],
        ['esc-prose1k', 'long', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['prose1k']), looped($quotedFast, $pools['prose1k']), ['esc_str_replace']],
        ['esc-dirty1k', 'long', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['dirty1k']), looped($quotedFast, $pools['dirty1k']), ['esc_str_replace']],
        ['esc-accented1k', 'long', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['accented1k']), looped($quotedFast, $pools['accented1k']), ['esc_str_replace']],
        ['esc-cjk1k', 'long', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['cjk1k']), looped($quotedFast, $pools['cjk1k']), ['esc_str_replace']],
        ['esc-bin1k', 'long', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['bin1k']), looped($quotedFast, $pools['bin1k']), ['esc_str_replace']],
        ['esc-mix', 'medium', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['mix']), looped($quotedFast, $pools['mix']), ['esc_str_replace']],
        ['esc-big1m', 'huge', 'quoted', 'real_escape_string', 'inline str_replace', looped($quotedReal, $pools['big1m']), looped($quotedFast, $pools['big1m']), ['esc_str_replace']],

        // --- Primitive shootout: str_replace vs each byte-identical alternative ---
        ['alt-addsl-clean1k', 'long', 'quoted', 'str_replace', 'addslashes + 3-pair tail',
            looped($quotedFast, $pools['clean1k']),
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['clean1k']), ['esc_str_replace', 'esc_addslashes_tail']],
        ['alt-addsl-prose1k', 'long', 'quoted', 'str_replace', 'addslashes + 3-pair tail',
            looped($quotedFast, $pools['prose1k']),
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['prose1k']), ['esc_str_replace', 'esc_addslashes_tail']],
        ['alt-addsl-dirty1k', 'long', 'quoted', 'str_replace', 'addslashes + 3-pair tail',
            looped($quotedFast, $pools['dirty1k']),
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['dirty1k']), ['esc_str_replace', 'esc_addslashes_tail']],
        ['alt-addsl-cjk1k', 'long', 'quoted', 'str_replace', 'addslashes + 3-pair tail',
            looped($quotedFast, $pools['cjk1k']),
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['cjk1k']), ['esc_str_replace', 'esc_addslashes_tail']],
        ['alt-addsl-short', 'short', 'quoted', 'str_replace', 'addslashes + 3-pair tail',
            looped($quotedFast, $pools['clean10']),
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['clean10']), ['esc_str_replace', 'esc_addslashes_tail']],
        ['alt-addsl-prose100', 'short', 'quoted', 'str_replace', 'addslashes + 3-pair tail',
            looped(static fn(string $v): int => strlen("'" . str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v) . "'"), $pools['prose100']),
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['prose100']), ['esc_str_replace', 'esc_addslashes_tail']],
        ['alt-addsl-text10k', 'bulk', 'quoted', 'str_replace', 'addslashes + 3-pair tail',
            looped(static fn(string $v): int => strlen("'" . str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v) . "'"), $pools['text10k']),
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['text10k']), ['esc_str_replace', 'esc_addslashes_tail']],
        ['alt-addcsl-prose1k', 'long', 'quoted', 'addslashes + 3-pair tail', 'addcslashes-5 + 2-pair tail',
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['prose1k']),
            looped(static fn(string $v): int => strlen("'" . esc_addcslashes_tail($v) . "'"), $pools['prose1k']), ['esc_addslashes_tail', 'esc_addcslashes_tail']],
        ['alt-addcsl-dirty1k', 'long', 'quoted', 'addslashes + 3-pair tail', 'addcslashes-5 + 2-pair tail',
            looped(static fn(string $v): int => strlen("'" . esc_addslashes_tail($v) . "'"), $pools['dirty1k']),
            looped(static fn(string $v): int => strlen("'" . esc_addcslashes_tail($v) . "'"), $pools['dirty1k']), ['esc_addslashes_tail', 'esc_addcslashes_tail']],
        ['alt-strtr-sparse1k', 'long', 'quoted', 'str_replace', 'strtr map',
            looped($quotedFast, $pools['sparse1k']),
            looped(static fn(string $v): int => strlen("'" . esc_strtr($v) . "'"), $pools['sparse1k']), ['esc_str_replace', 'esc_strtr']],
        ['alt-strtr-dirty1k', 'long', 'quoted', 'str_replace', 'strtr map',
            looped($quotedFast, $pools['dirty1k']),
            looped(static fn(string $v): int => strlen("'" . esc_strtr($v) . "'"), $pools['dirty1k']), ['esc_str_replace', 'esc_strtr']],
        ['alt-strtr-short', 'short', 'quoted', 'str_replace', 'strtr map',
            looped($quotedFast, $pools['dirty10']),
            looped(static fn(string $v): int => strlen("'" . esc_strtr($v) . "'"), $pools['dirty10']), ['esc_str_replace', 'esc_strtr']],
        ['alt-premask-prose1k', 'long', 'quoted', 'str_replace', 'quote-only premask',
            looped($quotedFast, $pools['prose1k']),
            looped(static fn(string $v): int => strlen("'" . esc_quote_premask($v) . "'"), $pools['prose1k']), ['esc_str_replace', 'esc_quote_premask']],
        ['alt-premask-clean1k', 'long', 'quoted', 'str_replace', 'quote-only premask',
            looped($quotedFast, $pools['clean1k']),
            looped(static fn(string $v): int => strlen("'" . esc_quote_premask($v) . "'"), $pools['clean1k']), ['esc_str_replace', 'esc_quote_premask']],
        ['alt-premask-dirty1k', 'long', 'quoted', 'str_replace', 'quote-only premask',
            looped($quotedFast, $pools['dirty1k']),
            looped(static fn(string $v): int => strlen("'" . esc_quote_premask($v) . "'"), $pools['dirty1k']), ['esc_str_replace', 'esc_quote_premask']],
        ['alt-gate-clean1k', 'long', 'quoted', 'str_replace', 'strcspn clean gate',
            looped($quotedFast, $pools['clean1k']),
            looped(static fn(string $v): int => strlen("'" . esc_clean_gate($v) . "'"), $pools['clean1k']), ['esc_str_replace', 'esc_clean_gate']],
        ['alt-gate-short', 'short', 'quoted', 'str_replace', 'strcspn clean gate',
            looped($quotedFast, $pools['clean10']),
            looped(static fn(string $v): int => strlen("'" . esc_clean_gate($v) . "'"), $pools['clean10']), ['esc_str_replace', 'esc_clean_gate']],
        ['alt-chunk-sparse1k', 'long', 'quoted', 'str_replace', 'strcspn chunked rewriter',
            looped($quotedFast, $pools['sparse1k']),
            looped(static fn(string $v): int => strlen("'" . esc_chunked($v) . "'"), $pools['sparse1k']), ['esc_str_replace', 'esc_chunked']],
        ['alt-chunk-big1m', 'huge', 'quoted', 'str_replace', 'strcspn chunked rewriter',
            looped($quotedFast, $pools['big1m']),
            looped(static fn(string $v): int => strlen("'" . esc_chunked($v) . "'"), $pools['big1m']), ['esc_str_replace', 'esc_chunked']],

        // --- Query-equivalent tier (parse-back gated, never dump-safe) ---
        ['qe-addsl-prose1k', 'long', 'quoted', 'str_replace (byte-identical)', 'bare addslashes (query-equivalent)',
            looped($quotedFast, $pools['prose1k']),
            looped(static fn(string $v): int => strlen("'" . addslashes($v) . "'"), $pools['prose1k']), ['esc_str_replace', 'esc_addslashes_bare']],
        ['qe-2pair-prose1k', 'long', 'quoted', 'str_replace (byte-identical)', '2-pair minimal (query-equivalent)',
            looped($quotedFast, $pools['prose1k']),
            looped(static fn(string $v): int => strlen("'" . esc_2pair($v) . "'"), $pools['prose1k']), ['esc_str_replace', 'esc_2pair']],
        ['qe-2pair-dirty1k', 'long', 'quoted', 'str_replace (byte-identical)', '2-pair minimal (query-equivalent)',
            looped($quotedFast, $pools['dirty1k']),
            looped(static fn(string $v): int => strlen("'" . esc_2pair($v) . "'"), $pools['dirty1k']), ['esc_str_replace', 'esc_2pair']],

        // --- Calibration floors ---
        ['floor-pregcb-prose1k', 'long', 'quoted', 'str_replace', 'preg_replace_callback',
            looped($quotedFast, $pools['prose1k']),
            looped(static fn(string $v): int => strlen("'" . esc_preg_callback($v) . "'"), $pools['prose1k']), ['esc_str_replace', 'esc_preg_callback']],
        ['floor-pregclass-prose1k', 'long', 'quoted', 'str_replace', 'one-pass PCRE class (query-equivalent)',
            looped($quotedFast, $pools['prose1k']),
            looped(static fn(string $v): int => strlen("'" . esc_preg_class($v) . "'"), $pools['prose1k']), ['esc_str_replace', 'esc_preg_class']],
        ['floor-loop-prose1k', 'long', 'quoted', 'str_replace', 'userland byte loop',
            looped($quotedFast, $pools['prose1k']),
            looped(static fn(string $v): int => strlen("'" . esc_byte_loop($v) . "'"), $pools['prose1k']), ['esc_str_replace', 'esc_byte_loop']],

        // --- Hex transport reference (different output, no escaping at all) ---
        ['ref-hex-bin1k', 'long', 'expr', 'quoted str_replace literal', '0x hex literal (bin2hex)',
            looped($quotedFast, $pools['bin1k']),
            looped(static fn(string $v): int => strlen('0x' . bin2hex($v)), $pools['bin1k']), []],
        ['ref-hex-prose1k', 'long', 'expr', 'quoted str_replace literal', '0x hex literal (bin2hex)',
            looped($quotedFast, $pools['prose1k']),
            looped(static fn(string $v): int => strlen('0x' . bin2hex($v)), $pools['prose1k']), []],

        // --- Numbers skip escaping entirely (ZenDB sets MYSQLI_OPT_INT_AND_FLOAT_NATIVE) ---
        ['ref-int-cast', 'short', 'expr', 'escape stringified int', 'native int branch (no escape)',
            looped(static fn(int $v): int => strlen("'" . str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, (string)$v) . "'"), $pools['ints']),
            looped(static fn(int $v): int => strlen((string)$v), $pools['ints']), []],
        // Explicit (string) cast vs letting concatenation coerce: same C conversion
        // either way, expected exact tie - the cell exists to prove it
        ['ref-int-coerce', 'short', 'expr', 'concat with (string) cast', 'concat with implicit coercion',
            looped(static fn(int $v): int => strlen('v = ' . (string)$v), $pools['ints']),
            looped(static fn(int $v): int => strlen('v = ' . $v), $pools['ints']), []],

        // --- Call-dispatch ladder: A always the inlined expression, B one convention ---
        ['call-named', 'short', 'strlen', 'inline str_replace', 'named function',
            looped(static fn(string $v): int => strlen(str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), $pools['clean10']),
            looped(static fn(string $v): int => strlen(zenesc($v)), $pools['clean10']), []],
        ['call-static', 'short', 'strlen', 'inline str_replace', 'static method',
            looped(static fn(string $v): int => strlen(str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), $pools['clean10']),
            looped(static fn(string $v): int => strlen(EscStyles::escStatic($v)), $pools['clean10']), []],
        ['call-instance', 'short', 'strlen', 'inline str_replace', 'instance method',
            looped(static fn(string $v): int => strlen(str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), $pools['clean10']),
            looped(static fn(string $v): int => strlen($obj->escInstance($v)), $pools['clean10']), []],
        ['call-closure', 'short', 'strlen', 'inline str_replace', 'closure in local $fn',
            looped(static fn(string $v): int => strlen(str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), $pools['clean10']),
            (static function (array $pool): callable {
                $fn = static fn(string $s): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s);
                return looped(static fn(string $v): int => strlen($fn($v)), $pool);
            })($pools['clean10']), []],
        ['call-fcc', 'short', 'strlen', 'inline str_replace', 'first-class callable of named fn',
            looped(static fn(string $v): int => strlen(str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), $pools['clean10']),
            (static function (array $pool): callable {
                $fn = zenesc(...);
                return looped(static fn(string $v): int => strlen($fn($v)), $pool);
            })($pools['clean10']), []],
        ['call-propfn', 'short', 'strlen', 'inline str_replace', 'closure in object property',
            looped(static fn(string $v): int => strlen(str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), $pools['clean10']),
            looped(static fn(string $v): int => strlen(($obj->escapeFn)($v)), $pools['clean10']), []],
        ['call-varname', 'short', 'strlen', 'inline str_replace', 'callable-string variable',
            looped(static fn(string $v): int => strlen(str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), $pools['clean10']),
            (static function (array $pool): callable {
                $name = 'zen' . 'esc'; // runtime-built so opcache can't resolve it at compile time
                return looped(static fn(string $v): int => strlen($name($v)), $pool);
            })($pools['clean10']), []],
        ['call-magic', 'short', 'strlen', 'inline str_replace', '__call trampoline',
            looped(static fn(string $v): int => strlen(str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), $pools['clean10']),
            looped(static fn(string $v): int => strlen($obj->escViaMagic($v)), $pools['clean10']), []],

        // --- Constant-table cost: where should FROM/TO live? ---
        ['const-classconst', 'short', 'strlen', 'call-site literal arrays', 'class const arrays',
            looped(static fn(string $v): int => strlen(EscStyles::escStatic($v)), $pools['clean10']),
            looped(static fn(string $v): int => strlen(EscStyles::escClassConst($v)), $pools['clean10']), []],
        ['const-staticprop', 'short', 'strlen', 'call-site literal arrays', 'static property arrays',
            looped(static fn(string $v): int => strlen(EscStyles::escStatic($v)), $pools['clean10']),
            looped(static fn(string $v): int => strlen(EscStyles::escStaticProp($v)), $pools['clean10']), []],

        // --- ZenDB integration shapes (the ConnectionInternals.php:789 decision) ---
        ['zen-guard-vs-real', 'short', 'quoted', 'real_escape_string (today)', 'shape A: bool-property inline ternary',
            looped($quotedReal, $pools['mix']),
            looped(static fn(string $v): int => strlen("'" . ($obj->fastEscapeOk ? str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v) : $mysqli->real_escape_string($v)) . "'"), $pools['mix']), ['esc_str_replace']],
        ['zen-guard-vs-inline', 'short', 'quoted', 'inline str_replace (no guard)', 'shape A: bool-property inline ternary',
            looped($quotedFast, $pools['mix']),
            looped(static fn(string $v): int => strlen("'" . ($obj->fastEscapeOk ? str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v) : $mysqli->real_escape_string($v)) . "'"), $pools['mix']), ['esc_str_replace']],
        ['zen-guard-vs-method', 'short', 'quoted', 'shape A: bool-property inline ternary', 'shape C: guarded method',
            looped(static fn(string $v): int => strlen("'" . ($obj->fastEscapeOk ? str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v) : $mysqli->real_escape_string($v)) . "'"), $pools['mix']),
            looped(static fn(string $v): int => strlen("'" . $obj->escGuarded($v) . "'"), $pools['mix']), ['esc_str_replace']],
        ['zen-guard-vs-closure', 'short', 'quoted', 'shape A: bool-property inline ternary', 'shape B: stored closure',
            looped(static fn(string $v): int => strlen("'" . ($obj->fastEscapeOk ? str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v) : $mysqli->real_escape_string($v)) . "'"), $pools['mix']),
            looped(static fn(string $v): int => strlen("'" . ($obj->escapeFn)($v) . "'"), $pools['mix']), ['esc_str_replace']],

        // --- ZenDB-shaped whole-query compile: is the per-query win above noise? ---
        ['query-compile-5', 'medium', 'expr', 'compile 5-value query via real_escape', 'compile 5-value query via str_replace',
            query_compile_loop($pools['mix'], static fn(string $v): string => $mysqli->real_escape_string($v), 5),
            query_compile_loop($pools['mix'], static fn(string $v): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v), 5), ['esc_str_replace']],
        ['query-compile-20', 'medium', 'expr', 'compile 20-value query via real_escape', 'compile 20-value query via str_replace',
            query_compile_loop($pools['mix'], static fn(string $v): string => $mysqli->real_escape_string($v), 20),
            query_compile_loop($pools['mix'], static fn(string $v): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v), 20), ['esc_str_replace']],
        ['bulk-assembly-500', 'bulk', 'expr', '500-value INSERT via real_escape', '500-value INSERT via str_replace',
            bulk_insert_loop($pools['mix'], static fn(string $v): string => $mysqli->real_escape_string($v)),
            bulk_insert_loop($pools['mix'], static fn(string $v): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)), ['esc_str_replace']],
        ['bulk-array-subject', 'bulk', 'expr', 'per-value str_replace loop', 'array-subject str_replace batch',
            bulk_insert_loop($pools['mix'], static fn(string $v): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $v)),
            bulk_array_subject_loop($pools['mix']), ['esc_str_replace']],
    ];

    // PDO::quote reference cells (optional extension)
    if ($pdo !== null) {
        $tests[] = ['ref-pdoquote-short', 'short', 'quoted', 'quoted real_escape_string', 'PDO::quote',
            looped($quotedReal, $pools['clean10']),
            looped(static fn(string $v): int => strlen($pdo->quote($v)), $pools['clean10']), []];
        $tests[] = ['ref-pdoquote-prose1k', 'long', 'quoted', 'quoted real_escape_string', 'PDO::quote',
            looped($quotedReal, $pools['prose1k']),
            looped(static fn(string $v): int => strlen($pdo->quote($v)), $pools['prose1k']), []];
    }

    // mysqli::quote_string reference cells (PHP 8.6+)
    if (method_exists($mysqli, 'quote_string')) {
        $tests[] = ['ref-quotestring-short', 'short', 'quoted', 'quoted real_escape_string', 'mysqli::quote_string',
            looped($quotedReal, $pools['clean10']),
            looped(static fn(string $v): int => strlen($mysqli->{'quote_string'}($v)), $pools['clean10']), []];
        $tests[] = ['ref-quotestring-prose1k', 'long', 'quoted', 'quoted real_escape_string', 'mysqli::quote_string',
            looped($quotedReal, $pools['prose1k']),
            looped(static fn(string $v): int => strlen($mysqli->{'quote_string'}($v)), $pools['prose1k']), []];
        $tests[] = ['alt-quotestring-vs-fast', 'long', 'quoted', 'mysqli::quote_string', 'quoted str_replace',
            looped(static fn(string $v): int => strlen($mysqli->{'quote_string'}($v)), $pools['prose1k']),
            looped($quotedFast, $pools['prose1k']), ['esc_str_replace']];
    }

    return $tests;
}

/**
 * Compile a WHERE-shaped query with N quoted string values per iteration,
 * mirroring the interpolation loop at src/ConnectionInternals.php:789.
 */
function query_compile_loop(array $pool, callable $escape, int $values): callable
{
    return static function (int $iters) use ($pool, $escape, $values): void {
        $n   = count($pool);
        $acc = 0;
        for ($i = 0; $i < $iters; $i++) {
            $sql = 'SELECT * FROM t WHERE ';
            for ($v = 0; $v < $values; $v++) {
                $sql .= "col$v = '" . $escape($pool[($i + $v) % $n]) . "' AND ";
            }
            $acc += strlen($sql);
        }
        $GLOBALS['sink'] += $acc;
    };
}

/** Assemble a 500-value multi-row INSERT (5 columns x 100 rows) per iteration. */
function bulk_insert_loop(array $pool, callable $escape): callable
{
    return static function (int $iters) use ($pool, $escape): void {
        $n   = count($pool);
        $acc = 0;
        for ($i = 0; $i < $iters; $i++) {
            $sql = 'INSERT INTO t VALUES ';
            for ($row = 0; $row < 100; $row++) {
                $sql .= "('" . $escape($pool[($i + $row) % $n]) . "','" . $escape($pool[($i + $row + 1) % $n])
                    . "','" . $escape($pool[($i + $row + 2) % $n]) . "','" . $escape($pool[($i + $row + 3) % $n])
                    . "','" . $escape($pool[($i + $row + 4) % $n]) . "'),";
            }
            $acc += strlen($sql);
        }
        $GLOBALS['sink'] += $acc;
    };
}

/** Same 500-value INSERT via ONE array-subject str_replace call per row batch. */
function bulk_array_subject_loop(array $pool): callable
{
    return static function (int $iters) use ($pool): void {
        $n   = count($pool);
        $acc = 0;
        for ($i = 0; $i < $iters; $i++) {
            $values = [];
            for ($v = 0; $v < 500; $v++) {
                $values[] = $pool[($i + $v) % $n];
            }
            $escaped = str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $values);
            $sql     = 'INSERT INTO t VALUES ';
            for ($row = 0; $row < 100; $row++) {
                $base = $row * 5;
                $sql .= "('" . $escaped[$base] . "','" . $escaped[$base + 1] . "','" . $escaped[$base + 2]
                    . "','" . $escaped[$base + 3] . "','" . $escaped[$base + 4] . "'),";
            }
            $acc += strlen($sql);
        }
        $GLOBALS['sink'] += $acc;
    };
}

//endregion
//region Main

$opts    = getopt('', ['json::', 'filter::', 'scale::', 'skip-corpus']);
$filter  = isset($opts['filter']) ? array_flip(array_map('trim', explode(',', (string)$opts['filter']))) : null;
$scale   = isset($opts['scale']) ? max(0.01, (float)$opts['scale']) : 1.0;
$itersBy = ['short' => (int)(300000 * $scale), 'medium' => (int)(100000 * $scale),
            'long'  => (int)(30000 * $scale),  'bulk'   => (int)(2000 * $scale), 'huge' => (int)(100 * $scale)];

$mysqli = escape_corpus_connect();
$pdo    = null;
if (extension_loaded('pdo_mysql')) {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('DB_HOSTNAME') ?: '127.0.0.1', (int)(getenv('DB_PORT') ?: 3306), getenv('DB_DATABASE') ?: 'phpunit_test_db'),
        getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '');
}

// Timer granularity: median delta of consecutive hrtime() calls (Windows QPC
// ticks at ~100ns; a cell must not report quantization noise as a verdict)
$deltas = [];
for ($i = 0; $i < 1000; $i++) {
    $t1 = hrtime(true);
    $t2 = hrtime(true);
    $deltas[] = $t2 - $t1;
}
sort($deltas);

// One-time guard cost: the probe self-check the shipping code would run per connection
$t = hrtime(true);
$probeOk = escape_fast_path_ok($mysqli);
$probeNs = hrtime(true) - $t;

$out = [
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
    'sql_mode'       => $mysqli->query('SELECT @@SESSION.sql_mode')->fetch_row()[0],
    'quote_string'   => method_exists($mysqli, 'quote_string'),
    'pdo'            => $pdo !== null,
    'timer_gran_ns'  => $deltas[500],
    'probe_check_ns' => $probeNs,
    'iterations'     => $itersBy,
    'corpus'         => null,
    'tests'          => [],
];

// 1. Correctness gate: byte-identical candidates strict, query-equivalent tier parse-back
$escaperStatus = [];
if (!isset($opts['skip-corpus'])) {
    $corpus         = escape_corpus();
    $byteIdentical  = ['esc_str_replace', 'esc_strtr', 'esc_addslashes_tail', 'esc_addcslashes_tail',
                       'esc_quote_premask', 'esc_clean_gate', 'esc_chunked', 'esc_preg_callback', 'esc_byte_loop'];
    $queryEquivalent = ['esc_addslashes_bare', 'esc_2pair', 'esc_preg_class'];
    foreach ($byteIdentical as $fn) {
        $res = escape_corpus_assert($fn, $mysqli, $corpus);
        $escaperStatus[$fn] = $res['fail'] === 0;
        if ($res['fail'] > 0) {
            fwrite(STDERR, "CORPUS FAIL: $fn ({$res['fail']} of {$res['count']})\n" . implode("\n", $res['samples']) . "\n");
        }
    }
    foreach ($queryEquivalent as $fn) {
        $res = escape_corpus_assert($fn, $mysqli, $corpus, false);
        $escaperStatus[$fn] = $res['fail'] === 0;
        if ($res['fail'] > 0) {
            fwrite(STDERR, "CORPUS FAIL (parse-back): $fn ({$res['fail']} of {$res['count']})\n" . implode("\n", $res['samples']) . "\n");
        }
    }

    // Same-C-path identity checks: PDO::quote and mysqli::quote_string must equal
    // quote-wrapped real_escape_string (both route through mysqlnd's escaper)
    $identity = [];
    if ($pdo !== null) {
        $ok = true;
        foreach ($corpus as $s) {
            if ($pdo->quote($s) !== "'" . $mysqli->real_escape_string($s) . "'") {
                $ok = false;
                break;
            }
        }
        $identity['pdo_quote'] = $ok;
    }
    if (method_exists($mysqli, 'quote_string')) {
        $ok = true;
        foreach ($corpus as $s) {
            if ($mysqli->{'quote_string'}($s) !== "'" . $mysqli->real_escape_string($s) . "'") {
                $ok = false;
                break;
            }
        }
        $identity['quote_string'] = $ok;
    }

    $canary = escape_set_canary($mysqli);
    $out['corpus'] = [
        'entries'  => count($corpus),
        'escapers' => $escaperStatus,
        'identity' => $identity,
        'canary'   => implode(' ', $canary),
        'canary_ok' => $canary === ZENDB_ESCAPE_CANARY_EXPECTED,
    ];
    unset($corpus);
}

// 2. Benchmarks
$pools = build_pools();
// $sinkTag, not $sink: at file scope $sink would BE $GLOBALS['sink'], the benchmark accumulator
foreach (build_tests($pools, $mysqli, $pdo) as [$id, $sizeClass, $sinkTag, $aLabel, $bLabel, $aFn, $bFn, $gatedBy]) {
    if ($filter !== null && !isset($filter[$id])) {
        continue;
    }
    $withheld = array_values(array_filter($gatedBy, static fn(string $fn): bool => isset($escaperStatus[$fn]) && !$escaperStatus[$fn]));
    if ($withheld !== []) {
        $out['tests'][$id] = ['a_label' => $aLabel, 'b_label' => $bLabel, 'sink' => $sinkTag,
                              'verdict' => 'CORPUS_FAIL', 'failed_escapers' => $withheld];
        continue;
    }
    [$aNs, $bNs] = ab_bench($aFn, $bFn, $itersBy[$sizeClass]);
    $ratio = $aNs / $bNs; // > 1: B faster
    $out['tests'][$id] = [
        'a_label' => $aLabel, 'b_label' => $bLabel, 'sink' => $sinkTag,
        'a_ns'    => round($aNs, 1), 'b_ns' => round($bNs, 1),
        'ratio'   => round($ratio, 3),
        'verdict' => $ratio >= 1.05 ? 'B_FASTER' : ($ratio <= 0.952 ? 'A_FASTER' : 'TIE'),
    ];
}

// 3. Report: markdown to stdout (workflow appends to $GITHUB_STEP_SUMMARY), JSON to --json
printf("### PHP %s%s on %s %s | %s | opcache_cli=%s jit=%s | timer=%dns%s\n\n",
    $out['php'], $out['zts'] ? ' ZTS' : '', $out['os'], $out['arch'], $out['server'],
    $out['opcache'] ? 'on' : 'off', $out['jit'] !== '' ? $out['jit'] : 'off',
    $out['timer_gran_ns'],
    $out['xdebug'] ? ' **XDEBUG LOADED - RESULTS INVALID**' : '');
if ($out['corpus'] !== null) {
    $bad = array_keys(array_filter($out['corpus']['escapers'], static fn(bool $ok): bool => !$ok));
    printf("Corpus: %d entries, %s | canary [%s] %s | probe check %dns\n\n",
        $out['corpus']['entries'],
        $bad === [] ? 'all escapers pass' : 'FAILED: ' . implode(', ', $bad),
        $out['corpus']['canary'], $out['corpus']['canary_ok'] ? 'OK' : 'UNEXPECTED',
        $out['probe_check_ns']);
    foreach ($out['corpus']['identity'] as $name => $ok) {
        printf("Identity %s: %s\n", $name, $ok ? 'byte-identical to quoted real_escape_string' : 'MISMATCH');
    }
    echo "\n";
}
echo "| test | A | B | sink | A ns | B ns | B vs A | verdict |\n|---|---|---|---|---|---|---|---|\n";
foreach ($out['tests'] as $id => $t) {
    if (($t['verdict'] ?? '') === 'CORPUS_FAIL') {
        printf("| %s | %s | %s | %s | - | - | - | CORPUS_FAIL |\n", $id, $t['a_label'], $t['b_label'], $t['sink']);
        continue;
    }
    printf("| %s | %s | %s | %s | %.1f | %.1f | %.2fx | %s |\n",
        $id, $t['a_label'], $t['b_label'], $t['sink'], $t['a_ns'], $t['b_ns'], $t['ratio'], $t['verdict']);
}

if (isset($opts['json'])) {
    file_put_contents((string)$opts['json'], json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}

$anyFail = $out['corpus'] !== null
    && (in_array(false, $out['corpus']['escapers'], true)
        || in_array(false, $out['corpus']['identity'], true)
        || !$out['corpus']['canary_ok']);
exit($anyFail ? 1 : 0);

//endregion
