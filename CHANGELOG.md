# ZenDB Changelog

## [0.9.2] - 2026-07-05

### Added
- `Table` - Experimental internal class for reading table details on the default connection (`Table::exists('users')`); each connection has its own instance bound to its `tablePrefix` (`$connection->table->exists()`), so clones check their own tables. See the class docblock
- `Table::showCreateTable()` - A table's CREATE TABLE statement, verbatim as SHOW CREATE TABLE returns it (raw mysqli, prefix-aware, plain string)
- `Table::normalizeCreateTable()` - Normalizes a CREATE TABLE statement for cross-server portability, string in, string out: crops deprecated int/year display widths (signed `tinyint(1)` and ZEROFILL columns keep theirs), strips column-level CHARACTER SET/COLLATE clauses matching the table's own defaults, and strips collations that are some server version's built-in default (including MariaDB's uca1400 names, which don't exist on MySQL) so each server applies its own default on replay. Quoted text (COMMENT, DEFAULT, enum values) is never modified, and engines and charsets replay as-is: it removes server-version noise, it doesn't upgrade schemas
- `Server` (`DB::$server`) - Experimental internal class for reading server details; see the class docblock
- Prefixed value placeholders - `::?` and `:::name` (no backticks) prepend the table prefix inside the quoted value, for matching table names as strings:
  - `SHOW TABLES LIKE ::?` with `user%` → `SHOW TABLES LIKE 'cms_user%'`
  - `WHERE TABLE_NAME = :::table` with `users` → `WHERE TABLE_NAME = 'cms_users'`
  - `IN (:::tables)` with `['users', 'orders']` → `IN ('cms_users', 'cms_orders')`
  - Strings only (or arrays of strings); anything else throws `InvalidArgumentException`
- `DB::assertIdentifier($identifier, $what)` - Throws `InvalidArgumentException` unless a string is a safe SQL identifier (letters, numbers, `_`, `-`). The same rule every table and column name already passes through internally, now callable directly for identifiers placeholders can't cover, like a user-picked sort column; `$what` names the value in the error message
- Security footguns guide (`docs/09-security-footguns.md`) - The narrow ways to defeat the safety guarantees on purpose, each with its safe form: raw queries through `DB::$mysqli`, interpolating user input into a quoted template, dynamic identifiers like `ORDER BY`, `rawHtml()` output, NULL and empty arrays in `IN` lists, and the encryption threat model

### Deprecated
- `DB::hasTable()` and `DB::getTableNames()` - Use `Table::exists()` and `Table::names()`/`namesFull()` instead; both still work and now log deprecation warnings. `DB::tableExists()`'s warning now also points at `Table::exists()`
- `Connection::hasTable()` and `Connection::getTableNames()` - Use `$connection->table->exists()` and `->table->names()`/`namesFull()` instead; marked deprecated for IDEs, no runtime warning
- `DB::getColumnDefinitions()` and `Connection::getColumnDefinitions()` - Use `Table::columnDefinitions()` / `$connection->table->columnDefinitions()` instead, which now applies the same cross-server normalizations; the deprecated forms still return `[]` for unknown tables where the replacement throws

### Fixed
- `useSmartStrings => false` - Connections and clones with SmartStrings disabled now return plain `SmartArray` results with raw values; previously every query on them threw `InvalidArgumentException` at result wrapping. `query()`/`queryOne()`/`select()`/`selectOne()` return types widened from `SmartArrayHtml` to their shared parent `SmartArrayBase`. `Table` methods now query raw mysqli internally, so they behave identically regardless of connection settings
- `Table::columnDefinitions()` - Numeric defaults now read quoted the way MySQL prints them (MariaDB's `DEFAULT 0` → `DEFAULT '0'`), so identical schemas return identical definition strings on every supported server; both servers accept either form in DDL. Keywords (`NULL`), expressions (`CURRENT_TIMESTAMP`, `uuid()`), bit literals, and string defaults are untouched. Quoted text is protected throughout: a default, comment, or generated-column expression containing phrases like `DEFAULT 5` or `CHARACTER SET utf8mb4` passes through byte-identical
- Table existence checks - `Table::exists()`/`existsFull()`, the deprecated `hasTable()`/`tableExists()` wrappers, and `getBaseTable()`/`getFullTable()` table checking now return false only for "no such table" (MySQL error 1146); other failures like a dead connection or missing privilege throw instead of reading as a missing table (previously any error answered false)
- `DB::transaction()` - When the connection dies mid-transaction, the closure's exception now reaches the caller; previously the failing `ROLLBACK` threw a second "server has gone away" that replaced the real cause
- Float values - Now written to SQL with exact round-trip precision; PHP's string cast rounds to 14 significant digits, so very large floats could silently match the wrong rows. `NAN` and `INF` now throw `InvalidArgumentException` instead of producing a MySQL syntax error
- Encrypted reads - A MEDIUMBLOB value that fails to decrypt (wrong `encryptionKey`, or the column holds unencrypted data) still passes through as raw bytes, but now triggers one `E_USER_WARNING` per connection naming the column (was silent)
- `DB::escapeCSV()` - Now accepts `RawSql` values in the list (e.g. `DB::rawSql('NOW()')`), matching every other value path
- Template validation - Now also rejects hex (`0x1AF`), binary (`0b1010`), and scientific (`1e10`) numeric literals in query templates; use placeholders instead
- `DB::escapeCSV()` - Skips `null` values in the list instead of emitting `NULL`. A `null` never matches inside `IN (...)`, and one in a `NOT IN (...)` silently makes the whole clause return zero rows; use `IS NULL` to match NULL rows. Dedupe now runs on the escaped values, so type-distinct entries like `''` and `false` no longer collapse into one
- SmartString values now escape by their original type everywhere: a wrapped `int`/`float`/`bool` becomes a typed SQL literal (`5`, `TRUE`) instead of a quoted string (`'5'`, `'1'`), and a wrapped `null` now means SQL `NULL` - it writes `NULL` in SET clauses (was `''`), matches with `IS NULL` in WHERE arrays (was `= ''`), and is skipped in IN lists
- Backtick identifier placeholders - `` `?` `` / `` `:name` `` now reject an empty-string value instead of emitting empty backticks and a MySQL syntax error
- Named params - Names can't start with `:_` (e.g. `:_id`). `:_` is the deprecated table-prefix token that rewrites to `::`, so a `:_name` param used to silently lose its value; now it throws
- Table and column name validation - Now also rejects names with a trailing newline (`"users\n"` passed the `^...$` regex check and failed later in MySQL)
- Result polyfill (PHP 8.1 without mysqlnd) - Fixed emulation gaps in the result object returned by raw-handle `execute_query()` / `prepare()->get_result()` (ZenDB's own API doesn't use these paths):
  - JOINs that select two same-named columns (`SELECT a.id, b.id`) now return both
  - Writes now return `true` instead of an empty result object
  - Invalid `fetch_array()` mode now throws `ValueError` like native mysqli
  - Added `data_seek()`
  - `num_rows` / `field_count` reads now return real counts (previously threw `Error: object is already closed`)
- `DB::getColumnDefinitions()` - Identical schemas now return identical definition strings on MySQL and MariaDB:
  - Display widths cropped to match MySQL 8 (`int(11)` → `int`, `year(4)` → `year`; plain `tinyint(1)` and ZEROFILL keep theirs)
  - MariaDB's `DEFAULT current_timestamp()` normalized to `DEFAULT CURRENT_TIMESTAMP`
  - Column-level charset/collation removed when it just restates the table default
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
