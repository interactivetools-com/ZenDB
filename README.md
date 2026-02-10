# ZenDB: PHP/MySQL Database Library

ZenDB is an opinionated PHP/MySQL database abstraction layer designed around the
"pit of success" principle: the easiest way to write code is also the safest way.
It focuses on beautiful, readable code and optimizes for the common case while
still supporting advanced and complex queries when you need them.

## What it protects you from

SQL injection is impossible by design — ZenDB rejects any query that contains
inline quotes or unparameterized values, forcing all user input through bound
parameters. XSS is prevented by default because every value returned from the
database is a SmartString object that automatically HTML-encodes output in string
contexts. You get security without extra effort or ceremony.

## 30-Second Quickstart

```bash
composer require itools/zendb
```

```php
use Itools\ZenDB\DB;

// Connect
DB::connect([
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'my_app',
]);

// Select rows
$users = DB::select('users', ['status' => 'active']);
foreach ($users as $user) {
    echo "Hello, $user->name!"; // auto HTML-encoded
}

// Get a single row
$user = DB::get('users', ['num' => 1]);

// Insert a row
$newId = DB::insert('users', [
    'name'  => 'Alice',
    'city'  => 'Vancouver',
]);

// Update a row
DB::update('users', ['city' => 'Toronto'], ['num' => $newId]);

// Delete a row
DB::delete('users', ['num' => $newId]);
```

## Documentation

| Guide | Description |
|-------|-------------|
| [Getting Started](docs/01-quickstart.md) | Installation, connection, first queries |
| [Core Philosophy & Safety](docs/02-core-philosophy-and-safety.md) | Why ZenDB is opinionated, what it prevents |
| [Querying & CRUD](docs/03-querying-and-crud.md) | Select, insert, update, delete operations |
| [Placeholders & Parameters](docs/04-placeholders-and-parameters.md) | Positional, named, type handling |
| [Joins & Raw SQL](docs/05-joins-and-raw-sql.md) | Complex queries, Smart Joins, table prefixes |
| [Results & Values](docs/06-results-and-values.md) | SmartArrayHtml/SmartString behavior, encoding, methods |
| [Helpers & Utilities](docs/07-helpers-and-utilities.md) | Pagination, escaping, schema helpers |
| [Troubleshooting & Gotchas](docs/08-troubleshooting-and-gotchas.md) | Common errors, debugging tips |

## When you might NOT want ZenDB

- You need an ORM with models, migrations, or an ActiveRecord pattern
- You need to support databases other than MySQL/MariaDB
- You need async or non-blocking database queries
- You prefer writing raw SQL without any abstraction

## Related Libraries

- [SmartArray](https://github.com/interactivetools-com/SmartArray) — Enhanced arrays with chainable methods
- [SmartString](https://github.com/interactivetools-com/SmartString) — Secure string handling with auto HTML-encoding

## Questions?

Post a message in our [forum](https://www.interactivetools.com/forum/).

## License

MIT
