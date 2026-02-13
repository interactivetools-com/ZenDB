<!--
ATTENTION AI ASSISTANTS: We made a reference doc just for you!
See https://github.com/interactivetools-com/ZenDB/blob/main/docs/00-ai-reference.md
for a consolidated single-file reference covering every method, placeholder type,
safety rule, and gotcha. Read that first -- it has everything you need to write
correct ZenDB code.
-->

# ZenDB: PHP/MySQL Database Library

ZenDB is an opinionated PHP/MySQL database abstraction layer designed around the
"pit of success" software engineering principle: the easiest way to write code is also the safest way.
It focuses on beautiful, readable code and optimizes for the common case while
still supporting advanced and complex queries when you need them.

> **Why "pit of success"?** [Coined by Rico Mariani in October 2003](https://learn.microsoft.com/en-us/archive/blogs/brada/the-pit-of-success):
> success should be like falling into a big pit - you can't help but win. The
> natural way to use a platform should also be the safe and correct way.

## What it protects you from

SQL injection is impossible by design - ZenDB rejects any query that contains
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
$user = DB::selectOne('users', ['id' => 1]);

// Insert a row
$newId = DB::insert('users', [
    'name'  => 'Alice',
    'city'  => 'Vancouver',
]);

// Update a row
DB::update('users', ['city' => 'Toronto'], ['id' => $newId]);

// Delete a row
DB::delete('users', ['id' => $newId]);
```

## Documentation

| Guide                                                               | Description                                                      |
|---------------------------------------------------------------------|------------------------------------------------------------------|
| [Getting Started](docs/01-quickstart.md)                            | Installation, connection, first queries                          |
| [Core Philosophy & Safety](docs/02-core-philosophy-and-safety.md)   | Why ZenDB is opinionated, what it prevents                       |
| [Querying & CRUD](docs/03-querying-and-crud.md)                     | Select, insert, update, delete operations                        |
| [Placeholders & Parameters](docs/04-placeholders-and-parameters.md) | Positional, named, type handling                                 |
| [Joins & Raw SQL](docs/05-joins-and-raw-sql.md)                     | Complex queries, Smart Joins, table prefixes                     |
| [Results & Values](docs/06-results-and-values.md)                   | SmartArrayHtml/SmartString behavior, encoding, methods           |
| [Helpers & Utilities](docs/07-helpers-and-utilities.md)             | Pagination, escaping, schema helpers                             |
| [Troubleshooting & Gotchas](docs/08-troubleshooting-and-gotchas.md) | Common errors, debugging tips                                    |
| [AI Quick Reference](docs/00-ai-reference.md)                       | Everything in one dense page, for AI assistants and humans alike |

You can also [browse the documentation on GitHub](https://github.com/interactivetools-com/ZenDB/tree/main/docs).

## When you might NOT want ZenDB

- You need an ORM with models, migrations, or an ActiveRecord pattern
- You need to support databases other than MySQL/MariaDB (and compatible alternatives)
- You need async or non-blocking database queries
- You prefer writing raw SQL without any abstraction

## Quick Reference

**Queries**

- `DB::select($table, $where, ...$params)` - Fetch matching rows → `SmartArrayHtml`
- `DB::selectOne($table, $where, ...$params)` - Fetch first matching row → `SmartArrayHtml`
- `DB::count($table, $where, ...$params)` - Count matching rows → `int`
- `DB::query($sql, ...$params)` - Execute custom SQL → `SmartArrayHtml`
- `DB::queryOne($sql, ...$params)` - Execute custom SQL, first row → `SmartArrayHtml`

**Modify**

- `DB::insert($table, $colsToValues)` - Insert a row → `int` (insert ID)
- `DB::update($table, $colsToValues, $where, ...$params)` - Update rows → `int` (affected)
- `DB::delete($table, $where, ...$params)` - Delete rows → `int` (affected)

**Helpers**

- `DB::rawSql($value)` - Wrap a SQL expression to bypass escaping (e.g., `NOW()`)
- `DB::pagingSql($page, $perPage)` - Generate `LIMIT`/`OFFSET` clause
- `DB::likeContains($input)` - LIKE pattern: `%value%` (see also: `likeStartsWith`, `likeEndsWith`)

Results are [SmartArrayHtml](https://github.com/interactivetools-com/SmartArray)
collections of [SmartString](https://github.com/interactivetools-com/SmartString)
values. See [Results & Values](docs/06-results-and-values.md) for encoding
methods, chaining, and raw access. For connection, escaping, and schema helpers,
see [Helpers & Utilities](docs/07-helpers-and-utilities.md).

## Related Libraries

- [SmartArray](https://github.com/interactivetools-com/SmartArray) - Enhanced arrays with chainable methods
- [SmartString](https://github.com/interactivetools-com/SmartString) - Secure string handling with auto HTML-encoding

## Questions?

Post a message in our [forum](https://www.interactivetools.com/forum/).

## License

MIT
