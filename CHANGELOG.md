# ZenDB Changelog

## [0.9.2] - 2026-07-05

### Added
- Prefixed value placeholders - `::?` and `:::name` (no backticks) prepend the table prefix inside the quoted value, for matching table names as strings:
  - `SHOW TABLES LIKE ::?` with `user%` → `SHOW TABLES LIKE 'cms_user%'`
  - `WHERE TABLE_NAME = :::table` with `users` → `WHERE TABLE_NAME = 'cms_users'`
  - `IN (:::tables)` with `['users', 'orders']` → `IN ('cms_users', 'cms_orders')`
  - Strings only (or arrays of strings); anything else throws `InvalidArgumentException`

### Fixed
- Template validation - Now also rejects hex (`0x1AF`), binary (`0b1010`), and scientific (`1e10`) numeric literals in query templates; use placeholders instead
- `DB::escapeCSV()` - Skips `null` values in the list instead of emitting `NULL`. A `null` never matches inside `IN (...)`, and one in a `NOT IN (...)` silently makes the whole clause return zero rows; use `IS NULL` to match NULL rows. Dedupe now runs on the escaped values, so type-distinct entries like `''` and `false` no longer collapse into one
- SmartString values now escape by their original type everywhere: a wrapped `int`/`float`/`bool` becomes a typed SQL literal (`5`, `TRUE`) instead of a quoted string (`'5'`, `'1'`), and a wrapped `null` now means SQL `NULL` - it writes `NULL` in SET clauses (was `''`), matches with `IS NULL` in WHERE arrays (was `= ''`), and is skipped in IN lists
- Result polyfill (PHP 8.1 without mysqlnd) - Fixed emulation gaps in the result object returned by raw-handle `execute_query()` / `prepare()->get_result()` (ZenDB's own API doesn't use these paths):
  - JOINs that select two same-named columns (`SELECT a.id, b.id`) now return both
  - Writes now return `true` instead of an empty result object
  - Invalid `fetch_array()` mode now throws `ValueError` like native mysqli
  - Added `data_seek()`
  - Known limitation (unchanged): reading `num_rows` / `field_count` throws `Error`; PHP can't expose these read-only properties on an emulated result, so count rows by fetching
- `DB::getColumnDefinitions()` - Identical schemas now return identical definition strings on MySQL and MariaDB:
  - Display widths cropped to match MySQL 8 (`int(11)` → `int`, `year(4)` → `year`; plain `tinyint(1)` and ZEROFILL keep theirs)
  - MariaDB's `DEFAULT current_timestamp()` normalized to `DEFAULT CURRENT_TIMESTAMP`
  - Column-level charset/collation removed when it just restates the table default

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
