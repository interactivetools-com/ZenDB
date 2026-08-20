# Method Reference

The `DB` class is a static facade over a default connection; methods below also
exist on `Connection` instances (`$db->select(...)`) with the same signature,
except the four marked `DB` only and connecting itself (`new Connection($config)`
connects in the constructor). Method names link to the guide section that
covers each in depth.

Contents:

- [Connecting](#connecting)
- [Querying Data](#querying-data)
- [Modifying Data](#modifying-data)
- [Custom SQL](#custom-sql)
- [Table Names](#table-names)
- [Query Helpers](#query-helpers)
- [Encryption](#encryption)
- [Constants](#constants)
- [Properties](#properties)
- [Parameter Forms](#parameter-forms)

## Connecting

| Method                                                                                              | Returns      | Description                                                                                                                               |
|-----------------------------------------------------------------------------------------------------|--------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| [`connect(array $config = [])`](getting-started.md#connecting---dbconnect)                          | `void`       | Connect and set the default connection. Throws `RuntimeException` if already connected                                                    |
| `isConnected(bool $ping = false)`                                                                   | `bool`       | Check if the default connection is set, optionally pinging the server                                                                     |
| `disconnect()`                                                                                      | `void`       | Close the default connection and clear `$mysqli` and `$tablePrefix`                                                                       |
| [`clone(array $config = [])`](multiple-connections.md#same-connection-different-settings---dbclone) | `Connection` | Copy of the default connection sharing the same mysqli link; only `tablePrefix`, `useSmartJoins`, and `useSmartStrings` can be overridden |
| [`new Connection(array $config = [])`](multiple-connections.md#a-second-database---new-connection)  | `Connection` | Standalone connection (connects in the constructor); use for a second database or different settings                                      |

See [Configuration Options](getting-started.md#configuration-options) for the
full list of `$config` keys.

## Querying Data

| Method                                                                                                        | Returns          | Description                                                                                 |
|---------------------------------------------------------------------------------------------------------------|------------------|---------------------------------------------------------------------------------------------|
| [`select(string $baseTable, $whereEtc = [], ...$params)`](querying-data.md#selecting-rows---dbselect)         | `SmartArrayHtml` | All matching rows                                                                           |
| [`selectOne(string $baseTable, $whereEtc = [], ...$params)`](querying-data.md#fetching-one-row---dbselectone) | `SmartArrayHtml` | First matching row (sends `LIMIT 1`); empty result object when no row matches, never `null` |
| [`count(string $baseTable, $whereEtc = [], ...$params)`](querying-data.md#counting-rows---dbcount)            | `int`            | `SELECT COUNT(*)` of matching rows                                                          |

Declared return type is `SmartArrayBase`; the object you get is a
`SmartArrayHtml` of `SmartString` values by default, or a plain-value
`SmartArray` when `useSmartStrings` is false. On Smart Join queries,
`->{'table.column'}` reads the dotted keys.
[Working with Results](working-with-results.md) covers both.

`selectOne()`, `queryOne()`, and `count()` throw if `$where` or the template
contains `LIMIT` or `OFFSET` ("This method doesn't support LIMIT or OFFSET");
the escape hatch is `DB::query(...)->first()`.

## Modifying Data

| Method                                                                                                          | Returns | Description                                                                                                                                          |
|-----------------------------------------------------------------------------------------------------------------|---------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| [`insert(string $baseTable, array $values)`](modifying-data.md#inserting-rows---dbinsert)                       | `int`   | Insert one row; returns the new auto-increment ID (0 when the table has no auto-increment column)                                                    |
| [`update(string $baseTable, array $values, $whereEtc, ...$params)`](modifying-data.md#updating-rows---dbupdate) | `int`   | Update matching rows; returns rows actually changed (MySQL `affected_rows`), not rows matched                                                        |
| [`delete(string $baseTable, $whereEtc, ...$params)`](modifying-data.md#deleting-rows---dbdelete)                | `int`   | Delete matching rows; returns rows deleted                                                                                                           |
| [`transaction(callable $fn)`](modifying-data.md#transactions---dbtransaction)                                   | `mixed` | Run `$fn` in a transaction: commit on return, rollback and rethrow on exception; returns `$fn`'s return value. Throws `RuntimeException` when nested |

`update()` and `delete()` require a WHERE condition; an empty one throws. To
intentionally update every row, pass the literal string `"TRUE"`. Details in
[Modifying Data](modifying-data.md).

## Custom SQL

| Method                                                                                                     | Returns          | Description                                                                                                        |
|------------------------------------------------------------------------------------------------------------|------------------|--------------------------------------------------------------------------------------------------------------------|
| [`query(string $sqlTemplate, ...$params)`](joins-and-custom-sql.md#custom-sql---dbquery-and-dbqueryone)    | `SmartArrayHtml` | Run custom SQL with placeholders                                                                                   |
| [`queryOne(string $sqlTemplate, ...$params)`](joins-and-custom-sql.md#custom-sql---dbquery-and-dbqueryone) | `SmartArrayHtml` | First row of custom SQL (appends `LIMIT 1` to `SELECT`/`WITH` statements); empty result object when no row matches |

Both return the same result objects as `select()`; see
[Querying Data](#querying-data) above.

## Table Names

| Method                                                                                                                                       | Returns  | Description                                                                                                                              |
|----------------------------------------------------------------------------------------------------------------------------------------------|----------|------------------------------------------------------------------------------------------------------------------------------------------|
| [`getBaseTable(string $table, bool $checkDb = false)`](helpers-and-utilities.md#table-prefix-conversion---dbgetfulltable-and-dbgetbasetable) | `string` | Strip `tablePrefix` from a table name; with `$checkDb`, queries the database to resolve base names that themselves start with the prefix |
| [`getFullTable(string $table, bool $checkDb = false)`](helpers-and-utilities.md#table-prefix-conversion---dbgetfulltable-and-dbgetbasetable) | `string` | Prepend `tablePrefix` to a table name; with `$checkDb`, queries the database to resolve the same ambiguity                               |

## Query Helpers

| Method                                                                                                   | Returns  | Description                                                                                                  |
|----------------------------------------------------------------------------------------------------------|----------|--------------------------------------------------------------------------------------------------------------|
| [`rawSql(string\|int\|float\|null $value)`](helpers-and-utilities.md#trusted-sql-expressions---dbrawsql) | `RawSql` | `DB` only. Mark a value as literal SQL, skipping escaping and quoting (e.g., `NOW()`); `null` becomes `NULL` |
| [`pagingSql(mixed $page, mixed $perPage = 10)`](helpers-and-utilities.md#pagination---dbpagingsql)       | `RawSql` | `DB` only. `LIMIT $perPage OFFSET ...` clause; zero, empty, or invalid input becomes page 1 / 10 per page    |
| [`likeContains($input)`](helpers-and-utilities.md#like-patterns---dblikecontains-and-friends)            | `RawSql` | Escaped `LIKE` pattern `'%value%'`                                                                           |
| [`likeStartsWith($input)`](helpers-and-utilities.md#like-patterns---dblikecontains-and-friends)          | `RawSql` | Escaped `LIKE` pattern `'value%'`                                                                            |
| [`likeEndsWith($input)`](helpers-and-utilities.md#like-patterns---dblikecontains-and-friends)            | `RawSql` | Escaped `LIKE` pattern `'%value'`                                                                            |
| [`likeContainsTSV($input)`](helpers-and-utilities.md#like-patterns---dblikecontains-and-friends)         | `RawSql` | Escaped `LIKE` pattern `'%\tvalue\t%'` for matching one value in a tab-separated column                      |

The `like*()` methods accept `string|int|float|null|SmartString` and escape
`%` and `_` in the input, so a search for `"50%"` matches the literal text:

```php
$news = DB::select('news', "title LIKE ?", DB::likeContains($_GET['q'] ?? ''));
// for q=50% this runs: WHERE title LIKE '%50\\%%'

$page  = $_GET['page'] ?? 1;
$users = DB::select('users', "ORDER BY name :pagingSQL", [
    ':pagingSQL' => DB::pagingSql($page, 25),
]);
// for page 1 this runs: ORDER BY name LIMIT 25 OFFSET 0
```

## Encryption

Encryption is opt-in: set `encryptionKey` in the connect config and
`MEDIUMBLOB` columns auto-encrypt on `insert()`/`update()` and auto-decrypt on
read. These helpers cover values that bypass those methods.

| Method                                                                                                               | Returns        | Description                                                                                                                                                                                         |
|----------------------------------------------------------------------------------------------------------------------|----------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [`encryptValue($value)`](encryption.md#searching-encrypted-columns---dbencryptvalue)                                 | `string\|null` | Encrypt a value in PHP, matching what `insert()`/`update()` produce; `null` in, `null` out (before any key check). Anything else throws `RuntimeException` when `encryptionKey` is not set          |
| [`decryptExpr(string $column)`](encryption.md#decrypting-in-mysql-with-column)                                       | `string`       | `DB` only. SQL expression to decrypt a column server-side: `decryptExpr('email')` → `` AES_DECRYPT(`email`, @ek) ``. The `{{column}}` template syntax generates this for you                        |
| [`decryptRows(array &$rows, array $keysOrFetchFields)`](encryption.md#decrypting-raw-mysqli-results---dbdecryptrows) | `void`         | Decrypt raw mysqli rows in place; pass `$result->fetch_fields()` to auto-detect `MEDIUMBLOB` columns, or name the keys yourself (column names for associative rows, field indexes for numeric rows) |
| [`getEncryptedColumns(array $fetchFields)`](encryption.md#decrypting-raw-mysqli-results---dbdecryptrows)             | `array`        | `DB` only. The `MEDIUMBLOB` columns in a result, from `$result->fetch_fields()`, keyed by field index: `[2 => 'apiToken']`                                                                          |

`encryptValue()` accepts `string|int|float|null|SmartString`. Use it for exact
matches on encrypted columns (the encryption is deterministic):

```php
$user = DB::selectOne('users', ['token' => DB::encryptValue($searchToken)]);
```

## Constants

| Constant                                                              | Value           | Description                                                          |
|-----------------------------------------------------------------------|-----------------|----------------------------------------------------------------------|
| [`DATETIME`](helpers-and-utilities.md#date-and-time-format-constants) | `'Y-m-d H:i:s'` | `date()` format for MySQL `DATETIME` columns (`2026-03-16 14:30:00`) |
| [`DATE`](helpers-and-utilities.md#date-and-time-format-constants)     | `'Y-m-d'`       | `date()` format for MySQL `DATE` columns (`2026-03-16`)              |
| [`TIME`](helpers-and-utilities.md#date-and-time-format-constants)     | `'H:i:s'`       | `date()` format for MySQL `TIME` columns (`14:30:00`)                |

```php
DB::insert('news', ['title' => 'Launch day', 'publishDate' => date(DB::DATETIME)]);
```

## Properties

| Property                                                   | Type             | Description                                                                                                                                                                                                   |
|------------------------------------------------------------|------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [`$mysqli`](security-gotchas.md#raw-queries---dbmysqli)    | `?MysqliWrapper` | The underlying connection (a `mysqli` subclass) for direct access: `DB::$mysqli->insert_id`, `DB::$mysqli->query($ddl)`, and `DB::$mysqli->lastQuery` (last SQL sent, for debugging). `null` when disconnected |
| [`$tablePrefix`](getting-started.md#configuration-options) | `string`         | The prefix prepended to table names, as set at connect (`''` by default)                                                                                                                                      |

## Parameter Forms

**`$baseTable`** is the table name without the prefix; `tablePrefix` is
prepended automatically. Names may only contain `a-z`, `A-Z`, `0-9`, `_`,
and `-`; anything else throws `InvalidArgumentException`.

**`$whereEtc`** takes two forms (array or SQL string; counting the string
form's positional and named placeholder variants separately gives the three
call forms in [Querying Data](querying-data.md)), or can be omitted to match
all rows in `select()`, `selectOne()`, and `count()`:

```php
// Array: column => value pairs, joined with AND
// (null values become IS NULL, array values become IN (...))
$users = DB::select('users', ['status' => 'active', 'city' => 'Vancouver']);
// WHERE `status` = 'active' AND `city` = 'Vancouver'

// String: SQL with placeholders; the WHERE keyword is optional,
// and ORDER BY / GROUP BY / LIMIT can follow
$users = DB::select('users', "status = ? ORDER BY name LIMIT 10", 'active');
// WHERE status = 'active' ORDER BY name LIMIT 10
```

**`...$params`** takes up to 3 positional values passed directly for `?`
placeholders. For 4 or more values, use named placeholders in a single array:

```php
// Up to 3 values: pass them directly
$users = DB::select('users', "status = ? AND age > ?", 'active', 25);

// 4+ values: one array of named placeholders
$users = DB::select('users', "city = :city AND status = :status AND age BETWEEN :min AND :max", [
    ':city'   => 'Vancouver',
    ':status' => 'active',
    ':min'    => 18,
    ':max'    => 65,
]);
```

Mixing `?` and `:name` in one query throws.

**`$sqlTemplate`** is raw SQL using `::table` for prefixed table names, `?`
and `:name` for values, `` `?` `` and `` `:name` `` for identifiers, `::?` and
`:::name` for prefixed values, and `{{column}}` for encrypted-column reads.
Quotes, inline numbers, and hex literals are rejected before the query runs
(the allowed exceptions: a trailing literal `LIMIT 10`, and empty string
literals like `!= ''`). Full rules in
[Placeholders](placeholders.md) and
[Joins and Custom SQL](joins-and-custom-sql.md).

**`$values`** (for `insert()`/`update()`) is an associative array of column
names to values. Use `DB::rawSql()` for SQL expressions:

```php
DB::insert('news', ['title' => 'Launch day', 'createdDate' => DB::rawSql('NOW()')]);
// INSERT INTO `news` SET `title` = 'Launch day', `createdDate` = NOW()
```

---

[← Troubleshooting](troubleshooting.md) | [Documentation Index](README.md) | [Next: AI Reference →](ai-reference.md)
