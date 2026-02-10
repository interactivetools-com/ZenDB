# Joins and Raw SQL

## DB::query() — Custom SQL

When `select()`, `get()`, and other convenience methods are not flexible enough, `DB::query()`
lets you write full SQL while retaining all of ZenDB's safety guarantees:

```php
$rows = DB::query("SELECT * FROM ::users WHERE status = ?", 'Active');
```

Key differences from `select()` / `get()`:
- You write the entire SQL statement — there is no automatic `SELECT *` or `FROM`
- SQL must begin with a valid keyword (SELECT, INSERT, UPDATE, etc.)
- Template safety checks still apply (no quotes, no standalone numbers)
- Placeholders are still replaced and values are still escaped
- Returns `SmartArrayHtml` like all other query methods

## Table Prefix Placeholder (`::`)

The `::` prefix is replaced with the configured `tablePrefix` (set via `DB::connect()`).
This is used extensively in `DB::query()` to keep SQL portable across environments with
different table prefixes.

```php
$rows = DB::query("SELECT * FROM ::users");
// With tablePrefix='cms_' -> SELECT * FROM cms_users
```

Works everywhere a table name appears, including JOINs and column references:

```php
$rows = DB::query("SELECT * FROM ::orders
                    LEFT JOIN ::users ON ::orders.user_id = ::users.num
                    WHERE ::orders.total_amount > ?", 100);
```

For backtick forms with dynamic table names and the full placeholder syntax reference,
see [Placeholders & Parameters](04-placeholders-and-parameters.md#table-prefix-placeholders).

## Smart Joins

When a query returns columns from multiple tables, ZenDB automatically adds table-prefixed
keys to each row. This makes it easy to disambiguate columns that share the same name across
different tables.

**Activates when:**
- `useSmartJoins` is `true` (the default)
- The query result contains columns from more than one table

**What it adds:**

```php
$rows = DB::query("SELECT * FROM ::users u
                    JOIN ::orders o ON u.num = o.user_id");

foreach ($rows as $row) {
    // Regular columns (first wins for duplicates)
    $row->num;          // users.num (first occurrence wins)
    $row->name;         // users.name
    $row->order_id;
    $row->user_id;

    // Table-prefixed keys (always available for all columns)
    $row->{'users.num'};
    $row->{'users.name'};
    $row->{'orders.order_id'};
    $row->{'orders.user_id'};
}
```

Note that the table-prefixed keys use the base table name without the configured prefix.
Even if your actual table is `cms_users`, the key is `users.name`, not `cms_users.name`.

### "First Wins" Rule

When multiple tables have a column with the same name (e.g., both `users` and `orders` have
a `num` column), the plain column name keeps the value from whichever table appears first
in the query. Use the qualified `table.column` form to access a specific table's value:

```php
$row->num;              // users.num (first table wins)
$row->{'users.num'};    // explicitly users.num
$row->{'orders.num'};   // explicitly orders.num
```

### Performance

When a query involves only a single table, or involves multiple tables but has no duplicate
column names and no Smart Join processing is needed, ZenDB takes a fast path using the
C-level `MYSQLI_ASSOC` fetch for best performance.

## Self-Join Aliases

When the same table is joined to itself, ZenDB adds alias-based keys in addition to
table-based keys:

```php
$rows = DB::query("SELECT * FROM ::employees a
                    JOIN ::employees b ON a.id = b.manager_id");

foreach ($rows as $row) {
    // Alias-based keys (unique per alias)
    $row->{'a.name'};    // employee name
    $row->{'b.name'};    // manager name

    // Table-based keys (same table, so refers to first occurrence)
    $row->{'employees.name'}; // first occurrence (alias a)
}
```

## Disabling Smart Joins

If Smart Joins add overhead you do not need, disable them using `DB::clone()`:

```php
$db = DB::clone(['useSmartJoins' => false]);
$rows = $db->query("SELECT u.name, o.total_amount
                     FROM ::users u JOIN ::orders o ON u.num = o.user_id");
// No extra table.column keys added — just the columns you selected
```

`DB::clone()` returns a new Connection that shares the same underlying mysqli connection
but with overridden configuration. The original `DB` connection is unaffected.

## DB::rawSql() — The Escape Hatch

For SQL expressions that should not be escaped or quoted, wrap them in `DB::rawSql()`.
The value is inserted into the query as-is:

```php
// SQL functions
DB::insert('users', ['created_at' => DB::rawSql('NOW()')]);

// SQL expressions
DB::update('users', ['views' => DB::rawSql('views + 1')], ['num' => 1]);

// Subqueries (put the subquery in the template so :: prefix expansion applies)
DB::select('users', "score > (SELECT AVG(score) FROM ::users)");
```

**WARNING**: `DB::rawSql()` bypasses all escaping. Never pass user input to it.
For the full reference including `DB::isRawSql()`, see
[Helpers & Utilities](07-helpers-and-utilities.md#raw-sql).

## Putting It Together — Complex Query Example

```php
$pageNum = 2;
$rows = DB::query("SELECT u.name, o.order_date, p.product_name,
                           od.quantity, p.price, (od.quantity * p.price) AS total
                    FROM ::users         AS u
                    JOIN ::orders        AS o  ON u.num         = o.user_id
                    JOIN ::order_details AS od ON o.order_id    = od.order_id
                    JOIN ::products      AS p  ON od.product_id = p.product_id
                    WHERE u.city = :city
                    ORDER BY o.order_date DESC
                    :pagingSQL", [
    ':city'      => 'Vancouver',
    ':pagingSQL' => DB::pagingSql($pageNum, 25),
]);

foreach ($rows as $row) {
    echo "$row->name ordered $row->product_name (qty: $row->quantity) — \$$row->total\n";
}
```

This query demonstrates several ZenDB features working together:
- `::` table prefix placeholders for all table names
- Named placeholder `:city` for the filter value
- `DB::pagingSql()` passed as a `RawSql` value through the `:pagingSQL` placeholder
- Smart Joins automatically providing `users.name`, `orders.order_date`, `products.product_name`, etc.
- Results returned as `SmartArrayHtml` with direct property access on each row

---

[← Back to README](../README.md) | [← Placeholders](04-placeholders-and-parameters.md) | [Next: Results & Values →](06-results-and-values.md)
