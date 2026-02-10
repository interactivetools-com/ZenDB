# Troubleshooting and Gotchas

This page covers the most common exception messages, platform-specific
connection issues, subtle behavioral gotchas, and debugging techniques.

## Common Exception Messages

### "Quotes not allowed in template"

**What happened:** Your SQL template contains quote characters (single or
double). ZenDB rejects these to prevent SQL injection.

```php
// Throws -- quotes in template
DB::select('users', "name = 'John'");
```

**Fix:** Use a placeholder and pass the value as a parameter:

```php
DB::select('users', "name = ?", 'John');
```

### "Standalone number in template"

**What happened:** Your SQL template contains a literal number. ZenDB rejects
these because they could be user input that was concatenated into the query.

```php
// Throws -- literal number in template
DB::select('users', "age > 21");
```

**Fix:** Use a placeholder:

```php
DB::select('users', "age > ?", 21);
```

**Exception:** A trailing `LIMIT #` at the end of a template is allowed. ZenDB
internally rewrites it to use a placeholder.

### "Max 3 positional arguments allowed"

**What happened:** You passed more than 3 `?` parameter values as separate
arguments.

```php
// Throws -- too many positional arguments
DB::select('users', "a = ? AND b = ? AND c = ? AND d = ?", 1, 2, 3, 4);
```

**Fix:** Pass the values as an array instead:

```php
DB::select('users', "a = ? AND b = ? AND c = ? AND d = ?", [1, 2, 3, 4]);
```

### "UPDATE/DELETE requires a WHERE condition"

**What happened:** You called `update()` or `delete()` without providing a WHERE
condition. ZenDB blocks this to prevent accidental bulk operations that would
affect every row in the table.

```php
// Throws -- empty WHERE condition
DB::update('users', ['status' => 'deleted'], []);
```

**Fix:** Add a WHERE condition. If you intentionally want to affect all rows,
use a condition that always evaluates to true:

```php
DB::update('users', ['status' => 'deleted'], ['num' => $userId]);

// If you truly need all rows
DB::update('users', ['status' => 'deleted'], "TRUE");
```

### "Suspicious SET clause"

**What happened:** Your UPDATE only sets a column named `num`, `id`, or `ID`.
ZenDB assumes this means you reversed the `$colsToValues` and `$whereEtc`
arguments.

```php
// Throws -- likely reversed arguments
DB::update('users', ['num' => 5], ['status' => 'active']);
```

**Fix:** Check the argument order. The signature is
`update($baseTable, $colsToValues, $whereEtc)` — the columns to set come
first, then the WHERE condition:

```php
DB::update('users', ['status' => 'active'], ['num' => 5]);
```

### "Missing value for ? parameter at position N"

**What happened:** Your template has a `?` placeholder but there is no
corresponding parameter value at that position.

```php
// Throws -- 2 placeholders but only 1 value
DB::select('users', "name = ? AND city = ?", 'Alice');
```

**Fix:** Ensure you pass enough parameter values to match all placeholders:

```php
DB::select('users', "name = ? AND city = ?", 'Alice', 'Vancouver');
```

### "Missing value for ':name' parameter"

**What happened:** Your template has a named placeholder like `:name` but the
params array does not include that key.

```php
// Throws -- :city not in params
DB::select('users', "name = :name AND city = :city", [':name' => 'Alice']);
```

**Fix:** Add the missing key to your params array:

```php
DB::select('users', "name = :name AND city = :city", [
    ':name' => 'Alice',
    ':city' => 'Vancouver',
]);
```

### "Arrays not allowed with positional ? placeholders"

**What happened:** You tried to pass an array value to a positional `?`
placeholder, which is ambiguous (should it be expanded to multiple values or
treated as a single value?).

```php
// Throws -- array with positional placeholder
DB::select('users', "num IN (?)", [1, 2, 3]);
```

**Fix:** Use a named placeholder instead:

```php
DB::select('users', "num IN (:ids)", [':ids' => [1, 2, 3]]);
```

### "This method doesn't support LIMIT or OFFSET, use select() instead"

**What happened:** You used LIMIT or OFFSET with `get()` or `count()`. The
`get()` method automatically adds `LIMIT 1`, and `count()` returns a scalar —
neither supports custom LIMIT or OFFSET.

```php
// Throws -- get() auto-adds LIMIT 1
DB::get('users', "status = ? LIMIT 5", 'active');
```

**Fix:** Use `select()` if you need custom LIMIT or OFFSET:

```php
DB::select('users', "status = ? LIMIT 5", 'active');
```

## Connection Errors

### WSL Socket Error

**Symptom:** Connection fails with "No such file or directory" when using
`localhost` in Windows Subsystem for Linux (WSL).

**Why:** `localhost` on WSL tries to use a Unix socket, which does not exist
when MySQL is running on the Windows host.

**Fix:** Use the IP address `127.0.0.1` instead:

```php
DB::connect([
    'hostname' => '127.0.0.1',  // not 'localhost'
    // ...
]);
```

### SSL Errors

**Symptom:** Connection fails with an SSL-related error.

**Fix:** Try disabling SSL in your configuration:

```php
DB::connect([
    // ...
    'requireSSL' => false,
]);
```

### Version Mismatch

**Symptom:** Exception stating the server does not meet the minimum version
requirement.

**Why:** ZenDB requires MySQL 5.7.32 or newer by default.

**Fix:** Upgrade your MySQL server, or lower the requirement if you understand
the implications:

```php
DB::connect([
    // ...
    'versionRequired' => '5.7.0',  // lower the minimum
]);
```

## Gotchas

### 1. NULL in WHERE Array vs Placeholder

The WHERE array form and placeholder form handle `null` differently:

```php
// WHERE array: correct -- generates "WHERE status IS NULL"
DB::select('users', ['status' => null]);

// Positional placeholder: generates "WHERE status = NULL" -- always false in SQL!
DB::select('users', "status = ?", null);
```

SQL requires `IS NULL` syntax for NULL comparisons. The WHERE array form handles
this automatically. If you use placeholders, you need to write `IS NULL`
yourself or use the array form.

### 2. Boolean Values

Boolean values are converted to the SQL keywords `TRUE` and `FALSE`:

```php
DB::insert('users', ['isAdmin' => true]);   // SET `isAdmin` = TRUE
DB::insert('users', ['isAdmin' => false]);  // SET `isAdmin` = FALSE
```

### 3. Empty Arrays in IN()

An empty array passed to an IN clause becomes `IN (NULL)`, which matches
nothing. This is not an error — it is the safest behavior for an empty set:

```php
$ids = [];  // empty
DB::select('users', "num IN (:ids)", [':ids' => $ids]);
// Generates: WHERE num IN (NULL) -- returns no rows
```

### 4. String Numbers in LIMIT

String numbers are quoted as strings, which can cause unexpected behavior in
LIMIT clauses. Always use integer types for LIMIT values:

```php
// Potential issue: "10" gets quoted as a string
$limit = "10";

// Correct: cast to integer
$limit = (int) $limit;
DB::select('users', "status = ? LIMIT $limit", 'active');
```

### 5. get() Rejects LIMIT and OFFSET

The `get()` method automatically appends `LIMIT 1` to your query. If you add
your own LIMIT or OFFSET, it throws an exception. Use `select()` instead if you
need custom LIMIT or OFFSET clauses.

### 6. Numeric WHERE Is Deprecated

Passing an integer directly as the WHERE argument still works but logs a
deprecation warning:

```php
// Deprecated -- logs a warning
$user = DB::get('users', 123);

// Preferred -- explicit and clear
$user = DB::get('users', ['num' => 123]);
```

### 7. Reserved Parameter Prefix

The `:zdb_` prefix is reserved for internal use. Named placeholders starting
with `:zdb_` will throw an exception:

```php
// Throws -- reserved prefix
DB::select('users', "name = :zdb_name", [':zdb_name' => 'Alice']);

// Use any other prefix
DB::select('users', "name = :name", [':name' => 'Alice']);
```

## Debugging Tips

### 1. print_r() on Results

The quickest way to inspect what ZenDB returned is `print_r()`. It shows the
simulated SQL query, row count, available methods, and the data itself:

```php
$users = DB::select('users', ['status' => 'active']);
print_r($users);
// Shows: query SQL, row count, available methods, column values
```

### 2. queryLogger Callback

Log every SQL query, its duration, and any errors by providing a `queryLogger`
callback when connecting:

```php
DB::connect([
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'my_app',
    'queryLogger' => function(string $query, float $duration, ?Throwable $error) {
        error_log("SQL ({$duration}s): $query");
        if ($error) {
            error_log("SQL Error: " . $error->getMessage());
        }
    },
]);
```

This is useful for finding slow queries, debugging unexpected results, and
monitoring query volume in development.

### 3. mysqli Metadata

Inspect the actual SQL that was executed and other MySQL metadata from any
result:

```php
$users = DB::select('users', ['status' => 'active']);

// See the full SQL query that was sent to MySQL
echo $users->mysqli('query');

// Check affected rows after an UPDATE
$result = DB::query("UPDATE ::users SET `status` = ? WHERE `city` = ?", 'active', 'Vancouver');
echo $result->mysqli('affected_rows') . " rows updated";
```

---

[← Back to README](../README.md) | [← Helpers & Utilities](07-helpers-and-utilities.md)
