# ZenDB Changelog

## [Unreleased]

### Fixed
- SmartJoin - Prefixed keys (jointable.name, j.name) are now decrypted by default, matching the behavior of unprefixed keys
- Query methods - `$paramValues` is now reset on each query; previously, a follow-up query with fewer params than placeholders silently bound leftover values from the prior call instead of throwing
- Placeholders - `::?` / `:::name` now throw on non-string values instead of producing invalid SQL
- `DB::transaction()` - Set `$inTransaction` after `START TRANSACTION` succeeds, not before, so a failed start doesn't leave the flag stuck
- `DB::insert()` / `DB::update()` - Passing an array as a column value now throws `InvalidArgumentException`; previously, single-element arrays silently stored only the first value and multi-element arrays triggered MySQL error 1064
- `DB::queryOne()` / `DB::selectOne()` - Now rejects `FOR UPDATE`, `FOR SHARE`, and `LOCK IN SHARE MODE` to prevent the appended `LIMIT 1` from breaking MySQL syntax
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
