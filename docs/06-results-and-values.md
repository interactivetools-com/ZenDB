# Results and Values

Every query in ZenDB returns structured objects that provide HTML-safe output by
default, chainable methods for transformation, and raw access when you need it.
This page covers the full result hierarchy and the methods available at each
level.

## The Result Hierarchy

```
Query → ResultSet (SmartArrayHtml) → Rows (SmartArrayHtml) → Values (SmartString)
```

```php
$rows = DB::select('users');        // ResultSet -- collection of rows
foreach ($rows as $row) {           // Row -- single record
    echo $row->name;                // SmartString -- auto HTML-encoded value
}
```

## HTML-Encoding by Default

SmartString objects automatically HTML-encode when used in string context. This
means `echo`, `print`, and string interpolation are always XSS-safe without any
extra effort:

```php
echo "Hello $row->name!";  // XSS-safe -- HTML-encoded automatically
```

### Encoding Methods

| Expression | Result |
|---|---|
| `$row->name` | HTML-encoded (default in string context) |
| `$row->name->value()` | Original raw value and type |
| `$row->name->rawHtml()` | Alias for `value()`, for trusted HTML content |
| `$row->name->htmlEncode()` | Explicit HTML encoding |
| `$row->name->urlEncode()` | URL-encoded |
| `$row->name->jsonEncode()` | JSON-encoded |

## Accessing Values

```php
// Object notation -- returns SmartString, auto-encodes in string context
echo $row->name;

// Array notation -- alternative access
echo $row['name'];

// With fallback -- returns default if column is missing or empty
echo $row->get('nickname', 'Anonymous');
```

## Getting Raw Data

When you need plain PHP arrays instead of SmartString-wrapped values, use
`toArray()`:

```php
$row       = DB::get('users', ['num' => 1]);
$resultSet = DB::select('users');

// Single row -- returns associative array of raw values
$data = $row->toArray();
// ['name' => "O'Reilly & Sons", 'city' => 'Vancouver', ...]

// ResultSet -- returns array of raw row arrays
$allData = $resultSet->toArray();
foreach ($allData as $rawRow) {
    // $rawRow is a plain PHP array, not encoded
}
```

## Method Chaining

SmartString methods can be chained to build transformation pipelines:

```php
// Strip HTML tags, then limit to 100 characters with ellipsis
echo $row->description->textOnly()->maxChars(100, '...');

// Format a price with prefix, or show "Free" if zero
echo $row->price->numberFormat(2)->andPrefix('$')->ifZero('Free');

// Date formatting with fallback
echo $row->published_at->dateFormat('M j, Y')->or('Not published');
```

## print_r() Inline Documentation

ZenDB objects are designed to be self-documenting when inspected with
`print_r()`:

```php
// Shows: simulated SQL, row count, available methods, HTML-encoded values
print_r($rows);

// Shows: column names and their values
print_r($row);

// Shows: SmartString value and available methods
print_r($row->name);
```

This makes `print_r()` a useful debugging tool — it tells you what data you
have and what you can do with it.

## MySQL Metadata

Access MySQL metadata from query results using the `mysqli()` method:

| Method | Description |
|---|---|
| `$result->mysqli()` | Returns all MySQL metadata as array |
| `$result->mysqli('insert_id')` | Auto-increment ID from last INSERT |
| `$result->mysqli('affected_rows')` | Rows affected by INSERT/UPDATE/DELETE |
| `$result->mysqli('query')` | The executed SQL query |
| `$result->mysqli('baseTable')` | The base table name (without prefix) |

```php
$result = DB::query("INSERT INTO ::users SET `name` = ?", 'Alice');
$newId = $result->mysqli('insert_id');

$result = DB::query("UPDATE ::users SET `status` = ? WHERE `city` = ?", 'active', 'Vancouver');
$changed = $result->mysqli('affected_rows');
```

## ResultSet Methods (Multiple Rows)

A ResultSet is the `SmartArrayHtml` returned by `DB::select()` and
`DB::query()`. It represents a collection of rows.

| Method | Description |
|---|---|
| `count($resultSet)` | Number of rows |
| `$resultSet->count()` | Alternative count |
| `$resultSet->first()` | First row (or empty SmartArrayHtml) |
| `$resultSet->toArray()` | Raw rows array (not encoded) |
| `$resultSet->pluck('col')` | Extract single column as flat array |
| `$resultSet->sortBy('col')` | Sort by column |
| `$resultSet->filter(fn)` | Filter with callback |
| `$resultSet->where([...])` | Filter by values |
| `$resultSet->map(fn)` | Transform each row |
| `$resultSet->indexBy('id')` | Convert to lookup keyed by column |
| `$resultSet->groupBy('col')` | Group rows by column value |

```php
$users = DB::select('users', ['status' => 'active']);

// Count results
echo count($users) . " active users";

// Get specific columns
$names = $users->pluck('name');

// Filter in PHP
$admins = $users->filter(fn($row) => $row->isAdmin->value());

// Build a lookup table
$byId = $users->indexBy('num');
echo $byId[42]->name;

// Group by a column
$byCity = $users->groupBy('city');
foreach ($byCity as $city => $cityUsers) {
    echo "$city: " . count($cityUsers) . " users\n";
}
```

## Row Methods

A Row is the `SmartArrayHtml` returned for each record in a ResultSet, or by
`DB::get()` for a single record.

| Method | Description |
|---|---|
| `$row->columnName` | Access as SmartString (auto HTML-encoded) |
| `$row['columnName']` | Alternative array access |
| `$row->get('col', 'default')` | Column with fallback value |
| `$row->values()` | Indexed array of SmartStrings |
| `$row->keys()` | Array of column names |
| `$row->toArray()` | Raw values array (not encoded) |
| `$row->isEmpty()` | Check if row is empty |
| `$row->contains('value')` | Check if row contains value |

```php
$user = DB::get('users', ['num' => 1]);

// Access values
echo $user->name;                           // SmartString, HTML-encoded
echo $user->get('nickname', 'Anonymous');    // with fallback

// Check if record was found
if ($user->isEmpty()) {
    echo "User not found";
}

// Get raw data
$data = $user->toArray();                   // plain PHP array
```

## SmartString Methods

Each column value in a row is a SmartString object. SmartString provides methods
for encoding, formatting, and conditional output.

### Value Access and Encoding

| Method | Description |
|---|---|
| `$column` | HTML-encoded in string context |
| `$column->value()` | Original raw value and type |
| `$column->rawHtml()` | Alias for `value()`, for trusted HTML |
| `$column->htmlEncode()` | Explicit HTML encoding |
| `$column->urlEncode()` | URL-encoded |
| `$column->jsonEncode()` | JSON-encoded |
| `$column->int()` | Convert to integer |
| `$column->float()` | Convert to float |
| `$column->string()` | Convert to string (unencoded) |

```php
$user = DB::get('users', ['num' => 1]);

// HTML context -- auto-encoded
echo "<p>$user->name</p>";

// URL context
echo "<a href='/users?name={$user->name->urlEncode()}'>Profile</a>";

// JSON context
echo "<script>var name = {$user->name->jsonEncode()};</script>";

// Raw value for logic
if ($user->isAdmin->value()) {
    echo "Admin user";
}
```

### Text Manipulation

| Method | Description |
|---|---|
| `$column->textOnly()` | Remove HTML, decode entities, trim |
| `$column->maxChars(100)` | Limit to N chars with ellipsis |
| `$column->maxWords(20)` | Limit to N words with ellipsis |
| `$column->nl2br()` | Newlines to `<br>` |
| `$column->trim()` | Trim whitespace |

```php
// Strip HTML and truncate for a preview
echo $row->body->textOnly()->maxChars(200, '...');

// Preserve newlines in plain text
echo $row->bio->nl2br();
```

### Formatting and Conditionals

| Method | Description |
|---|---|
| `$column->dateFormat()` | Format date — "Sep 10, 2024" |
| `$column->numberFormat(2)` | Format number — "123.45" |
| `$column->or('N/A')` | Alternate if null or "" |
| `$column->ifZero('None')` | Alternate if zero |
| `$column->ifNull('N/A')` | Alternate if null |
| `$column->ifBlank('Empty')` | Alternate if empty string |
| `$column->and(' more')` | Append if present |
| `$column->andPrefix('$')` | Prepend if present |

```php
// Date formatting
echo $row->created_at->dateFormat('M j, Y');   // "Sep 10, 2024"

// Number formatting with prefix
echo $row->price->numberFormat(2)->andPrefix('$');  // "$1,234.56"

// Fallback values
echo $row->nickname->or('Anonymous');

// Conditional suffix
echo $row->comment_count->and(' comments');    // "5 comments" or "" if zero
```

## Further Reading

- [SmartArray documentation](https://github.com/interactivetools-com/SmartArray) for the full method reference on result sets and rows
- [SmartString documentation](https://github.com/interactivetools-com/SmartString) for the full method reference on value objects

---

[← Back to README](../README.md) | [← Joins & Raw SQL](05-joins-and-raw-sql.md) | [Next: Helpers & Utilities →](07-helpers-and-utilities.md)
