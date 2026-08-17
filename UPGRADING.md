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

> - `tablePrefix` can no longer contain dots; `connect()` throws
>   "Invalid tablePrefix" naming the allowed characters
>   (`a-z A-Z 0-9 _ -`). CMS Builder's installer used to accept dotted
>   prefixes like `'client2.cms_'`, which named tables literally
>   (`client2.cms_news`, dot included). An install like that needs a
>   one-time rename to a dot-free prefix before upgrading:
>
>   ```sql
>   RENAME TABLE `client2.cms_news` TO `client2_cms_news`;  -- repeat per table
>   ```
>
>   ```php
>   'tablePrefix' => 'client2_cms_',  // was 'client2.cms_'
>   ```

### Behavior changes

> - `Table::exists()` and `Table::existsFull()` throw
>   `InvalidArgumentException` for names with characters outside
>   `a-z A-Z 0-9 _ -`, same as the other table methods. The deprecated
>   `DB::hasTable()` and `DB::tableExists()` keep returning false for
>   invalid names, so existing code only changes behavior when it moves
>   to the new methods.

### Silent changes

> - Passing a result field (a SmartString) back into a query as a value,
> which is uncommon: a field holding null now writes SQL NULL and matches
> IS NULL instead of `''`. NOT NULL columns reject that write with a clear
> MySQL error; nullable columns silently store NULL where they stored `''`.
> - Null values in IN lists are skipped instead of written as `NULL`. An
>   empty list still writes `NULL`, so `IN (NULL)` matches nothing. Search:
>   `NOT IN` where the value list can contain null.
>
>   ```php
>   DB::select('users', "status NOT IN (:statuses)", [':statuses' => ['banned', null]]);
>   // before this ran: NOT IN ('banned', NULL)  - one NULL made it return zero rows
>   // after this runs: NOT IN ('banned')        - returns rows again
>   ```
>
> - `versionRequired` used to misread most real server version strings, so
>   the check passed on servers it should have rejected. It now compares
>   the real version and can throw where it silently passed before.
>
>   ```php
>   'versionRequired' => '10.6.0',
>   // server "10.5.29-MariaDB-ubu2004":
>   // before this read: 10.5.292004 - passed the check
>   // after this reads: 10.5.29     - throws, names the server's real version
>   ```
>
>   Search: `versionRequired`
>
> - `DB::transaction()` - when the connection dies mid-transaction, catch
>   blocks now receive the exception your code threw; they used to get the
>   "server has gone away" error from the failing ROLLBACK that replaced it.
>   Search: `DB::transaction(`
>
> - Floats are written with exact round-trip precision; the old string cast
>   rounded to 14 significant digits, so equality matches against very
>   large floats could match the wrong rows. Passing `NAN` or `INF` now throws.
>
>   ```php
>   DB::select('measurements', 'value = ?', 12345678901234567.0);
>   // before this ran: value = 1.2345678901235E+16   - rounded, can match the wrong rows
>   // after this runs: value = 12345678901234568.0   - the exact value PHP holds
>   ```
>
> - Table existence checks used to answer false whenever anything went
>   wrong; now a dead connection throws (there's no answer to report),
>   while a missing table, broken view, or missing privilege still answer
>   false.

### New errors for inputs that used to produce wrong SQL

> Edge cases only - normal queries and placeholders are unchanged. Each of
> these used to silently produce wrong SQL and now throws up front with a
> message naming the fix, so it announces itself the first time the query
> runs:
>
> - Hex, binary, and scientific literals (`0x1AF`, `0b1010`, `1e10`) in
>   query templates - pass the value through a placeholder instead
> - Empty strings in backtick identifier placeholders
> - Param names starting with `:_` - the value silently never bound before
> - Table or column names with a trailing newline
> - A spread named-param array - `DB::query($sql, ...$params)` where
>   `$params` is `[':id' => 5]`. PHP turns that into named arguments, which
>   query methods don't accept. It used to bind them plus emit an
>   "Undefined array key 0" warning. Drop the `...`:
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
>   to 3 direct values. Worth fixing promptly: the array form will throw in
>   a future release, and with an IN list it was silently running as `IN (1)`
>   (first value only).
> - `escape()`, `escapef()`, and `escapeCSV()` are marked `@internal` (IDEs
>   flag them); placeholders are the supported API. No runtime change.
>
> Regex: `["']\s*,\s*\[\s*(?:["'](?!:)|[^"'\s])` - an array of values right
> after the SQL template (single- or double-quoted); named-placeholder
> arrays (starting `':` or `":`) are fine - also check calls that pass the
> SQL in a variable

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

No required changes yet: the old names still work, and IDEs and static
analysis flag them with the replacement to switch to. A later release logs
a deprecation notice with the caller's file and line, and a release after
that throws, so rename when convenient.

| Old name (still works)       | Current name                 |
|------------------------------|------------------------------|
| `DB::hasTable()`             | `Table::exists()`            |
| `DB::getTableNames()`        | `Table::names()`             |
| `DB::getColumnDefinitions()` | `Table::columnDefinitions()` |

One difference when migrating: `Table::columnDefinitions()` throws for
unknown tables where `getColumnDefinitions()` returned `[]`.

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
>   $row['id'] === '5';         // before: true - after: false ($row['id'] is int 5)
>   (string)$row['id'] === '5'; // works either way
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
