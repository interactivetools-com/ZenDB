# ZenDB Changelog

> **Upgrading?** See [UPGRADING.md](UPGRADING.md) for the checks that matter, per version - tagged releases roll up every change since the previous tag. Versions bundled with CMS Builder are marked on their sections.

## [UNRELEASED]

### Added

- **Named locks: `DB::withLock()`, `DB::getLock()` and `DB::releaseLock()`** - `withLock()` runs a function while holding a MySQL named lock, so two copies of the same job never run at the same time. When another connection holds the lock it returns `false` without calling the function. Lock names are specific to the database and `tablePrefix`, and a lock still held when the request ends is released then, on persistent connections too. `DB::disconnect()` throws while the connection holds one. See [Named Locks](docs/named-locks.md)
- **`Table::foreignKeys()` reports `refDatabase`** - a foreign key can reference a table in another database

### Fixed

- **`Table::foreignKeysReferencing()` lists same-named keys separately** - it was keyed by name, so two keys with the same name were merged into one entry. MariaDB 12.1+ names keys `1`, `2`, `3` in every table
- **`Table::foreignKeys()` gives every constraint name as a string** - on MariaDB 12.1+ the names `1`, `2`, `3` came back as int array keys
- **Ints and floats stay native after a persistent connection is replaced** - with a `p:` hostname, once the server closed an idle connection (a restart, `wait_timeout`, a KILL), that PHP worker returned int and float columns as strings until it restarted. Connections that stayed open were not affected
- **Connections are utf8mb4 on servers that override the charset at login** - with `skip-character-set-client-handshake` or a charset in `init_connect`, the connection stayed on the server's charset: non-ASCII text was stored and read wrong, and values were escaped for the wrong charset. On those servers the connection collation is now the server's default for utf8mb4
- **Failed queries throw when other code has turned off mysqli error reporting** - after another library in the same request called `mysqli_report(MYSQLI_REPORT_OFF)` (WordPress does when it connects), a failed query returned an empty result, `0` or `-1`, and `transaction()` committed the statements that had worked. It now throws `mysqli_sql_exception` as usual, with `(mysqli_report() was turned off by other code)` added to the message
- **`DB::count()` throws when a paging value leaves no count to return** - with `DB::pagingSql()` in the WHERE it returned `0` with a PHP warning from page 2 on (page 1 gave the right total). It now throws `InvalidArgumentException`: pass `count()` the WHERE without the paging value. A `GROUP BY` or `HAVING` that leaves no row returns `0` without the warning
- **`DB::disconnect()` throws inside `DB::transaction()`** - closing the connection makes MySQL roll the transaction back, and queries the callback ran after reconnecting were committed on their own. In 1.0.0 `transaction()` then threw `Error: Attempt to assign property "inTransaction" on null`, and on a `new Connection()` it returned as if it had committed

### Changed

- **Connect sends one `SET NAMES utf8mb4, time_zone, sql_mode` statement on every server** - MySQL and Percona 8.0+ sent two. A `queryLogger` sees the new statement text
- **`Table::indexes()`, `Table::foreignKeys()` and `Table::foreignKeysReferencing()` return a plain list** - no longer keyed by name, so read `name` from each entry. `foreignKeysReferencing()` entries have a new `database` field. See [UPGRADING.md](UPGRADING.md)

### Internal

- **`connect()` no longer sets `MYSQLI_OPT_LOCAL_INFILE`** - the call ran before the connect, where it has no effect: PHP sets the option from `mysqli.allow_local_infile` when it connects. `LOAD DATA LOCAL INFILE` is controlled by that php.ini setting, as it was before, and PHP has it off by default

### Minor

`Table::columnDefinitions()` and `Table::normalizeCreateTable()` keep `CHARACTER SET` on a column in a table whose collation was changed by hand. `Table::columnNames()` returns an all-digit column name such as `2024` as a string, not an int. `DB::queryOne()` and `DB::selectOne()` with a string WHERE take about 0.3 µs less PHP time per call.

## [1.0.0] - 2026-10-16

> **Bundled with CMS Builder v3.85**

The headlines: ZenDB runs 3x faster in PHP (real pages, MySQL time included, run up to 40% faster), and 1.0 has complete online docs plus [ai-reference.md](docs/ai-reference.md), the whole API in one file for AI coding assistants. Everything else is fixes and stricter checks.

### Requirements

- **SmartArray 3.0+ and SmartString 3.0+** - query results are their objects, so check their upgrade notes too (linked from [UPGRADING.md](UPGRADING.md))

### Added

- **Documentation** - guides organized by task in [docs/README.md](docs/README.md), every example verified against the current source
- **Prefixed value placeholders** - `::?` and `:::name` (no backticks) add the table prefix inside the quoted value, for matching table names as strings: `SHOW TABLES LIKE ::?` with `user%` → `SHOW TABLES LIKE 'cms_user%'`
- **`::` works inside `{{}}`** - encrypted-column reads take the table prefix like the rest of the query: `{{::users.apiToken}}` expands to `` AES_DECRYPT(`cms_users`.`apiToken`, @ek) ``. Hardcoded prefixes still work
- **`Table` and `Server`** - internal classes for reading table facts (exists, columns, indexes, ...) and server facts (version, vendor, SSL); the old table helpers are deprecated in their favor (below)

### Performance

- **ZenDB is 3x faster than v0.9.1**, not counting MySQL's own time. Including MySQL time, real pages run up to 40% faster. What a page costs today: [docs/performance.md](docs/performance.md)

### Changed

- **SmartNull values act like `null` everywhere** - `insert()`, `update()`, and WHERE arrays treat a SmartNull (usually a missed lookup) as `null`, the same as placeholders
- **`tablePrefix` can no longer contain dots** - `connect()` throws "Invalid tablePrefix" naming the allowed characters. Rename steps in [UPGRADING.md](UPGRADING.md)
- **`connect()` throws if `NO_BACKSLASH_ESCAPES` is on** - whether it's set in `sqlMode` or, when `sqlMode` is `''`, in the server's global `sql_mode`. Remove the mode to connect
- **`escape()`, `escapef()`, and `escapeCSV()` marked `@internal`** - IDEs flag them; use placeholders instead

### Parameter renames

Only named-argument calls are affected: an old name throws PHP's "Unknown named parameter" error. Search strings in [UPGRADING.md](UPGRADING.md).

| Method              | Old parameter  | New parameter        |
|---------------------|----------------|----------------------|
| `DB::pagingSql()`   | `$pageNum`     | `$page`              |
| `DB::decryptRows()` | `$fetchFields` | `$keysOrFetchFields` |

### Deprecated

Deprecated calls keep working and raise a notice naming their replacement and your file and line.

- **Positional values as a single array** - `"id IN (?)"` with `[1, 2, 3]` runs as `IN (1)`. Use a named placeholder: `"id IN (:ids)"` with `[':ids' => [1, 2, 3]]`
- **Extra positional values** - `DB::select('users', 'num = ?', 5, 6)` logs a deprecation notice; drop the unused values
- **Record numbers as the WHERE argument** - `DB::select('users', 5)` logs a deprecation notice and `'5'` logs a warning; use `['num' => 5]`
- **Table helpers** - old names still work, IDE strikethrough only:

  | Old name (still works)       | Current name                 |
  |------------------------------|------------------------------|
  | `DB::hasTable()`             | `Table::exists()`            |
  | `DB::getTableNames()`        | `Table::names()`             |
  | `DB::getColumnDefinitions()` | `Table::columnDefinitions()` |

  One difference: `Table::columnDefinitions()` throws for unknown tables where `getColumnDefinitions()` returned `[]`

### Fixed

- **`useSmartStrings => false` works** - connections with SmartStrings disabled return raw `SmartArray` results
- **`NOT IN` with an empty list matches all rows** - `IN` with an empty list still matches none. Null values in IN lists are skipped (they never match); use `IS NULL` to match NULL rows
- **SmartString values keep their type** - a wrapped int writes `5`, and a wrapped null writes `NULL` and matches `IS NULL` in WHERE arrays (both were written as strings). See [UPGRADING.md](UPGRADING.md)
- **Floats keep exact precision** - large floats are written exactly, so equality matches can't hit the wrong rows. `NAN` and `INF` throw
- **`DB::transaction()` reports the real error** - when the connection dies mid-transaction, the catch block gets your exception, not "server has gone away"
- **`versionRequired` reads real server versions** - including distro builds (`10.5.29-MariaDB-ubu2004`) and Aurora, so servers below the minimum fail to connect. See [UPGRADING.md](UPGRADING.md)
- **`DB::pagingSql()` accepts huge page numbers** - a page number from the URL can't cause a MySQL syntax error
- **LIMIT in a subquery or comment no longer throws** - in `selectOne()`, `queryOne()` and `count()`, and neither does a backtick-quoted column named `offset` or `limit`. Same for `FOR UPDATE` in `selectOne()` and `queryOne()`
- **Failed decryption warns** - a MEDIUMBLOB value that won't decrypt comes back as raw bytes with one warning per connection naming the column
- **Invisible MEDIUMBLOB columns are encrypted** - `insert()` and `update()` encrypt `INVISIBLE` columns (MySQL 8.0.23+, MariaDB 10.3+) like any other MEDIUMBLOB
- **Encrypted columns match in any letter case** - `insert()` and `update()` encrypt `['Token' => ...]` for a `token` MEDIUMBLOB column. Booleans for an encrypted column throw
- **Encryption follows schema changes** - after DDL or `USE` through `DB::query()` on any clone, writes and reads use the new column types. After DDL through raw `DB::$mysqli`, reconnect
- **Time zones past +13:00 connect** - with `usePhpTimezone`, zones like Pacific/Kiritimati (+14:00) connect on MariaDB and MySQL before 8.0.19

### Minor

Stricter checks: invalid table names, multibyte table prefixes, hex and binary literals in query templates, placeholders inside `/* */` comments, spread named-param arrays (`...$params`), and other inputs that used to produce wrong SQL now throw with a message naming the fix (list in [UPGRADING.md](UPGRADING.md)). Also: `Table::columnDefinitions()` returns the same output on every supported server, the database password, encryption key, and encrypted-column values stay out of stack traces on PHP 8.2+, fixes for PHP builds without mysqlnd, and clearer error messages that wrap names and values in backticks instead of quotes.

## [0.9.1] - 2026-04-22

### Fixed
- SmartJoin - Prefixed keys (jointable.name, j.name) are now decrypted by default, matching the behavior of unprefixed keys
- Query methods - `$paramValues` is now reset on each query; previously, a follow-up query with fewer params than placeholders silently bound leftover values from the prior call instead of throwing
- Placeholders - `::?` / `:::name` now throw on non-string values instead of producing invalid SQL
- `DB::transaction()` - Set `$inTransaction` after `START TRANSACTION` succeeds, not before, so a failed start doesn't leave the flag stuck
- `DB::insert()` / `DB::update()` - Passing an array as a column value now throws `InvalidArgumentException`; previously, single-element arrays silently stored only the first value and multi-element arrays triggered MySQL error 1064
- `DB::queryOne()` / `DB::selectOne()` - Now rejects `FOR UPDATE`, `FOR SHARE`, and `LOCK IN SHARE MODE` to prevent the appended `LIMIT 1` from breaking MySQL syntax
- `DB::queryOne()` / `DB::selectOne()` - Now rejects trailing `--` / `#` and `;` that would break the auto-appended `LIMIT 1`
- `DB::rawSql()` - Now returns `'NULL'` when it receives `null` (previously returned an empty string)
- `DB::escapef()` - Placeholder / value count mismatch now throws; previously, missing values silently became NULL and extras were dropped
- `DB::$mysqli->lastQuery` - No longer overwritten by the internal encryption probe during DB::insert() / DB::update()

### Changed
- Encryption - `encryptionKey` now automatically encrypts/decrypts all MEDIUMBLOB fields if set.  Mixed usage (some encrypted, some not) no longer supported
- Require `itools/smartarray` `^2.6.6`

### Removed
- `DB::like()` - Use `DB::likeContains()`, `DB::likeStartsWith()`, or `DB::likeEndsWith()` instead

---

## [0.9.0] - 2026-03-31
> Initial public release

First public release on Packagist. ZenDB is a PHP/MySQL database layer that's easy to use and hard to misuse.

### Highlights
- **SQL injection is impossible** - Queries with inline values are rejected. Every dynamic value goes through placeholders, not because you remembered, but because there's no other way. Named (`:name`) and positional (`?`) placeholders, plus backtick variants for identifiers
- **XSS is prevented by default** - Results come back as SmartArrays of SmartStrings that HTML-encode themselves on output. You don't call `htmlspecialchars()`, and neither does the next developer
- **Fast to learn** - Methods mirror SQL: `select`, `insert`, `update`, `delete`. If you know MySQL, you already know ZenDB
- **Smart joins** - Table-prefixed keys let you walk join results as `$row->users->name`
- **Transactions** - `DB::transaction()` with automatic commit, rollback, and nesting prevention
- **Automatic column encryption** - Configure `encryptionKey` and `MEDIUMBLOB` columns are transparently encrypted on write and decrypted on read
- **Query helpers** - `DB::rawSql()`, `DB::pagingSql()`, `DB::likeContains()`, `DB::encryptValue()`, and friends
