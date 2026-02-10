# Getting Started

This guide walks you through installing ZenDB, connecting to a database, and
running your first queries.

## Requirements

- PHP ^8.1
- ext-mysqli

## Installation

```bash
composer require itools/zendb
```

This will also install the required dependencies
[SmartArray](https://github.com/interactivetools-com/SmartArray) and
[SmartString](https://github.com/interactivetools-com/SmartString).

## Connecting to the Database

Call `DB::connect()` with an array of configuration options. This creates a
singleton connection used by all `DB::` static methods.

```php
use Itools\ZenDB\DB;

DB::connect([
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'my_app',
]);
```

### All Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `hostname` | string | *(required)* | Database server hostname |
| `username` | string | *(required)* | Database username |
| `password` | string\|null | `null` | Database password |
| `database` | string\|null | `null` | Database name |
| `tablePrefix` | string | `''` | Prefix prepended to all table names (e.g. `'cms_'`) |
| `useSmartJoins` | bool | `true` | Add table-prefixed keys to JOIN results for disambiguation |
| `useSmartStrings` | bool | `true` | Return values as SmartString objects with auto HTML-encoding |
| `usePhpTimezone` | bool | `true` | Sync MySQL session timezone with PHP's timezone |
| `smartArrayLoadHandler` | callable\|null | `null` | Custom handler called when loading results into SmartArray |
| `versionRequired` | string | `'5.7.32'` | Minimum MySQL version required to connect |
| `requireSSL` | bool | `false` | Require SSL for the database connection |
| `databaseAutoCreate` | bool | `true` | Automatically create the database if it does not exist |
| `connectTimeout` | int | `3` | Connection timeout in seconds |
| `readTimeout` | int | `60` | Read timeout in seconds |
| `queryLogger` | callable\|null | `null` | Callback for logging queries: `fn(string $query, float $durationSecs, ?Throwable $error): void` |
| `sqlMode` | string | *See below* | MySQL SQL mode set on connection |

The default `sqlMode` is:

```
STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
```

For most projects, you only need `hostname`, `username`, `password`, and
`database`. The defaults are sensible and secure.

## Your First Query

Use `DB::select()` to fetch multiple rows and `DB::get()` to fetch a single row.

```php
// Fetch all active users
$users = DB::select('users', ['status' => 'active']);
foreach ($users as $user) {
    echo "$user->name from $user->city\n"; // values are auto HTML-encoded
}

// Fetch a single user by primary key
$user = DB::get('users', ['num' => 1]);
echo "Name: $user->name\n";
echo "Admin: {$user->isAdmin->value()}\n"; // ->value() returns the raw value

// Fetch with SQL conditions and placeholders
$admins = DB::select('users', "isAdmin = ? AND city = ?", 1, 'Vancouver');
```

Both `DB::select()` and `DB::get()` return `SmartArrayHtml` objects. Each row
is a `SmartArrayHtml` and each field value is a `SmartString` that
automatically HTML-encodes when used in a string context.

## Your First Insert

Use `DB::insert()` to add a row. It returns the new auto-increment ID.

```php
$newId = DB::insert('users', [
    'name'    => 'Alice',
    'isAdmin' => 0,
    'status'  => 'active',
    'city'    => 'Toronto',
]);

echo "Created user #$newId\n";
```

## Your First Update

Use `DB::update()` to modify existing rows. It returns the number of affected
rows. A WHERE condition is **required** — ZenDB will throw an exception if you
try to update without one.

```php
$affected = DB::update('users',
    ['city' => 'Montreal', 'status' => 'active'],  // columns to set
    ['num' => $newId]                                // WHERE condition
);

echo "Updated $affected row(s)\n";

// You can also use SQL conditions with placeholders
DB::update('users',
    ['status' => 'inactive'],
    "city = ? AND isAdmin = ?", 'Vancouver', 0
);
```

## Your First Delete

Use `DB::delete()` to remove rows. It returns the number of affected rows.
Like `DB::update()`, a WHERE condition is **required**.

```php
$deleted = DB::delete('users', ['num' => $newId]);
echo "Deleted $deleted row(s)\n";

// With SQL conditions
DB::delete('users', "status = ? AND city = ?", 'inactive', 'Vancouver');
```

## Catching Errors

ZenDB throws standard PHP exceptions. Wrap your calls in a try/catch block to
handle errors gracefully.

```php
try {
    $user = DB::get('users', ['num' => 1]);
    echo "Hello, $user->name!\n";
} catch (\RuntimeException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
} catch (\InvalidArgumentException $e) {
    echo "Invalid query: " . $e->getMessage() . "\n";
}
```

Common exception types:

- `RuntimeException` — Connection failures, SQL errors, version mismatches
- `InvalidArgumentException` — Invalid table names, missing WHERE clauses,
  bad placeholder usage

## Multiple Connections

For most applications, the `DB::connect()` singleton is all you need. If you
need to work with multiple databases or different settings, create `Connection`
instances directly.

```php
use Itools\ZenDB\Connection;

$analytics = new Connection([
    'hostname' => 'analytics-db.example.com',
    'username' => 'reader',
    'password' => 'secret',
    'database' => 'analytics',
]);

$rows = $analytics->select('events', "created_at > NOW() - INTERVAL ? DAY", 1);
```

Each `Connection` instance has the same query methods as `DB::` (`select`,
`get`, `insert`, `update`, `delete`, `count`, `query`) but operates on its own
connection. See [Joins & Raw SQL](05-joins-and-raw-sql.md) for more on advanced
connection usage.

---

[← Back to README](../README.md) | [Next: Core Philosophy & Safety →](02-core-philosophy-and-safety.md)
