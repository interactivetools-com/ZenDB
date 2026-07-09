# Troubleshooting

The exact error messages ZenDB throws, what each one means, and the fix. Plus
deprecation warnings, connection problems, behavioral gotchas, and how to see
the SQL a call actually ran. Headings quote the error text so you can find
them by search.

## Error Messages

### "Quotes not allowed in template"

Full message includes the quoted text and the fix, for example:
`Quotes not allowed in template. Replace 'John' with :paramName and add: [ ':paramName' => 'John' ]`

**What happened:** The SQL template contains a quote character (single or
double). Templates are code, values are data; the guard rejects quotes so a
value can never be concatenated into the SQL.

```php
// Throws - quoted value in template
DB::select('users', "name = 'John'");
```

**Fix:** Move the value into a placeholder:

```php
DB::select('users', "name = ?", 'John');
// SELECT * FROM `users` WHERE name = 'John'
```

### "Standalone number in template"

Full message includes the number and the fix, for example:
`Standalone number in template. Replace 21 with :n21 and add: [ ':n21' => 21 ]`

**What happened:** The SQL template contains a literal number. A literal
number in a template is indistinguishable from user input that was
concatenated in, so the guard rejects it.

```php
// Throws - literal number in template
DB::select('users', "age > 21");
```

**Fix:** Use a placeholder:

```php
DB::select('users', "age > ?", 21);
// SELECT * FROM `users` WHERE age > 21
```

**Exception:** a trailing `LIMIT 10` is allowed and runs exactly as written.
MySQL's `LIMIT` only accepts literal integers, so the guard strips a trailing
`LIMIT #` from the copy it checks; anything that isn't a plain trailing
`LIMIT #` still throws.

### "Max 3 positional arguments allowed. For more, use named placeholders: [':name' => $value]"

**What happened:** More than 3 values were passed for `?` placeholders.
Positional placeholders stop at three because matching each `?` to its value
by counting positions is where mistakes start.

```php
// Throws - four positional values
DB::select('users', "a = ? AND b = ? AND c = ? AND d = ?", 1, 2, 3, 4);
```

**Fix:** Switch to named placeholders:

```php
DB::select('users', "a = :a AND b = :b AND c = :c AND d = :d", [
    ':a' => 1,
    ':b' => 2,
    ':c' => 3,
    ':d' => 4,
]);
```

### "Can't mix positional (?) and named (:param) placeholders. Use one style consistently."

**What happened:** One query used both `?` and `:name` placeholders.

```php
// Throws - two placeholder styles in one call
DB::select('users', "status = ? AND city = :city", [':city' => 'Vancouver']);
```

**Fix:** Pick one style for the whole query:

```php
DB::select('users', "status = :status AND city = :city", [
    ':status' => 'active',
    ':city'   => 'Vancouver',
]);
```

### "Missing value for ? parameter at position N"

**What happened:** The template has more `?` placeholders than values.

```php
// Throws - 2 placeholders, 1 value
DB::select('users', "name = ? AND city = ?", 'Alice');
```

**Fix:** Pass one value per placeholder:

```php
DB::select('users', "name = ? AND city = ?", 'Alice', 'Vancouver');
```

### "Missing value for ':name' parameter"

**What happened:** The template references a named placeholder that isn't in
the params array.

```php
// Throws - :city not in the array
DB::select('users', "name = :name AND city = :city", [':name' => 'Alice']);
```

**Fix:** Add the missing key:

```php
DB::select('users', "name = :name AND city = :city", [
    ':name' => 'Alice',
    ':city' => 'Vancouver',
]);
```

### "Arrays not allowed with positional ? placeholders (ambiguous). Use named placeholder instead: ':paramName' => [...]"

**What happened:** An array value reached a `?` placeholder. With named
placeholders an array expands to a comma-separated list (for `IN`); with `?`
there is no way to tell whether you meant one value or a list, so it throws.

**Fix:** Use a named placeholder for the list:

```php
DB::select('users', "id IN (:ids)", [':ids' => [1, 2, 3]]);
// SELECT * FROM `users` WHERE id IN (1,2,3)
```

### "This method doesn't support LIMIT or OFFSET"

**What happened:** The template passed to `selectOne()`, `queryOne()`, or
`count()` contains `LIMIT` or `OFFSET`. `selectOne()` and `queryOne()` append
`LIMIT 1` themselves, and `count()` returns a single number; a caller-supplied
`LIMIT` conflicts with both.

```php
// Throws - selectOne() adds its own LIMIT 1
DB::selectOne('users', "status = ? LIMIT 5", 'active');
```

**Fix:** Use `select()` (or `query()`) when you want your own `LIMIT`, or
`query(...)->first()` when you want one row from a query these methods
reject:

```php
DB::select('users', "status = ? LIMIT 5", 'active');
// SELECT * FROM `users` WHERE status = 'active' LIMIT 5

DB::query("SELECT * FROM ::users WHERE status = ? ORDER BY id DESC LIMIT 5", 'active')->first();
```

`selectOne()` and `queryOne()` also reject templates ending in a `--` or `#`
comment or a `;`, because the appended ` LIMIT 1` would be swallowed by the
comment (silent full-table scan) or fail to parse after the semicolon. The
messages name the fix; `query(...)->first()` is the escape for those too.

### "UPDATE requires a WHERE condition to prevent accidental bulk UPDATE"

Also thrown as `DELETE requires a WHERE condition to prevent accidental bulk DELETE`.

**What happened:** `update()` or `delete()` was called with an empty WHERE, or
a string starting with `ORDER`, `LIMIT`, `OFFSET`, or `FOR` (which would apply
to every row).

```php
// Throws - empty WHERE
DB::update('users', ['status' => 'inactive'], []);
```

**Fix:** Add a WHERE condition. To intentionally affect every row, pass the
literal string `"TRUE"`; that is the designed escape:

```php
DB::update('users', ['status' => 'inactive'], ['id' => 42]);
// UPDATE `users` SET `status` = 'inactive' WHERE `id` = 42

DB::update('users', ['status' => 'inactive'], "TRUE");
// UPDATE `users` SET `status` = 'inactive' WHERE TRUE
```

### "Suspicious SET clause: only updating 'num'. Did you reverse the arguments? Signature is: update($table, $values, $whereEtc)"

**What happened:** The UPDATE sets exactly one column and it's named `num`,
`id`, or `ID`. That almost always means the values and WHERE arguments are
swapped, an easy mix-up since both are arrays.

```php
// Throws - arguments reversed
DB::update('users', ['num' => 5], ['status' => 'active']);
```

**Fix:** Values first, WHERE second:

```php
DB::update('users', ['status' => 'active'], ['num' => 5]);
// UPDATE `users` SET `status` = 'active' WHERE `num` = 5
```

## Deprecation Warnings

These log `E_USER_DEPRECATED` (with the calling file and line appended) and
keep working for now, but will throw in a future version. Fix them when they
show up in your error log.

### "Positional values in an array are deprecated. Pass up to 3 values directly for ? placeholders, or use named placeholders: [':name' => $value]"

**What happened:** Values for `?` placeholders were wrapped in an array.

```php
// Deprecated - logs a warning, still runs
DB::select('users', "name = ? AND city = ?", ['Alice', 'Vancouver']);
```

**Fix:** Pass up to 3 values directly, or use named placeholders:

```php
DB::select('users', "name = ? AND city = ?", 'Alice', 'Vancouver');
```

### "Query has N positional (?) placeholder(s) but M values were passed. Unused positional values are deprecated and will throw in a future version. For IN() lists use a named placeholder: ':ids' => [...]"

**What happened:** More positional values were passed than the template has
`?` placeholders. The extras are silently unused, which almost always means a
bug; the classic case is trying to expand a list into a single `?` placeholder:

```php
// Deprecated - runs as IN (1), values 2 and 3 are ignored
DB::select('users', "id IN (?)", 1, 2, 3);
```

**Fix:** For lists, use a named placeholder, which expands arrays:

```php
DB::select('users', "id IN (:ids)", [':ids' => [1, 2, 3]]);
// SELECT * FROM `users` WHERE id IN (1,2,3)
```

## Connection Problems

### Connection Fails on WSL with "No such file or directory"

`localhost` on Windows Subsystem for Linux connects through a Unix socket,
which doesn't exist when MySQL runs on the Windows host. Use the IP address
instead:

```php
DB::connect([
    'hostname' => '127.0.0.1',  // not 'localhost'
    'username' => 'root',
    'password' => '',
    'database' => 'my_app',
]);
```

### SSL Connection Errors

`requireSSL` defaults to `false`. If you enabled it and the server doesn't
support SSL, connecting fails with MySQL error 2006 and the message starts
with `Try disabling 'requireSSL' in database configuration.` Do that, or
enable SSL on the server.

### "This program requires MySQL v5.7.32+ or compatible. This server has MySQL vX.Y.Z installed."

ZenDB requires MySQL 5.7.32 or newer by default (a compatible server like
MariaDB or Percona also passes, compared on its own version number). Upgrade
the server, or lower the check with the `versionRequired` config setting if
you've confirmed your older version works for your queries:

```php
DB::connect([
    'hostname'        => '127.0.0.1',
    'username'        => 'root',
    'password'        => '',
    'database'        => 'my_app',
    'versionRequired' => '5.7.0',  // lower the minimum
]);
```

## Gotchas

### NULL Comparisons - WHERE Array vs Placeholder

SQL's `=` never matches `NULL`; that needs `IS NULL`. The WHERE array form
converts `null` for you; a placeholder inserts a literal `NULL` and the
comparison matches nothing:

```php
// WHERE array: converts to IS NULL
DB::select('users', ['deletedAt' => null]);
// SELECT * FROM `users` WHERE `deletedAt` IS NULL

// Placeholder: literal NULL, "= NULL" matches no rows
DB::select('users', "deletedAt = ?", null);
// SELECT * FROM `users` WHERE deletedAt = NULL
```

With string templates, write `deletedAt IS NULL` yourself or use the array
form.

### NULL and Empty Arrays in IN Lists

Two related behaviors when an array expands into `IN (...)`. First, `null`
elements are skipped, because `IN (1, NULL)` would never match `NULL` rows
anyway:

```php
DB::select('users', "id IN (:ids)", [':ids' => [1, null, 3]]);
// SELECT * FROM `users` WHERE id IN (1,3)
```

Second, an empty array expands to `IN (NULL)`, which matches nothing. For
`IN`, that's usually what you want; an empty list of wanted ids should return
no rows:

```php
$wantedIds = [];  // e.g., no checkboxes ticked
DB::select('users', "id IN (:ids)", [':ids' => $wantedIds]);
// SELECT * FROM `users` WHERE id IN (NULL) - returns no rows
```

The trap is `NOT IN`. `NOT IN (NULL)` **also** matches nothing, but an empty
exclusion list should match everything. The query silently returns zero rows
instead of all rows. Fix it with a sentinel id that can't exist:

```php
$excludeIds = [];  // nothing to exclude - should return all rows

// Wrong: NOT IN (NULL) returns no rows
DB::select('users', "id NOT IN (:ids)", [':ids' => $excludeIds]);

// Right: sentinel keeps the list non-empty
DB::select('users', "id NOT IN (:ids)", [':ids' => $excludeIds ?: [-1]]);
// SELECT * FROM `users` WHERE id NOT IN (-1) - returns all rows
```

### Booleans Convert to TRUE and FALSE

PHP booleans become the SQL keywords `TRUE` and `FALSE` (which MySQL stores
as `1` and `0`):

```php
DB::insert('users', ['isAdmin' => true]);
// INSERT INTO `users` SET `isAdmin` = TRUE
```

## Debugging

### Seeing the SQL That Just Ran - `DB::$mysqli->lastQuery`

ZenDB escapes values into the final SQL string before sending it, so the
exact query is always available after any call:

```php
DB::select('users', ['status' => 'active', 'city' => 'Vancouver']);
echo DB::$mysqli->lastQuery;
// SELECT * FROM `users` WHERE `status` = 'active' AND `city` = 'Vancouver'
```

This is the first thing to check when a query returns unexpected results:
read the SQL that actually ran, then run it yourself in a MySQL client.

### Inspecting Results - print_r()

Results are collections, but `print_r()` shows their contents like plain
arrays:

```php
$users = DB::select('users', ['status' => 'active']);
print_r($users);
```

CMS Builder users: `showme($users)` prints the same thing with formatting and
the calling line number.

### Behavior Reference

For version-by-version behavior across MySQL and MariaDB (type conversions,
edge cases, driver differences), see the CI-generated
[db-behavior-report.md](../tools/db-behavior-report.md).

---

[← Security Gotchas](security-gotchas.md) | [Documentation Index](README.md) | [Next: Method Reference →](method-reference.md)
