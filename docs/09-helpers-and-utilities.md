# Helpers and Utilities

ZenDB includes a set of helper methods for common tasks like pagination, LIKE
pattern building, manual escaping, and schema introspection. This page covers
each one with practical examples.

## Pagination

### `DB::pagingSql($pageNum, $perPage = 10)`

Generates a `LIMIT ... OFFSET ...` clause wrapped in a `RawSql` object, ready
to drop into a query. Pass the current page number and the number of rows per
page.

```php
$pageNum = 2;
$perPage = 25;
$users = DB::select('users', "ORDER BY name :pagingSQL", [
    ':pagingSQL' => DB::pagingSql($pageNum, $perPage),
]);
// Generates: LIMIT 25 OFFSET 25
```

**Defaults and normalization:**

- If `$pageNum` is zero, negative, or non-numeric, it defaults to `1`.
- If `$perPage` is zero, negative, or non-numeric, it defaults to `10`.
- Values are cast to absolute integers before use, so passing user input
  directly is safe from a type perspective.

```php
// Page 1, 10 per page (defaults)
DB::pagingSql(0);        // LIMIT 10 OFFSET 0
DB::pagingSql(1);        // LIMIT 10 OFFSET 0
DB::pagingSql(3, 20);    // LIMIT 20 OFFSET 40
```

## LIKE Pattern Helpers

ZenDB provides four helpers that build properly escaped LIKE patterns. Each
returns a `RawSql` object that includes the surrounding quotes and wildcard
characters, ready to be used as a placeholder value.

### `DB::likeContains($input)`

Matches rows where the column contains the value anywhere:

```php
$users = DB::select('users', "name LIKE ?", DB::likeContains('John'));
// -> name LIKE '%John%'
```

### `DB::likeStartsWith($input)`

Matches rows where the column starts with the value:

```php
$users = DB::select('users', "name LIKE ?", DB::likeStartsWith('J'));
// -> name LIKE 'J%'
```

### `DB::likeEndsWith($input)`

Matches rows where the column ends with the value:

```php
$users = DB::select('users', "name LIKE ?", DB::likeEndsWith('son'));
// -> name LIKE '%son'
```

### `DB::likeContainsTSV($input)`

Matches a value stored in a tab-separated column. Wraps the value with tab
characters so it only matches complete values, not substrings of other values:

```php
$rows = DB::select('settings', "tags LIKE ?", DB::likeContainsTSV('featured'));
// -> tags LIKE '%\tfeatured\t%'
```

**Key point:** All four helpers automatically escape the LIKE wildcard
characters `%` and `_` in the input value. This means user input containing
those characters is handled safely without matching unintended patterns.

## Raw SQL

### `DB::rawSql($value)`

Wraps a value as a `RawSql` object. When ZenDB encounters a `RawSql` value in a
placeholder or column-value array, it inserts the string into the SQL verbatim
- no escaping, no quoting.

```php
$raw = DB::rawSql('NOW()');
DB::insert('users', ['created_at' => $raw]);
// -> INSERT INTO `users` SET `created_at` = NOW()
```

**Warning:** `DB::rawSql()` bypasses all escaping. Never pass user-supplied
input to this method. Doing so reintroduces the SQL injection risk that ZenDB is
designed to prevent.

```php
// DANGER: Never do this
DB::rawSql($_GET['sort']); // Bypasses all escaping -- SQL injection risk
```

## Schema Helpers

These methods inspect the database structure at runtime. They are useful for
admin panels, migration tools, or dynamic form builders.

### `DB::hasTable($table, $isPrefixed = false)`

Check whether a table, view, or temporary table exists in the database. By default,
the configured table prefix is prepended to the name. Pass `true` as the second
argument if the name already includes the prefix.

```php
if (DB::hasTable('users')) {
    // Table exists (checks for prefix + 'users', e.g. 'cms_users')
}

if (DB::hasTable('cms_users', isPrefixed: true)) {
    // Checks for exactly 'cms_users' without adding prefix
}
```

### `DB::getTableNames($includePrefix = false)`

Returns an array of all table names that match the configured table prefix. By
default, the prefix is stripped from the returned names.

```php
$tables = DB::getTableNames();
// ['users', 'orders', 'products', 'order_details']

$tables = DB::getTableNames(true);
// ['cms_users', 'cms_orders', 'cms_products', 'cms_order_details']
```

Tables whose base name starts with an underscore (e.g., `_migrations`) are
sorted to the end of the list.

### `DB::getColumnDefinitions($baseTable)`

Returns an associative array mapping column names to their MySQL column
definitions for the given table. The table prefix is added automatically.

```php
$columns = DB::getColumnDefinitions('users');
// [
//     'id'      => 'int NOT NULL AUTO_INCREMENT',
//     'name'    => 'varchar(255)',
//     'isAdmin' => 'tinyint DEFAULT NULL',
//     'status'  => "enum('Active','Inactive','Suspended')",
//     'city'    => 'varchar(255)',
//     'dob'     => 'date',
//     'age'     => 'int',
// ]
```

Column definitions have table-default charset/collation values removed for
cleaner output, and integer display widths (e.g., `int(11)`) are normalized to
just the type name (e.g., `int`).

### `DB::getFullTable($table, $checkDb = false)`

Returns the full table name with the configured prefix prepended. If the name
already starts with the prefix, it is returned unchanged.

```php
// With tablePrefix = 'cms_'
DB::getFullTable('users');      // 'cms_users'
DB::getFullTable('cms_users');  // 'cms_users' (already has prefix)
```

Pass `checkDb: true` to query the database when the input starts with the prefix
string. This resolves ambiguity when a base name happens to start with the prefix.

### `DB::getBaseTable($table, $checkDb = false)`

Returns the base table name with the configured prefix removed. If the name does
not start with the prefix, it is returned unchanged.

```php
// With tablePrefix = 'cms_'
DB::getBaseTable('cms_users');  // 'users'
DB::getBaseTable('users');      // 'users' (no prefix to remove)
```

Pass `checkDb: true` to query the database when the input starts with the prefix
string. This resolves ambiguity when a base name happens to start with the prefix.

## Transactions

ZenDB doesn't wrap transaction methods. Use MySQL's native syntax via
`DB::query()`:

```php
DB::query("START TRANSACTION");
try {
    $userId = DB::insert('users', ['name' => 'Alice', 'status' => 'Active']);
    DB::insert('profiles', ['user_id' => $userId, 'bio' => 'New user']);
    DB::query("COMMIT");
} catch (\Throwable $e) {
    DB::query("ROLLBACK");
    throw $e;
}
```

If anything fails between `START TRANSACTION` and `COMMIT`, the catch block
rolls back all changes so your data stays consistent.

---

[← Back to README](../README.md) | [← Safety by Design](08-safety-by-design.md) | [Next: Troubleshooting →](10-troubleshooting-and-gotchas.md)
