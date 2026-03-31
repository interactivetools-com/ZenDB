# Common Patterns

Practical recipes combining ZenDB features for everyday tasks. Each example
is self-contained and ready to adapt.

## Record Detail with 404

Load a single record or send a 404 response if it does not exist:

```php
$user = DB::selectOne('users', ['id' => $id])->or404();

echo "<h1>$user->name</h1>";
echo "<p>Member since: {$user->created_at->dateFormat('M j, Y')}</p>";
echo "<p>{$user->bio->nl2br()}</p>";
```

`or404()` sends a `404 Not Found` header and exits if the result is empty.
See also `orThrow()` and `orRedirect()` for alternative error handling.

## Formatted Values

SmartString methods chain for display formatting:

```php
echo $row->price->numberFormat(2)->andPrefix('$');          // "$1,234.56"
echo $row->created_at->dateFormat('M j, Y');                // "Jan 15, 2026"
echo $row->nickname->or('Anonymous');                       // fallback if empty
echo $row->bio->textOnly()->maxChars(200, '...');           // plain text preview
echo $row->comment_count->and(' comments')->or('None yet'); // "5 comments" or "None yet"
```

## Select Dropdown from Query Results

Build `<option>` elements from a query using `pluck()` and `sprintf()`:

```php
$categories = DB::select('categories', "ORDER BY name");
$options    = $categories->pluck('name', 'id');
?>
<select name="category_id">
    <option value="">-- Select --</option>
    <?= $options->sprintf('<option value="{key}">{value}</option>')->implode("\n") ?>
</select>
```

Values are automatically HTML-encoded. See the
[SmartArray docs](https://github.com/interactivetools-com/SmartArray) for more
on `sprintf()` with `{key}` and `{value}` placeholders.

## HTML Table from Query Results

Display a result set as an HTML table:

```php
$users = DB::select('users', ['status' => 'Active']);
?>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>City</th>
            <th>Joined</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= $user->name ?></td>
                <td><?= $user->city ?></td>
                <td><?= $user->created_at->dateFormat('M j, Y') ?></td>
            </tr>
        <?php endforeach ?>

        <?php if ($users->isEmpty()): ?>
            <tr><td colspan="3">No records found</td></tr>
        <?php endif ?>
    </tbody>
</table>
```

## Search + Sort + Paginate

A common admin listing pattern combining LIKE search with pagination:

```php
$search  = $_GET['q'] ?? '';
$page    = (int) ($_GET['page'] ?? 1);
$perPage = 25;

// Query with search and pagination
$users = DB::select('users', "name LIKE :search ORDER BY name :paging", [
    ':search' => DB::likeContains($search),
    ':paging' => DB::pagingSql($page, $perPage),
]);

// Total count for pagination links
$total = DB::count('users', "name LIKE ?", DB::likeContains($search));

// Display results
foreach ($users as $user) {
    echo "<div>$user->name - $user->city</div>";
}

echo "$total total results, page $page of " . ceil($total / $perPage);
```

When `$search` is empty, `likeContains('')` generates `%%` which matches all
rows, so the same query works with or without a search term.

## Grouped Display

Group results by a column for organized output:

```php
$products = DB::select('products', "ORDER BY category, name");
$byCategory = $products->groupBy('category');

foreach ($byCategory as $category => $items) {
    echo "<h2>$category</h2>";
    echo "<ul>";
    foreach ($items as $item) {
        echo "<li>$item->name - {$item->price->numberFormat(2)->andPrefix('$')}</li>";
    }
    echo "</ul>";
}
```

## Building URLs with Encoded Values

Use `urlEncode()` for URL parameters and `jsonEncode()` for JavaScript:

```php
// URL parameter
echo "<a href='/users?name={$user->name->urlEncode()}'>$user->name</a>";

// JavaScript variable
echo "<script>var userName = {$user->name->jsonEncode()};</script>";
```

## Displaying Trusted HTML

By default, all values are HTML-encoded for safety. For content from a WYSIWYG
editor or other trusted HTML source, use `rawHtml()` to output without encoding:

```php
// Regular field -- auto HTML-encoded (safe)
echo $article->title;

// Rich text field -- raw output for trusted HTML
echo $article->body->rawHtml();
```

Only use `rawHtml()` for content you trust. It bypasses HTML encoding entirely.

---

[← Back to README](../README.md) | [← Joins & Custom SQL](06-joins-and-custom-sql.md) | [Next: Safety by Design →](08-safety-by-design.md)
