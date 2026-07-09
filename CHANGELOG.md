# ZenDB Changelog

## [Unreleased]

### Added
- `::` works inside `{{}}` - Encrypted-column reads take the table prefix the same way the rest of the query does: `{{::users.apiToken}}` expands to `` AES_DECRYPT(`cms_users`.`apiToken`, @ek) ``, matching `FROM ::users`. Write the column reference as you would unencrypted, wrapped in braces; alias qualifiers pass through as written (`{{u.apiToken}}`). Previously the prefix had to be hardcoded (`{{cms_users.apiToken}}`), which broke if `tablePrefix` changed; that form still works

## [1.0.0] - 2026-07-08

First stable release, and the first with a complete manual: task-oriented guides in `docs/` covering everything from your first query to joins, encryption, security, and troubleshooting, plus [ai-reference.md](docs/ai-reference.md), the whole API in one file for AI coding assistants.

### Added
- Documentation - guides organized by task ([start at the index](docs/README.md)): getting started, querying, results, modifying data, placeholders, joins and custom SQL, common patterns, helpers, multiple connections, encryption, security gotchas, and troubleshooting with exact error messages. Every example verified against the current source
- Prefixed value placeholders - `::?` and `:::name` (no backticks) prepend the table prefix inside the quoted value, for matching table names as strings:
  - `SHOW TABLES LIKE ::?` with `user%` → `SHOW TABLES LIKE 'cms_user%'`
  - `WHERE TABLE_NAME = :::table` with `users` → `WHERE TABLE_NAME = 'cms_users'`
  - `IN (:::tables)` with `['users', 'orders']` → `IN ('cms_users', 'cms_orders')`
  - Strings only (or arrays of strings); anything else throws `InvalidArgumentException`
- `Table` and `Server` - Internal classes for reading table facts (exists, columns, CREATE TABLE, primary key, indexes, foreign keys) and server facts (version, vendor, SSL). Internal API that may change between releases; the old table helpers are deprecated in their favor (below)

### Changed
- `escape()`, `escapef()`, and `escapeCSV()` - Marked `@internal`; they exist so ZenDB and CMS Builder can build their own SQL. Placeholders are the supported API

### Deprecated
- Positional values as a single array - `"id IN (?)"` with `[1, 2, 3]` was silently running as `IN (1)`; it now logs a deprecation warning and will throw in a future release. Use a named placeholder (`"id IN (:ids)"` with `[':ids' => [1, 2, 3]]`) or up to 3 direct values (`DB::select('users', 'id = ?', $id)`). Extra positional values a query doesn't use also warn (usually a missing `?`); unused named params stay allowed
- `DB::hasTable()`, `DB::getTableNames()`, `DB::getColumnDefinitions()` - Use `Table::exists()`, `Table::names()`, and `Table::columnDefinitions()` instead; the old forms still work and log deprecation warnings. Same for the `Connection` equivalents (IDE-only, no runtime warning). Note: `Table::columnDefinitions()` throws for unknown tables where `getColumnDefinitions()` returned `[]`

### Fixed
- `useSmartStrings => false` - Connections and clones with SmartStrings disabled now return plain `SmartArray` results with raw values; previously every query on them threw `InvalidArgumentException`. `query()`/`queryOne()`/`select()`/`selectOne()` return types widened from `SmartArrayHtml` to their shared parent `SmartArrayBase`
- Time zones past +13:00 - With `usePhpTimezone`, PHP zones Pacific/Kiritimati (+14:00) and Chatham DST (+13:45) failed to connect on MariaDB and MySQL before 8.0.19; those two offsets now map to time zone names every supported server accepts
- `versionRequired` - The version parser misread most real server strings (`10.5.29-MariaDB-ubu2004` parsed as `10.5.292004`); it now handles distro suffixes, MariaDB's handshake prefix, and Aurora's format. The error message also names the actual server product instead of calling everything MySQL
- `DB::transaction()` - When the connection dies mid-transaction, the closure's exception now reaches the caller; previously the failing `ROLLBACK` threw a second "server has gone away" that replaced the real cause
- Float values - Now written to SQL with exact round-trip precision; PHP's string cast rounds to 14 significant digits, so very large floats could silently match the wrong rows. `NAN` and `INF` now throw `InvalidArgumentException`
- SmartString values - Now escape by their original type everywhere: a wrapped `int`/`float`/`bool` becomes a typed SQL literal (`5`, `TRUE`) instead of a quoted string (`'5'`, `'1'`), and a wrapped `null` means SQL `NULL`: it writes `NULL` in SET clauses (was `''`), matches with `IS NULL` in WHERE arrays (was `= ''`), and is skipped in IN lists
- IN lists - `null` values are now skipped instead of emitting `NULL`, which never matches in `IN (...)` and makes a `NOT IN (...)` return zero rows; use `IS NULL` to match NULL rows
- Encrypted reads - A MEDIUMBLOB value that fails to decrypt (wrong `encryptionKey`, or the column holds unencrypted data) still passes through as raw bytes, but now triggers one `E_USER_WARNING` per connection naming the column (was silent)
- Table existence checks - Now answer false only for "no such table"; other failures like a dead connection or missing privilege throw instead of reading as a missing table
- Stricter input validation - Each of these previously produced wrong SQL or a confusing MySQL error, now they throw or reject up front: hex/binary/scientific literals (`0x1AF`, `0b1010`, `1e10`) in query templates (use placeholders), empty strings in backtick identifier placeholders, param names starting with `:_` (the deprecated prefix token, the value silently never bound), and table/column names with a trailing newline
- Result polyfill (PHP 8.1 without mysqlnd) - Fixed emulation gaps in raw-handle `execute_query()` / `prepare()->get_result()` results: JOINs selecting two same-named columns return both, writes return `true`, invalid `fetch_array()` mode throws `ValueError`, added `data_seek()`. ZenDB's own API doesn't use these paths
- Cross-server consistency - `Table::columnDefinitions()` and `Table::normalizeCreateTable()` return identical output for identical schemas on every supported server (display widths, default spellings, charset/collation noise normalized; quoted text never touched), verified by a behavior-probe matrix of 19 MySQL, MariaDB, and Percona versions ([docs/internal/db-behavior-matrix.md](docs/internal/db-behavior-matrix.md))
- Misc code and other minor improvements

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
