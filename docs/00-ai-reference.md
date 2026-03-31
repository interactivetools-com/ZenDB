# ZenDB AI Reference

This is a consolidated reference for AI coding assistants. It contains everything
needed to write correct ZenDB code in a single file. For human-friendly docs with
tutorials and explanations, see [Getting Started](01-getting-started.md).

---

## What is ZenDB

ZenDB is a PHP/MySQL database library where **SQL injection is impossible by
design**. All dynamic values go through parameterized queries. SQL templates are
scanned before execution -- quotes, standalone numbers, backslashes, NULL bytes,
and CTRL-Z are rejected outright. Every value returned from queries is a
SmartString that auto-HTML-encodes in string context, preventing XSS by default.

```php
use Itools\ZenDB\DB;

DB::connect([
    'hostname' => 'localhost',
    'username' => 'dbuser',
    'password' => 'secret',
    'database' => 'my_app',
]);

$users = DB::select('users', ['status' => 'active']);
foreach ($users as $user) {
    echo "Hello, $user->name!"; // auto HTML-encoded
}
```

---

## Querying Data

### DB::select() -- Multiple Rows

Returns `SmartArrayHtml` collection of rows.

```php
// All rows
$users = DB::select('users');

// WHERE array (simple equality, joined with AND)
$users = DB::select('users', ['status' => 'Active', 'city' => 'Vancouver']);

// SQL + positional placeholders (max 3 separate args)
$users = DB::select('users', "status = ? AND city = ?", 'Active', 'Vancouver');

// SQL + named placeholders
$users = DB::select('users', "status = :status AND city = :city", [
    ':status' => 'Active',
    ':city'   => 'Vancouver',
]);

// ORDER BY and LIMIT
$users = DB::select('users', "status = ? ORDER BY name DESC LIMIT ?", 'Active', 10);

// Pagination
$users = DB::select('users', "ORDER BY name :pagingSQL", [
    ':pagingSQL' => DB::pagingSql(2, 25),
]);
```

### DB::selectOne() -- Single Row

Returns first matching row as `SmartArrayHtml`. Auto-adds `LIMIT 1`.
Returns empty `SmartArrayHtml` if not found. **Throws if you add LIMIT or OFFSET.**

```php
$user = DB::selectOne('users', ['id' => 123]);
echo $user->name;

if ($user->isEmpty()) {
    echo "Not found";
}
```

### DB::count() -- Row Count

Returns `int`. **Throws if you add LIMIT or OFFSET.**

```php
$total  = DB::count('users');
$active = DB::count('users', ['status' => 'Active']);
```

---

## Modifying Data

### DB::insert() -- Returns Insert ID

```php
$newId = DB::insert('users', [
    'name'       => 'Alice',
    'status'     => 'Active',
    'created_at' => DB::rawSql('NOW()'),
]);
```

### DB::update() -- Returns Affected Rows

Signature: `update($table, $values, $whereEtc, ...$params)` -- **values
first, then WHERE.** WHERE is required.

```php
$affected = DB::update('users',
    ['city' => 'Toronto', 'status' => 'Active'],      // SET
    ['id' => 123]                                     // WHERE
);

// SQL WHERE
DB::update('users', ['status' => 'Inactive'], "lastLogin < ?", '2025-01-01');

// Update all rows (must be explicit)
DB::update('users', ['status' => 'archived'], "TRUE");
```

**Reversed argument detection:** If SET clause only contains `num`, `id`, or `ID`,
ZenDB assumes you reversed the arguments and throws.

### DB::delete() -- Returns Affected Rows

WHERE is required.

```php
$deleted = DB::delete('users', ['id' => 123]);
$deleted = DB::delete('users', "status = ?", 'Suspended');
```

---

## WHERE Condition Forms

### 1. Array (recommended for simple equality)

All conditions joined with AND. Special type handling:

```php
['status' => 'Active']                  // `status` = 'Active'
['age' => 25]                           // `age` = 25
['isAdmin' => true]                     // `isAdmin` = TRUE
['isAdmin' => null]                     // `isAdmin` IS NULL
['status' => ['Active', 'Inactive']]    // `status` IN ('Active','Inactive')
['created_at' => DB::rawSql('NOW()')]   // `created_at` = NOW()
```

### 2. SQL + positional `?` (max 3 separate args, array for 4+)

```php
DB::select('users', "status = ? AND age > ?", 'Active', 25);
DB::select('users', "a = ? AND b = ? AND c = ? AND d = ?", [1, 2, 3, 4]);
```

### 3. SQL + named `:placeholders`

```php
DB::select('users', "status = :status AND city = :city", [
    ':status' => 'Active',
    ':city'   => 'Vancouver',
]);
```

Named placeholders can be reused in the same query.

---

## Placeholders & Parameters

### Positional `?`

Max 3 as separate arguments. For 4+, pass as array. Arrays cannot be used with
positional `?` (ambiguous) -- use named placeholders instead.

```php
DB::select('users', "name = ? AND city = ?", 'John', 'Vancouver');

// Arrays require named placeholders
DB::select('users', "id IN (:ids)", [':ids' => [1, 2, 3]]);
```

### Named `:name`

Pattern: `/^:\w+$/`. Reusable in same query.

```php
DB::query("SELECT * FROM ::users WHERE city = :city OR birthplace = :city", [
    ':city' => 'Vancouver',
]);
```

### Backtick Identifier Placeholders

For dynamic table/column names. Validated: only `[a-zA-Z0-9_-]` allowed.

```php
DB::query("SELECT `?` FROM ::users", 'name');         // SELECT `name` FROM cms_users
DB::query("SELECT `:col` FROM ::users", [':col' => 'name']); // same
```

### Table Prefix `::`

Replaced with configured `tablePrefix` from `DB::connect()`.

```php
DB::query("SELECT * FROM ::users JOIN ::orders ON ::users.id = ::orders.user_id");
// With tablePrefix='cms_' -> SELECT * FROM cms_users JOIN cms_orders ON cms_users.id = cms_orders.user_id

// Dynamic table with prefix
DB::query("SELECT * FROM `::?`", 'users');             // SELECT * FROM `cms_users`
DB::query("SELECT * FROM `:::table`", [':table' => 'users']); // same
```

---

## Type Handling

| PHP Type | SQL Output | Example |
|----------|-----------|---------|
| `string` | Quoted and escaped | `'John'` |
| `int` | Unquoted | `42` |
| `float` | Unquoted | `9.5` |
| `null` | `NULL` | `NULL` |
| `bool` | `TRUE` / `FALSE` | `TRUE` |
| `array` | Comma-separated values | `1,2,3` |
| `RawSql` | As-is (no escaping) | `NOW()` |

**Critical:** String `"10"` becomes `'10'` (quoted). Integer `10` becomes `10`
(unquoted). This matters for LIMIT -- always use int, not string.

SmartString, SmartNull, and SmartArray values are auto-unwrapped to their
underlying types before processing.

### $values Type Handling (INSERT/UPDATE)

```php
DB::insert('users', [
    'name'       => 'John',                // string  -> quoted, escaped
    'age'        => 30,                    // int     -> unquoted
    'score'      => 9.5,                   // float   -> unquoted
    'isAdmin'    => true,                  // bool    -> TRUE
    'bio'        => null,                  // null    -> NULL
    'created_at' => DB::rawSql('NOW()'),   // RawSql  -> as-is
    'views'      => DB::rawSql('views + 1'), // SQL expression
]);
```

---

## DB::query() / DB::queryOne() -- Custom SQL

Full SQL with all safety checks still enforced. `query()` returns
`SmartArrayHtml` collection. `queryOne()` returns the first row only.

```php
$rows = DB::query(
    "SELECT u.name, COUNT(o.order_id) AS order_count
       FROM ::users u
       LEFT JOIN ::orders o ON o.user_id = u.id
      WHERE u.status = ?
      GROUP BY u.id
      ORDER BY order_count DESC",
    'Active'
);

$row = DB::queryOne("SELECT MAX(price) AS max_price FROM ::products");
echo $row->max_price;
```

### Smart Joins

When a query returns columns from multiple tables and `useSmartJoins` is `true`
(default), ZenDB adds table-prefixed keys for disambiguation.

```php
$rows = DB::query("SELECT * FROM ::users u JOIN ::orders o ON u.id = o.user_id");

foreach ($rows as $row) {
    $row->id;              // users.id (first table wins for duplicates)
    $row->{'users.id'};    // explicitly users.id
    $row->{'orders.id'};   // explicitly orders.id
}
```

Table-prefixed keys use the base table name without the configured prefix
(`users.name`, not `cms_users.name`).

Self-joins also add alias-based keys: `$row->{'a.name'}`, `$row->{'b.name'}`.

### DB::clone() -- Override Settings

```php
$db = DB::clone(['useSmartJoins' => false]);
$rows = $db->query("SELECT u.name FROM ::users u JOIN ::orders o ON u.id = o.user_id");
```

Shares the same mysqli connection with different config. Original unaffected.

---

## Results & Values

### Hierarchy

```
Query -> ResultSet (SmartArrayHtml) -> Rows (SmartArrayHtml) -> Values (SmartString)
```

### HTML-Encoding (Automatic)

SmartString auto-HTML-encodes in string context (echo, print, interpolation):

```php
echo $row->name;                    // HTML-encoded (safe for output)
echo $row->name->value();           // Raw original value and type
echo $row->name->rawHtml();         // Alias for value() (trusted HTML)
```

### Value Access & Encoding

| Expression | Result |
|------------|--------|
| `$row->name` | HTML-encoded in string context |
| `$row->name->value()` | Raw value, original PHP type |
| `$row->name->rawHtml()` | Alias for `value()` |
| `$row->name->htmlEncode()` | Explicit HTML encoding |
| `$row->name->urlEncode()` | URL-encoded |
| `$row->name->jsonEncode()` | JSON-encoded |
| `$row->name->int()` | Cast to int |
| `$row->name->float()` | Cast to float |
| `$row->name->string()` | Cast to string (unencoded) |
| `$row->get('col', 'default')` | With fallback value |

### Text Methods

| Method | Description |
|--------|-------------|
| `->textOnly()` | Strip HTML, decode entities, trim |
| `->maxChars(100, '...')` | Limit to N chars with suffix |
| `->maxWords(20, '...')` | Limit to N words with suffix |
| `->nl2br()` | Newlines to `<br>` |
| `->trim()` | Trim whitespace |

### Formatting & Conditionals

| Method | Description |
|--------|-------------|
| `->dateFormat('M j, Y')` | Format date |
| `->numberFormat(2)` | Format number |
| `->or('N/A')` | Fallback if null or empty |
| `->ifZero('None')` | Fallback if zero |
| `->ifNull('N/A')` | Fallback if null |
| `->ifBlank('Empty')` | Fallback if empty string |
| `->and(' more')` | Append if present |
| `->andPrefix('$')` | Prepend if present |
| `->apply($callback)` | Apply arbitrary function to value |

### Validation & Error Handling

| Method | Description |
|--------|-------------|
| `->isEmpty()` | True if empty ("", null, false, 0, "0") |
| `->isNotEmpty()` | True if not empty |
| `->isMissing()` | True if null or empty string |
| `->isNull()` | True if null |
| `->or404()` | Send 404 and exit if value is missing |
| `->orDie($msg)` | Die with message if missing |
| `->orThrow($msg)` | Throw RuntimeException if missing |
| `->orRedirect($url)` | Redirect if missing |

```php
echo $row->price->numberFormat(2)->andPrefix('$');      // "$1,234.56"
echo $row->bio->textOnly()->maxChars(200, '...');       // truncated preview
echo $row->nickname->or('Anonymous');                   // fallback
echo $row->created_at->dateFormat('M j, Y');            // "Sep 10, 2025"

// Validation and error handling
$user = DB::selectOne('users', ['id' => $id])->or404();     // 404 if not found
if ($row->name->isMissing()) { echo "No name"; }
```

### ResultSet Methods

| Method | Returns |
|--------|---------|
| `count($resultSet)` | int - row count |
| `$rs->first()` | First row or empty SmartArrayHtml |
| `$rs->last()` | Last row or empty SmartArrayHtml |
| `$rs->nth($index)` | Row by position (0-based, negative counts from end) |
| `$rs->toArray()` | Array of raw PHP arrays (no encoding) |
| `$rs->pluck('col')` | Flat array of one column |
| `$rs->pluckNth($index)` | Extract value at position from each row |
| `$rs->column('col', 'keyCol')` | Extract column, optionally keyed by another |
| `$rs->sortBy('col')` | Sorted ResultSet |
| `$rs->filter(fn)` | Filtered ResultSet |
| `$rs->where([...])` | Filter by values |
| `$rs->map(fn)` | Transformed array |
| `$rs->indexBy('col')` | Lookup keyed by column |
| `$rs->groupBy('col')` | Grouped by column value |
| `$rs->implode(', ')` | Join values into string |
| `$rs->sprintf($format)` | Format each element (see below) |
| `$rs->or404()` | Send 404 if empty ResultSet |
| `$rs->orThrow($msg)` | Throw RuntimeException if empty |

**`sprintf()` with `{value}` and `{key}`** - format each element in a flat array:

```php
$names = $users->pluck('name');
echo $names->sprintf('<li>{value}</li>')->implode("\n");
// <li>Alice</li>
// <li>Bob</li>

$options = $users->pluck('name', 'id');
echo $options->sprintf('<option value="{key}">{value}</option>')->implode("\n");
```

### Loop Position Helpers (on rows inside foreach)

| Method | Description |
|--------|-------------|
| `$row->isFirst()` | True if first row in ResultSet |
| `$row->isLast()` | True if last row in ResultSet |
| `$row->position()` | 1-based position in ResultSet |
| `$row->isMultipleOf($n)` | True every nth row (for grid layouts) |

### Row Methods

| Method | Returns |
|--------|---------|
| `$row->isEmpty()` | bool -- true if no data |
| `$row->toArray()` | Raw associative array |
| `$row->keys()` | Column names |
| `$row->values()` | SmartString values |

### MySQL Metadata

```php
$result->mysqli('query');          // Executed SQL
$result->mysqli('insert_id');      // Auto-increment ID from INSERT
$result->mysqli('affected_rows');  // Rows changed by UPDATE/DELETE
$result->mysqli('baseTable');      // Base table name (no prefix)
```

---

## Helpers

### Pagination

```php
DB::pagingSql($pageNum, $perPage = 10)  // Returns RawSql: LIMIT x OFFSET y
// $pageNum defaults to 1 if zero/negative/non-numeric
// $perPage defaults to 10 if zero/negative/non-numeric
```

### LIKE Patterns

All escape `%` and `_` in input. All return `RawSql`.

```php
DB::select('users', "name LIKE ?", DB::likeContains('John'));    // %John%
DB::select('users', "name LIKE ?", DB::likeStartsWith('J'));     // J%
DB::select('users', "name LIKE ?", DB::likeEndsWith('son'));     // %son
DB::select('users', "tags LIKE ?", DB::likeContainsTSV('featured')); // %\tfeatured\t%
```

### Raw SQL

```php
DB::rawSql('NOW()')                  // Inserted verbatim -- NO escaping
```

**Never pass user input to `rawSql()`.** It bypasses all escaping.

### Schema Helpers

```php
DB::hasTable('users')                     // true/false (adds tablePrefix)
DB::hasTable('cms_users', true)           // true/false (exact name, no prefix)
DB::getTableNames()                       // ['users', 'orders', ...] (prefix stripped)
DB::getTableNames(true)                   // ['cms_users', 'cms_orders', ...]
DB::getColumnDefinitions('users')         // ['id' => 'int NOT NULL AUTO_INCREMENT', ...]
DB::getFullTable('users')                 // 'cms_users'
DB::getBaseTable('cms_users')             // 'users'
```

---

## Connection

### Configuration Options

```php
DB::connect([
    'hostname'             => 'localhost',    // Required
    'username'             => 'dbuser',       // Required
    'password'             => 'secret',       // Required (use '' for none)
    'database'             => 'my_app',       // Required (use '' for none)
    'tablePrefix'          => 'cms_',         // Default: ''
    'useSmartJoins'        => true,           // Add table.column keys to JOIN results
    'useSmartStrings'      => true,           // Return SmartString values (auto HTML-encode)
    'usePhpTimezone'       => true,           // Sync MySQL timezone with PHP
    'versionRequired'      => '5.7.32',       // Minimum MySQL version
    'requireSSL'           => false,          // Require SSL connection
    'databaseAutoCreate'   => false,          // Create database if missing
    'connectTimeout'       => 3,              // Seconds
    'readTimeout'          => 60,             // Seconds
    'queryLogger'          => null,           // fn(string $query, float $secs, ?Throwable $error)
    'sqlMode'              => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
    'smartArrayLoadHandler'=> null,           // Custom result loading handler
]);
```

### Connection Management

```php
DB::isConnected()          // true if default connection exists
DB::isConnected(true)      // also pings the server to verify
DB::disconnect()           // Close the default connection
```

### Multiple Connections

```php
use Itools\ZenDB\Connection;

$analytics = new Connection([...config...]);
$rows = $analytics->select('events', "created_at > NOW() - INTERVAL ? DAY", 1);
```

Each Connection has the same methods as `DB::` (`select`, `selectOne`,
`insert`, `update`, `delete`, `count`, `query`, `queryOne`).

---

## Template Safety Rules

SQL templates are scanned before execution. The following are **rejected**:

| Pattern | Rejected |
|---------|----------|
| Quotes (`'` or `"`) | Always -- use placeholders |
| Standalone numbers | Always -- use placeholders |
| Backslashes (`\`) | Always |
| NULL bytes (`\x00`) | Always |
| CTRL-Z (`\x1a`) | Always |
| Trailing `LIMIT #` | **Allowed** -- internally rewritten to `LIMIT ?` |

Table and column names are validated against `/^[\w-]+$/` (alphanumeric,
underscore, hyphen only).

---

## Common Errors Quick Reference

| Error | Fix |
|-------|-----|
| "Quotes not allowed in template" | Use placeholder: `"name = ?", 'John'` |
| "Standalone number in template" | Use placeholder: `"age > ?", 21` |
| "Max 3 positional arguments allowed" | Pass as array: `[1, 2, 3, 4]` |
| "UPDATE/DELETE requires a WHERE condition" | Add WHERE or use `"TRUE"` for all rows |
| "Suspicious SET clause" | Check argument order: `update($table, $values, $whereEtc)` |
| "Missing value for ? parameter at position N" | Pass enough values for all `?` placeholders |
| "Missing value for ':name' parameter" | Add missing key to params array |
| "Arrays not allowed with positional ? placeholders" | Use named: `"IN (:ids)"`, `[':ids' => [1,2,3]]` |
| "This method doesn't support LIMIT or OFFSET" | Use `select()` not `selectOne()` for custom LIMIT |
| "Invalid table/column name" | Only `a-z, A-Z, 0-9, _, -` allowed |

## Gotchas

- **NULL in WHERE:** Array form `['col' => null]` correctly generates `IS NULL`.
  Placeholder form `"col = ?", null` generates `col = NULL` which is always false.
  Use the array form for null comparisons.
- **String numbers in LIMIT:** `"LIMIT ?", "10"` (string) quotes it. Use `"LIMIT ?", 10` (int).
- **selectOne() auto-adds LIMIT 1.** Don't add your own LIMIT or OFFSET.
- **count() rejects LIMIT/OFFSET** too. Use `select()` if you need them.
- **Empty arrays in IN():** `[':ids' => []]` becomes `IN (NULL)`, matching nothing.
- **Boolean values:** `true`/`false` become SQL `TRUE`/`FALSE` keywords.
- **Can't mix direct args + array:** Use all separate args OR a single array, not both.

---

## Further Reading

- [SmartArray](https://github.com/interactivetools-com/SmartArray) -- Full ResultSet/Row method reference
- [SmartString](https://github.com/interactivetools-com/SmartString) -- Full value method reference
- [Detailed docs](01-getting-started.md) -- Human-friendly tutorials and explanations

---

[← Back to README](../README.md)
