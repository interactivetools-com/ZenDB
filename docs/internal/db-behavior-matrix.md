# DB Behavior Matrix

MySQL, MariaDB and Percona Server answer the same statement differently in places: what
SHOW CREATE TABLE prints, which error code comes back, what a variable is called, what a
column defaults to. This page lists where the supported servers disagree, from the same
probes run on each one, so code that has to work on all of them can be written against
what they return.

*Generated 2026-11-19 from 22 servers. Every result is in [db-behavior-matrix-data.md](data/db-behavior-matrix-data.md).*

## The Short Version

- **Percona Server answers like the same MySQL version.** Every probe is byte-identical
  apart from the version strings, so it needs no handling of its own
- **MySQL and MariaDB print SHOW CREATE TABLE differently** for the same table: quoted or
  typed defaults, `CURRENT_TIMESTAMP` or `current_timestamp()`, `int` or `int(11)`. Text
  from two servers can't be compared as it comes
- **To see whether a connection is encrypted, read session status `Ssl_cipher`.** It's
  empty exactly when the connection isn't encrypted, on every server. Each of the
  variables is missing or misleading somewhere
- **A `CHARSET=utf8mb4` table gets one of three collations**, depending on the server:
  `0900_ai_ci`, `general_ci` or `uca1400_ai_ci`
- **For a backup snapshot, send `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`, then
  `START TRANSACTION WITH CONSISTENT SNAPSHOT`.** That pair holds one on every server. A
  bare START at READ-COMMITTED never does
- **Add and drop CHECK constraints with `ADD CONSTRAINT` and `DROP CONSTRAINT`**, the one
  pair every server that enforces them accepts. MySQL/Percona 5.7 parse a CHECK and
  ignore it
- **AES_ENCRYPT gives the same ciphertext on every server**, so encrypted data moves
  between them

## Versions

- Percona Server answers every probe byte-identically to the same MySQL version, except
  the version-string probes (its build suffix). Nothing else needs separate handling
- Every version source agrees: `server_info` matches `VERSION()` byte for byte, and
  mysqlnd's `server_version` int matches to the patch level, with Percona's build suffix
  dropped correctly
- That int is parsed on the client. PHP before 8.0.16 and 8.1.3 read MariaDB's `5.5.5-`
  handshake prefix as 50505 (php-src GH-7972)

## TLS

Session status `Ssl_cipher` and `Ssl_version` exist on every server and are empty exactly
when the connection is unencrypted. None of the variables works on every server.

| Variable | Where it can't be relied on |
|---|---|
| `@@have_ssl` | Removed in MySQL/Percona 8.4. Reads DISABLED on stock MariaDB thru 10.11 and YES on MariaDB 11.4+, which generates its own certificates |
| `@@tls_version` | Missing on MariaDB thru 10.3 |
| `@@require_secure_transport` | Missing on MariaDB thru 10.4 |

## SHOW CREATE TABLE and Column Defaults

| | MySQL and Percona | MariaDB |
|---|---|---|
| A numeric default | Quoted: `'0'` | A typed literal: `0` |
| A timestamp default | `CURRENT_TIMESTAMP` | `current_timestamp()` |
| Int display width | `int` on 8.0+ | Kept: `int(11)` |
| `MEDIUMTEXT DEFAULT 'abc'` | Rejected: "can't have a default value". Only the parenthesized expression form works, on MySQL 8.0.13+ | Accepted |
| A nullable text column | Prints no default | Prints the implicit `DEFAULT NULL` |

A bare TIMESTAMP column gets an implicit DEFAULT CURRENT_TIMESTAMP ON UPDATE on
MySQL/Percona 5.7 and MariaDB thru 10.6. On MySQL/Percona 8.0+ and MariaDB 10.11+ it
stays plain and nullable, because the `explicit_defaults_for_timestamp` default changed.

## CHECK Constraints

- **MySQL/Percona 5.7 don't enforce them.** Table-level and inline column CHECKs are
  parsed and silently ignored there
- **A named column CHECK** (`age INT CONSTRAINT name CHECK (...)`) is a 1064 syntax error
  on MySQL/Percona 5.7 and every MariaDB. Only MySQL/Percona 8.0+ accept it
- **Every other server enforces them, in its own way.** The error code is 3819 on MySQL
  and 4025 on MariaDB. In SHOW CREATE TABLE, MySQL moves column CHECKs into named
  constraints and MariaDB keeps them inline
- **Adding and dropping:** ADD CONSTRAINT / DROP CONSTRAINT is the one pair every 8.0.16+
  and MariaDB server accepts. DROP CHECK and NOT ENFORCED are MySQL-only
- **MODIFY COLUMN with an inline CHECK** adds another auto-named constraint each time on
  MySQL 8.0+. On MariaDB it replaces the column's CHECK, which also can't be dropped by
  name there
- **Renaming a column a CHECK references** is rejected on MySQL 8.0+ (error 3959).
  MariaDB 10.2.44 and 10.3+ rewrite the expression
- **DROP COLUMN** silently deletes a single-column CHECK on both
- **MariaDB 10.2.6 and 10.2.7** reject the rename and the DROP COLUMN with error 1054
  "Unknown column 'age' in 'CHECK'"

## Collations, Table Lists and Result Metadata

SHOW CREATE TABLE for a `DEFAULT CHARSET=utf8mb4` table prints the default collation
three ways, and leaves it out on the oldest servers.

| Servers | COLLATE in SHOW CREATE TABLE |
|---|---|
| MySQL/Percona 5.7, MariaDB thru 10.2 | None stated (the table is `utf8mb4_general_ci`) |
| MySQL/Percona 8.0+ | `utf8mb4_0900_ai_ci` |
| MariaDB 10.3 to 10.11 | `utf8mb4_general_ci` |
| MariaDB 11.4+ | `utf8mb4_uca1400_ai_ci` |

- MariaDB 11.4+ lists temporary tables in SHOW TABLES and INFORMATION_SCHEMA.TABLES. Every
  other server hides them
- Result metadata through an aliased view: MySQL/Percona report the view's name in
  `orgtable`, MariaDB reports the query alias. Neither reports the underlying base table
- MEDIUMTEXT metadata charset varies by era (45, 255 or 224) but is never binary (63), so
  MEDIUMBLOB encryption detection is safe everywhere

## Time Zones and Encryption

- Time zone offsets beyond -12:59 to +13:00 are accepted only by MySQL/Percona 8.0+
  (widened in MySQL 8.0.19). Every other server rejects them with error 1298
- AES_ENCRYPT produces identical ciphertext on every server and matches PHP openssl
  aes-128-ecb, so encrypted data is fully portable

## Transactions and Snapshots

- **A consistent snapshot for backups:** SET TRANSACTION ISOLATION LEVEL REPEATABLE READ
  followed by START TRANSACTION WITH CONSISTENT SNAPSHOT holds a point-in-time snapshot
  on every server. It still works with other statements between the SET and the START,
  needs no privileges, and reverts after COMMIT
- **A bare START at READ-COMMITTED never holds one.** MySQL/Percona and MariaDB thru 10.5
  warn (code 138) and MariaDB 10.6+ gives no warning. With the RocksDB plugin loaded (no
  RocksDB table needed) MariaDB rejects it with error 4062, while Percona MyRocks only
  warns
- **SET TRANSACTION while a transaction is open** throws error 1568. That includes
  autocommit=0 after any table read; autocommit=0 alone is fine. The SET SESSION form is
  accepted mid-transaction on every server
- **The isolation variable has two names.** `@@transaction_isolation` is missing on
  MariaDB thru 10.11 (added in 11.1), and `@@tx_isolation` is missing on MySQL/Percona
  8.0+. Neither ever reports the pending one-shot level, so the only way to tell whether
  the snapshot took is to check what the transaction can see

## Pooled Connections

Reusing a pooled (`p:`) connection that the server closed for `wait_timeout` idling
recovers everywhere: a fresh connection, a new thread, no exception. MySQL/Percona 8.0+
write a final error packet before closing, so mysqlnd raises "Packets out of order"
(E_WARNING) during the reconnect. MariaDB and both 5.7s close the socket silently, and
KILL is warning-free on every server because no packet is written. ZenDB's connect
wrapper @-suppresses the warning.

## Full Results

Every probe is in [db-behavior-matrix-data.md](data/db-behavior-matrix-data.md), in the
order the probe script runs them. Comments in `src/` and `tests/` that cite this page by
a probe name, such as 'SHOW CREATE: oldText', mean a heading in that file.

Stock Docker images with default configs answered the probes. Install-dependent rows
(directory paths, sql_mode, TLS and log settings, version strings) will differ on real
servers, where distro packages, hosting panels, and my.cnf all change them. SHOW CREATE
formatting, error codes, collations, and result typing are fixed per server version.

## Regenerating This Report

This page and its data file are generated, so change them in the script and not by hand.
The sections above are in the heredocs in .github/scripts/db-behavior-merge.php.

    gh workflow run db-behavior-matrix.yml   # wait for the run to finish, then:
    gh run download --dir probes             # pick the latest DB Behavior Matrix run
    php .github/scripts/db-behavior-merge.php probes/*/probe-*.json > docs/internal/db-behavior-matrix.md
    php .github/scripts/db-behavior-merge.php --data probes/*/probe-*.json > docs/internal/data/db-behavior-matrix-data.md
