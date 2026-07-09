# ZenDB AI Reference

This is a consolidated reference for AI coding assistants. It contains everything
needed to write correct ZenDB code in a single file, and covers ZenDB 1.0. For
human-friendly docs with tutorials and explanations, see [Getting Started](getting-started.md).

Contents:

- What is ZenDB
- Querying Data - select(), selectOne(), count()
- Modifying Data - insert(), update(), delete(), transaction()
- WHERE Condition Forms - array, positional ?, named :placeholders
- Placeholders & Parameters - ?, :name, backtick identifiers, :: table prefix
- Type Handling - PHP-to-SQL conversion for WHERE and INSERT/UPDATE values
- Custom SQL - query(), queryOne(), smart joins, clone()
- Results & Values - SmartArray/SmartString hierarchy, HTML-encoding, methods
- Helpers - pagination, LIKE patterns, raw SQL, date constants, table names
- Connection - config options, connection management, multiple connections
- Encryption (opt-in)
- Template Safety Rules - what SQL templates reject and allow
- Common Errors Quick Reference - exact messages and fixes
- Gotchas - the silent failure cases

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

**Reversed argument detection:** If SET clause is a single column named
`num`, `id`, or `ID`, ZenDB assumes you reversed the arguments and throws.

### DB::delete() -- Returns Affected Rows

WHERE is required.

```php
$deleted = DB::delete('users', ['id' => 123]);
$deleted = DB::delete('users', "status = ?", 'Suspended');
```

### DB::transaction() -- Atomic Operations

Commits when the callback returns, rolls back and rethrows on exception.
Returns the callback's return value. Nested transactions throw.

```php
$orderId = DB::transaction(function() use ($userId, $skus) {
    $orderId = DB::insert('orders', ['userId' => $userId]);
    foreach ($skus as $sku) {
        DB::insert('order_items', ['orderId' => $orderId, 'sku' => $sku]);
    }
    return $orderId;
});
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

### 2. SQL + positional `?` (max 3 values, passed as direct args)

```php
DB::select('users', "status = ? AND age > ?", 'Active', 25);
// 4+ values: use named placeholders (next section)
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

Max 3, passed as separate arguments. For 4 or more values, use named
placeholders. Never pass positional values as a single array (deprecated),
and arrays cannot be used as `?` values (ambiguous) -- use named placeholders.

```php
DB::select('users', "name = ? AND city = ?", 'John', 'Vancouver');

// Arrays require named placeholders
DB::select('users', "id IN (:ids)", [':ids' => [1, 2, 3]]);
```

### Named `:name`

Names start with a letter, then letters/digits/underscores (`:[a-zA-Z]\w*`).
Reusable in same query.

```php
DB::query("SELECT * FROM ::users WHERE city = :city OR birthplace = :city", [
    ':city' => 'Vancouver',
]);
```

### Backtick Identifier Placeholders

For dynamic table/column names. Validated: only `[a-zA-Z0-9_-]` allowed.

```php
// with tablePrefix 'cms_' this runs: SELECT `name` FROM cms_users
DB::query("SELECT `?` FROM ::users", 'name');
DB::query("SELECT `:col` FROM ::users", [':col' => 'name']); // same
```

### Table Prefix `::`

Replaced with configured `tablePrefix` from `DB::connect()`.

```php
DB::query("SELECT * FROM ::users JOIN ::orders ON ::users.id = ::orders.user_id");
// with tablePrefix 'cms_' this runs:
// SELECT * FROM cms_users JOIN cms_orders ON cms_users.id = cms_orders.user_id

// Dynamic table with prefix
// with tablePrefix 'cms_' this runs: SELECT * FROM `cms_users`
DB::query("SELECT * FROM `::?`", 'users');
DB::query("SELECT * FROM `:::table`", [':table' => 'users']); // same
```

---

## Type Handling

| PHP Type | SQL Output             | Example  |
|----------|------------------------|----------|
| `string` | Quoted and escaped     | `'John'` |
| `int`    | Unquoted               | `42`     |
| `float`  | Unquoted               | `9.5`    |
| `null`   | `NULL`                 | `NULL`   |
| `bool`   | `TRUE` / `FALSE`       | `TRUE`   |
| `array`  | Comma-separated values | `1,2,3`  |
| `RawSql` | As-is (no escaping)    | `NOW()`  |

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
Auto-adds `LIMIT 1` (for SELECT/WITH). **Throws if you add LIMIT or OFFSET.**

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
(default), ZenDB adds qualified `table.column` keys alongside the plain keys.
Qualified keys contain a dot, so read them with `get()`.

```php
$rows = DB::query("SELECT * FROM ::users u JOIN ::orders o ON u.id = o.user_id");

foreach ($rows as $row) {
    $row->id;                  // plain key: duplicate names keep the FIRST column's value
    $row->get('users.id');     // always the users table's id
    $row->get('orders.id');    // always the orders table's id
}
```

Qualified keys use the base table name: no prefix (`users.name`, never
`cms_users.name`) and never the alias (`FROM ::users u` still produces
`users.name`, not `u.name`). Computed columns (`AS alias`) get only their
alias, no qualified key. Self-joins are the one exception: when the same
table appears twice, alias keys are added too (`$row->get('a.name')`,
`$row->get('b.name')`).

### DB::clone() -- Override Settings

```php
$db = DB::clone(['useSmartJoins' => false]);
$rows = $db->query("SELECT u.name FROM ::users u JOIN ::orders o ON u.id = o.user_id");
```

Shares the same mysqli connection with different config. Original unaffected.
Only `tablePrefix`, `useSmartJoins`, and `useSmartStrings` can be overridden;
any other key throws.

---

## Results & Values

### Hierarchy

```
Query -> Result set (SmartArrayHtml) -> Rows (SmartArrayHtml) -> Values (SmartString)
```

### HTML-Encoding (Automatic)

SmartString auto-HTML-encodes in string context (echo, print, interpolation):

```php
echo $row->name;                    // HTML-encoded (safe for output)
echo $row->name->value();           // Raw original value and type
echo $row->name->rawHtml();         // Alias for value() (trusted HTML)
```

### Value Access & Encoding

| Expression                    | Result                                                               |
|-------------------------------|----------------------------------------------------------------------|
| `$row->name`                  | HTML-encoded in string context                                       |
| `$row->name->value()`         | Raw value, original PHP type                                         |
| `$row->name->rawHtml()`       | Alias for `value()`                                                  |
| `$row->name->htmlEncode()`    | Explicit HTML encoding                                               |
| `$row->name->urlEncode()`     | URL-encoded                                                          |
| `$row->name->jsonEncode()`    | JSON-encoded                                                         |
| `$row->name->int()`           | Cast to int                                                          |
| `$row->name->float()`         | Cast to float                                                        |
| `$row->name->string()`        | Cast to string (unencoded)                                           |
| `$row->get('col', 'default')` | Fallback applies only when the key is missing, never to stored NULLs |

### Text Methods

| Method                   | Description                                  |
|--------------------------|----------------------------------------------|
| `->textOnly()`           | Strip HTML, decode entities, trim            |
| `->maxChars(100, '...')` | Limit to N chars with suffix                 |
| `->maxWords(20, '...')`  | Limit to N words with suffix                 |
| `->textToHtml()`         | Encode + newlines to `<br>` (returns string) |
| `->trim()`               | Trim whitespace                              |

### Formatting & Conditionals

| Method                   | Description                                   |
|--------------------------|-----------------------------------------------|
| `->dateFormat('M j, Y')` | Format date                                   |
| `->numberFormat(2)`      | Format number                                 |
| `->or('N/A')`            | Fallback if null or empty string (zero stays) |
| `->ifZero('None')`       | Fallback if zero                              |
| `->ifNull('N/A')`        | Fallback if null                              |
| `->ifBlank('Empty')`     | Fallback if empty string                      |
| `->and(' more')`         | Append if present                             |
| `->andPrefix('$')`       | Prepend if present                            |
| `->apply($callback)`     | Apply arbitrary function to value             |

### Validation & Error Handling

| Method               | Description                             |
|----------------------|-----------------------------------------|
| `->isEmpty()`        | True if empty ("", null, false, 0, "0") |
| `->isNotEmpty()`     | True if not empty                       |
| `->isMissing()`      | True if null or empty string            |
| `->isNull()`         | True if null                            |
| `->or404()`          | Send 404 and exit if value is missing   |
| `->orDie($msg)`      | Die with message if missing             |
| `->orThrow($msg)`    | Throw RuntimeException if missing       |
| `->orRedirect($url)` | Redirect if missing                     |

```php
echo $row->price->numberFormat(2)->andPrefix('$');      // "$1,234.56"
echo $row->bio->textOnly()->maxChars(200, '...');       // truncated preview
echo $row->nickname->or('Anonymous');                   // fallback
echo $row->created_at->dateFormat('M j, Y');            // "Sep 10, 2025"

// Validation and error handling
$user = DB::selectOne('users', ['id' => $id])->or404();     // 404 if not found
if ($row->name->isMissing()) { echo "No name"; }
```

### Result Set Methods

| Method                         | Returns                                                                                        |
|--------------------------------|------------------------------------------------------------------------------------------------|
| `count($resultSet)`            | int - row count                                                                                |
| `$rs->first()`                 | First row, or `SmartNull` if the set is empty (chaining works, but it is not a SmartArrayHtml) |
| `$rs->last()`                  | Last row, or `SmartNull` if the set is empty                                                   |
| `$rs->nth($index)`             | Row by position (0-based, negative counts from end)                                            |
| `$rs->toArray()`               | Array of raw PHP arrays (no encoding)                                                          |
| `$rs->pluck('col')`            | Flat collection of one column                                                                  |
| `$rs->pluckNth($index)`        | Extract value at position from each row                                                        |
| `$rs->column('col', 'keyCol')` | Extract column, optionally keyed by another                                                    |
| `$rs->sortBy('col')`           | Sorted result set                                                                              |
| `$rs->filter(fn)`              | Filtered result set                                                                            |
| `$rs->where('col', $val)`      | Rows where column matches (chain for multiple)                                                 |
| `$rs->map(fn)`                 | Transformed collection                                                                         |
| `$rs->indexBy('col')`          | Lookup keyed by column                                                                         |
| `$rs->groupBy('col')`          | Grouped by column value                                                                        |
| `$rs->implode(', ')`           | Join values into string                                                                        |
| `$rs->sprintf($format)`        | Format each element of a flat collection; `{value}`/`{key}` placeholders, HTML-encodes both    |
| `$rs->or404()`                 | Send 404 if empty result set                                                                   |
| `$rs->orThrow($msg)`           | Throw RuntimeException if empty                                                                |

```php
// sprintf() works on flat collections only - pluck a column first (on a row set it throws)
echo $users->pluck('name')->sprintf('<li>{value}</li>')->implode("\n");
```

### Loop Position Helpers (on rows inside foreach)

| Method             | Description                     |
|--------------------|---------------------------------|
| `$row->isFirst()`  | True if first row in result set |
| `$row->isLast()`   | True if last row in result set  |
| `$row->position()` | 1-based position in result set  |

### Row Methods

| Method            | Returns                 |
|-------------------|-------------------------|
| `$row->isEmpty()` | bool -- true if no data |
| `$row->toArray()` | Raw associative array   |
| `$row->keys()`    | Column names            |
| `$row->values()`  | SmartString values      |

### MySQL Metadata

```php
$result->mysqli('query');          // Executed SQL
$result->mysqli('insert_id');      // Auto-increment ID from INSERT
$result->mysqli('affected_rows');  // Rows changed by INSERT/UPDATE/DELETE
$result->mysqli('baseTable');      // Base table name (no prefix)
```

---

## Helpers

### Pagination

```php
DB::pagingSql($pageNum, $perPage = 10)  // Returns RawSql: LIMIT x OFFSET y
// $pageNum defaults to 1 if zero/non-numeric; negative becomes positive (abs)
// $perPage defaults to 10 if zero/non-numeric; negative becomes positive (abs)
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

### Date/Time Constants

```php
DB::DATETIME  // 'Y-m-d H:i:s' - format for MySQL DATETIME columns
DB::DATE      // 'Y-m-d'       - format for MySQL DATE columns
DB::TIME      // 'H:i:s'       - format for MySQL TIME columns
```

### Table Name Helpers

```php
// with tablePrefix 'cms_':
DB::getFullTable('users')                 // 'cms_users'
DB::getBaseTable('cms_users')             // 'users'
```

Schema introspection (does a table exist, list tables, column definitions) is
in the internal `Table` class - rarely needed in application code; see
src/Table.php if you do. The manual escaping methods (`DB::escape()`,
`DB::escapef()`, `DB::escapeCSV()`) are internal too: use placeholders instead.

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
    'versionRequired'      => '5.7.32',       // Minimum MySQL version or compatible
    'requireSSL'           => false,          // Require SSL connection
    'databaseAutoCreate'   => false,          // Create database if missing
    'connectTimeout'       => 3,              // Seconds
    'readTimeout'          => 60,             // Seconds
    'encryptionKey'        => '',             // Encrypt/decrypt MEDIUMBLOB columns (see Encryption)
    'sqlMode'              => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
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

$analytics = new Connection([
    'hostname' => 'localhost',
    'username' => 'dbuser',
    'password' => 'secret',
    'database' => 'analytics',
]);
$rows = $analytics->select('events', "created_at > NOW() - INTERVAL ? DAY", 1);
```

Each Connection has the same methods as `DB::` (`select`, `selectOne`,
`insert`, `update`, `delete`, `count`, `query`, `queryOne`, `transaction`).

---

## Encryption (Opt-In)

With `encryptionKey` set in `DB::connect()`, every `MEDIUMBLOB` column is
AES-128-ECB encrypted on `insert()`/`update()` and decrypted on read; no
query changes needed. `MEDIUMBLOB` is then reserved for encrypted data --
store plain binary (images, files) in `BLOB` or `LONGBLOB`, which are left
alone. `NULL` passes through unencrypted.

```php
// Exact match: encrypt the search value in PHP (encryption is deterministic)
$user = DB::selectOne('users', ['apiToken' => DB::encryptValue($token)]);

// LIKE / ranges / functions need plaintext: {{column}} decrypts in MySQL
$users = DB::select('users', "{{apiToken}} LIKE ?", '%abc%');
// WHERE AES_DECRYPT(`apiToken`, @ek) LIKE '%abc%'

// Raw DB::$mysqli results skip auto-decryption; decrypt in place:
DB::decryptRows($rows, $result->fetch_fields());
```

`DB::encryptValue()` produces the same ciphertext as `insert()`/`update()`,
so it also writes encrypted values through raw SQL. `{{table.column}}` works
in joins: write the column reference as you would unencrypted, wrapped in
braces. `::` applies `tablePrefix` inside `{{}}` (`{{::users.apiToken}}`
matches `FROM ::users`); alias qualifiers stay as written (`{{u.apiToken}}`).

---

## Template Safety Rules

SQL templates are scanned before execution. The following are **rejected**.

| Pattern                                         | Rejected                   |
|-------------------------------------------------|----------------------------|
| Quotes (`'` or `"`)                             | Always -- use placeholders |
| Standalone numbers                              | Always -- use placeholders |
| Hex/binary/scientific (`0x1F`, `0b101`, `1e10`) | Always -- count as numbers |
| Backslashes (`\`)                               | Always                     |
| NULL bytes (`\x00`)                             | Always                     |
| CTRL-Z (`\x1a`)                                 | Always                     |

The following are **allowed** in templates.

| Pattern            | Notes                                                       |
|--------------------|-------------------------------------------------------------|
| `''` and `""`      | Empty string literals (no injection payload)                |
| Trailing `LIMIT #` | Literal number kept in query, skipped by the template guard |

Table and column names are validated against `/^[\w-]+$/` (alphanumeric,
underscore, hyphen only).

---

## Common Errors Quick Reference

| Error                                                                                   | Fix                                                        |
|-----------------------------------------------------------------------------------------|------------------------------------------------------------|
| "Quotes not allowed in template"                                                        | Use placeholder: `"name = ?", 'John'`                      |
| "Standalone number in template"                                                         | Use placeholder: `"age > ?", 21`                           |
| "Max 3 positional arguments allowed"                                                    | Use named placeholders: `[':a' => 1, ':b' => 2, ...]`      |
| "UPDATE requires a WHERE condition to prevent accidental bulk UPDATE" (same for DELETE) | Add WHERE or use `"TRUE"` for all rows                     |
| "Suspicious SET clause"                                                                 | Check argument order: `update($table, $values, $whereEtc)` |
| "Missing value for ? parameter at position N"                                           | Pass enough values for all `?` placeholders                |
| "Missing value for ':name' parameter"                                                   | Add missing key to params array                            |
| "Arrays not allowed with positional ? placeholders"                                     | Use named: `"IN (:ids)"`, `[':ids' => [1,2,3]]`            |
| "This method doesn't support LIMIT or OFFSET"                                           | Use `select()` not `selectOne()` for custom LIMIT          |
| "Invalid table/column name"                                                             | Only `a-z, A-Z, 0-9, _, -` allowed                         |

## Gotchas

- **NULL in WHERE:** Array form `['col' => null]` correctly generates `IS NULL`.
  Placeholder form `"col = ?", null` generates `col = NULL` which is always false.
  Use the array form for null comparisons.
- **String numbers in LIMIT:** `"LIMIT ?", "10"` (string) quotes it. Use `"LIMIT ?", 10` (int).
- **selectOne() auto-adds LIMIT 1.** Don't add your own LIMIT or OFFSET.
- **count() rejects LIMIT/OFFSET** too. Use `select()` if you need them.
- **Empty arrays in IN():** `[':ids' => []]` becomes `IN (NULL)`, matching nothing.
  Expansion also skips `null` elements and removes duplicates: `[1, null, 1, 2]` → `IN (1,2)`.
- **Boolean values:** `true`/`false` become SQL `TRUE`/`FALSE` keywords.
- **Param forms:** up to 3 direct values for `?` placeholders, or one array of `:name` params. Never pass positional values as an array, e.g. `("a = ? AND b = ?", [1, 2])` -- that form is deprecated and will throw in a future version.

---

## Further Reading

- [SmartArray](https://github.com/interactivetools-com/SmartArray) -- Full result-set/row method reference
- [SmartString](https://github.com/interactivetools-com/SmartString) -- Full value method reference
- [Detailed docs](README.md) -- Human-friendly tutorials and explanations

---

[← Method Reference](method-reference.md) | [Documentation Index](README.md)
