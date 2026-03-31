# Modifying Data

This guide covers inserting, updating, and deleting rows, including how ZenDB
handles type conversion in column values and how to use SQL expressions like
`NOW()`.

## Inserting Rows - `DB::insert()`

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

## Updating Rows - `DB::update()`

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

## Deleting Rows - `DB::delete()`

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

For the full type mapping table covering all PHP types, see
[Placeholders & Parameters](05-placeholders-and-parameters.md#type-handling).

## `DB::rawSql()` for SQL Expressions

Use `DB::rawSql()` when you need a SQL function or expression in a column value.
The wrapped string is inserted into the query without escaping or quoting.

```php
DB::insert('users', ['created_at' => DB::rawSql('NOW()')]);
DB::update('users', ['views' => DB::rawSql('views + 1')], ['id' => 1]);
```

**Warning:** Never pass user input to `rawSql()`. It bypasses all escaping.
For the full reference, see
[Helpers & Utilities](09-helpers-and-utilities.md#raw-sql).

---

[← Back to README](../README.md) | [← Working with Results](03-working-with-results.md) | [Next: Placeholders →](05-placeholders-and-parameters.md)
