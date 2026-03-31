# Safety by Design

ZenDB is designed around a single idea: **the easiest way to write code should
be the safest way.** Unsafe patterns are impossible, not discouraged.

This is what API designers call the "pit of success": the natural way to use the
library is the secure way. There is no unsafe path to accidentally wander down.
Security stops being something you verify after the fact and becomes something
the library guarantees before execution.

## Insecure Code Can't Work

ZenDB goes beyond making the safe path easy. It makes the unsafe path
non-functional.

Every SQL template is validated before execution. If a developer accidentally
interpolates a variable into a query string, the validation catches it the moment
they test with real data. A query like `WHERE city = '$city'` throws an error as
soon as `$city` contains any real value like "Vancouver". The developer is forced
to switch to `WHERE city = ?` with a placeholder before the code can function at
all.

This creates a powerful guarantee: **code that works during normal development and
testing is safe from injection.** You cannot write code that passes testing with
real values and is also injectable. These properties are mutually exclusive. The
closest analogy is Rust's borrow checker, which prevents unsafe memory patterns
from compiling. ZenDB prevents injection-vulnerable templates from functioning
with real data.

OWASP recommends parameterized queries. ZenDB makes them the only option that
works.

## SQL Injection is Impossible

Every query method accepts conditions through parameterized placeholders. There
is no API for concatenating user input into SQL:

```php
// The only way to query
$users = DB::select('users', ['role' => $role]);

// Dynamic conditions use parameterized queries
$rows = DB::query("SELECT * FROM ::users WHERE role = ?", $role);
```

`DB::rawSql()` exists for embedding SQL expressions (like `NOW()` or
`views + 1`), but it wraps a literal string, not a variable:

```php
// rawSql wraps a literal expression, not user input
DB::update('users', ['views' => DB::rawSql('views + 1')], ['id' => $id]);
```

You cannot accidentally introduce an injection vulnerability because the library
does not provide a method that would allow it.

## XSS Prevention is Automatic

Values returned from queries are SmartString objects. When used in string context
(echo, print, string interpolation), they automatically HTML-encode their output:

```php
$user = DB::selectOne('users', ['id' => 1]);

echo $user->name;           // O&#039;Reilly &amp; Sons -- HTML-encoded
echo $user->name->value();  // O'Reilly & Sons -- raw value
```

**Encoding is the default, raw access is the opt-in.** Most code never needs the
raw value, so most code is safe without any extra effort.

## Why Placeholders are Strict

Every SQL template is scanned before execution. The following are rejected
outright:

- **Quotes** (single or double): forces values through placeholders
- **Standalone numbers**: forces numeric values through placeholders
- **Backslashes**: prevents escape-sequence manipulation
- **NULL bytes**: prevents string truncation attacks
- **CTRL-Z**: prevents Windows EOF injection

This means even accidental string interpolation is caught immediately:

```php
$rows = DB::select('users', "name = '$name'");
// Throws: Quotes not allowed in template
```

**Exception:** A trailing `LIMIT #` at the end of a template is allowed for
convenience. ZenDB internally rewrites it to use a placeholder.

## Identifiers are Validated

Table and column names must match `/^[\w-]+$/` (letters, numbers, underscores,
and hyphens only). Special characters, spaces, semicolons, and comment sequences
are rejected before the query is built:

```php
DB::select('users; DROP TABLE users--', ['id' => 1]);
// Throws: Invalid table name
```

This prevents injection through table or column names entirely.

## The WHERE Clause is Mandatory

UPDATE and DELETE require a WHERE condition. Without one, every row in the table
is affected:

```php
DB::update('users', ['status' => 'deprecated']);
// Throws -- missing WHERE condition

DB::update('users', ['status' => 'deprecated'], ['id' => $id]);
// Executes -- explicit condition provided
```

If you truly need to modify all rows, state your intent explicitly:

```php
DB::update('users', ['views' => DB::rawSql('views + 1')], "TRUE");
```

## What ZenDB Refuses to Do

- **Pass raw strings into SQL templates.** Quoted values in templates trigger an
  immediate exception.
- **Allow unparameterized values in query conditions.** Standalone numbers in
  templates are rejected.
- **Execute UPDATE or DELETE without WHERE conditions.** Both operations require
  an explicit condition to prevent accidental bulk modification.
- **Accept suspicious SET clauses.** If an UPDATE only sets a column named
  `num`, `id`, or `ID`, ZenDB assumes the arguments were reversed and throws an
  error. The correct signature is `update($baseTable, $values, $whereEtc)`:
  values first, then WHERE.

## Summary

| Risk | Protection | How |
|---|---|---|
| SQL injection | Parameterized queries only | No raw-string query API |
| XSS | Auto HTML-encoding | SmartString default behavior |
| Null errors | Null-safe chaining | SmartNull absorbs method calls |
| Type bugs | SmartString type methods | `->int()`, `->float()`, `->value()` |
| Accidental bulk UPDATE/DELETE | Required WHERE clause | Throws without a condition |
| Identifier injection | Identifier validation | Regex allowlist on names |

The easiest way to use ZenDB is the safest way. You have to go out of your way
to write unsafe code, and even then the library won't let it work.

---

[← Back to README](../README.md) | [← Common Patterns](07-common-patterns.md) | [Next: Helpers & Utilities →](09-helpers-and-utilities.md)
