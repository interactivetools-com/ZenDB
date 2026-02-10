# Core Philosophy and Safety

ZenDB is built around a single idea: **the easiest way to write code should also
be the safest way.** This page explains the design decisions that make that
possible and the protections you get automatically.

## The "Pit of Success" Design

Most libraries offer safe tools alongside unsafe ones and trust you to choose
correctly. ZenDB takes a different approach: **unsafe patterns are impossible.**

- You cannot embed a quoted string in a SQL template — the library throws an
  exception before the query ever reaches the database.
- You cannot run an UPDATE or DELETE without a WHERE clause — the library stops
  you.
- You cannot pass a table name containing special characters — it is rejected
  immediately.

The result is a library where you couldn't introduce an injection vulnerability
even if you wanted to.

## Why Placeholders are Strict

Every SQL template you pass to ZenDB is scanned before execution. The following
are rejected outright:

- **Quotes** (single or double) — forces values through placeholders
- **Standalone numbers** — forces numeric values through placeholders
- **Backslashes** — prevents escape-sequence manipulation
- **NULL bytes** — prevents string truncation attacks
- **CTRL-Z** — prevents Windows EOF injection

This means even accidental string interpolation is caught immediately:

```php
// This throws an exception -- quotes are not allowed in the template
$rows = DB::select('users', "name = '$name'");
```

The error message tells you exactly what to do:

```
Quotes not allowed in template. Replace 'value' with :paramName and add: [ ':paramName' => 'value' ]
```

The correct approach uses a placeholder, which goes through parameter binding:

```php
$rows = DB::select('users', "name = ?", $name);
```

**Exception:** A trailing `LIMIT #` at the end of a template is allowed for
convenience. ZenDB internally rewrites it to use a placeholder so it remains
safe even if the value originated from user input.

## Why Identifiers are Validated

Table and column names are validated against the regex `/^[\w-]+$/`. Only word
characters (a-z, A-Z, 0-9, underscore, hyphen) are permitted.

This prevents injection through table or column names entirely:

```php
// Throws immediately -- special characters are rejected
DB::select('users; DROP TABLE--');
```

```
Invalid table name 'users; DROP TABLE--', allowed characters: a-z, A-Z, 0-9, _, -
```

## Why Empty WHERE is Blocked

UPDATE and DELETE require a WHERE condition. Without one, every row in the table
is affected — a common source of catastrophic data loss:

```php
// Throws -- empty WHERE condition
DB::update('users', ['status' => 'deleted'], []);
```

```
UPDATE requires a WHERE condition to prevent accidental bulk UPDATE
```

This is intentional. If you truly need to modify all rows, state your intent
explicitly with a condition that always evaluates to true:

```php
// Explicit "update everything" -- makes intent clear
DB::update('users', ['status' => 'deleted'], "TRUE");

// Or use a self-referencing expression
DB::update('users', ['status' => DB::rawSql("status")], ['num' => $num]);
```

## What ZenDB Guarantees

- **Injection-proof SQL**: All dynamic values go through parameterized queries.
  Templates are scanned for suspicious content before execution.
- **HTML-safe output**: SmartString auto-encodes in string context, so
  `echo "Hello $row->name!"` is safe in HTML without any manual escaping.
- **Type-safe parameters**: Values are escaped and quoted based on their PHP
  type — strings are quoted, integers are unquoted, `null` becomes `NULL`,
  booleans become `TRUE`/`FALSE`.

## What ZenDB Refuses to Do

- **Pass raw strings into SQL templates.** Quoted values in templates trigger an
  immediate exception.
- **Allow unparameterized values in query conditions.** Standalone numbers in
  templates are rejected.
- **Execute UPDATE or DELETE without WHERE conditions.** Both operations require
  an explicit condition to prevent accidental bulk modification.
- **Accept suspicious SET clauses.** If an UPDATE only sets a column named
  `num`, `id`, or `ID`, ZenDB assumes the arguments were reversed and throws an error.
  The correct signature is `update($baseTable, $colsToValues, $whereEtc)` —
  values first, then WHERE.

## Automatic HTML-Encoding

Values returned from queries are SmartString objects. When used in string context
(echo, print, string interpolation), they automatically HTML-encode their output:

```php
$row = DB::get('users', ['num' => 1]);

// Outputs: O&apos;Reilly &amp; Sons -- safe in HTML
echo $row->name;

// Returns original value: O'Reilly & Sons
echo $row->name->value();
```

Array notation also works as an alternative:

```php
echo $row['name'];
```

For a complete reference of encoding methods and result handling, see
[Results & Values](06-results-and-values.md).

---

[← Back to README](../README.md) | [← Getting Started](01-quickstart.md) | [Next: Querying & CRUD →](03-querying-and-crud.md)
