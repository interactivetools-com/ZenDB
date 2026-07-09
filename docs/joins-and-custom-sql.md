# Joins and Custom SQL

Queries that outgrow the table-based methods: writing full SQL with
`DB::query()` and `DB::queryOne()`, table prefixes in raw SQL, and Smart
Joins, the table-qualified keys ZenDB adds to multi-table results.

## Custom SQL - `DB::query()` and `DB::queryOne()`

`DB::query()` runs SQL you write yourself, with the same protections as
`select()`. The template guard still rejects quotes and inline numbers, and
values still enter through placeholders. `DB::queryOne()` works the same way
but returns only the first row:

```php
$rows = DB::query("
    SELECT * FROM ::users
      JOIN ::orders ON ::orders.userId = ::users.id
     WHERE ::orders.total > ?", 100);

$row = DB::queryOne("SELECT MAX(price) AS maxPrice FROM ::products");
echo $row->maxPrice;
```

Differences from the table-based methods:

- You write the whole statement; nothing is added for you, except that
  `queryOne()` appends `LIMIT 1` to SELECT queries.
- Placeholders, escaping, and the template guard work exactly the same.
- The returned objects are the same: `query()` returns a row collection,
  `queryOne()` a single row.

`queryOne()` appends its own `LIMIT 1`, so it doesn't accept a template with
`LIMIT` or `OFFSET`. For your own `LIMIT`, or clauses that go after it like
`FOR UPDATE`, use `DB::query(...)->first()`.

## Table Prefixes in Raw SQL with `::`

`select()` adds the table prefix for you. In raw SQL, write `::` in front of
every table name, including column references:

```php
$rows = DB::query("
    SELECT * FROM ::orders
 LEFT JOIN ::users ON ::orders.userId = ::users.id
     WHERE ::orders.total > ?", 100);
// with tablePrefix 'cms_' this runs:
// SELECT * FROM cms_orders LEFT JOIN cms_users ON cms_orders.userId = cms_users.id ...
```

Table aliases don't take the prefix; only the real names after `FROM` and
`JOIN` do:

```php
$rows = DB::query("SELECT u.name, o.total FROM ::users u JOIN ::orders o ON o.userId = u.id");
// SELECT u.name, o.total FROM cms_users u JOIN cms_orders o ON o.userId = u.id
```

For dynamic table names and the full placeholder reference, see
[Placeholders](placeholders.md).

## Smart Joins

When a result contains columns from more than one table, ZenDB adds a
qualified `table.column` key for every column, alongside the plain column
names. Columns that share a name across tables stop being ambiguous:

```php
$rows = DB::query("SELECT * FROM ::users JOIN ::orders ON ::orders.userId = ::users.id");

foreach ($rows as $row) {
    echo $row->name;               // plain key
    echo $row->get('users.id');    // always the users table's id
    echo $row->get('orders.id');   // always the orders table's id
}
```

Qualified keys contain a dot, which PHP object syntax can't type, so read
them with `get()`, covered in [Working with Results](working-with-results.md).

Here is every key one joined row contains. Plain keys come first, one per
column name in SELECT order; the qualified keys follow:

```php
$row = DB::queryOne("
    SELECT *, YEAR(o.orderDate) AS orderYear
      FROM ::users  u
      JOIN ::orders o ON o.userId = u.id");

print_r($row);
// [id]               => 1           // plain keys: duplicates keep the first value
// [name]             => Alice
// [city]             => Vancouver
// [userId]           => 1
// [orderDate]        => 2026-07-02
// [total]            => 100.00
// [orderYear]        => 2026        // computed column: alias only
// [users.id]         => 1           // qualified keys: one per table column
// [users.name]       => Alice
// [users.city]       => Vancouver
// [orders.id]        => 7001
// [orders.userId]    => 1
// [orders.orderDate] => 2026-07-02
// [orders.total]     => 100.00
```

Qualified keys follow one rule: reading the key should tell you the source.
`users.id` means the `id` column of the `users` table, no query context
needed. That's why the prefix is dropped (`cms_users` still produces
`users.id`), why aliases don't get keys (`FROM ::users u` also produces
`users.id`, because `u.id` needs the query to decode), and why self-joins
are the exception: when the same table appears twice, the table name stops
identifying the source, so the aliases step in (below).

Computed columns get only their alias: `YEAR(o.orderDate) AS orderYear`
appears as `orderYear`, with no qualified key.

Whenever you're unsure what keys a query produced, `print_r($row)` and look.

Smart Joins are on by default and only add keys to multi-table results;
single-table queries are unchanged. Turning them off for a query or a whole
connection is covered below.

### Duplicate Keys: The First One Wins

In PHP, when the same array key is assigned twice, the last value wins.
ZenDB goes the other way: when a query returns two columns with the same
name, the plain key keeps the **first** one, and later columns never
overwrite it. With `SELECT *`, columns arrive in join order, so in practice
the first table wins:

```php
$row = DB::queryOne("SELECT * FROM ::users JOIN ::orders ON ::orders.userId = ::users.id");

echo $row->id;                // 1    - users comes first, so this is the users id
echo $row->get('orders.id');  // 7001 - the orders id is still there, under its qualified key
```

This is deliberate: your main table comes first in the query, so joining
lookup tables on never changes the values you started with. Every later
duplicate stays reachable through its qualified key.

## Self-Joins

When the same table appears more than once, its table-qualified keys can
only point at the first occurrence, so ZenDB also adds alias-based keys for
that table:

```php
$rows = DB::query("SELECT * FROM ::employees a JOIN ::employees b ON a.managerId = b.id");

foreach ($rows as $row) {
    echo $row->get('a.name');   // the employee
    echo $row->get('b.name');   // their manager
}
// $row->get('employees.name') also exists and holds the first occurrence (alias a)
```

## Turning Smart Joins Off - `DB::clone()`

To get results with only the column names you selected, clone the connection
with `useSmartJoins` off:

```php
$db   = DB::clone(['useSmartJoins' => false]);
$rows = $db->query("SELECT u.name, o.orderDate FROM ::users u JOIN ::orders o ON o.userId = u.id");
// Rows have keys 'name' and 'orderDate', no table-qualified keys
```

`DB::clone()` returns a new `Connection` that shares the live MySQL
connection but has its own settings; the default `DB` connection is
unchanged. [Multiple Connections](multiple-connections.md) covers it fully.

One caution: when a query returns two columns with the same name, the first
one still sets the value ([Duplicate Keys](#duplicate-keys-the-first-one-wins)
above), and with Smart Joins off there's no qualified key to reach the
second. Select specific columns instead, with an `AS` alias for any shared
name:

```php
$rows = $db->query("
    SELECT u.id AS userId, o.id AS orderId, u.name, o.total
      FROM ::users u JOIN ::orders o ON o.userId = u.id");
// Rows have keys 'userId', 'orderId', 'name', and 'total'
```

## SQL Expressions as Values - `DB::rawSql()`

Placeholder values are always quoted and escaped, so a SQL expression passed
as a value would arrive as a string literal. Wrap it in `DB::rawSql()` to
insert it as-is:

```php
DB::insert('users', [
    'name'      => 'Alice',
    'createdAt' => DB::rawSql('NOW()'),
]);
// INSERT INTO `users` SET `name` = 'Alice', `createdAt` = NOW()

DB::update('articles',
    ['views' => DB::rawSql('views + 1')],  // columns to set
    ['id'    => 42],                       // WHERE condition
);
// UPDATE `articles` SET `views` = views + 1 WHERE `id` = 42
```

**Never pass user input to `rawSql()`.** It bypasses all escaping; that is
its job. [Helpers and Utilities](helpers-and-utilities.md) is the full
reference.

Subqueries don't need `rawSql()`. Write them in the template, where the
guard still applies and `::` prefixes still expand:

```php
$pricey = DB::select('products', "price > (SELECT AVG(price) FROM ::products)");
// SELECT * FROM `products` WHERE price > (SELECT AVG(price) FROM products)
```

## Putting It Together

Everything on this page in one query: table prefixes on four tables, a named
placeholder, `DB::pagingSql()` passed through a placeholder (`RawSql` values
skip escaping), and Smart Join keys on the result:

```php
$pageNum = $_GET['page'] ?? 1;
$rows    = DB::query("
    SELECT u.name, o.orderDate, p.title, oi.qty, (oi.qty * p.price) AS total
      FROM ::users       u
      JOIN ::orders      o  ON o.userId   = u.id
      JOIN ::order_items oi ON oi.orderId = o.id
      JOIN ::products    p  ON p.id       = oi.productId
     WHERE u.city = :city
  ORDER BY o.orderDate DESC
  :pagingSQL", [
    ':city'      => 'Vancouver',
    ':pagingSQL' => DB::pagingSql($pageNum, 25),
]);

foreach ($rows as $row) {
    echo "$row->name bought $row->title (qty $row->qty) for \$$row->total<br>\n";
}
```

The first row, printed:

```php
print_r($rows->first());
/*
    [name]             => Alice          plain keys, in SELECT order
    [orderDate]        => 2026-07-02
    [title]            => Blue Widget
    [qty]              => 2
    [total]            => 59.90          computed column: alias only
    [users.name]       => Alice          qualified keys follow
    [orders.orderDate] => 2026-07-02
    [products.title]   => Blue Widget
    [order_items.qty]  => 2
*/
```

---

[← Placeholders](placeholders.md) | [Documentation Index](README.md) | [Next: Common Patterns →](common-patterns.md)
