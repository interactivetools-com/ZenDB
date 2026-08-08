# Performance: Faster than Prepared Statements, Within Microseconds of Raw SQL

Parameterized queries and encoded output are the safest way to build pages;
ZenDB does both automatically, at a cost measured in microseconds. This page
shows the measurements: complete pages from a news site, each timed three
ways, with the query and every HTML encode included on all sides.

Pages that HTML-encode their output, the common case.

| Page              | prepared + `htmlspecialchars()` | raw SQL + `htmlspecialchars()` | ZenDB + SmartString |       ZenDB vs raw |
|-------------------|--------------------------------:|-------------------------------:|--------------------:|-------------------:|
| Detail, 1 article |                          275 µs |                         168 µs |              164 µs |                tie |
| Widget, 5 rows    |                          285 µs |                         190 µs |              216 µs | +26 µs (0.000026s) |
| List, 25 rows     |                          478 µs |                         353 µs |              386 µs | +33 µs (0.000033s) |
| List, 100 rows    |                        1,045 µs |                         899 µs |              979 µs | +80 µs (0.000080s) |

Data processing with no HTML output: ZenDB's `->toArray()` returns plain
arrays and skips the encoding layer.

| Page              | prepared | raw SQL | ZenDB `->toArray()` |        ZenDB vs raw |
|-------------------|---------:|--------:|--------------------:|--------------------:|
| Detail, 1 article |   240 µs |  140 µs |              151 µs |  +11 µs (0.000011s) |
| List, 25 rows     |   408 µs |  301 µs |              345 µs |  +44 µs (0.000044s) |
| List, 100 rows    |   809 µs |  679 µs |              824 µs | +145 µs (0.000145s) |

These benchmarks ran on PHP 8.5 and MySQL 8.0 on GitHub Actions (full setup
below), and all other PHP and database versions come out within a few percent.

ZenDB runs faster than prepared statements plus `htmlspecialchars()`, the
standard safe alternative, on every page, and costs at most 145 µs (0.000145s)
more than raw SQL, the fastest safe approach, tying the
single-article detail page. For scale, humans start to notice interface
delays around 100 ms: about 700 times the largest difference in these tables.

If those numbers answer your question, you can stop here. The rest of the
page shows the code behind each column, explains where the microseconds go,
and ends with how to rerun everything.

## How We Measured

The corpus is a news table: 1,000 rows of realistic prose across 10
categories, each row a 60-byte title with an apostrophe, a 300-byte summary,
and 5 KB of content.

```sql
CREATE TABLE news
(
    id         INT PRIMARY KEY AUTO_INCREMENT,
    category   VARCHAR(30)  NOT NULL,
    title      VARCHAR(255) NOT NULL,
    summary    TEXT         NOT NULL,
    content    MEDIUMTEXT   NOT NULL,
    created_at DATETIME     NOT NULL,
    KEY idx_category_created (category, created_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;
```

Each cell in the tables above times the complete page: run the query, fetch
the rows, HTML-encode every output. The raw-array cells skip the encoding on
all sides.

## The List Page, Three Ways

The 25-row list page from the tables above: load the 25 newest articles in a
category, output title and summary per row.

### Prepared Statements

```php
$category = $_GET['category'] ?? 'news';
$query    = "SELECT * FROM news WHERE category = ? ORDER BY created_at DESC LIMIT 25";
$rows     = $mysqli->execute_query($query, [$category])->fetch_all(MYSQLI_ASSOC);

foreach ($rows as $row) {
    echo "<h2>" . htmlspecialchars($row['title'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8') . "</h2>";
    echo "<p>" . htmlspecialchars($row['summary'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8') . "</p>";
}
```

The `execute_query()` call (PHP 8.2+) is the same as calling `prepare()`,
`bind_param()`, and `execute()`.

### Raw SQL

```php
$category = $_GET['category'] ?? 'news';
$query    = "SELECT * FROM news WHERE category = '" . $mysqli->real_escape_string($category) . "'
             ORDER BY created_at DESC LIMIT 25";
$rows     = $mysqli->query($query)->fetch_all(MYSQLI_ASSOC);

foreach ($rows as $row) {
    echo "<h2>" . htmlspecialchars($row['title'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8') . "</h2>";
    echo "<p>" . htmlspecialchars($row['summary'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8') . "</p>";
}
```

### ZenDB

```php
$category = $_GET['category'] ?? 'news';
$articles = DB::query("SELECT * FROM news WHERE category = ? ORDER BY created_at DESC LIMIT 25", $category);

foreach ($articles as $article) {
    echo "<h2>$article->title</h2>";   // output auto-HTML-encodes
    echo "<p>$article->summary</p>";
}
```

ZenDB is shorter than the fast option and faster than the safe-by-default
option, and there is nothing to wrap and nothing to remember per field.

## Why It Comes Out This Way

**Interpolation skips a round trip.** ZenDB sends one interpolated,
fully-escaped query; a prepared statement sends `prepare()` and `execute()`
separately, and that extra round trip is why the prepared column trails on
every page, worst on the one-article page (275 µs against raw SQL's 168 µs).
Sending finished SQL does not change the safety model: the API takes
placeholders only, and templates with inline values are rejected. See
[Security Gotchas](security-gotchas.md) for the full reasoning.

**Long fields encode faster through SmartString.** SmartString's encoder
scans first and only transforms text that needs it, so on multi-KB fields it
runs faster than `htmlspecialchars()` (measurements on
[SmartString's performance page](https://github.com/interactivetools-com/SmartString/blob/main/docs/performance.md)).
That saving on the 5 KB content field is what pays for ZenDB's query-building
work on the detail page: ~11 µs behind on raw arrays, a tie with HTML output.

**Row construction sets the list-page gap.** Every row becomes a result
object, so the difference grows with row count. The raw-array table is the
clean view, no encoding on either side: ~11 µs at 1 row, ~44 µs at 25 rows,
~145 µs at 100. On the HTML pages the encoding saving offsets part of that,
which is why their gaps run smaller.

## When to Care

Rarely. The largest difference on any page, ~145 µs (0.000145s), is about a
sixth of that page's own ~0.8 ms cost and about a 700th of the ~100 ms
where humans notice. These tests also ran with the database on the same
machine as PHP; when the database is a separate server, network time is
added equally to every column and the differences shrink further. If a page
is genuinely slow, benchmark it and fix what the numbers
show: it will be a query, a missing index, or an API call, not the database
layer.

What the microseconds buy is visible in the code above: every value
parameterized and every output encoded without anyone remembering to do it.
Raw SQL asks you to escape every string and wrap every output on every page,
indefinitely, so it is only as safe as its most recently edited line.

## The Fine Print

Benchmark choices, stated plainly.

- **Both sides are timed in full.** Every cell times the query plus all
  output work, and the baseline is raw SQL as a careful programmer writes
  it: escaped values, `htmlspecialchars()` on every output.
- **The benchmark checks correctness before timing.** Before timing starts,
  the suite verifies ZenDB's HTML output is byte-identical to the baseline's
  `htmlspecialchars()` output, using the exact flags shown in the code above.
- **The baseline encodes with the full flag set.** ZenDB encodes with
  `ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5` for the extra
  safety: `ENT_DISALLOWED` replaces control characters that are invalid in
  HTML, so they never reach the page. The baseline uses the same flags to
  produce identical output. Plain `htmlspecialchars($x)` with PHP's
  defaults skips that scan and encodes these pages ~5 µs (detail) to
  ~37 µs (100-row list) faster; against that baseline the gaps grow by the
  same amounts and the detail-page tie becomes a loss of about 5 µs.
- **The prepared cells use the classic form.** `prepare()`, `bind_param()`,
  `execute()`, fresh per query, which is what per-request PHP pays.
  `execute_query()` measured the same in a separate five-server comparison.
- **Not measured: reused prepared statements.** Reusing a statement handle
  beats re-preparing ~1.8x, but handles don't survive a PHP-FPM request, so
  no cell compares against reuse; long-running workers that keep statements
  alive are outside these measurements.
- **PHP's JIT compiler is off**, matching PHP's default production
  configuration.
- **The corpus is real-page shaped.** 1,000 rows across 10 categories, with
  real-prose special-character density; the same corpus SmartArray
  benchmarks against.
- **Ties are real ties.** The suite's noise band is +/-3%, and every run
  includes a raw-vs-itself calibration cell that must measure ~1.00x.
- **Scope.** All cells are reads on GitHub Actions Linux x64, PHP 8.5,
  MySQL 8.0, with no `encryptionKey` set. Write paths and other platforms
  were not measured.

## Reproducing the Numbers

The tables come from the repo's benchmark workflow, which builds the news
corpus, runs the correctness gate, and times every cell pairwise in one
process. Run the probe locally against your own MySQL:

```bash
git clone https://github.com/interactivetools-com/ZenDB.git && cd ZenDB
composer install
php .github/scripts/escape-zendb-probe.php
```

Or fork the repo and dispatch the same workflow CI runs:
`gh workflow run escape-zendb-matrix.yml`. The published numbers are from
[run 31272787620](https://github.com/interactivetools-com/ZenDB/actions/runs/31272787620),
and a variant run on the latest MariaDB with JIT enabled
([run 31273510581](https://github.com/interactivetools-com/ZenDB/actions/runs/31273510581))
lands within a few percent of them. The committed grids for every benchmark
family are in [escape-results.md](../.github/scripts/escape-results.md).

---

[← Security Gotchas](security-gotchas.md) | [Documentation Index](README.md) | [Next: Troubleshooting →](troubleshooting.md)
