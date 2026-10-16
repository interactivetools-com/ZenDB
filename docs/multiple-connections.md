# Multiple Connections

The `DB::` methods operate on the single default connection created by
`DB::connect()`. This page covers two tools for applications that need
more than that: `DB::clone()` reuses the live MySQL connection with different
settings, and `new Connection()` opens a separate connection to
another server, database, or set of credentials.

Contents:

- [Same Connection, Different Settings - `DB::clone()`](#same-connection-different-settings---dbclone)
- [A Second Database - `new Connection()`](#a-second-database---new-connection)

## Same Connection, Different Settings - `DB::clone()`

`DB::clone()` returns a new `Connection` object that shares the default
connection's live mysqli link but can override three settings:
`tablePrefix`, `useSmartJoins`, and `useSmartStrings`.
No second connection is opened, and the original `DB::` connection
is unaffected.

The most common use is reaching tables with a different prefix in the same
database. With `tablePrefix` set to `'cms_'` on the default connection:

```php
$legacy = DB::clone(['tablePrefix' => 'legacy_']);

$users    = DB::select('users');       // this runs: SELECT * FROM `cms_users`
$oldUsers = $legacy->select('users');  // this runs: SELECT * FROM `legacy_users`
```

The clone is a normal `Connection` object: call the same query methods on it
that you call on `DB::`, and keep it around as long as you need it. Every
`Connection` instance has `clone()`, not just `DB::`.

### Getting Raw Values - `'useSmartStrings' => false`

A clone with `useSmartStrings` off returns plain PHP values instead of
HTML-encoding
[`SmartString`](https://github.com/interactivetools-com/SmartString) objects,
useful for values that won't be output as HTML:

```php
$raw   = DB::clone(['useSmartStrings' => false]);
$users = DB::select('users', ['status' => 'active']);
$rows  = $raw->select('users', ['status' => 'active']);

$users->first()->name;  // SmartString object, HTML-encodes when echoed
$rows->first()->name;   // plain PHP string
```

For one-off raw values on the default connection, `->value()` and
`->toArray()` are usually enough; see
[Getting Raw Data](working-with-results.md#getting-raw-data). A clone saves
repeating those calls when a whole section of code (a CSV export, an API
endpoint) needs raw values throughout.

### Turning Off Smart Joins - `'useSmartJoins' => false`

A clone with `useSmartJoins` off skips the extra `table.column` keys that
JOIN results normally include, which saves building those keys on large result
sets where you don't need them:

```php
$plain = DB::clone(['useSmartJoins' => false]);
$rows  = $plain->query("SELECT u.name, o.total FROM ::users u JOIN ::orders o ON o.userId = u.id");
// rows have `name` and `total` keys only, no `users.name` / `orders.total` keys
```

### What a Clone Can't Change

Everything else in the config (`hostname`, `username`, `password`,
`database`, timeouts, and so on) is fixed at connect time, because the clone
runs on the same live connection. Passing any other key throws:

```php
DB::clone(['database' => 'analytics']);
// throws InvalidArgumentException: clone() only supports: tablePrefix, useSmartJoins, useSmartStrings. Got: database
```

The shared link also means shared transaction state: a `DB::transaction()`
covers queries made through clones too, and starting a second `transaction()`
on either one while the first is open throws `RuntimeException`.

To reach a different database or server, open a separate connection.

## A Second Database - `new Connection()`

`new Connection()` opens a separate MySQL connection with its own
credentials, server, and settings. It accepts the same config array as
`DB::connect()` (see
[Configuration Options](getting-started.md#configuration-options)) and
connects immediately in the constructor, throwing `RuntimeException` on
failure:

```php
use Itools\ZenDB\Connection;

$reports = new Connection([
    'hostname' => 'reports-db.example.com',
    'username' => 'reader',
    'password' => 'secret',
    'database' => 'analytics',
]);

$signups = $reports->select('daily_signups', "signupDate >= ?", '2026-07-01');
// SELECT * FROM `daily_signups` WHERE signupDate >= '2026-07-01'
```

Every `DB::` query method exists as an instance method: `select()`,
`selectOne()`, `insert()`, `update()`, `delete()`, `count()`, `query()`,
`queryOne()`, and `transaction()` all work the same way, just called on the
object:

```php
$rowCount = $reports->count('daily_signups');
$reports->insert('report_runs', ['runAt' => date('Y-m-d H:i:s'), 'status' => 'complete']);
```

Each connection opened with `new Connection()` is independent: its own
`tablePrefix`, its own `encryptionKey`, its own transactions. A
`transaction()` on `$reports` does not affect queries running through `DB::`.

The usual pattern is `DB::` for the application database and a named
variable (`$reports`) for each additional connection.

---

[← Helpers and Utilities](helpers-and-utilities.md) | [Documentation Index](README.md) | [Next: Encryption →](encryption.md)
