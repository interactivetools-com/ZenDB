# Common Patterns

Recipes for everyday tasks: detail pages, existence checks, single-value
queries, search with pagination, lookup maps, and turning result sets into
HTML. Each example combines features covered on earlier pages and is ready
to adapt.

## Record Detail with 404 - `or404()`

Load a single record, or send a `404 Not Found` response and exit if it
doesn't exist. `or404()` returns the row when the result has data, so it
chains directly onto a `selectOne()` call:

```php
$id   = $_GET['id'] ?? 0;
$user = DB::selectOne('users', ['id' => $id])->or404();
// SELECT * FROM `users` WHERE `id` = 123 LIMIT 1

echo "<h1>$user->name</h1>";
echo "<p>Member since {$user->createdAt->dateFormat('M j, Y')}</p>";
echo "<p>{$user->bio->textToHtml()}</p>";
```

`or404()` takes an optional message: `->or404("That user no longer exists")`.
For other outcomes on an empty result, `orDie($message)`, `orThrow($message)`,
and `orRedirect($url)` work the same way.

## Missing Row or Empty Column - `orThrow()`

`orThrow()` works on rows and on values, so one chain can report "no row
matched" and "row found, but the column is empty" as two different errors:

```php
$email = $_POST['email'] ?? '';

$memberId = DB::selectOne('users', ['email' => $email])
    ->orThrow("No user found for $email")
    ->memberId
    ->orThrow("User $email has no member ID")
    ->int();
```

The first `orThrow()` fires when the query returns no row; the second fires
when the row exists but `memberId` is null or `''` (zero counts as present).
When something is missing, the exception says which of the two it was.

## Checking a Row Exists - `DB::count()`

`DB::count()` returns an `int`, and `0` is falsy, so the count works directly
in an `if` statement:

```php
$emailInUse = DB::count('users', ['email' => 'john@example.com']);
// SELECT COUNT(*) FROM `users` WHERE `email` = 'john@example.com'

if ($emailInUse) {
    echo "Email already registered";
}
```

## One Value from a Query - `DB::queryOne()`

Aggregates return one row with one column. `queryOne()` returns that row
directly, so the value chains right off the call:

```php
$newest = DB::queryOne("SELECT MAX(createdAt) AS newest FROM ::users")->newest;
// SELECT MAX(createdAt) AS newest FROM users LIMIT 1

echo "Newest signup: {$newest->dateFormat('M j, Y')}";
```

For columns whose names are awkward to type, read by position with `nth()`.
`SHOW CREATE TABLE` returns a column literally named `Create Table`:

```php
$createSql = DB::queryOne("SHOW CREATE TABLE ::users")->nth(1)->value();
// by name instead: ->get('Create Table')->value()
```

The same trick works on whole result sets. `pluckNth()` extracts a column by
position when the column name varies:

```php
$tables = DB::query("SHOW TABLES")->pluckNth(0)->toArray();
// SHOW TABLES names its column after the database ("Tables_in_myapp"), so
// pluck by position: ['categories', 'orders', 'products', 'users']
```

## Insert, Then Load the New Row - `DB::insert()`

`DB::insert()` returns the new auto-increment ID, so inserting and reloading
the full row (with database defaults filled in) is two calls:

```php
$newId = DB::insert('users', [
    'name'      => 'Bob Smith',
    'email'     => 'bob@example.com',
    'createdAt' => DB::rawSql('NOW()'),
]);
// INSERT INTO `users` SET `name` = 'Bob Smith', `email` = 'bob@example.com', `createdAt` = NOW()

$user = DB::selectOne('users', ['id' => $newId]);
// SELECT * FROM `users` WHERE `id` = 42 LIMIT 1
```

## Search, Sort, and Paginate - `DB::likeContains()` and `DB::pagingSql()`

The standard admin listing: a LIKE search from user input, sorted, paginated,
with a total count for the page links:

```php
$search  = $_GET['q'] ?? '';
$page    = (int) ($_GET['page'] ?? 1);
$perPage = 25;

$total = DB::count('users', "name LIKE ?", DB::likeContains($search));
// SELECT COUNT(*) FROM `users` WHERE name LIKE '%john%'

$totalPages = max(1, ceil($total / $perPage));

$users = DB::select('users', "name LIKE :search ORDER BY name :paging", [
    ':search' => DB::likeContains($search),
    ':paging' => DB::pagingSql($page, $perPage),
]);
// SELECT * FROM `users` WHERE name LIKE '%john%' ORDER BY name LIMIT 25 OFFSET 0

foreach ($users as $user) {
    echo "<div>$user->name - $user->city</div>";
}

echo "$total results, page $page of $totalPages";
```

Both helpers sanitize their own inputs, so `$_GET` values can be passed
straight in. `likeContains()` escapes quotes and the LIKE wildcards `%` and
`_`, so a search for `50%` matches the literal text `50%`. `pagingSql()`
casts the page number to `int` and falls back to page 1 on anything empty or
non-numeric.

When `$search` is empty, `likeContains('')` generates `'%%'`, which matches
every row (except rows where the column is `NULL`), so the same query works
with or without a search term.

## HTML Table from Query Results

Loop the result set into rows; values HTML-encode themselves on output:

```php
$users = DB::select('users', ['status' => 'active']);
// SELECT * FROM `users` WHERE `status` = 'active'
?>
<table>
    <thead>
        <tr><th>Name</th><th>City</th><th>Joined</th></tr>
    </thead>
    <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= $user->name ?></td>
                <td><?= $user->city ?></td>
                <td><?= $user->createdAt->dateFormat('M j, Y') ?></td>
            </tr>
        <?php endforeach ?>

        <?php if ($users->isEmpty()): ?>
            <tr><td colspan="3">No records found</td></tr>
        <?php endif ?>
    </tbody>
</table>
```

## An HTML Table from Any Query - `sprintf()` and `implode()`

When the columns aren't fixed (admin tools, debug pages, ad-hoc SQL), build
the cells from the data itself. `sprintf()` formats every element of a
collection with a `{value}` placeholder, HTML-encoding each one, and
`implode()` joins the results:

```php
$rows = DB::query("SHOW TABLE STATUS");
?>
<table>
    <thead>
        <tr><?= $rows->first()->keys()->sprintf("<th>{value}</th>")->implode("\n") ?></tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <tr><?= $row->sprintf("<td>{value}</td>")->implode("\n") ?></tr>
        <?php endforeach ?>
    </tbody>
</table>
```

Header cells come from the first row's column names via `keys()`; body cells
come from each row's values. No column is named anywhere, so the same
template renders any query.

## Select Dropdown from Query Results

One `<option>` per row, with the id as the value:

```php
$categories = DB::select('categories', "ORDER BY name");
?>
<select name="categoryId">
    <option value="">-- Select --</option>
    <?php foreach ($categories as $category): ?>
        <option value="<?= $category->id ?>"><?= $category->name ?></option>
    <?php endforeach ?>
</select>
```

## Grouped Display - `groupBy()`

`groupBy()` turns a flat result set into one collection per column value.
Sorting by the group column first keeps each group's items in order:

```php
$products   = DB::select('products', "ORDER BY category, name");
$byCategory = $products->groupBy('category');
// ['Books' => [row, row, ...], 'Electronics' => [row, ...]]

foreach ($byCategory as $category => $items) {
    echo "<h2>$category</h2><ul>";
    foreach ($items as $item) {
        echo "<li>$item->name - {$item->price->numberFormat(2)->andPrefix('$')}</li>";
    }
    echo "</ul>";
}
```

## Lookup Maps - `pluck()` and `indexBy()`

Both turn a result set into a keyed lookup. Two-argument `pluck()` keeps one
column, reading as "pluck the name, keyed by id":

```php
$categoryNames = DB::select('categories')->pluck('name', 'id');
// [1 => 'Books', 2 => 'Electronics', 3 => 'Toys', ...]

foreach (DB::select('products') as $product) {
    echo "<li>$product->name - {$categoryNames->get($product->categoryId->int())}</li>";
}
```

When later code needs more than one column, `indexBy()` keeps the whole row:

```php
$categoriesById = DB::select('categories')->indexBy('id');
echo $categoriesById->get(3)->name;   // get() reads keys object syntax can't, like numbers
```

## Checking a Column for a Value - `contains()`

With rows already loaded, `pluck()` plus `contains()` answers "is this value
in that column" without another query:

```php
$admins = DB::select('users', ['isAdmin' => 1]);

if ($admins->pluck('email')->contains($email)) {
    echo "That email belongs to an admin";
}
```

Metadata queries work too:

```php
$hasEmailIndex = DB::query("SHOW INDEX FROM ::users")->pluck('Column_name')->contains('email');
```

For a one-off check straight against the database, `DB::count()` above is
the better tool. `contains()` earns its keep when the collection is already
in hand, or when several values get tested against the same one.

## Default Missing Numbers to Zero - `or()`

`numberFormat()` returns blank when the value is null or not numeric, so a
missing price prints nothing. When a zero should show instead, default the
value first with `or(0)`:

```php
echo $product->price->numberFormat(2);          // null price prints nothing
echo $product->price->or(0)->numberFormat(2);   // null price prints "0.00"
```

Order matters. Before `numberFormat()`, `or()` supplies a number to format;
after it, `or()` supplies display text for values that couldn't be formatted:

```php
echo $product->price->numberFormat(2)->or('n/a');   // null price prints "n/a"
```

The reverse direction also works: `ifZero('')` after formatting blanks out
zeros, for report cells where a grid of "0.00" is noise:

```php
echo $row->total->numberFormat(2)->ifZero('');   // "1,234.56", or nothing for 0
```

## Calculations in Templates - `divide()`, `subtract()`, and `percentOf()`

The math methods keep the value chainable, so a calculation formats and
falls back like any other value. Nulls, non-numeric values, and division by
zero all produce null, which falls through to the `or()` or `ifNull()` at
the end of the chain:

```php
// Per-day average
echo $stats->inquiries->divide($stats->days)->numberFormat(1)->or('-');
// 250 inquiries / 30 days → "8.3", zero days → "-"

// Conversion rate
echo $stats->leads->percentOf($stats->visitors, 1)->or('-');
// 45 of 1,234 → "3.6%"

// Change vs last year, with a plus sign on gains
echo $thisYear->sales
    ->subtract($lastYear->sales)
    ->percentOf($lastYear->sales)
    ->ifNull('-')
    ->apply(fn(string $v) => str_starts_with($v, '-') ? $v : "+$v");
// 120 vs 100 → "+20%",  100 vs 120 → "-17%",  no last year → "-"
```

The `apply()` on the end runs any callable on the raw value; the result
still HTML-encodes on output.

For rates stored as fractions, `percent()` multiplies by 100, and its second
argument is a zero fallback:

```php
echo $plan->completionRate->percent(1);        // 0.254 → "25.4%"
echo $plan->completionRate->percent(0, '-');   // 0 → "-"
```

## Address Lines That Skip Empty Fields - `and()`

`and()` appends its argument only when the value is present, so separators
after optional fields disappear with the field, no `if` statements needed:

```php
echo $user->city->and(', ') . $user->region->and(' ') . $user->postalCode;
// "Vancouver, BC V6B 1A1" - or "Vancouver, V6B 1A1" when region is empty
```

`andPrefix()` is the same idea in front: `$user->balance->andPrefix('$')`
prints `$0` for a zero balance and nothing for null (zero counts as present,
null and `''` do not).

The appended text becomes part of the value, so it HTML-encodes on output
with everything else. Keep separators plain text; a `<br>` inside `and()`
prints as literal text, not a line break.

## Values in URLs and JavaScript - `urlEncode()` and `jsonEncode()`

The default HTML encoding is wrong for URLs and JavaScript; each context has
its own method:

```php
// URL parameter
echo "<a href='/users?name={$user->name->urlEncode()}'>$user->name</a>";

// JavaScript variable (jsonEncode() adds the quotes)
echo "<script>let userName = {$user->name->jsonEncode()};</script>";
```

## Click-to-Call Phone Links - `pregReplace()`

A `tel:` link needs bare digits; the displayed number keeps its formatting.
`pregReplace()` runs a regex on the raw value and returns a new value that
HTML-encodes on output like any other:

```php
<a href="tel:<?= $user->phone->pregReplace('/\D/', '') ?>"><?= $user->phone ?></a>
<!-- <a href="tel:6045551234">(604) 555-1234</a> -->
```

## Displaying Trusted HTML - `rawHtml()`

Values HTML-encode themselves on output, which turns stored HTML into visible
tags. For content that is *supposed* to contain HTML, like a WYSIWYG editor
field, `rawHtml()` outputs the value unencoded:

```php
echo $article->title;             // HTML-encoded (the default)
echo $article->body->rawHtml();   // raw, tags render as HTML
```

`rawHtml()` skips encoding entirely, so only use it on content you trust:
HTML written by your own site's editors, not anything submitted by visitors.

---

[← Joins and Custom SQL](joins-and-custom-sql.md) | [Documentation Index](README.md) | [Next: Helpers and Utilities →](helpers-and-utilities.md)
