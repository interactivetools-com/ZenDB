# Method Reference

Every supported method, constant, and property in one place: signatures, return
types, and a one-line description each. `DB::` is a static facade over a
default connection; methods below also exist on `Connection` instances
(`$db->select(...)`) with the same signature, except the four marked `DB` only
and connecting itself (`new Connection($config)` connects in the constructor).

## Connecting

| Method                                | Returns      | Description                                                                                                                               |
|---------------------------------------|--------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| `DB::connect(array $config = [])`     | `void`       | Connect and set the default connection. Throws `RuntimeException` if already connected                                                    |
| `DB::isConnected(bool $ping = false)` | `bool`       | Check if the default connection is set, optionally pinging the server                                                                     |
| `DB::disconnect()`                    | `void`       | Close the default connection and clear `DB::$mysqli` and `DB::$tablePrefix`                                                               |
| `DB::clone(array $config = [])`       | `Connection` | Copy of the default connection sharing the same mysqli link; only `tablePrefix`, `useSmartJoins`, and `useSmartStrings` can be overridden |
| `new Connection(array $config = [])`  | `Connection` | Standalone connection (connects in the constructor); use for a second database or different settings                                      |

See [Getting Started](getting-started.md) for the full list of `$config` keys.

## Reading Rows

| Method                                                         | Returns          | Description                                                                                                        |
|----------------------------------------------------------------|------------------|--------------------------------------------------------------------------------------------------------------------|
| `DB::select(string $baseTable, $whereEtc = [], ...$params)`    | `SmartArrayHtml` | All matching rows                                                                                                  |
| `DB::selectOne(string $baseTable, $whereEtc = [], ...$params)` | `SmartArrayHtml` | First matching row (sends `LIMIT 1`); empty result object when no row matches, never `null`                        |
| `DB::count(string $baseTable, $whereEtc = [], ...$params)`     | `int`            | `SELECT COUNT(*)` of matching rows                                                                                 |
| `DB::query(string $sqlTemplate, ...$params)`                   | `SmartArrayHtml` | Run custom SQL with placeholders                                                                                   |
| `DB::queryOne(string $sqlTemplate, ...$params)`                | `SmartArrayHtml` | First row of custom SQL (appends `LIMIT 1` to `SELECT`/`WITH` statements); empty result object when no row matches |

Declared return type is `SmartArrayBase`; the object you get is a
`SmartArrayHtml` of `SmartString` values by default, or a plain-value
`SmartArray` when `useSmartStrings` is false. On Smart Join queries,
`->get('table.column')` reads the dotted keys.
[Working with Results](working-with-results.md) covers both.

`selectOne()`, `queryOne()`, and `count()` throw if `$where` or the template
contains `LIMIT` or `OFFSET` ("This method doesn't support LIMIT or OFFSET");
the escape hatch is `DB::query(...)->first()`.

## Writing Rows

| Method                                                                | Returns | Description                                                                                                                                          |
|-----------------------------------------------------------------------|---------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| `DB::insert(string $baseTable, array $values)`                        | `int`   | Insert one row; returns the new auto-increment ID (0 when the table has no auto-increment column)                                                    |
| `DB::update(string $baseTable, array $values, $whereEtc, ...$params)` | `int`   | Update matching rows; returns rows actually changed (MySQL `affected_rows`), not rows matched                                                        |
| `DB::delete(string $baseTable, $whereEtc, ...$params)`                | `int`   | Delete matching rows; returns rows deleted                                                                                                           |
| `DB::transaction(callable $fn)`                                       | `mixed` | Run `$fn` in a transaction: commit on return, rollback and rethrow on exception; returns `$fn`'s return value. Throws `RuntimeException` when nested |

`update()` and `delete()` require a WHERE condition; an empty one throws. To
intentionally update every row, pass the literal string `"TRUE"`. Details in
[Modifying Data](modifying-data.md).

## Table Names

| Method                                                   | Returns  | Description                                                                                                                              |
|----------------------------------------------------------|----------|------------------------------------------------------------------------------------------------------------------------------------------|
| `DB::getBaseTable(string $table, bool $checkDb = false)` | `string` | Strip `tablePrefix` from a table name; with `$checkDb`, queries the database to resolve base names that themselves start with the prefix |
| `DB::getFullTable(string $table, bool $checkDb = false)` | `string` | Prepend `tablePrefix` to a table name; with `$checkDb`, queries the database to resolve the same ambiguity                               |

## Query Helpers

| Method                                               | Returns  | Description                                                                                                  |
|------------------------------------------------------|----------|--------------------------------------------------------------------------------------------------------------|
| `DB::rawSql(string\|int\|float\|null $value)`        | `RawSql` | `DB` only. Mark a value as literal SQL, skipping escaping and quoting (e.g., `NOW()`); `null` becomes `NULL` |
| `DB::pagingSql(mixed $pageNum, mixed $perPage = 10)` | `RawSql` | `DB` only. `LIMIT $perPage OFFSET ...` clause; zero, empty, or invalid input becomes page 1 / 10 per page    |
| `DB::likeContains($input)`                           | `RawSql` | Escaped `LIKE` pattern `'%value%'`                                                                           |
| `DB::likeStartsWith($input)`                         | `RawSql` | Escaped `LIKE` pattern `'value%'`                                                                            |
| `DB::likeEndsWith($input)`                           | `RawSql` | Escaped `LIKE` pattern `'%value'`                                                                            |
| `DB::likeContainsTSV($input)`                        | `RawSql` | Escaped `LIKE` pattern `'%\tvalue\t%'` for matching one value in a tab-separated column                      |

The `like*()` methods accept `string|int|float|null|SmartString` and escape
`%` and `_` in the input, so a search for `"50%"` matches the literal text:

```php
$news = DB::select('news', "title LIKE ?", DB::likeContains($_GET['q'] ?? ''));
// for q=50% this runs: WHERE title LIKE '%50\%%'

$pageNum = $_GET['page'] ?? 1;
$users   = DB::select('users', "ORDER BY name :pagingSQL", [
    ':pagingSQL' => DB::pagingSql($pageNum, 25),
]);
// for page 1 this runs: ORDER BY name LIMIT 25 OFFSET 0
```

## Encryption

Encryption is opt-in: set `encryptionKey` in the connect config and
`MEDIUMBLOB` columns auto-encrypt on `insert()`/`update()` and auto-decrypt on
read. These helpers cover values that bypass those methods.

| Method                                                    | Returns        | Description                                                                                                                                                                                         |
|-----------------------------------------------------------|----------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `DB::encryptValue($value)`                                | `string\|null` | Encrypt a value in PHP, matching what `insert()`/`update()` produce; `null` in, `null` out. Throws `RuntimeException` when `encryptionKey` is not set                                               |
| `DB::decryptExpr(string $column)`                         | `string`       | `DB` only. SQL expression to decrypt a column server-side: `decryptExpr('email')` → `` AES_DECRYPT(`email`, @ek) ``. The `{{column}}` template syntax generates this for you                        |
| `DB::decryptRows(array &$rows, array $keysOrFetchFields)` | `void`         | Decrypt raw mysqli rows in place; pass `$result->fetch_fields()` to auto-detect `MEDIUMBLOB` columns, or name the keys yourself (column names for associative rows, field indexes for numeric rows) |
| `DB::getEncryptedColumns(array $fetchFields)`             | `array`        | `DB` only. The `MEDIUMBLOB` columns in a result, from `$result->fetch_fields()`, keyed by field index: `[2 => 'apiToken']`                                                                          |

`encryptValue()` accepts `string|int|float|null|SmartString`. Use it for exact
matches on encrypted columns (the encryption is deterministic):

```php
$user = DB::selectOne('users', ['token' => DB::encryptValue($searchToken)]);
```

## Constants

| Constant       | Value           | Description                                                          |
|----------------|-----------------|----------------------------------------------------------------------|
| `DB::DATETIME` | `'Y-m-d H:i:s'` | `date()` format for MySQL `DATETIME` columns (`2026-03-16 14:30:00`) |
| `DB::DATE`     | `'Y-m-d'`       | `date()` format for MySQL `DATE` columns (`2026-03-16`)              |
| `DB::TIME`     | `'H:i:s'`       | `date()` format for MySQL `TIME` columns (`14:30:00`)                |

```php
DB::insert('news', ['title' => 'Launch day', 'publishDate' => date(DB::DATETIME)]);
```

## Properties

| Property           | Type             | Description                                                                                                                                       |
|--------------------|------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| `DB::$mysqli`      | `?MysqliWrapper` | The underlying connection (a `mysqli` subclass) for direct access: `DB::$mysqli->insert_id`, `DB::$mysqli->query($ddl)`. `null` when disconnected |
| `DB::$tablePrefix` | `string`         | The prefix prepended to table names, as set at connect (`''` by default)                                                                          |

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

// Deprecated - positional values in an array log E_USER_DEPRECATED
$users = DB::select('users', "status = ? AND age > ?", ['active', 25]);
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
