# Performance: Within Microseconds of Raw mysqli + `htmlspecialchars()`

Parameterized queries and encoded output are the safest way to build pages;
ZenDB does both automatically, at a cost measured in microseconds. This page
shows the measurements: complete pages from a news site, each timed both
ways, with the query and every HTML encode included on both sides.

Pages that HTML-encode their output, the common case.

| Page              | raw mysqli + `htmlspecialchars()` | ZenDB + SmartString |  difference |
|-------------------|----------------------------------:|--------------------:|------------:|
| Detail, 1 article |                          0.047 ms |            0.042 ms | -0.000005 s |
| Widget, 5 rows    |                          0.065 ms |            0.076 ms | +0.000011 s |
| List, 25 rows     |                          0.150 ms |            0.169 ms | +0.000020 s |
| List, 100 rows    |                          0.477 ms |            0.559 ms | +0.000082 s |

Data processing with no HTML output: ZenDB's `->toArray()` returns plain
arrays and skips the encoding layer.

| Page              | raw mysqli | ZenDB `->toArray()` |  difference |
|-------------------|-----------:|--------------------:|------------:|
| Detail, 1 article |   0.033 ms |            0.036 ms | +0.000003 s |
| List, 25 rows     |   0.117 ms |            0.134 ms | +0.000017 s |
| List, 100 rows    |   0.341 ms |            0.419 ms | +0.000078 s |

Worst case, ZenDB adds 82 millionths of a second to a page. Best case, it's
faster: the single-article detail page runs faster through ZenDB than through
hand-written mysqli. For scale, humans start to notice interface delays
around 100 ms: about 1,200 times the largest difference in these tables.

These benchmarks ran on PHP 8.5 and MariaDB 10.3 on a dedicated Xeon E-2386G
with the database on the same machine; two full passes agreed within 1%, and
PHP 8.1 through 8.5 come out within a few percent of each other. Slower
hosting scales every column up roughly proportionally; the last section shows
how to rerun everything on your own server.

If those numbers answer your question, you can stop here. The rest of the
page shows the code behind each column, explains where the microseconds go,
and ends with how to rerun everything.

Contents:

- [How We Measured](#how-we-measured)
- [The List Page, Two Ways](#the-list-page-two-ways)
- [Why It Comes Out This Way](#why-it-comes-out-this-way)
- [When to Care](#when-to-care)
- [The Fine Print](#the-fine-print)
- [Reproducing the Numbers](#reproducing-the-numbers)

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

## The List Page, Two Ways

The 25-row list page from the tables above: load the 25 newest articles in a
category, output title and summary per row.

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

ZenDB is the shorter of the two, and there is nothing to escape, nothing to
wrap, and nothing to remember per field.

## Why It Comes Out This Way

**One finished query per call.** ZenDB escapes every value and inlines it
into the SQL before sending, so each query is a single round trip with no
prepare step. Sending finished SQL does not change the safety model: the API
takes placeholders only, and templates with inline values are rejected. See
[Security Gotchas](security-gotchas.md) for the full reasoning.

**Long fields encode faster through SmartString.** SmartString's encoder
scans first and only transforms text that needs it, so on multi-KB fields it
runs faster than `htmlspecialchars()` (measurements on
[SmartString's performance page](https://github.com/interactivetools-com/SmartString/blob/main/docs/performance.md)).
That saving on the 5 KB content field is what pays for ZenDB's query-building
work on the detail page: ~3 µs behind on raw arrays, ~5 µs ahead with HTML
output.

**Row construction sets the list-page gap.** Every row becomes a result
object, so the difference grows with row count. The raw-array table is the
clean view, no encoding on either side: ~3 µs at 1 row, ~17 µs at 25 rows,
~78 µs at 100. On the HTML pages the encoding saving offsets part of that,
which is why their gaps run smaller.

## When to Care

Rarely. The largest difference on any page, ~82 µs (0.000082 s), is about a
seventh of that page's own ~0.56 ms cost and about a 1,200th of the ~100 ms
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
  defaults skips that scan and encodes these pages ~3 µs (detail) to
  ~25 µs (100-row list) faster; against that baseline the gaps grow by the
  same amounts and the detail page stays ~2 µs ahead.
- **PHP's JIT compiler is off**, matching PHP's default production
  configuration.
- **The corpus is real-page shaped.** 1,000 rows across 10 categories, with
  real-prose special-character density; the same corpus SmartArray
  benchmarks against.
- **Ties are real ties.** Every run includes a raw-vs-itself calibration cell
  that must measure ~1.00x, and the two full passes behind these tables
  agreed within 1% per cell.
- **Scope.** All cells are reads on a dedicated Linux x64 server (Intel Xeon
  E-2386G), PHP 8.5, MariaDB 10.3 on the same machine, with no
  `encryptionKey` set. Write paths and other platforms were not measured.
  The same workflow dispatched on GitHub Actions (PHP 8.1-8.5, MySQL 8.0)
  lands within a few percent on every ratio.

## Reproducing the Numbers

The tables come from the repo's benchmark workflow, which builds the news
corpus, runs the correctness gate, and times every cell pairwise in one
process. Run the probe locally against your own MySQL:

```bash
git clone https://github.com/interactivetools-com/ZenDB.git && cd ZenDB
composer install
php .github/scripts/escape-zendb-probe.php
```

The published numbers come from this probe on the dedicated server described
in The Fine Print, run twice with the passes agreeing within 1%. You can also
fork the repo and dispatch the same workflow on GitHub Actions:
`gh workflow run escape-zendb-matrix.yml`. CI runs land within a few percent
on every ratio. The committed grids for every benchmark family are in
[escape-results.md](../.github/scripts/escape-results.md).

---

[← Security Gotchas](security-gotchas.md) | [Documentation Index](README.md) | [Next: Troubleshooting →](troubleshooting.md)
