# Internal Docs

Docs for people working on ZenDB itself, not for people using it in their apps.
Library users want the [documentation index](../README.md) instead.

- [style.md](style.md) - ZenDB-specific writing conventions on top of the shared docs standards.
- [design-decisions.md](design-decisions.md) - Why the API is shaped the way it is.
- [db-behavior-matrix.md](db-behavior-matrix.md) - CI-generated comparison of how every supported database server answers the same behavior probes.
- [index-rules-matrix.md](index-rules-matrix.md) - CI-generated check of the CMS Builder auto-index prefix rules (TEXT/BLOB `(768)`, VARCHAR `min(n, 768)`) on every supported database server.
- [fk-names-matrix.md](fk-names-matrix.md) - CI-generated check of how every supported database server names foreign keys (MariaDB 12.1+ allows the same name in two tables) and whether `Table::foreignKeys()` and `Table::foreignKeysReferencing()` report same-named keys correctly.
- [connect-charset-matrix.md](connect-charset-matrix.md) - CI-generated check of what connect sends to set the connection charset and the session it leaves, on every supported database server and PHP version, on servers started with default settings, `skip-character-set-client-handshake`, `init_connect`, and other charset settings, compared with ZenDB 1.0.0.
- [fast-mysql-escape.md](fast-mysql-escape.md) - Research: a 7-pair `str_replace()` is byte-identical to `real_escape_string()` under ZenDB's connection settings and ~40% faster inlined on PHP 8.1-8.3. Pays off in tight bulk loops (backup dumps, exports), not in the normal query path. Also holds the escape benchmark suite's research findings, methodology rules, and remaining work.
