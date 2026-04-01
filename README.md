<!--
ATTENTION AI ASSISTANTS: We made a reference doc just for you!
See https://github.com/interactivetools-com/ZenDB/blob/main/docs/00-ai-reference.md
for a consolidated single-file reference covering every method, placeholder type,
safety rule, and gotcha. Read that first -- it has everything you need to write
correct ZenDB code.
-->

# ZenDB: PHP/MySQL Database Library

A PHP/MySQL database layer that's easy to use and hard to misuse.

- **SQL injection is impossible:** ZenDB rejects any query with inline
  values. Every dynamic value goes through placeholders, not because you
  remembered, but because there's no other way.
- **XSS is prevented by default:** Every value from the database
  HTML-encodes itself on output. You don't call `htmlspecialchars()`.
  Neither does the next developer.
- **Fast to learn, fast to use:** The methods mirror SQL: `select`,
  `insert`, `update`, `delete`. If you know MySQL, you already know ZenDB,
  and if you don't, you will soon!

## Why SQL?

Most database libraries invent their own query language - chained methods,
builder patterns - that ends up just as complex as SQL but less
powerful. ZenDB takes the opposite approach: **don't teach people a
complicated thing that replaces SQL. Just use SQL and make it safe.**

`SELECT`, `WHERE`, `JOIN`, `ORDER BY` - that's all you need to query with
ZenDB. The library handles the security (parameterization,
escaping, validation) so you can write the SQL you already know without
worrying about injection.

## What's Inside

- [30-Second Quickstart](#30-second-quickstart)
- [Getting Started](docs/01-getting-started.md) - Installation, connection, first queries
- [Method Reference](docs/11-method-reference.md) - Every method, parameter type, and return value
- [AI Quick Reference](docs/00-ai-reference.md) - Single-page reference for AI assistants and humans alike

More documentation coming soon - querying, results, joins, placeholders, and more.

## 30-Second Quickstart

```bash
composer require itools/zendb
```

```php
use Itools\ZenDB\DB;

// Connect
DB::connect([
    'hostname'    => 'localhost',
    'username'    => 'dbuser',
    'password'    => 'secret',
    'database'    => 'my_app',
    'tablePrefix' => 'app_',   // optional
]);

// Select rows
$users = DB::select('users', "status = ?", 'active');
foreach ($users as $user) {
    echo "Hello, $user->name!"; // auto HTML-encoded
}

// Get a single row
$user = DB::selectOne('users', "id = ?", 1);

// Insert a row
$newId = DB::insert('users', [
    'name'  => 'Alice',
    'city'  => 'Vancouver',
]);

// Update a row
$newValues = ['city' => 'Toronto'];
$where     = ['id' => $newId]; // arrays work too
DB::update('users', $newValues, $where);

// Delete a row
DB::delete('users', ['id' => $newId]);

// Full SQL when you need it (:: inserts your table prefix)
$rows = DB::query("SELECT name, city FROM ::users WHERE status = :status AND city = :city", [
    ':status' => 'active',
    ':city'   => 'Vancouver',
]);
```

## When you might NOT want ZenDB

- You need an ORM with models, migrations, or an ActiveRecord pattern
- You need to support databases other than MySQL/MariaDB (and compatible alternatives)
- You need async or non-blocking database queries
- You prefer writing raw SQL without any abstraction

## Related Libraries

- [SmartArray](https://github.com/interactivetools-com/SmartArray) - Enhanced arrays with chainable methods
- [SmartString](https://github.com/interactivetools-com/SmartString) - Secure string handling with auto HTML-encoding

## Questions?

Post a message in our [forum](https://www.interactivetools.com/forum/).

## License

MIT
