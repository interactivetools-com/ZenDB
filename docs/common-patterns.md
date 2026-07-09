# Common Patterns

Recipes for everyday tasks: detail pages, existence checks, insert-then-fetch,
search with pagination, and turning result sets into HTML. Each example
combines features covered on earlier pages and is ready to adapt.

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

## Values in URLs and JavaScript - `urlEncode()` and `jsonEncode()`

The default HTML encoding is wrong for URLs and JavaScript; each context has
its own method:

```php
// URL parameter
echo "<a href='/users?name={$user->name->urlEncode()}'>$user->name</a>";

// JavaScript variable (jsonEncode() adds the quotes)
echo "<script>let userName = {$user->name->jsonEncode()};</script>";
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
