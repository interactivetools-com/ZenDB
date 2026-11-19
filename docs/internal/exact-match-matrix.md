# Exact Match Matrix

`col = ?` treats some different strings as equal: most collations ignore trailing spaces,
and `_ci` collations also ignore case and accents, so `token = ' '` finds a row where
`token` is ''. This page shows how to write a comparison that matches only the identical
value, and what it costs next to `col = ?`, on every database server in the CI matrix.

*Generated 2026-11-19 from 19 servers. Every result is in [exact-match-matrix-data.md](data/exact-match-matrix-data.md).*

## The Short Version

- **For an exact match, cast the value you send: `col = CAST(? AS BINARY)`.** On all 19
  servers it matched only the identical value, with no warning or error
- **It costs the same as `col = ?`**: the same index, the same rows read, and 0.90x to
  1.07x the time
- **Or make the column `VARBINARY`, and plain `col = ?` is exact.** Where arrays too,
  with no cast in any query, and the same speed and index size. Best for a new column
  that only holds generated values; an existing one needs an ALTER TABLE
- **Cast the value, never the column.** `CAST(col AS BINARY) = ?` reads every row, and
  was 60x to 114x slower at 100,000 rows
- **One exception:** a non-ASCII value in a latin1 column doesn't match itself with the
  cast. Generated tokens, codes and ids are ASCII, so they aren't affected

## Exact Matches with `CAST(? AS BINARY)`

For a generated value (a token, code or id), cast the value you send:

```php
// Finds '' as well as ' ', and 'abc' as well as 'ABC'
$rows = DB::select('tokens', "token = ?", $token);

// Finds only the value sent
$rows = DB::select('tokens', "token = CAST(? AS BINARY)", $token);
```

On all 19 servers the cast matched only the identical value, used the same index as
`col = ?`, read the same rows, took 0.90x to 1.07x the time and gave no warning or error.

For a lookup on two columns, cast each value. The index on both columns is still used:

```php
$rows = DB::select('tokens', "code = CAST(? AS BINARY) AND token = CAST(? AS BINARY)", $code, $token);
```

### Cast the Value, Not the Column

`col = CAST(? AS BINARY)` converts the one value sent, then uses the column's index.
`CAST(col AS BINARY) = ?` has to convert every row before it can compare, so the index
can't be used: at 100,000 rows it read 100,001 rows where the value cast read 2, and was
60x to 114x slower. The same goes for any function around a column in a WHERE, such as
`LOWER(col) = ?`.

### Where Arrays and IN Lists

A where array writes `col = ?`, so `['token' => $token]` compares the way the column's
collation does. Use the string form above for an exact match.

An array placeholder can't go inside a cast: `CAST(:tokens AS BINARY)` becomes
`CAST('abc','xyz' AS BINARY)`, which is a syntax error. Cast each value instead:

```php
$rows = DB::select('tokens', "token IN (CAST(? AS BINARY), CAST(? AS BINARY))", 'abc', 'xyz');
```

## What `col = ?` Matches

Each pair below gave the same answer on all 19 servers.

| Stored | Sent | `col = ?` on `utf8mb4_unicode_ci` | `col = ?` on `utf8mb4_general_ci` | `col = CAST(? AS BINARY)` |
|---|---|---|---|---|
| `''` | `' '` | matches | matches | no |
| `'abc'` | `'abc '` | matches | matches | no |
| `'abc '` | `'abc'` | matches | matches | no |
| `'abc'` | `'ABC'` | matches | matches | no |
| `'e'` | `'é'` | matches | matches | no |
| `'ss'` | `'ß'` | matches | no | no |
| `''` | U+200B (zero-width space) | matches | no | no |
| `''` | a NUL byte | matches | no | no |
| `'abc'` | `' abc'` | no | no | no |

`utf8mb4_0900_ai_ci`, the MySQL 8.0+ default, doesn't ignore trailing spaces but matches
every other pair `utf8mb4_unicode_ci` does.

## Speed and Indexes

At 100,000 rows, median over the 19 servers.

| Column | `col = ?` | `col = CAST(? AS BINARY)` | Rows read, both |
|---|---|---|---|
| `utf8mb4_unicode_ci`, unique index | 115.3 µs | 115.6 µs | 1 |
| `utf8mb4_general_ci`, plain index | 131.5 µs | 134.9 µs | 2 |
| `ascii_general_ci`, plain index | 131.4 µs | 133.5 µs | 2 |
| Two-column index, both columns | 150.2 µs | 150.1 µs | 2 |
| IN list of 5, `utf8mb4_unicode_ci` unique index | 142.5 µs | 150.9 µs | 5 |

- Index size doesn't depend on the column type for ASCII values: the plain index on
  100,000 tokens is 5,648 KB on every type tested, `VARBINARY` included. The lookups use
  no temporary tables, so memory use is the same as `col = ?`
- MySQL and Percona show the cast as `range` with note 1739 ("Cannot use ref access on
  index ... due to type or collation conversion"), where MariaDB shows `ref`. Both read
  the same rows as `col = ?`, and the two-column index uses both columns (key_len 1279)
  on every server

## Other Ways to Write It

Each of these misses on some server, column or value, or loses the index.

| Form | Exact | Index | Warnings and errors | Use it |
|---|---|---|---|---|
| `a` `col = ?` (now) | No: case on `_ci`, trailing spaces on every PAD SPACE collation, accents, and `''` = U+200B = NUL on `unicode_ci` and `0900_ai_ci` | Yes | Error 1267 (3854 on MySQL 9.6+) when an ascii or latin1 column is compared with a value it can't hold | Today's form |
| `b1` `col = CAST(? AS BINARY)` | Yes, on every server and column type, except non-ASCII in latin1 | Yes. ref/const on MariaDB, range with note 1739 on MySQL and Percona; same rows read | None | Yes |
| `b2` `col = BINARY ?` | Same as `b1` | Same as `b1` | Warning 1287 "'BINARY expr' is deprecated" on MySQL/Percona 8.0+ | No |
| `c` `CAST(col AS BINARY) = ?` | Same as `b1` | No. Reads every row, 60x to 114x slower at 100,000 rows | None | No |
| `d` `col = ? COLLATE utf8mb4_bin` | No: still matches trailing spaces (`utf8mb4_bin` is PAD SPACE) | Lost on ascii and latin1 columns on MySQL/Percona 5.7 and MariaDB, and on VARBINARY columns on MariaDB: reads every row, up to 155x slower | None | No |
| `e` `col LIKE ?` | No: case and accents on `_ci`; `%` and `_` in the value are wildcards | Yes (range) | Same errors as `a` | No |
| `f` `col = ? AND col = CAST(? AS BINARY)` | Same as `b1` | Yes | Same errors as `a`: the `col = ?` half fails on ascii and latin1 columns | No gain over `b1` |
| `g1` `COLLATE utf8mb4_0900_bin` | Yes where it exists | Lost on ascii and VARBINARY columns on MariaDB 11.4+ | Not on MySQL 5.7 or MariaDB thru 10.11 | No |
| `g2` `COLLATE utf8mb4_0900_as_cs` | No: `''` = U+200B = NUL | Reads every row on MySQL | Not on MySQL 5.7 or MariaDB thru 10.11 | No |
| `g3` `COLLATE utf8mb4_nopad_bin` | No on MariaDB thru 10.4: `''` = NUL | Lost on non-utf8mb4 columns | MariaDB only | No |

## Gotchas

- **latin1 columns:** a non-ASCII value stored in a latin1 column doesn't match itself
  with the cast, because the column holds `é` as byte E9 and the connection sends C3 A9.
  ASCII values in ascii and latin1 columns match. Generated values are ASCII, so this
  only matters if the cast is used on text people type
- **ascii and latin1 columns with `col = ?`:** a value the column can't hold (`é`, `ß`,
  U+200B) is error 1267, or 3854 on MySQL 9.6+. The cast gives no match and no error
- **Unique indexes:** `'a'` and `'a '` are the same key under every collation that
  ignores trailing spaces (`unicode_ci`, `general_ci`, `bin`, ascii, latin1), and
  different under `VARBINARY`, `0900_bin`, `0900_ai_ci` and `nopad_bin`. `'a'` and `'A'`
  are the same key under every `_ci` collation
- **CHAR columns:** trailing spaces are dropped when the value is stored, so `'abc '` is
  read back as `'abc'`. `VARCHAR` and `TEXT` keep them
- **`utf8mb4_nopad_bin` on MariaDB 10.2 to 10.4:** `''` and a NUL byte are equal, in
  comparisons and in unique indexes. The 10.5, 10.6, 10.11, 11.4, 11.8 and 12.3 images
  keep them apart; a local MariaDB 11.3.2 doesn't
- **NULL:** `CAST(NULL AS BINARY)` is NULL, so a NULL value matches nothing, as with
  `col = ?`

### Strings and Numbers

Integer columns compare as numbers, so send an int and don't cast it.

- `intCol = ''` matches 0 on MySQL and Percona with no warning, under strict mode too.
  MariaDB matches 0 with warning 1292, and its strict UPDATE errors
- `intCol = 'abc'` and `intCol = '5abc'` match 0 and 5 with warning 1292 on every server.
  Under ZenDB's default sql_mode (`STRICT_ALL_TABLES`) the same WHERE errors 1292 in an
  UPDATE everywhere, and in a DELETE on MySQL and Percona; a MariaDB DELETE goes ahead
  with the warning
- `intCol = CAST('5abc' AS BINARY)` matches 5 with no warning and no strict error on
  MySQL/Percona 5.7. Other servers treat it as `intCol = '5abc'`
- `varcharCol = 0` matches `'abc'`, `'0'`, `''` and `'x1'`; `= 1` and `= TRUE` match `'1'`,
  `'1abc'` and `' 1'`, with warning 1292. `varcharCol = CAST('1' AS BINARY)` matches only
  `'1'`

## Changing the Column to `VARBINARY`

A `VARBINARY` column compares bytes, so plain `col = ?` and where arrays match only the
identical value with no cast in the query. It measured the same as a collated column for
plan, speed and index size, and its unique index keeps `'a'`, `'a '` and `'A'` apart.

- An existing install needs an ALTER TABLE to change the column
- `UPPER()` and `LOWER()` don't change a binary value, and `LIKE` on it is case-sensitive
  (checked on MariaDB 11.3 and MySQL 8.3)
- `utf8mb4_bin` and `ascii_bin` columns aren't enough: they still ignore trailing spaces.
  `utf8mb4_0900_bin` is exact, but only MySQL 8.0+ and MariaDB 11.4+ have it

The cast fixes a lookup on an existing column. For a column that only ever holds generated
values, `VARBINARY` makes every query on it exact.

## Full Results

Every check on every server is in [exact-match-matrix-data.md](data/exact-match-matrix-data.md):
which stored and sent pairs each form matches on each column type, the query plans, the
index sizes, and the speed and rows read of each form.

## Regenerating This Report

This page and its data file are generated, so change them in the script and not by
hand. The sections above are in the heredocs in .github/scripts/exact-match-probe.php.
The Exact Match Matrix workflow runs the probe on every server in .github/db-matrix.json.

    gh workflow run exact-match-matrix.yml   # wait for the run to finish, then:
    gh run download --dir probes             # pick the latest Exact Match Matrix run
    php .github/scripts/exact-match-probe.php --merge probes/*/exact-match-probe-*.json > docs/internal/exact-match-matrix.md
    php .github/scripts/exact-match-probe.php --merge --data probes/*/exact-match-probe-*.json > docs/internal/data/exact-match-matrix-data.md
