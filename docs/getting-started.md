# Getting Started

Install ZenDB, connect to a database, and run your first queries. By the end of
this page you will have selected, inserted, updated, and deleted rows.

## Installation

Using CMS Builder? ZenDB is already installed and connected; skip ahead to
[The Mental Model](#the-mental-model).

```bash
composer require itools/zendb
```

Requirements: PHP 8.1+, `ext-mysqli`, `ext-openssl`, and MySQL 5.7.32+ or an
equivalent MariaDB. Composer also installs
[SmartArray](https://github.com/interactivetools-com/SmartArray) and
[SmartString](https://github.com/interactivetools-com/SmartString), the two
libraries behind ZenDB's result sets and values.

## Connect and Fetch Your First Rows

Call `DB::connect()` once at startup, then query with the `DB::` static methods:

```php
use Itools\ZenDB\DB;

DB::connect([
    'hostname' => 'localhost',
    'username' => 'dbuser',
    'password' => 'secret',    // use '' for none
    'database' => 'my_app',
]);

$users = DB::select('users', ['status' => 'active']);
// SELECT * FROM `users` WHERE `status` = 'active'

foreach ($users as $user) {
    echo "<li>$user->name from $user->city</li>";  // values HTML-encode themselves
}
```

All four config keys are required; a missing one throws `RuntimeException`
with the key name, and a failed connection throws with the MySQL error.
Those four are enough for most apps; for everything else `DB::connect()`
accepts (timeouts, `tablePrefix`, SSL, encryption), see
[Configuration Options](#configuration-options) at the bottom of this page.

## The Mental Model

Three rules explain most of the library:

1. **Method names are SQL statements.** `select()` runs a SELECT, `insert()`
   runs an INSERT. If you know MySQL, you already know what each method does,
   and each example below shows the exact SQL it generates.
2. **Values only enter through placeholders.** Never quote or escape a value
   yourself. SQL templates containing inline quotes or numbers are rejected
   before the query runs, which is what makes injection impossible on the
   normal path.
3. **Output is HTML-encoded by default.** Every value from the database
   encodes itself when echoed, so XSS protection doesn't depend on anyone
   remembering `htmlspecialchars()`.

## Fetching One Row - `DB::selectOne()`

`DB::selectOne()` returns the first matching row and adds `LIMIT 1` for you.

```php
$user = DB::selectOne('users', ['id' => 1]);
// SELECT * FROM `users` WHERE `id` = 1 LIMIT 1

echo "Name: $user->name";
echo "City: $user->city";

if ($user->isEmpty()) {
    echo "No such user";
}
```

Both select methods also accept SQL conditions with placeholders:

```php
$admins = DB::select('users', "isAdmin = ? AND city = ?", 1, 'Vancouver');
// SELECT * FROM `users` WHERE isAdmin = 1 AND city = 'Vancouver'
```

[Querying Data](querying-data.md) covers every WHERE form, sorting, and
pagination.

## Inserting Rows - `DB::insert()`

`DB::insert()` takes a table name and column-value pairs, and returns the new
auto-increment ID.

```php
$newId = DB::insert('users', [
    'name'    => 'Alice',
    'isAdmin' => 0,
    'status'  => 'active',
    'city'    => 'Toronto',
]);
// INSERT INTO `users` SET `name` = 'Alice', `isAdmin` = 0, `status` = 'active', `city` = 'Toronto'

echo "Created user #$newId";
```

## Updating Rows - `DB::update()`

`DB::update()` takes the new values and a WHERE condition, and returns the
number of affected rows. The WHERE condition is required: updating without one
throws `InvalidArgumentException` rather than silently rewriting every row.

```php
$affected = DB::update('users',
    ['city' => 'Montreal'],   // columns to set
    ['id'   => $newId],       // WHERE condition
);
// UPDATE `users` SET `city` = 'Montreal' WHERE `id` = 42

// SQL conditions with placeholders work here too
DB::update('users', ['status' => 'inactive'], "city = ? AND isAdmin = ?", 'Vancouver', 0);
```

## Deleting Rows - `DB::delete()`

`DB::delete()` takes a WHERE condition and returns the number of deleted rows.
Like `update()`, the WHERE condition is required.

```php
$deleted = DB::delete('users', ['id' => $newId]);
// DELETE FROM `users` WHERE `id` = 42

DB::delete('users', "status = ? AND city = ?", 'inactive', 'Vancouver');
// DELETE FROM `users` WHERE status = 'inactive' AND city = 'Vancouver'
```

## Getting Raw Values

Results are `SmartArrayHtml` collections of `SmartString` values, which is what
makes output HTML-encode itself. When you need the underlying data instead,
ask for it:

```php
// One field's raw value
$user = DB::selectOne('users', ['id' => 1]);
$name = $user->name->value();   // string as stored in the database

// A whole result as a plain PHP array
$users = DB::select('users', ['status' => 'active'])->toArray();
```

[Working with Results](working-with-results.md) covers the result objects and
their methods in full.

## Catching Errors

ZenDB fails fast: every error throws an exception rather than returning
`false`. Misuse of the API throws `InvalidArgumentException`, connection
problems throw `RuntimeException`, and MySQL errors surface as exceptions too.
Catch `\Exception` to handle all of them:

```php
try {
    $user = DB::selectOne('users', ['id' => 1]);
    echo "Hello, $user->name!";
} catch (\Exception $e) {
    echo "Database error: " . $e->getMessage();
}
```

Exception messages state what went wrong and how to fix it; when one stops
you, [Troubleshooting](troubleshooting.md) lists the common messages with
explanations.

## Configuration Options

Everything `DB::connect()` accepts. Only the first four are required; an
unknown key throws `InvalidArgumentException`, so typos fail at connect time
rather than being silently ignored.

| Option                 | Type   | Default      | Description                                                                           |
|------------------------|--------|--------------|---------------------------------------------------------------------------------------|
| **Connection**         |        |              |                                                                                       |
| `hostname`             | string | *(required)* | Database server hostname                                                              |
| `username`             | string | *(required)* | Database username                                                                     |
| `password`             | string | *(required)* | Database password (use `''` for none)                                                 |
| `database`             | string | *(required)* | Database name (use `''` for none)                                                     |
| **Query Behavior**     |        |              |                                                                                       |
| `tablePrefix`          | string | `''`         | Prefix prepended to all table names, e.g. `'app_'` makes `users` query `app_users`    |
| `useSmartJoins`        | bool   | `true`       | Add table-prefixed keys to JOIN results for disambiguation                            |
| `useSmartStrings`      | bool   | `true`       | Return values as SmartString objects with auto HTML-encoding                          |
| **Connection Options** |        |              |                                                                                       |
| `connectTimeout`       | int    | `3`          | Connection timeout in seconds                                                         |
| `readTimeout`          | int    | `60`         | Read timeout in seconds                                                               |
| `requireSSL`           | bool   | `false`      | Require SSL for the database connection                                               |
| `versionRequired`      | string | `'5.7.32'`   | Minimum MySQL version or compatible; connecting to an older server throws             |
| `usePhpTimezone`       | bool   | `true`       | Set the MySQL session timezone to PHP's timezone                                      |
| `sqlMode`              | string | *see below*  | `STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` |
| `databaseAutoCreate`   | bool   | `false`      | Create the database if it does not exist                                              |
| **Advanced**           |        |              |                                                                                       |
| `encryptionKey`        | string | *(none)*     | Encrypt `MEDIUMBLOB` columns with AES; see [Encryption](encryption.md)                |

---

[← Documentation Index](README.md) | [Next: Querying Data →](querying-data.md)
