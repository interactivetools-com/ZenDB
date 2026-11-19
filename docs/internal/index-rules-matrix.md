# Index Rules Matrix

An index on a TEXT, BLOB or long VARCHAR column needs a prefix length, and the servers
don't agree on which lengths they take: one refuses a length that another shortens with a
warning. This page checks one prefix rule for creating an index, on every supported
database server. The rule: TEXT and BLOB types get `(768)`, `VARCHAR(n)` and
`VARBINARY(n)` get `(min(n, 768))`, and every other type gets no prefix.

*Generated 2026-11-19 from 19 servers. Every result is in [index-rules-matrix-data.md](data/index-rules-matrix-data.md).*

## The Short Version

- **`(768)` is the longest prefix every server accepts on a utf8mb4 column.** 768
  characters at 4 bytes each is 3,072 bytes, the key limit of a DYNAMIC table. At `(769)`
  MySQL, Percona and MariaDB 10.2.6 and 10.2.7 refuse the index with error 1071; MariaDB
  10.2.44 and 10.3+ create it at 768 with a warning
- **TEXT and BLOB columns need a prefix.** Without one, every MySQL and Percona and
  MariaDB thru 10.3 give error 1170. MariaDB 10.4+ picks a prefix itself
- **TINYTEXT and TINYBLOB can't take `(768)` on MySQL and Percona**, which refuse a
  prefix over 255 bytes on them. `(63)` on TINYTEXT and `(255)` on TINYBLOB work on every
  server
- **A VARCHAR prefix as long as the column isn't a prefix.** `(255)` on `VARCHAR(255)`
  indexes the whole column: SHOW INDEX and SHOW CREATE TABLE show no prefix, and ORDER BY
  uses the index. `(250)` is a real prefix index, and ORDER BY does a filesort
- **These numbers are for ROW_FORMAT=DYNAMIC**, the default on all 19 servers. A COMPACT
  or REDUNDANT table stops at 767 bytes, which is 191 utf8mb4 characters

## The Prefix for Each Column Type

On a DYNAMIC utf8mb4 table.

| Column type | Accepted on every server | Otherwise |
|---|---|---|
| `TINYTEXT` | `(63)` | `(255)` and `(768)` are error 1071 on MySQL and Percona; MariaDB cuts them to 255 |
| `TINYBLOB` | `(255)` | `(768)` is error 1071 on MySQL and Percona; MariaDB cuts it to 255 |
| `TEXT` | `(768)` | No prefix is error 1170 on MySQL, Percona and MariaDB thru 10.3 |
| `VARCHAR(n)` | No prefix up to `VARCHAR(768)`, `(768)` above it | `(769)`, or no prefix on `VARCHAR(769)`, is error 1071 or a shortened index |
| `VARBINARY(n)` | No prefix up to `VARBINARY(3072)` | `VARBINARY(3073)` with no prefix is error 1071 or a shortened index |
| `CHAR(255)`, `BINARY(255)`, `ENUM`, `SET`, `DECIMAL(10,2)`, `DATETIME` | No prefix | |
| `JSON`, `GEOMETRY` | Nothing | See [JSON and GEOMETRY](#json-and-geometry) |

`MEDIUMTEXT`, `LONGTEXT`, `BLOB`, `MEDIUMBLOB` and `LONGBLOB` were only run with no prefix.
They give the same error 1170 as `TEXT` there, and MariaDB 10.4+ picks 768 for the text
types and 3072 for the blob types.

A lowercase type name (`tinytext`) gets the same answers as `TINYTEXT` on every server,
and SHOW CREATE TABLE prints the type in lowercase on every server.

## The Rule Against the Results

| The rule | On the 19 servers |
|---|---|
| TEXT and BLOB types get `(768)` | Accepted on `TEXT` everywhere. `TINYTEXT` and `TINYBLOB` need `(63)` and `(255)` on MySQL and Percona |
| `VARCHAR(n)` and `VARBINARY(n)` get `(min(n, 768))` | Accepted everywhere. With n at 768 or under it's a whole-column index, the same as no prefix. `VARBINARY` could go to 3072 |
| Every other type gets no prefix | Accepted for the six ordinary types. `JSON` and `GEOMETRY` can't take a plain index on MySQL and Percona |
| Tables are DYNAMIC | True of a new table on every server. On a COMPACT or REDUNDANT table the limit is 191 characters, so `(768)` fails |

## What Each Server Does at the Limit

| | MySQL, Percona, MariaDB 10.2.6 and 10.2.7 | MariaDB 10.2.44 and 10.3+ |
|---|---|---|
| `(769)` on a utf8mb4 column, or no prefix on `VARCHAR(769)` | Error 1071 | Index created at 768, warning 1071 |
| No prefix on `VARBINARY(3073)` | Error 1071 | Index created at 3072, warning 1071 |

| | MySQL, Percona, MariaDB thru 10.3 | MariaDB 10.4+ |
|---|---|---|
| No prefix on a TEXT or BLOB type | Error 1170 | Picks 255 for `TINYTEXT` and `TINYBLOB`, 768 for the other text types, 3072 for the other blob types. Warning 1071 on the 768 and 3072 cases |

MariaDB shortens a prefix with a warning at most, and with none on `TINYTEXT` and
`TINYBLOB`. SHOW INDEX reports the prefix the server used.

## Other Row Formats

COMPACT and REDUNDANT tables stop at 767 bytes (191 utf8mb4 characters) on every server,
so `VARCHAR(768)` with no prefix and `TEXT (768)` both fail there. MySQL and Percona give
error 1071 "max key length is 767 bytes". MariaDB gives error 1709 "Index column size too
large. The maximum column size is 767 bytes". COMPACT was the default row format before
MySQL 5.7.

`innodb_large_prefix` is ON by default on MySQL/Percona 5.7 and MariaDB 10.2. With it
OFF, a DYNAMIC table stops at 767 bytes too: MySQL/Percona 5.7 give error 1071, and
MariaDB 10.2 cuts the index to 191 with a warning (10.2.6 and 10.2.7 do that for `TEXT`
and give error 1071 for `VARCHAR(768)`). MariaDB 10.3 to 10.5 show the variable as empty,
and MariaDB 10.6+ and MySQL/Percona 8.0+ don't have it.

## JSON and GEOMETRY

| Type | MySQL and Percona | MariaDB |
|---|---|---|
| `JSON` | Error 3152 on every version | 10.2.6 has no JSON type (error 1064 at CREATE TABLE). 10.2.7 thru 10.3 treat it as `LONGTEXT` and give error 1170. 10.4+ index it at 768 with warning 1071 |
| `GEOMETRY` | Error 1170 on 5.7. On 8.0+ error 1252 "All parts of a SPATIAL index must be NOT NULL" | Error 1170 thru 10.3. 10.4+ index it at 3072 with warning 1071 |

## Full Results

Every case on every server is in [index-rules-matrix-data.md](data/index-rules-matrix-data.md):
each server's InnoDB settings, then one table per question with the prefix SHOW INDEX
reported, the error and warning text, and the EXPLAIN and SHOW CREATE TABLE lines.

## Regenerating This Report

This page and its data file are generated, so change them in the script and not by hand.
The sections above are in the heredocs in .github/scripts/index-rules-merge.php.

    gh workflow run index-rules-matrix.yml   # wait for the run to finish, then:
    gh run download --dir probes             # pick the latest Index Rules Matrix run
    php .github/scripts/index-rules-merge.php probes/*/index-probe-*.json > docs/internal/index-rules-matrix.md
    php .github/scripts/index-rules-merge.php --data probes/*/index-probe-*.json > docs/internal/data/index-rules-matrix-data.md
