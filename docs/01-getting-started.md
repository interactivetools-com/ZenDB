# Getting Started

This guide walks you through installing ZenDB, connecting to a database, and
running your first queries.

## Requirements

- PHP ^8.1
- ext-mysqli
- MySQL 5.7.32+ or MariaDB equivalent

## Installation

```bash
composer require itools/zendb
```

This will also install the required dependencies
[SmartArray](https://github.com/interactivetools-com/SmartArray) and
[SmartString](https://github.com/interactivetools-com/SmartString).

## Connecting to the Database

> **Note:** If your framework already uses ZenDB (e.g., CMS Builder), the
> connection is already established and you can skip this step.

Call `DB::connect()` with an array of configuration options. This creates a
singleton connection used by all `DB::` static methods.

```php
use Itools\ZenDB\DB;

DB::connect([
    'hostname'    => 'localhost',
    'username'    => 'dbuser',
    'password'    => 'secret',
    'database'    => 'my_app',  // use '' for none
    'tablePrefix' => 'app_',    // optional
]);
```

### All Configuration Options

| Option                  | Type           | Default      | Description                                                                           |
|-------------------------|----------------|--------------|---------------------------------------------------------------------------------------|
| **Connection**          |                |              |                                                                                       |
| `hostname`              | string         | *(required)* | Database server hostname                                                              |
| `username`              | string         | *(required)* | Database username                                                                     |
| `password`              | string         | *(required)* | Database password (use `''` for none)                                                 |
| `database`              | string         | *(required)* | Database name (use `''` for none)                                                     |
| **Query Behavior**      |                |              |                                                                                       |
| `tablePrefix`           | string         | `''`         | Prefix prepended to all table names (e.g. `'cms_'`)                                   |
| `useSmartJoins`         | bool           | `true`       | Add table-prefixed keys to JOIN results for disambiguation                            |
| `useSmartStrings`       | bool           | `true`       | Return values as SmartString objects with auto HTML-encoding                          |
| **Connection Options**  |                |              |                                                                                       |
| `connectTimeout`        | int            | `3`          | Connection timeout in seconds                                                         |
| `readTimeout`           | int            | `60`         | Read timeout in seconds                                                               |
| `requireSSL`            | bool           | `false`      | Require SSL for the database connection                                               |
| `versionRequired`       | string         | `'5.7.32'`   | Minimum MySQL version required to connect                                             |
| `usePhpTimezone`        | bool           | `true`       | Sync MySQL session timezone with PHP's timezone                                       |
| `sqlMode`               | string         | *See desc*   | `STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` |
| `databaseAutoCreate`    | bool           | `false`      | Automatically create the database if it does not exist                                |
| **Advanced**            |                |              |                                                                                       |
| `queryLogger`           | callable\|null | `null`       | Callback for logging queries: `fn(string $query, float $secs, ?Throwable $error)`     |
| `smartArrayLoadHandler` | callable\|null | `null`       | Custom handler for loading results into SmartArray                                    |

## Method Names Are SQL Statements

ZenDB's methods are named after the SQL statements they run: `select`,
`insert`, `update`, `delete`. If you know what a
SELECT does, you know what `DB::select()` does. If you don't, you'll
learn real SQL vocabulary as you go. No proprietary syntax to memorize. If you're learning ZenDB, you're learning MySQL.

## Your First Select

Use `DB::select()` to fetch multiple rows and `DB::selectOne()` to fetch a
single row.

```php
// Fetch all active users
$users = DB::select('users', ['status' => 'active']);
foreach ($users as $user) {
    echo "$user->name from $user->city\n"; // values are auto HTML-encoded
}

// Fetch a single user by primary key
$user = DB::selectOne('users', ['id' => 1]);
echo "Name: $user->name\n";
echo "City: $user->city\n";

// Fetch with SQL conditions and placeholders
$admins = DB::select('users', "isAdmin = ? AND city = ?", 1, 'Vancouver');
```

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
rows. A WHERE condition is **required**: ZenDB will throw an exception if you
try to update without one.

```php
$affected = DB::update('users',
    ['city' => 'Montreal', 'status' => 'active'],      // columns to set
    ['id' => $newId]                                   // WHERE condition
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
$deleted = DB::delete('users', ['id' => $newId]);
echo "Deleted $deleted row(s)\n";

// With SQL conditions
DB::delete('users', "status = ? AND city = ?", 'inactive', 'Vancouver');
```

## Getting Raw Values

ZenDB returns objects, not plain arrays. Results are `SmartArrayHtml` collections of
`SmartString` values that automatically HTML-encode on output. This means
you can echo any value directly into HTML without worrying about XSS.

If you need the raw data and will handle encoding yourself:

```php
// Get a plain PHP array
$users = DB::select('users', ['status' => 'active'])->toArray();

// Get a single field's raw value
$user = DB::selectOne('users', ['id' => 1]);
$name = $user->name->value();   // raw value from the database
```

## Catching Errors

ZenDB throws standard PHP exceptions. Catch `\Exception` to handle any error:

```php
try {
    $user = DB::selectOne('users', ['id' => 1]);
    echo "Hello, $user->name!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

---

[← Back to README](../README.md) | [Next: Querying Data →](02-querying-data.md)
