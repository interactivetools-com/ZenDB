# Querying and CRUD Operations

This guide covers the most common database tasks: fetching rows, inserting new
data, updating existing rows, and deleting records. Each section is organized
around what you want to accomplish, not around individual method signatures.

## Getting Data

### Selecting Multiple Rows - `DB::select()`

`DB::select()` returns a `SmartArrayHtml` of all matching rows. You can pass
WHERE conditions in several forms.

```php
// All rows
$users = DB::select('users');

// WHERE array (simple equality)
$users = DB::select('users', ['status' => 'Active', 'city' => 'Vancouver']);

// SQL string with positional placeholders
$users = DB::select('users', "status = ? AND city = ?", 'Active', 'Vancouver');

// SQL string with named placeholders
$users = DB::select('users', "status = :status AND city = :city", [
    ':status' => 'Active',
    ':city'   => 'Vancouver',
]);

// With ORDER BY and LIMIT
$users = DB::select('users', "status = ? ORDER BY name LIMIT ?", 'Active', 10);
```

Iterate over results like any collection. Values are SmartString objects that
automatically HTML-encode when used in string context:

```php
foreach ($users as $user) {
    echo "$user->name - $user->city\n"; // auto HTML-encoded
}
```

### Getting a Single Row - `DB::selectOne()`

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

### Counting Rows - `DB::count()`

`DB::count()` returns an integer count of matching rows. It supports the same
WHERE condition forms as `select()`.

```php
$total  = DB::count('users');
$active = DB::count('users', ['status' => 'Active']);
$recent = DB::count('orders', "order_date > ?", '2025-06-01');
```

## Changing Data

### Inserting Rows - `DB::insert()`

`DB::insert()` takes a table name and an associative array of column-value
pairs. It returns the new auto-increment ID.

```php
$newId = DB::insert('users', [
    'name'    => 'Bob Smith',
    'isAdmin' => 0,
    'status'  => 'Active',
    'city'    => 'Vancouver',
]);
echo "Created user #$newId";
```

### Updating Rows - `DB::update()`

`DB::update()` takes the table name, an array of columns to set, and a WHERE
condition. It returns the number of affected rows.

**WHERE is required.** ZenDB blocks updates without a WHERE condition to prevent
accidental bulk modifications. If you truly need to update every row, use an
always-true condition like `"TRUE"`.

```php
$affected = DB::update('users', [
    'city'   => 'Toronto',
    'status' => 'Active',
], ['id' => 123]);

// With SQL WHERE
$affected = DB::update('users', [
    'status' => 'Inactive',
], "lastLogin < ?", '2025-01-01');
```

**Argument order matters.** The signature is `update($baseTable, $values,
$whereEtc)`: values first, then WHERE. If you accidentally reverse them and pass
only a column like `id`, `num`, or `ID` as the SET clause, ZenDB detects the likely
mistake and throws an error.

### Deleting Rows - `DB::delete()`

`DB::delete()` takes the table name and a WHERE condition. It returns the number
of affected rows.

**WHERE is required.** Just like `update()`, ZenDB blocks deletes without a
WHERE condition.

```php
$deleted = DB::delete('users', ['id' => 123]);
$deleted = DB::delete('users', "status = ?", 'Suspended');
```

## The `$values` Array

Both `insert()` and `update()` accept an associative array mapping column names
to values. ZenDB handles type conversion automatically:

```php
$values = [
    'name'       => 'John',                // string  -> quoted and escaped
    'age'        => 30,                    // int     -> unquoted
    'score'      => 9.5,                   // float   -> unquoted
    'isAdmin'    => true,                  // bool    -> TRUE in SET clause
    'bio'        => null,                  // null    -> NULL
    'created_at' => DB::rawSql('NOW()'),   // RawSql  -> inserted as-is
    'sort_order' => DB::rawSql('sort_order + 1'), // SQL expression
];
```

## `DB::rawSql()` for SQL Expressions

Use `DB::rawSql()` when you need a SQL function or expression in a column value.
The wrapped string is inserted into the query without escaping or quoting.

```php
DB::insert('users', ['created_at' => DB::rawSql('NOW()')]);
DB::update('users', ['views' => DB::rawSql('views + 1')], ['id' => 1]);
```

**Warning:** Never pass user input to `rawSql()`. It bypasses all escaping.
For the full reference, see
[Helpers & Utilities](07-helpers-and-utilities.md#raw-sql).

## WHERE Conditions Reference

ZenDB accepts WHERE conditions in four forms. Use whichever best fits the
complexity of your query.

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

### 2. SQL String with Positional Placeholders

Use `?` placeholders for values and pass them as additional arguments:

```php
$users = DB::select('users', "status = ? AND age > ?", 'Active', 25);
```

A maximum of three positional parameters can be passed this way. For more
parameters, use named placeholders or an array.

### 3. SQL String with Named Placeholders

Use `:name` placeholders and pass values in an associative array:

```php
$users = DB::select('users', "status = :status AND city = :city", [
    ':status' => 'Active',
    ':city'   => 'Vancouver',
]);
```

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

## Running Raw SQL - `DB::query()` and `DB::queryOne()`

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
the first row. The same placeholder rules apply to both. ZenDB scans the
template for unsafe patterns and rejects anything suspicious. For table prefix
placeholders, Smart Joins, and more complex examples, see
[Joins & Raw SQL](05-joins-and-raw-sql.md).

---

[← Back to README](../README.md) | [← Core Philosophy](02-core-philosophy-and-safety.md) | [Next: Placeholders →](04-placeholders-and-parameters.md)
