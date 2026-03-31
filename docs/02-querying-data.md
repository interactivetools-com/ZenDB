# Querying Data

This guide covers fetching data from the database: selecting rows, counting
records, filtering with WHERE conditions, and controlling sort order and
pagination.

## Selecting Multiple Rows - `DB::select()`

`DB::select()` returns a `SmartArrayHtml` of all matching rows. You can pass
WHERE conditions in several forms.

```php
// All rows
$users = DB::select('users');

// WHERE array (simple equality)
$users = DB::select('users', ['status' => 'Active', 'city' => 'Vancouver']);

// SQL string with named placeholders
$users = DB::select('users', "status = :status AND city = :city", [
    ':status' => 'Active',
    ':city'   => 'Vancouver',
]);

// SQL string with positional placeholders (shortcut for simple cases)
$users = DB::select('users', "status = ? AND city = ?", 'Active', 'Vancouver');

// With ORDER BY and LIMIT
$users = DB::select('users', "status = :status ORDER BY name LIMIT :limit", [
    ':status' => 'Active',
    ':limit'  => 10,
]);
```

Iterate over results like any collection. Values are SmartString objects that
automatically HTML-encode when used in string context:

```php
foreach ($users as $user) {
    echo "$user->name - $user->city\n"; // auto HTML-encoded
}
```

For a full guide to what results look like and what you can do with them, see
[Working with Results](03-working-with-results.md).

## Getting a Single Row - `DB::selectOne()`

`DB::selectOne()` returns the first matching row as a `SmartArrayHtml`. It
automatically adds `LIMIT 1` to the query. If no row matches, it returns an
empty `SmartArrayHtml`.

```php
$user = DB::selectOne('users', ['id' => 123]);
echo $user->name; // HTML-encoded

if ($user->isEmpty()) {
    echo "User not found";
}
```

**Important:** Do not add `LIMIT` or `OFFSET` to a `selectOne()` call. ZenDB
already adds `LIMIT 1` internally and will throw an exception if you include
your own:

```php
// Throws -- LIMIT/OFFSET not allowed
$user = DB::selectOne('users', "status = ? LIMIT 1", 'Active');
```

## Counting Rows - `DB::count()`

`DB::count()` returns an integer count of matching rows. It supports the same
WHERE condition forms as `select()`.

```php
$total  = DB::count('users');
$active = DB::count('users', ['status' => 'Active']);
$recent = DB::count('orders', "order_date > ?", '2025-06-01');
```

## WHERE Conditions Reference

ZenDB accepts WHERE conditions in three forms:

- **Array** - Best for simple equality conditions. Clean and readable.
- **Named `:name`** - The standard approach for SQL conditions. Works with any
  number of parameters and each placeholder documents what it represents.
- **Positional `?`** - A convenient shortcut for quick one-liners with just a
  few values.

### 1. Array (recommended for simple equality)

Pass an associative array of column-value pairs. All conditions are joined with
`AND`:

```php
$users = DB::select('users', ['status' => 'Active', 'city' => 'Vancouver']);
// WHERE `status` = 'Active' AND `city` = 'Vancouver'
```

Special value types are handled automatically:

```php
// null becomes IS NULL
$users = DB::select('users', ['isAdmin' => null]);
// WHERE `isAdmin` IS NULL

// Array becomes IN (csv)
$users = DB::select('users', ['status' => ['Active', 'Inactive']]);
// WHERE `status` IN ('Active','Inactive')

// RawSql is inserted as-is
$users = DB::select('users', ['created_at' => DB::rawSql('NOW()')]);
// WHERE `created_at` = NOW()
```

### 2. SQL String with Named Placeholders

Use `:name` placeholders and pass values in an associative array:

```php
$users = DB::select('users', "status = :status AND city = :city", [
    ':status' => 'Active',
    ':city'   => 'Vancouver',
]);
```

Named placeholders scale to any number of parameters and can be reused in the
same query. When in doubt, use named placeholders.

### 3. SQL String with Positional Placeholders (shortcut)

Use `?` placeholders for quick queries with a few values:

```php
$users = DB::select('users', "status = ? AND age > ?", 'Active', 25);
```

Up to three values can be passed as direct arguments. For more, pass an array
or switch to named placeholders.

For the full placeholder reference (type handling, backtick identifiers, edge
cases), see [Placeholders & Parameters](05-placeholders-and-parameters.md).

## ORDER BY, LIMIT, OFFSET

Append ordering and limiting clauses to the SQL string portion of your WHERE
condition:

```php
// ORDER BY only
$users = DB::select('users', "ORDER BY name");

// Combined WHERE + ORDER BY + LIMIT
$users = DB::select('users', "status = ? ORDER BY name DESC LIMIT ?", 'Active', 10);

// With pagination helper
$users = DB::select('users', "ORDER BY name :pagingSQL", [
    ':pagingSQL' => DB::pagingSql(2, 25),
]);
```

A trailing `LIMIT #` in a template is allowed as a convenience, and ZenDB
internally rewrites it to use a safe placeholder.

## Custom SQL - `DB::query()` and `DB::queryOne()`

For queries that do not fit the table-based methods (complex joins, unions,
subqueries), use `DB::query()` with a full SQL statement. Use `DB::queryOne()`
when you only need the first row.

```php
$results = DB::query(
    "SELECT u.name, COUNT(o.order_id) AS order_count
       FROM ::users u
       LEFT JOIN ::orders o ON o.user_id = u.id
      WHERE u.status = ?
      GROUP BY u.id
      ORDER BY order_count DESC",
    'Active'
);

// queryOne() returns just the first row
$row = DB::queryOne("SELECT MAX(price) AS max_price FROM ::products");
echo $row->max_price;
```

`DB::query()` returns a `SmartArrayHtml` collection, `DB::queryOne()` returns
the first row. The same placeholder rules apply to both. For table prefix
placeholders (`::tableName`), Smart Joins, and more complex examples, see
[Joins & Custom SQL](06-joins-and-custom-sql.md).

---

[← Back to README](../README.md) | [← Getting Started](01-getting-started.md) | [Next: Working with Results →](03-working-with-results.md)
