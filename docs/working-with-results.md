# Working with Results

Every query returns result objects: collections you can loop like arrays, and
values that HTML-encode themselves on output. This page covers the result
hierarchy, output encoding, and the methods available at each level.

## The Result Hierarchy

```
Query → Result (SmartArrayHtml) → Rows (SmartArrayHtml) → Values (SmartString)
```

```php
$users = DB::select('users');   // result - collection of rows
foreach ($users as $user) {     // row - one record
    echo $user->name;           // value - HTML-encodes itself
}
```

## Output Encoding

Values HTML-encode themselves in string context, so `echo`, `print`, and
string interpolation are XSS-safe with no extra effort. For other contexts,
ask for the encoding you need:

| Expression                  | Result                                             |
|-----------------------------|----------------------------------------------------|
| `$user->name`               | HTML-encoded (string context)                      |
| `$user->name->value()`      | Original raw value and type                        |
| `$user->name->rawHtml()`    | Unencoded, for HTML you trust (alias of `value()`) |
| `$user->name->urlEncode()`  | URL-encoded                                        |
| `$user->name->jsonEncode()` | JSON-encoded                                       |

```php
// HTML context - encodes automatically
echo "<p>$user->name</p>";

// URL parameter
echo "<a href='/profile?name={$user->name->urlEncode()}'>Profile</a>";

// JavaScript
echo "<script>let name = {$user->name->jsonEncode()};</script>";

// Logic - compare the raw value, not the encoded string
if ($user->isAdmin->value()) {
    echo "Admin";
}
```

`rawHtml()` is the one output path that skips encoding. Call it only on HTML
you control and trust; [Security Gotchas](security-gotchas.md) covers why it's
the single name for unencoded output.

## Getting Values from a Row

Access columns with object notation. For a column that may be empty, chain
`or()` for a fallback:

```php
echo $user->name;
echo $user->nickname->or('Anonymous');   // fallback when null or ''
```

## Getting Raw Data

`value()` returns one field's original value and type; `toArray()` converts a
row or a whole result to plain PHP arrays:

```php
$user = DB::selectOne('users', ['id' => 1]);
$name = $user->name->value();   // "O'Brien & Sons" - exactly as stored
$data = $user->toArray();       // ['id' => 1, 'name' => "O'Brien & Sons", ...]

$rows = DB::select('users')->toArray();   // array of plain row arrays
```

## Chaining Value Methods

Value methods return a new SmartString, so transformations chain:

```php
// Strip HTML tags, then shorten to 100 characters with an ellipsis
echo $article->body->textOnly()->maxChars(100);

// Format a number and prepend a currency symbol (a blank price stays blank, no stray $)
echo $product->price->numberFormat(2)->andPrefix('$');   // $1,234.56

// Format a date; null, invalid, and 0000-00-00 dates all fall through to the or()
echo $user->lastLogin->dateFormat('M j, Y')->or('Never');
```

## Debugging with print_r()

The result objects describe themselves when inspected. `print_r()` on a
result shows the executed SQL, row count, and values; on a single value it
shows the raw data and available methods:

```php
print_r($users);        // the query, rows, and values
print_r($user);         // one row's columns and values
print_r($user->name);   // one value plus the methods you can call on it
```

CMS Builder users: `showme()` does the same thing, wrapped in `<xmp>` tags for
readable browser output.

## Query Metadata - `mysqli()`

Results carry their MySQL metadata, most useful with `DB::query()`.

| Method                             | Returns                              |
|------------------------------------|--------------------------------------|
| `$result->mysqli('insert_id')`     | Auto-increment ID from an INSERT     |
| `$result->mysqli('affected_rows')` | Rows changed by INSERT/UPDATE/DELETE |
| `$result->mysqli('query')`         | The executed SQL                     |
| `$result->mysqli()`                | All metadata as an array             |

```php
$result = DB::query("INSERT INTO ::users SET name = ?", 'Alice');
$newId  = $result->mysqli('insert_id');
```

## Result Methods (Collections)

The most used methods on the collection returned by `DB::select()` and
`DB::query()`. This isn't the full list; see
[SmartArray](https://github.com/interactivetools-com/SmartArray) for
everything.

| Method                        | Description                                   |
|-------------------------------|-----------------------------------------------|
| `count($result)`              | Number of rows (`$result->count()` works too) |
| `$result->first()`            | First row (or an empty row if none)           |
| `$result->toArray()`          | Plain array of raw row arrays                 |
| `$result->pluck('col')`       | One column as a new collection                |
| `$result->sortBy('col')`      | Sort rows by column                           |
| `$result->filter(fn)`         | Keep rows where the callback returns true     |
| `$result->where('col', $val)` | Keep rows where the column matches a value    |
| `$result->map(fn)`            | Transform each row                            |
| `$result->indexBy('col')`     | Lookup array keyed by column                  |
| `$result->groupBy('col')`     | Groups of rows keyed by column value          |

```php
$users = DB::select('users', ['status' => 'active']);

echo count($users) . " active users";

// One column
$names = $users->pluck('name');   // collection: ['Alice', 'Bob', 'Charlie', ...]

// Lookup by primary key - get() reads keys PHP object syntax can't, like numbers
$byId = $users->indexBy('id');
echo $byId->get(42)->name;

// Group rows by a column value
$byCity = $users->groupBy('city');
foreach ($byCity as $city => $cityUsers) {
    echo "<h2>$city (" . count($cityUsers) . ")</h2>";
}
```

## Row Methods

Each row in a collection, and the return value of `DB::selectOne()`.

| Method                    | Description                                                              |
|---------------------------|--------------------------------------------------------------------------|
| `$row->columnName`        | Column value as SmartString                                              |
| `$row->get('users.name')` | Column whose key PHP syntax can't type: Smart Join keys, numeric indexes |
| `$row->keys()`            | Column names                                                             |
| `$row->values()`          | Column values                                                            |
| `$row->toArray()`         | Raw associative array                                                    |
| `$row->isEmpty()`         | True when no row was found                                               |

## Value Methods (SmartString)

Each column value is a SmartString. The most used methods:

**Text**

| Method            | Description                             |
|-------------------|-----------------------------------------|
| `->textOnly()`    | Remove HTML tags, decode entities, trim |
| `->maxChars(100)` | Shorten to N characters with ellipsis   |
| `->maxWords(20)`  | Shorten to N words with ellipsis        |
| `->nl2br()`       | Newlines to `<br>`                      |
| `->trim()`        | Trim whitespace                         |

**Formatting**

| Method                   | Description                   |
|--------------------------|-------------------------------|
| `->dateFormat('M j, Y')` | Format a date: "Sep 10, 2026" |
| `->numberFormat(2)`      | Format a number: "1,234.56"   |
| `->int()`, `->float()`   | Convert to a plain PHP type   |

**Conditional fallbacks**

| Method               | Applies when                                    |
|----------------------|-------------------------------------------------|
| `->or('N/A')`        | Value is null or `''` (zero stays)              |
| `->ifBlank('Empty')` | Value is `''`                                   |
| `->ifNull('N/A')`    | Value is null                                   |
| `->ifZero('Free')`   | Value is numeric zero                           |
| `->and(' items')`    | Appends when value is present (including zero)  |
| `->andPrefix('$')`   | Prepends when value is present (including zero) |

## Full References

These objects come from ZenDB's companion libraries, and the complete method
lists live in their own docs:

- [SmartArray](https://github.com/interactivetools-com/SmartArray) - results and rows
- [SmartString](https://github.com/interactivetools-com/SmartString) - values

---

[← Querying Data](querying-data.md) | [Documentation Index](README.md) | [Next: Modifying Data →](modifying-data.md)
