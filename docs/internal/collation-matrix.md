# Collation Matrix

A `utf8mb4` table created with no COLLATE gets `utf8mb4_general_ci`, `utf8mb4_0900_ai_ci` or
`utf8mb4_uca1400_ai_ci`, depending on the database server and its version. So the same code
makes tables that sort at different speeds, treat different values as equal, and fail with
"Illegal mix of collations" when one is joined to another. This page shows what each
supported server does, what each collation costs, what to write when two collations meet in
one statement, and what converting a table costs.

*Generated 2026-11-19 from 19 servers. Every result is in [collation-matrix-data.md](data/collation-matrix-data.md).*

## The Short Version

- **A new table's collation depends on the server.** `CREATE TABLE ... CHARSET=utf8mb4` with
  no COLLATE gives `utf8mb4_general_ci` on MySQL 5.7 and MariaDB thru 10.11,
  `utf8mb4_0900_ai_ci` on MySQL 8.0+ and `utf8mb4_uca1400_ai_ci` on MariaDB 11.4+. A server
  upgrade changes it for new tables only, so write the COLLATE when every table has to match
- **Two collations in one UNION or JOIN is error 1271 or 1267.** `col COLLATE utf8mb4_unicode_ci`
  on the column that isn't `utf8mb4_unicode_ci` fixes it on all 19 servers. The server can
  no longer look that column up in its index
- **The collation changes speed where the server compares text across many rows:** a sort
  or GROUP BY with no index, or a `LIKE '%word%'` scan. Index lookups and ORDER BY on an
  indexed column cost the same in all six collations, and a
  list page on 10,000 rows differs by about 2 ms. Sorting all of 1,000,000 rows takes 0.8 s
  in `utf8mb4_0900_ai_ci` and 14.7 s in `utf8mb4_unicode_ci` on MySQL 8.0+
- **The fastest ci collation for a large sort is the server default, except on MariaDB 11.4+.**
  It is `utf8mb4_0900_ai_ci` on MySQL 8.0+ and `utf8mb4_general_ci` everywhere else.
  `utf8mb4_unicode_ci` exists on all 19 servers and a server upgrade doesn't change it, but
  from 10,000 rows up it was not the fastest at sorting a whole table on any of them
- **Converting a table with an indexed text column copies it on every MySQL version and
  on MariaDB thru 10.5**, and writes wait until it's done: 1.4 to 3.0 s per 100,000 rows, 21 to 44 s per 1,000,000.
  MariaDB 10.6+ takes 0.25 to 0.36 s and 2.6 to 4.4 s, and writes continue. The convert
  stops with error 1062 when a UNIQUE column holds two values the new collation treats as
  equal
- **Comparisons change with the collation.** `'abc' = 'abc '` is false only in
  `utf8mb4_0900_ai_ci`, any two emoji are equal in `utf8mb4_general_ci` and
  `utf8mb4_unicode_ci`, `'ß' = 's'` only in `utf8mb4_general_ci`, and `'æ' = 'ae'` only in
  `utf8mb4_unicode_520_ci`, `utf8mb4_0900_ai_ci` and `utf8mb4_uca1400_ai_ci`

Contents:

- [Which Collation a New Table Gets](#which-collation-a-new-table-gets)
- [Choosing One Collation for Every Table](#choosing-one-collation-for-every-table)
- [Two Collations in One Statement](#two-collations-in-one-statement)
- [What Each Collation Costs in Speed](#what-each-collation-costs-in-speed)
- [Converting a Table](#converting-a-table)
- [Results That Change Between Collations](#results-that-change-between-collations)
- [Looked Up, Not Tested](#looked-up-not-tested)
- [Full Results](#full-results)
- [Regenerating This Report](#regenerating-this-report)

Every check gave the same answer in every run. A statement on this page is true of all 19
servers unless it names versions. "MySQL" means MySQL and Percona Server: the two answered
every check alike except for one ALTER TABLE clause, which is noted under
[Converting a Table](#converting-a-table).

## Which Collation a New Table Gets

`CREATE TABLE ... CHARSET=utf8mb4` with no COLLATE gives the table the server's default
utf8mb4 collation. `ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4` with no COLLATE gives
the same one, from a latin1 table and from a `utf8mb4_unicode_ci` table. That default is one
of three.

| Servers | A new `CHARSET=utf8mb4` table gets | Has `utf8mb4_0900_ai_ci` | Has `utf8mb4_uca1400_ai_ci` |
|---|---|---|---|
| MySQL 5.7 | `utf8mb4_general_ci` | no | no |
| MySQL 8.0 to 9.7 | `utf8mb4_0900_ai_ci` | yes | no |
| MariaDB 10.2.6 to 10.6 | `utf8mb4_general_ci` | no | no |
| MariaDB 10.11 | `utf8mb4_general_ci` | no | yes |
| MariaDB 11.4 to 12.3 | `utf8mb4_uca1400_ai_ci` | yes | yes |

`utf8mb4_bin`, `utf8mb4_general_ci`, `utf8mb4_unicode_ci` and `utf8mb4_unicode_520_ci` exist
on all 19 servers, and none prints a warning. A server that doesn't have a collation answers
`error 1273: Unknown collation: 'utf8mb4_0900_ai_ci'`.

### Asking the Server Which One

```sql
SHOW CHARACTER SET LIKE 'utf8mb4';  -- the answer is in the `Default collation` column
```

That query named the collation a new table gets on every server, in all seven states tried:
as the server starts, with two other database defaults, and after four session settings. The
other places to look were each wrong somewhere.

| Query | Wrong when |
|---|---|
| `SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = 'utf8mb4'` | `default_collation_for_utf8mb4` is changed on MySQL 8.0+ |
| `SELECT COLLATION(CONVERT('a' USING utf8mb4))` | The same |
| `SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE CHARACTER_SET_NAME = 'utf8mb4' AND IS_DEFAULT = 'Yes'` | The same, and on MariaDB 11.4+ it returns no row unless `character_set_collations` is set in the session |
| `SELECT @@collation_server`, `@@collation_database` or `@@collation_connection` | In at least two of the seven states each |

### What Changes It

- Two settings change what a `CHARSET=utf8mb4` table gets: `default_collation_for_utf8mb4`
  on MySQL 8.0+ and `character_set_collations` on MariaDB 11.4+
- The database's default collation, `collation_server` and `SET NAMES ... COLLATE` don't
  change it. Of those three, only the database default has any effect on a new table, and
  only on a CREATE TABLE with no CHARSET
- A column declared `CHARACTER SET utf8mb4` with no COLLATE gets the server default too,
  even in a `utf8mb4_unicode_ci` table. A column declared with no character set gets the
  table's collation
- MariaDB 11.4 gets its answer from the image's config file, which has the line
  `character-set-collations = utf8mb4=uca1400_ai_ci`. The built-in default changed in
  MariaDB 11.5 ([MDEV-25829](https://jira.mariadb.org/browse/MDEV-25829)), so an 11.4 server
  without that line gives `utf8mb4_general_ci`. The 11.8 and 12.3 images have no such line
  and give `utf8mb4_uca1400_ai_ci`

### After a Server Upgrade

A server upgrade keeps every existing column's collation and changes what a new table gets.
The workflow's upgrade job started MySQL 8.0 on a MySQL 5.7 data directory and MariaDB 11.8
on a MariaDB 10.11 one. Its output is in the workflow run's summary, not in Full Results.

- The old tables stayed `utf8mb4_general_ci`
- A new `CHARSET=utf8mb4` table got `utf8mb4_0900_ai_ci` on MySQL 8.0 and
  `utf8mb4_uca1400_ai_ci` on MariaDB 11.8
- A UNION of an old and a new table failed with error 1271
- The database default stayed `utf8mb4_general_ci`, so a CREATE TABLE with no CHARSET, and a
  column added to an old table, still got `utf8mb4_general_ci`
- `CHECK TABLE ... FOR UPGRADE` reported nothing to rebuild

## Choosing One Collation for Every Table

A database has tables in two collations when some were created with a COLLATE and others
with only `CHARSET=utf8mb4`, or when the server default changed between two CREATE TABLE
statements. A UNION or JOIN across them then fails with error 1271 or 1267. There are three
ways to get to one collation, and each can be done on every server in the matrix (MySQL 5.7
to 9.7, MariaDB 10.2.6 to 12.3, Percona Server 5.7 to 8.4).

| Option | Tables to convert | Speed | One collation on every server |
|---|---|---|---|
| A. The server default | Every table in another collation | The fastest for a large sort, except on MariaDB 11.4+ | No: one of three, and a server upgrade changes it |
| B. `utf8mb4_unicode_ci` | Every table in the server default | Slower sorts and grouping on MySQL and on MariaDB thru 10.11 | Yes |
| C. Whatever the existing tables have | None, unless there are already two | No change | No |

`utf8mb4_unicode_ci` is the only one of the three that exists on all 19 servers under one
name and stays the same through a server upgrade. Its price is the sort and grouping times
under [What Each Collation Costs in Speed](#what-each-collation-costs-in-speed).

### Option A: Every Table in the Server Default

- **The convert:** one ALTER for each table that isn't in the default. Every MySQL version
  and MariaDB thru 10.5 copy a table that has an indexed text column (1.4 to 3.0 s per 100,000 rows here, 21 to 44 s per
  1,000,000) and writes wait until it's done. MariaDB 10.6+ rebuilds only the indexes (0.25
  to 0.36 s per 100,000 rows, 2.6 to 4.4 s per 1,000,000) and writes continue
- **Speed:** the default is the fastest of the ci collations at sorting a large table on
  MySQL 8.0+, MySQL 5.7 and MariaDB thru 10.11. On MariaDB 11.4+ it isn't: on MariaDB 11.8
  the default `utf8mb4_uca1400_ai_ci` sorted a 1,000,000-row `MEDIUMTEXT` title in 6x to 8x
  the time `utf8mb4_general_ci` took
- **It isn't one collation:** "the server default" is three different collations, and it
  changes when the server is upgraded. Old tables keep theirs, new tables get the new one,
  and the two-collation error is back until the convert runs again
- **Comparisons change with it**, next to `utf8mb4_unicode_ci`: trailing spaces, `æ`, `ø`
  and emoji on MySQL 8.0+, `æ`, `ø` and emoji on MariaDB 11.4+, and `ß`, `ı` and combining
  accents where the default is `utf8mb4_general_ci`
- **A UNIQUE column can stop the convert:** it is error 1062 when the column holds two
  values the new collation treats as equal
- **Dumps:** a dump that names `utf8mb4_0900_ai_ci` doesn't load on MySQL 5.7 or on MariaDB
  thru 10.11, and one that names `utf8mb4_uca1400_ai_ci` doesn't load on any MySQL or on
  MariaDB thru 10.6. Both are error 1273

### Option B: Every Table in `utf8mb4_unicode_ci`

- **The convert:** none for tables that are already `utf8mb4_unicode_ci`, and no change in
  their results. Tables in the server default need the same convert as option A, in the
  other direction, with the same error 1062 on a UNIQUE column
- **Speed at 100,000 rows:** sorts, GROUP BY, DISTINCT and text joins take 1.3x to 2.1x the
  default's time on MySQL 5.7 and MariaDB thru 10.6. On MySQL 8.0+ the whole-table sort
  takes 2.6x, GROUP BY, SELECT DISTINCT and a join with no index 1.2x to 1.6x, and
  COUNT(DISTINCT) and a join on an indexed column the same
- **A sort of the whole table with no index grows faster than the table does.** At 1,000,000
  rows and the default `sort_buffer_size` it takes 14.7 s against 0.8 s on MySQL 8.0+,
  13.0 s against 3.1 s on MySQL 5.7, and 8.6 to 9.5 s against 2.2 to 3.7 s on MariaDB thru
  10.4. With a `MEDIUMTEXT` title, the type CMS Builder gives a text field, that sort takes
  8.4 to 13.3 s against 1.2 to 4.5 s in the fastest collation on every server tested,
  MariaDB 11.8 included
- **On MariaDB 11.4+** it is up to 14% faster than the default on the `VARCHAR` statements
- **No difference:** point lookups, ORDER BY on an indexed column and INSERT into a plain
  index cost the same on every server
- **Emoji:** any two emoji compare as equal

### Option C: New Tables Copy the Collation the Existing Tables Have

- No convert, no speed change and no change in results
- Installs differ from each other: a new install gets the server default, with option A's
  dump limits
- A database that already has two collations still needs one convert and a rule for which
  collation stays
- A table whose default was changed without its columns (`ALTER TABLE t CHARACTER SET utf8mb4`)
  reports one collation and holds another

## Two Collations in One Statement

A statement that compares or combines two columns in different ci collations fails. The
fix is one COLLATE in the statement, and it works without converting either table.

```sql
-- articles.name is utf8mb4_unicode_ci, pages.name is utf8mb4_general_ci

-- error 1271: Illegal mix of collations for operation 'UNION'
SELECT name FROM articles UNION SELECT name FROM pages;

-- works on all 19 servers: COLLATE on the column that isn't utf8mb4_unicode_ci
SELECT name FROM articles UNION SELECT name COLLATE utf8mb4_unicode_ci FROM pages;

-- error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='
SELECT a.num FROM articles a JOIN pages p ON a.name = p.name;

-- works on all 19 servers
SELECT a.num FROM articles a JOIN pages p ON a.name = p.name COLLATE utf8mb4_unicode_ci;
```

When either column might be latin1, convert both and then name the collation:

```sql
-- works in every case tried, and the result is utf8mb4_unicode_ci on every server
SELECT CONVERT(name USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM articles
UNION
SELECT CONVERT(name USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM pages;
```

### What Fails and What Works

These fail between any two ci collations.

| Statement | Error |
|---|---|
| UNION and UNION ALL | `error 1271: Illegal mix of collations for operation 'UNION'` |
| `JOIN ... ON a.name = b.name`, `USING (name)`, `IN (subquery)`, `= (subquery)`, `<` and LIKE between the two columns, `CONCAT(a.name, 'text') = b.name` | `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` |
| `'text' IN (a.name, b.name)` | Error 1270 |

These work with no change:

- A comparison of each column with a string literal or a prepared-statement parameter
- `INSERT ... SELECT` from one table into the other
- CONCAT, CONCAT_WS, COALESCE, IFNULL, IF and CASE over both columns. The result is
  `utf8mb4_bin`. GREATEST does the same, except on MySQL 5.7 and MariaDB 10.2, where it is
  error 1267
- A ci column against a `utf8mb4_bin` column, which compares as `utf8mb4_bin`
- A latin1 column against a utf8mb4 column: the server converts the latin1 side

### Each Way to Write the Fix

The same forms were tried on a UNION, a JOIN and the searchMultipleTables() query (a UNION
of one subquery per table with a LIKE on the result), and each form gave the same answer on
all three.

| Written as | Two utf8mb4 columns | One latin1 column | Collation of a UNION's result |
|---|---|---|---|
| `col COLLATE utf8mb4_unicode_ci` on the column that isn't `utf8mb4_unicode_ci` | Works | Works, written on the utf8mb4 column | `utf8mb4_unicode_ci` |
| `col COLLATE utf8mb4_unicode_ci` on both columns | Works | `error 1253: COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'latin1'` | `utf8mb4_unicode_ci` |
| `CONVERT(col USING utf8mb4)` on both columns | Works | Works | The server default |
| `CAST(col AS CHAR)` on both columns | Works | Works | The server default |
| `CONVERT(col USING utf8mb4) COLLATE utf8mb4_unicode_ci` on both columns | Works | Works | `utf8mb4_unicode_ci` |
| `CONVERT(col USING utf8mb4)` on one column | Error 1271 or 1267 on MySQL and on MariaDB thru 11.4. Works on MariaDB 11.8+ | Works on MySQL and on MariaDB thru 11.4. Error 1267 on MariaDB 11.8+ | |

One pair in the last row worked on MariaDB 10.11: `utf8mb4_general_ci` with
`utf8mb4_uca1400_ai_ci`, where the column left alone was already in the server default.

### What the Fix Costs

A function or a COLLATE around a column stops the server from looking that column up in its
index.

- `WHERE name = 'x'` is `type=ref` in EXPLAIN. With CONVERT, CAST or `name COLLATE x` around
  the column it is `type=index`, a scan of the whole index. MySQL 8.0+ still uses the index
  when x is the column's own collation; no other server does
- A COLLATE on the value keeps the index when it names the column's collation and loses it
  when it names another
- ORDER BY with a COLLATE on the column adds a filesort
- In a join, `ON u.name = g.name COLLATE utf8mb4_unicode_ci` keeps the index lookup on `u`,
  the `utf8mb4_unicode_ci` table, when `g` is read first, which is the order every server
  picked. CONVERT on both sides, or `COLLATE utf8mb4_bin` on both sides, leaves neither
  table an index lookup
- On the searchMultipleTables() query, which has no index to lose, `COLLATE utf8mb4_unicode_ci`
  after every column makes a table in any collation take the time a `utf8mb4_unicode_ci`
  table takes without it. `CONVERT(col USING utf8mb4)` around every column takes 6% to 17%
  longer than a table in the server default without it

## What Each Collation Costs in Speed

The speed test makes one table per collation with the same rows and times the same
statements on each, at 1,000, 10,000, 100,000 and 1,000,000 rows. A statement that reads
rows through an index costs the same in every collation. A list page costs a little more in
a slower collation. A sort or a GROUP BY of a whole large table is where the collations
differ, and a `MEDIUMTEXT` column makes that difference larger.

A time in this section is the median of a server group unless a server is named. Three runs
at 100,000 rows and one each at 1,000, 10,000 and 1,000,000 covered all 19 servers. Three
later runs added the `MEDIUMTEXT`, last-page and sort-memory statements on five servers
each: one at 1,000,000 rows on MySQL 5.7, 8.0, 8.4 and MariaDB 10.4, 11.8, and two (100,000
and 1,000,000 rows) on MySQL 8.0, 8.4 and MariaDB 10.6, 10.11, 11.8 with English Wikipedia
article titles in plain ASCII in place of the generated titles.

### Statements That Cost the Same in Every Collation

At 100,000 rows these took 0.92x to 1.08x the `utf8mb4_general_ci` time in every collation
and every server group. Two identical `utf8mb4_general_ci` tables were as far apart (0.90x
to 1.07x).

- A point lookup on an indexed column
- ORDER BY an indexed column with a LIMIT
- The list page with an index on its ORDER BY column (0.1 to 0.4 ms at every table size)
- `WHERE title = ?` over the whole table
- INSERT into a table with a plain index

Table and index size on disk is the same in all six collations too: 83.6 MB of data and
10.0 to 10.1 MB of indexes for 100,000 rows.

### A List Page

The list page is `SELECT * FROM t WHERE hidden = 0 ORDER BY title LIMIT 25` with no index
on `title`. Each cell is the `utf8mb4_unicode_ci` time and then the server default's time,
in ms.

| Rows | MySQL 5.7 | MySQL 8.0+ | MariaDB 10.2 to 10.6 | MariaDB 11.4+ |
|---|---|---|---|---|
| 1,000 | 0.941 / 0.668 | 0.941 / 0.754 | 0.633 / 0.638 | 0.515 / 0.548 |
| 10,000 | 5.46 / 3.38 | 7.54 / 5.40 | 3.82 / 3.76 | 5.37 / 5.86 |
| 100,000 | 68.8 / 45.7 | 103 / 73.5 | 53.8 / 54.7 | 50.7 / 56.4 |
| 1,000,000 | 740 / 504 | 836 / 616 | 470 / 515 | 482 / 526 |

- On MySQL, `utf8mb4_unicode_ci` costs 0.2 to 0.3 ms more for each 1,000 rows in the table,
  in a straight line from 1,000 rows to 1,000,000. That is 1.46x and 1.34x the default's
  time at 100,000 rows, and 1.47x and 1.35x at 1,000,000
- MariaDB thru 10.6 shows no difference at 100,000 rows or below (0.99x over three runs at
  100,000). At 1,000,000 rows `utf8mb4_unicode_ci` was the faster one on MariaDB 10.3 to
  10.6, by 3% to 23%
- On MariaDB 11.4+ `utf8mb4_unicode_ci` is the faster one at every size: 0.91x at 1,000,000
  rows, and 6 ms less at 100,000. On MariaDB 10.11 it is the slower one at every size, by
  1.07x to 1.14x
- The same query sorted by an integer or a datetime column is the part no collation
  touches, and it is the same in every collation's table: 0.3 to 0.7 ms at 1,000 rows, 1.2
  to 4.7 ms at 10,000, 21 to 26 ms at 100,000 (60 ms on MySQL 8.0+), and 241 to 299 ms at
  1,000,000 (473 to 493 ms on MySQL 8.0+)
- Sorting by `title` adds to that. At 100,000 rows it adds 20 ms in `utf8mb4_general_ci`
  and 43 ms in `utf8mb4_unicode_ci` on MySQL 5.7, 13 ms in `utf8mb4_0900_ai_ci` and 43 ms in
  `utf8mb4_unicode_ci` on MySQL 8.0+, about 30 ms in either on MariaDB thru 10.6, and 27 ms
  in `utf8mb4_unicode_ci` and 31 ms in `utf8mb4_uca1400_ai_ci` on MariaDB 11.4+
- So the text sort is 43% of the whole query in `utf8mb4_general_ci` and 62% in
  `utf8mb4_unicode_ci` on MySQL 5.7, 18% in `utf8mb4_0900_ai_ci` and 42% in
  `utf8mb4_unicode_ci` on MySQL 8.0+, and a little over half in every collation on MariaDB
- With an index on the ORDER BY column the page takes 0.1 to 0.4 ms in every collation

### Sorting or Grouping a Whole Table

`utf8mb4_unicode_ci` against the server default. Each cell is the ratio at 100,000 rows and
then at 1,000,000 rows, and above 1.00x `utf8mb4_unicode_ci` is the slower one.

| Statement | MySQL 5.7 | MySQL 8.0+ | MariaDB 10.2 to 10.6 | MariaDB 10.11 | MariaDB 11.4+ |
|---|---|---|---|---|---|
| ORDER BY with no index, whole table | 1.36x / 4.36x | 2.60x / 17.52x | 1.30x / 2.46x | 1.05x / 1.11x | 0.93x / 0.96x |
| GROUP BY | 1.42x / 1.54x | 1.24x / 1.02x | 1.29x / 1.25x | 1.08x / 1.10x | 0.94x / 0.93x |
| SELECT DISTINCT | 1.44x / 1.51x | 1.26x / 1.00x | 1.31x / 1.10x | 1.10x / 1.10x | 0.94x / 0.91x |
| COUNT(DISTINCT) | 1.60x / 1.74x | 0.97x / 0.97x | 2.11x / 1.93x | 1.12x / 1.18x | 0.90x / 0.89x |
| A join on an indexed text column | 1.44x / 1.43x | 1.03x / 0.98x | 1.66x / 1.64x | 1.13x / 1.11x | 0.89x / 0.88x |
| A join with no index | 1.26x / 1.28x | 1.55x / 1.60x | 1.42x / 1.41x | 1.10x / 1.12x | 0.98x / 0.99x |
| The searchMultipleTables() query | 1.12x / 1.11x | 1.29x / 1.26x | 1.12x / 1.07x | 1.20x / 1.22x | 0.98x / 0.99x |
| INSERT into a UNIQUE text index | 1.06x / 0.84x | 1.60x / 1.04x | 1.06x / 1.05x | 1.00x / 1.01x | 1.01x / 0.96x |
| ALTER TABLE ... ADD INDEX | 1.38x / 1.46x | 0.97x / 1.01x | 1.68x / 1.54x | 1.16x / 1.16x | 0.86x / 0.98x |

Only the whole-table sort gets worse as the table grows. These are its times at 1,000,000
rows, sorting a `VARCHAR(255)` title with no index and the default `sort_buffer_size`, in
ms.

| Servers | `utf8mb4_general_ci` | `utf8mb4_unicode_ci` | `utf8mb4_0900_ai_ci` | `utf8mb4_uca1400_ai_ci` |
|---|---|---|---|---|
| MySQL 5.7 | 3095 (default) | 13011 | | |
| MySQL 8.0+ | 2719 | 14701 | 846 (default) | |
| MariaDB 10.2 to 10.6 | 2804 (default) | 8679 | | |
| MariaDB 10.11 | 3113 (default) | 3453 | | 3596 |
| MariaDB 11.4+ | 2638 | 2814 | 2973 | 2930 (default) |

- The MariaDB 10.2 to 10.6 row is two kinds of server. On 10.2 to 10.4 `utf8mb4_unicode_ci`
  takes 8.6 to 9.5 s against 2.2 to 3.7 s. On 10.5 and 10.6 it takes 1.25x and 1.31x the
  default's time
- These sorts ran with each server's default `sort_buffer_size`, which 1,000,000 titles
  don't fit in, so the server sorts in temp files. The ratios are for default settings;
  [Sort Memory](#sort-memory) has the same sorts with more memory. It is also the sort the
  last page of a list ordered by `title` needs
- English titles give the same order. Sorting every row of an English `VARCHAR(255)` title
  takes 0.6 to 0.8 s in `utf8mb4_0900_ai_ci`, 1.6 to 3.3 s in `utf8mb4_general_ci` and 9.1
  to 14.2 s in `utf8mb4_unicode_ci` on MySQL 8.0 and 8.4. On MariaDB 10.6 to 11.8 it takes
  1.9 to 2.1 s in `utf8mb4_general_ci` and 2.0 to 2.4 s in the others
- `utf8mb4_0900_ai_ci` also sorts faster than `utf8mb4_general_ci` on MySQL 8.0+: 0.60x at
  100,000 rows
- `utf8mb4_unicode_520_ci` costs the same as `utf8mb4_unicode_ci` on every server (within 3%
  on most statements)
- `utf8mb4_bin` is not always the fastest: at 100,000 rows the whole-table sort takes 1.19x
  to 1.35x the `utf8mb4_general_ci` time on MySQL and on MariaDB thru 10.6
- On MariaDB 11.4+ the default `utf8mb4_uca1400_ai_ci` is slower than `utf8mb4_unicode_ci`,
  which takes 0.86x to 1.02x the default's time on every statement at 100,000 rows, and
  `utf8mb4_general_ci` is the fastest ci collation. At 1,000,000 rows the default takes
  longer than `utf8mb4_general_ci` by 6% to 15% on the whole-table sort, 19% to 24% on the
  first list page, 15% to 19% on GROUP BY, and 1.5x to 2.5x on `content LIKE '%word%'`
  (2,158 ms against 864 ms on MariaDB 12.3). At 100,000 rows that LIKE takes 146 ms in
  `utf8mb4_general_ci` and 337 to 338 ms in each of the other four

### `MEDIUMTEXT` Columns

A `MEDIUMTEXT` title, the type CMS Builder gives a text field, makes the difference larger,
and on MySQL 8.0 and 8.4 it makes `utf8mb4_general_ci` as slow as `utf8mb4_unicode_ci`.
These are the times to sort every row of a 1,000,000-row table by a `MEDIUMTEXT` title with
the default `sort_buffer_size`, in ms.

| Server | Titles | `utf8mb4_general_ci` | `utf8mb4_unicode_ci` | `utf8mb4_0900_ai_ci` | `utf8mb4_uca1400_ai_ci` |
|---|---|---|---|---|---|
| MySQL 5.7 | generated | 4521 (default) | 10570 | | |
| MySQL 8.0 | generated | 13189 | 13264 | 1603 (default) | |
| MySQL 8.0 | English | 12169 | 12279 | 1157 (default) | |
| MySQL 8.4 | generated | 11315 | 11130 | 2686 (default) | |
| MySQL 8.4 | English | 10035 | 10105 | 1788 (default) | |
| MariaDB 10.4 | generated | 2001 (default) | 9712 | | |
| MariaDB 10.6 | English | 1502 (default) | 8407 | | |
| MariaDB 10.11 | English | 1506 (default) | 10464 | | 9777 |
| MariaDB 11.8 | generated | 2025 | 12308 | 12285 | 12287 (default) |
| MariaDB 11.8 | English | 1409 | 11941 | 11288 | 11503 (default) |

- `utf8mb4_unicode_ci` takes 2.3x to 10.6x the time of the fastest collation on the same
  server
- On MariaDB 11.8 the default `utf8mb4_uca1400_ai_ci` takes 6x the `utf8mb4_general_ci`
  time with the generated titles and 8x with English titles. On the `VARCHAR` title it took
  1.1x
- A prefix index on a `MEDIUMTEXT` column, `titleIndexed(768)`, is never used for ORDER BY.
  EXPLAIN shows `type=ALL, Using filesort` on every server, for the first page and the
  last, and the first page takes the same time with it as without it (342 ms against 342 ms
  on MySQL 8.0). So a list sorted by a `MEDIUMTEXT` column sorts the table on every page
- The first list page (`LIMIT 25`) with a `MEDIUMTEXT` title takes 342 to 470 ms in
  `utf8mb4_0900_ai_ci` on MySQL 8.0 and 8.4 with English titles, and `utf8mb4_unicode_ci`
  takes 1.3x that. On MariaDB 10.4 and 10.6 `utf8mb4_unicode_ci` was the fastest for that
  page (263 ms against 325 ms on 10.6), as it was for the `VARCHAR` title on MariaDB 10.3
  to 10.6. On MariaDB 10.11 and 11.8 `utf8mb4_general_ci` was the fastest, and the others
  took 3% to 27% longer

### The Last Page of a List

The last page is the same list query with an OFFSET of 19/20 of the rows. The server has to
sort every row to find it, so the LIMIT no longer cuts the sort short, and the slower
collations cost what they cost in a whole-table sort.

- On MySQL 8.0 at 1,000,000 rows the last page took 2.3 s in `utf8mb4_0900_ai_ci` and
  15.3 s in `utf8mb4_unicode_ci`. The first page took 0.6 s and 0.9 s
- An index on a `VARCHAR` title didn't help: 2.3 s and 15.9 s with it. Only MariaDB 11.8
  read the index for the last page (`type=index`), and took 2.1 to 2.2 s in every collation
  with the generated titles and 1.6 s with English titles. Every other server sorted the
  table

### Sort Memory

The large differences come from sorts that don't fit in `sort_buffer_size` and go to temp
files. With more sort memory they mostly go away. These are the times to sort every row of
the 1,000,000 generated `VARCHAR` titles on MySQL 8.0, in ms.

| `sort_buffer_size` | `utf8mb4_0900_ai_ci` | `utf8mb4_general_ci` | `utf8mb4_unicode_ci` |
|---|---|---|---|
| The server's default | 874 | 2343 | 15363 |
| 64 MB | 963 | 1251 | 6253 |
| 2 GB | 987 | 1517 | 2126 |

- The slow collations write more to the temp files. At the default size that sort merged
  temp files 86 times in `utf8mb4_0900_ai_ci`, 360 times in `utf8mb4_general_ci` and 687
  times in `utf8mb4_unicode_ci`. At 64 MB it was 1, 1 and 3 times, and at 2 GB the sort
  stayed in memory. Why their sort values are larger was not looked into in the server code
- With English `VARCHAR` titles and 2 GB, MySQL 8.0 and 8.4 take 1.4 to 1.7 s in
  `utf8mb4_unicode_ci` against 0.7 to 0.8 s in `utf8mb4_0900_ai_ci`. At 64 MB
  `utf8mb4_unicode_ci` takes 5.0 to 5.6 s
- With `MEDIUMTEXT` English titles and 2 GB, MariaDB 11.8 takes 2.2 s in
  `utf8mb4_uca1400_ai_ci` against 1.8 s in `utf8mb4_general_ci`. At the default size it
  merged temp files 82 times in every collation but `utf8mb4_general_ci` and `utf8mb4_bin`,
  which merged 4 times
- A larger `sort_buffer_size` isn't free. In the English-title run, which timed all three
  sizes on each server, a sort that was already fast got slower at 2 GB than at the
  default: the `VARCHAR` sort on MariaDB 10.6, 10.11 and 11.8 took 4% to 32% longer in
  every collation, and `utf8mb4_0900_ai_ci` on MySQL 8.0 and 8.4 took 5% to 15% longer

## Converting a Table

`ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 COLLATE x` changes the table default and
every text column. What it costs depends on whether the server copies the table, and
whether it finishes depends on what the table holds.

```sql
ALTER TABLE articles CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The times are for a table with six text columns and two text indexes, converted between
`utf8mb4_unicode_ci` and the server default in either direction.

| Servers | 100,000 rows (84 MB) | 1,000,000 rows (834 MB) | Copies the table | An INSERT from another connection |
|---|---|---|---|---|
| MySQL, every version | 1.5 to 3.0 s | 24 to 42 s | Yes | Waits until the ALTER is done |
| MariaDB thru 10.5 | 1.4 to 2.3 s | 21 to 44 s | Yes | Waits until the ALTER is done |
| MariaDB 10.6+ | 0.25 to 0.36 s | 2.6 to 4.4 s | No: 0 rows affected | Runs while the ALTER does |

- A SELECT from another connection ran during the ALTER on every server
- At 1,000,000 rows the INSERT waits as long as the convert takes. On the servers where it
  took over 30 s the probe's INSERT stopped at its own lock wait limit with error 1205
- The two directions cost about the same: within 25% of each other on every server but
  MariaDB 10.6, where converting to `utf8mb4_unicode_ci` took 4.4 s and converting from it
  2.8 s at 1,000,000 rows

### The MODIFY Form

The same change can be written as a table default plus one MODIFY for each text column,
which is the form CMS Builder writes.

```sql
ALTER TABLE articles DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    MODIFY title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY content MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
```

- It takes the same time as CONVERT TO on every server, and with no ALGORITHM clause it
  copies the table on the same servers
- It leaves the table default and each listed column on x. A column it doesn't list keeps
  its collation: a `utf8mb4_bin` column stays `utf8mb4_bin`. CONVERT TO changes that column
  too, and a latin1 column
- SHOW CREATE TABLE afterwards differs. MySQL 8.0+ prints `CHARACTER SET utf8mb4 COLLATE x`
  on each modified column, even when x is the table default, and prints none after CONVERT
  TO. MariaDB 10.3+ prints none in both cases. MySQL 5.7 and MariaDB 10.2 print a column's
  COLLATE whenever it isn't `utf8mb4_general_ci`, however the table was made

### `ALTER TABLE t CHARACTER SET utf8mb4` Changes the Default Only

With no CONVERT and no MODIFY, the statement changes the table default and nothing else.
The existing columns keep `utf8mb4_unicode_ci`, SHOW CREATE TABLE then prints
`CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` on each of them, and a column added
afterwards gets the new default. That is two collations inside one table.

### Converting Without a Table Copy

- **A column with no index** changes collation without a table copy on MySQL 8.0+
  (`ALGORITHM=INPLACE` works, 0 rows affected) and on MariaDB 10.4+ (`ALGORITHM=INSTANT`
  works for CONVERT TO, `ALGORITHM=NOCOPY` for the MODIFY form). MySQL 5.7 and MariaDB thru
  10.3 copy the table
- **An indexed column** needs the copy on every MySQL version and on MariaDB thru 10.5.
  There `ALGORITHM=INPLACE` is
  `error 1846: ALGORITHM=INPLACE is not supported. Reason: Cannot change column type INPLACE. Try ALGORITHM=COPY.`
  (MariaDB 10.4 and 10.5 give the reason as "Collation change on an indexed column"). It
  works on MariaDB 10.6+
- **No lock:** `ALGORITHM=INPLACE, LOCK=NONE` on a column with no index works for a
  one-column MODIFY on every MySQL 8.0+. For CONVERT TO and the MODIFY form that lists
  every text column it is error 1845 on MySQL 8.4 and 9.7, and works on MySQL 8.0 and 9.6
  and on Percona Server 8.0 and 8.4, which is the one place Percona Server and MySQL
  differ. `ALGORITHM=COPY, LOCK=NONE` works on
  MariaDB 11.4+ only

### What Stops a Convert

**A UNIQUE column** stops it when two of its values are equal in the new collation:
`error 1062: Duplicate entry 'ı' for key 'v'`. MySQL 8.0+ prints the key with its table, as
`'uq.v'`, and every server prints an emoji in the value as `?`. It can happen in either direction. Of
the 39 sample pairs under [Results That Change Between Collations](#results-that-change-between-collations),
these become equal.

| Convert | Pairs that become equal |
|---|---|
| `utf8mb4_unicode_ci` → `utf8mb4_general_ci` | 4 |
| `utf8mb4_general_ci` → `utf8mb4_unicode_ci` | 9 |
| `utf8mb4_unicode_ci` → `utf8mb4_0900_ai_ci` | 6 |
| `utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci` | 7, among them `'abc'` against `'abc '` and every emoji pair |
| `utf8mb4_unicode_ci` → `utf8mb4_uca1400_ai_ci` | 6 |
| `utf8mb4_uca1400_ai_ci` → `utf8mb4_unicode_ci` | 5 |

**`ALTER IGNORE TABLE ... CONVERT`** on MariaDB doesn't stop: it deletes the rows that
became duplicates (16 rows became 7 in one check). MySQL has no ALTER IGNORE (error 1064).

**A text column in a foreign key** stops the convert of either table: error 1832 for the
child and 1833 for the parent, and error 3780 for both on MySQL 8.0+. With
`foreign_key_checks = 0` both tables convert on MySQL and on MariaDB thru 10.3, and the key
is still enforced afterwards. MariaDB 10.4+ refuses even then.

These convert without error: a STORED or indexed VIRTUAL generated column, a FULLTEXT index,
and prefix indexes, whose prefix lengths stay as they were.

## Results That Change Between Collations

A different collation changes which values are equal, so it changes what a WHERE finds,
what a UNIQUE index accepts and how ORDER BY sorts. Every pair below gave the same answer
on every server that has the collation.

| Is this true | `general_ci` | `unicode_ci` | `unicode_520_ci` | `0900_ai_ci` | `uca1400_ai_ci` |
|---|---|---|---|---|---|
| `'abc' = 'abc '` (a trailing space) | yes | yes | yes | no | yes |
| `'a' = 'A'`, `'cafe' = 'café'`, `'ä' = 'a'`, `'ñ' = 'n'` | yes | yes | yes | yes | yes |
| `'ß' = 'ss'` | no | yes | yes | yes | yes |
| `'ß' = 's'`, `'i' = 'ı'`, `'I' = 'ı'` | yes | no | no | no | no |
| `'æ' = 'ae'`, `'ø' = 'o'`, `'ł' = 'l'`, `'đ' = 'd'`, `'ð' = 'd'` | no | no | yes | yes | yes |
| `'é'` = `'e'` plus a combining accent | no | yes | yes | yes | yes |
| Hiragana = katakana, fullwidth `'Ａ' = 'A'`, `'ﬁ' = 'fi'` | no | yes | yes | yes | yes |
| A zero width space inside a word is ignored | no | yes | yes | yes | yes |
| A soft hyphen inside a word is ignored | no | no | no | yes | yes |
| Any two emoji are equal | yes | yes | no | no | no |

- **Trailing spaces:** `utf8mb4_bin` ignores them too, so `utf8mb4_0900_ai_ci` is the only
  one of the six that doesn't, on MySQL and on MariaDB 11.4+. A UNIQUE index stores both
  `'abc'` and `'abc '` only in `utf8mb4_0900_ai_ci`. LIKE doesn't match them in any
  collation
- **Emoji:** where any two are equal, a UNIQUE index refuses the second one with error 1062
- **ORDER BY changes as well.** `utf8mb4_general_ci` sorts `_a`, `æ`, `ø` and `ł` after `z`;
  `utf8mb4_unicode_ci` sorts `_a` first and the letters beside `a`, `o` and `l`.
  `utf8mb4_0900_ai_ci` and `utf8mb4_uca1400_ai_ci` sort emoji before the digits, except an
  emoji newer than the collation's Unicode version (🥲 sorts last on MySQL 8.0+); the older
  three sort them last

## Looked Up, Not Tested

These come from the manuals, bug trackers and other projects' source, not from the probe.

- **Deprecation:** no utf8mb4 collation is listed as deprecated in the MySQL 8.0, 8.4 or 9.7
  manuals ([8.4 What Is New](https://dev.mysql.com/doc/refman/8.4/en/mysql-nutshell.html)),
  and no deprecation notice was found for one in MariaDB's documentation. No server in the
  matrix printed a warning for any of the six
- **`default_collation_for_utf8mb4`** accepts `utf8mb4_0900_ai_ci` and `utf8mb4_general_ci`
  and "is for internal use by MySQL Replication only"
  ([manual](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html#sysvar_default_collation_for_utf8mb4)).
  Setting it prints a deprecation warning ([bug 104066](https://bugs.mysql.com/bug.php?id=104066))
- **When the defaults changed:** MySQL's default changed from `utf8mb4_general_ci` to
  `utf8mb4_0900_ai_ci` in 8.0.1
  ([release notes](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-1.html)). MariaDB
  added the uca1400 collations in 10.10 ([MDEV-27009](https://jira.mariadb.org/browse/MDEV-27009))
  and `character_set_collations` in 11.2 ([MDEV-30164](https://jira.mariadb.org/browse/MDEV-30164)),
  and gave that variable its `utf8mb4=utf8mb4_uca1400_ai_ci` default in 11.5
  ([MDEV-25829](https://jira.mariadb.org/browse/MDEV-25829))
- **MariaDB's `utf8mb4_0900_ai_ci`** is an alias for `utf8mb4_uca1400_nopad_ai_ci`, added in
  11.4.5 so that MySQL dumps load ([MDEV-20912](https://jira.mariadb.org/browse/MDEV-20912)).
  An 11.4 server older than 11.4.5 doesn't have it
- **Upgrades:** "The upgrade from 5.7 to 8.0 does not change any collation for any existing
  database objects, but takes effect for new objects"
  ([MySQL manual](https://dev.mysql.com/doc/refman/8.0/en/upgrading-from-previous-series.html)),
  which matches the upgrade job. MariaDB's list of collations that need an index rebuild
  after an upgrade has no utf8mb4 entry (`collation_changed_order()` in `sql_string.h`)
- **Pad attribute:** "The Unicode collations based on UCA 9.0.0 and higher have a pad
  attribute of NO PAD" and the rest are PAD SPACE
  ([MySQL manual](https://dev.mysql.com/doc/refman/8.4/en/charset-binary-collations.html)).
  MariaDB names its NO PAD collations with `_nopad_`, so `utf8mb4_uca1400_ai_ci` is PAD
  SPACE, which matches the trailing-space check
- **Unicode versions:** `utf8mb4_unicode_ci` follows Unicode 4.0.0, `utf8mb4_unicode_520_ci`
  5.2.0, `utf8mb4_0900_ai_ci` 9.0.0 and `utf8mb4_uca1400_ai_ci` 14.0.0. For the first, "all
  supplementary characters are equal to each other"
  ([MySQL manual](https://dev.mysql.com/doc/refman/8.4/en/charset-unicode-sets.html)), which
  is the emoji result above
- **Open MariaDB issues:** a table can end up with old and new columns in different
  collations after the default change ([MDEV-40343](https://jira.mariadb.org/browse/MDEV-40343)),
  and a documentation task says some comparisons are "significantly slower with
  utf8mb4_uca1400_ai_ci" ([MDEV-38743](https://jira.mariadb.org/browse/MDEV-38743)). A sort
  speed fix for the UCA collations is in 11.8.6 and 12.2.1
  ([MDEV-37849](https://jira.mariadb.org/browse/MDEV-37849))
- **Other projects:** WordPress creates tables in `utf8mb4_unicode_520_ci` when the server
  has it and `utf8mb4_unicode_ci` otherwise (`determine_charset()` in `class-wpdb.php`).
  Laravel 12 and Joomla 5 use `utf8mb4_unicode_ci` on every server; Laravel 11.0.0 shipped
  with the two server defaults and went back in 11.0.7. Drupal 11 writes no COLLATE unless
  one is set in `settings.php`
- **Hosted databases:** for Amazon RDS, Aurora, Azure and Google Cloud SQL, no page was
  found that says they change the default utf8mb4 collation. Not known for cPanel hosts

## Full Results

Every check and every timing on every server is in
[collation-matrix-data.md](data/collation-matrix-data.md): which collations each server
has, each numbered check with the servers that gave each answer, and the speed test at
every table size, with the query plans and the sorts that went to temp files.

## Regenerating This Report

This page and its data file are generated, so change them in the script and not by hand.
The sections above are in the `__INTRO__` and `__FINDINGS__` heredocs in
.github/scripts/collation-probe.php. Their numbers are typed in, so check them against the
data file after a new run.

    gh workflow run collation-matrix.yml      # 100,000 rows on every server; wait for the run to finish
    gh run download <run id> --dir probes/1   # one folder for each run
    php .github/scripts/collation-probe.php --merge probes/*/*/collation-probe-*.json > docs/internal/collation-matrix.md
    php .github/scripts/collation-probe.php --merge --data probes/*/*/collation-probe-*.json > docs/internal/data/collation-matrix-data.md

- **Other runs:** `-f rows=1000000` sets the table size (give the server more memory with
  `-f server_options=`), `-f titles=wikipedia` uses English Wikipedia article titles,
  `-f databases="mysql:8.4 mariadb:11.8"` picks the servers, and `-f only=<regex>` times
  only the statements whose name matches
- **Several runs are merged in one command.** The first file given for a server supplies
  its checks, so the folder names decide which run that is. The same checks in the other
  files are compared with it, and any that differ are listed in the data file under
  [Checks That Gave Different Answers in Different Runs](data/collation-matrix-data.md#checks-that-gave-different-answers-in-different-runs).
  The speed test keeps every run: runs with the same table size and titles are combined by
  taking the median of each run's time, and each table size gets its own section
- **The upgrade job** in the same workflow writes its findings to the run's summary. They
  aren't merged into either file
