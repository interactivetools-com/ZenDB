# ZenDB Changelog

> **Upgrading?** See [UPGRADING.md](UPGRADING.md) for the checks that matter,
> per version - tagged releases roll up every change since the previous tag.
> Versions bundled with CMS Builder are marked on their sections.

## [1.0.0] - [UNRELEASED]

> **Bundled with CMS Builder v3.85**

The headlines: ZenDB's own code runs 3x faster (real pages, MySQL time
included, run up to 40% faster), and 1.0 has complete online docs plus
[ai-reference.md](docs/ai-reference.md), the whole API in one file for AI
coding assistants. Everything else is fixes and stricter checks.

### Added

- **Documentation** - guides organized by task
  ([start at the index](https://github.com/interactivetools-com/ZenDB/blob/main/docs/README.md)),
  every example verified against the current source
- **Prefixed value placeholders** - `::?` and `:::name` (no backticks) add
  the table prefix inside the quoted value, for matching table names as
  strings: `SHOW TABLES LIKE ::?` with `user%` → `SHOW TABLES LIKE 'cms_user%'`
- **`::` works inside `{{}}`** - encrypted-column reads take the table
  prefix like the rest of the query: `{{::users.apiToken}}` expands to
  `` AES_DECRYPT(`cms_users`.`apiToken`, @ek) ``. Hardcoded prefixes still
  work
- **`Table` and `Server`** - internal classes for reading table facts
  (exists, columns, indexes, ...) and server facts (version, vendor, SSL);
  the old table helpers are deprecated in their favor (below)

### Performance

- **ZenDB is 3x faster than v0.9.1**, not counting MySQL's own time. Including
  MySQL time, real pages run up to 40% faster. What a page costs today:
  [docs/performance.md](docs/performance.md)

### Changed

- **SmartNull values act like `null` everywhere** - `insert()`, `update()`,
  and WHERE arrays treat a SmartNull (usually a missed lookup) as `null`,
  the same as placeholders
- **`tablePrefix` can no longer contain dots** - `connect()` throws
  "Invalid tablePrefix" naming the allowed characters. Rename steps in
  [UPGRADING.md](UPGRADING.md)
- **`connect()` throws if `NO_BACKSLASH_ESCAPES` is on** - whether it's set
  in `sqlMode` or, when `sqlMode` is `''`, in the server's global
  `sql_mode`. Remove the mode to connect
- **`escape()`, `escapef()`, and `escapeCSV()` marked `@internal`** - IDEs
  flag them; use placeholders instead

### Parameter renames

Only named-argument calls are affected: an old name throws PHP's "Unknown
named parameter" error. Search strings in [UPGRADING.md](UPGRADING.md).

| Method              | Old parameter  | New parameter        |
|---------------------|----------------|----------------------|
| `DB::pagingSql()`   | `$pageNum`     | `$page`              |
| `DB::decryptRows()` | `$fetchFields` | `$keysOrFetchFields` |

### Deprecated

Deprecated calls keep working and raise a notice naming their replacement
and your file and line.

- **Positional values as a single array** - `"id IN (?)"` with `[1, 2, 3]`
  runs as `IN (1)`. Use a named placeholder: `"id IN (:ids)"` with
  `[':ids' => [1, 2, 3]]`
- **Record numbers as the WHERE argument** - `DB::select('users', 5)` logs
  a deprecation notice and `'5'` logs a warning; use `['num' => 5]`
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
  disabled return raw `SmartArray` results
- **`NOT IN` with an empty list matches all rows** - `IN` with an empty list
  still matches none. Null values in IN lists are skipped (they never
  match); use `IS NULL` to match NULL rows
- **SmartString values keep their type** - a wrapped int writes `5`, and a
  wrapped null writes `NULL` and matches `IS NULL` in WHERE arrays (both
  were written as strings). See [UPGRADING.md](UPGRADING.md)
- **Floats keep exact precision** - large floats are written exactly, so
  equality matches can't hit the wrong rows. `NAN` and `INF` throw
- **`DB::transaction()` reports the real error** - when the connection dies
  mid-transaction, the catch block gets your exception, not "server has
  gone away"
- **`versionRequired` reads real server versions** - distro suffixes
  (`10.5.29-MariaDB-ubu2004`), MariaDB's handshake prefix, and Aurora's
  format, so servers below the minimum fail to connect. See
  [UPGRADING.md](UPGRADING.md)
- **`DB::pagingSql()` accepts huge page numbers** - a page number from the
  URL can't cause a MySQL syntax error
- **LIMIT/OFFSET checks only see the top level** - in `selectOne()`,
  `queryOne()` and `count()`, a column named `offset` or `limit`, a keyword
  in a comment, or a LIMIT inside a subquery no longer throws. The same goes
  for `FOR UPDATE` in `selectOne()` and `queryOne()`
- **Failed decryption warns** - a MEDIUMBLOB value that won't decrypt comes
  back as raw bytes with one warning per connection naming the column
- **Invisible MEDIUMBLOB columns are encrypted** - `insert()` and `update()`
  encrypt `INVISIBLE` columns (MySQL 8.0.23+, MariaDB 10.3+) like any other
  MEDIUMBLOB
- **Encryption follows schema changes** - after DDL or `USE` through
  `DB::query()` on any clone, writes and reads use the new column types.
  After DDL through raw `DB::$mysqli`, reconnect
- **Time zones past +13:00 connect** - with `usePhpTimezone`, zones like
  Pacific/Kiritimati (+14:00) connect on MariaDB and MySQL before 8.0.19

### Minor

Stricter checks: invalid table names, multibyte table prefixes, hex and
binary literals in query templates, spread named-param arrays
(`...$params`), and other inputs that used to produce wrong SQL now throw
with a message naming the fix (list in [UPGRADING.md](UPGRADING.md)). Also:
`Table::columnDefinitions()` returns the same output on every supported
server, the encryption key and encrypted-column values stay out of stack
traces on PHP 8.2+, fixes for PHP builds without mysqlnd, and clearer
error messages.

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
