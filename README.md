# ZenDB: PHP/MySQL Database Library

<!-- TOC -->
* [ZenDB: PHP/MySQL Database Library](#zendb-phpmysql-database-library)
    * [Overview](#overview)
        * [SmartArray and SmartString Integration](#smartarray-and-smartstring-integration)
    * [Features](#features)
        * [Injection Proof SQL](#injection-proof-sql)
        * [Automatic HTML-Encoding with SmartString](#automatic-html-encoding-with-smartstring)
        * [Flexible Syntax](#flexible-syntax)
        * [Database Operations](#database-operations)
        * [Named and Positional Placeholders](#named-and-positional-placeholders)
        * [Smart Joins](#smart-joins)
        * [Inline Documentation](#inline-documentation)
    * [Methods](#methods)
        * [Overview](#overview-1)
        * [Select and Get](#select-and-get)
        * [Insert, Update, Delete](#insert-update-delete)
        * [Query](#query)
    * [Method Arguments](#method-arguments)
        * [$baseTable](#basetable)
        * [$conditions](#conditions)
        * [...$mixedParams](#mixedparams)
        * [$colsToValues](#colstovalues)
    * [Other Methods & Properties](#other-methods--properties)
        * [Config & Connection](#config--connection)
        * [SmartArray Objects](#smartarray-objects)
            * [MySQL Metadata Access](#mysql-metadata-access)
            * [ResultSet Methods (multiple rows)](#resultset-methods-multiple-rows)
            * [Row Methods (single row)](#row-methods-single-row)
        * [SmartString Objects](#smartstring-objects)
            * [Value Access & Encoding](#value-access--encoding)
            * [Text Manipulation](#text-manipulation)
            * [Formatting & Conditional Operations](#formatting--conditional-operations)
        * [Inline Documentation](#inline-documentation-1)
        * [Utility Methods](#utility-methods)
        * [SQL Pattern Helpers](#sql-pattern-helpers)
        * [Table/Schema Helpers](#tableschema-helpers)
    * [Catching Errors](#catching-errors)
    * [Related Libraries](#related-libraries)
    * [Questions?](#questions)
<!-- TOC -->

## Overview

ZenDB is a PHP/MySQL database abstraction layer designed to make your
development process faster, easier, and more enjoyable. It focuses on ease of
use, beautiful code, and optimizing for common use cases while also allowing for
advanced and complex queries when needed.

### SmartArray and SmartString Integration

ZenDB returns query results as a hierarchy of SmartArray and SmartString objects:

- **ResultSets**: SmartArray objects containing rows
- **Rows**: SmartArray objects containing columns
- **Values**: SmartString objects for individual fields

This powerful combination provides several benefits:

- **Automatic XSS Protection**: Values are HTML-encoded by default when used in string contexts
- **Flexible Access**: Use object notation (`$row->name`) or array notation (`$row['name']`)
- **Method Chaining**: Perform multiple operations in a fluid, readable way
- **Helpful Debugging**: Use `print_r()` on any object to see documentation and property values

ZenDB leverages these libraries to enhance data security and developer productivity:

- [SmartArray](https://github.com/interactivetools-com/SmartArray) - Enhanced Arrays with chainable methods
- [SmartString](https://github.com/interactivetools-com/SmartString) - Secure string handling with auto HTML-encoding

## Features

### Injection Proof SQL

<div style="margin-left: 30px;">

ZenDB completely eliminates the risk of MySQL injection vulnerabilities. Here's how a typical injection attack works.

```php
// Example of an INSECURE mysql query that passes user input directly to the database
$mysqli->query("SELECT * FROM users WHERE user = '{$_POST['user']}' AND pass = '{$_POST['pass']}'");

// Expected input of username 'John' and password '1234' produces this query:
$mysqli->query("SELECT * FROM users WHERE user = 'John' AND pass = '1234'");

// But if a malicious user enters this as their password: 1234' OR '1
$mysqli->query("SELECT * FROM users WHERE user = 'John' AND pass = '1234' OR '1'");
// The resulting query will allow logging in without a password, because '1' is always true
// Attackers can use these exploits to gain complete control of your server, steal data, and more.
```

Most database libraries have tools to prevent SQL injection; but they're not
mandatory, meaning a single mistaken direct data input can put your entire
site at risk.

Other libraries require you to incrementally build your queries bit by bit,
wrapping each piece in a complicated series of arrays and method calls so they can
scrub each bit of data. This turns even simple queries into long, complex
strings of code and requires you to learn a whole new language on top of MySQL.

ZenDB takes a more straightforward approach, we completely eliminate the risk
of MySQL injections altogether by simply preventing direct string or number
inputs. This means you couldn't introduce an injection vulnerability even if
you wanted to, and the replacement code is actually cleaner and easier to read.
Here's an example:

```php
// Attempting to write the same INSECURE MySQL Query with ZenDB (returns error and won't run)
DB::query("SELECT * FROM users WHERE user = '{$_POST['user']}' AND pass = '{$_POST['pass']}'");

// Returns error: Disallowed single quote (') in sql clause. Use whereArray or placeholders instead.
// By disallowing quotes and standalone numbers, directly passing inputs or injections is not even possible.

// Examples of SECURE ZenDB queries that use placeholders
DB::query("SELECT * FROM users WHERE user = ? AND pass = ?", $_POST['user'], $_POST['pass']);

// Code is actually cleaner and easier to read and also secure.  
// And there are even simpler ways to write secure queries with ZenDB, read on.
```

</div>

### Automatic HTML-Encoding with SmartString

<div style="margin-left: 30px;">

The most common use case for web apps is to HTML-encode output to prevent XSS vulnerabilities.
ZenDB automatically handles this through SmartString objects, which automatically HTML-encode
their values when used in string contexts. This provides security by default, while still
allowing access to raw values when needed.

```php
// Insecure old way, outputting user values without encoding.  This is vulnerable to XSS attacks.
print "Hello, {$row['name']}!"; // requires curly braces { } to insert the variable into the string

// Secure old way, outputting user values with HTML-encoding.  This is safe, but cumbersome.
print "Hello, " . htmlspecialchars($row['name']) . "!"; 

// ZenDB values are SmartString objects that automatically HTML-encode when used in string context.
print "Hello $row->name!"; // No extra characters required to insert variable into string

// What happens if you forget and try to access it as an array index? 
print "Hello, {$row['name']}!";
// Returns helpful error: Invalid access: Use $row->name instead of $row['name']

// Don't want HTML-encoding? You can access the original value or different encodings instead
$row->name;               // O&apos;Reilly &amp; Sons    // Default usage, HTML-encoded output
$row->name->htmlEncode(); // O&apos;Reilly &amp; Sons    // As above, available for self-documenting code
$row->name->urlEncode();  // O%27Reilly+%26+Sons         // URL-encoded      
$row->name->jsonEncode(); // "O\u0027Reilly \u0026 Sons" // JSON-encoded         
$row->name->value();      // O'Reilly & Sons             // Returns original type and value
$row->name->rawHtml();    // O'Reilly & Sons             // Alias for value(), useful for trusted HTML

// SmartString supports method chaining for more complex operations
echo $row->description->textOnly()->maxChars(100, '...'); // Strip HTML tags, limit to 100 chars

// Forget the options? Use print_r for inline documentation with all methods and values
print_r($row->name);  

// You can also disable encoding on the resultSet or row to get an array of original values
$resultSet = DB::select('users')->toArray(); 

// Alternative way to do the same thing
foreach ($resultSet->toArray() as $row) { ... }    
```

</div>

### Flexible Syntax

<div style="margin-left: 30px;">

We mimic MySQL terminology while removing unnecessary complexity, making it easy to learn and use.
If you're familiar with MySQL, you'll find this fast and intuitive; otherwise, you'll effortlessly learn
MySQL just by using it.

```php
// MySQL
SELECT * FROM `users` WHERE num = 1

// ZenDB
DB::select('users', 1);
```

</div>

<div style="margin-left: 30px;">

The interface enables multiple calling methods for simple or complex queries,
ensuring your code is clean and readable. For more complex queries you can
even write direct MySQL and use :: to insert table prefixes.

```php
// Lookup row by id
$row = DB::get('users', 1);

// Lookup rows with array of WHERE conditions
$rows = DB::select('users', ['active' => 1, 'city' => 'Vancouver']);

// Lookup rows with custom SQL
$rows = DB::select('news', "WHERE publishDate <= NOW() ORDER BY publishDate DESC");

// Write a custom SQL query, using :: placeholder to insert table prefix
$rows = DB::query("SELECT *, p.price AS unit_price, (od.quantity * p.price) AS total_price
                          FROM ::users         AS u
                          JOIN ::orders        AS o  ON u.num         = o.user_id
                          JOIN ::order_details AS od ON o.order_id    = od.order_id
                          JOIN ::products      AS p  ON od.product_id = p.product_id");

```

</div>

### Database Operations

<div style="margin-left: 30px;">

We provide a unified, intuitive interface for all the standard database operations, while also giving
you the flexibility to execute custom SQL queries for more complex use cases.

```php
// Select one or more rows
$rows         = DB::select($table);            // return multiple rows
$row          = DB::get($table, $conditions);  // return first result

// Insert, update or delete rows
$newId        = DB::insert($table, $colsToValues);
$affectedRows = DB::update($table, $colsToValues, $conditions);
$affectedRows = DB::delete($table, $conditions);

// Count rows
$count        = DB::count($table, $conditions);

// Custom SQL queries 
$sqlQuery     = "SELECT * FROM news WHERE publishDate <= NOW() ORDER BY publishDate DESC";
$rows         = DB::query($sqlQuery);

// These are all simple examples, you can also pass placeholder parameters as additional arguments
```

</div>

### Named and Positional Placeholders

<div style="margin-left: 30px;">

We support both named `:named` and positional `?` placeholders for secure database input.
Inputs are sent to the server independently of the query, using bound parameters, which
eliminates any risk of malicious code injection and guarantees data integrity.

```php
// You can specify up to 3 positional parameters as method arguments 
DB::select('news', "status = ?", 'admin'); // the leading "WHERE" is optional
DB::select('news', "lastLogin BETWEEN ? AND ?", '2023-10-01', '2023-10-31');
DB::select('news', "lastLogin BETWEEN ? AND ? AND status = ?", '2023-10-01', '2023-10-31', 'admin');

// Or use named parameters for better readability in complex queries.
DB::select('news', "lastLogin BETWEEN :start AND :end AND status = :status AND hidden = :hidden", [
    ':start'  => '2023-10-01',
    ':end'    => '2023-10-31',
    ':status' => 'admin',
    ':hidden' => 0,
]);
```

</div>

### Smart Joins

<div style="margin-left: 30px;">

When rapidly prototyping join queries with `SELECT *`, we automatically
add table-prefixed keys, such as `tablename.columnName` to uniquely identify
each value. For result sets with duplicate column names (like `name`) the first
returned value is preserved to prevent overwrites.

This streamlined approach frees you to focus on your initial query logic,
with the option to optimize performance later.

```php
$rows = DB::query("SELECT *, p.price AS unit_price, (od.quantity * p.price) AS total_price
                          FROM ::users         AS u
                          JOIN ::orders        AS o  ON u.num         = o.user_id
                          JOIN ::order_details AS od ON o.order_id    = od.order_id
                          JOIN ::products      AS p  ON od.product_id = p.product_id
                         WHERE u.num = ?", $userNum);

//
foreach ($rows as $row) { 
  // Rows contain regular columns, plus additional table-prefixed keys in this format `tablename.columnName`
  [ 
    'num' => 13,             // retains user.num value, not overwritten by orders.num
    'name' => 'John Smith',  // retains users.name value, not overwritten by products.name
    'order_id' => 8,
    'user_id' => 13,
    'order_date' => '2024-01-22',
    'total_amount' => '70.50',
    'order_detail_id' => 8,
    'product_id' => 3,
    'quantity' => 1,
    'price' => '25.75',
    'unit_price' => '25.75',
    'total_price' => '25.75',
    // These additional keys are added when the result set contains columns from multiple tables
    'users.num' => 13,
    'users.name' => 'Kevin Lewis',
    'orders.order_id' => 8,
    'orders.user_id' => 13,
    'orders.order_date' => '2024-01-22',
    'orders.total_amount' => '70.50',
    'order_details.order_detail_id' => 8,
    'order_details.order_id' => 8,
    'order_details.product_id' => 3,
    'order_details.quantity' => 1,
    'products.product_id' => 3,
    'products.name' => 'Product C',
    'products.price' => '25.75',
  ];

}
```

</div>

### Inline Documentation

<div style="margin-left: 30px;">

Not sure if you can remember all of that? Don't worry, you don't have to. When you use standard
programmer debugging functions such as `print_r` we'll automatically show you some inline documentation
for programmers including the effective SQL query that was executed and useful stats and info.

```php
// SmartArray example
$rows = DB::select('locations', "city = ?", "O'Brien");

// Example output from print_r($rows)
SmartArray Object(
 [__DEVELOPERS__] => 
        This SmartArray contains rows as SmartArrays, with each field being a SmartString object.
        The SQL values below are simulated; actual queries use parameter binding for security.
        
        Simulated SQL: SELECT * FROM `locations` WHERE city = "O\'Brien"

        Below are the accessible properties and methods:
        
        count($rows)               // Total number of rows
        $rows->count()             // Alternative way to get number of rows
        $rows->mysqli('insert_id') // Primary key of last inserted row for INSERT queries
        $rows->mysqli('affected_rows') // Number of rows affected by INSERT, UPDATE, DELETE
        $rows->mysqli('error')     // Error message, if any, from the last SQL operation
        $rows->mysqli('errno')     // Error number from the last SQL operation
        $rows->first()             // Returns the first row as SmartArray, or empty if no rows
        $rows->toArray()           // Array of original unencoded row data
                        
        foreach ($rows as $row)         // Iterate over rows in the SmartArray
            $row->column                // Access columns by name - values are auto HTML-encoded when output 
            $row->column->value()       // Access original value (not HTML-encoded)
        }                               

        HTML-encoded values for all rows in this SmartArray are listed below.

    [0] => Array
        (
            [num] => 1
            [address] => 123 Main St
            [city] => O&apos;Brien
            [state] => Oregon
        )
    // array results continue ... 
```

You can use print_r on the resultSet, row, or value objects to get inline documentation
and useful information for programmers.
</div>

## Methods

### Overview

<div style="margin-left: 30px;">

The following database methods are available.  [Method Arguments](#method-arguments) are listed below.

```php
// Select: Returns a SmartArray with matching rows 
$rows = DB::select($baseTable, $conditions, ...$mixedParams);

// Get: Returns first matching SmartArray, or empty SmartArray if no results 
$row = DB::get($baseTable, $conditions, ...$mixedParams); 

// Count: Returns count of matching rows
$count = DB::count($baseTable, $conditions, ...$mixedParams);

// Insert: Inserts a new row and returns the new id
$newId = DB::insert($baseTable, $colsToValues);

// Update: Updates one or more rows and returns the number of rows affected
$affectedRows = DB::update($baseTable, $colsToValues, $conditions, ...$mixedParams);

// Delete: Deletes one or more rows and returns the number of rows affected
$affectedRows = DB::delete($baseTable, $conditions, ...$mixedParams);

// Query: Executes a SQL query and returns a SmartArray
$rows = DB::query($sqlQuery, ...$mixedParams);
```

</div>

### Select and Get

<div style="margin-left: 30px;">

Use DB::get() to load a single row and DB::select() to load multiple rows.

```php
// load first result by row number
$user = DB::get('users', 123);

// load first result with whereArray (allows for simple "column = value" queries)
$user = DB::get('users', ['id' => 123]);

// load all users
$users = DB::select('users');
foreach ($users as $user) { 
  print "$user->name\n"; // outputs html-encoded name

  // other options: 
  print $user->name->value();       // outputs original value (not html-encoded)
  print $user->name->htmlEncode();  // outputs htmlEncoded name (if different encoding)
  print $user->name->urlEncode();   // outputs urlEncoded name (if different encoding)
  print $user->name->jsonEncode();  // outputs JSON-encoded name (if different encoding)
}

// load matching users with sql and positional placeholders (allows for more complex queries)
$users = DB::select('users', "division = ? AND city = ?", 2, "Vancouver");

// load matching users with sql and named placeholders (allows for even more complex queries)
$where = "category = :cat AND city = :city AND age >= :min AND age <= :max AND job_status = :status"; 
$users = DB::select('users', $where, [
    ':cat'    => 2,
    ':city'   => "Vancouver",
    ':min'    => 21,
    ':max'    => 50,
    ':status' => 'Full-Time'
]);

// Sort by city and get the first 10 results
$users = DB::select('users', "dept = ? ORDER BY city LIMIT ?", "support", 10);

// get 2nd page of results for orders in the last 7 days that aren't processed
$pageNum = 2;
$perPage = 25;
$orders = DB::select('orders',
                     "WHERE status = :status AND created_at > NOW() - INTERVAL :days DAY
                      ORDER BY created_at DESC :pagingSQL", [
                          ":days"     => 7,
                          ":status"   => "pending",
                          ":pagingSQL" => DB::pagingSql($pageNum, $perPage), // creates OFFSET and LIMIT clauses
                     ]);
```

</div>

### Insert, Update, Delete

<div style="margin-left: 30px;">

```php
// insert row
$newId = DB::insert('users', [
    'username' => 'bob',
    'email'    => 'bob@example.com',
    'city'     => 'Vancouver'
]);

// update row
$colsToValues = [
    'username'    => 'bob2',
    'email'       => 'bob2@example.com',
    'lastUpdated' => DB::rawSql('NOW()'),
];
$affectedRows = DB::update('users', $colsToValues, ['id' => 123]);

// delete row
$deletedRows = DB::delete('users', "id = ?", 123);
```

</div>

### Query

<div style="margin-left: 30px;">

DB::query() lets you write a direct or more complex query.

```php
// direct SQL query - :: is replaced with table prefix
$query = <<<__SQL__
SELECT  * FROM ::orders
     LEFT JOIN ::users    ON ::orders.user_num   = ::users.num
     LEFT JOIN ::products ON ::orders.product_id = ::products.product_id
     WHERE ::products.price > ?
__SQL__;
$orders = DB::query($query, "1000.00");

// example output (with additional table keys)
[
    [order_id] => 1
    [user_num] => 1
    [product_id] => 1
    [quantity] => 1
    [order_date] => 2023-10-01
    [num] => 1
    [name] => John Doe
    [city] => Vancouver
    [price] => 1200.50
    [orders.order_id] => 1  // ... additional keys added because multiple tables are joined
    [orders.user_num] => 1
    [orders.product_id] => 1
    [orders.quantity] => 1
    [orders.order_date] => 2023-10-01
    [users.num] => 1
    [users.name] => John Doe
    [users.isAdmin] => 0
    [users.status] => Active
    [users.city] => Vancouver
    [products.product_id] => 1
    [products.name] => Laptop
    [products.price] => 1200.50
],[ 
    ...
]
```

</div>

## Method Arguments

<div style="margin-left: 30px;">

All of the database methods share these common method arguments.
</div>

### $baseTable

<div style="margin-left: 30px;">

Table name without prefix. The table prefix will be added automatically.
</div>

### $conditions

<div style="margin-left: 30px;">

The conditions specify which rows are returned. Can be
a primary key as an integer, an array of WHERE conditions, or an SQL query
string with optional placeholders. Examples:

```php
  // lookup by primary key
  DB::get('users', 12);
  DB::get('news', (int) $_GET['num']); // convert string to an integer
  DB::get('news', '12');               // strings will produce an error
  
  // lookup by where array - with separate variable for readability
  $where = ['name' => 'John', 'city' => 'Vancouver']; // specify as var for cleaner code
  $rows = DB::select('users', $where); 
  
  // lookup by where array - as one line
  $rows = DB::select('users', ['name' => 'John', 'city' => 'Vancouver']);
  
  // lookup with SQL
  $rows = DB::select('news', "publishDate <= NOW()"); // WHERE is optional
  
  // lookup with SQL - with additional SQL clauses
  $whereEtc = "publishDate <= NOW() ORDER BY publishDate DESC LIMIT 10"; // where with additional clauses 
  $rows = DB::select('news', $whereEtc);
  
  // lookup with SQL - with positional placeholders
  $rows = DB::select('users', "name = ? and city = ?", "Bob", "Vancouver");
  
  // lookup with SQL - with named placeholders
  $rows = DB::select('users', "name = :name and city = :city", [
    ':name' => 'Bob',
    ':city' => 'Vancouver'
  ]);
```

</div>

### ...$mixedParams

<div style="margin-left: 30px;">

Parameters to fill SQL placeholders. Can be 1-3 positional parameters or array of parameters.
Extra or unused parameters are ignored.

```php
// Example of 1-3 positional parameters
DB::select('users', "name = ?", "Bob");
DB::select('users', "name = ? and city = ?", "Bob", "Vancouver");
DB::select('users', "name = ? and city = ? and status = ?", "Bob", "Vancouver", "active");

// If you need more than 3 it will read better if use an array and multiple lines
DB::select('users', "name = ? and city = ? and status = ?", [
    "Bob",
    "Vancouver",
    "active"
]);

// or better yet, use a separate var for your conditions and named parameters
DB::select('users', "name = :name and city = :city and status = :status", [
    ":name"   => "Bob",
    ":city"   => "Vancouver",
    ":status" => "active"
]);

// Need to insert an unquoted number?  Pass it as an integer
DB::select('users', "LIMIT ?", 10); // e.g. LIMIT 10
DB::select('users', "LIMIT ?", "10"); // Won't work, quotes and escapes value as: LIMIT "10" 
```

</div>

### $colsToValues

<div style="margin-left: 30px;">

Array of column names to values to use in an insert or update query.

```php
$colsToValues = [
    'username' => 'john',
    'email'    => 'john@example.com', 
    'city'     => 'Vancouver'
];

// Example insert and update
$newId        = DB::insert('users', $colsToValues);
$affectedRows = DB::update('users', $colsToValues, "num = ?", 123);

// Need to specify a raw SQL expression for the value? Use DB::rawSql()
// Note: DB::rawSql() is the one way you can introduce SQL injection vulnerabilities,
// so use with caution. Never pass user input this way.
$colsToValues = [
    'createdDate' => DB::rawSql("NOW()"),  // Never pass user input this way
    'username'    => 'john',
    'email'       => 'john@example.com', 
    'city'        => 'Vancouver'
];
```

</div>

## Other Methods & Properties

### Config & Connection

| Method              | Description & Example Usage                                                          |
|---------------------|--------------------------------------------------------------------------------------|
| `DB::config(...)`   | **REQUIRED**; Sets the config options for the connection.  See code for more details |
| `DB::connect()`     | **REQUIRED**; Connect to the database, does nothing if already connected             |
| `DB::isConnected()` | Returns a boolean to indicate if a database connection is active                     |
| `DB::disconnect()`  | Closes the active database connection. If no connection is active, does nothing      |

### SmartArray Objects

ZenDB returns query results as SmartArray objects containing nested SmartArrays (rows) and SmartString objects (fields).
This hierarchy makes your code cleaner, safer, and more powerful.

#### MySQL Metadata Access

Both ResultSet and Row objects provide access to MySQL metadata through the `mysqli()` method:

| Method                             | Description & Example Usage                               |
|------------------------------------|-----------------------------------------------------------|
| `$result->mysqli()`                | Returns all MySQL metadata as array                       |
| `$result->mysqli('insert_id')`     | Auto-increment ID from the last INSERT query              |
| `$result->mysqli('affected_rows')` | Number of rows affected by INSERT, UPDATE, DELETE queries |
| `$result->mysqli('query')`         | The SQL query that was executed                           |
| `$result->mysqli('error')`         | Error message from the query (if any)                     |
| `$result->mysqli('errno')`         | Error number from the query (if any)                      |

#### ResultSet Methods (multiple rows)

| Method                       | Description & Example Usage                                          |
|------------------------------|----------------------------------------------------------------------|
| `count($resultSet)`          | The number of rows in the result set                                 |
| `$resultSet->count()`        | Alternative way to get the number of rows                            |
| `$resultSet->first()`        | Returns first row as SmartArray or an empty SmartArray if no rows    |
| `$resultSet->toArray()`      | Returns array of raw rows (not HTML-encoded, not SmartArray objects) |
| `$resultSet->pluck('col')`   | Extract a single column from all rows, e.g. `$users->pluck('id')`    |
| `$resultSet->sortBy('col')`  | Sort result set by a column, e.g. `$users->sortBy('last_name')`      |
| `$resultSet->filter(fn)`     | Filter rows using a callback function                                |
| `$resultSet->where([...])`   | Filter rows by values, e.g. `$users->where(['active' => 1])`         |
| `$resultSet->map(fn)`        | Transform each row using a callback                                  |
| `$resultSet->indexBy('id')`  | Convert result set to a lookup by key                                |
| `$resultSet->groupBy('col')` | Group rows by a column, e.g. `$users->groupBy('department')`         |

#### Row Methods (single row)

| Method                        | Description & Example Usage                                        |
|-------------------------------|--------------------------------------------------------------------|
| `$row->columnName`            | Access column as SmartString (auto HTML-encoded in string context) |
| `$row['columnName']`          | Alternative array-style access to column values                    |
| `$row->get('col', 'default')` | Get column with optional default if missing                        |
| `$row->values()`              | Returns indexed array of SmartString objects                       |
| `$row->keys()`                | Returns array of column names                                      |
| `$row->toArray()`             | Returns array of column names and raw values (not HTML-encoded)    |
| `$row->isEmpty()`             | Check if row has no columns                                        |
| `$row->contains('value')`     | Check if row contains a specific value                             |

For additional array manipulation methods, see
the [SmartArray documentation](https://github.com/interactivetools-com/SmartArray).

### SmartString Objects

Each field in a row is a SmartString object that automatically HTML-encodes values when used in string contexts.
SmartString provides a wide range of utility methods for working with text, numbers, and dates.

#### Value Access & Encoding

| Method                  | Description & Example Usage                             |
|-------------------------|---------------------------------------------------------|
| `$column`               | Returns HTML-encoded value when used in string context  |
| `$column->value()`      | Returns original raw value and variable type (e.g. int) |
| `$column->rawHtml()`    | Alias for value(), useful when displaying trusted HTML  |
| `$column->htmlEncode()` | Returns HTML-encoded value (explicit method)            |
| `$column->urlEncode()`  | Returns URL-encoded value for use in URLs               |
| `$column->jsonEncode()` | Returns JSON-encoded value for use in JavaScript        |
| `$column->int()`        | Convert value to integer                                |
| `$column->float()`      | Convert value to float                                  |
| `$column->string()`     | Convert value to string (unencoded)                     |

#### Text Manipulation

| Method                   | Description & Example Usage                        |
|--------------------------|----------------------------------------------------|
| `$column->textOnly()`    | Remove HTML tags, decode entities, trim whitespace |
| `$column->maxChars(100)` | Limit string to 100 characters with ellipsis       |
| `$column->maxWords(20)`  | Limit string to 20 words with ellipsis             |
| `$column->nl2br()`       | Convert newlines to `<br>` tags                    |
| `$column->trim()`        | Remove whitespace from beginning and end           |

#### Formatting & Conditional Operations

| Method                      | Description & Example Usage                                          |
|-----------------------------|----------------------------------------------------------------------|
| `$column->dateFormat()`     | Format dates, e.g. `$row->created->dateFormat()` → "Sep 10, 2024"    |
| `$column->numberFormat(2)`  | Format numbers, e.g. `$row->price->numberFormat(2)` → "123.45"       |
| `$column->or('N/A')`        | Show alternate value if missing (null or "")                         |
| `$column->ifZero('None')`   | Show alternate value if zero                                         |
| `$column->ifNull('N/A')`    | Show alternate value if null                                         |
| `$column->ifBlank('Empty')` | Show alternate value if empty string                                 |
| `$column->and(' more')`     | Append text if present (not null or ""), zero is considered present  |
| `$column->andPrefix('$')`   | Prepend text if present (not null or ""), zero is considered present |

For additional string manipulation methods, see
the [SmartString documentation](https://github.com/interactivetools-com/SmartString).

### Inline Documentation

| Method            | Description & Example Usage                                          |
|-------------------|----------------------------------------------------------------------|
| `print_r($rows)`  | Show extra debugging info about the rows SmartArray and its contents |
| `print_r($row)`   | Show extra debugging info about the row SmartArray and its contents  |
| `print_r($field)` | Show extra debugging info about a field SmartString and its methods  |

### Utility Methods

| Method                                    | Description & Example Usage                                                                                             |
|-------------------------------------------|-------------------------------------------------------------------------------------------------------------------------|
| `DB::pagingSql($pageNum, $perPage)`       | Returns a `LIMIT/OFFSET` SQL clause for pagination based on the page number and entries per page. Defaults are 1 and 10 |
| `DB::rawSql($value)`                      | Returns a `RawSql` object wrapping the input, which can be string, int, or float. Meant for SQL literals like `NOW()`   |
| `DB::isRawSql($stringOrObj)`              | Returns a boolean indicating whether the given value is a `RawSql` instance. Private method                             |
| `DB::getFullTable($baseTable)`            | Returns the full table name with the prefix, based on a given base table name                                           |
| `DB::getBaseTable($fullTable)`            | Returns the base table name after removing the prefix from a full table name                                            |
| `DB::setTimezoneToPhpTimezone($timezone)` | Sets the timezone offset for the database connection to the given PHP timezone offset                                   |
| `DB::$mysqli`                             | $mysqli object                                                                                                          |

### SQL Pattern Helpers

| Method                                     | Description & Example Usage                                                            |
|--------------------------------------------|----------------------------------------------------------------------------------------|
| `DB::likeContains($value)`                 | Returns a LIKE pattern for "contains value" search: `'%value%'`                        |
| `DB::likeStartsWith($value)`               | Returns a LIKE pattern for "starts with value" search: `'value%'`                      |
| `DB::likeEndsWith($value)`                 | Returns a LIKE pattern for "ends with value" search: `'%value'`                        |
| `DB::likeContainsTSV($value)`              | Returns a LIKE pattern for finding values in tab-delimited columns: `'%\tvalue\t%'`    |
| `DB::escapeCSV(array $values)`             | Returns SQL-safe comma-separated list for IN clauses, handling datatypes appropriately |
| `DB::escape($value, $escapeLikeWildcards)` | Escapes a string safely for SQL. Optionally escapes LIKE wildcards (%, _)              |

### Table/Schema Helpers

| Method                                      | Description & Example Usage                                                            |
|---------------------------------------------|----------------------------------------------------------------------------------------|
| `DB::tableExists($tableName)`               | Checks if the specified table exists in the database                                   |
| `DB::getTableNames($includePrefix = false)` | Returns an array of table names in the database, optionally including the table prefix |
| `DB::getColumnDefinitions($baseTable)`      | Returns column definitions from a table as name=>definition pairs                      |

## Catching Errors

ZenDB uses exceptions to report errors. You can catch them like this:

```php
try {
    $resultSet = DB::select('users', 'username = ?', 'john');
} catch (Exception $e) {
    print "Error: " . $e->getMessage();
}
```

## Related Libraries

ZenDB is built on top of these powerful libraries:

- **[SmartArray](https://github.com/interactivetools-com/SmartArray)**: Enhanced arrays with chainable methods and
  fluent interface
- **[SmartString](https://github.com/interactivetools-com/SmartString)**: Secure string handling with automatic
  HTML-encoding

## Questions?

Post a message in our "CMS Builder" forum
here: [https://www.interactivetools.com/forum/](https://www.interactivetools.com/forum/)
