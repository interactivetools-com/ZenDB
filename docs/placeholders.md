# Placeholders

Every dynamic value enters a query through a placeholder. The template guard
enforces this: SQL templates with inline quotes or numbers throw before the
query runs. This page covers every placeholder type, how PHP types convert to
SQL, and the errors the guard produces.

Three placeholders cover nearly all queries (generated SQL shown with
`tablePrefix` set to `'cms_'`).

| Placeholder | Example        | With value             | Generates           |
|-------------|----------------|------------------------|---------------------|
| `?`         | `age > ?`      | `25`                   | `age > 25`          |
| `:name`     | `city = :city` | `':city' => "O'Brien"` | `city = 'O\'Brien'` |
| `::`        | `FROM ::users` | (uses `tablePrefix`)   | `FROM cms_users`    |

The rest are for dynamic identifiers: table or column names that arrive as
values, like a user-picked sort column. Most queries never need these.
Identifiers can't be quoted like values, so the backtick forms accept only
identifier characters (letters, numbers, `_`, `-`; anything else throws) and
insert the value unquoted.

| Placeholder                  | Example               | With value | Generates             |
|------------------------------|-----------------------|------------|-----------------------|
| `` `?` ``, `` `:name` ``     | ``ORDER BY `:sort` `` | `'name'`   | ``ORDER BY `name` ``  |
| `` `::?` ``, `` `:::name` `` | ``FROM `:::table` ``  | `'users'`  | ``FROM `cms_users` `` |
| `::?`, `:::name`             | `LIKE ::?`            | `'user%'`  | `LIKE 'cms_user%'`    |

The last row (prefixed *value*, quoted not backticked) is rare; it exists
for queries about tables themselves, like `SHOW TABLES LIKE`.

## Positional Placeholders (`?`)

Each `?` takes the next argument in order. Great for short conditions:

```php
DB::select('users', "status = ?", 'active');
// SELECT * FROM `users` WHERE status = 'active'

DB::select('users', "status = ? AND city = ?", 'active', 'Vancouver');
// SELECT * FROM `users` WHERE status = 'active' AND city = 'Vancouver'
```

Positional placeholders take at most three values. For more, use named
placeholders (next section); past three values, matching each `?` to its
argument by counting positions is where mistakes start.

## Named Placeholders (`:name`)

`:name` placeholders take values from an associative array. Names start with
a letter, followed by letters, numbers, or underscores:

```php
DB::select('users', "city = :city AND status = :status", [
    ':city'   => 'Vancouver',
    ':status' => 'active',
]);
// SELECT * FROM `users` WHERE city = 'Vancouver' AND status = 'active'
```

The same placeholder can appear more than once in a query:

```php
DB::query("SELECT * FROM ::users WHERE city = :city OR birthplace = :city", [
    ':city' => 'Vancouver',
]);
// SELECT * FROM users WHERE city = 'Vancouver' OR birthplace = 'Vancouver'
```

Use one style per query: mixing `?` and `:name` in the same call throws.

## Table Prefix (`::`)

`::` in front of a table name expands to the configured `tablePrefix`. With
no prefix configured it expands to nothing, so `::users` is just `users`.
Examples that show the prefix applied assume `tablePrefix` is `'cms_'`;
the rest run with no prefix.

```php
DB::query("SELECT * FROM ::users JOIN ::orders ON ::orders.userId = ::users.id");
// SELECT * FROM cms_users JOIN cms_orders ON cms_orders.userId = cms_users.id
```

Write `::` in every raw query, even without a prefix configured; if the
prefix ever changes, every query keeps working.

## Identifier Placeholders (`` `?` `` and `` `:name` ``)

Value placeholders quote their input, and `ORDER BY 'name'` orders by a
constant string, so nothing gets sorted and no error is raised. Identifiers
need their own placeholder: wrap it in backticks.

```php
$sort = $_GET['sort'] ?? 'name';
DB::select('users', "ORDER BY `:sort`", [':sort' => $sort]);
// SELECT * FROM `users` ORDER BY `name`
```

Passing `$_GET` input straight in is safe here: the identifier check runs
before the query, so a `sort` of `name; DROP TABLE users` throws instead of
reaching MySQL.

For a dynamic table name that needs the prefix, add `::` inside the
backticks:

```php
DB::query("SELECT * FROM `:::table` WHERE id = :id", [':table' => 'users', ':id' => 42]);
// SELECT * FROM `cms_users` WHERE id = 42
```

## Prefixed Values (`::?` and `:::name`)

Without backticks, `::?` and `:::name` prepend the table prefix to a regular
value, which then gets quoted and escaped like any other. The main use is
matching prefixed table names in `SHOW TABLES` queries:

```php
$tables = DB::query("SHOW TABLES LIKE ::?", 'user%');
// SHOW TABLES LIKE 'cms_user%'
```

## Type Handling

PHP types convert to SQL by actual type, so `10` and `"10"` produce different
SQL.

| PHP Type | SQL Output             | Example                         |
|----------|------------------------|---------------------------------|
| `string` | Quoted and escaped     | `"O'Brien"` → `'O\'Brien'`      |
| `int`    | Unquoted number        | `42` → `42`                     |
| `float`  | Unquoted number        | `9.5` → `9.5`                   |
| `bool`   | TRUE / FALSE           | `true` → `TRUE`                 |
| `null`   | NULL keyword           | `null` → `NULL`                 |
| `array`  | Comma-separated values | `[1, 2, 3]` → `1,2,3`           |
| `RawSql` | As-is, no escaping     | `DB::rawSql('NOW()')` → `NOW()` |

The string-vs-int difference matters most in `LIMIT`, where MySQL requires an
unquoted number:

```php
DB::select('users', "LIMIT ?", 10);     // LIMIT 10       - works
DB::select('users', "LIMIT ?", "10");   // LIMIT '10'     - MySQL syntax error
```

SmartString, SmartNull, and SmartArray parameters unwrap to their underlying
values automatically, so passing `$row->name` as a parameter just works.

Arrays expand to comma-separated values for `IN` lists, and require a named
placeholder (`?` would be ambiguous). The expansion skips `null` elements and
removes duplicates, so `[1, null, 1, 2]` becomes `IN (1,2)`:

```php
DB::select('users', "id IN (:ids)", [':ids' => [1, 2, 3]]);
// SELECT * FROM `users` WHERE id IN (1,2,3)
```

## What Happens If...

The guard's error messages state the fix; here are the common ones:

```php
// Missing parameter
DB::select('users', "name = ? AND city = ?", 'John');
// Throws: Missing value for ? parameter at position 2

// Positional values in an array (deprecated form)
DB::select('users', "id IN (?)", [1, 2, 3]);
// Runs as IN (1) and logs a deprecation; arrays need a named placeholder: ':ids' => [1, 2, 3]

// More direct values than ? placeholders
DB::select('users', "num = ?", 1, 999);
// Runs as num = 1 and logs a deprecation; an extra value usually means a missing ?

// Quotes in the template
DB::select('users', "name = 'John'");
// Throws: Quotes not allowed in template. Replace 'John' with :paramName and add: [ ':paramName' => 'John' ]

// Standalone number in the template
DB::select('users', "age > 18");
// Throws: Standalone number in template. Replace 18 with :n18 and add: [ ':n18' => 18 ]
```

Four non-errors worth knowing:

- A literal number in a *trailing* `LIMIT` is recognized as safe and runs
  as-is: `"ORDER BY name LIMIT 10"`.
- Empty string literals are allowed: `"email != ''"` runs as-is. The quote
  check only rejects quotes with content between them.
- An empty array in an `IN` list expands to `NULL`, and `IN (NULL)` matches
  nothing, which is usually what an empty list should do. Watch `NOT IN`
  though: `NOT IN (NULL)` *also* matches nothing (a NULL comparison rule in
  MySQL), when an empty exclusion list should match everything. If the list
  can be empty, substitute a value that can't match:
  `[':ids' => $excludeIds ?: [-1]]`.
- Unused *named* parameters are allowed, so you can build one params array
  and pass it to several related queries. Unused *positional* values are
  deprecated (they usually mean a missing `?`) and log a warning.

## Why `::` for the Prefix

The colon count says what gets inserted:

- `:name` - one colon and a name: a value
- `::` - two colons: the table prefix (`::users` → `cms_users`)
- `:::name` - `::` then `:name` combined: the prefix, then a value (`':name' => 'user%'` → `'cms_user%'`)

And `::` has no meaning in MySQL's grammar, so if a template somehow reached
MySQL unprocessed, it would fail loudly with a syntax error rather than run
something unintended.

---

[← Modifying Data](modifying-data.md) | [Documentation Index](README.md) | [Next: Joins and Custom SQL →](joins-and-custom-sql.md)
