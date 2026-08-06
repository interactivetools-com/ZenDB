# Escape Benchmark Results

Committed, citable results of the escape benchmark suite. First full run
2026-08-05: `escape-matrix.yml` run 30964178143 (CPU family, all 30 cells:
5 OS x PHP 8.1-8.6, JIT off, scale 1.0; the 8.6 cells ran 8.6.0-dev nightly),
`escape-e2e-matrix.yml` run 30964179764 (5-server family), and
`escape-zendb-matrix.yml` run 30968096535 (ZenDB-vs-raw family, PHP 8.1 and
8.4 against MySQL 8.0). Every escaper passed its correctness gate on every
cell in every family, and all three grids are below in full.

Ratios are B-vs-A measured interleaved in one process; >1.00x means the
candidate is faster. The selftie rows calibrate the noise band: ties within
+/-3% everywhere except macOS, where darwin-arm 8.5 mismeasured its own
selftie at 0.74x - treat macOS deltas under ~10% (and darwin-arm 8.5 cells
entirely) as noise.

Regenerate:

    gh workflow run escape-matrix.yml
    gh workflow run escape-e2e-matrix.yml
    gh workflow run escape-zendb-matrix.yml

## Adopted

- **Inline 7-pair str_replace with probe guard, for bulk dump loops**
  (shipped in CMSB's `backupDatabase_dumpData()`). Realistic mixed data wins
  on every cell: `esc-mix` 1.10-3.53x, `bulk-assembly-500` 1.11-3.50x,
  `query-compile-20` 1.10-2.87x. The win is version-shaped: PHP 8.1-8.3
  cells run 1.8-3.5x, 8.4+ cells 1.1-1.7x (the 8.4 mysqlnd rewrite closed
  most of the gap). The guarded shape A ternary keeps ~85-95% of the
  no-guard inline speed (`zen-guard-vs-inline` 0.76-1.16x) and beats both a
  guarded private method and a stored closure (`zen-guard-vs-method`,
  `zen-guard-vs-closure`, both ~0.9x for the alternatives).
- **Skip escaping for native ints and floats** (ZenDB's existing type
  branch, confirmed): `ref-int-cast` 2.21-4.52x for the native branch over
  escaping stringified numbers, and an explicit `(string)` cast ties
  implicit coercion exactly (`ref-int-coerce` 1.00x).

## Rejected

- **Converting ZenDB's per-query escape site** (`ConnectionInternals.php:789`).
  At whole-query scale the escaper choice is invisible: `rt-fast-vs-real`
  0.96-1.03x on every server. The isolated quoted-literal expression does
  win (`zen-guard-vs-real` 0.98-3.10x) but per-query string counts are
  single digits; not worth any complexity in the hot path.
- **addcslashes-5 + 2-pair tail**: loses to addslashes + tail on every cell
  (`alt-addcsl-prose1k` 0.29-0.64x).
- **strtr map**: loses the sparse/clean pools badly
  (`alt-strtr-sparse1k` 0.23-0.90x); its dirty-pool wins don't transfer to
  real data.
- **Quote-only premask, strcspn clean gate, strcspn chunked rewriter**:
  catastrophic on PHP 8.1-8.3 (`alt-gate-clean1k` and `alt-premask-clean1k`
  down to 0.04x), mildly useful only on short strings on 8.4+; not worth a
  version-dependent code path.
- **Any callable wrapper around the escaper**: the dispatch ladder never
  reaches 1.00x (`call-named` 0.74-0.99x down to `call-magic` 0.40-0.70x).
  Inline it or keep real_escape_string.
- **Hex literals for text values**: `ref-hex-prose1k` 0.33-1.08x. Binary
  only (see Reference).

## Measured for Reference

- **addslashes + 3-pair tail** - the strongest byte-identical successor
  candidate: beats the 7-pair on escape-dense data everywhere
  (`alt-addsl-dirty1k` 1.66-5.76x) and on most text pools from PHP 8.2 up
  (`alt-addsl-text10k` 1.03-2.07x, `alt-addsl-clean1k` 0.68-1.79x, losses
  confined to two x64 8.1 cells). If the fast path is ever revisited, start
  here.
- **Query-equivalent tier** (restores identical data, never dump-safe):
  bare addslashes `qe-addsl-prose1k` 1.40-3.61x, 2-pair minimal
  `qe-2pair-prose1k` 1.52-2.33x over the byte-identical 7-pair - the
  ceiling available by relaxing byte-identity.
- **C-level quote baselines**: PDO::quote beats real_escape + PHP concat on
  short values (`ref-pdoquote-short` 1.12-1.41x, ties at 1KB). PHP 8.6's
  `mysqli::quote_string()` behaves the same (`ref-quotestring-short`
  1.11-1.27x) and its output identity held over the full corpus - but the
  quoted str_replace still beats it 1.56-3.65x (`alt-quotestring-vs-fast`).
- **Hex literals for binary values**: `ref-hex-bin1k` 0.97-2.66x - the one
  pool where bin2hex wins as client CPU.
- **Array-subject str_replace batch**: 1.06-1.41x over a per-value loop
  (`bulk-array-subject`); the library-API shape for any future export
  feature.
- **Floors** (close the family): preg_replace_callback 0.23-0.69x, one-pass
  PCRE class 0.30-0.84x, userland byte loop 0.01-0.03x.
- **Constant placement**: class-const operand arrays are free vs call-site
  literals (`const-classconst` ~1.0x); static-property arrays cost a little
  (`const-staticprop` 0.73-1.00x).
- **End-to-end channel rankings, identical on all 5 servers**: one-shot
  interpolation beats fresh-prepared ~1.7x (`rt-interp-vs-prepared`
  0.58-0.61x for prepared) and PDO emulated ties interpolation
  (`rt-interp-vs-pdo-emulated` 0.96-1.01x); PDO native costs a round trip
  like mysqli prepared (`rt-pdo-emulated-vs-native` 0.56-0.61x); reusing a
  prepared statement beats re-preparing 1.80-1.87x
  (`rt-prepared-fresh-vs-reused`).
- **ZenDB vs hand-written mysqli** (`zvr-*`, loopback, where round trips are
  cheapest and library cost is most visible): a raw point query with
  hand-written escaping runs 1.2x a `DB::select()` doing identical work
  (`zvr-select-string` 0.80-0.83x, `zvr-query-raw-sql` 0.85-0.86x) and 2x a
  `DB::selectOne()` by id (`zvr-select-int` 0.41-0.51x); fetching 1000 rows
  costs about 2x raw `fetch_all` (`zvr-fetch-1000` 0.51-0.61x), holding
  steady when both sides HTML-encode their output (`zvr-fetch-touch-1000`
  0.51-0.59x). Local decomposition puts the absolute cost at tens of
  microseconds per query plus roughly a microsecond per row for
  SmartArray/SmartString wrapping - what parameterized compilation,
  validation, and XSS-safe output cost, and the ratio shrinks as real
  network latency replaces loopback.

## CPU Family Grid (run 30964178143)


Correctness: every escaper passes its gate on every cell.

| test | darwin-arm 8.1 | darwin-arm 8.2 | darwin-arm 8.3 | darwin-arm 8.4 | darwin-arm 8.5 | darwin-arm 8.6 | darwin-x64 8.1 | darwin-x64 8.2 | darwin-x64 8.3 | darwin-x64 8.4 | darwin-x64 8.5 | darwin-x64 8.6 | linux-arm 8.1 | linux-arm 8.2 | linux-arm 8.3 | linux-arm 8.4 | linux-arm 8.5 | linux-arm 8.6 | linux-x64 8.1 | linux-x64 8.2 | linux-x64 8.3 | linux-x64 8.4 | linux-x64 8.5 | linux-x64 8.6 | windows-x64 8.1 | windows-x64 8.2 | windows-x64 8.3 | windows-x64 8.4 | windows-x64 8.5 | windows-x64 8.6 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| selftie-short | 0.98x | 1.00x | 0.97x | 0.97x | 0.74x (slower) | 0.99x | 0.92x (slower) | 1.01x | 0.98x | 1.00x | 1.00x | 1.01x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 0.99x | 1.00x | 1.00x | 1.01x | 1.00x | 1.00x |
| selftie-1kb | 0.95x (slower) | 1.00x | 0.91x (slower) | 0.97x | **1.07x** | 1.01x | 0.93x (slower) | 1.02x | 1.00x | 1.00x | 0.99x | 0.99x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x |
| real-oo-vs-proc | 0.94x (slower) | 1.04x | **1.16x** | 0.99x | 1.03x | 1.01x | 0.97x | 0.94x (slower) | 0.99x | 0.86x (slower) | 0.97x | 0.95x | 0.97x | 0.97x | 0.97x | 0.97x | 0.96x | 0.97x | 0.99x | 0.97x | 0.96x | 0.96x | 0.99x | 1.01x | 0.95x | 0.97x | 1.01x | 0.98x | 0.96x | 0.96x |
| esc-clean10 | **1.13x** | **1.05x** | 1.03x | 0.91x (slower) | 0.81x (slower) | 0.88x (slower) | 0.89x (slower) | 1.03x | **1.09x** | 0.92x (slower) | 0.93x (slower) | 0.97x | **1.10x** | **1.08x** | **1.08x** | 0.90x (slower) | 0.87x (slower) | 0.88x (slower) | 1.04x | **1.07x** | **1.11x** | 0.86x (slower) | 0.87x (slower) | 0.87x (slower) | **1.16x** | **1.08x** | **1.12x** | 1.02x | **1.05x** | **1.12x** |
| esc-clean32 | **1.55x** | **1.56x** | **1.26x** | **1.18x** | 1.04x | **1.39x** | **1.42x** | **1.79x** | **1.79x** | **1.31x** | **1.20x** | **1.22x** | **1.59x** | **1.55x** | **1.54x** | 1.04x | **1.05x** | **1.06x** | **1.59x** | **2.06x** | **2.24x** | **1.20x** | **1.21x** | **1.09x** | **1.35x** | **2.20x** | **1.97x** | **1.30x** | **1.31x** | **1.39x** |
| esc-prose100 | **2.80x** | **2.75x** | **2.34x** | **1.57x** | **1.75x** | **1.49x** | **1.94x** | **2.29x** | **2.28x** | **1.46x** | **1.39x** | **1.42x** | **2.62x** | **2.60x** | **2.58x** | **1.25x** | **1.12x** | **1.12x** | **3.03x** | **2.92x** | **3.04x** | **1.49x** | **1.47x** | **1.31x** | **2.37x** | **3.82x** | **3.31x** | **1.25x** | **1.56x** | **1.56x** |
| esc-text10k | **9.69x** | **9.54x** | **8.81x** | **4.36x** | **3.55x** | **3.70x** | **14.76x** | **4.86x** | **4.86x** | **2.63x** | **4.09x** | **5.35x** | **9.99x** | **9.96x** | **10.15x** | **2.86x** | **1.75x** | **1.72x** | **8.59x** | **10.83x** | **12.65x** | **2.78x** | **2.80x** | **1.96x** | **16.38x** | **10.64x** | **7.82x** | **2.31x** | **3.02x** | **2.64x** |
| esc-datetime | **1.40x** | **1.37x** | **1.32x** | **1.05x** | 0.99x | **1.17x** | **1.16x** | **1.33x** | **1.39x** | **1.07x** | **1.05x** | **1.09x** | **1.39x** | **1.35x** | **1.35x** | 0.96x | 0.95x | 0.96x | **1.31x** | **1.48x** | **1.67x** | 1.00x | 1.00x | 1.00x | **1.22x** | **1.52x** | **1.53x** | **1.20x** | **1.15x** | **1.24x** |
| esc-clean1k | **11.67x** | **11.64x** | **12.58x** | **5.57x** | **3.95x** | **4.05x** | **15.78x** | **5.56x** | **5.57x** | **3.00x** | **4.42x** | **4.82x** | **10.90x** | **10.71x** | **10.73x** | **3.24x** | **1.88x** | **1.88x** | **20.74x** | **9.34x** | **12.01x** | **4.11x** | **4.11x** | **2.55x** | **13.81x** | **16.02x** | **11.86x** | **4.25x** | **4.12x** | **4.01x** |
| esc-prose1k | **7.73x** | **8.19x** | **8.32x** | **3.91x** | **3.01x** | **3.73x** | **9.06x** | **4.64x** | **4.63x** | **2.47x** | **2.99x** | **3.66x** | **7.72x** | **7.99x** | **8.05x** | **2.47x** | **1.62x** | **1.62x** | **13.93x** | **7.45x** | **9.74x** | **3.58x** | **3.54x** | **2.32x** | **9.82x** | **11.66x** | **10.27x** | **3.20x** | **3.80x** | **3.28x** |
| esc-dirty1k | 0.45x (slower) | 0.45x (slower) | 0.46x (slower) | 0.27x (slower) | 0.35x (slower) | 0.32x (slower) | 0.45x (slower) | 0.55x (slower) | 0.53x (slower) | 0.33x (slower) | 0.24x (slower) | 0.28x (slower) | 0.84x (slower) | 0.84x (slower) | 0.86x (slower) | 0.39x (slower) | 0.59x (slower) | 0.58x (slower) | 0.92x (slower) | 1.02x | 1.03x | 0.52x (slower) | 0.54x (slower) | 0.54x (slower) | 0.74x (slower) | **1.35x** | **1.26x** | 0.39x (slower) | 0.52x (slower) | 0.48x (slower) |
| esc-accented1k | **11.25x** | **11.05x** | **11.31x** | **4.76x** | **4.93x** | **3.77x** | **16.00x** | **6.24x** | **5.56x** | **3.13x** | **4.48x** | **4.66x** | **10.74x** | **10.61x** | **10.51x** | **3.78x** | **1.80x** | **1.80x** | **17.71x** | **7.74x** | **10.87x** | **4.00x** | **4.01x** | **2.61x** | **14.71x** | **15.23x** | **12.13x** | **4.11x** | **4.07x** | **4.13x** |
| esc-cjk1k | **5.60x** | **5.76x** | **5.89x** | **5.55x** | **4.59x** | **3.19x** | **5.17x** | **2.12x** | **2.10x** | **2.86x** | **3.05x** | **3.56x** | **3.90x** | **4.16x** | **3.84x** | **4.34x** | **2.06x** | **2.06x** | **6.95x** | **2.82x** | **3.49x** | **3.15x** | **3.40x** | **2.45x** | **4.50x** | **3.53x** | **2.74x** | **2.71x** | **2.74x** | **2.91x** |
| esc-bin1k | **6.51x** | **6.57x** | **6.60x** | **6.48x** | **7.57x** | **5.45x** | **5.45x** | **6.19x** | **5.71x** | **4.67x** | **4.05x** | **4.53x** | **6.82x** | **6.84x** | **6.88x** | **5.77x** | **4.58x** | **4.59x** | **14.69x** | **10.84x** | **11.63x** | **11.73x** | **11.40x** | **9.62x** | **6.46x** | **7.53x** | **10.15x** | **8.14x** | **5.41x** | **5.45x** |
| esc-mix | **2.72x** | **2.73x** | **2.68x** | **1.65x** | **1.32x** | **1.39x** | **1.94x** | **2.24x** | **2.23x** | **1.44x** | **1.29x** | **1.44x** | **2.53x** | **2.49x** | **2.49x** | **1.28x** | **1.11x** | **1.10x** | **3.19x** | **2.68x** | **2.89x** | **1.45x** | **1.60x** | **1.22x** | **2.99x** | **3.53x** | **3.14x** | **1.33x** | **1.65x** | **1.58x** |
| esc-big1m | **6.53x** | **6.50x** | **6.63x** | **3.19x** | **2.75x** | **2.48x** | **3.68x** | **2.83x** | **2.66x** | **1.83x** | **1.93x** | **2.31x** | **4.17x** | **4.16x** | **4.24x** | **1.67x** | **1.36x** | **1.39x** | **10.81x** | **4.83x** | **6.41x** | **3.31x** | **3.34x** | **2.22x** | **4.90x** | **6.46x** | **5.17x** | **1.67x** | **2.05x** | **1.86x** |
| alt-addsl-clean1k | **1.17x** | **1.17x** | **1.16x** | **1.18x** | **1.64x** | **1.48x** | 0.80x (slower) | **1.38x** | **1.38x** | **1.55x** | **1.05x** | 1.00x | **1.06x** | **1.06x** | **1.07x** | 1.04x | **1.46x** | **1.46x** | 0.68x (slower) | **1.26x** | **1.08x** | **1.23x** | **1.22x** | **1.42x** | 1.05x | **1.13x** | **1.27x** | **1.09x** | **1.12x** | **1.09x** |
| alt-addsl-prose1k | **1.41x** | **1.34x** | **1.39x** | **1.38x** | **1.42x** | **1.36x** | **1.21x** | **1.41x** | **1.46x** | **1.53x** | **1.23x** | **1.13x** | **1.20x** | **1.15x** | **1.15x** | **1.16x** | **1.48x** | **1.48x** | 0.95x | **1.24x** | **1.11x** | **1.23x** | **1.26x** | **1.38x** | **1.23x** | **1.34x** | **1.39x** | **1.24x** | **1.29x** | **1.27x** |
| alt-addsl-dirty1k | **4.41x** | **4.14x** | **4.18x** | **4.13x** | **3.31x** | **2.49x** | **5.50x** | **4.20x** | **4.30x** | **4.49x** | **4.48x** | **4.01x** | **2.71x** | **2.64x** | **2.59x** | **2.63x** | **1.66x** | **1.67x** | **5.76x** | **2.22x** | **2.65x** | **4.35x** | **4.17x** | **4.08x** | **3.51x** | **2.69x** | **5.40x** | **5.47x** | **2.73x** | **2.73x** |
| alt-addsl-cjk1k | **1.13x** | **1.22x** | **1.15x** | **1.19x** | **1.35x** | **1.49x** | 0.77x (slower) | **1.36x** | **1.33x** | **1.40x** | **1.08x** | 0.99x | 1.04x | 1.05x | 1.05x | 1.04x | **1.45x** | **1.45x** | 0.75x (slower) | **1.29x** | **1.10x** | **1.25x** | **1.24x** | **1.41x** | 1.03x | **1.17x** | **1.27x** | **1.13x** | **1.12x** | **1.09x** |
| alt-addsl-short | 0.98x | 0.95x (slower) | 0.94x (slower) | 0.97x | **1.07x** | **1.14x** | 0.96x | 0.76x (slower) | 0.79x (slower) | 0.86x (slower) | 0.89x (slower) | 0.86x (slower) | 1.01x | 1.02x | 1.01x | 1.01x | 1.04x | 1.04x | 0.89x (slower) | **1.06x** | 1.02x | 1.04x | 0.98x | **1.06x** | 0.89x (slower) | 0.92x (slower) | 0.93x (slower) | 0.92x (slower) | 0.87x (slower) | 0.82x (slower) |
| alt-addsl-prose100 | **1.11x** | **1.06x** | **1.06x** | 1.02x | 1.04x | 0.98x | 1.04x | 0.88x (slower) | 0.90x (slower) | 0.99x | 0.93x (slower) | 0.91x (slower) | 0.99x | 1.00x | 0.99x | 0.97x | **1.08x** | **1.08x** | 1.00x | **1.09x** | **1.10x** | 1.03x | 1.02x | **1.09x** | **1.13x** | 0.96x | 1.01x | 0.89x (slower) | 0.92x (slower) | 0.87x (slower) |
| alt-addsl-text10k | **1.87x** | **1.90x** | **1.90x** | **1.83x** | **2.07x** | **1.80x** | **1.52x** | **1.83x** | **1.86x** | **1.83x** | **1.63x** | **1.45x** | **1.46x** | **1.44x** | **1.45x** | **1.44x** | **1.77x** | **1.77x** | 1.03x | **1.32x** | **1.21x** | **1.23x** | **1.20x** | **1.32x** | **1.26x** | **1.37x** | **1.39x** | **1.31x** | **1.38x** | **1.33x** |
| alt-addcsl-prose1k | 0.31x (slower) | 0.31x (slower) | 0.32x (slower) | 0.33x (slower) | 0.43x (slower) | 0.29x (slower) | 0.49x (slower) | 0.62x (slower) | 0.63x (slower) | 0.64x (slower) | 0.55x (slower) | 0.56x (slower) | 0.46x (slower) | 0.45x (slower) | 0.45x (slower) | 0.45x (slower) | 0.58x (slower) | 0.56x (slower) | 0.32x (slower) | 0.47x (slower) | 0.47x (slower) | 0.41x (slower) | 0.41x (slower) | 0.45x (slower) | 0.55x (slower) | 0.50x (slower) | 0.52x (slower) | 0.50x (slower) | 0.41x (slower) | 0.53x (slower) |
| alt-addcsl-dirty1k | 0.93x (slower) | 1.01x | 1.01x | 0.98x | 0.90x (slower) | 0.96x | 0.85x (slower) | 0.86x (slower) | 0.87x (slower) | 0.92x (slower) | 0.99x | **1.05x** | 0.96x | 0.97x | 1.00x | 0.97x | 0.97x | 1.00x | 0.52x (slower) | 0.90x (slower) | 0.76x (slower) | 0.96x | 1.00x | 0.97x | 0.95x (slower) | 0.93x (slower) | **1.15x** | 0.84x (slower) | 0.87x (slower) | 0.92x (slower) |
| alt-strtr-sparse1k | 0.44x (slower) | 0.42x (slower) | 0.42x (slower) | 0.41x (slower) | 0.55x (slower) | 0.56x (slower) | 0.34x (slower) | 0.89x (slower) | 0.90x (slower) | 0.90x (slower) | 0.44x (slower) | 0.41x (slower) | 0.47x (slower) | 0.45x (slower) | 0.45x (slower) | 0.45x (slower) | 0.87x (slower) | 0.87x (slower) | 0.23x (slower) | 0.69x (slower) | 0.53x (slower) | 0.45x (slower) | 0.45x (slower) | 0.58x (slower) | 0.49x (slower) | 0.50x (slower) | 0.55x (slower) | 0.43x (slower) | 0.49x (slower) | 0.48x (slower) |
| alt-strtr-dirty1k | **1.75x** | **1.77x** | **1.74x** | **1.76x** | **1.20x** | **1.25x** | **1.13x** | 0.93x (slower) | 0.94x (slower) | 0.93x (slower) | 0.96x | 0.97x | **1.13x** | **1.09x** | **1.08x** | **1.09x** | 0.76x (slower) | 0.75x (slower) | 0.83x (slower) | 0.91x (slower) | 0.89x (slower) | 0.69x (slower) | 0.68x (slower) | 0.70x (slower) | **1.44x** | **1.07x** | **1.06x** | 1.01x | **1.05x** | **1.07x** |
| alt-strtr-short | **1.20x** | **1.12x** | **1.06x** | **1.10x** | **1.09x** | **1.09x** | **1.25x** | 0.89x (slower) | 0.90x (slower) | 0.91x (slower) | 1.00x | 0.91x (slower) | **1.16x** | **1.11x** | **1.09x** | **1.09x** | **1.11x** | **1.11x** | 1.02x | **1.07x** | **1.06x** | **1.07x** | **1.05x** | 1.04x | **1.12x** | 1.05x | **1.09x** | 0.97x | 0.94x (slower) | 0.86x (slower) |
| alt-premask-prose1k | 0.32x (slower) | 0.31x (slower) | 0.31x (slower) | 0.75x (slower) | 0.81x (slower) | 0.82x (slower) | 0.33x (slower) | 0.46x (slower) | 0.46x (slower) | 0.86x (slower) | 0.81x (slower) | 0.76x (slower) | 0.35x (slower) | 0.35x (slower) | 0.34x (slower) | 0.76x (slower) | 0.85x (slower) | 0.85x (slower) | 0.27x (slower) | 0.41x (slower) | 0.32x (slower) | 0.80x (slower) | 0.81x (slower) | 0.84x (slower) | 0.33x (slower) | 0.40x (slower) | 0.40x (slower) | 0.82x (slower) | 0.82x (slower) | 0.81x (slower) |
| alt-premask-clean1k | 0.07x (slower) | 0.08x (slower) | 0.08x (slower) | 0.57x (slower) | 0.92x (slower) | 0.90x (slower) | 0.07x (slower) | 0.19x (slower) | 0.19x (slower) | **1.28x** | 0.89x (slower) | 0.68x (slower) | 0.10x (slower) | 0.10x (slower) | 0.10x (slower) | 0.60x (slower) | **1.12x** | **1.12x** | 0.04x (slower) | 0.14x (slower) | 0.09x (slower) | 0.81x (slower) | 0.82x (slower) | **1.05x** | 0.09x (slower) | 0.12x (slower) | 0.14x (slower) | 0.80x (slower) | 0.88x (slower) | 0.86x (slower) |
| alt-premask-dirty1k | 1.00x | 0.99x | 1.00x | 0.99x | 0.85x (slower) | 0.96x | 1.02x | 0.98x | 1.00x | 1.00x | 0.99x | 0.98x | 0.99x | 0.99x | 0.99x | 0.99x | 0.99x | 0.99x | 0.99x | 1.00x | 0.98x | 0.99x | 0.99x | 0.99x | 0.98x | 0.99x | 1.00x | 0.98x | 0.98x | 1.00x |
| alt-gate-clean1k | 0.07x (slower) | 0.07x (slower) | 0.07x (slower) | 0.54x (slower) | 1.04x | 1.00x | 0.07x (slower) | 0.18x (slower) | 0.18x (slower) | **1.74x** | 1.02x | 0.76x (slower) | 0.09x (slower) | 0.09x (slower) | 0.09x (slower) | 0.66x (slower) | **1.34x** | **1.34x** | 0.04x (slower) | 0.08x (slower) | 0.09x (slower) | 0.92x (slower) | 0.93x (slower) | **1.24x** | 0.08x (slower) | 0.11x (slower) | 0.12x (slower) | 0.90x (slower) | 1.00x | 0.99x |
| alt-gate-short | 1.03x | **1.08x** | **1.05x** | **1.50x** | **1.51x** | **1.69x** | **1.34x** | **1.09x** | **1.06x** | **1.25x** | **1.06x** | 1.02x | **1.08x** | **1.11x** | **1.10x** | **1.39x** | **1.44x** | **1.44x** | 0.93x (slower) | 0.96x | **1.14x** | **1.40x** | **1.38x** | **1.36x** | 0.96x | **1.10x** | 1.01x | **1.19x** | **1.16x** | 1.04x |
| alt-chunk-sparse1k | 0.08x (slower) | 0.08x (slower) | 0.08x (slower) | 0.56x (slower) | 0.83x (slower) | 0.87x (slower) | 0.10x (slower) | 0.18x (slower) | 0.18x (slower) | **1.15x** | 0.74x (slower) | 0.59x (slower) | 0.10x (slower) | 0.09x (slower) | 0.09x (slower) | 0.56x (slower) | **1.10x** | **1.11x** | 0.04x (slower) | 0.08x (slower) | 0.10x (slower) | 0.69x (slower) | 0.72x (slower) | 0.94x (slower) | 0.09x (slower) | 0.12x (slower) | 0.12x (slower) | 0.68x (slower) | 0.78x (slower) | 0.78x (slower) |
| alt-chunk-big1m | 0.12x (slower) | 0.12x (slower) | 0.12x (slower) | 0.59x (slower) | 0.75x (slower) | 0.72x (slower) | 0.27x (slower) | 0.35x (slower) | 0.32x (slower) | 0.89x (slower) | 0.78x (slower) | 0.69x (slower) | 0.21x (slower) | 0.21x (slower) | 0.21x (slower) | 0.74x (slower) | 1.00x | 0.99x | 0.07x (slower) | 0.14x (slower) | 0.17x (slower) | 0.61x (slower) | 0.60x (slower) | 0.76x (slower) | 0.20x (slower) | 0.24x (slower) | 0.24x (slower) | 0.71x (slower) | 0.77x (slower) | 0.77x (slower) |
| qe-addsl-prose1k | **2.56x** | **2.38x** | **2.42x** | **2.27x** | **3.24x** | **2.80x** | **2.02x** | **3.61x** | **3.57x** | **3.59x** | **2.03x** | **1.76x** | **1.84x** | **1.80x** | **1.80x** | **1.80x** | **3.19x** | **3.20x** | **1.40x** | **2.46x** | **2.05x** | **2.22x** | **2.33x** | **3.00x** | **2.05x** | **2.44x** | **2.77x** | **2.04x** | **2.24x** | **2.22x** |
| qe-2pair-prose1k | **2.10x** | **2.04x** | **2.01x** | **2.09x** | **2.22x** | **2.43x** | **1.74x** | **2.19x** | **2.14x** | **2.23x** | **1.81x** | **1.59x** | **1.85x** | **1.83x** | **1.83x** | **1.83x** | **2.32x** | **2.33x** | **1.52x** | **2.27x** | **2.27x** | **2.04x** | **2.03x** | **2.28x** | **1.94x** | **1.84x** | **1.97x** | **1.81x** | **1.83x** | **1.76x** |
| qe-2pair-dirty1k | **1.42x** | **1.42x** | **1.42x** | **1.44x** | **1.46x** | **1.62x** | **1.32x** | **1.39x** | **1.43x** | **1.56x** | **1.38x** | **1.42x** | **1.43x** | **1.43x** | **1.44x** | **1.42x** | **1.59x** | **1.59x** | **1.48x** | **1.50x** | **1.52x** | **1.56x** | **1.62x** | **1.66x** | **1.45x** | **1.49x** | **1.72x** | **1.78x** | **1.50x** | **1.49x** |
| floor-pregcb-prose1k | 0.39x (slower) | 0.39x (slower) | 0.38x (slower) | 0.38x (slower) | 0.53x (slower) | 0.51x (slower) | 0.37x (slower) | 0.69x (slower) | 0.67x (slower) | 0.64x (slower) | 0.48x (slower) | 0.42x (slower) | 0.33x (slower) | 0.32x (slower) | 0.32x (slower) | 0.32x (slower) | 0.57x (slower) | 0.57x (slower) | 0.23x (slower) | 0.36x (slower) | 0.33x (slower) | 0.40x (slower) | 0.40x (slower) | 0.50x (slower) | 0.35x (slower) | 0.41x (slower) | 0.52x (slower) | 0.44x (slower) | 0.46x (slower) | 0.42x (slower) |
| floor-pregclass-prose1k | 0.43x (slower) | 0.44x (slower) | 0.43x (slower) | 0.42x (slower) | 0.62x (slower) | 0.58x (slower) | 0.39x (slower) | 0.80x (slower) | 0.81x (slower) | 0.84x (slower) | 0.66x (slower) | 0.58x (slower) | 0.42x (slower) | 0.41x (slower) | 0.41x (slower) | 0.41x (slower) | 0.74x (slower) | 0.74x (slower) | 0.30x (slower) | 0.43x (slower) | 0.39x (slower) | 0.50x (slower) | 0.51x (slower) | 0.65x (slower) | 0.44x (slower) | 0.65x (slower) | 0.68x (slower) | 0.56x (slower) | 0.63x (slower) | 0.63x (slower) |
| floor-loop-prose1k | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.03x (slower) | 0.03x (slower) | 0.03x (slower) | 0.01x (slower) | 0.01x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.03x (slower) | 0.03x (slower) | 0.01x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.02x (slower) | 0.01x (slower) | 0.01x (slower) | 0.02x (slower) | 0.01x (slower) | 0.01x (slower) | 0.01x (slower) |
| ref-hex-bin1k | **2.47x** | **2.31x** | **2.35x** | **2.28x** | **2.58x** | **2.37x** | **2.24x** | **2.14x** | **2.22x** | **2.15x** | **2.65x** | **1.98x** | **1.63x** | **1.61x** | **1.64x** | **1.62x** | **2.01x** | **1.84x** | 0.97x | **1.92x** | **1.68x** | 1.03x | **1.06x** | **1.24x** | **2.66x** | **2.45x** | **1.68x** | **1.51x** | **2.47x** | **2.45x** |
| ref-hex-prose1k | 0.69x (slower) | 0.66x (slower) | 0.66x (slower) | 0.65x (slower) | 0.89x (slower) | 0.89x (slower) | 0.52x (slower) | 0.84x (slower) | 0.98x | 1.00x | 1.00x | 0.69x (slower) | 0.62x (slower) | 0.61x (slower) | 0.60x (slower) | 0.60x (slower) | **1.08x** | 0.98x | 0.33x (slower) | 0.81x (slower) | 0.71x (slower) | 0.56x (slower) | 0.57x (slower) | 0.72x (slower) | 0.81x (slower) | 0.87x (slower) | 0.90x (slower) | 0.75x (slower) | 0.87x (slower) | 0.86x (slower) |
| ref-int-cast | **2.95x** | **3.12x** | **3.12x** | **2.93x** | **2.92x** | **2.95x** | **4.52x** | **3.73x** | **3.19x** | **3.53x** | **2.21x** | **2.22x** | **2.63x** | **2.64x** | **2.65x** | **2.65x** | **2.63x** | **2.63x** | **2.53x** | **3.23x** | **3.42x** | **2.63x** | **2.61x** | **2.61x** | **2.92x** | **2.50x** | **2.35x** | **2.37x** | **2.38x** | **2.41x** |
| ref-int-coerce | 1.00x | 0.99x | 1.00x | 0.98x | 0.95x | 1.03x | 1.01x | 0.91x (slower) | 1.00x | 1.00x | 0.99x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x | 1.01x | 1.00x | 1.00x | 1.00x | 1.00x | 1.00x |
| call-named | 0.89x (slower) | 0.93x (slower) | 0.85x (slower) | 0.88x (slower) | 0.99x | 0.99x | 0.79x (slower) | 0.76x (slower) | 0.74x (slower) | 0.78x (slower) | 0.84x (slower) | 0.82x (slower) | 0.92x (slower) | 0.92x (slower) | 0.90x (slower) | 0.89x (slower) | 0.90x (slower) | 0.90x (slower) | 0.89x (slower) | 0.91x (slower) | 0.92x (slower) | 0.90x (slower) | 0.90x (slower) | 0.90x (slower) | 0.88x (slower) | 0.88x (slower) | 0.86x (slower) | 0.85x (slower) | 0.85x (slower) | 0.86x (slower) |
| call-static | 0.88x (slower) | 0.87x (slower) | 0.86x (slower) | 0.84x (slower) | 0.86x (slower) | 0.86x (slower) | 0.77x (slower) | 0.84x (slower) | 0.72x (slower) | 0.78x (slower) | 0.84x (slower) | 0.81x (slower) | 0.90x (slower) | 0.91x (slower) | 0.89x (slower) | 0.87x (slower) | 0.89x (slower) | 0.88x (slower) | 0.87x (slower) | 0.88x (slower) | 0.89x (slower) | 0.89x (slower) | 0.87x (slower) | 0.88x (slower) | 0.88x (slower) | 0.86x (slower) | 0.84x (slower) | 0.85x (slower) | 0.83x (slower) | 0.84x (slower) |
| call-instance | 0.82x (slower) | 0.86x (slower) | 0.81x (slower) | 0.80x (slower) | 0.81x (slower) | 0.85x (slower) | 0.77x (slower) | 0.77x (slower) | 0.73x (slower) | 0.77x (slower) | 0.81x (slower) | 0.80x (slower) | 0.84x (slower) | 0.85x (slower) | 0.85x (slower) | 0.83x (slower) | 0.84x (slower) | 0.84x (slower) | 0.82x (slower) | 0.84x (slower) | 0.87x (slower) | 0.81x (slower) | 0.83x (slower) | 0.82x (slower) | 0.80x (slower) | 0.83x (slower) | 0.79x (slower) | 0.82x (slower) | 0.80x (slower) | 0.80x (slower) |
| call-closure | 0.82x (slower) | 0.81x (slower) | 0.80x (slower) | 0.79x (slower) | 0.78x (slower) | 0.86x (slower) | 0.72x (slower) | 0.73x (slower) | 0.71x (slower) | 0.68x (slower) | 0.79x (slower) | 0.78x (slower) | 0.82x (slower) | 0.83x (slower) | 0.83x (slower) | 0.81x (slower) | 0.83x (slower) | 0.82x (slower) | 0.79x (slower) | 0.81x (slower) | 0.82x (slower) | 0.80x (slower) | 0.84x (slower) | 0.81x (slower) | 0.79x (slower) | 0.81x (slower) | 0.78x (slower) | 0.79x (slower) | 0.80x (slower) | 0.78x (slower) |
| call-fcc | 0.83x (slower) | 0.83x (slower) | 0.83x (slower) | 0.81x (slower) | 0.85x (slower) | 0.89x (slower) | 0.72x (slower) | 0.68x (slower) | 0.72x (slower) | 0.63x (slower) | 0.80x (slower) | 0.77x (slower) | 0.82x (slower) | 0.83x (slower) | 0.83x (slower) | 0.81x (slower) | 0.83x (slower) | 0.82x (slower) | 0.78x (slower) | 0.81x (slower) | 0.82x (slower) | 0.81x (slower) | 0.81x (slower) | 0.81x (slower) | 0.78x (slower) | 0.82x (slower) | 0.78x (slower) | 0.79x (slower) | 0.79x (slower) | 0.78x (slower) |
| call-propfn | 0.80x (slower) | 0.79x (slower) | 0.77x (slower) | 0.76x (slower) | 0.76x (slower) | 0.76x (slower) | 0.78x (slower) | 0.65x (slower) | 0.66x (slower) | 0.71x (slower) | 0.77x (slower) | 0.73x (slower) | 0.80x (slower) | 0.81x (slower) | 0.81x (slower) | 0.79x (slower) | 0.81x (slower) | 0.80x (slower) | 0.76x (slower) | 0.78x (slower) | 0.81x (slower) | 0.77x (slower) | 0.79x (slower) | 0.78x (slower) | 0.75x (slower) | 0.78x (slower) | 0.76x (slower) | 0.76x (slower) | 0.76x (slower) | 0.75x (slower) |
| call-varname | 0.75x (slower) | 0.77x (slower) | 0.77x (slower) | 0.70x (slower) | 0.75x (slower) | 0.76x (slower) | 0.69x (slower) | 0.66x (slower) | 0.63x (slower) | 0.65x (slower) | 0.77x (slower) | 0.74x (slower) | 0.79x (slower) | 0.80x (slower) | 0.79x (slower) | 0.77x (slower) | 0.79x (slower) | 0.79x (slower) | 0.76x (slower) | 0.78x (slower) | 0.78x (slower) | 0.80x (slower) | 0.81x (slower) | 0.80x (slower) | 0.74x (slower) | 0.76x (slower) | 0.76x (slower) | 0.79x (slower) | 0.76x (slower) | 0.77x (slower) |
| call-magic | 0.69x (slower) | 0.68x (slower) | 0.66x (slower) | 0.65x (slower) | 0.58x (slower) | 0.70x (slower) | 0.55x (slower) | 0.52x (slower) | 0.40x (slower) | 0.56x (slower) | 0.65x (slower) | 0.62x (slower) | 0.64x (slower) | 0.65x (slower) | 0.65x (slower) | 0.64x (slower) | 0.66x (slower) | 0.65x (slower) | 0.63x (slower) | 0.64x (slower) | 0.66x (slower) | 0.64x (slower) | 0.67x (slower) | 0.64x (slower) | 0.59x (slower) | 0.65x (slower) | 0.64x (slower) | 0.66x (slower) | 0.57x (slower) | 0.56x (slower) |
| const-classconst | 0.98x | 1.02x | **1.06x** | 1.03x | **1.05x** | 1.02x | **1.10x** | 0.98x | 0.96x | **1.73x** | **1.05x** | 1.01x | 1.02x | 1.01x | 1.01x | 1.03x | 1.02x | 1.03x | 1.02x | 1.02x | 1.02x | 1.01x | **1.09x** | 1.03x | 1.03x | 1.04x | 1.03x | **1.05x** | **1.08x** | **1.06x** |
| const-staticprop | 0.93x (slower) | 0.93x (slower) | 0.95x (slower) | 0.98x | 0.96x | 0.96x | 0.89x (slower) | 0.87x (slower) | 0.92x (slower) | 0.73x (slower) | 1.01x | 0.99x | 0.87x (slower) | 0.88x (slower) | 0.88x (slower) | 0.94x (slower) | 0.96x | 0.96x | 0.88x (slower) | 0.89x (slower) | 0.89x (slower) | 0.97x | 0.99x | 0.97x | 0.91x (slower) | 0.92x (slower) | 0.94x (slower) | 0.98x | 0.98x | 1.00x |
| zen-guard-vs-real | **2.53x** | **2.59x** | **2.55x** | **1.48x** | **1.44x** | **1.41x** | **1.89x** | **2.13x** | **2.06x** | 0.98x | **1.13x** | **1.28x** | **2.32x** | **2.27x** | **2.29x** | **1.13x** | 1.04x | 1.04x | **2.69x** | **2.46x** | **2.63x** | **1.32x** | **1.34x** | **1.15x** | **2.55x** | **3.10x** | **2.81x** | **1.19x** | **1.52x** | **1.43x** |
| zen-guard-vs-inline | 0.92x (slower) | 0.92x (slower) | 0.94x (slower) | 0.88x (slower) | 0.97x | 0.94x (slower) | 0.89x (slower) | 0.88x (slower) | **1.16x** | 0.76x (slower) | 0.87x (slower) | 0.89x (slower) | 0.91x (slower) | 0.91x (slower) | 0.92x (slower) | 0.92x (slower) | 0.93x (slower) | 0.95x | 0.87x (slower) | 0.92x (slower) | 0.92x (slower) | 0.89x (slower) | 0.93x (slower) | 0.93x (slower) | 0.85x (slower) | 0.90x (slower) | 0.89x (slower) | 0.89x (slower) | 0.93x (slower) | 0.92x (slower) |
| zen-guard-vs-method | 0.93x (slower) | 0.94x (slower) | 0.96x | 0.92x (slower) | 0.95x | 0.94x (slower) | 0.92x (slower) | 0.90x (slower) | 0.81x (slower) | **1.08x** | 0.91x (slower) | 0.89x (slower) | 0.95x (slower) | 0.94x (slower) | 0.94x (slower) | 0.94x (slower) | 0.95x | 0.95x (slower) | 0.96x | 0.95x | 0.96x | 0.93x (slower) | 0.93x (slower) | 0.96x | 0.95x | 0.94x (slower) | 0.94x (slower) | 0.94x (slower) | 0.92x (slower) | 0.91x (slower) |
| zen-guard-vs-closure | 0.87x (slower) | 0.92x (slower) | 0.93x (slower) | 0.92x (slower) | 0.90x (slower) | 0.92x (slower) | 0.90x (slower) | 0.92x (slower) | 0.95x | 0.94x (slower) | 0.90x (slower) | 0.88x (slower) | 0.92x (slower) | 0.92x (slower) | 0.92x (slower) | 0.92x (slower) | 0.93x (slower) | 0.93x (slower) | 0.92x (slower) | 0.92x (slower) | 0.93x (slower) | 0.91x (slower) | 0.90x (slower) | 0.91x (slower) | 0.95x | 0.93x (slower) | 0.92x (slower) | 0.91x (slower) | 0.90x (slower) | 0.91x (slower) |
| query-compile-5 | **2.14x** | **2.18x** | **2.21x** | **1.38x** | **1.19x** | **1.39x** | **1.88x** | **1.97x** | **1.93x** | **1.26x** | **1.20x** | **1.31x** | **2.17x** | **2.14x** | **2.14x** | **1.18x** | **1.10x** | **1.10x** | **2.51x** | **2.26x** | **2.49x** | **1.35x** | **1.37x** | **1.20x** | **2.35x** | **2.85x** | **2.65x** | **1.23x** | **1.43x** | **1.38x** |
| query-compile-20 | **2.18x** | **2.16x** | **2.17x** | **1.44x** | **1.65x** | **1.38x** | **1.77x** | **1.84x** | **1.84x** | **1.37x** | **1.20x** | **1.32x** | **2.15x** | **2.12x** | **2.12x** | **1.17x** | **1.10x** | **1.10x** | **2.49x** | **2.17x** | **2.42x** | **1.34x** | **1.33x** | **1.20x** | **2.35x** | **2.87x** | **2.65x** | **1.22x** | **1.41x** | **1.37x** |
| bulk-assembly-500 | **2.47x** | **2.54x** | **2.52x** | **1.59x** | **1.41x** | **1.45x** | **2.02x** | **2.33x** | **2.25x** | **1.37x** | **1.29x** | **1.47x** | **2.46x** | **2.42x** | **2.43x** | **1.24x** | **1.11x** | **1.12x** | **3.05x** | **2.58x** | **2.79x** | **1.43x** | **1.43x** | **1.23x** | **2.80x** | **3.50x** | **3.38x** | **1.30x** | **1.59x** | **1.55x** |
| bulk-array-subject | **1.25x** | **1.22x** | **1.22x** | **1.17x** | **1.10x** | **1.06x** | **1.23x** | **1.26x** | **1.14x** | **1.26x** | **1.27x** | **1.36x** | **1.31x** | **1.31x** | **1.30x** | **1.30x** | **1.25x** | **1.24x** | **1.27x** | **1.20x** | **1.21x** | **1.18x** | **1.19x** | **1.17x** | **1.38x** | **1.41x** | **1.31x** | **1.20x** | **1.24x** | **1.21x** |
| ref-pdoquote-short | **1.22x** | **1.35x** | **1.26x** | **1.25x** | **1.38x** | **1.29x** | **1.26x** | **1.27x** | **1.31x** | **1.41x** | **1.36x** | **1.36x** | **1.24x** | **1.24x** | **1.28x** | **1.35x** | **1.40x** | **1.39x** | **1.17x** | **1.31x** | **1.37x** | **1.31x** | **1.36x** | **1.36x** | **1.19x** | **1.12x** | **1.22x** | **1.32x** | **1.28x** | **1.29x** |
| ref-pdoquote-prose1k | 0.99x | 1.01x | 1.03x | 1.02x | 1.03x | 0.99x | **1.08x** | 1.03x | **1.10x** | 1.01x | 1.04x | 1.00x | 1.01x | 1.01x | 1.01x | 1.04x | 1.04x | 1.04x | 1.01x | 1.01x | 1.00x | 1.03x | 1.03x | 1.03x | 1.01x | 1.03x | 1.00x | 1.03x | 1.02x | 1.03x |
| ref-quotestring-short | - | - | - | - | - | **1.11x** | - | - | - | - | - | **1.27x** | - | - | - | - | - | **1.19x** | - | - | - | - | - | **1.26x** | - | - | - | - | - | **1.25x** |
| ref-quotestring-prose1k | - | - | - | - | - | **1.06x** | - | - | - | - | - | 1.04x | - | - | - | - | - | 1.03x | - | - | - | - | - | 1.03x | - | - | - | - | - | 1.02x |
| alt-quotestring-vs-fast | - | - | - | - | - | **3.19x** | - | - | - | - | - | **3.65x** | - | - | - | - | - | **1.56x** | - | - | - | - | - | **2.25x** | - | - | - | - | - | **3.20x** |

<details><summary>Test legend (A vs B, sink)</summary>

- **selftie-short**: quoted str_replace vs quoted str_replace (same) [quoted sink]
- **selftie-1kb**: quoted str_replace vs quoted str_replace (same) [quoted sink]
- **real-oo-vs-proc**: OO real_escape_string vs procedural mysqli_real_escape_string [quoted sink]
- **esc-clean10**: real_escape_string vs inline str_replace [quoted sink]
- **esc-clean32**: real_escape_string vs inline str_replace [quoted sink]
- **esc-prose100**: real_escape_string vs inline str_replace [quoted sink]
- **esc-text10k**: real_escape_string vs inline str_replace [quoted sink]
- **esc-datetime**: real_escape_string vs inline str_replace [quoted sink]
- **esc-clean1k**: real_escape_string vs inline str_replace [quoted sink]
- **esc-prose1k**: real_escape_string vs inline str_replace [quoted sink]
- **esc-dirty1k**: real_escape_string vs inline str_replace [quoted sink]
- **esc-accented1k**: real_escape_string vs inline str_replace [quoted sink]
- **esc-cjk1k**: real_escape_string vs inline str_replace [quoted sink]
- **esc-bin1k**: real_escape_string vs inline str_replace [quoted sink]
- **esc-mix**: real_escape_string vs inline str_replace [quoted sink]
- **esc-big1m**: real_escape_string vs inline str_replace [quoted sink]
- **alt-addsl-clean1k**: str_replace vs addslashes + 3-pair tail [quoted sink]
- **alt-addsl-prose1k**: str_replace vs addslashes + 3-pair tail [quoted sink]
- **alt-addsl-dirty1k**: str_replace vs addslashes + 3-pair tail [quoted sink]
- **alt-addsl-cjk1k**: str_replace vs addslashes + 3-pair tail [quoted sink]
- **alt-addsl-short**: str_replace vs addslashes + 3-pair tail [quoted sink]
- **alt-addsl-prose100**: str_replace vs addslashes + 3-pair tail [quoted sink]
- **alt-addsl-text10k**: str_replace vs addslashes + 3-pair tail [quoted sink]
- **alt-addcsl-prose1k**: addslashes + 3-pair tail vs addcslashes-5 + 2-pair tail [quoted sink]
- **alt-addcsl-dirty1k**: addslashes + 3-pair tail vs addcslashes-5 + 2-pair tail [quoted sink]
- **alt-strtr-sparse1k**: str_replace vs strtr map [quoted sink]
- **alt-strtr-dirty1k**: str_replace vs strtr map [quoted sink]
- **alt-strtr-short**: str_replace vs strtr map [quoted sink]
- **alt-premask-prose1k**: str_replace vs quote-only premask [quoted sink]
- **alt-premask-clean1k**: str_replace vs quote-only premask [quoted sink]
- **alt-premask-dirty1k**: str_replace vs quote-only premask [quoted sink]
- **alt-gate-clean1k**: str_replace vs strcspn clean gate [quoted sink]
- **alt-gate-short**: str_replace vs strcspn clean gate [quoted sink]
- **alt-chunk-sparse1k**: str_replace vs strcspn chunked rewriter [quoted sink]
- **alt-chunk-big1m**: str_replace vs strcspn chunked rewriter [quoted sink]
- **qe-addsl-prose1k**: str_replace (byte-identical) vs bare addslashes (query-equivalent) [quoted sink]
- **qe-2pair-prose1k**: str_replace (byte-identical) vs 2-pair minimal (query-equivalent) [quoted sink]
- **qe-2pair-dirty1k**: str_replace (byte-identical) vs 2-pair minimal (query-equivalent) [quoted sink]
- **floor-pregcb-prose1k**: str_replace vs preg_replace_callback [quoted sink]
- **floor-pregclass-prose1k**: str_replace vs one-pass PCRE class (query-equivalent) [quoted sink]
- **floor-loop-prose1k**: str_replace vs userland byte loop [quoted sink]
- **ref-hex-bin1k**: quoted str_replace literal vs 0x hex literal (bin2hex) [expr sink]
- **ref-hex-prose1k**: quoted str_replace literal vs 0x hex literal (bin2hex) [expr sink]
- **ref-int-cast**: escape stringified int vs native int branch (no escape) [expr sink]
- **ref-int-coerce**: concat with (string) cast vs concat with implicit coercion [expr sink]
- **call-named**: inline str_replace vs named function [strlen sink]
- **call-static**: inline str_replace vs static method [strlen sink]
- **call-instance**: inline str_replace vs instance method [strlen sink]
- **call-closure**: inline str_replace vs closure in local $fn [strlen sink]
- **call-fcc**: inline str_replace vs first-class callable of named fn [strlen sink]
- **call-propfn**: inline str_replace vs closure in object property [strlen sink]
- **call-varname**: inline str_replace vs callable-string variable [strlen sink]
- **call-magic**: inline str_replace vs __call trampoline [strlen sink]
- **const-classconst**: call-site literal arrays vs class const arrays [strlen sink]
- **const-staticprop**: call-site literal arrays vs static property arrays [strlen sink]
- **zen-guard-vs-real**: real_escape_string (today) vs shape A: bool-property inline ternary [quoted sink]
- **zen-guard-vs-inline**: inline str_replace (no guard) vs shape A: bool-property inline ternary [quoted sink]
- **zen-guard-vs-method**: shape A: bool-property inline ternary vs shape C: guarded method [quoted sink]
- **zen-guard-vs-closure**: shape A: bool-property inline ternary vs shape B: stored closure [quoted sink]
- **query-compile-5**: compile 5-value query via real_escape vs compile 5-value query via str_replace [expr sink]
- **query-compile-20**: compile 20-value query via real_escape vs compile 20-value query via str_replace [expr sink]
- **bulk-assembly-500**: 500-value INSERT via real_escape vs 500-value INSERT via str_replace [expr sink]
- **bulk-array-subject**: per-value str_replace loop vs array-subject str_replace batch [expr sink]
- **ref-pdoquote-short**: quoted real_escape_string vs PDO::quote [quoted sink]
- **ref-pdoquote-prose1k**: quoted real_escape_string vs PDO::quote [quoted sink]
- **ref-quotestring-short**: quoted real_escape_string vs mysqli::quote_string [quoted sink]
- **ref-quotestring-prose1k**: quoted real_escape_string vs mysqli::quote_string [quoted sink]
- **alt-quotestring-vs-fast**: mysqli::quote_string vs quoted str_replace [quoted sink]

</details>

## Per-Server Family Grid (run 30964179764)


Correctness: every escaper passes its gate on every cell.

| test | mysql:5.7 | mysql:8.0 | mysql:8.4 | mariadb:10.6 | mariadb:11.8 |
|---|---|---|---|---|---|
| rt-selftie | 1.02x | 0.99x | 1.03x | 1.02x | 0.97x |
| rt-fast-vs-real | 0.99x | 0.98x | 0.98x | 1.03x | 1.00x |
| rt-interp-vs-prepared | 0.59x (slower) | 0.61x (slower) | 0.59x (slower) | 0.58x (slower) | 0.58x (slower) |
| rt-prepared-fresh-vs-reused | **1.86x** | **1.80x** | **1.87x** | **1.86x** | **1.80x** |
| rt-interp-vs-pdo-emulated | 1.00x | 0.98x | 0.96x | 1.01x | 0.98x |
| rt-pdo-emulated-vs-native | 0.61x (slower) | 0.60x (slower) | 0.60x (slower) | 0.56x (slower) | 0.57x (slower) |

<details><summary>Test legend (A vs B, sink)</summary>

- **rt-selftie**: interp-fast vs interp-fast (same) [wall sink]
- **rt-fast-vs-real**: interpolate via real_escape vs interpolate via str_replace [wall sink]
- **rt-interp-vs-prepared**: interpolate via str_replace vs mysqli prepared (fresh per query) [wall sink]
- **rt-prepared-fresh-vs-reused**: mysqli prepared (fresh) vs mysqli prepared (reused) [wall sink]
- **rt-interp-vs-pdo-emulated**: mysqli interpolate str_replace vs PDO emulated prepare (fresh) [wall sink]
- **rt-pdo-emulated-vs-native**: PDO emulated prepare vs PDO native prepare [wall sink]

</details>

## ZenDB-vs-Raw Family Grid (run 30968096535)


Correctness: every escaper passes its gate on every cell.

| test | zendb-vs-raw php8.1 | zendb-vs-raw php8.4 |
|---|---|---|
| zvr-selftie | 1.00x | 0.97x |
| zvr-select-string | 0.83x (slower) | 0.80x (slower) |
| zvr-select-int | 0.51x (slower) | 0.41x (slower) |
| zvr-query-raw-sql | 0.85x (slower) | 0.86x (slower) |
| zvr-fetch-1000 | 0.61x (slower) | 0.52x (slower) |
| zvr-fetch-touch-1000 | 0.59x (slower) | 0.51x (slower) |

<details><summary>Test legend (A vs B, sink)</summary>

- **zvr-selftie**: raw select by string vs raw select by string (same) [wall sink]
- **zvr-select-string**: raw mysqli + real_escape + fetch_all vs DB::select(kv, a = ?) [wall sink]
- **zvr-select-int**: raw mysqli WHERE id = int vs DB::selectOne(kv, id) [wall sink]
- **zvr-query-raw-sql**: raw mysqli hand-escaped SQL vs DB::query with placeholders [wall sink]
- **zvr-fetch-1000**: raw fetch_all assoc (no encoding) vs DB::select full table [wall sink]
- **zvr-fetch-touch-1000**: raw fetch_all + htmlspecialchars per row vs DB::select + SmartString output per row [wall sink]

</details>
