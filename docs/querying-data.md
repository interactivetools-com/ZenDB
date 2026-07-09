# Querying Data

Fetching data from the database: selecting rows, fetching a single row,
counting, WHERE conditions, sorting, and pagination.

## Selecting Rows - `DB::select()`

`DB::select()` returns all matching rows as a `SmartArrayHtml`. The WHERE
condition can be an array, a SQL string with placeholders, or omitted entirely:

```php
// All rows
$users = DB::select('users');
// SELECT * FROM `users`

// WHERE array (equality conditions, joined with AND)
$users = DB::select('users', ['status' => 'active', 'city' => 'Vancouver']);
// SELECT * FROM `users` WHERE `status` = 'active' AND `city` = 'Vancouver'

// SQL string with positional placeholders
$users = DB::select('users', "status = ? AND age > ?", 'active', 25);
// SELECT * FROM `users` WHERE status = 'active' AND age > 25

// SQL string with named placeholders
$users = DB::select('users', "status = :status AND age > :age", [
    ':status' => 'active',
    ':age'    => 25,
]);
// SELECT * FROM `users` WHERE status = 'active' AND age > 25

// The WHERE keyword is optional; these two are identical
$users = DB::select('users', "WHERE status = ?", 'active');
$users = DB::select('users', "status = ?", 'active');
// SELECT * FROM `users` WHERE status = 'active'
```

Loop over the result with `foreach` like any array, and echo values directly;
they HTML-encode themselves:

```php
foreach ($users as $user) {
    echo "<li>$user->name - $user->city</li>";  // O'Brien & Sons → O&apos;Brien &amp; Sons
}
```

[Working with Results](working-with-results.md) covers everything you can do
with the returned rows and values.

## Fetching One Row - `DB::selectOne()`

`DB::selectOne()` adds `LIMIT 1` and returns the first matching row. No match
returns an empty `SmartArrayHtml`, never `null`, so field access is always
safe:

```php
$user = DB::selectOne('users', ['id' => 123]);
// SELECT * FROM `users` WHERE `id` = 123 LIMIT 1

if ($user->isEmpty()) {
    echo "User not found";
}
```

## Counting Rows - `DB::count()`

`DB::count()` returns an `int` and accepts the same WHERE forms as `select()`
does (though like `selectOne()`, it throws on `LIMIT` or `OFFSET`):

```php
$total  = DB::count('users');
// SELECT COUNT(*) FROM `users`

$active = DB::count('users', ['status' => 'active']);
// SELECT COUNT(*) FROM `users` WHERE `status` = 'active'

$recent = DB::count('orders', "orderDate > ?", '2026-06-01');
// SELECT COUNT(*) FROM `orders` WHERE orderDate > '2026-06-01'
```

## The Three WHERE Forms

Every query method accepts WHERE conditions in three forms. Pick by
complexity:

- **Array** - equality matches, the common case. Most readable.
- **Positional placeholders `?`** - quick, short SQL conditions.
- **Named placeholders `:name`** - longer conditions with several values.
  When in doubt, use these.

### Array Conditions

Column-value pairs, joined with the `AND` operator:

```php
$books = DB::select('products', ['category' => 'books']);
// SELECT * FROM `products` WHERE `category` = 'books'
```

Three value types get special handling:

```php
// An array becomes an IN list
$users = DB::select('users', ['status' => ['active', 'pending']]);
// SELECT * FROM `users` WHERE `status` IN ('active','pending')

// null becomes IS NULL (a literal "= NULL" would match no rows)
$unverified = DB::select('users', ['verifiedAt' => null]);
// SELECT * FROM `users` WHERE `verifiedAt` IS NULL

// RawSql is inserted as-is
$today = DB::select('orders', ['orderDate' => DB::rawSql('CURDATE()')]);
// SELECT * FROM `orders` WHERE `orderDate` = CURDATE()
```

### Positional Placeholders

`?` placeholders with values as direct arguments, great for short conditions.
Up to three values can be passed; for more, use named placeholders (below):

```php
$users = DB::select('users', "isAdmin = ? AND city = ?", 1, 'Vancouver');
// SELECT * FROM `users` WHERE isAdmin = 1 AND city = 'Vancouver'
```

### Named Placeholders

`:name` placeholders with values in an associative array. With several
values, each placeholder names what it holds and the condition stays readable:

```php
$orders = DB::select('orders', "status = :status AND total >= :minTotal AND orderDate > :since", [
    ':status'   => 'shipped',
    ':minTotal' => 100,
    ':since'    => '2026-01-01',
]);
// SELECT * FROM `orders` WHERE status = 'shipped' AND total >= 100 AND orderDate > '2026-01-01'
```

The same placeholder can be repeated within one condition.

[Placeholders](placeholders.md) is the full reference: every placeholder
type, value handling, and identifier placeholders.

## ORDER BY, LIMIT, and Pagination

Sorting and limiting clauses go at the end of the condition string. A WHERE
condition isn't required first:

```php
// ORDER BY alone
$users = DB::select('users', "ORDER BY name");
// SELECT * FROM `users` ORDER BY name

// Combined, with a placeholder for the limit
$users = DB::select('users', "status = ? ORDER BY name DESC LIMIT ?", 'active', 10);
// SELECT * FROM `users` WHERE status = 'active' ORDER BY name DESC LIMIT 10

// Literal trailing LIMIT is allowed (the one number the template guard accepts)
$users = DB::select('users', "ORDER BY name LIMIT 10");
// SELECT * FROM `users` ORDER BY name LIMIT 10
```

A value bound to a `LIMIT` placeholder must be an int: a string produces
`LIMIT '10'`, a MySQL syntax error, and `$_GET` values arrive as strings, so
cast first ([Placeholders](placeholders.md) covers type conversion).

The template guard normally rejects inline numbers, with one carve-out: a
literal number in a trailing `LIMIT` clause is recognized as safe and runs
as-is. That covers only the exact trailing form `LIMIT 10`. For an offset, use
placeholders or `DB::pagingSql()`, which builds the `LIMIT ... OFFSET ...`
clause from a page number and page size (the full pagination recipe is in
[Common Patterns](common-patterns.md)):

```php
$pageNum = $_GET['page'] ?? 1;
$users   = DB::select('users', "ORDER BY name :pagingSQL", [
    ':pagingSQL' => DB::pagingSql($pageNum, 10),
]);
// for page 1 this runs: SELECT * FROM `users` ORDER BY name LIMIT 10 OFFSET 0
```

`pagingSql()` sanitizes its own inputs: page numbers are cast to int, and
anything empty, zero, or non-numeric falls back to page 1 (and 10 per page),
so user input like `$_GET['page']` can be passed straight in.

## Custom SQL - `DB::query()` and `DB::queryOne()`

When a query outgrows the table-based methods (selecting specific columns
instead of `select()`'s `SELECT *`, joins, unions, subqueries), write the SQL
yourself with `DB::query()`. The same placeholder rules and
returned objects apply, and `::` before a table name inserts your table
prefix:

```php
$rows = DB::query("
    SELECT u.name, COUNT(o.id) AS orderCount
      FROM ::users u
 LEFT JOIN ::orders o ON o.userId = u.id
     WHERE u.status = :status
  GROUP BY u.id
  ORDER BY orderCount DESC",
    [':status' => 'active'],
);

// queryOne() returns just the first row
$row = DB::queryOne("SELECT MAX(price) AS maxPrice FROM ::products");
echo $row->maxPrice;
```

[Joins and Custom SQL](joins-and-custom-sql.md) covers table prefixes, Smart
Joins, and larger examples.

---

[← Getting Started](getting-started.md) | [Documentation Index](README.md) | [Next: Working with Results →](working-with-results.md)
