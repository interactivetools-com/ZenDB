# Placeholders and Parameters

## Why Placeholders Matter

ZenDB forces all dynamic values through placeholders. SQL templates cannot contain quotes or
standalone numbers — the library actively rejects them at runtime with clear error messages
instructing you to use placeholders instead. This eliminates SQL injection by design, not by
discipline. For more on this philosophy, see [Core Philosophy](02-core-philosophy-and-safety.md).

## Positional Placeholders (`?`)

Use `?` as a placeholder for values. Each `?` is matched to arguments in order, mapped internally
to `:1`, `:2`, `:3`, etc.

Up to 3 values can be passed directly as method arguments:

```php
DB::select('users', "name = ?", 'John');
DB::select('users', "name = ? AND city = ?", 'John', 'Vancouver');
DB::select('users', "name = ? AND city = ? AND status = ?", 'John', 'Vancouver', 'Active');
```

For more than 3, pass an array:

```php
DB::select('users', "name = ? AND city = ? AND status = ? AND age > ?", [
    'John', 'Vancouver', 'Active', 25
]);
```

## Named Placeholders (`:name`)

Named placeholders start with `:` followed by word characters (`a-z`, `A-Z`, `0-9`, `_`).
They must match the pattern `/^:\w+$/`.

```php
DB::select('users', "city = :city AND status = :status", [
    ':city'   => 'Vancouver',
    ':status' => 'Active',
]);
```

Named placeholders can be reused in the same query:

```php
DB::query("SELECT * FROM ::users WHERE city = :city OR birthplace = :city", [
    ':city' => 'Vancouver',
]);
```

The `:zdb_` prefix is reserved for internal use. Attempting to use it throws an error.

## Mixed Positional and Named

You can mix positional (numeric keys) and named (string keys) in the same array:

```php
DB::select('users', "name = ? AND status = :status", [
    'John',               // positional -> :1
    ':status' => 'Active' // named
]);
```

## Type Handling

ZenDB maps PHP types to SQL output as follows:

| PHP Type | SQL Output | Example |
|---|---|---|
| `string` | Quoted and escaped | `'John'` becomes `"John"` |
| `int` | Unquoted number | `42` becomes `42` |
| `float` | Unquoted number | `9.5` becomes `9.5` |
| `null` | NULL keyword | `null` becomes `NULL` |
| `bool` | TRUE/FALSE | `true` becomes `TRUE` |
| `RawSql` | As-is (no escaping) | `DB::rawSql('NOW()')` becomes `NOW()` |
| `array` | CSV via escapeCSV | `[1,2,3]` becomes `1,2,3` |

**Important**: The difference between a string `"10"` and an integer `10` matters. Strings are
always quoted, integers are not. This is especially relevant for `LIMIT`:

```php
DB::select('users', "LIMIT ?", 10);   // LIMIT 10 (correct — unquoted integer)
DB::select('users', "LIMIT ?", "10"); // LIMIT "10" (wrong — quoted string)
```

SmartString and SmartNull values passed as parameters are automatically unwrapped to their
underlying values. SmartArrayBase values are converted via `->toArray()`.

## Backtick Identifier Placeholders

For dynamic table or column names, use backtick-wrapped placeholders. Values are validated to
contain only word characters and hyphens — anything else throws an error.

```php
// Positional identifier placeholder
DB::query("SELECT `?` FROM ::users", 'name');
// -> SELECT `name` FROM cms_users

// Named identifier placeholder
DB::query("SELECT `:col` FROM ::users", [':col' => 'name']);
// -> SELECT `name` FROM cms_users
```

## Table Prefix Placeholders

The `::` prefix is replaced with the configured `tablePrefix` value (set via `DB::connect()`).

**Bare `::` (most common)** — no backticks, the prefix is prepended directly:

```php
DB::query("SELECT * FROM ::users JOIN ::orders ON ::users.num = ::orders.user_id");
// With tablePrefix='cms_' -> SELECT * FROM cms_users JOIN cms_orders ON cms_users.num = cms_orders.user_id
```

**Backtick with prefix** — for dynamic table names that also need the prefix:

```php
// `::?` — positional with prefix
DB::query("SELECT * FROM `::?`", 'users');
// -> SELECT * FROM `cms_users`

// `:::name` — named with prefix
DB::query("SELECT * FROM `:::table`", [':table' => 'users']);
// -> SELECT * FROM `cms_users`
```

## What Happens If...

### Missing parameter

```php
DB::select('users', "name = ? AND city = ?", 'John');
// Error: Missing value for ? parameter at position 2
```

### Array with positional `?`

Arrays cannot be used with positional `?` placeholders because the expansion is ambiguous:

```php
DB::select('users', "id IN (?)", [1, 2, 3]);
// Error: Arrays not allowed with positional ? placeholders (ambiguous). Use named placeholder instead.

// Fix: use a named placeholder
DB::select('users', "id IN (:ids)", [':ids' => [1, 2, 3]]);
```

### Empty array

An empty array expands to `NULL`, which effectively matches nothing:

```php
DB::select('users', "id IN (:ids)", [':ids' => []]);
// -> WHERE id IN (NULL) — matches nothing
```

### Trailing LIMIT with a number

A trailing `LIMIT` followed by a number at the end of the template is a special exception.
ZenDB allows this without requiring a placeholder:

```php
DB::select('users', "status = ? ORDER BY name LIMIT 10", 'Active');
// Allowed! Trailing LIMIT # is internally rewritten to use a placeholder before safety checks
```

### Standalone numbers elsewhere

Any other standalone number in the template triggers an error:

```php
DB::select('users', "age > 18");
// Error: Standalone number in template. Replace 18 with :n18 and add: [ ':n18' => 18 ]
```

### Quotes in templates

Quotes in the SQL template are never allowed — use placeholders:

```php
DB::select('users', "name = 'John'");
// Error: Quotes not allowed in template. Replace 'John' with :paramName and add: [ ':paramName' => 'John' ]
```

### Duplicate parameter names

In PHP, duplicate array keys are resolved by the language itself (the last value wins), so
ZenDB never sees the duplicate. This is standard PHP behavior, not a ZenDB error:

```php
DB::select('users', "city = :city", [':city' => 'Vancouver', ':city' => 'Toronto']);
// PHP silently keeps only the last value -- ZenDB receives [':city' => 'Toronto']
```

### Extra/unused parameters

```php
DB::select('users', "name = ?", 'John', 'extra_value');
// Extra parameters are silently ignored
```

---

[← Back to README](../README.md) | [← Querying & CRUD](03-querying-and-crud.md) | [Next: Joins & Raw SQL →](05-joins-and-raw-sql.md)
