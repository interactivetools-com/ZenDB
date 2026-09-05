# CI Scripts

PHP scripts run by the workflows in [`../workflows/`](../workflows/). Nothing
here is part of the library. Each script's header docblock has its full usage;
this is the map.

**Test timing** (`tests.yml`) - times the suite across every PHP x database job
and builds a grid for the run-summary page.

- `ci-timing.php` - parse one job's JUnit XML into a timing JSON
- `ci-timing-summary.php` - merge those JSONs into the PHP x database grid

**DB behavior matrix** (`db-behavior-matrix.yml`) - probes every database image
with plain mysqli (not ZenDB, so the library's own fixes can't hide what the
server returns) and merges the results into
[docs/internal/db-behavior-matrix.md](../../docs/internal/db-behavior-matrix.md).

- `db-behavior-probe.php` - probe one server, print markdown (and optional JSON)
- `db-behavior-merge.php` - merge per-server JSONs into one "who differs" report

**Index rules matrix** (`index-rules-matrix.yml`) - fact-checks the prefix rules
CMS Builder uses when it auto-creates an index (TEXT/BLOB get `(768)`, VARCHAR
gets `min(n, 768)`, everything else none) by running CREATE INDEX on every
text, blob, and varchar shape on every database image. Results go in
[docs/internal/index-rules-matrix.md](../../docs/internal/index-rules-matrix.md).

- `index-rules-probe.php` - probe one server, print markdown (and optional JSON)
- `index-rules-merge.php` - merge per-server JSONs into one table per question

**Version speed test** (`version-matrix.yml`) - the previous release versus the
working copy on the pages from
[docs/performance.md](../../docs/performance.md), so a release can say how much
faster it got.

- `version-probe.php` - composer installs the baseline release into a scratch
  directory with its namespaces suffixed "Old", loads both versions in one
  process, and runs their pages interleaved; separate processes drift more than
  the difference being measured. The baseline pulls the SmartArray and
  SmartString that release resolves to, so each side is the whole stack as an
  install gets it. Gated on identical page bytes and identical statements per
  call, so a difference in the table is PHP-side work and never a round trip

**Escape benchmark suite** (`escape-matrix.yml`, `escape-e2e-matrix.yml`,
`escape-zendb-matrix.yml`) -
measures every MySQL escaping candidate and every way values get into queries,
gated by a byte-identity corpus against a live `real_escape_string()` before any
timing. Results land in [escape-results.md](escape-results.md); the research,
suite methodology, and remaining work live in
[docs/internal/fast-mysql-escape.md](../../docs/internal/fast-mysql-escape.md).

- `escape-corpus.php` - corpus builder, equivalence gates, escape-set canary,
  probe self-check; shared with `tests/Escaping/EscapeEquivalenceTest.php`
- `escape-probe.php` - CPU family: escaper candidates, call-dispatch ladder,
  ZenDB-shaped query-compile cells; one JSON per OS x PHP cell
- `escape-e2e-probe.php` - end-to-end family: interpolation vs prepared vs PDO
  vs hex per DB server, round-trip census, reuse crossover, bulk grid
- `escape-zendb-probe.php` - ZenDB vs raw mysqli vs fresh-prepared mysqli
  (`escape-zendb-matrix.yml`) on real-world page scenarios: detail page (1
  row), widget (5), list page (25 and 100), each consumed as HTML output or
  raw arrays, on news-corpus content; whole-query wall time. The only escape
  script that needs composer install
- `escape-merge.php` - merge probe JSONs from either family into one grid
- `escape-results.md` - committed verdict grid from full CI runs (citable source)

**Non-candidates ledger** - techniques enumerated and rejected without timing
cells, so future passes don't re-litigate them: two-string `strtr` (1:1
translation, cannot insert bytes); `sprintf` (transforms placeholders, not
payload bytes); 7-char `addcslashes` (emits octal `\000`/`\032`, which MySQL
parses as NUL + literal digits = corruption; the 5-char list is fine);
separator-joined whole-buffer batch escape (separator collision corrupts data;
array-subject `str_replace` supersedes it); `serialize`/`json_encode` as
escapers (different escape grammar); type-planned escape skipping for
user-supplied values (only server-sourced dump values may skip by column type);
`mysqli::escape_string` (alias of `real_escape_string`, same C function);
`sodium_bin2hex` (constant-time by design, deliberately slower than `bin2hex`).

**Shared code**

- `ci-lib.php` - helpers used by the scripts above (server sort order); `require`-only, no entry point
