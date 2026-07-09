# Helpers and Utilities

Small helpers that build SQL fragments and check inputs: `DB::rawSql()` for
trusted SQL expressions, `DB::pagingSql()` for pagination, four `like*()`
helpers for escaped LIKE patterns, table prefix conversion, and date format
constants.

## Trusted SQL Expressions - `DB::rawSql()`

`DB::rawSql()` wraps a string as a `RawSql` object. Wherever ZenDB accepts a
value (placeholder parameters, insert/update column arrays, WHERE arrays), a
`RawSql` value is inserted into the SQL verbatim: no quoting, no escaping.
That's how you pass a SQL function or expression where a plain string would be
quoted into a literal:

```php
DB::insert('users', ['name' => 'Alice', 'createdAt' => DB::rawSql('NOW()')]);
// INSERT INTO `users` SET `name` = 'Alice', `createdAt` = NOW()

DB::update('users', ['views' => DB::rawSql('views + 1')], ['id' => 42]);
// UPDATE `users` SET `views` = views + 1 WHERE `id` = 42

$today = DB::select('orders', ['orderDate' => DB::rawSql('CURDATE()')]);
// SELECT * FROM `orders` WHERE `orderDate` = CURDATE()
```

It also works as a placeholder value in SQL strings:

```php
$expires = DB::select('coupons', "expiresAt > ?", DB::rawSql('NOW()'));
// SELECT * FROM `coupons` WHERE expiresAt > NOW()
```

The signature is `rawSql(string|int|float|null $value): RawSql`. Ints and
floats are converted to their string form, and `null` becomes the SQL keyword
`NULL`.

**Never pass user input to `rawSql()`.** It marks its argument as trusted SQL
and skips every protection the library has. This is the escape hatch, and the
one place where SQL injection is your responsibility again:

```php
// WRONG - user input inserted into SQL verbatim, classic injection
DB::select('users', "ORDER BY ?", DB::rawSql($_GET['sort']));

// RIGHT - identifier placeholder validates the value before the query runs
DB::select('users', "ORDER BY `:sort`", [':sort' => $_GET['sort'] ?? 'name']);
```

The remaining helpers on this page (`pagingSql()` and the `like*()` methods)
also return `RawSql` objects; the difference is they escape and validate their
inputs first, so user input can be passed to them directly.

## Pagination - `DB::pagingSql()`

`DB::pagingSql($pageNum, $perPage = 10)` builds a `LIMIT ... OFFSET ...`
clause from a page number and page size, returned as a `RawSql` ready to use
as a placeholder value:

```php
$page  = $_GET['page'] ?? 1;
$users = DB::select('users', "ORDER BY name :paging", [
    ':paging' => DB::pagingSql($page, 25),
]);
// for page 3 this runs: SELECT * FROM `users` ORDER BY name LIMIT 25 OFFSET 50
```

It sanitizes its own inputs, so `$_GET['page']` can be passed straight in:
each argument is cast to an integer and made positive (`abs()`), and anything
that comes out zero (missing, non-numeric, empty string) falls back to the
default, page 1 and 10 per page:

```php
DB::pagingSql(1);          // LIMIT 10 OFFSET 0
DB::pagingSql(3, 25);      // LIMIT 25 OFFSET 50
DB::pagingSql(0);          // LIMIT 10 OFFSET 0   (page 0 becomes page 1)
DB::pagingSql('abc');      // LIMIT 10 OFFSET 0   (non-numeric becomes page 1)
DB::pagingSql(-3, 25);     // LIMIT 25 OFFSET 50  (negative becomes positive)
```

To show "page 3 of 12", pair it with `DB::count()` on the same WHERE condition
and divide by the page size.

## LIKE Patterns - `DB::likeContains()` and Friends

Four helpers build escaped LIKE patterns from user input. Each returns a
`RawSql` containing the complete quoted pattern, wildcards included, so the
helper call is the whole right side of the `LIKE` comparison:

```php
$search = $_GET['search'] ?? '';
$users  = DB::select('users', "name LIKE ?", DB::likeContains($search));
// for a search of "John" this runs: SELECT * FROM `users` WHERE name LIKE '%John%'
```

| Helper                        | Pattern           | Matches                          |
|-------------------------------|-------------------|----------------------------------|
| `DB::likeContains('John')`    | `'%John%'`        | value anywhere in the column     |
| `DB::likeStartsWith('John')`  | `'John%'`         | column starts with the value     |
| `DB::likeEndsWith('son')`     | `'%son'`          | column ends with the value       |
| `DB::likeContainsTSV('news')` | `'%\tnews\t%'`    | whole value in a tab-separated column |

All four escape the input for SQL *and* escape the LIKE wildcards `%` and `_`,
so those characters in user input match literally instead of acting as
wildcards:

```php
$users = DB::select('users', "name LIKE ?", DB::likeContains('50%'));
// name LIKE '%50\%%' - matches "50% off", not every name containing "50"
```

`likeContainsTSV()` is for columns that store multiple values separated by
tabs (with leading and trailing tabs, so every value is tab-wrapped, e.g.
`"\tfeatured\tnew\t"`). It wraps the search value in tabs so it matches
complete values only, never substrings of other values:

```php
$featured = DB::select('products', "tags LIKE ?", DB::likeContainsTSV('featured'));
// tags LIKE '%\tfeatured\t%' - matches "featured" but not "unfeatured"
```

The helpers accept strings, ints, floats, `null`, and SmartString values.

## Table Prefix Conversion - `DB::getFullTable()` and `DB::getBaseTable()`

These convert between base table names (`users`) and full names with the
configured `tablePrefix` (`cms_users`). Useful when code that talks to ZenDB
(base names) meets code that talks to MySQL directly (full names). The
examples assume `tablePrefix` is `'cms_'` throughout:

```php
DB::getFullTable('users');      // 'cms_users'
DB::getFullTable('cms_users');  // 'cms_users' (already starts with the prefix, left alone)

DB::getBaseTable('cms_users');  // 'users'
DB::getBaseTable('users');      // 'users' (no prefix found, left alone)
```

Both are string operations by default: they check whether the name starts with
the prefix and add or strip it. That guess is wrong in one case: a base name
that itself starts with the prefix string, like a base table actually named
`cms_archive` (full name `cms_cms_archive`). Pass `checkDb: true` to resolve
the ambiguity by checking which table exists in the database:

```php
// Base table 'cms_archive' exists, so its full name is 'cms_cms_archive'
DB::getFullTable('cms_archive', checkDb: true);  // 'cms_cms_archive' (no table named 'cms_archive' exists as-is)
DB::getBaseTable('cms_archive', checkDb: true);  // 'cms_archive' ('cms_cms_archive' exists, so input was a base name)
```

If none of your base names start with the prefix string, the default
string-only mode is always right and skips the database query.

## Date and Time Format Constants

Three constants hold the `date()` format strings for MySQL's date and time
column types, so you don't have to remember `'Y-m-d H:i:s'`.

| Constant       | Value           | Example output        |
|----------------|-----------------|-----------------------|
| `DB::DATETIME` | `'Y-m-d H:i:s'` | `2026-07-08 14:30:00` |
| `DB::DATE`     | `'Y-m-d'`       | `2026-07-08`          |
| `DB::TIME`     | `'H:i:s'`       | `14:30:00`            |

```php
DB::update('users', ['lastLogin' => date(DB::DATETIME)], ['id' => 42]);
// UPDATE `users` SET `lastLogin` = '2026-07-08 14:30:00' WHERE `id` = 42

$expiry = date(DB::DATE, strtotime('+30 days'));
DB::insert('coupons', ['code' => 'SUMMER26', 'expiresAt' => $expiry]);
// INSERT INTO `coupons` SET `code` = 'SUMMER26', `expiresAt` = '2026-08-07'
```

For the current time, `DB::rawSql('NOW()')` uses the database clock instead of
PHP's; `date(DB::DATETIME)` uses PHP's. With `usePhpTimezone` enabled (the
default), the two agree on timezone.

## Encryption Helpers

`DB::encryptValue()`, `{{column}}` decryption in raw SQL, and automatic
MEDIUMBLOB encryption are covered in [Encryption](encryption.md).

---

[← Common Patterns](common-patterns.md) | [Documentation Index](README.md) | [Next: Multiple Connections →](multiple-connections.md)
