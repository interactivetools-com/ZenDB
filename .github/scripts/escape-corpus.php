<?php
declare(strict_types=1);

/**
 * Correctness corpus for MySQL escape fast-path candidates.
 *
 * The reference is a LIVE $mysqli->real_escape_string() on a utf8mb4 connection
 * with backslash escaping enabled (no NO_BASH... sql_mode), never a hardcoded
 * expectation, so the gate re-verifies against whatever driver actually runs.
 * escape-probe.php runs this gate before timing anything, and
 * tests/Escaping/EscapeEquivalenceTest.php runs the same corpus in the test suite.
 *
 * Equivalence has two tiers, split per string by UTF-8 validity:
 * - valid UTF-8 (incl. pure ASCII): candidate output must be BYTE-IDENTICAL
 *   to real_escape_string. One mismatch fails the gate.
 * - invalid UTF-8 / binary: byte-identity is driver-version-dependent (mysqlnd
 *   may insert a backslash before an invalid lead byte; observed in php-src
 *   master, absent on PHP 8.1 + MariaDB 11.3). Both outputs must instead PARSE
 *   back to the original bytes under MySQL string-literal rules, with no bare
 *   quote that would terminate the literal early. Identical-vs-diverged is
 *   recorded per run so a driver change is visible, never silently absorbed.
 *
 * Corpus coverage (~112k entries): empty string, all 256 single bytes, ALL
 * 65,536 two-byte strings, every byte at head/mid/tail of clean ASCII, all 49
 * adjacent escapable pairs, backslash-sequence traps ("a\nb" as literal text),
 * valid multibyte incl. emoji and combining marks, GBK/BIG5 injection sequences
 * (CVE-2006-2753 shapes), invalid UTF-8 (overlongs, surrogate halves,
 * truncations, stray continuations), escapables at head/mid/tail across length
 * boundaries, embedded NUL mid-1KB, 10KB clean and dirty, and 40,000 seeded
 * fuzz strings. Fixed mt_srand seed: every run tests the identical corpus.
 *
 * Self-check (connects with the same DB_* env vars as the test suite):
 *
 *     php .github/scripts/escape-corpus.php
 */

// The 7 characters mysqlnd escapes under backslash mode (ext/mysqlnd/mysqlnd_charset.c).
// str_replace applies pairs sequentially, so the backslash pair must come FIRST:
// escaping existing backslashes before other pairs introduce new ones.
const ZENDB_ESCAPE_FROM = ["\\",   "'",   "\"",   "\n",  "\r",  "\0",  "\x1a"];
const ZENDB_ESCAPE_TO   = ["\\\\", "\\'", "\\\"", "\\n", "\\r", "\\0", "\\Z"];
const ZENDB_ESCAPE_MAP  = ["\\" => "\\\\", "'" => "\\'", "\"" => "\\\"",
                           "\n" => "\\n", "\r" => "\\r", "\0" => "\\0", "\x1a" => "\\Z"];

// Runtime guard probe: contains every escapable, pure ASCII so the invalid-byte
// divergence can never falsely fail it. fast(probe) === real_escape_string(probe)
// proves backslash mode is active and the escape set unchanged.
const ZENDB_ESCAPE_PROBE = "a\\b'c\"d\ne\rf\0g\x1ah";

/**
 * Build the full corpus. Generate once per process and pass into the assert
 * helpers; strings are runtime-built (never interned literals).
 *
 * @return string[]
 */
function escape_corpus(): array
{
    $corpus = [''];

    // All 256 single bytes
    for ($b = 0; $b <= 0xFF; $b++) {
        $corpus[] = chr($b);
    }

    // ALL 65,536 two-byte strings (escapable pairs, partial multibyte, invalid leads)
    for ($a = 0; $a <= 0xFF; $a++) {
        $ca = chr($a);
        for ($b = 0; $b <= 0xFF; $b++) {
            $corpus[] = $ca . chr($b);
        }
    }

    // Each byte at head / mid / tail of clean ASCII (position sensitivity)
    for ($b = 0; $b <= 0xFF; $b++) {
        $corpus[] = "Hello " . chr($b) . " World";
        $corpus[] = chr($b) . " leading";
        $corpus[] = "trailing " . chr($b);
    }

    // All 49 adjacent escapable pairs, plus runs (ordering traps: a backslash
    // produced by one replacement must never be re-escaped by a later pair)
    $esc = ["\\", "'", "\"", "\n", "\r", "\0", "\x1a"];
    foreach ($esc as $x) {
        foreach ($esc as $y) {
            $corpus[] = $x . $y;
            $corpus[] = "a{$x}{$y}b";
        }
        $corpus[] = str_repeat($x, 50);
    }

    // Backslash-sequence traps: literal text that LOOKS like an escape sequence
    // ("a\nb" here is backslash + letter n, not a newline)
    foreach (['n', 'r', '0', 'Z', 'b', 't', '%', '_', 'x'] as $c) {
        $corpus[] = "a\\{$c}b";
        $corpus[] = "\\{$c}";
        $corpus[] = "\\\\{$c}";
    }

    // Valid multibyte UTF-8: 2/3/4-byte, emoji, combining marks, BOM, max code point
    $mb = ["caf\u{E9}", "\u{4E2D}\u{6587}\u{6F22}\u{5B57}", "\u{1F600}\u{1F4A9}", "e\u{0301}",
           "\u{FEFF}", "\u{00A0}", "\u{FFFD}", "\u{10FFFF}", "\u{200B}"];
    foreach ($mb as $s) {
        $corpus[] = $s;
        $corpus[] = "pre $s post";
        $corpus[] = "'{$s}'";          // escapables hugging multibyte
        $corpus[] = "\\{$s}\\";
    }

    // GBK/BIG5 injection shapes (CVE-2006-2753, Shiflett 0xbf27): must round-trip
    // inert under utf8mb4, and must DIVERGE under gbk (the negative-control test)
    $inject = ["\xBF\x27", "\xBF\x5C", "\xBF\x27 OR 1=1 -- ", "\x81\x27", "\xA1\x27",
               "\xBF\x5C\x27", "abc\xBF\x27def"];
    foreach ($inject as $s) {
        $corpus[] = $s;
    }

    // Invalid UTF-8: overlongs, surrogate halves, truncations, stray continuations
    $invalid = [
        "\xC0\xAF", "\xC1\xBF",                 // overlong '/'
        "\xE0\x80\xAF", "\xF0\x80\x80\xAF",     // more overlongs
        "\xED\xA0\x80", "\xED\xBF\xBF",         // surrogate halves
        "\xF4\x90\x80\x80",                     // > U+10FFFF
        "\xC3", "\xE2\x82", "\xF0\x9F\x98",     // truncated sequences (also at end-of-string)
        "\x80", "\xBF", "\x80\x80\x80",         // stray continuations
        "\xFE", "\xFF", "\xFF\xFE\xFD",
        "ok\xC3\x28bad",                        // invalid continuation
    ];
    foreach ($invalid as $s) {
        $corpus[] = $s;
        $corpus[] = "text $s text";
        $corpus[] = "$s'";                      // invalid byte then a quote
    }

    // Escapables at head/mid/tail across length boundaries (catches positional
    // misses in any scan- or chunk-based candidate)
    $clean1k = str_repeat('The quick brown fox jumps over the lazy dog. ', 23);
    foreach ([63, 64, 65, 127, 128, 129, 255, 256, 257] as $n) {
        $clean = substr($clean1k, 0, $n);
        $mid   = intdiv($n, 2);
        $corpus[] = $clean;
        foreach (["'", "\\", "\0", "\x1a"] as $bad) {
            $corpus[] = $bad . substr($clean, 1);
            $corpus[] = substr($clean, 0, $mid) . $bad . substr($clean, $mid + 1);
            $corpus[] = substr($clean, 0, $n - 1) . $bad;
        }
    }

    // Long strings: embedded NUL mid-1KB, 10KB clean, 10KB dirty
    $corpus[] = substr($clean1k, 0, 500) . "\0" . substr($clean1k, 500);
    $corpus[] = str_repeat($clean1k, 10);
    $corpus[] = str_repeat(substr($clean1k, 0, 200) . "' \"quoted\" O'Brien \\ ", 40);

    // Fuzz: 20,000 random-byte strings + 20,000 escapable-biased ASCII.
    // Fixed seed: every run, every platform, tests the identical corpus.
    mt_srand(20260802);
    for ($i = 0; $i < 20000; $i++) {
        $len = mt_rand(0, 64);
        $s   = '';
        for ($j = 0; $j < $len; $j++) {
            $s .= chr(mt_rand(0, 255));
        }
        $corpus[] = $s;
    }
    $escapables = ["\\", "'", "\"", "\n", "\r", "\0", "\x1a"];
    for ($i = 0; $i < 20000; $i++) {
        $len = mt_rand(0, 64);
        $s   = '';
        for ($j = 0; $j < $len; $j++) {
            $s .= mt_rand(0, 6) === 0 ? $escapables[mt_rand(0, 6)] : chr(mt_rand(0x20, 0x7E));
        }
        $corpus[] = $s;
    }

    return $corpus;
}

/**
 * Parse escaped content as MySQL would inside a single-quoted literal under
 * backslash mode, returning the decoded bytes. Returns null when the content
 * could not appear safely inside '...' (a bare single quote would terminate
 * the literal early, a trailing lone backslash would consume the closing quote).
 *
 *     escape_literal_parse("a\\'b")  → "a'b"
 *     escape_literal_parse("a'b")    → null (bare quote = injection)
 *
 * Escape rules per the MySQL string-literal grammar: \0 \b \n \r \t \Z \' \" \\
 * decode to their characters; \% and \_ keep BOTH characters (LIKE escapes);
 * a backslash before any other byte is dropped and the byte kept.
 */
function escape_literal_parse(string $escaped): ?string
{
    $out = '';
    $len = strlen($escaped);
    for ($i = 0; $i < $len; $i++) {
        $c = $escaped[$i];
        if ($c === "'") {
            return null;
        }
        if ($c !== "\\") {
            $out .= $c;
            continue;
        }
        if (++$i >= $len) {
            return null;
        }
        $e    = $escaped[$i];
        $out .= match ($e) {
            '0'      => "\0",
            'b'      => "\x08",
            'n'      => "\n",
            'r'      => "\r",
            't'      => "\t",
            'Z'      => "\x1a",
            '%', '_' => "\\$e",
            default  => $e,   // covers \' \" \\ and unrecognized escapes (backslash dropped)
        };
    }
    return $out;
}

/**
 * Assert an escaper against a live real_escape_string over the whole corpus.
 *
 * Valid-UTF-8 inputs must match byte-for-byte. Invalid-UTF-8/binary inputs must
 * parse back to the original bytes (see escape_literal_parse); whether they were
 * also byte-identical is recorded in invalid_identical / invalid_diverged so a
 * driver behavior change shows up in the numbers without failing the gate.
 *
 * For query-equivalent candidates (bare addslashes, 2-pair) pass
 * $requireByteIdentity = false: every input then uses the parse-back check only.
 *
 * @param callable $escaper fn(string): string
 * @param mysqli   $mysqli  live utf8mb4 connection (the reference escaper)
 * @param string[] $corpus  result of escape_corpus() (pass in to reuse across escapers)
 * @return array{count: int, fail: int, samples: string[], invalid_identical: int, invalid_diverged: int}
 */
function escape_corpus_assert(callable $escaper, mysqli $mysqli, array $corpus, bool $requireByteIdentity = true): array
{
    $fail             = 0;
    $samples          = [];
    $invalidIdentical = 0;
    $invalidDiverged  = 0;

    foreach ($corpus as $s) {
        $want = $mysqli->real_escape_string($s);
        $got  = $escaper($s);

        // preg_match('//u') returns 1 for valid UTF-8, false (not 0) for invalid
        $isValidUtf8 = preg_match('//u', $s) === 1;

        if ($got === $want) {
            $invalidIdentical += (int)!$isValidUtf8;
            continue;
        }

        // Byte mismatch: valid UTF-8 under byte-identity rules is a hard failure
        if ($requireByteIdentity && $isValidUtf8) {
            $fail++;
            if (count($samples) < 5) {
                $samples[] = sprintf('input=%s want=%s got=%s', bin2hex($s), bin2hex($want), bin2hex($got));
            }
            continue;
        }

        // Invalid/binary (or query-equivalent tier): both outputs must parse back
        // to the original bytes with no bare quote
        if (escape_literal_parse($got) === $s && escape_literal_parse($want) === $s) {
            $invalidDiverged++;
            continue;
        }
        $fail++;
        if (count($samples) < 5) {
            $samples[] = sprintf('PARSE input=%s want=%s got=%s', bin2hex($s), bin2hex($want), bin2hex($got));
        }
    }

    return [
        'count'             => count($corpus),
        'fail'              => $fail,
        'samples'           => $samples,
        'invalid_identical' => $invalidIdentical,
        'invalid_diverged'  => $invalidDiverged,
    ];
}

/**
 * The shipping guard, both conditions: connection charset is utf8mb4 AND the
 * probe string escapes identically through the fast path and the C escaper.
 * The gbk finding proves neither check alone suffices: the ASCII probe passes
 * under gbk, and the charset check passes under NO_BASH... sql_mode.
 */
function escape_fast_path_ok(mysqli $mysqli): bool
{
    return $mysqli->character_set_name() === 'utf8mb4'
        && str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, ZENDB_ESCAPE_PROBE) === $mysqli->real_escape_string(ZENDB_ESCAPE_PROBE);
}

/**
 * Which single bytes does the live escaper modify? The whole fast path rests on
 * the answer being exactly {5C 27 22 0A 0D 00 1A}; any deviation means the
 * FROM/TO pairs are wrong for this server/driver and fails the build.
 *
 * @return string[] uppercase hex of each modified byte, e.g. ["00","0A","0D","1A","22","27","5C"]
 */
function escape_set_canary(mysqli $mysqli): array
{
    $modified = [];
    for ($b = 0; $b <= 0xFF; $b++) {
        if ($mysqli->real_escape_string(chr($b)) !== chr($b)) {
            $modified[] = strtoupper(str_pad(dechex($b), 2, '0', STR_PAD_LEFT));
        }
    }
    return $modified;
}

const ZENDB_ESCAPE_CANARY_EXPECTED = ['00', '0A', '0D', '1A', '22', '27', '5C'];

/**
 * Connect with the suite's DB_* env conventions and pin the connection state the
 * fast path requires: utf8mb4 and ZenDB's default sql_mode. Throws on failure -
 * a probe run under the wrong charset or sql_mode must refuse to emit numbers.
 */
function escape_corpus_connect(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
    $mysqli->real_connect(
        getenv('DB_HOSTNAME') ?: '127.0.0.1',
        getenv('DB_USERNAME') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        null,
        (int)(getenv('DB_PORT') ?: 3306),
    );
    $database = getenv('DB_DATABASE') ?: 'phpunit_test_db';
    $mysqli->query("CREATE DATABASE IF NOT EXISTS `$database`");
    $mysqli->select_db($database);
    $mysqli->set_charset('utf8mb4');
    $mysqli->query("SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    if (!escape_fast_path_ok($mysqli)) {
        throw new RuntimeException("Connection state rejects the fast path (charset="
            . $mysqli->character_set_name() . "); refusing to run.");
    }
    return $mysqli;
}

// Standalone self-check: reference vs the 7-pair replacement family.
// Run: php .github/scripts/escape-corpus.php
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $mysqli = escape_corpus_connect();
    $corpus = escape_corpus();

    printf("PHP %s | %s | mysqlnd %s | corpus=%d\n",
        PHP_VERSION, $mysqli->server_info, mysqli_get_client_info(), count($corpus));

    $canary = escape_set_canary($mysqli);
    printf("escape-set canary: [%s] %s\n", implode(' ', $canary),
        $canary === ZENDB_ESCAPE_CANARY_EXPECTED ? 'OK' : 'UNEXPECTED - fast path unsafe here');

    $escapers = [
        'str_replace 7-pair' => static fn(string $s): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s),
        'strtr map'          => static fn(string $s): string => strtr($s, ZENDB_ESCAPE_MAP),
        'addslashes+3tail'   => static fn(string $s): string => str_replace(["\n", "\r", "\x1a"], ["\\n", "\\r", "\\Z"], addslashes($s)),
        'addcslashes5+2tail' => static fn(string $s): string => str_replace(["\0", "\x1a"], ["\\0", "\\Z"], addcslashes($s, "'\"\\\n\r")),
    ];
    $anyFail = $canary !== ZENDB_ESCAPE_CANARY_EXPECTED;
    foreach ($escapers as $name => $fn) {
        $res = escape_corpus_assert($fn, $mysqli, $corpus);
        printf("%-20s fail=%d invalid_identical=%d invalid_diverged=%d\n",
            $name, $res['fail'], $res['invalid_identical'], $res['invalid_diverged']);
        foreach ($res['samples'] as $sample) {
            echo "  $sample\n";
        }
        $anyFail = $anyFail || $res['fail'] > 0;
    }
    exit($anyFail ? 1 : 0);
}
