# The Pit of Success

ZenDB is designed around a single idea: **the easiest way to write code should be the safest way.** Unsafe patterns are impossible, not discouraged.

This page explains the design decisions that make that possible, and the protections you get automatically.

## SQL Injection is Impossible

Every query method accepts conditions through parameterized placeholders. There is no API for concatenating user input into SQL:

```php
// The only way to query
$users = DB::select('users', ['role' => $role]);

// Dynamic conditions use parameterized queries
$rows = DB::query("SELECT * FROM ::users WHERE role = ?", $role);
```

There is no `rawQuery()` that accepts an unparameterized string. `DB::rawSql()` exists for embedding SQL *expressions* (like `NOW()` or `views + 1`), but it does not accept external input - it wraps a literal string, not a variable:

```php
// rawSql wraps a literal expression - not user input
DB::update('users', ['views' => DB::rawSql('views + 1')], ['id' => $id]);

// This is safe because the expression is a constant, not a variable
// The WHERE clause still uses parameterized binding
```

You cannot accidentally introduce an injection vulnerability because the library does not provide a method that would allow it.

## XSS Prevention is Automatic

Values returned from queries are `SmartString` instances. When used in string context (echo, print, string interpolation), they automatically HTML-encode their output:

```php
$user = DB::selectOne('users', ['id' => 1]);

echo $user->name; // O&#039;Reilly &amp; Sons -- HTML-encoded
```

You do not need to remember to call `htmlspecialchars()`. The safe behavior happens without any effort. If you need the raw value (for JSON encoding, database writes, or comparison), access it explicitly:

```php
echo $user->name->value();   // O'Reilly & Sons -- raw value
echo $user->name->rawHtml(); // same thing (alias for trusted HTML)
```

The principle: **encoding is the default, raw access is the opt-in.** Most code never needs the raw value, so most code is safe by accident.

For a complete reference of encoding methods and result handling, see [Results & Values](06-results-and-values.md).

## Type Safety Without Ceremony

Every column value returned from a query is a `SmartString` object. SmartString provides type-appropriate methods for formatting, encoding, and conditional output - all chainable:

```php
$user = DB::selectOne('users', ['id' => 1]);

echo $user->name;                                    // HTML-encoded automatically
echo $user->price->numberFormat(2)->andPrefix('$');   // "$1,234.56"
echo $user->created_at->dateFormat('M j, Y');         // "Sep 10, 2026"
echo $user->nickname->or('Anonymous');                // fallback if empty
```

SmartString prevents common bugs silently:

- **Null safety**: Accessing a missing column returns a SmartNull that absorbs method calls. `$user->deleted_at->dateFormat('M j, Y')` does not throw - it returns an empty string. You do not need null checks before chaining.
- **Type coercion**: `$user->age->int()` and `$user->price->float()` give you the native PHP type when you need it. `$user->age->value()` returns the raw value with its original type.
- **Boolean evaluation**: SmartString works in boolean context, so `if ($user->is_active->value())` behaves as expected.

## Placeholders Replace Templates

Every SQL value you pass to ZenDB goes through parameter binding. The library scans conditions and replaces them with `?` markers before sending anything to the database:

```php
$rows = DB::select('users', [
    'role'    => $role,
    'status'  => 'active',
    'created' => DB::rawSql('NOW()'),
]);
// Executes: SELECT * FROM users WHERE role = ? AND status = ? AND created = NOW()
// Binds: [$role, 'active']
```

The `rawSql` expression (`NOW()`) is embedded directly because it is a literal you wrote, not a variable from user input. The other values go through binding.

This means even accidental string interpolation is caught:

```php
$rows = DB::select('users', "name = '{$name}'");
// Throws -- quotes are not allowed in templates
```

## Identifiers are Validated

Table and column names cannot use parameter binding (SQL does not allow `SELECT * FROM ?`). ZenDB validates all identifiers immediately:

```php
DB::select('users; DROP TABLE users--', ['id' => 1]);
// Throws -- invalid table name
```

Identifiers must match `/^[\w-]+$/` (letters, numbers, underscores, and hyphens only). Special characters, spaces, semicolons, and comment sequences are rejected before the query is built. This prevents injection through table or column names entirely.

## The WHERE Clause is Mandatory

UPDATE and DELETE require a WHERE condition. Without one, every row in the table is affected - a common source of catastrophic data loss:

```php
DB::update('users', ['status' => 'deprecated']);
// Throws -- missing WHERE condition

DB::update('users', ['status' => 'deprecated'], ['id' => $id]);
// Executes -- explicit condition provided
```

If you truly need to modify all rows, state your intent explicitly with a condition that always evaluates to true:

```php
DB::update('users', ['views' => DB::rawSql('views + 1')], "TRUE");
```

## Summary

| Risk | Protection | How |
|---|---|---|
| SQL injection | Parameterized queries only | No raw-string query API |
| XSS | Auto HTML-encoding | SmartString default behavior |
| Null errors | Null-safe chaining | SmartNull absorbs method calls |
| Type bugs | SmartString type methods | `->int()`, `->float()`, `->value()` |
| Accidental bulk UPDATE/DELETE | Required WHERE clause | Throws without a condition |
| Identifier injection | Identifier validation | Regex allowlist on names |

The easiest way to use ZenDB is the safest way. You have to go out of your way to write unsafe code, and even then the library makes it difficult.

---

[← Back to README](../README.md) | [← Getting Started](01-quickstart.md) | [Next: AI Quick Reference →](00-ai-reference.md)
