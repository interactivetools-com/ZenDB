# ZenDB Changelog

> **Upgrading?** See [UPGRADING.md](UPGRADING.md) for the checks that matter,
> per version - tagged releases roll up every change since the previous tag.
> Versions bundled with CMS Builder are marked on their sections.

## [1.0.0] - [UNRELEASED]

> **Bundled with CMS Builder v3.85**

The headlines: ZenDB is 3x faster (including MySQL time, real pages run up to
40% faster), and 1.0 has complete online docs plus
[ai-reference.md](docs/ai-reference.md), the whole API in one file for AI
coding assistants. Everything else is hardening and fixes.

### Added

- **Documentation** - guides organized by task
  ([start at the index](https://github.com/interactivetools-com/ZenDB/blob/main/docs/README.md)),
  every example verified against the current source
- **Prefixed value placeholders** - `::?` and `:::name` (no backticks) add
  the table prefix inside the quoted value, for matching table names as
  strings: `SHOW TABLES LIKE ::?` with `user%` → `SHOW TABLES LIKE 'cms_user%'`
- **`::` works inside `{{}}`** - encrypted-column reads take the table
  prefix like the rest of the query: `{{::users.apiToken}}` expands to
  `` AES_DECRYPT(`cms_users`.`apiToken`, @ek) ``. Previously the prefix had
  to be hardcoded (`{{cms_users.apiToken}}`); that form still works
- **`Table` and `Server`** - internal classes for reading table facts
  (exists, columns, indexes, ...) and server facts (version, vendor, SSL);
  the old table helpers are deprecated in their favor (below)

### Performance

- **ZenDB is 3x faster than v0.9.1**, not counting MySQL's own time. Including
  MySQL time, real pages run up to 40% faster. What a page costs today:
  [docs/performance.md](docs/performance.md)

### Changed

- **SmartNull values act like `null` everywhere** - `insert()`, `update()`,
  and WHERE arrays now treat a SmartNull (usually a missed lookup) like
  `null` instead of throwing, the same as placeholders already did
- **`tablePrefix` can no longer contain dots** - `connect()` throws
  "Invalid tablePrefix" naming the allowed characters; a dotted prefix
  reads as a `database.` qualifier, so ZenDB refuses at config time instead
  of guessing. Rename steps in [UPGRADING.md](UPGRADING.md)
- **`escape()`, `escapef()`, and `escapeCSV()` marked `@internal`** - they
  exist so ZenDB and CMS Builder can build their own SQL; placeholders are
  the supported API

### Deprecated

Deprecated calls keep working and raise a notice naming their replacement
and your file and line.

- **Positional values as a single array** - `"id IN (?)"` with `[1, 2, 3]`
  was silently running as `IN (1)`. Use a named placeholder:
  `"id IN (:ids)"` with `[':ids' => [1, 2, 3]]`
- **Table helpers** - old names still work, IDE strikethrough only:

  | Old name (still works)       | Current name                 |
  |------------------------------|------------------------------|
  | `DB::hasTable()`             | `Table::exists()`            |
  | `DB::getTableNames()`        | `Table::names()`             |
  | `DB::getColumnDefinitions()` | `Table::columnDefinitions()` |

  One difference: `Table::columnDefinitions()` throws for unknown tables
  where `getColumnDefinitions()` returned `[]`

### Fixed

- **`useSmartStrings => false` works** - connections with SmartStrings
  disabled return raw `SmartArray` results; previously every query on them
  threw
- **`NOT IN` with an empty list matches all rows** - empty arrays expand to
  a zero-row subquery instead of the literal `NULL`, which silently matched
  no rows; `IN` with an empty list still matches nothing. Null values inside
  IN lists are now skipped (they never match); use `IS NULL` to match NULL
  rows
- **SmartString values escape by their original type** - a wrapped int
  writes `5` (was `'5'`), and a wrapped null writes SQL `NULL` in SET
  clauses and matches `IS NULL` in WHERE arrays (was `''`). See
  [UPGRADING.md](UPGRADING.md)
- **Floats keep exact precision** - values are written with full round-trip
  precision instead of PHP's 14-digit rounding, so equality matches against
  very large floats can't hit the wrong rows; `NAN` and `INF` now throw
- **`DB::transaction()` reports the real error** - when the connection dies
  mid-transaction, your exception reaches the catch block; previously the
  failing ROLLBACK replaced it with "server has gone away"
- **`versionRequired` reads real server strings** - the parser misread most
  of them (`10.5.29-MariaDB-ubu2004` parsed as `10.5.292004`); distro
  suffixes, MariaDB's handshake prefix, and Aurora's format now parse
  correctly
- **`DB::pagingSql()` clamps huge page numbers** - the offset math could
  overflow to float notation, a MySQL syntax error reachable from any
  page-number URL parameter
- **Failed decryption warns** - a MEDIUMBLOB value that won't decrypt still
  passes through as raw bytes, but now triggers one warning per connection
  naming the column (was silent)
- **Time zones past +13:00 connect** - with `usePhpTimezone`, zones like
  Pacific/Kiritimati (+14:00) failed to connect on MariaDB and MySQL before
  8.0.19

### Minor

Stricter up-front validation: invalid table names, multibyte table
prefixes, hex/binary literals in query templates, spread named-param
arrays (`...$params`), and other inputs that silently produced wrong SQL
now throw with a message naming the fix (details in
[UPGRADING.md](UPGRADING.md)). Also: identical `Table::columnDefinitions()`
output across all supported servers (verified against a 19-server matrix),
fixes to the no-mysqlnd result polyfill, clearer error messages, and misc
internal cleanup.

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
