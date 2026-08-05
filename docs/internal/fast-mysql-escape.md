# Fast MySQL Escape: str_replace Instead of real_escape_string

Research from 2026-08-02, done in the CMSB repo while optimizing database
backups. Conclusion: under ZenDB's connection conditions, a 7-pair
`str_replace()` (or `strtr()`) produces byte-identical output to
`mysqli::real_escape_string()` and runs ~40% faster when inlined (measured on
PHP 8.1/8.3; PHP 8.4 sped up the native escaper, see the 2026-08 research pass
below). The win only matters in tight bulk loops that escape millions of
values per run (backup dumps, exports); in the normal query path it is
nanoseconds per query. This
page holds the research: what the php-src implementation actually does, the
conditions that make the shortcut safe, and where it could apply in ZenDB.

The CI escape benchmark suite grew out of this research and is the citable
source for current numbers: the committed verdict grid is
[escape-results.md](../../.github/scripts/escape-results.md), and the suite
map is in [.github/scripts/README.md](../../.github/scripts/README.md).

CMS Builder's backup ships this pattern in production: its dump loop
(`backupDatabase_dumpData()` in `lib/database_functions.php`) inlines the
7-pair `str_replace` with a once-per-table probe self-check and a
`real_escape_string` fallback.

## The Core Finding

`real_escape_string()` escapes exactly 7 characters, and its charset machinery
is a no-op under utf8mb4, so plain PHP string replacement is equivalent:

```php
const MYSQL_ESCAPE_FROM = ["\\",   "'",    "\"",    "\n",   "\r",   "\0",   "\x1a"];  // backslash FIRST
const MYSQL_ESCAPE_TO   = ["\\\\", "\\'",  "\\\"",  "\\n",  "\\r",  "\\0",  "\\Z"];

str_replace(MYSQL_ESCAPE_FROM, MYSQL_ESCAPE_TO, $value)   // == $mysqli->real_escape_string($value)
```

Byte-identity was verified by sha256 on a 458MB dump of a 1M-row table
(mixed content: text with quotes and apostrophes, datetimes, NULLs, decimals),
and by md5 across several function variants. Equivalence holds only under the
two conditions in "Correctness Conditions" below, both of which ZenDB already
guarantees at connect time.

`strtr($value, $map)` is equally correct and within noise of the same speed;
it is single-pass and has no pair-ordering requirement. `str_replace` runs the
7 pairs sequentially, so the backslash pair must come first (escaping existing
backslashes before other pairs introduce new ones).

## Benchmarks

All numbers: MariaDB 11.3.2 on Windows, 1M-row InnoDB table `bench_rows`
(reproduction SQL at the bottom), PHP CLI without opcache. "Linux" = WSL2
PHP 8.1 NTS (representative of production Linux hosting). "Windows" = WAMP
PHP 8.3.6 ZTS. All ratios predate PHP 8.4's mysqlnd escape optimization
(finding 1 in the research pass below); the CI suite re-measures per PHP
version.

### Function Shootout (Inlined, No Closures; 300k Rows, 1.5M String Values, Assembly-Only Loop)

| function                 | secs  | vs real_escape | note                          |
|--------------------------|-------|----------------|-------------------------------|
| real_escape_string       | 0.838 | 100%           | baseline                      |
| str_replace (7 pairs)    | 0.497 | 59%            | winner, byte-identical        |
| strtr (map)              | 0.517 | 62%            | tied within noise             |
| addcslashes              | 0.471 | 56%            | DISQUALIFIED, see below       |
| preg_replace_callback    | 1.135 | 136%           | slower, out                   |

addcslashes emits octal for NUL and Ctrl-Z (`\000`, `\032`). MySQL parses
`\000` as NUL followed by literal "00" - data corruption. Do not use.

### Call Style Matters as Much as the Function (300k Rows)

| calling style           | secs  | vs real_escape |
|-------------------------|-------|----------------|
| real_escape (baseline)  | 0.676 | 100%           |
| strtr inline            | 0.382 | 57%            |
| strtr via named function| 0.574 | 85%            |
| strtr via static method | 0.573 | 85%            |
| strtr via closure       | 0.662 | 98% (gain gone)|

At millions of calls, wrapping the replacement in any callable gives back a
third to nearly all of the win (~190ns/call CLI without opcache; smaller with
opcache but same ordering). API design implication: a `DB::escapeFast()`
method is the slowest correct option; an inlined constant-pair replacement at
the hot call site is the fastest.

### Scan-First Tier (SmartString Style) Does NOT Transfer

SmartString's htmlencode wins by scanning first (`strpbrk`/`strspn`) and
skipping the encoder for clean values (see SmartString `docs/performance.md`).
That works because `htmlspecialchars()` is expensive. Here the replacement
encoder (`str_replace`) is already cheap, and MySQL's escape set includes the
apostrophe, which English text contains constantly, so long values routinely
fail the scan and the scan becomes pure overhead on exactly the values that
dominate runtime. Measured on the 1M-row dump (full pipeline, buffered
writes):

| variant                                  | secs | vs escape-all |
|------------------------------------------|------|---------------|
| real_escape on every string              | 4.46 | 100%          |
| scan, clean -> raw, dirty -> real_escape | 4.76 | 6.7% SLOWER   |
| scan, clean -> raw, dirty -> strtr       | 3.92 | 12% faster    |
| strtr on every string, no scan           | 3.75 | 16% faster    |

(These used a closure dispatch, so absolute gains understate; the ordering is
the point. On apostrophe-light data the scan tier would win, but "always
str_replace" is never worse than baseline and needs no tuning.)

### Platform Note

Windows ZTS PHP (WAMP) has roughly 10x the per-function-call overhead of Linux
NTS. On Windows the whole dump loop is call-overhead-bound and this change
only bought ~6%; on Linux it is ~16-20% of total dump time. Also observed,
unrelated but useful: on MariaDB 11.3/Windows, a full-table scan fetched 7x
faster inside `START TRANSACTION WITH CONSISTENT SNAPSHOT` (1.4s) than in
plain autocommit (9.8s, stable across passes). Not investigated further.

## What php-src Actually Does (mysqlnd Source)

Read from `ext/mysqlnd/mysqlnd_charset.c` (php-src master, 2026-08).
`mysqli::real_escape_string` dispatches to one of two escapers based on the
server status flag `SERVER_STATUS_NO_BACKSLASH_ESCAPES`, which mysqlnd tracks
live (it updates after every statement, including `SET sql_mode`):

**mysqlnd_cset_escape_slashes** (normal mode) - the exact escape set:

```c
case 0:      esc = '0'; break;
case '\n':   esc = 'n'; break;
case '\r':   esc = 'r'; break;
case '\\': case '\'': case '"': esc = *escapestr; break;
case '\032': esc = 'Z'; break;
```

Everything else is copied through. Multibyte handling: for any byte >= the
charset's lowest multibyte lead byte, it asks the charset driver whether a
valid multibyte sequence starts there; valid sequences are copied through
untouched. This machinery exists for charsets like GBK/SJIS/Big5 where byte 2
of a valid character can be 0x5C (backslash). Under utf8mb4 it is a no-op for
correctness: all 7 escapable chars are ASCII and every byte of a UTF-8
multibyte sequence is >= 0x80.

**One divergence found:** when mysqlnd hits an INVALID multibyte lead byte
(possible in binary/BLOB data, e.g. encrypted MEDIUMBLOB columns), it emits
backslash + that raw byte. Plain str_replace passes the byte through without
the backslash. Restored data is identical either way - MySQL strips the
backslash before any unrecognized escape character, so `\<byte>` parses to
`<byte>` - but output BYTES can differ on binary columns. Consequence for
testing: "diff the two outputs" is only a valid equivalence test on
binary-free data; for binary data the test is restore-and-compare.

**mysqlnd_cset_escape_quotes** (NO_BACKSLASH_ESCAPES mode) - doubles quotes
and nothing else. It cannot escape NUL, newlines, or Ctrl-Z at all. Any code
that needs those escaped (e.g. one-statement-per-line dump formats) is already
broken in this mode regardless of this optimization.

## Correctness Conditions (Both Already Guaranteed by ZenDB)

1. **Connection charset must be utf8mb4** (or any charset whose multibyte
   sequences never contain bytes < 0x80). ZenDB hard-sets utf8mb4 at connect,
   `src/Connection.php` (~line 194: `set_charset('utf8mb4')` when not already
   set). There is no config option to change it; someone would have to edit
   ZenDB source. Runtime confirmation: `$mysqli->character_set_name()`.
2. **sql_mode must not contain NO_BACKSLASH_ESCAPES.** ZenDB sets sql_mode
   explicitly at connect (`src/Connection.php` ~line 214, default list in
   `src/ConnectionInternals.php`: STRICT_ALL_TABLES,NO_ZERO_IN_DATE,
   ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION), overriding server
   defaults. The mode can only appear if user code runs SET sql_mode later.

**Recommended runtime guard - probe self-check** (covers both conditions plus
any future mysqlnd change, costs microseconds, run once per connection or per
bulk operation):

```php
$probe      = "a\\b'c\"d\ne\rf\0g\x1ah";
$fastEscape = str_replace(MYSQL_ESCAPE_FROM, MYSQL_ESCAPE_TO, $probe)
              === $mysqli->real_escape_string($probe);
// $fastEscape ? str_replace path : real_escape_string fallback
```

This is the runtime version of SmartString's "keep the shortcut honest"
approach (SmartString proves htmlencode equivalence by exhaustive sweep in CI;
see its docs/performance.md "How We Know It's Safe"). The same probe and a
byte-identity corpus now gate every timing run in the CI escape suite
(`escape-corpus.php`, shared with `tests/Escaping/EscapeEquivalenceTest.php`).

## Where This Could Apply in ZenDB

Current `real_escape_string` call sites:

- `src/ConnectionInternals.php:789` - string values compiled into queries
  (`is_string($value) => "'" . escape . "'"`). The hot path, but per-query
  volume is a handful of values, not millions - the win per query is
  nanoseconds. Worth it only if it costs no complexity.
- `src/ConnectionInternals.php:662` - single-value escape helper.
- `src/TableInfo.php` (8 sites) - table names in information_schema lookups.
  Cold paths, short strings; not worth touching.

The case where this genuinely pays is bulk row export: CMSB's backup dump
escapes millions of values per run, and its dump loop inlines the str_replace
directly with the probe check (per benchmarks above, a shared function/method
costs ~15% of the win; that is acceptable for ZenDB API use, just not for a
hot dump loop). If ZenDB grows a dump/export feature, this belongs in it.

## Test Strategy for Adoption

1. Unit test: all 256 single-byte strings, plus multibyte UTF-8 samples
   (2/3/4-byte chars, emoji), plus strings mixing escapables and multibyte,
   through both `str_replace` and a live `real_escape_string`; assert
   identical output. (Invalid-UTF-8 inputs will differ per the divergence
   above - either exclude them or assert MySQL-equivalence instead of
   byte-equality.)
2. Probe self-check in the shipping code path (above).
3. For dump-style output: full backup -> restore -> compare data (CHECKSUM
   TABLE or ordered row hash), since file bytes legitimately differ on binary
   columns.

## Reproducing the Benchmarks

Test table (any scratch DB; ~350MB text, takes ~1 min to insert):

```sql
CREATE TABLE bench_rows (
  num INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL, content TEXT NOT NULL, created DATETIME NOT NULL,
  flag TINYINT NOT NULL, category VARCHAR(50) NULL, price DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET SESSION max_recursive_iterations = 1010;  -- MySQL: cte_max_recursion_depth
INSERT INTO bench_rows (title, content, created, flag, category, price)
WITH RECURSIVE seq AS (SELECT 1 AS n UNION ALL SELECT n+1 FROM seq WHERE n < 1000)
SELECT CONCAT('Widget ', a.n, '-', b.n, ' "deluxe" O''Brien edition'),
       REPEAT(CONCAT('Lorem ipsum d''olor sit amet consectetur ', b.n, ' adipiscing elit sed do eiusmod tempor. '), 4),
       NOW() - INTERVAL a.n HOUR, a.n % 7,
       IF(b.n % 10 = 0, NULL, CONCAT('category-', a.n % 50)),
       ROUND(a.n * 1.37 + b.n * 0.01, 2)
FROM seq a JOIN seq b;
```

Benchmark harness shape (connection options matter - ZenDB sets both):

```php
$mysqli = mysqli_init();
$mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);   // ints/floats arrive native, skip escaping entirely
$mysqli->real_connect('127.0.0.1', 'root', '', 'scratch_db');
$mysqli->set_charset('utf8mb4');
// fetch with MYSQLI_USE_RESULT for streaming, or fetch_all() then time assembly only;
// inline each escape variant in its own loop (no closures - they erase the gain);
// best-of-5 passes; md5/sha256 the output to verify byte-identity between variants
```

The ad-hoc scripts from the original session were throwaway; the permanent CI
suite (`.github/scripts/escape-*.php`) has since re-measured this ground
across the full OS x PHP x server matrix with committed scripts and results
(links at the top of this page).

## Benchmark-Suite Research Pass (2026-08)

The CI suite was planned from a second research pass (7 agents, 140 findings,
deduplicated). Scope decisions: compare against raw mysqli and raw PDO only,
no framework installs (raw PHP is their ceiling; two harness configs labeled
"Laravel-shaped" and "WP-shaped" give ecosystem reference rows); no
NO_BACKSLASH_ESCAPES speed benchmarking (correctness gates for the mode
remain, and ZenDB would reject it at connect via the probe); utf8mb4 only for
the shipped fast path, other charsets as correctness controls only.

Findings that survive as reference material:

1. **PHP 8.4 already optimized mysqlnd escaping** (commits 1571eed, a8c9270,
   not backported to 8.1-8.3): removed indirect charset-plugin calls and
   skipped multibyte validity checks for bytes that can't start a sequence,
   roughly 2x on their microbench. Output bytes unchanged, no SIMD. The ~40%
   str_replace win above was measured against the slow 8.1/8.3 escaper, so
   every ratio needs per-version re-measurement.
2. **Headline candidate: addslashes + 3-pair tail.** addslashes() escapes
   backslash, both quotes, and NUL (as `\0`, byte-identical to MySQL; the
   octal corruption is addcslashes-only), has an SSE4.2 fast path plus an ARM
   NEON path, and returns the original string with zero allocation when
   nothing matches. `addslashes($s)` then `str_replace(["\n","\r","\x1a"],
   ["\\n","\\r","\\Z"], ...)` is byte-identical to real_escape_string and
   predicted fastest on the pools that dominate real data.
3. **addcslashes resurrected in corrected form.** The disqualified call used a
   7-char list; a 5-char list `'\"\\\n\r` emits valid `\n`/`\r` (octal only
   affects NUL and Ctrl-Z), so addcslashes-5 + 2-pair tail is byte-identical.
   Prior data says take it seriously: addcslashes measured 0.471s vs
   str_replace 0.497s on the 1.5M-value corpus before disqualification, so a
   one-pass scalar loop can beat 7 SIMD-assisted passes on mixed data.
4. **The invalid-lead-byte divergence did not reproduce** on PHP 8.1.34 +
   MariaDB 11.3.2 (live-tested: zero divergences over exhaustive 1-2 byte
   inputs). The reading above came from php-src master. It is
   version-dependent, prime suspect the 8.4 rewrite. Gates must accept
   byte-identity OR the modeled divergence and record which, per cell.
5. **PHP 8.6-dev is actively refactoring mysqlnd charset code** ("Remove
   charsets plugin", merged 2026-04). Behavior is meant to be unchanged; a
   nightly-PHP canary cell catches it if not.
6. **mysqli is mysqlnd-only since PHP 8.2** (php-src PR #7889). Only PHP 8.1
   could theoretically pair mysqli with libmysqlclient (where
   NO_BACKSLASH_ESCAPES makes real_escape_string error instead of switching
   escapers). One probe assertion on 8.1, dead by construction after.
7. **PDO::quote and PDO emulated prepares call the same mysqlnd escaper**
   (verified in ext/pdo_mysql/mysql_driver.c). One corpus identity check makes
   PDO::quote a same-C-path baseline; beating it with userland PHP is the
   strongest claim available.
8. **GBK injection hazard live-verified**: under gbk, real_escape_string
   neutralizes `\xBF\x27` while str_replace does not, and the ASCII probe
   still passes. So the shipping guard needs BOTH the sql_mode probe AND
   `character_set_name() === 'utf8mb4'`; neither alone suffices.
9. **Client-side interpolation is mainstream prior art**: go-sql-driver ships
   it as a perf feature (and hard-rejects GBK-class charsets); PyMySQL escapes
   via a pure-Python translate table for the whole Django-on-MySQL world.
10. **PHP 8.6 adds `mysqli::quote_string()`** (RFC passed 15-2, implemented in
    master 2026-04-05): escapes exactly like real_escape_string and wraps in
    single quotes in one C call, mirroring PDO::quote. Two consequences: it is
    the fairest C baseline for the full quoted-literal expression (escape +
    quote concat in one internal call, no PHP-level concatenation), and it is
    documented safe under NO_BACKSLASH_ESCAPES where real_escape_string plus
    caller-added quotes is not. Benchmark it on 8.6 against real_escape+concat,
    PDO::quote, and the inline str_replace winners; gate its output as
    byte-identical to `"'" . real_escape_string($s) . "'"` under ZenDB's
    sql_mode.

## Suite Methodology Rules

The probe scripts embody these; this is the why. Violating them invalidates
results.

- **Materializing sink for headline numbers.** str_replace/strtr/addslashes
  return the original string on no-match (refcount bump); real_escape_string
  always allocates. A strlen() sink measures "refcount bump vs full copy" and
  overstates zero-copy candidates. Bare-function cells may keep strlen for
  scan-cost isolation, but every headline ratio comes from the full
  quote-wrap-and-concat expression (the real `ConnectionInternals.php:789`
  shape). Label the sink per cell in results.
- **Self-tie calibration cell** (A and B are the same callable) published in
  every grid; a non-TIE verdict widens that platform's dead band.
- **Live reference, never hardcoded**: every gate compares against a live
  `$mysqli->real_escape_string()` on the actual connection.
- **Bulk cells hold durability constant**: same rows-per-transaction across
  channels; record innodb_flush_log_at_trx_commit, sync_binlog, log_bin in
  the header. Commit-batch-size is its own axis on one channel.
- **MariaDB cells**: assert query_cache_type=OFF (prepared statements bypass
  the cache, interpolated repeats would hit it); start containers with
  --performance-schema=ON or tag server-side timings SCOPE_DEGRADED.
- **Vary a literal per iteration** in repeated-statement benchmarks so no two
  interpolated statements are byte-identical.
- **Round-trip census before trusting E2E numbers**: SHOW SESSION STATUS
  Com_* deltas per channel, then re-run under induced latency (netem 1ms) and
  confirm wall time scales as RTT x counted round trips.
- Environment self-report header per probe: php, os, arch, ZTS/NTS, opcache,
  JIT, xdebug warning, mysqlnd version, server version, charset, sql_mode,
  timer granularity. Wrong charset/sql_mode is a hard error, not a warning.

## Status and Remaining Work

Shipped and built:

- CMSB shipped buffered writes in its dump loop (1MB chunks, 5-10%).
- CMSB shipped the inline str_replace swap in `backupDatabase_dumpData()`:
  pairs inlined in the row loop, probe self-check once per table,
  `real_escape_string` fallback. Multi-row INSERTs (~6% more, much faster
  restores) were designed and validated against both CMSB restore parsers but
  deferred.
- The CI suite core is built (map in
  [.github/scripts/README.md](../../.github/scripts/README.md)): corpus and
  correctness gates shared with `tests/Escaping/EscapeEquivalenceTest.php`,
  the CPU probe, the end-to-end probe, the ZenDB-vs-raw probe, the merge
  script, and the `escape-matrix.yml`, `escape-e2e-matrix.yml`, and
  `escape-zendb-matrix.yml` workflows.

Remaining:

- **Full CI dispatch.**
  [escape-results.md](../../.github/scripts/escape-results.md) is still a stub
  holding local direction-check numbers only. Dispatch `escape-matrix.yml` and
  `escape-e2e-matrix.yml`, paste the merged grids with run IDs, and write the
  Adopted / Rejected / Reference verdict sections. Early partial dispatches
  (2026-08-02, runs 30787047934 / 30787049337 / 30787243403) had every gate
  green (including the 95-job tests.yml re-run) and confirmed the version
  story: on clean 1KB pools the str_replace-vs-real_escape ratio collapsed
  26x (PHP 8.1) → 3.8x (8.4) → 2.4x (8.6); on dense pools real_escape_string
  is 2x FASTER than the 7-pair on 8.4+; addslashes + 3-pair tail flips to the
  winner on 8.4+ (1.28-1.35x over str_replace, all pools); binary pools stay
  ~8-10x on every version; practical 8.6 wins are guard-on-mix 1.15x,
  query-compile 1.19x, bulk 1.24x (vs 3.0x/2.7x/3.5x on 8.1). PHP 8.6's
  `quote_string()` was identity-verified over the full corpus. E2E rankings
  were identical on all 7 servers: interpolation beats fresh-prepared
  1.6-1.8x, statement-reuse crossover N=10-100, and for bulk LOAD DATA beats
  multi-row beats prepared. Any adoption decision is therefore
  version-dependent: the 7-pair fast path pays on 8.1-8.3 and on binary data,
  while 8.4+ narrows or reverses it on text.
- **Exhaustive sweep workflow** (never built): all strings up to 4 bytes
  against a live handle; lengths 1-3 run in seconds, length 4 sharded by first
  byte (~minutes per cell with xargs -P), manual dispatch only. Valid inputs
  assert byte-equality; invalid inputs assert the divergence bound (finding 4).
- **Differential fuzz** (never built): ~1M fuzzed values per cell as 1000-row
  multi-row INSERTs into a low-durability scratch table, parse and round-trip
  asserted; mutation-test the gate by seeding a deliberate escaper bug.
- **Wild tier** (run once, document, likely close): raw-socket COM_QUERY
  pipelining probe (~200-line pure-PHP client, bounds what round-trip hiding
  is worth); MYSQLI_ASYNC + mysqli_poll parallel bulk load at 1/2/4/8
  connections; protocol compression on bulk paths; send_long_data for
  over-packet values; FROM_BASE64 channel (predicted strictly worse than hex
  on localhost); the consistent-snapshot 7x observation from the Platform Note
  above (interleaved A/B, isolation-level and STORE/USE_RESULT axes, confirm
  or debunk).
- **ZenDB adoption proposal** (chat first): connect-time probe plus charset
  assertion setting a `$fastEscapeOk` flag; NO_BACKSLASH_ESCAPES rejection at
  connect with an error message naming both sources (sql_mode and charset);
  inline guarded fast escape at `ConnectionInternals.php:789` in shape A
  (`$fastEscapeOk ? str_replace(FROM, TO, $v) : real_escape_string($v)`
  inline; local ladder runs predict inline beats a private method beats a
  stored closure, retaining ~91% of the inline win); possibly an array-batch
  escape for a future export API; a type-confusion test (no non-string zval
  reaches the fast path; Stringable cast happens before the escape decision);
  a cross-run drift detector diffing fresh grids against the committed
  results.
- **A public `docs/performance.md`** (the end deliverable), following
  SmartString's pattern: headline multiplier with platform variants,
  one-command local reproduction, How It Works, a generated numbers table,
  How We Know It's Safe, The Fine Print.
