# Core Philosophy and Safety

ZenDB is built around a single idea: **the easiest way to write code should also
be the safest way.** This page explains the design decisions that make that
possible and the protections you get automatically.

## The "Pit of Success" Design

Most database libraries hand you safe tools and unsafe tools side by side, then
trust discipline and code review to make sure you pick the right ones. ZenDB
takes a different approach, one that API designers call the "pit of success":
**the natural way to use the library is the secure way.** There is no unsafe path to accidentally wander down.

The API is deliberately constrained so that the straightforward way to write a
query is also the injection-proof way. The constraints are not guardrails bolted
onto the side of an open road; they are the road itself. This is a conscious
trade-off - a narrower API surface means fewer ways to solve unusual problems -
but the payoff is significant: security stops being something you verify after
the fact and becomes something the library guarantees before execution.

The practical effect is that you stop carrying a mental checklist of things to
sanitize, escape, or double-check. That cognitive overhead disappears. Here is
what the library handles structurally, not by convention:

- **Parameter binding** - Every value flows through placeholders automatically.
  You never decide whether a particular input "needs" escaping; the query
  simply will not execute with unparameterized values in the template.
- **Output encoding** - Query results come back as SmartString objects that
  HTML-encode themselves in string context. Writing `echo $row->name` is
  already safe. You opt _into_ raw output when you need it, not the other way
  around.
- **Bulk operation protection** - UPDATE and DELETE require an explicit WHERE
  condition. A missing or empty clause is an error before the query ever runs,
  not a production incident.
- **Identifier safety** - Table and column names are validated against a strict
  character allowlist before they reach a query. You cannot construct a valid
  call that smuggles SQL through an identifier.

Each of these protections works the same way: the safe behavior is what happens
when you do nothing special. The unsafe behavior requires you to explicitly opt
in. If the code runs without throwing an exception, the parameterization is
correct. The library has already checked.

The goal is not to prevent advanced usage. It is to make sure that the 95% of
queries that are straightforward also happen to be bulletproof. Developers
should naturally fall into successful outcomes by default, not have to climb
toward them.

## Why Placeholders are Strict

Every SQL template you pass to ZenDB is scanned before execution. The following
are rejected outright:

- **Quotes** (single or double): forces values through placeholders
- **Standalone numbers**: forces numeric values through placeholders
- **Backslashes**: prevents escape-sequence manipulation
- **NULL bytes**: prevents string truncation attacks
- **CTRL-Z**: prevents Windows EOF injection

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

Table and column names can only contain letters, numbers, underscores, and
hyphens. Anything else is rejected immediately.

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
is affected, a common source of catastrophic data loss:

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

// Or use a self-referencing expression to bump all rows
DB::update('users', ['views' => DB::rawSql('views + 1')], "TRUE");
```

Tip: When the argument order isn't obvious at a glance, pulling arguments into
named variables can make your intent clearer:

```php
$set   = ['views' => DB::rawSql('views + 1')];
$where = "TRUE";
DB::update('users', $set, $where);
```

## What ZenDB Guarantees

- **Injection-proof SQL**: All dynamic values go through parameterized queries.
  Templates are scanned for suspicious content before execution.
- **HTML-safe output**: SmartString auto-encodes in string context, so
  `echo "Hello $row->name!"` is safe in HTML without any manual escaping.
- **Type-safe parameters**: Values are escaped and quoted based on their PHP
  type: strings are quoted, integers are unquoted, `null` becomes `NULL`,
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
  The correct signature is `update($baseTable, $values, $whereEtc)`:
  values first, then WHERE.

## Automatic HTML-Encoding

Values returned from queries are SmartString objects. When used in string context
(echo, print, string interpolation), they automatically HTML-encode their output:

```php
$row = DB::selectOne('users', ['id' => 1]);

// Outputs: O&apos;Reilly &amp; Sons -- safe in HTML
echo $row->name;

// Returns original value: O'Reilly & Sons
echo $row->name->value();
```

For a complete reference of encoding methods and result handling, see
[Results & Values](06-results-and-values.md).

---

[← Back to README](../README.md) | [← Getting Started](01-quickstart.md) | [Next: Querying & CRUD →](03-querying-and-crud.md)
