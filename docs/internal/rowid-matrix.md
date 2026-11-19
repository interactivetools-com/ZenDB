# `_rowid` Matrix

MySQL and MariaDB accept `_rowid` in place of a table's primary key column when that key
is one integer column, so a query can ask for a row by its key without knowing whether
the column is called `num` or `id`. This page shows where every supported database
server accepts it, where it fails, and what it costs next to the real column name.

*Generated 2026-11-19 from 19 servers. Every result is in [rowid-matrix-data.md](data/rowid-matrix-data.md).*

## The Short Version

- **`_rowid` works where the statement reads one table**: in the WHERE and ORDER BY of a
  SELECT, UPDATE or DELETE, on all 19 servers, for a table whose primary key is one
  integer column
- **Write it in backticks.** A bare `_rowid` costs a few microseconds more each time a
  statement is parsed: +5.1 to +5.8 µs on a 31 µs query on a quiet MariaDB 12.3 server.
  `` `_rowid` `` costs the same as the real column name. ZenDB's where arrays write
  it in backticks already
- **In a join, put the table name or alias in front.** A bare `_rowid` in a statement
  that reads two or more tables is error 1054, "Unknown column '_rowid'"
- **Some tables have none.** A view, a derived table, and a table with a composite or
  non-integer primary key give the same error 1054
- **It's the same lookup as the real column.** The rows, warnings, errors, EXPLAIN
  output and rows read are identical

## Where to Use It

For a table with one integer primary key, on every server in the matrix (MySQL 5.7 to
9.7, MariaDB 10.2.6 to 12.3, Percona Server 5.7 to 8.4).

| Use | Verdict |
|---|---|
| SELECT where | Yes, when the statement reads one table. Write it in backticks: `` `_rowid` = '123' `` |
| UPDATE where | Yes, same condition. Works on every server; the MySQL manual only promises SELECT |
| DELETE where | Yes, same as UPDATE |
| JOIN condition | Yes, only with the table name or alias in front: `alias._rowid` |
| ORDER BY | Yes, when the statement reads one table; `alias._rowid` in a join |

Every finding on this page is the same on all 19 servers unless it names versions. Three
full runs gave the same answer for all 150 checks.

## Where It Fails

- **Two or more tables:** `_rowid` with no table name in front fails in any statement
  that reads two or more tables, with `error 1054: Unknown column '_rowid' in 'where
  clause'` (the text ends `in 'WHERE'` on MariaDB 10.6+). It fails even when only one of
  the tables has a key, where an unqualified `num` works. `table._rowid` and
  `alias._rowid` work. The server only looks the name up when it has a table name in
  front or the FROM list has one table (`allow_rowid` in `sql_base.cc`, both servers)
- **Subqueries:** an unqualified `_rowid` inside a subquery is the key of the subquery's
  own table when that table has one, with no error. It is the outer table's key only
  when the subquery's table has none
- **Views and derived tables** have no `_rowid`: error 1054 for `WHERE _rowid = 2` on a
  view and for `d._rowid` on `(SELECT num, title FROM t) d`. A view whose own definition
  uses `_rowid` works, and is stored with the real column name

## Which Tables Have One

| Table | `_rowid` is |
|---|---|
| Primary key on one integer column: TINYINT thru BIGINT, signed or unsigned, with or without AUTO_INCREMENT | That column |
| No primary key, one UNIQUE NOT NULL integer index | That column |
| No primary key, two UNIQUE NOT NULL integer indexes | The column of the index created first, not the first column |
| No primary key, and only a nullable or non-unique index | Nothing (error 1054) |
| Composite, VARCHAR, CHAR(36), DECIMAL or BINARY(16) primary key | Nothing (error 1054), and a UNIQUE NOT NULL integer index beside it doesn't give it one |
| INVISIBLE primary key (MySQL 8.0.23+, MariaDB 10.3+) | That column |
| Created with `sql_generate_invisible_primary_key=ON` (MySQL and Percona 8.0.30+) | `my_row_id` |

InnoDB, MyISAM, MEMORY, Aria, partitioned and temporary tables all behave the same, as
do `ANSI_QUOTES`, `ONLY_FULL_GROUP_BY` and `TRADITIONAL`.

## Gotchas

- **A real column named `_rowid` wins.** It takes priority over the primary key, in
  WHERE, in the SELECT list and in `UPDATE ... SET`. Every server accepts one in CREATE
  TABLE and ALTER TABLE, as an ordinary column or as the primary key
- **The result column is named `_rowid`.** `SELECT _rowid` names the result column
  `_rowid` (original column `num`), so a ZenDB row has the key `_rowid`, not `num`.
  `SELECT *`, `SHOW COLUMNS` and `information_schema.COLUMNS` don't list it
- **Writing to it changes the primary key.** `UPDATE ... SET _rowid = 50`, `INSERT
  (_rowid, ...)` and `INSERT ... SET _rowid = 61` all work. ZenDB's `update()` refuses a
  lone `num`, `id` or `ID` in the values as a reversed-argument mistake, and doesn't
  refuse a lone `_rowid`
- **Values compare the same as with the real column:** same rows, same warnings and the
  same errors, strict and non-strict. `'9999999999'` against an INT or INT UNSIGNED key
  returns zero rows with no error and no warning, as a plain or a prepared statement,
  for SELECT, UPDATE and DELETE

## In ZenDB

ZenDB accepts `_rowid` everywhere it accepts a column name: array where, string where
with `?` and `:name`, backtick placeholders, ORDER BY, `update()` and `delete()`
conditions, `insert()` values. `DB::isIdentifier('_rowid')` is true. Array where and
backtick placeholders write it in backticks.

## What It Costs

EXPLAIN is identical for `_rowid` and the real column in every case tried (point lookup,
IN list, range, ORDER BY ... LIMIT, MAX, join, UPDATE, DELETE, partition pruning), and
so are the Handler_read counters when the query runs. The only cost is in parsing a bare
`_rowid`.

| Servers | Bare `_rowid` against `num`, per plain query |
|---|---|
| MySQL and Percona 5.7 | +1.6 to +2.6 µs, with one run at -0.1 |
| MySQL and Percona 8.0 to 9.7 | 0.0 to +1.4 µs |
| MariaDB 10.2 to 10.6 | +0.5 to +3.8 µs |
| MariaDB 10.11 to 12.3 | +3.4 to +6.7 µs, with one run at +10.8 |

Those are three CI runs, and CI runners are noisy: the control moved by -1.0 to +2.0 µs,
and `_rowid` in backticks by -1.6 to +2.0 µs. On a quiet dedicated server (MariaDB
12.3.3, five runs) the bare cost was +5.1 to +5.8 µs on a 31 µs query, backticks -0.04
to +0.15 µs, control within 0.1 µs.

- **In backticks, or with a table name in front, it costs the same as `num`.**
  `` `num` `` and `num` also cost the same on every server (-0.6 to +1.3 µs, inside the
  control's range), so backticks don't make an ordinary column faster
- **The cost is the leading underscore, not `_rowid`.** The lexer checks whether an
  unquoted word that starts with `_` is a charset name, as in `_utf8'text'`
  (`get_charset_by_csname()` in `sql_lex.cc`, both servers). MariaDB and MySQL 5.7 walk
  the whole charset list to find out it isn't; MySQL 8.0+ look it up in a hash map. A
  real column named `_label` costs the same as `_rowid` bare and nothing extra in
  backticks. A backticked name and a name after a dot don't go through that check
- **Prepared statements:** one that is prepared once and run many times shows no
  difference for any spelling. One that is prepared again for every lookup shows the
  same cost as a plain query
- **A column named like a charset** (`_latin1`, `_utf8`, `_utf8mb4`, `_binary`) is a
  syntax error when written bare (error 1064), in SELECT, WHERE, ORDER BY, UPDATE,
  INSERT and CREATE TABLE. It works in backticks or with the table name in front.
  Checked by hand on MariaDB 11.3.2 and MySQL 8.3.0, not in this matrix
- **Query cache:** the MariaDB 10.2, 10.3 and 10.4 images start with the query cache on.
  A cached result comes back before the statement is parsed, so on a cache hit no
  spelling costs anything. The speed test turns the cache off

## What the Manuals Say

Looked up, not tested.

- The MySQL manual defines `_rowid` under [CREATE INDEX, Unique Indexes](https://dev.mysql.com/doc/refman/8.4/en/create-index.html),
  with a pointer from CREATE TABLE. The wording is the same in the 5.7, 8.0, 8.4 and 9.7
  manuals and only promises SELECT: "you can use _rowid to refer to the indexed column
  in SELECT statements". No deprecation or removal notice was found in those manuals;
  the What Is New pages for 9.0 to 9.6 weren't read
- MariaDB documents it as a [pseudo column](https://mariadb.com/docs/server/reference/sql-functions/pseudo-columns/_rowid),
  with an `UPDATE ... WHERE _rowid=2` example and no deprecation notice
- No bug report of wrong results, a crash or a replication problem from using `_rowid`
  was found on bugs.mysql.com, jira.mariadb.org or Percona's Jira. MySQL bugs
  [42539](https://bugs.mysql.com/bug.php?id=42539) and
  [88098](https://bugs.mysql.com/bug.php?id=88098) are documentation fixes; the second
  is the composite-key rule above
- TiDB doesn't support it: [pingcap/tidb issue 3635](https://github.com/pingcap/tidb/issues/3635)
  is open, and the last comment that confirms the error is from August 2020. TiDB's own
  `_tidb_rowid` is a different thing
- Amazon Aurora, RDS, Vitess and PlanetScale: unknown, nothing found

## Full Results

Every check on every server is in [rowid-matrix-data.md](data/rowid-matrix-data.md): a
pass or fail grid of all 150 checks, the statement and error text behind each one, and
the speed test per server.

## Regenerating This Report

This page and its data file are generated, so change them in the script and not by
hand. The sections above are in the heredocs in .github/scripts/rowid-probe.php.

    gh workflow run rowid-matrix.yml   # wait for the run to finish, then:
    gh run download --dir probes       # pick the latest Rowid Matrix run
    php .github/scripts/rowid-probe.php --merge probes/*/rowid-probe-*.json > docs/internal/rowid-matrix.md
    php .github/scripts/rowid-probe.php --merge --data probes/*/rowid-probe-*.json > docs/internal/data/rowid-matrix-data.md
