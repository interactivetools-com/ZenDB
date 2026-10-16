# Upgrading ZenDB

Most old code keeps working after an upgrade:

- **If it breaks, it tells you.** Old names phase out over multiple
  releases - IDE strikethrough, then a quietly logged notice with your file
  and line (CMS Builder shows these in the Developer Log), then a clear
  error - always naming the replacement.
- **Everything worth checking is listed here.** Silent behavior changes,
  deprecations, and optional renames, per version, each with a search that
  finds affected code.

Upgrading ZenDB also upgrades SmartArray and SmartString - query results
are their objects, so check their upgrade notes too:

- [SmartArray UPGRADING.md](https://github.com/interactivetools-com/SmartArray/blob/main/UPGRADING.md)
- [SmartString UPGRADING.md](https://github.com/interactivetools-com/SmartString/blob/main/UPGRADING.md)

Full lists of what changed per release: [CHANGELOG.md](CHANGELOG.md).

---

## v1.0.0

*Follow this section when upgrading from ZenDB before v1.0.0
(or CMS Builder before 3.85).*

### Config changes

> - `tablePrefix` can no longer contain dots or any other character
>   outside `a-z A-Z 0-9 _ -`; `connect()` throws "Invalid tablePrefix"
>   naming the allowed characters. An install with a dotted prefix (CMS Builder's
>   installer used to accept ones like `'client2.cms_'`) needs a one-time
>   rename before upgrading:
>
>   ```sql
>   RENAME TABLE `client2.cms_news` TO `client2_cms_news`;  -- repeat per table
>   ```
>
>   ```php
>   'tablePrefix' => 'client2_cms_',  // was 'client2.cms_'
>   ```
>
>   Search: `tablePrefix` in your config.
>
> - `connect()` throws when `NO_BACKSLASH_ESCAPES` is on, whether it's set
>   in `sqlMode` or, when `sqlMode` is `''`, in the server's global
>   `sql_mode`. Remove the mode to connect.
>
>   Search: `NO_BACKSLASH_ESCAPES` in your config; if `sqlMode` is `''`,
>   also check `SELECT @@GLOBAL.sql_mode` on the server.

### Behavior changes

> - `versionRequired` reads real server version strings, so `connect()`
>   can throw on a server it used to accept:
>
>   ```php
>   'versionRequired' => '10.5.30',
>   // server "10.5.29-MariaDB-ubu2004":
>   // before this read: 10.5.292004 - passed the check
>   // after this reads: 10.5.29     - throws, names the server's real version
>   ```
>
>   Search: `versionRequired`
>
> - `DB::hasTable()`, `DB::tableExists()`, and `DB::getColumnDefinitions()`
>   throw on a dead connection instead of returning false or `[]`. A missing
>   table, broken view, or missing privilege still returns false or `[]`.

### Silent changes

> - A null result field (a SmartString) passed to `insert()`, `update()`,
>   or a WHERE array writes SQL NULL, not `''`: NOT NULL columns reject the
>   write, and `['col' => $field]` matches `col IS NULL`. Placeholders are
>   unchanged. Review code that passes query results back into these.
> - A SmartNull (a field read from a lookup that found no row) in
>   `insert()`/`update()` values or a WHERE array writes NULL and matches
>   `IS NULL`. Check the lookup first when a miss should stop the write:
>
>   ```php
>   $user = DB::selectOne('users', ['email' => $email]);  // no row found
>   DB::delete('sessions', ['userNum' => $user->num]);
>   // before this threw: Unsupported value type for column 'userNum'
>   // after this runs:   DELETE ... WHERE `userNum` IS NULL
>   ```
>
> - IN lists skip null values, and `NOT IN` with an empty list matches every
>   row (it matched none). `IN` with an empty list still matches nothing.
>   Search: `NOT IN` where the list can be empty or contain null.
>
>   ```php
>   DB::select('users', "status NOT IN (:statuses)", [':statuses' => ['banned', null]]);
>   // before this ran: NOT IN ('banned', NULL)  - one NULL made it return zero rows
>   // after this runs: NOT IN ('banned')        - returns rows again
>   ```
>
> - `DB::transaction()`: when the connection dies mid-transaction, catch
>   blocks get the exception your code threw, not "server has gone away".
>   Search: `DB::transaction(` and check catch blocks that look for that
>   message.
>
> - `DB::getColumnDefinitions()` returns the same strings on every server,
>   so some definitions read differently. On MariaDB,
>   `tinyint NOT NULL DEFAULT 0` now reads
>   `tinyint(1) NOT NULL DEFAULT '0'`, and `DEFAULT current_timestamp()`
>   reads `DEFAULT CURRENT_TIMESTAMP`. Search: `getColumnDefinitions(`
>   where the result is compared to saved definitions.
>
> - Floats are written with exact precision, and `NAN` or `INF` throws:
>
>   ```php
>   DB::select('measurements', 'value = ?', 12345678901234567.0);
>   // before this ran: value = 1.2345678901235E+16   - rounded, can match the wrong rows
>   // after this runs: value = 12345678901234568.0   - the exact value PHP holds
>   ```

### New errors for inputs that used to produce wrong SQL

> Edge cases only; normal queries and placeholders are unchanged. Each of
> these now throws the first time the query runs, with a message naming the
> fix:
>
> - Hex, binary, and scientific literals (`0x1AF`, `0b1010`, `1e10`) in
>   query templates - pass the value through a placeholder instead
> - Empty strings in backtick identifier placeholders
> - Param names starting with `:_` (the value never bound)
> - Table or column names with a trailing newline
> - Placeholders inside `/* */` comments (a value containing `*/` closed
>   the comment, so the rest ran as SQL) - move the value out of the
>   comment
> - A spread named-param array - `DB::query($sql, ...$params)` where
>   `$params` is `[':id' => 5]`. Drop the `...`:
>
>   ```php
>   DB::query($sql, ...$params);   // throws
>   DB::query($sql, $params);      // same params, supported form
>   ```

### Deprecations

> Deprecated calls keep working and raise a notice naming their replacement
> and your file and line. In this release:
>
> - Positional values as a single array - `DB::query($sql, [1, 2, 3])` - use
>   a named placeholder (`"id IN (:ids)"` with `[':ids' => [1, 2, 3]]`) or up
>   to 3 direct values. Fix these first: with an IN list the array form runs
>   as `IN (1)`, first value only, and it will throw in a future release.
>   Regex:
>   `["']\s*,\s*\[\s*(?:"(?!:)[^"]*"\s*[,\]]|'(?!:)[^']*'\s*[,\]]|[^"'\s])`
>   finds an array of values right after a quoted SQL template and skips
>   named-placeholder arrays (starting `':` or `":`) and `'column' =>`
>   arrays. Also check calls that pass the SQL in a variable.
> - More direct values than `?` placeholders -
>   `DB::select('users', 'num = ?', 5, 6)` - the extra values are never
>   used. Drop them, or use a named placeholder for an IN list. This will
>   throw in a future release.
> - `escape()`, `escapef()`, and `escapeCSV()` are marked `@internal` (IDEs
>   flag them); placeholders are the supported API. No runtime change.
> - Record numbers as the WHERE argument - `DB::select('users', 5)` - use
>   `['num' => 5]` instead. `5` and `'5'` both run as `['num' => 5]` and log:
>
>   ```php
>   DB::select('users', 5);            // works, logs a deprecation notice
>   DB::select('users', '5');          // works, logs a warning (used to throw)
>   DB::select('users', ['num' => 5]); // current form, nothing logged
>   ```
>
>   Both forms will throw in a future release. No search needed: each log
>   entry names your file and line.

### Parameter renames

Two parameters have new names. Positional calls work unchanged; a
named-argument call using the old name throws PHP's "Unknown named
parameter" error the first time it runs:

```php
DB::pagingSql(pageNum: 2, perPage: 25);  // throws: Unknown named parameter $pageNum
DB::pagingSql(page: 2, perPage: 25);     // correct
```

| Method              | Old named argument | New named argument   |
|---------------------|--------------------|----------------------|
| `DB::pagingSql()`   | `pageNum:`         | `page:`              |
| `DB::decryptRows()` | `fetchFields:`     | `keysOrFetchFields:` |

Search: `pageNum:` and `fetchFields:`

### Optional renames

The old names still work, and IDEs flag them with the replacement. A later
release will log a notice with your file and line, and a release after that
will throw, so rename when convenient.

| Old name (still works)       | Current name                 |
|------------------------------|------------------------------|
| `DB::hasTable()`             | `Table::exists()`            |
| `DB::getTableNames()`        | `Table::names()`             |
| `DB::getColumnDefinitions()` | `Table::columnDefinitions()` |

Two differences when migrating: `Table::exists()` throws for names with
characters outside `a-z A-Z 0-9 _ -` where `DB::hasTable()` returns false,
and `Table::columnDefinitions()` throws for unknown tables where
`getColumnDefinitions()` returns `[]`.

## v0.9.1

*Follow this section when upgrading from ZenDB before v0.9.1
(or CMS Builder before 3.83).*

### Removed: `DB::like()`

> Calling it throws an exception naming the replacements:
>
> ```php
> $sql = DB::like($value);          // throws: DB::like() has been removed
> $sql = DB::likeContains($value);  // correct - also likeStartsWith(), likeEndsWith()
> ```
>
> Search: `DB::like(`

### Silent changes

> - Ints and floats return as native PHP types when coming from a ZenDB
>   bundled with CMS Builder (3.82 or earlier); every public ZenDB release
>   already did this. The connection sets `MYSQLI_OPT_INT_AND_FLOAT_NATIVE`
>   (requires mysqlnd, PHP's default driver), so it also applies to raw
>   mysqli queries run on `DB::$mysqli`. Strict comparisons against strings
>   stop matching:
>
>   ```php
>   $row = DB::$mysqli->query("SELECT * FROM users LIMIT 1")->fetch_assoc();
>   $row['id'] === '5';          // before: true - after: false ($row['id'] is int 5)
>   $row['id'] === $_GET['id'];  // same - request values are always strings
>   (string)$row['id'] === '5';  // works either way
>   ```
>
>   The version-by-version history is in CMS Builder's UPGRADING.md, v3.83
>   section. Search: `===` and `!==` comparisons of query values against
>   strings.
>
> - `DB::rawSql(null)` returns `'NULL'` (was an empty string), so SQL built
> with it contains the NULL keyword where it had a gap.

### New errors for code that silently got wrong results

> Each of these now throws; before, the query ran and did the wrong thing:
>
> - A follow-up query with fewer params than placeholders - it used to
>   silently bind leftover values from the prior call.
> - An array passed as a column value to `DB::insert()` / `DB::update()` -
>   single-element arrays used to store just their first value.
> - A `DB::escapef()` placeholder and value count mismatch - missing values
>   used to become NULL and extras were dropped.

---

*End of upgrade notes. v0.9.0 (2026-03-31) was ZenDB's first public release.
Earlier versions shipped only inside CMS Builder (3.63 through 3.82), and
CMS Builder's own changelog and upgrade notes cover moving off those.*
