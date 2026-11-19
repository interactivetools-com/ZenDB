# Internal Docs

Docs for people working on ZenDB itself, not for people using it in their apps.
Library users want the [documentation index](../README.md) instead.

Each CI-generated page starts with The Short Version and holds what the results
mean. The results themselves, every check on every server, are in a file of the
same name in [data/](data/), such as
[data/collation-matrix-data.md](data/collation-matrix-data.md).

- [style.md](style.md) - ZenDB-specific writing conventions on top of the shared docs standards.
- [design-decisions.md](design-decisions.md) - Why the API is shaped the way it is.
- [db-behavior-matrix.md](db-behavior-matrix.md) - CI-generated comparison of how every supported database server answers the same behavior probes.
- [index-rules-matrix.md](index-rules-matrix.md) - CI-generated check of the CMS Builder auto-index prefix rules (TEXT/BLOB `(768)`, VARCHAR `min(n, 768)`) on every supported database server.
- [fk-names-matrix.md](fk-names-matrix.md) - CI-generated check of how every supported database server names foreign keys (MariaDB 12.1+ allows the same name in two tables) and whether `Table::foreignKeys()` and `Table::foreignKeysReferencing()` report same-named keys correctly.
- [collation-matrix.md](collation-matrix.md) - CI-generated comparison of the utf8mb4 collations on every supported database server: which one a `CHARSET=utf8mb4` table gets, where each costs speed (sorts and grouping with no index; `utf8mb4_0900_ai_ci` on MySQL 8.0+ and `utf8mb4_general_ci` elsewhere are the fastest ci collations), what to write when two collations meet in one statement, what converting a table costs, and which values compare equal.
- [rowid-matrix.md](rowid-matrix.md) - CI-generated check of where every supported database server accepts `_rowid` in place of a table's primary key column (one table: yes; joins need `alias._rowid`; views and composite keys have none), with verdicts per use and the cost of writing it bare: a few microseconds per parsed statement, none in backticks.
- [exact-match-matrix.md](exact-match-matrix.md) - CI-generated comparison of the ways to write an exact string comparison on every supported database server, against `col = ?`: `col = CAST(? AS BINARY)` matches only the identical value and keeps the same index, rows read and speed everywhere; `BINARY ?` is deprecated on MySQL 8.0+, `COLLATE utf8mb4_bin` still ignores trailing spaces, and `CAST(col AS BINARY)` reads every row. Also index size per column type, strings against INT columns, and unique-index collisions.
- [connect-charset-matrix.md](connect-charset-matrix.md) - CI-generated check of what connect sends to set the connection charset and the session it leaves, on every supported database server and PHP version, on servers started with default settings, `skip-character-set-client-handshake`, `init_connect`, and other charset settings, compared with ZenDB 1.0.0.
- [fast-mysql-escape.md](fast-mysql-escape.md) - Research: a 7-pair `str_replace()` is byte-identical to `real_escape_string()` under ZenDB's connection settings, and inlined in a bulk loop (backup dumps, exports) it ran 1.8x to 3.5x as fast on PHP 8.1 to 8.3 and 1.1x to 1.7x on 8.4+. In ZenDB's own queries whole-query time was a tie, so they keep `real_escape_string()`. Also holds the escape benchmark suite's research findings, methodology rules, and remaining work.
