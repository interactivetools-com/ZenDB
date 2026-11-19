# Exact Match Matrix

`col = ?` treats some different strings as equal: most collations ignore trailing spaces,
and `_ci` collations also ignore case and accents, so `token = ' '` finds a row where
`token` is ''. This page shows how to write a comparison that matches only the identical
value, and what it costs next to `col = ?`, on every database server in the CI matrix.

Generated 2026-11-18 from 19 servers by the Exact Match Matrix workflow. Generated file - don't hand-edit. The sections above Full Results are in the heredoc in .github/scripts/exact-match-probe.php; how to regenerate the page is at the bottom.

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

Every check on every server. Servers that gave the same answer share one table.

Forms, with `?` sent the way ZenDB sends it (escaped, in quotes, text protocol). `a` is
the plain form, the baseline the others are compared with:

    a   col = ?
    b1  col = CAST(? AS BINARY)
    b2  col = BINARY ?
    c   CAST(col AS BINARY) = ?
    d   col = ? COLLATE utf8mb4_bin
    e   col LIKE ?
    f   col = ? AND col = CAST(? AS BINARY)
    g1  col = ? COLLATE utf8mb4_0900_bin     // NO PAD, MySQL 8.0.17+
    g2  col = ? COLLATE utf8mb4_0900_as_cs   // NO PAD, MySQL 8.0+
    g3  col = ? COLLATE utf8mb4_nopad_bin    // NO PAD, MariaDB

Cells: yes/no = matched or not, E1234 = error code, W1234 = warning code, n/a = the
server has no such collation, or the column can't hold the stored value as sent.
Plans: EXPLAIN type, key, estimated rows. Rows read: the Handler_read counters for one
lookup.

### Facts

- connection collation:
  - `utf8mb4_general_ci`: MySQL/Percona 5.7 and MariaDB thru 10.11
  - `utf8mb4_0900_ai_ci`: MySQL/Percona 8.0+
  - `utf8mb4_uca1400_ai_ci`: MariaDB 11.4+
- server sql_mode:
  - `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`: MySQL/Percona 5.7
  - `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`: MySQL/Percona 8.0+
  - `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`: all MariaDB
- column types tested:
  - `utf8mb4_unicode_ci, utf8mb4_general_ci, utf8mb4_bin, ascii_general_ci, ascii_bin, latin1_swedish_ci, VARBINARY, CHAR utf8mb4_unicode_ci, TEXT utf8mb4_unicode_ci`: MySQL/Percona 5.7
  - `utf8mb4_unicode_ci, utf8mb4_general_ci, utf8mb4_bin, ascii_general_ci, ascii_bin, latin1_swedish_ci, utf8mb4_0900_ai_ci, utf8mb4_0900_bin, VARBINARY, CHAR utf8mb4_unicode_ci, TEXT utf8mb4_unicode_ci`: MySQL/Percona 8.0+
  - `utf8mb4_unicode_ci, utf8mb4_general_ci, utf8mb4_bin, ascii_general_ci, ascii_bin, latin1_swedish_ci, utf8mb4_nopad_bin, VARBINARY, CHAR utf8mb4_unicode_ci, TEXT utf8mb4_unicode_ci`: MariaDB thru 10.11
  - `utf8mb4_unicode_ci, utf8mb4_general_ci, utf8mb4_bin, ascii_general_ci, ascii_bin, latin1_swedish_ci, utf8mb4_0900_ai_ci, utf8mb4_0900_bin, utf8mb4_nopad_bin, VARBINARY, CHAR utf8mb4_unicode_ci, TEXT utf8mb4_unicode_ci`: MariaDB 11.4+
- literal: BINARY 'a' = 'A':
  - `0`: MySQL/Percona 5.7 and all MariaDB
  - `0 W1287`: MySQL/Percona 8.0+
- literal: 'a' = BINARY 'a ':
  - `0`: MySQL/Percona 5.7 and all MariaDB
  - `0 W1287`: MySQL/Percona 8.0+
- literal: 'a' = CAST('a ' AS BINARY): `0`
- literal: CAST('a' AS BINARY) = 'A': `0`
- literal: 'a' = CONVERT('a ' USING binary): `0`
- literal: 'a' = _binary'a ': `0`
- literal: 'a' = 'a ':
  - `1`: MySQL/Percona 5.7 and all MariaDB
  - `0`: MySQL/Percona 8.0+
- literal: CAST(NULL AS BINARY) IS NULL: `1`
- prepared: 'a' = BINARY ? with 'a ': `0`
- prepared: 'a' = CAST(? AS BINARY) with 'a ': `0`
- BINARY warning text:
  - `(none)`: MySQL/Percona 5.7 and all MariaDB
  - `1287 'BINARY expr' is deprecated and will be removed in a future release. Please use CAST instead`: MySQL/Percona 8.0+
- speed test rows: `100000`

### compare: utf8mb4_unicode_ci column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | yes | no | no W1287 | no | no | no | no | no | yes | n/a |
| '' / NUL | yes | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| ss / ß | yes | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | no |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | no | no | no |
| trailing space sent | yes | no | no | no | yes | no | no | no | no | no |
| trailing space stored | yes | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | yes | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | yes | no | no | no | no | no | no | no | yes | no |
| '' / NUL | yes | no | no | no | no | no | no | no | yes | no |
| e / é | yes | no | no | no | no | yes | no | no | no | no |
| ss / ß | yes | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### compare: utf8mb4_general_ci column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| ss / ß | no | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | no |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | no | no | no |
| trailing space sent | yes | no | no | no | yes | no | no | no | no | no |
| trailing space stored | yes | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | yes | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | no | no | no | no | no | no | no | no | yes | no |
| '' / NUL | no | no | no | no | no | no | no | no | yes | no |
| e / é | yes | no | no | no | no | yes | no | no | no | no |
| ss / ß | no | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### compare: utf8mb4_bin column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | n/a |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | no | no | no | no | no | no | no | n/a | n/a | n/a |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no W1287 | no | no | no | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | no | no | no W1287 | no | no | no | no | no | no | n/a |
| ss / ß | no | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | no | no | no | no | no | no | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | no |
| e / é | no | no | no | no | no | no | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | no | no | no |
| trailing space sent | yes | no | no | no | yes | no | no | no | no | no |
| trailing space stored | yes | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | yes | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | no | no | no | no | no | no | no | no | yes | no |
| '' / NUL | no | no | no | no | no | no | no | no | yes | no |
| e / é | no | no | no | no | no | no | no | no | no | no |
| ss / ß | no | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### compare: ascii_general_ci column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | n/a |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | n/a |
| ss / ß | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On mysql:8.0, mysql:8.4, percona/percona-server:8.0, percona/percona-server:8.4 (4 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | E1267 | no | no W1287 | no | no | E1267 | E1267 | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | E1267 | no | no W1287 | no | no | E1267 | E1267 | no | no | n/a |
| ss / ß | E1267 | no | no W1287 | no | no | E1267 | E1267 | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On MySQL/Percona 9.6+ (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | E3854 | no | no W1287 | no | no | E3854 | E3854 | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | E3854 | no | no W1287 | no | no | E3854 | E3854 | no | no | n/a |
| ss / ß | E3854 | no | no W1287 | no | no | E3854 | E3854 | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| ss / ß | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | no |
| e / é | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| ss / ß | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | no | no | no |
| trailing space sent | yes | no | no | no | yes | no | no | no | no | no |
| trailing space stored | yes | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | yes | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | no | yes | no |
| '' / NUL | no | no | no | no | no | no | no | no | yes | no |
| e / é | E1267 | no | no | no | no | E1267 | E1267 | no | no | no |
| ss / ß | E1267 | no | no | no | no | E1267 | E1267 | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |


### compare: ascii_bin column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | n/a |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | n/a |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | n/a |
| ss / ß | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On mysql:8.0, mysql:8.4, percona/percona-server:8.0, percona/percona-server:8.4 (4 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no W1287 | no | no | no | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | E1267 | no | no W1287 | no | no | E1267 | E1267 | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | E1267 | no | no W1287 | no | no | E1267 | E1267 | no | no | n/a |
| ss / ß | E1267 | no | no W1287 | no | no | E1267 | E1267 | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On MySQL/Percona 9.6+ (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no W1287 | no | no | no | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | E3854 | no | no W1287 | no | no | E3854 | E3854 | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | E3854 | no | no W1287 | no | no | E3854 | E3854 | no | no | n/a |
| ss / ß | E3854 | no | no W1287 | no | no | E3854 | E3854 | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| ss / ß | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | no |
| e / é | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| ss / ß | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | no | no | no |
| trailing space sent | yes | no | no | no | yes | no | no | no | no | no |
| trailing space stored | yes | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | yes | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | no | yes | no |
| '' / NUL | no | no | no | no | no | no | no | no | yes | no |
| e / é | E1267 | no | no | no | no | E1267 | E1267 | no | no | no |
| ss / ß | E1267 | no | no | no | no | E1267 | E1267 | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |


### compare: latin1_swedish_ci column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | n/a |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | yes | no | no | no | yes | yes | no | n/a | n/a | n/a |

On mysql:8.0, mysql:8.4, percona/percona-server:8.0, percona/percona-server:8.4 (4 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | E1267 | no | no W1287 | no | no | E1267 | E1267 | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| ss / ß | no | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | no | no W1287 | no | yes | yes | no | yes | yes | n/a |

On MySQL/Percona 9.6+ (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | E3854 | no | no W1287 | no | no | E3854 | E3854 | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| ss / ß | no | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | no | no W1287 | no | yes | yes | no | yes | yes | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | no | no | no | yes | yes | no | n/a | n/a | yes |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | no |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | no | no | no | yes | yes | no | n/a | n/a | yes |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | no | no | no |
| trailing space sent | yes | no | no | no | yes | no | no | no | no | no |
| trailing space stored | yes | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | yes | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | E1267 | no | no | no | no | E1267 | E1267 | no | yes | no |
| '' / NUL | no | no | no | no | no | no | no | no | yes | no |
| e / é | yes | no | no | no | no | yes | no | no | no | no |
| ss / ß | no | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | no | no | no | yes | yes | no | yes | yes | yes |


### compare: VARBINARY column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | n/a |
| trailing space sent | no | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | no | no | no | no | yes | no | no | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | no | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | no | no | no | no | no | no | no | n/a | n/a | n/a |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no W1287 | no | no | no | no | no | no | n/a |
| trailing space sent | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | no | no | no W1287 | no | no | no | no | no | no | n/a |
| ss / ß | no | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | no |
| trailing space sent | no | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | no | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | no | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | no | no | no | no | no | no | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | no |
| trailing space sent | no | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | no | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | no | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | no |
| e / é | no | no | no | no | no | no | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | no | no | no |
| trailing space sent | no | no | no | no | yes | no | no | no | no | no |
| trailing space stored | no | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | no | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | no | no | no | no | no | no | no | no | yes | no |
| '' / NUL | no | no | no | no | no | no | no | no | yes | no |
| e / é | no | no | no | no | no | no | no | no | no | no |
| ss / ß | no | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### compare: CHAR utf8mb4_unicode_ci column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | yes | no | no W1287 | no | no | no | no | no | yes | n/a |
| '' / NUL | yes | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| ss / ß | yes | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | no |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | no | no | no |
| trailing space sent | yes | no | no | no | yes | no | no | no | no | no |
| trailing space stored | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | yes | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | yes | no | no | no | no | no | no | no | yes | no |
| '' / NUL | yes | no | no | no | no | no | no | no | yes | no |
| e / é | yes | no | no | no | no | yes | no | no | no | no |
| ss / ß | yes | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### compare: TEXT utf8mb4_unicode_ci column

On MySQL/Percona 5.7 (2 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | n/a |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | n/a |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | n/a |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | yes | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | yes | no | no W1287 | no | no | no | no | no | yes | n/a |
| '' / NUL | yes | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| ss / ß | yes | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | n/a | n/a | no |
| trailing space sent | yes | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | yes | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | yes | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | yes | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | no |
| e / é | yes | no | no | no | no | yes | no | n/a | n/a | no |
| ss / ß | yes | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | no | no | no |
| trailing space sent | yes | no | no | no | yes | no | no | no | no | no |
| trailing space stored | yes | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | yes | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | yes | no | no | no | no | no | no | no | yes | no |
| '' / NUL | yes | no | no | no | no | no | no | no | yes | no |
| e / é | yes | no | no | no | no | yes | no | no | no | no |
| ss / ß | yes | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### unique index

On MySQL/Percona 5.7 (2 servers):

|  | a / 'a ' | a / A | '' / ' ' | '' / NUL | e / é | ss / ß |
|---|---|---|---|---|---|---|
| utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| utf8mb4_general_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_bin | collide | distinct | collide | distinct | distinct | distinct |
| ascii_general_ci | collide | collide | collide | distinct | E1366 | E1366 |
| ascii_bin | collide | distinct | collide | distinct | E1366 | E1366 |
| latin1_swedish_ci | collide | collide | collide | distinct | collide | distinct |
| VARBINARY | distinct | distinct | distinct | distinct | distinct | distinct |
| CHAR utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| TEXT utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |

On MySQL/Percona 8.0+ (6 servers):

|  | a / 'a ' | a / A | '' / ' ' | '' / NUL | e / é | ss / ß |
|---|---|---|---|---|---|---|
| utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| utf8mb4_general_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_bin | collide | distinct | collide | distinct | distinct | distinct |
| ascii_general_ci | collide | collide | collide | distinct | E1366 | E1366 |
| ascii_bin | collide | distinct | collide | distinct | E1366 | E1366 |
| latin1_swedish_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_0900_ai_ci | distinct | collide | distinct | collide | collide | collide |
| utf8mb4_0900_bin | distinct | distinct | distinct | distinct | distinct | distinct |
| VARBINARY | distinct | distinct | distinct | distinct | distinct | distinct |
| CHAR utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| TEXT utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |

On MariaDB thru 10.4 (5 servers):

|  | a / 'a ' | a / A | '' / ' ' | '' / NUL | e / é | ss / ß |
|---|---|---|---|---|---|---|
| utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| utf8mb4_general_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_bin | collide | distinct | collide | distinct | distinct | distinct |
| ascii_general_ci | collide | collide | collide | distinct | E1366 | E1366 |
| ascii_bin | collide | distinct | collide | distinct | E1366 | E1366 |
| latin1_swedish_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_nopad_bin | distinct | distinct | distinct | collide | distinct | distinct |
| VARBINARY | distinct | distinct | distinct | distinct | distinct | distinct |
| CHAR utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| TEXT utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a / 'a ' | a / A | '' / ' ' | '' / NUL | e / é | ss / ß |
|---|---|---|---|---|---|---|
| utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| utf8mb4_general_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_bin | collide | distinct | collide | distinct | distinct | distinct |
| ascii_general_ci | collide | collide | collide | distinct | E1366 | E1366 |
| ascii_bin | collide | distinct | collide | distinct | E1366 | E1366 |
| latin1_swedish_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_nopad_bin | distinct | distinct | distinct | distinct | distinct | distinct |
| VARBINARY | distinct | distinct | distinct | distinct | distinct | distinct |
| CHAR utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| TEXT utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |

On MariaDB 11.4+ (3 servers):

|  | a / 'a ' | a / A | '' / ' ' | '' / NUL | e / é | ss / ß |
|---|---|---|---|---|---|---|
| utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| utf8mb4_general_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_bin | collide | distinct | collide | distinct | distinct | distinct |
| ascii_general_ci | collide | collide | collide | distinct | E1366 | E1366 |
| ascii_bin | collide | distinct | collide | distinct | E1366 | E1366 |
| latin1_swedish_ci | collide | collide | collide | distinct | collide | distinct |
| utf8mb4_0900_ai_ci | distinct | collide | distinct | collide | collide | collide |
| utf8mb4_0900_bin | distinct | distinct | distinct | distinct | distinct | distinct |
| utf8mb4_nopad_bin | distinct | distinct | distinct | distinct | distinct | distinct |
| VARBINARY | distinct | distinct | distinct | distinct | distinct | distinct |
| CHAR utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |
| TEXT utf8mb4_unicode_ci | collide | collide | collide | collide | collide | collide |


### numbers

On MySQL/Percona 5.7 (2 servers):

|  | ZenDB default | not strict |
|---|---|---|
| i = '5abc' SELECT | ids 2 W1292 | ids 2 W1292 |
| i = '5abc' UPDATE | E1292 | 1 rows W1292 |
| i = '5abc' DELETE | E1292 | 1 rows W1292 |
| i = ' 5' SELECT | ids 2 | ids 2 |
| i = ' 5' UPDATE | 1 rows | 1 rows |
| i = ' 5' DELETE | 1 rows | 1 rows |
| i = '5 ' SELECT | ids 2 | ids 2 |
| i = '5 ' UPDATE | 1 rows | 1 rows |
| i = '5 ' DELETE | 1 rows | 1 rows |
| i = '' SELECT | ids 1 | ids 1 |
| i = '' UPDATE | 1 rows | 1 rows |
| i = '' DELETE | 1 rows | 1 rows |
| i = 'abc' SELECT | ids 1 W1292 | ids 1 W1292 |
| i = 'abc' UPDATE | E1292 | 1 rows W1292 |
| i = 'abc' DELETE | E1292 | 1 rows W1292 |
| i = '1e1' SELECT | ids 3 | ids 3 |
| i = '1e1' UPDATE | 1 rows | 1 rows |
| i = '1e1' DELETE | 1 rows | 1 rows |
| i = CAST('5' AS BINARY) SELECT | ids 2 | ids 2 |
| i = CAST('5' AS BINARY) UPDATE | 1 rows | 1 rows |
| i = CAST('5' AS BINARY) DELETE | 1 rows | 1 rows |
| i = CAST('5abc' AS BINARY) SELECT | ids 2 | ids 2 |
| i = CAST('5abc' AS BINARY) UPDATE | 1 rows | 1 rows |
| i = CAST('5abc' AS BINARY) DELETE | 1 rows | 1 rows |
| i = CAST('' AS BINARY) SELECT | ids 1 | ids 1 |
| i = CAST('' AS BINARY) UPDATE | 1 rows | 1 rows |
| i = CAST('' AS BINARY) DELETE | 1 rows | 1 rows |
| v = 0 SELECT | ids 1,2,5,7 W1292 | ids 1,2,5,7 W1292 |
| v = 0 UPDATE | E1292 | 4 rows W1292 |
| v = 0 DELETE | E1292 | 4 rows W1292 |
| v = 1 SELECT | ids 3,4,6 W1292 | ids 3,4,6 W1292 |
| v = 1 UPDATE | E1292 | 3 rows W1292 |
| v = 1 DELETE | E1292 | 3 rows W1292 |
| v = TRUE SELECT | ids 3,4,6 W1292 | ids 3,4,6 W1292 |
| v = TRUE UPDATE | E1292 | 3 rows W1292 |
| v = TRUE DELETE | E1292 | 3 rows W1292 |
| v = CAST('1' AS BINARY) SELECT | ids 3 | ids 3 |
| v = CAST('1' AS BINARY) UPDATE | 1 rows | 1 rows |
| v = CAST('1' AS BINARY) DELETE | 1 rows | 1 rows |

On MySQL/Percona 8.0+ (6 servers):

|  | ZenDB default | not strict |
|---|---|---|
| i = '5abc' SELECT | ids 2 W1292 | ids 2 W1292 |
| i = '5abc' UPDATE | E1292 | 1 rows W1292 |
| i = '5abc' DELETE | E1292 | 1 rows W1292 |
| i = ' 5' SELECT | ids 2 | ids 2 |
| i = ' 5' UPDATE | 1 rows | 1 rows |
| i = ' 5' DELETE | 1 rows | 1 rows |
| i = '5 ' SELECT | ids 2 | ids 2 |
| i = '5 ' UPDATE | 1 rows | 1 rows |
| i = '5 ' DELETE | 1 rows | 1 rows |
| i = '' SELECT | ids 1 | ids 1 |
| i = '' UPDATE | 1 rows | 1 rows |
| i = '' DELETE | 1 rows | 1 rows |
| i = 'abc' SELECT | ids 1 W1292 | ids 1 W1292 |
| i = 'abc' UPDATE | E1292 | 1 rows W1292 |
| i = 'abc' DELETE | E1292 | 1 rows W1292 |
| i = '1e1' SELECT | ids 3 | ids 3 |
| i = '1e1' UPDATE | 1 rows | 1 rows |
| i = '1e1' DELETE | 1 rows | 1 rows |
| i = CAST('5' AS BINARY) SELECT | ids 2 | ids 2 |
| i = CAST('5' AS BINARY) UPDATE | 1 rows | 1 rows |
| i = CAST('5' AS BINARY) DELETE | 1 rows | 1 rows |
| i = CAST('5abc' AS BINARY) SELECT | ids 2 W1292 | ids 2 W1292 |
| i = CAST('5abc' AS BINARY) UPDATE | E1292 | 1 rows W1292 |
| i = CAST('5abc' AS BINARY) DELETE | E1292 | 1 rows W1292 |
| i = CAST('' AS BINARY) SELECT | ids 1 | ids 1 |
| i = CAST('' AS BINARY) UPDATE | 1 rows | 1 rows |
| i = CAST('' AS BINARY) DELETE | 1 rows | 1 rows |
| v = 0 SELECT | ids 1,2,5,7 W1292 | ids 1,2,5,7 W1292 |
| v = 0 UPDATE | E1292 | 4 rows W1292 |
| v = 0 DELETE | E1292 | 4 rows W1292 |
| v = 1 SELECT | ids 3,4,6 W1292 | ids 3,4,6 W1292 |
| v = 1 UPDATE | E1292 | 3 rows W1292 |
| v = 1 DELETE | E1292 | 3 rows W1292 |
| v = TRUE SELECT | ids 3,4,6 W1292 | ids 3,4,6 W1292 |
| v = TRUE UPDATE | E1292 | 3 rows W1292 |
| v = TRUE DELETE | E1292 | 3 rows W1292 |
| v = CAST('1' AS BINARY) SELECT | ids 3 | ids 3 |
| v = CAST('1' AS BINARY) UPDATE | 1 rows | 1 rows |
| v = CAST('1' AS BINARY) DELETE | 1 rows | 1 rows |

On all MariaDB (11 servers):

|  | ZenDB default | not strict |
|---|---|---|
| i = '5abc' SELECT | ids 2 W1292 | ids 2 W1292 |
| i = '5abc' UPDATE | E1292 | 1 rows W1292 |
| i = '5abc' DELETE | 1 rows W1292 | 1 rows W1292 |
| i = ' 5' SELECT | ids 2 | ids 2 |
| i = ' 5' UPDATE | 1 rows | 1 rows |
| i = ' 5' DELETE | 1 rows | 1 rows |
| i = '5 ' SELECT | ids 2 W1292 | ids 2 W1292 |
| i = '5 ' UPDATE | 1 rows W1292 | 1 rows W1292 |
| i = '5 ' DELETE | 1 rows W1292 | 1 rows W1292 |
| i = '' SELECT | ids 1 W1292 | ids 1 W1292 |
| i = '' UPDATE | E1292 | 1 rows W1292 |
| i = '' DELETE | 1 rows W1292 | 1 rows W1292 |
| i = 'abc' SELECT | ids 1 W1292 | ids 1 W1292 |
| i = 'abc' UPDATE | E1292 | 1 rows W1292 |
| i = 'abc' DELETE | 1 rows W1292 | 1 rows W1292 |
| i = '1e1' SELECT | ids 3 | ids 3 |
| i = '1e1' UPDATE | 1 rows | 1 rows |
| i = '1e1' DELETE | 1 rows | 1 rows |
| i = CAST('5' AS BINARY) SELECT | ids 2 | ids 2 |
| i = CAST('5' AS BINARY) UPDATE | 1 rows | 1 rows |
| i = CAST('5' AS BINARY) DELETE | 1 rows | 1 rows |
| i = CAST('5abc' AS BINARY) SELECT | ids 2 W1292 | ids 2 W1292 |
| i = CAST('5abc' AS BINARY) UPDATE | E1292 | 1 rows W1292 |
| i = CAST('5abc' AS BINARY) DELETE | 1 rows W1292 | 1 rows W1292 |
| i = CAST('' AS BINARY) SELECT | ids 1 W1292 | ids 1 W1292 |
| i = CAST('' AS BINARY) UPDATE | E1292 | 1 rows W1292 |
| i = CAST('' AS BINARY) DELETE | 1 rows W1292 | 1 rows W1292 |
| v = 0 SELECT | ids 1,2,5,7 W1292 | ids 1,2,5,7 W1292 |
| v = 0 UPDATE | E1292 | 4 rows W1292 |
| v = 0 DELETE | 4 rows W1292 | 4 rows W1292 |
| v = 1 SELECT | ids 3,4,6 W1292 | ids 3,4,6 W1292 |
| v = 1 UPDATE | E1292 | 3 rows W1292 |
| v = 1 DELETE | 3 rows W1292 | 3 rows W1292 |
| v = TRUE SELECT | ids 3,4,6 W1292 | ids 3,4,6 W1292 |
| v = TRUE UPDATE | E1292 | 3 rows W1292 |
| v = TRUE DELETE | 3 rows W1292 | 3 rows W1292 |
| v = CAST('1' AS BINARY) SELECT | ids 3 | ids 3 |
| v = CAST('1' AS BINARY) UPDATE | 1 rows | 1 rows |
| v = CAST('1' AS BINARY) DELETE | 1 rows | 1 rows |


### plan: utf8mb4_unicode_ci

On MySQL/Percona 5.7 (2 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | n/a | n/a | n/a |

On mysql:8.0, mysql:8.4, mysql:9.7, percona/percona-server:8.0, percona/percona-server:8.4 (5 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | range prefix_col W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col W1287 | range unique_col W1287 | range prefix_col W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On mysql:9.6 (1 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | ALL - W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | ALL - |
| b1 IN | range plain_col | range unique_col | ALL - |
| b2 IN | range plain_col W1287 | range unique_col W1287 | ALL - W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | ALL - |
| f IN | range plain_col | range unique_col | ALL - |
| g1 IN | range plain_col | range unique_col | ALL - |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On MariaDB thru 10.11 (8 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | ref plain_col | const unique_col | ref prefix_col |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | range plain_col | range unique_col | range prefix_col |

On MariaDB 11.4+ (3 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | ref plain_col | const unique_col | ref prefix_col |
| g2 | index plain_col W1105 | index unique_col W1105 | ALL - W1105 |
| g3 | ref plain_col | const unique_col | ref prefix_col |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1105 | index unique_col W1105 | ALL - W1105 |
| g3 IN | range plain_col | range unique_col | range prefix_col |


### plan: utf8mb4_general_ci

On MySQL/Percona 5.7 (2 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | n/a | n/a | n/a |

On mysql:8.0, mysql:8.4, mysql:9.7, percona/percona-server:8.0, percona/percona-server:8.4 (5 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | range prefix_col W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col W1287 | range unique_col W1287 | range prefix_col W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On mysql:9.6 (1 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | ALL - W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | ALL - |
| b1 IN | range plain_col | range unique_col | ALL - |
| b2 IN | range plain_col W1287 | range unique_col W1287 | ALL - W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | ALL - |
| f IN | range plain_col | range unique_col | ALL - |
| g1 IN | range plain_col | range unique_col | ALL - |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On MariaDB thru 10.11 (8 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | ref plain_col | const unique_col | ref prefix_col |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | range plain_col | range unique_col | range prefix_col |

On MariaDB 11.4+ (3 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | ref plain_col | const unique_col | ref prefix_col |
| g2 | index plain_col W1105 | index unique_col W1105 | ALL - W1105 |
| g3 | ref plain_col | const unique_col | ref prefix_col |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1105 | index unique_col W1105 | ALL - W1105 |
| g3 IN | range plain_col | range unique_col | range prefix_col |


### plan: utf8mb4_bin

On MySQL/Percona 5.7 (2 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | range prefix_col W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col W1287 | range unique_col W1287 | range prefix_col W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On MariaDB thru 10.11 (8 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | ref plain_col | const unique_col | ref prefix_col |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | range plain_col | range unique_col | range prefix_col |

On MariaDB 11.4+ (3 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | ref plain_col | const unique_col | ref prefix_col |
| g2 | index plain_col W1105 | index unique_col W1105 | ALL - W1105 |
| g3 | ref plain_col | const unique_col | ref prefix_col |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1105 | index unique_col W1105 | ALL - W1105 |
| g3 IN | range plain_col | range unique_col | range prefix_col |


### plan: ascii_general_ci

On MySQL/Percona 5.7 (2 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | range prefix_col W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col W1287 | range unique_col W1287 | range prefix_col W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On MariaDB thru 10.11 (8 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | index plain_col | index unique_col | ALL - |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | index plain_col | index unique_col | ALL - |

On MariaDB 11.4+ (3 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | index plain_col | index unique_col | ALL - |
| g2 | index plain_col | index unique_col | ALL - |
| g3 | index plain_col | index unique_col | ALL - |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | index plain_col | index unique_col | ALL - |
| g2 IN | index plain_col | index unique_col | ALL - |
| g3 IN | index plain_col | index unique_col | ALL - |


### plan: ascii_bin

On MySQL/Percona 5.7 (2 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | range prefix_col W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col W1287 | range unique_col W1287 | range prefix_col W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On MariaDB thru 10.11 (8 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | index plain_col | index unique_col | ALL - |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | index plain_col | index unique_col | ALL - |

On MariaDB 11.4+ (3 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | index plain_col | index unique_col | ALL - |
| g2 | index plain_col | index unique_col | ALL - |
| g3 | index plain_col | index unique_col | ALL - |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | index plain_col | index unique_col | ALL - |
| g2 IN | index plain_col | index unique_col | ALL - |
| g3 IN | index plain_col | index unique_col | ALL - |


### plan: latin1_swedish_ci

On MySQL/Percona 5.7 (2 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | range prefix_col W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col W1287 | range unique_col W1287 | range prefix_col W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On MariaDB thru 10.11 (8 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | index plain_col | index unique_col | ALL - |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | index plain_col | index unique_col | ALL - |

On MariaDB 11.4+ (3 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | index plain_col | index unique_col | ALL - |
| g2 | index plain_col | index unique_col | ALL - |
| g3 | index plain_col | index unique_col | ALL - |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | index plain_col | index unique_col | ALL - |
| g2 IN | index plain_col | index unique_col | ALL - |
| g3 IN | index plain_col | index unique_col | ALL - |


### plan: VARBINARY

On MySQL/Percona 5.7 (2 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | n/a | n/a | n/a |

On MySQL/Percona 8.0+ (6 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col W1287 | const unique_col W1287 | ref prefix_col W1287 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col W1287 | range unique_col W1287 | range prefix_col W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On MariaDB thru 10.11 (8 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | n/a | n/a | n/a |
| g2 | n/a | n/a | n/a |
| g3 | index plain_col | index unique_col | ALL - |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | n/a | n/a | n/a |
| g2 IN | n/a | n/a | n/a |
| g3 IN | index plain_col | index unique_col | ALL - |

On MariaDB 11.4+ (3 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | index plain_col | index unique_col | ALL - |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | index plain_col | index unique_col | ALL - |
| g2 | index plain_col | index unique_col | ALL - |
| g3 | index plain_col | index unique_col | ALL - |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | index plain_col | index unique_col | ALL - |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | index plain_col | index unique_col | ALL - |
| g2 IN | index plain_col | index unique_col | ALL - |
| g3 IN | index plain_col | index unique_col | ALL - |


### plan: composite (code, token)

On MySQL/Percona 5.7 (2 servers):

|  | plan |
|---|---|
| a both | ref lookup/1279 |
| b1 both | range lookup/1279 W1739 |
| b1 token | range lookup/1279 |
| f both | ref lookup/1279 W1739 |
| d both | index lookup/1279 |

On MySQL/Percona 8.0+ (6 servers):

|  | plan |
|---|---|
| a both | ref lookup/1279 |
| b1 both | range lookup/1279 W1739 |
| b1 token | range lookup/1279 |
| f both | ref lookup/1279 W1739 |
| d both | range lookup/1279 W1739 |

On all MariaDB (11 servers):

|  | plan |
|---|---|
| a both | ref lookup/1279 |
| b1 both | ref lookup/1279 |
| b1 token | ref lookup/1279 |
| f both | ref lookup/1279 |
| d both | index lookup/1279 |


### compare: utf8mb4_0900_ai_ci column

On MySQL/Percona 8.0+ (6 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| trailing space sent | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | yes | no | no W1287 | no | no | no | no | no | yes | n/a |
| '' / NUL | yes | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | yes | no | no W1287 | no | no | yes | no | no | no | n/a |
| ss / ß | yes | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | yes | no | no | no | no | yes | no | no | no | no |
| trailing space sent | no | no | no | no | yes | no | no | no | no | no |
| trailing space stored | no | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | no | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | yes | no | no | no | no | no | no | no | yes | no |
| '' / NUL | yes | no | no | no | no | no | no | no | yes | no |
| e / é | yes | no | no | no | no | yes | no | no | no | no |
| ss / ß | yes | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### compare: utf8mb4_0900_bin column

On MySQL/Percona 8.0+ (6 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no W1287 | no | no | no | no | no | no | n/a |
| trailing space sent | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| trailing space stored | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| leading space sent | no | no | no W1287 | no | no | no | no | no | no | n/a |
| '' / one space | no | no | no W1287 | no | yes | no | no | no | no | n/a |
| '' / U+200B | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| '' / NUL | no | no | no W1287 | no | no | no | no | no | yes | n/a |
| e / é | no | no | no W1287 | no | no | no | no | no | no | n/a |
| ss / ß | no | no | no W1287 | no | no | no | no | no | no | n/a |
| same: abc / abc | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |
| same non-ASCII: é / é | yes | yes | yes W1287 | yes | yes | yes | yes | yes | yes | n/a |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | no | no | no |
| trailing space sent | no | no | no | no | yes | no | no | no | no | no |
| trailing space stored | no | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | no | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | no | no | no | no | no | no | no | no | yes | no |
| '' / NUL | no | no | no | no | no | no | no | no | yes | no |
| e / é | no | no | no | no | no | no | no | no | no | no |
| ss / ß | no | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### plan: utf8mb4_0900_ai_ci

On mysql:8.0, mysql:8.4, mysql:9.7, percona/percona-server:8.0, percona/percona-server:8.4 (5 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | range prefix_col W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | range prefix_col W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col W1287 | range unique_col W1287 | range prefix_col W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On mysql:9.6 (1 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| b2 | range plain_col W1287,1739 | range unique_col W1287,1739 | ALL - W1287,1739 |
| c | index plain_col | index unique_col | ALL - |
| d | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col W1739 | const unique_col W1739 | ref prefix_col W1739 |
| g1 | range plain_col W1739 | range unique_col W1739 | ALL - W1739 |
| g2 | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 | n/a | n/a | n/a |
| a IN | range plain_col | range unique_col | ALL - |
| b1 IN | range plain_col | range unique_col | ALL - |
| b2 IN | range plain_col W1287 | range unique_col W1287 | ALL - W1287 |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | ALL - |
| f IN | range plain_col | range unique_col | ALL - |
| g1 IN | range plain_col | range unique_col | ALL - |
| g2 IN | index plain_col W1739 | index unique_col W1739 | ALL - W1739 |
| g3 IN | n/a | n/a | n/a |

On MariaDB 11.4+ (3 servers):

|  | plain | unique | prefix |
|---|---|---|---|
| a | ref plain_col | const unique_col | ref prefix_col |
| b1 | ref plain_col | const unique_col | ref prefix_col |
| b2 | ref plain_col | const unique_col | ref prefix_col |
| c | index plain_col | index unique_col | ALL - |
| d | ref plain_col | const unique_col | ref prefix_col |
| e | range plain_col | range unique_col | range prefix_col |
| f | ref plain_col | const unique_col | ref prefix_col |
| g1 | ref plain_col | const unique_col | ref prefix_col |
| g2 | index plain_col W1105 | index unique_col W1105 | ALL - W1105 |
| g3 | ref plain_col | const unique_col | ref prefix_col |
| a IN | range plain_col | range unique_col | range prefix_col |
| b1 IN | range plain_col | range unique_col | range prefix_col |
| b2 IN | range plain_col | range unique_col | range prefix_col |
| c IN | index plain_col | index unique_col | ALL - |
| d IN | range plain_col | range unique_col | range prefix_col |
| f IN | range plain_col | range unique_col | range prefix_col |
| g1 IN | range plain_col | range unique_col | range prefix_col |
| g2 IN | index plain_col W1105 | index unique_col W1105 | ALL - W1105 |
| g3 IN | range plain_col | range unique_col | range prefix_col |


### compare: utf8mb4_nopad_bin column

On MariaDB thru 10.4 (5 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | no |
| trailing space sent | no | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | no | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | no | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | yes | no | no | no | no | no | no | n/a | n/a | yes |
| e / é | no | no | no | no | no | no | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On mariadb:10.5, mariadb:10.6, mariadb:10.11 (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | n/a | n/a | no |
| trailing space sent | no | no | no | no | yes | no | no | n/a | n/a | no |
| trailing space stored | no | no | no | no | yes | no | no | n/a | n/a | no |
| leading space sent | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / one space | no | no | no | no | yes | no | no | n/a | n/a | no |
| '' / U+200B | no | no | no | no | no | no | no | n/a | n/a | no |
| '' / NUL | no | no | no | no | no | no | no | n/a | n/a | no |
| e / é | no | no | no | no | no | no | no | n/a | n/a | no |
| ss / ß | no | no | no | no | no | no | no | n/a | n/a | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | n/a | n/a | yes |

On MariaDB 11.4+ (3 servers):

|  | a | b1 | b2 | c | d | e | f | g1 | g2 | g3 |
|---|---|---|---|---|---|---|---|---|---|---|
| case: abc / ABC | no | no | no | no | no | no | no | no | no | no |
| trailing space sent | no | no | no | no | yes | no | no | no | no | no |
| trailing space stored | no | no | no | no | yes | no | no | no | no | no |
| leading space sent | no | no | no | no | no | no | no | no | no | no |
| '' / one space | no | no | no | no | yes | no | no | no | no | no |
| '' / U+200B | no | no | no | no | no | no | no | no | yes | no |
| '' / NUL | no | no | no | no | no | no | no | no | yes | no |
| e / é | no | no | no | no | no | no | no | no | no | no |
| ss / ß | no | no | no | no | no | no | no | no | no | no |
| same: abc / abc | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| same non-ASCII: é / é | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |


### Index Size (KB, median over servers)

|  | PRIMARY | plain_col | unique_col | prefix_col |
|---|---|---|---|---|
| utf8mb4_unicode_ci | 10768 | 5648 | 5648 | 4624 |
| utf8mb4_general_ci | 10768 | 5648 | 5648 | 4624 |
| utf8mb4_bin | 10768 | 5648 | 5648 | 4624 |
| ascii_general_ci | 10768 | 5648 | 5648 | 4624 |
| ascii_bin | 10768 | 5648 | 5648 | 4624 |
| latin1_swedish_ci | 10768 | 5648 | 5648 | 4624 |
| VARBINARY | 10768 | 5648 | 5648 | 4624 |
| utf8mb4_0900_ai_ci | 10768 | 5648 | 5648 | 4624 |

### Speed and Rows Read (median over servers)

#### utf8mb4_unicode_ci, plain

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 135.4 | 1x | 2 | 64.2 (mariadb:11.8) | 226.3 (mysql:8.0) |
| b1 | 141.9 | 1.05x | 2 | 63.9 (mariadb:11.8) | 213.4 (mysql:8.0) |
| b2 | 135 | 1x | 2 | 64.6 (mariadb:11.8) | 225.1 (mysql:9.6) |
| c | 12758.5 | 94.23x | 100001 | 6916 (mariadb:11.8) | 24699.7 (mysql:9.6) |
| d | 141.8 | 1.05x | 2 | 65 (mariadb:11.8) | 225.6 (percona/percona-server:8.0) |
| e | 128.6 | 0.95x | 2 | 63.9 (mariadb:11.8) | 220.1 (percona/percona-server:8.0) |
| f | 148.7 | 1.1x | 2 | 69.4 (mariadb:11.4) | 232.3 (percona/percona-server:8.0) |
| a IN | 182.9 | 1x | 10 | 78.4 (mariadb:11.4) | 289.5 (percona/percona-server:8.0) |
| b1 IN | 188.4 | 1.03x | 10 | 81.4 (mariadb:11.8) | 293.4 (percona/percona-server:8.0) |
| b2 IN | 184.1 | 1.01x | 10 | 81.6 (mariadb:11.4) | 301.5 (percona/percona-server:8.0) |
| c IN | 14737.9 | 80.58x | 100001 | 8036.8 (mariadb:11.8) | 38695.3 (percona/percona-server:8.0) |
| d IN | 191.8 | 1.05x | 10 | 81.8 (mariadb:11.4) | 295.7 (percona/percona-server:8.0) |
| f IN | 211 | 1.15x | 10 | 90.4 (mariadb:11.8) | 360.5 (percona/percona-server:8.0) |
| g1 | 181.5 | 1.34x | 2 | 67.7 (mariadb:11.8) | 228.2 (percona/percona-server:8.0) |
| g2 | 20807.3 | 153.67x | 100002 | 10949.7 (mariadb:11.8) | 25564.6 (mysql:9.6) |
| g1 IN | 232.2 | 1.27x | 10 | 88.2 (mariadb:11.8) | 334.4 (percona/percona-server:8.0) |
| g2 IN | 39055.2 | 213.53x | 100002 | 27514.6 (mariadb:11.8) | 44781.7 (mariadb:12.3) |
| g3 | 136.6 | 1.01x | 2 | 68.6 (mariadb:11.8) | 193.6 (mariadb:10.2.7) |
| g3 IN | 181.9 | 0.99x | 10 | 90.7 (mariadb:11.4) | 284.5 (mariadb:10.2.7) |

#### utf8mb4_unicode_ci, unique

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 115.3 | 1x | 1 | 61.5 (mariadb:11.4) | 189.4 (percona/percona-server:8.0) |
| b1 | 115.6 | 1x | 1 | 58.3 (mariadb:11.8) | 207.7 (percona/percona-server:8.0) |
| b2 | 120.2 | 1.04x | 1 | 60.5 (mariadb:11.8) | 208.5 (mysql:8.0) |
| c | 12690.3 | 110.06x | 100001 | 7307.3 (mariadb:11.4) | 24737.3 (mysql:9.6) |
| d | 116.2 | 1.01x | 1 | 61.2 (mariadb:11.8) | 212.4 (mysql:9.6) |
| e | 125.3 | 1.09x | 1 | 60.7 (mariadb:11.8) | 215.7 (mysql:9.6) |
| f | 122.9 | 1.07x | 1 | 60 (mariadb:11.8) | 214.9 (mysql:9.6) |
| a IN | 142.5 | 1x | 5 | 72.3 (mariadb:11.8) | 228.3 (mysql:9.6) |
| b1 IN | 150.9 | 1.06x | 5 | 72.2 (mariadb:11.8) | 232.4 (mysql:9.6) |
| b2 IN | 151.2 | 1.06x | 5 | 74.6 (mariadb:11.4) | 236.3 (mysql:9.6) |
| c IN | 14830.4 | 104.07x | 100001 | 8003 (mariadb:11.8) | 26261.5 (mysql:9.6) |
| d IN | 157.2 | 1.1x | 5 | 77.5 (mariadb:11.4) | 241.5 (mysql:9.6) |
| f IN | 167 | 1.17x | 5 | 80.1 (mariadb:11.4) | 264.5 (mysql:9.6) |
| g1 | 168.4 | 1.46x | 1 | 60.9 (mariadb:11.8) | 211 (mysql:9.6) |
| g2 | 20786.9 | 180.29x | 100002 | 10896.1 (mariadb:11.8) | 25771.8 (mysql:9.6) |
| g1 IN | 202.6 | 1.42x | 5 | 75.8 (mariadb:11.8) | 250.3 (mysql:8.0) |
| g2 IN | 38515.1 | 270.28x | 100002 | 25783 (mariadb:11.8) | 44920.3 (mariadb:12.3) |
| g3 | 112.7 | 0.98x | 1 | 64 (mariadb:11.4) | 174.3 (mariadb:10.6) |
| g3 IN | 153.6 | 1.08x | 5 | 80.5 (mariadb:11.8) | 229.5 (mariadb:10.6) |

#### utf8mb4_unicode_ci, prefix

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 202.9 | 1x | 53 | 93.7 (mariadb:11.8) | 291.9 (mysql:9.6) |
| b1 | 199.9 | 0.99x | 53 | 91.8 (mariadb:11.8) | 17477 (mysql:9.6) |
| b2 | 199.2 | 0.98x | 53 | 90.8 (mariadb:11.8) | 17444.3 (mysql:9.6) |
| c | 12542.7 | 61.82x | 100001 | 7348 (mariadb:11.8) | 24140.9 (mysql:9.6) |
| d | 201.9 | 1x | 53 | 92.4 (mariadb:11.8) | 18480 (mysql:9.6) |
| e | 207.7 | 1.02x | 53 | 99.3 (mariadb:11.8) | 301.1 (mysql:9.6) |
| f | 205.2 | 1.01x | 53 | 99.7 (mariadb:11.8) | 303.4 (mysql:9.6) |
| a IN | 407.8 | 1x | 257 | 214 (mariadb:11.8) | 35387.1 (mysql:9.6) |
| b1 IN | 366 | 0.9x | 257 | 211.4 (mariadb:11.8) | 20591.5 (mysql:9.6) |
| b2 IN | 377.6 | 0.93x | 257 | 205 (mariadb:11.8) | 20646 (mysql:9.6) |
| c IN | 15442.4 | 37.87x | 100001 | 9408 (mariadb:11.4) | 26804.4 (mysql:9.6) |
| d IN | 380.7 | 0.93x | 257 | 210.5 (mariadb:11.8) | 20585.7 (mysql:9.6) |
| f IN | 461.3 | 1.13x | 257 | 220.1 (mariadb:11.8) | 36094.2 (mysql:9.6) |
| g1 | 261.3 | 1.29x | 53 | 91.4 (mariadb:11.8) | 18357.1 (mysql:9.6) |
| g2 | 21854.1 | 107.71x | 100003 | 11488.5 (mariadb:11.4) | 25829.2 (percona/percona-server:8.0) |
| g1 IN | 497.3 | 1.22x | 257 | 209.7 (mariadb:11.8) | 20514.9 (mysql:9.6) |
| g2 IN | 41492.1 | 101.75x | 100003 | 28242.2 (mariadb:11.4) | 46854.8 (mariadb:12.3) |
| g3 | 194.1 | 0.96x | 53 | 99 (mariadb:11.8) | 273.7 (mariadb:10.6) |
| g3 IN | 360.9 | 0.88x | 257 | 213.4 (mariadb:11.8) | 530.2 (mariadb:10.6) |

#### utf8mb4_general_ci, plain

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 131.5 | 1x | 2 | 64.9 (mariadb:11.8) | 212.6 (mysql:8.0) |
| b1 | 134.9 | 1.03x | 2 | 66.3 (mariadb:11.8) | 215.9 (percona/percona-server:8.0) |
| b2 | 133 | 1.01x | 2 | 67.6 (mariadb:11.4) | 215.2 (mysql:8.0) |
| c | 13091.8 | 99.56x | 100001 | 7252.1 (mariadb:11.4) | 24672.8 (mysql:9.6) |
| d | 132.3 | 1.01x | 2 | 68.6 (mariadb:11.4) | 217.9 (percona/percona-server:8.0) |
| e | 133.2 | 1.01x | 2 | 66.4 (mariadb:11.4) | 214.8 (percona/percona-server:8.0) |
| f | 145.1 | 1.1x | 2 | 71.9 (mariadb:11.4) | 228.9 (percona/percona-server:8.0) |
| a IN | 159.4 | 1x | 10 | 77.1 (mariadb:11.4) | 266.8 (percona/percona-server:8.0) |
| b1 IN | 169.3 | 1.06x | 10 | 82 (mariadb:11.4) | 285.5 (percona/percona-server:8.0) |
| b2 IN | 175.2 | 1.1x | 10 | 82 (mariadb:11.8) | 281.5 (percona/percona-server:8.0) |
| c IN | 14657.2 | 91.95x | 100001 | 7848.6 (mariadb:11.8) | 26347.3 (mysql:9.6) |
| d IN | 166.1 | 1.04x | 10 | 78.4 (mariadb:11.8) | 273.2 (percona/percona-server:8.0) |
| f IN | 181.7 | 1.14x | 10 | 85.9 (mariadb:11.8) | 294 (percona/percona-server:8.0) |
| g1 | 175.7 | 1.34x | 2 | 67.6 (mariadb:11.4) | 218.3 (percona/percona-server:8.0) |
| g2 | 20799.3 | 158.17x | 100002 | 11519.9 (mariadb:11.8) | 25604.7 (mysql:9.6) |
| g1 IN | 227.5 | 1.43x | 10 | 78.7 (mariadb:11.8) | 267 (percona/percona-server:8.0) |
| g2 IN | 38400.8 | 240.91x | 100002 | 26532.2 (mariadb:11.8) | 44609.6 (mariadb:12.3) |
| g3 | 133.7 | 1.02x | 2 | 68.6 (mariadb:11.4) | 188.9 (mariadb:10.6) |
| g3 IN | 164.1 | 1.03x | 10 | 86 (mariadb:11.8) | 239.4 (mariadb:10.6) |

#### utf8mb4_general_ci, unique

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 110 | 1x | 1 | 57.8 (mariadb:11.8) | 195.7 (mysql:8.0) |
| b1 | 115.2 | 1.05x | 1 | 60.2 (mariadb:11.8) | 204.4 (percona/percona-server:8.0) |
| b2 | 113.1 | 1.03x | 1 | 58.6 (mariadb:11.8) | 210 (mysql:9.6) |
| c | 12573.6 | 114.31x | 100001 | 6965.2 (mariadb:11.8) | 24622.9 (mysql:9.6) |
| d | 114.9 | 1.04x | 1 | 57.8 (mariadb:11.8) | 200.1 (percona/percona-server:8.0) |
| e | 121.4 | 1.1x | 1 | 59.7 (mariadb:11.8) | 194 (mysql:9.6) |
| f | 118.3 | 1.08x | 1 | 61.2 (mariadb:11.8) | 208.5 (mysql:9.6) |
| a IN | 142.7 | 1x | 5 | 70 (mariadb:11.8) | 227.9 (mysql:8.0) |
| b1 IN | 148.1 | 1.04x | 5 | 74.9 (mariadb:11.4) | 229.9 (mysql:9.6) |
| b2 IN | 148.1 | 1.04x | 5 | 72.2 (mariadb:11.4) | 245.1 (percona/percona-server:8.0) |
| c IN | 14039.5 | 98.38x | 100001 | 7663.3 (mariadb:11.8) | 26136.5 (mysql:9.6) |
| d IN | 146 | 1.02x | 5 | 73.6 (mariadb:11.8) | 229.7 (mysql:9.6) |
| f IN | 170.5 | 1.19x | 5 | 79.4 (mariadb:11.8) | 248 (mysql:9.6) |
| g1 | 168.1 | 1.53x | 1 | 59 (mariadb:11.8) | 221.5 (mysql:9.6) |
| g2 | 20643.9 | 187.67x | 100002 | 10726.3 (mariadb:11.8) | 25592.8 (mysql:9.6) |
| g1 IN | 196.1 | 1.37x | 5 | 79 (mariadb:11.8) | 232.2 (mysql:9.6) |
| g2 IN | 38755.9 | 271.59x | 100002 | 26762.8 (mariadb:11.8) | 44612 (mariadb:12.3) |
| g3 | 113.8 | 1.03x | 1 | 61.2 (mariadb:11.8) | 166.8 (mariadb:10.6) |
| g3 IN | 151 | 1.06x | 5 | 79.9 (mariadb:11.8) | 218.8 (mariadb:10.6) |

#### utf8mb4_general_ci, prefix

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 189.8 | 1x | 53 | 95.2 (mariadb:11.8) | 303.7 (mysql:9.6) |
| b1 | 188.8 | 0.99x | 53 | 87.6 (mariadb:11.8) | 17591 (mysql:9.6) |
| b2 | 193.7 | 1.02x | 53 | 86.2 (mariadb:11.8) | 17636.7 (mysql:9.6) |
| c | 12591.4 | 66.34x | 100001 | 7363.5 (mariadb:11.8) | 24166.9 (mysql:9.6) |
| d | 190.7 | 1x | 53 | 98.9 (mariadb:11.8) | 18198 (mysql:9.6) |
| e | 196.9 | 1.04x | 53 | 92.8 (mariadb:11.8) | 306 (mysql:9.6) |
| f | 202.8 | 1.07x | 53 | 93.1 (mariadb:11.8) | 324.8 (mysql:9.6) |
| a IN | 364.4 | 1x | 257 | 191.3 (mariadb:11.8) | 26762.6 (mysql:9.6) |
| b1 IN | 363.4 | 1x | 257 | 199.9 (mariadb:11.8) | 20772.9 (mysql:9.6) |
| b2 IN | 356.9 | 0.98x | 257 | 201.2 (mariadb:11.8) | 20621.9 (mysql:9.6) |
| c IN | 15248 | 41.84x | 100001 | 9303.4 (mariadb:11.4) | 26805.8 (percona/percona-server:8.0) |
| d IN | 364.3 | 1x | 257 | 210 (mariadb:11.8) | 20581 (mysql:9.6) |
| f IN | 385.9 | 1.06x | 257 | 216 (mariadb:11.8) | 27505.8 (mysql:9.6) |
| g1 | 244.3 | 1.29x | 53 | 104.8 (mariadb:11.8) | 17974.3 (mysql:9.6) |
| g2 | 21756.1 | 114.63x | 100003 | 11211.7 (mariadb:11.8) | 25863.8 (percona/percona-server:8.0) |
| g1 IN | 499.5 | 1.37x | 257 | 213.7 (mariadb:11.8) | 20408.3 (mysql:9.6) |
| g2 IN | 41145.5 | 112.91x | 100003 | 28001.4 (mariadb:11.4) | 46663 (mariadb:12.3) |
| g3 | 182.6 | 0.96x | 53 | 99.2 (mariadb:11.8) | 263.9 (mariadb:10.6) |
| g3 IN | 343.7 | 0.94x | 257 | 213.5 (mariadb:11.8) | 498.7 (mariadb:10.6) |

#### utf8mb4_bin, plain

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 130.3 | 1x | 2 | 64.4 (mariadb:11.4) | 206.2 (mysql:8.0) |
| b1 | 134.8 | 1.03x | 2 | 63.9 (mariadb:11.8) | 221.1 (mysql:9.6) |
| b2 | 133.8 | 1.03x | 2 | 66.1 (mariadb:11.4) | 212.6 (mysql:8.0) |
| c | 12669.3 | 97.23x | 100001 | 7321.6 (mariadb:11.8) | 24757.5 (mysql:9.6) |
| d | 137.6 | 1.06x | 2 | 65.7 (mariadb:11.8) | 219.1 (percona/percona-server:8.0) |
| e | 134.3 | 1.03x | 2 | 64 (mariadb:11.8) | 216.7 (percona/percona-server:8.0) |
| f | 142.2 | 1.09x | 2 | 70.1 (mariadb:11.4) | 221.9 (percona/percona-server:8.0) |
| a IN | 150.8 | 1x | 10 | 74.5 (mariadb:11.4) | 254.4 (percona/percona-server:8.0) |
| b1 IN | 159.3 | 1.06x | 10 | 78.8 (mariadb:11.4) | 261.1 (percona/percona-server:8.0) |
| b2 IN | 163.3 | 1.08x | 10 | 77.1 (mariadb:11.8) | 257.1 (percona/percona-server:8.0) |
| c IN | 14559.2 | 96.55x | 100001 | 8303.7 (mariadb:11.8) | 26186.3 (mysql:9.6) |
| d IN | 167 | 1.11x | 10 | 80 (mariadb:11.4) | 250.4 (percona/percona-server:8.0) |
| f IN | 173.7 | 1.15x | 10 | 82.2 (mariadb:11.4) | 270.1 (mysql:9.6) |
| g1 | 177.5 | 1.36x | 2 | 68.3 (mariadb:11.4) | 222.4 (percona/percona-server:8.0) |
| g2 | 20833.2 | 159.89x | 100002 | 11436.7 (mariadb:11.8) | 25444.9 (mysql:9.6) |
| g1 IN | 209 | 1.39x | 10 | 86.4 (mariadb:11.4) | 250.7 (mysql:8.0) |
| g2 IN | 38501.4 | 255.31x | 100002 | 28279.1 (mariadb:11.4) | 44659.9 (mariadb:12.3) |
| g3 | 132.6 | 1.02x | 2 | 69.2 (mariadb:11.8) | 193.8 (mariadb:10.6) |
| g3 IN | 164.9 | 1.09x | 10 | 87 (mariadb:11.4) | 233.7 (mariadb:10.6) |

#### utf8mb4_bin, unique

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 112.9 | 1x | 1 | 58.5 (mariadb:11.4) | 191.9 (percona/percona-server:8.0) |
| b1 | 114.9 | 1.02x | 1 | 59.4 (mariadb:11.4) | 205.3 (mysql:8.0) |
| b2 | 117.5 | 1.04x | 1 | 60.1 (mariadb:11.4) | 204.8 (mysql:8.0) |
| c | 12765.4 | 113.07x | 100001 | 7465.8 (mariadb:11.4) | 24695 (mysql:9.6) |
| d | 114.7 | 1.02x | 1 | 59 (mariadb:11.4) | 191.8 (mysql:9.6) |
| e | 124.3 | 1.1x | 1 | 63.9 (mariadb:11.4) | 200.6 (mysql:8.0) |
| f | 125.1 | 1.11x | 1 | 61.4 (mariadb:11.4) | 202.7 (mysql:8.0) |
| a IN | 145.8 | 1x | 5 | 70.3 (mariadb:11.8) | 220.3 (percona/percona-server:8.0) |
| b1 IN | 143.3 | 0.98x | 5 | 70.7 (mariadb:11.8) | 226.2 (mysql:9.6) |
| b2 IN | 147.1 | 1.01x | 5 | 70.4 (mariadb:11.8) | 241.4 (mysql:9.6) |
| c IN | 14543.3 | 99.75x | 100001 | 7613.6 (mariadb:11.8) | 26121.4 (mysql:9.6) |
| d IN | 150.4 | 1.03x | 5 | 73 (mariadb:11.8) | 223.7 (mysql:9.6) |
| f IN | 162.1 | 1.11x | 5 | 78.8 (mariadb:11.4) | 261 (mysql:9.6) |
| g1 | 170.2 | 1.51x | 1 | 61.6 (mariadb:11.4) | 205.4 (mysql:9.6) |
| g2 | 20969.6 | 185.74x | 100002 | 11566.2 (mariadb:11.4) | 25610.3 (mysql:9.6) |
| g1 IN | 187.3 | 1.28x | 5 | 77.5 (mariadb:11.8) | 248.4 (mysql:9.6) |
| g2 IN | 38342.4 | 262.98x | 100002 | 25657.7 (mariadb:11.8) | 44530.6 (mariadb:12.3) |
| g3 | 111.8 | 0.99x | 1 | 64.5 (mariadb:11.4) | 171.5 (mariadb:10.6) |
| g3 IN | 150.7 | 1.03x | 5 | 77.6 (mariadb:11.8) | 227.2 (mariadb:10.2.7) |

#### utf8mb4_bin, prefix

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 187.5 | 1x | 53 | 94.2 (mariadb:11.8) | 306.8 (mysql:9.6) |
| b1 | 186.3 | 0.99x | 53 | 93.7 (mariadb:11.8) | 313.2 (mysql:9.6) |
| b2 | 184.7 | 0.99x | 53 | 93 (mariadb:11.8) | 303.2 (mysql:9.6) |
| c | 12555.6 | 66.96x | 100001 | 7306.2 (mariadb:11.8) | 24376.3 (mysql:9.6) |
| d | 188.1 | 1x | 53 | 95 (mariadb:11.8) | 304.9 (mysql:9.6) |
| e | 181.6 | 0.97x | 53 | 96 (mariadb:11.8) | 305.4 (mysql:9.6) |
| f | 196.3 | 1.05x | 53 | 101.2 (mariadb:11.8) | 323.7 (mysql:9.6) |
| a IN | 369.4 | 1x | 257 | 190.2 (mariadb:11.8) | 558.8 (mysql:9.6) |
| b1 IN | 345.3 | 0.93x | 257 | 190.8 (mariadb:11.8) | 586.4 (mysql:9.6) |
| b2 IN | 346.2 | 0.94x | 257 | 192.6 (mariadb:11.8) | 580.4 (mysql:9.6) |
| c IN | 15351.9 | 41.56x | 100001 | 8994.3 (mariadb:11.8) | 26824.2 (mysql:9.6) |
| d IN | 345 | 0.93x | 257 | 192.1 (mariadb:11.8) | 571.6 (mysql:9.6) |
| f IN | 368.2 | 1x | 257 | 196.6 (mariadb:11.8) | 582.2 (mysql:9.6) |
| g1 | 235.9 | 1.26x | 53 | 96.5 (mariadb:11.8) | 313.2 (mysql:9.6) |
| g2 | 22143.8 | 118.1x | 100003 | 11201.5 (mariadb:11.8) | 25111.8 (percona/percona-server:8.0) |
| g1 IN | 454.7 | 1.23x | 257 | 208.6 (mariadb:11.8) | 567.2 (mysql:9.6) |
| g2 IN | 41688.2 | 112.85x | 100003 | 27275.4 (mariadb:11.4) | 46899.9 (mariadb:12.3) |
| g3 | 181.8 | 0.97x | 53 | 95.6 (mariadb:11.8) | 282.2 (mariadb:10.6) |
| g3 IN | 350.9 | 0.95x | 257 | 210.5 (mariadb:11.8) | 497 (mariadb:10.6) |

#### ascii_general_ci, plain

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 131.4 | 1x | 2 | 65.3 (mariadb:11.4) | 209 (percona/percona-server:8.0) |
| b1 | 133.5 | 1.02x | 2 | 66.2 (mariadb:11.4) | 210 (mysql:9.6) |
| b2 | 128.2 | 0.98x | 2 | 67.1 (mariadb:11.8) | 220.1 (percona/percona-server:8.0) |
| c | 12188.4 | 92.76x | 100001 | 6444.3 (mariadb:11.8) | 24403.1 (mysql:9.6) |
| d | 17052.8 | 129.78x | 100001 | 119.2 (mysql:9.7) | 31021.9 (mariadb:10.2.7) |
| e | 125.7 | 0.96x | 2 | 62.4 (mariadb:11.4) | 207 (percona/percona-server:8.0) |
| f | 145.4 | 1.11x | 2 | 68.6 (mariadb:11.4) | 227.2 (percona/percona-server:8.0) |
| a IN | 152.5 | 1x | 10 | 77 (mariadb:11.8) | 253.9 (percona/percona-server:8.0) |
| b1 IN | 160.1 | 1.05x | 10 | 79.7 (mariadb:11.8) | 256 (percona/percona-server:8.0) |
| b2 IN | 161.8 | 1.06x | 10 | 75.8 (mariadb:11.8) | 259.4 (percona/percona-server:8.0) |
| c IN | 13413.8 | 87.96x | 100001 | 6727.1 (mariadb:11.4) | 25942.1 (mysql:9.6) |
| d IN | 19071.4 | 125.06x | 100001 | 150.5 (mysql:9.7) | 34817.2 (mariadb:10.2.7) |
| f IN | 172.7 | 1.13x | 10 | 86.8 (mariadb:11.4) | 274.5 (mysql:9.6) |
| g1 | 213.9 | 1.63x | 2 | 107.3 (mysql:9.7) | 22480 (mariadb:12.3) |
| g2 | 22536.8 | 171.51x | 100002 | 18501.2 (mysql:9.7) | 31081.7 (mariadb:12.3) |
| g1 IN | 255.5 | 1.68x | 10 | 143.5 (mysql:9.7) | 24273.5 (mariadb:12.3) |
| g2 IN | 39747.9 | 260.64x | 100002 | 32300.7 (mysql:9.7) | 55543.8 (mariadb:12.3) |
| g3 | 21306 | 162.15x | 100001 | 14267.4 (mariadb:11.4) | 29874.9 (mariadb:10.2.7) |
| g3 IN | 24658 | 161.69x | 100001 | 13981.8 (mariadb:11.4) | 32564.9 (mariadb:10.2.7) |

#### ascii_general_ci, unique

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 113.3 | 1x | 1 | 62.9 (mariadb:11.8) | 196.5 (percona/percona-server:8.0) |
| b1 | 115.6 | 1.02x | 1 | 60.6 (mariadb:11.8) | 207.7 (percona/percona-server:8.0) |
| b2 | 111.2 | 0.98x | 1 | 58.7 (mariadb:11.4) | 208.3 (percona/percona-server:8.0) |
| c | 12529.3 | 110.59x | 100001 | 6064.2 (mariadb:11.4) | 24761.1 (mysql:9.6) |
| d | 17035.4 | 150.36x | 100001 | 108.6 (mysql:9.7) | 31136.3 (mariadb:10.2.7) |
| e | 124.6 | 1.1x | 1 | 64.7 (mariadb:11.4) | 192.2 (mysql:8.0) |
| f | 120.8 | 1.07x | 1 | 64.2 (mariadb:11.4) | 196.5 (mysql:8.0) |
| a IN | 139.3 | 1x | 5 | 70.4 (mariadb:11.8) | 218.4 (mysql:9.6) |
| b1 IN | 142 | 1.02x | 5 | 71.2 (mariadb:11.8) | 227.2 (mysql:9.6) |
| b2 IN | 147.5 | 1.06x | 5 | 70.1 (mariadb:11.8) | 232.5 (mysql:9.6) |
| c IN | 13580.4 | 97.49x | 100001 | 6638 (mariadb:11.8) | 26155.8 (mysql:9.6) |
| d IN | 19079.5 | 136.97x | 100001 | 121.7 (mysql:9.7) | 34820 (mariadb:10.2.7) |
| f IN | 162.9 | 1.17x | 5 | 74.7 (mariadb:11.8) | 240.8 (mysql:9.6) |
| g1 | 203 | 1.79x | 1 | 113.1 (mysql:9.7) | 22377.7 (mariadb:12.3) |
| g2 | 22904.3 | 202.16x | 100002 | 18850.3 (mysql:9.7) | 31146.7 (mariadb:12.3) |
| g1 IN | 221.2 | 1.59x | 5 | 126.2 (mysql:9.7) | 24334.2 (mariadb:12.3) |
| g2 IN | 40104.3 | 287.9x | 100002 | 32259.7 (mysql:9.7) | 55577.6 (mariadb:12.3) |
| g3 | 21572.5 | 190.4x | 100001 | 13860.4 (mariadb:11.4) | 31681.2 (mariadb:10.2.6) |
| g3 IN | 24562.2 | 176.33x | 100001 | 14351.4 (mariadb:11.4) | 32596.5 (mariadb:10.2.7) |

#### ascii_general_ci, prefix

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 193.6 | 1x | 53 | 95.8 (mariadb:11.8) | 302.7 (mysql:9.6) |
| b1 | 194 | 1x | 53 | 96.6 (mariadb:11.8) | 302.1 (mysql:9.6) |
| b2 | 184.2 | 0.95x | 53 | 97.3 (mariadb:11.8) | 299.2 (mysql:9.6) |
| c | 12382.1 | 63.96x | 100001 | 6259.7 (mariadb:11.8) | 23693.3 (percona/percona-server:8.0) |
| d | 16649.5 | 86x | 100001 | 182.4 (mysql:9.7) | 31977.5 (mariadb:10.2.6) |
| e | 189.6 | 0.98x | 53 | 100.9 (mariadb:11.8) | 303.2 (mysql:9.6) |
| f | 197.8 | 1.02x | 53 | 105 (mariadb:11.8) | 322.2 (mysql:9.6) |
| a IN | 334 | 1x | 257 | 203.2 (mariadb:11.8) | 554.8 (mysql:9.6) |
| b1 IN | 350.3 | 1.05x | 257 | 204 (mariadb:11.8) | 568.8 (mysql:9.6) |
| b2 IN | 334.7 | 1x | 257 | 203.3 (mariadb:11.8) | 562.7 (mysql:9.6) |
| c IN | 15171.4 | 45.42x | 100001 | 8376 (mariadb:11.4) | 26783.5 (mysql:9.6) |
| d IN | 19676.9 | 58.91x | 100001 | 329.1 (mysql:9.7) | 36522.4 (mariadb:10.2.6) |
| f IN | 357.8 | 1.07x | 257 | 211.9 (mariadb:11.8) | 589 (mysql:9.6) |
| g1 | 267.3 | 1.38x | 53 | 183.3 (mysql:9.7) | 23259.6 (mariadb:12.3) |
| g2 | 24279.1 | 125.41x | 100003 | 18204.4 (mysql:9.7) | 31837.4 (mariadb:12.3) |
| g1 IN | 503.4 | 1.51x | 257 | 331.8 (mysql:9.7) | 26332.4 (mariadb:12.3) |
| g2 IN | 42846.7 | 128.28x | 100003 | 33223.4 (mysql:9.7) | 57778.2 (mariadb:12.3) |
| g3 | 21777.7 | 112.49x | 100001 | 14194.3 (mariadb:11.4) | 31176.5 (mariadb:10.2.7) |
| g3 IN | 26011.6 | 77.88x | 100001 | 15289.1 (mariadb:11.4) | 34970.8 (mariadb:10.2.7) |

#### ascii_bin, plain

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 133.4 | 1x | 2 | 64.6 (mariadb:11.4) | 209.7 (mysql:9.6) |
| b1 | 137.4 | 1.03x | 2 | 67.3 (mariadb:11.4) | 212.4 (mysql:8.0) |
| b2 | 135 | 1.01x | 2 | 68.1 (mariadb:11.4) | 221.2 (percona/percona-server:8.0) |
| c | 12122.4 | 90.87x | 100001 | 6199.6 (mariadb:11.4) | 24327.7 (mysql:9.6) |
| d | 17426.2 | 130.63x | 100001 | 116.1 (mysql:9.7) | 30713.9 (mariadb:10.2.7) |
| e | 132.4 | 0.99x | 2 | 63.5 (mariadb:11.4) | 219.1 (mysql:8.0) |
| f | 142.1 | 1.07x | 2 | 70.7 (mariadb:11.4) | 226.6 (percona/percona-server:8.0) |
| a IN | 151.4 | 1x | 10 | 74.2 (mariadb:11.4) | 260.9 (percona/percona-server:8.0) |
| b1 IN | 155.5 | 1.03x | 10 | 73.9 (mariadb:11.8) | 263.4 (percona/percona-server:8.0) |
| b2 IN | 165.6 | 1.09x | 10 | 74.8 (mariadb:11.8) | 259.8 (percona/percona-server:8.0) |
| c IN | 13464.8 | 88.94x | 100001 | 6669.5 (mariadb:11.8) | 26073.8 (mysql:9.6) |
| d IN | 19173.7 | 126.64x | 100001 | 144.6 (mysql:9.7) | 34674.4 (mariadb:10.2.7) |
| f IN | 170 | 1.12x | 10 | 81.5 (mariadb:11.8) | 269 (percona/percona-server:8.0) |
| g1 | 212.7 | 1.59x | 2 | 116.9 (mysql:9.7) | 22632.9 (mariadb:12.3) |
| g2 | 23195.4 | 173.88x | 100002 | 17357.2 (mysql:9.7) | 31212.4 (mariadb:12.3) |
| g1 IN | 250.2 | 1.65x | 10 | 137.3 (mysql:9.7) | 24288 (mariadb:12.3) |
| g2 IN | 40093.3 | 264.82x | 100002 | 31222.6 (mysql:9.7) | 55541.7 (mariadb:12.3) |
| g3 | 21566.3 | 161.67x | 100001 | 13284.7 (mariadb:11.4) | 30057.2 (mariadb:10.2.7) |
| g3 IN | 24215.4 | 159.94x | 100001 | 14324.7 (mariadb:11.4) | 34959.5 (mariadb:10.2.7) |

#### ascii_bin, unique

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 112.5 | 1x | 1 | 56.4 (mariadb:11.4) | 192.6 (mysql:9.6) |
| b1 | 116.7 | 1.04x | 1 | 61.3 (mariadb:11.4) | 213.9 (mysql:8.0) |
| b2 | 120 | 1.07x | 1 | 56.8 (mariadb:11.4) | 202.4 (mysql:8.0) |
| c | 12032 | 106.95x | 100001 | 6105.2 (mariadb:11.4) | 24528.7 (mysql:9.6) |
| d | 17407.8 | 154.74x | 100001 | 105.5 (mysql:9.7) | 31051.1 (mariadb:10.2.7) |
| e | 123.2 | 1.1x | 1 | 62.6 (mariadb:11.4) | 200 (mysql:9.6) |
| f | 121.3 | 1.08x | 1 | 64.4 (mariadb:11.8) | 196.5 (mysql:8.0) |
| a IN | 140.1 | 1x | 5 | 70 (mariadb:11.4) | 219.9 (mysql:8.0) |
| b1 IN | 144.7 | 1.03x | 5 | 71.5 (mariadb:11.4) | 229 (mysql:9.6) |
| b2 IN | 150.3 | 1.07x | 5 | 72.1 (mariadb:11.4) | 230.6 (mysql:9.6) |
| c IN | 13198 | 94.2x | 100001 | 7032.9 (mariadb:11.4) | 26153.9 (mysql:9.6) |
| d IN | 19140.8 | 136.62x | 100001 | 126.1 (mysql:9.7) | 34746.8 (mariadb:10.2.7) |
| f IN | 165.4 | 1.18x | 5 | 78.8 (mariadb:11.4) | 245.8 (mysql:9.6) |
| g1 | 199.2 | 1.77x | 1 | 104.6 (mysql:9.7) | 22410.2 (mariadb:12.3) |
| g2 | 22895 | 203.51x | 100002 | 18568.6 (mysql:9.7) | 31134.4 (mariadb:12.3) |
| g1 IN | 222.9 | 1.59x | 5 | 127.4 (mysql:9.7) | 24160.6 (mariadb:12.3) |
| g2 IN | 40199.8 | 286.94x | 100002 | 32485 (mysql:9.7) | 55377.1 (mariadb:12.3) |
| g3 | 21745.8 | 193.3x | 100001 | 13330 (mariadb:11.4) | 32672.8 (mariadb:10.2.7) |
| g3 IN | 24708.9 | 176.37x | 100001 | 14292.3 (mariadb:11.4) | 32237.3 (mariadb:10.2.7) |

#### ascii_bin, prefix

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 191.8 | 1x | 53 | 93.2 (mariadb:11.8) | 311.1 (mysql:9.6) |
| b1 | 188.1 | 0.98x | 53 | 98.5 (mariadb:11.8) | 295.7 (mysql:9.6) |
| b2 | 190.8 | 0.99x | 53 | 97.7 (mariadb:11.8) | 305.7 (mysql:9.6) |
| c | 12390.4 | 64.6x | 100001 | 6273.1 (mariadb:11.4) | 24099 (mysql:9.6) |
| d | 16764.1 | 87.4x | 100001 | 172.1 (mysql:9.7) | 32150.9 (mariadb:10.2.7) |
| e | 188.2 | 0.98x | 53 | 97.4 (mariadb:11.4) | 293.5 (mysql:9.6) |
| f | 202 | 1.05x | 53 | 103.6 (mariadb:11.8) | 311.2 (mysql:9.6) |
| a IN | 328.9 | 1x | 257 | 200.6 (mariadb:11.8) | 557.3 (mysql:9.6) |
| b1 IN | 337.7 | 1.03x | 257 | 205.8 (mariadb:11.8) | 568.7 (mysql:9.6) |
| b2 IN | 334.6 | 1.02x | 257 | 189.6 (mariadb:11.8) | 586.7 (mysql:9.6) |
| c IN | 15312.6 | 46.56x | 100001 | 8108 (mariadb:11.8) | 26898.6 (mysql:9.6) |
| d IN | 19694.2 | 59.88x | 100001 | 324.5 (mysql:9.7) | 36432.7 (mariadb:10.2.7) |
| f IN | 358.4 | 1.09x | 257 | 197.1 (mariadb:11.8) | 579.9 (mysql:9.6) |
| g1 | 274 | 1.43x | 53 | 167.1 (mysql:9.7) | 23169 (mariadb:12.3) |
| g2 | 24365.6 | 127.04x | 100003 | 18291.3 (mysql:9.7) | 31716.3 (mariadb:12.3) |
| g1 IN | 512 | 1.56x | 257 | 331.6 (mysql:9.7) | 26334.4 (mariadb:12.3) |
| g2 IN | 43010.5 | 130.77x | 100003 | 32885.7 (mysql:9.7) | 57748.2 (mariadb:12.3) |
| g3 | 21438.4 | 111.77x | 100001 | 14062.8 (mariadb:11.4) | 31561.6 (mariadb:10.2.7) |
| g3 IN | 26061.6 | 79.24x | 100001 | 15741.1 (mariadb:11.4) | 34906.4 (mariadb:10.2.7) |

#### latin1_swedish_ci, plain

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 137 | 1x | 2 | 65.7 (mariadb:11.8) | 209.8 (mysql:8.0) |
| b1 | 135.9 | 0.99x | 2 | 64.6 (mariadb:11.4) | 211 (percona/percona-server:8.0) |
| b2 | 131.6 | 0.96x | 2 | 63.7 (mariadb:11.4) | 222.2 (percona/percona-server:8.0) |
| c | 12014.3 | 87.7x | 100001 | 6091.1 (mariadb:11.4) | 24311 (mysql:9.6) |
| d | 17079.1 | 124.66x | 100001 | 113.5 (mysql:9.7) | 31184.5 (mariadb:10.2.7) |
| e | 125.4 | 0.92x | 2 | 63.1 (mariadb:11.4) | 206.4 (percona/percona-server:8.0) |
| f | 141.3 | 1.03x | 2 | 68.1 (mariadb:11.4) | 227.8 (percona/percona-server:8.0) |
| a IN | 153.4 | 1x | 10 | 72.7 (mariadb:11.8) | 264.2 (percona/percona-server:8.0) |
| b1 IN | 159 | 1.04x | 10 | 75.6 (mariadb:11.8) | 254.3 (mysql:8.0) |
| b2 IN | 153.5 | 1x | 10 | 75.1 (mariadb:11.8) | 257.7 (mysql:8.0) |
| c IN | 13535 | 88.23x | 100001 | 6780 (mariadb:11.4) | 25888 (mysql:9.6) |
| d IN | 19271.9 | 125.63x | 100001 | 140.3 (mysql:9.7) | 33879.6 (mariadb:10.2.7) |
| f IN | 172.9 | 1.13x | 10 | 83.5 (mariadb:11.4) | 265.9 (percona/percona-server:8.0) |
| g1 | 209.5 | 1.53x | 2 | 111 (mysql:9.7) | 21534.1 (mariadb:12.3) |
| g2 | 23037.8 | 168.16x | 100002 | 17293.1 (mysql:9.7) | 30021.4 (mariadb:12.3) |
| g1 IN | 249.9 | 1.63x | 10 | 137.2 (mysql:9.7) | 23325.4 (mariadb:12.3) |
| g2 IN | 39907 | 260.15x | 100002 | 31661.4 (mysql:9.7) | 55080.2 (mariadb:12.3) |
| g3 | 21476.9 | 156.77x | 100001 | 13585.2 (mariadb:11.4) | 29259.1 (mariadb:10.2.7) |
| g3 IN | 23952.4 | 156.14x | 100001 | 14135.5 (mariadb:11.4) | 31935.7 (mariadb:10.2.7) |

#### latin1_swedish_ci, unique

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 113.6 | 1x | 1 | 58.3 (mariadb:11.8) | 194.1 (percona/percona-server:8.0) |
| b1 | 116.5 | 1.03x | 1 | 60.3 (mariadb:11.4) | 204.7 (mysql:9.6) |
| b2 | 114.1 | 1x | 1 | 59.8 (mariadb:11.8) | 204.9 (mysql:8.0) |
| c | 12016.4 | 105.78x | 100001 | 5993.2 (mariadb:11.4) | 24669.6 (mysql:9.6) |
| d | 17510 | 154.14x | 100001 | 109.7 (mysql:9.7) | 30149.9 (mariadb:10.2.7) |
| e | 120.3 | 1.06x | 1 | 61 (mariadb:11.4) | 201.8 (percona/percona-server:8.0) |
| f | 118.6 | 1.04x | 1 | 61.9 (mariadb:11.8) | 205.6 (percona/percona-server:8.0) |
| a IN | 140.5 | 1x | 5 | 68.9 (mariadb:11.8) | 217.4 (percona/percona-server:8.0) |
| b1 IN | 145 | 1.03x | 5 | 71.6 (mariadb:11.8) | 231.9 (mysql:8.0) |
| b2 IN | 142.4 | 1.01x | 5 | 72.8 (mariadb:11.4) | 228.3 (mysql:8.0) |
| c IN | 13446.2 | 95.7x | 100001 | 6847.3 (mariadb:11.4) | 26187 (mysql:9.6) |
| d IN | 19152.5 | 136.32x | 100001 | 125.7 (mysql:9.7) | 33992.3 (mariadb:10.2.7) |
| f IN | 159.9 | 1.14x | 5 | 77.1 (mariadb:11.4) | 245.1 (mysql:8.0) |
| g1 | 210 | 1.85x | 1 | 110.8 (mysql:9.7) | 21591.9 (mariadb:12.3) |
| g2 | 23476.4 | 206.66x | 100002 | 17533.1 (mysql:9.7) | 30161.8 (mariadb:12.3) |
| g1 IN | 226.9 | 1.61x | 5 | 123.2 (mysql:9.7) | 23428.9 (mariadb:12.3) |
| g2 IN | 40302.9 | 286.85x | 100002 | 31684.2 (mysql:9.7) | 54735.1 (mariadb:12.3) |
| g3 | 21575.6 | 189.93x | 100001 | 13219.4 (mariadb:11.4) | 29321.3 (mariadb:10.2.7) |
| g3 IN | 24219.9 | 172.38x | 100001 | 15566.5 (mariadb:11.4) | 32363.4 (mariadb:10.2.7) |

#### latin1_swedish_ci, prefix

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 187.6 | 1x | 53 | 109.8 (mariadb:11.4) | 300.8 (mysql:9.6) |
| b1 | 190.2 | 1.01x | 53 | 106.1 (mariadb:11.4) | 311 (mysql:9.6) |
| b2 | 194 | 1.03x | 53 | 107.5 (mariadb:11.4) | 311.4 (mysql:9.6) |
| c | 12379.6 | 65.99x | 100001 | 6330 (mariadb:11.4) | 23712.9 (mysql:9.6) |
| d | 16725.8 | 89.16x | 100001 | 175.6 (mysql:9.7) | 31187.8 (mariadb:10.2.6) |
| e | 180.5 | 0.96x | 53 | 110.1 (mariadb:11.8) | 299.1 (mysql:9.6) |
| f | 212.1 | 1.13x | 53 | 114.2 (mariadb:11.4) | 307.2 (mysql:9.6) |
| a IN | 333.3 | 1x | 257 | 207 (mariadb:11.8) | 547.3 (mysql:9.6) |
| b1 IN | 331.9 | 1x | 257 | 205.2 (mariadb:11.8) | 575.4 (mysql:9.6) |
| b2 IN | 342.9 | 1.03x | 257 | 204.1 (mariadb:11.8) | 556.1 (mysql:9.6) |
| c IN | 15337.8 | 46.02x | 100001 | 8343.5 (mariadb:11.4) | 26546.8 (mysql:9.6) |
| d IN | 19659.3 | 58.98x | 100001 | 333 (mysql:9.7) | 35475.7 (mariadb:10.2.7) |
| f IN | 353.6 | 1.06x | 257 | 217.1 (mariadb:11.8) | 577.1 (mysql:9.6) |
| g1 | 286.4 | 1.53x | 53 | 178.7 (mysql:9.7) | 22392.2 (mariadb:12.3) |
| g2 | 24583.5 | 131.04x | 100003 | 18027.2 (mysql:9.7) | 30854.7 (mariadb:12.3) |
| g1 IN | 490.1 | 1.47x | 257 | 322.3 (mysql:9.7) | 25393.4 (mariadb:12.3) |
| g2 IN | 42748.5 | 128.26x | 100003 | 32664 (mysql:9.7) | 56963.3 (mariadb:12.3) |
| g3 | 21135.4 | 112.66x | 100001 | 13724.9 (mariadb:11.4) | 30941.8 (mariadb:10.2.7) |
| g3 IN | 25081.8 | 75.25x | 100001 | 15871.6 (mariadb:11.4) | 33893.3 (mariadb:10.2.7) |

#### VARBINARY, plain

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 131.1 | 1x | 2 | 65.8 (mariadb:11.8) | 211.6 (mysql:8.0) |
| b1 | 136.2 | 1.04x | 2 | 64 (mariadb:11.8) | 213.9 (percona/percona-server:8.0) |
| b2 | 133.4 | 1.02x | 2 | 65.3 (mariadb:11.4) | 223.8 (percona/percona-server:8.0) |
| c | 11995 | 91.5x | 100001 | 6072.4 (mariadb:11.8) | 24535 (mysql:9.6) |
| d | 8694.9 | 66.32x | 100001 | 79.2 (percona/percona-server:5.7) | 18283.8 (mariadb:10.2.6) |
| e | 127.5 | 0.97x | 2 | 63.1 (mariadb:11.8) | 210.2 (percona/percona-server:8.0) |
| f | 134.8 | 1.03x | 2 | 68.2 (mariadb:11.4) | 222.3 (percona/percona-server:8.0) |
| a IN | 149.9 | 1x | 10 | 70.3 (mariadb:11.8) | 249.2 (percona/percona-server:8.0) |
| b1 IN | 155.1 | 1.03x | 10 | 74.9 (mariadb:11.4) | 253 (percona/percona-server:8.0) |
| b2 IN | 154.2 | 1.03x | 10 | 74 (mariadb:11.8) | 255.7 (percona/percona-server:8.0) |
| c IN | 13485 | 89.96x | 100001 | 6714 (mariadb:11.4) | 26302.7 (mysql:8.0) |
| d IN | 8735.9 | 58.28x | 100001 | 109 (percona/percona-server:5.7) | 23517.3 (mariadb:10.2.6) |
| f IN | 167.7 | 1.12x | 10 | 80.7 (mariadb:11.4) | 265.3 (mysql:8.0) |
| g1 | 212.2 | 1.62x | 2 | 105.9 (mysql:9.7) | 15378.4 (mariadb:12.3) |
| g2 | 23394 | 178.44x | 100002 | 14158.1 (mariadb:11.4) | 27769.3 (mysql:9.6) |
| g1 IN | 240.5 | 1.6x | 10 | 132 (mysql:9.7) | 17367.2 (mariadb:12.3) |
| g2 IN | 40786.6 | 272.09x | 100002 | 29407.3 (mariadb:11.4) | 48599.3 (mariadb:12.3) |
| g3 | 12793.6 | 97.59x | 100001 | 7804.8 (mariadb:11.8) | 17433.7 (mariadb:10.2.6) |
| g3 IN | 17300.4 | 115.41x | 100001 | 8545.5 (mariadb:11.4) | 22735.6 (mariadb:10.6) |

#### VARBINARY, unique

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 113.5 | 1x | 1 | 58.6 (mariadb:11.8) | 188.5 (mysql:8.0) |
| b1 | 118.5 | 1.04x | 1 | 64 (mariadb:11.8) | 190.5 (mysql:8.0) |
| b2 | 112.3 | 0.99x | 1 | 60.7 (mariadb:11.4) | 195 (mysql:9.6) |
| c | 12185.2 | 107.36x | 100001 | 6024.7 (mariadb:11.8) | 24541.6 (mysql:9.6) |
| d | 8223.5 | 72.45x | 100001 | 108.5 (mysql:9.7) | 18692.1 (mariadb:10.2.6) |
| e | 126.5 | 1.11x | 1 | 64 (mariadb:11.4) | 194.7 (mysql:9.6) |
| f | 122.5 | 1.08x | 1 | 60.6 (mariadb:11.4) | 195.2 (mysql:8.0) |
| a IN | 140.6 | 1x | 5 | 69 (mariadb:11.8) | 215.5 (mysql:9.6) |
| b1 IN | 146 | 1.04x | 5 | 71.6 (mariadb:11.8) | 247.5 (mysql:9.6) |
| b2 IN | 143.7 | 1.02x | 5 | 70.2 (mariadb:11.8) | 247.4 (mysql:9.6) |
| c IN | 13584.4 | 96.62x | 100001 | 6759.1 (mariadb:11.8) | 26322.7 (mysql:9.6) |
| d IN | 8583 | 61.05x | 100001 | 95.1 (percona/percona-server:5.7) | 23561.2 (mariadb:10.2.6) |
| f IN | 150.3 | 1.07x | 5 | 74.8 (mariadb:11.8) | 236.1 (mysql:8.0) |
| g1 | 207.5 | 1.83x | 1 | 108 (mysql:9.7) | 15393.9 (mariadb:12.3) |
| g2 | 23502.7 | 207.07x | 100002 | 13877.2 (mariadb:11.4) | 27600.7 (mysql:9.6) |
| g1 IN | 226.4 | 1.61x | 5 | 125 (mysql:9.7) | 17348.2 (mariadb:12.3) |
| g2 IN | 40955.9 | 291.29x | 100002 | 30156.9 (mariadb:11.4) | 48636.5 (mariadb:12.3) |
| g3 | 13002.4 | 114.56x | 100001 | 8111.3 (mariadb:11.8) | 17568.8 (mariadb:10.2.6) |
| g3 IN | 16596.7 | 118.04x | 100001 | 8875.1 (mariadb:11.8) | 22604.4 (mariadb:10.6) |

#### VARBINARY, prefix

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 196 | 1x | 53 | 103.6 (mariadb:11.8) | 303.6 (mysql:9.6) |
| b1 | 190.4 | 0.97x | 53 | 102.5 (mariadb:11.8) | 309.9 (mysql:9.6) |
| b2 | 184.5 | 0.94x | 53 | 104.8 (mariadb:11.8) | 311.3 (mysql:9.6) |
| c | 12657 | 64.58x | 100001 | 6619.5 (mariadb:11.4) | 23898.9 (percona/percona-server:8.0) |
| d | 8691.5 | 44.34x | 100001 | 154.5 (percona/percona-server:5.7) | 19502.5 (mariadb:10.2.6) |
| e | 184 | 0.94x | 53 | 107.2 (mariadb:11.8) | 301.4 (mysql:9.6) |
| f | 197.6 | 1.01x | 53 | 107.6 (mariadb:11.8) | 307.4 (mysql:9.6) |
| a IN | 328.4 | 1x | 257 | 187.8 (mariadb:11.8) | 535.4 (mysql:9.6) |
| b1 IN | 332.8 | 1.01x | 257 | 189 (mariadb:11.8) | 547.9 (mysql:9.6) |
| b2 IN | 327.3 | 1x | 257 | 187.2 (mariadb:11.8) | 564.4 (mysql:9.6) |
| c IN | 15407.5 | 46.92x | 100001 | 8291 (mariadb:11.4) | 26474.5 (mysql:8.0) |
| d IN | 9996.6 | 30.44x | 100001 | 271.5 (mysql:5.7) | 25250.2 (mariadb:10.2.6) |
| f IN | 351 | 1.07x | 257 | 196.1 (mariadb:11.8) | 569.5 (mysql:9.6) |
| g1 | 270.4 | 1.38x | 53 | 169.4 (mysql:9.7) | 16676.6 (mariadb:12.3) |
| g2 | 24655.3 | 125.79x | 100003 | 13448.4 (mariadb:11.4) | 27702.2 (percona/percona-server:8.0) |
| g1 IN | 484.6 | 1.48x | 257 | 315.2 (mysql:9.7) | 19334 (mariadb:12.3) |
| g2 IN | 43527.3 | 132.54x | 100003 | 30072.1 (mariadb:11.8) | 50704.9 (mariadb:12.3) |
| g3 | 13626.4 | 69.52x | 100001 | 8281.6 (mariadb:11.8) | 18791.1 (mariadb:10.2.6) |
| g3 IN | 17655 | 53.76x | 100001 | 9724.2 (mariadb:11.8) | 23651 (mariadb:10.6) |

#### composite (code, token)

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a both | 150.2 | 1x | 2 | 69.3 (mariadb:11.8) | 231.4 (mysql:8.0) |
| b1 both | 150.1 | 1x | 2 | 71.4 (mariadb:11.8) | 229.5 (percona/percona-server:8.0) |
| b1 token | 153.7 | 1.02x | 2 | 70.8 (mariadb:11.8) | 229.6 (percona/percona-server:8.0) |
| f both | 157.8 | 1.05x | 2 | 72.8 (mariadb:11.8) | 265.8 (percona/percona-server:8.0) |
| d both | 13508.4 | 89.94x | 100001 | 125.8 (mysql:9.7) | 21742.5 (mariadb:10.2.7) |

#### utf8mb4_0900_ai_ci, plain

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 172.1 | 1x | 2 | 65.9 (mariadb:11.4) | 218.4 (mysql:9.6) |
| b1 | 176 | 1.02x | 2 | 66.4 (mariadb:11.4) | 213.7 (mysql:8.0) |
| b2 | 178.7 | 1.04x | 2 | 67.5 (mariadb:11.4) | 215 (mysql:8.0) |
| c | 14187.5 | 82.44x | 100002 | 7149.4 (mariadb:11.4) | 24750.9 (mysql:9.6) |
| d | 177.2 | 1.03x | 2 | 67.3 (mariadb:11.4) | 227.3 (percona/percona-server:8.0) |
| e | 168.1 | 0.98x | 2 | 63.4 (mariadb:11.4) | 219.7 (percona/percona-server:8.0) |
| f | 177.9 | 1.03x | 2 | 70.2 (mariadb:11.4) | 238.3 (percona/percona-server:8.0) |
| g1 | 175.1 | 1.02x | 2 | 69.3 (mariadb:11.4) | 228.1 (percona/percona-server:8.0) |
| g2 | 21329.1 | 123.93x | 100002 | 10860.6 (mariadb:11.4) | 25632 (mysql:9.6) |
| a IN | 221.5 | 1x | 10 | 76.7 (mariadb:11.8) | 283 (percona/percona-server:8.0) |
| b1 IN | 223.5 | 1.01x | 10 | 84 (mariadb:11.8) | 292.6 (percona/percona-server:8.0) |
| b2 IN | 228.6 | 1.03x | 10 | 78.9 (mariadb:11.4) | 295.4 (percona/percona-server:8.0) |
| c IN | 17172.2 | 77.53x | 100002 | 7839.4 (mariadb:11.4) | 26560.6 (mysql:8.0) |
| d IN | 226.7 | 1.02x | 10 | 80 (mariadb:11.4) | 292.5 (mysql:8.0) |
| f IN | 244.8 | 1.11x | 10 | 88.7 (mariadb:11.4) | 318.7 (percona/percona-server:8.0) |
| g1 IN | 227.6 | 1.03x | 10 | 84 (mariadb:11.8) | 289.9 (percona/percona-server:8.0) |
| g2 IN | 38893.9 | 175.59x | 100002 | 25717.1 (mariadb:11.4) | 44778.8 (mariadb:12.3) |
| g3 | 72.2 | 0.42x | 2 | 67.5 (mariadb:11.4) | 141.2 (mariadb:12.3) |
| g3 IN | 91.4 | 0.41x | 10 | 87.7 (mariadb:11.8) | 170.3 (mariadb:12.3) |

#### utf8mb4_0900_ai_ci, unique

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 154 | 1x | 1 | 59.2 (mariadb:11.4) | 194.7 (mysql:8.0) |
| b1 | 164.3 | 1.07x | 1 | 58.9 (mariadb:11.4) | 214.8 (mysql:8.0) |
| b2 | 172.2 | 1.12x | 1 | 59.5 (mariadb:11.8) | 210 (mysql:8.0) |
| c | 14142.9 | 91.84x | 100002 | 7033.2 (mariadb:11.8) | 24897.9 (mysql:9.6) |
| d | 165.9 | 1.08x | 1 | 59.4 (mariadb:11.4) | 222.7 (mysql:9.6) |
| e | 161.8 | 1.05x | 1 | 61.6 (mariadb:11.4) | 204.3 (mysql:9.6) |
| f | 167.1 | 1.09x | 1 | 63.5 (mariadb:11.8) | 196.7 (mysql:8.0) |
| g1 | 166.7 | 1.08x | 1 | 59.2 (mariadb:11.8) | 207.4 (mysql:9.6) |
| g2 | 20689.9 | 134.35x | 100002 | 10942.9 (mariadb:11.4) | 25636.2 (mysql:9.6) |
| a IN | 190.7 | 1x | 5 | 70.5 (mariadb:11.4) | 226.3 (mysql:9.6) |
| b1 IN | 201.2 | 1.06x | 5 | 73.6 (mariadb:11.4) | 241.4 (mysql:9.6) |
| b2 IN | 201.8 | 1.06x | 5 | 76.8 (mariadb:11.4) | 246.2 (mysql:9.6) |
| c IN | 16434 | 86.18x | 100002 | 7772.3 (mariadb:11.4) | 26015 (mysql:8.0) |
| d IN | 202.8 | 1.06x | 5 | 76.1 (mariadb:11.4) | 234.9 (mysql:9.6) |
| f IN | 219.7 | 1.15x | 5 | 82.6 (mariadb:11.4) | 258.1 (mysql:9.6) |
| g1 IN | 201.2 | 1.06x | 5 | 79.2 (mariadb:11.4) | 237.9 (mysql:9.6) |
| g2 IN | 38711.8 | 203x | 100002 | 25494.2 (mariadb:11.4) | 44802.1 (mariadb:12.3) |
| g3 | 64.4 | 0.42x | 1 | 62.6 (mariadb:11.8) | 116.8 (mariadb:12.3) |
| g3 IN | 81.6 | 0.43x | 5 | 79.2 (mariadb:11.4) | 161.6 (mariadb:12.3) |

#### utf8mb4_0900_ai_ci, prefix

| form | µs per lookup | vs a | rows read | fastest server | slowest server |
|---|---|---|---|---|---|
| a | 247.4 | 1x | 53 | 97.9 (mariadb:11.4) | 301.4 (mysql:9.6) |
| b1 | 245.9 | 0.99x | 53 | 98.8 (mariadb:11.4) | 17676.8 (mysql:9.6) |
| b2 | 252.6 | 1.02x | 53 | 97.3 (mariadb:11.4) | 17601.9 (mysql:9.6) |
| c | 14960.8 | 60.47x | 100003 | 7383.1 (mariadb:11.8) | 24295.2 (mysql:9.6) |
| d | 247.6 | 1x | 53 | 110 (mariadb:11.4) | 18269.3 (mysql:9.6) |
| e | 255 | 1.03x | 53 | 112 (mariadb:11.8) | 318 (percona/percona-server:8.0) |
| f | 261.7 | 1.06x | 53 | 114.8 (mariadb:11.8) | 320 (mysql:9.6) |
| g1 | 250.8 | 1.01x | 53 | 120 (mariadb:11.4) | 18119.7 (mysql:9.6) |
| g2 | 21705.9 | 87.74x | 100003 | 11252.6 (mariadb:11.4) | 25849.5 (percona/percona-server:8.0) |
| a IN | 570 | 1x | 257 | 203.4 (mariadb:11.8) | 40122.2 (mysql:9.6) |
| b1 IN | 517.4 | 0.91x | 257 | 212.8 (mariadb:11.8) | 20708.4 (mysql:9.6) |
| b2 IN | 524 | 0.92x | 257 | 212 (mariadb:11.8) | 20731.8 (mysql:9.6) |
| c IN | 18163.3 | 31.87x | 100003 | 9469 (mariadb:11.4) | 26932.8 (mysql:9.6) |
| d IN | 507.8 | 0.89x | 257 | 205.1 (mariadb:11.8) | 20637.7 (mysql:9.6) |
| f IN | 598.5 | 1.05x | 257 | 216.4 (mariadb:11.8) | 40539.6 (mysql:9.6) |
| g1 IN | 513.5 | 0.9x | 257 | 210.3 (mariadb:11.8) | 20777.5 (mysql:9.6) |
| g2 IN | 41674.3 | 73.11x | 100003 | 27753.4 (mariadb:11.8) | 46854.2 (mariadb:12.3) |
| g3 | 119.8 | 0.48x | 53 | 113.9 (mariadb:11.8) | 208.1 (mariadb:12.3) |
| g3 IN | 241.3 | 0.42x | 257 | 220 (mariadb:11.8) | 415.6 (mariadb:12.3) |

## Regenerating This Report

The Exact Match Matrix workflow (.github/workflows/exact-match-matrix.yml) runs the probe on every server in .github/db-matrix.json:

    gh workflow run exact-match-matrix.yml   # wait for the run to finish, then:
    gh run download --dir probes             # pick the latest Exact Match Matrix run
    php .github/scripts/exact-match-probe.php --merge probes/*/exact-match-probe-*.json > docs/internal/exact-match-matrix.md

Servers in this run: mysql:5.7 (5.7.44), mysql:8.0 (8.0.46), mysql:8.4 (8.4.13), mysql:9.6 (9.6.0), mysql:9.7 (9.7.4), mariadb:10.2 (10.2.44-MariaDB-1:10.2.44+maria~bionic), mariadb:10.2.6 (10.2.6-MariaDB-10.2.6+maria~jessie), mariadb:10.2.7 (10.2.7-MariaDB-10.2.7+maria~jessie), mariadb:10.3 (10.3.39-MariaDB-1:10.3.39+maria~ubu2004), mariadb:10.4 (10.4.34-MariaDB-1:10.4.34+maria~ubu2004), mariadb:10.5 (10.5.29-MariaDB-ubu2004), mariadb:10.6 (10.6.28-MariaDB-ubu2204), mariadb:10.11 (10.11.19-MariaDB-ubu2204), mariadb:11.4 (11.4.13-MariaDB-ubu2404), mariadb:11.8 (11.8.9-MariaDB-ubu2404), mariadb:12.3 (12.3.3-MariaDB-ubu2404), percona/percona-server:5.7 (5.7.44-48), percona/percona-server:8.0 (8.0.46-37), percona/percona-server:8.4 (8.4.11-12)
