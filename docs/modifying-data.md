# Modifying Data

Inserting, updating, and deleting rows: how column values map to SQL types,
and how to use SQL expressions like `NOW()`.

## Inserting Rows - `DB::insert()`

`DB::insert()` takes a table name and column-value pairs, and returns the new
auto-increment ID:

```php
$newId = DB::insert('users', [
    'name'    => 'Bob Smith',
    'isAdmin' => 0,
    'status'  => 'active',
    'city'    => 'Vancouver',
]);
// INSERT INTO `users` SET `name` = 'Bob Smith', `isAdmin` = 0, `status` = 'active', `city` = 'Vancouver'

echo "Created user #$newId";
```

## Updating Rows - `DB::update()`

`DB::update()` takes the values to set, then the WHERE condition, and returns
the number of rows changed:

```php
$affected = DB::update('users',
    ['city' => 'Toronto'],   // values to set
    ['id'   => 123],         // WHERE condition
);
// UPDATE `users` SET `city` = 'Toronto' WHERE `id` = 123

// The same call with named variables, easier to tell which array is which
$newValues = ['city' => 'Toronto'];
$where     = ['id'   => 123];
$affected  = DB::update('users', $newValues, $where);

// SQL WHERE with placeholders
DB::update('users', ['status' => 'inactive'], "lastLogin < ?", '2025-01-01');
// UPDATE `users` SET `status` = 'inactive' WHERE lastLogin < '2025-01-01'
```

**The WHERE condition is required.** Updating without one throws, so a typo
can't silently rewrite the whole table. To genuinely update every row, say so
with an always-true condition:

```php
DB::update('users', ['newsletter' => 0], "TRUE");
// UPDATE `users` SET `newsletter` = 0 WHERE TRUE
```

Three details worth knowing:

- The count is MySQL's `affected_rows` value, returned as-is: rows *changed*,
  not rows matched, so rows that already held the new values aren't counted.
- Values come before WHERE. If the arguments get reversed, ZenDB catches the
  most common form (a SET clause that only sets `id` or `num`) and throws
  with the correct signature in the message.
- The WHERE string can carry `ORDER BY` and `LIMIT`, so batched or
  oldest-first operations don't need raw SQL:
  `DB::delete('logs', "createdAt < ? ORDER BY createdAt LIMIT 1000", '2026-01-01')`.

## Deleting Rows - `DB::delete()`

`DB::delete()` takes a WHERE condition (required, same as `update()`) and
returns the number of rows deleted:

```php
$deleted = DB::delete('users', ['id' => 123]);
// DELETE FROM `users` WHERE `id` = 123

DB::delete('users', "status = ?", 'suspended');
// DELETE FROM `users` WHERE status = 'suspended'
```

## Column Values and Types

`insert()` and `update()` convert PHP types to SQL automatically:

```php
DB::insert('products', [
    'name'      => 'Coffee Mug',          // string → 'Coffee Mug' (quoted and escaped)
    'stock'     => 30,                    // int    → 30
    'price'     => 9.5,                   // float  → 9.5
    'featured'  => true,                  // bool   → TRUE
    'notes'     => null,                  // null   → NULL
    'createdAt' => DB::rawSql('NOW()'),   // RawSql → NOW(), inserted as-is
]);
```

SmartString values unwrap to their underlying value automatically. Arrays
throw: column assignment is single-valued, so serialize first
(`json_encode()`, `implode()`).
[Placeholders](placeholders.md) covers the full type handling rules. With
`encryptionKey` set, `MEDIUMBLOB` columns encrypt automatically on
`insert()`/`update()`; see [Encryption](encryption.md).

## SQL Expressions - `DB::rawSql()`

Wrap a value in `DB::rawSql()` when it's a SQL function or expression rather
than data. The wrapped string goes into the query without quoting or
escaping:

```php
DB::insert('users', ['createdAt' => DB::rawSql('NOW()')]);
// INSERT INTO `users` SET `createdAt` = NOW()

DB::insert('coupons', ['expiresAt' => DB::rawSql('NOW() + INTERVAL 30 DAY')]);
// INSERT INTO `coupons` SET `expiresAt` = NOW() + INTERVAL 30 DAY

// Increment a counter in place
$newValues = ['views' => DB::rawSql('views + 1')];
DB::update('articles', $newValues, ['id' => 1]);
// UPDATE `articles` SET `views` = views + 1 WHERE `id` = 1
```

**Never pass user input to `rawSql()`.** It marks its contents as trusted
SQL, so nothing in it gets escaped. Build the expression from fixed strings
and put the data in placeholders or plain values.

---

[← Working with Results](working-with-results.md) | [Documentation Index](README.md) | [Next: Placeholders →](placeholders.md)
