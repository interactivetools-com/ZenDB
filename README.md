# ZenDB: PHP/MySQL Database Library

## Overview

ZenDB is a PHP/MySQL database abstraction layer designed to make your
development process faster, easier, and more enjoyable. It focuses on ease of
use, beautiful code, and optimizing for common use cases while also allowing for
advanced and complex queries when needed. 

## Features

### Injection Proof SQL

<div style="margin-left: 30px;">

ZenDB completely eliminates the risk of MySQL injection vulnerabilities.  Here's how a typical injection attack works.

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

We make it impossible to accidentally introduce injection vulnerabilities by
disallowing direct string or number inputs. Even if you accidentally pass
unfiltered user input directly to the database, the query will refuse to
run and throw an error.

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

### Automatic HTML-Encoding

<div style="margin-left: 30px;">

The most common use case for web apps is to HTML-encode output, so we do that automatically while allowing
for other encoding methods and the original value to be accessed if needed. Additionally,
we provide rows and values as objects instead of arrays, so you can access them using properties.
Which allows for easier interpolation and cleaner code.

```php
// Insecure old way, outputting user values without encoding.  This is vulnerable to XSS attacks.
print "Hello, {$row['name']}!"; // requires curly braces { } to insert the variable into the string

// Secure old way, outputting user values with HTML-encoding.  This is safe, but cumbersome.
print "Hello, " . htmlspecialchars($row['name']) . "!"; 

// ZenDB values are automatically encoded and accessed via properties.  This is safe and easy to read.
print "Hello $row->name!"; // No extra characters required to insert variable into string

// What happens if you forget and try to access it as an array index? 
print "Hello, {$row['name']}!";
// Returns helpful error: Invalid access: Use $row->name instead of $row['name']

// Don't want HTML-encoding?  You can access the original value or different encodings instead
$row->name;               // O&apos;Reilly &amp; Sons // Default usage, HTML-encoded output
$row->name->htmlEncode(); // O&apos;Reilly &amp; Sons // As above, available for self-documenting code
$row->name->urlEncode();  // O%27Reilly+%26+Sons      // URL-encoded      
$row->name->jsEncode();   // O\'Reilly & Sons         // JS-encoded         
$row->name->raw();        // O'Reilly & Sons          // Returns original type and value

// Forget the options?  Use print_r for inline documentation with all methods, properties and values
print_r($row->name);  

// You can also disable encoding on the resultSet or row to get an array of raw values
$resultSet = DB::select('users')->raw();      // 
foreach ($resultSet->raw() as $row) { ... }   // Alternative way to do the same thing 
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
ensuring your code is clean and readable.  For more complex querys you can
even write direct MySQL and uses :_ to insert table prefixes.  

```php
// Lookup row by id
$row = DB::get('users', 1);

// Lookup rows with array of WHERE conditions
$results = DB::select('users', ['active' => 1, 'city' => 'Vancouver']);

// Lookup rows with custom SQL
$results = DB::select('news', "WHERE publishDate <= NOW() ORDER BY publishDate DESC");

// Write a custom SQL query, using :_ placeholder to insert table prefix
$resultSet = DB::query("SELECT *, p.price AS unit_price, (od.quantity * p.price) AS total_price
                          FROM :_users         AS u
                          JOIN :_orders        AS o  ON u.num         = o.user_id
                          JOIN :_order_details AS od ON o.order_id    = od.order_id
                          JOIN :_products      AS p  ON od.product_id = p.product_id");

```

</div>


### Database Operations

<div style="margin-left: 30px;">

We provide a unified, intuitive interface for all the standard database operations, while also giving
you the flexibility to execute custom SQL queries for more complex use cases.

```php
// Select one or more rows
$resultSet    = DB::select($table);            // return multiple rows
$record       = DB::get($table, $conditions);  // return first result

// Insert, update or delete rows
$newId        = DB::insert($table, $conditions);
$affectedRows = DB::update($table, $colsToValues, $conditions);
$affectedRows = DB::delete($table, $colsToValues);

// Count rows
$count        = DB::count($table, $conditions);

// Custom SQL queries 
$sqlQuery     = "SELECT * FROM users WHERE publishDate <= NOW() ORDER BY publishDate DESC";
$resultSet    = DB::query($sqlQuery);

// These are all simple examples, you can also pass placeholder parameters as additional arguments
```

</div>

### Named and Positional Placeholders

<div style="margin-left: 30px;">

We support both named `:named` and positional `?` placeholders for secure database input.
Inputs are sent to the server independently from the query, using bound parameters, which
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
$resultSet = DB::query("SELECT *, p.price AS unit_price, (od.quantity * p.price) AS total_price
                          FROM :_users         AS u
                          JOIN :_orders        AS o  ON u.num         = o.user_id
                          JOIN :_order_details AS od ON o.order_id    = od.order_id
                          JOIN :_products      AS p  ON od.product_id = p.product_id
                         WHERE u.num = ?", $userNum);

//
foreach ($resultSet as $row) { 
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

Not sure you can remember all of that? Don't worry, you don't have to. When you use standard
programmer debugging functions such as `print_r` we'll automatically show you some inline documentation
for programmers including the effective SQL query that was executed and useful stats and info.

```php
// ResultSet object 
$resultSet = DB::select('locations', "city = ?", "O'Brien");

// Example output from print_r($resultSet)
ResultSet Object(
 [__DEVELOPERS__] => 
        This 'ResultSet' object acts like an array, with each 'Row' object being a collection of 'Value' objects.
        The SQL values below are simulated; actual queries use parameter binding for security.
        
        Simulated SQL: SELECT * FROM `locations` WHERE city = "O\'Brien"


        Below are the accessible properties and methods. Parentheses are optional for code clarity:
        
        $resultSet->count        = 18   // Total number of rows, equivalent to count($resultSet)
        $resultSet->success      = true // Boolean value true|false as returned by mysqli_result
        $resultSet->affectedRows = 18   // Number of rows affected for INSERT, UPDATE, DELETE
        $resultSet->insertId     = 0    // Primary key of last inserted row for INSERT queries
        $resultSet->error        =      // Error message, if any, from the last SQL operation
        $resultSet->errno        = 0    // Error number from the last SQL operation
        $resultSet->getFirst()          // Returns the first 'Row' object or an empty 'Row' if no rows
        $resultSet->raw()               // Key/value pair array of the original unencoded row data
                        
        foreach ($resultSet as $row)    // Iterate over rows; each row is HTML-encoded by default
            $row->column                // Default usage gives HTML-encoded output. Use print_r($row->column) for details
            $row->column->raw()         // Returns the original data type and unencoded value
        }                               

        HTML-encoded values for all rows in this resultSet are listed below.

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
// Select: Returns a resultSet object with matching rows 
$resultSet = DB::select($baseTable, $conditions, ...$mixedParams);

// Get: Returns first matching Row object, or empty Row if the result set is empty 
$resultSet = DB::get($baseTable, $conditions, ...$mixedParams); 

// Count: Returns count of matching rows
$resultSet = DB::count($baseTable, $conditions, ...$mixedParams);

// Insert: Inserts a new row and returns the new id
$newId = DB::insert($baseTable, $colsToValues);

// Update: Updates one or more rows and returns the number of rows affected
$affectedRows = DB::update($baseTable, $colsToValues, $conditions, ...$mixedParams);

// Delete: Deletes one or more rows and returns the number of rows affected
$affectedRows = DB::delete($baseTable, $conditions, ...$mixedParams);

// Query: Executes a SQL query and returns a resultSet object
$resultSet = DB::query($sqlQuery, ...$mixedParams);
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
  print $user->name->raw();         // outputs original value (not html-encoded)
  print $user->name->htmlEncode();  // outputs htmlEncoded name (if different encoding)
  print $user->name->urlEncode();   // outputs urlEncoded name (if different encoding)
  print $user->name->jsEncode();    // outputs jsEncoded name (if different encoding)
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
                          ":pagingSQL => pagingSql($pageNum, $perPage), // creates OFFSET and LIMIT clauses
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
    'email'       => 'bob2@example.com'
    'lastUpdated' => DB::raw('NOW()'),
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
// direct SQL query - :_ is replaced with table prefix
$query = <<<__SQL__
SELECT  * FROM :_orders
     LEFT JOIN :_users    ON :_orders.user_num   = :_users.num
     LEFT JOIN :_products ON :_orders.product_id = :_products.product_id
     WHERE product.price > ?
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

The conditions specify which rows are returned.  Can be
a primary key as an integer, an array of WHERE conditions, or an SQL query
string with optional placeholders.  Examples:

```php
  // lookup by primary key
  DB::get('users', 12);
  DB::get('news', (int) $_GET['num']); // convert string to an integer
  DB::get('news', '12');               // strings will produce an error
  
  // lookup by where array - with separate variable for readability
  $where = ['name' => 'John', 'city' => 'Vancouver']; // specify as var for cleaner code
  $results = DB::select('users', $where); 
  
  // lookup by where array - as one line
  $results = DB::select('users', ['name' => 'John', 'city' => 'Vancouver']);
  
  // lookup with SQL
  $results = DB::select('news', "publishDate <= NOW()"); // WHERE is optional
  
  // lookup with SQL - with additional SQL clauses
  $whereEtc = "publishDate <= NOW() ORDER BY publishDate DESC LIMIT 10"; // where with additional clauses 
  $results = DB::select('news', $whereEtc);
  
  // lookup with SQL - with positional placeholders
  $results = DB::select('users', "name = ? and city = ?", "Bob", "Vancouver");
  
    // lookup with SQL - with named placeholders
  $results = DB::select('users', "name = :name and city = :city", [
    ':name' => 'Bob',
    ':city' => 'Vancouver'
  ]);
```

</div>

### ...$mixedParams

<div style="margin-left: 30px;">

Parameters to fill SQL placeholders.  Can be 1-3 positional parameters or array of parameters.
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

// Need to specify a raw SQL expression for the value? Use DB::raw()
// Note: DB::raw() is the one way you can introduce SQL injection vulnerabilities,
// so use with caution. Never pass user input this way.
$colsToValues = [
    'createdDate' => DB::raw("NOW()"),  // Never pass user input this way
    'username'    => 'john',
    'email'       => 'john@example.com', 
    'city'        => 'Vancouver'
];
```

</div>

## Other Methods & Properties

### Config & Connection

| Method              | Description & Example Usage                                                           |
|---------------------|---------------------------------------------------------------------------------------|
| `DB::config(...)`   | **REQUIRED**; Set's the config options for the connection.  See code for more details |
| `DB::connect()`     | **REQUIRED**; Connect to the database, does nothing if already connected              |
| `DB::isConnected()` | Returns a boolean to indicate if a database connection is active                      |
| `DB::disconnect()`  | Closes the active database connection. If no connection is active, does nothing       |

### ResultSet Object

| Method                     | Description & Example Usage                                             |
|----------------------------|-------------------------------------------------------------------------|
| `$resultSet`               | Object that emulates an array of Row objects.  Use foreach to loop over |
| `$resultSet->success`      | If the query was successful, boolean true                               |
| `$resultSet->count`        | The number of rows in the result set, same as count($resultSet)         |
| `$resultSet->affectedRows` | For INSERT, UPDATE, DELETE queries, rows affected                       |
| `$resultSet->insertId`     | For INSERT queries, primary key of last inserted row                    |
| `$resultSet->error`        | Error message from this query                                           |
| `$resultSet->errno`        | Errno from this query                                                   |
| `$resultSet->getFirst()`   | Returns first `Row` object or an empty `Row` object if no rows          |
| `$resultSet->raw()`        | Returns array of raw rows (not HTML-encoded, not objects)               |

### Row Object

| Method                           | Description & Example Usage                                                                           |
|----------------------------------|-------------------------------------------------------------------------------------------------------|
| `$row`                           | Object that emulates an array of Value objects.  Use foreach to loop over                             |
| `$row->columnName`               | Returns **named** column as Value object (e.g.; $row->city).  Access as string for HTML-encoded value |
| <nobr>`$row->getValues()`</nobr> | Returns an indexed array of Value objects, like array_values()                                        |
| `$row->raw()`                    | Returns array of column names and values (not HTML-encoded, not objects)                              |

### Value Object

| Method                  | Description & Example Usage                              |
|-------------------------|----------------------------------------------------------|
| `$column`               | Returns HTML-encoded value (when accessed as string)     |
| `$column->htmlEncode()` | Returns HTML-encoded value (when accessed as string)     |
| `$column->urlEncode()`  | Returns URL-encoded value (when accessed as string)      |
| `$column->jsEncode()`   | Returns JS-encoded value (when accessed as string)       |
| `$column->raw()`        | Returns original value and variable type (e.g. int, etc) |

### Inline Documentation

| Method                | Description & Example Usage                                             |
|-----------------------|-------------------------------------------------------------------------|
| `print_r($resultSet)` | Show extra debugging info about the object and its contents             |
| `print_r($row)`       | Row Obj - Show extra debugging info about the object and its contents   |
| `print_r($value)`     | Value Obj - Show extra debugging info about the object and its contents |

### Utility Methods

| Method                                    | Description & Example Usage                                                                                             |
|-------------------------------------------|-------------------------------------------------------------------------------------------------------------------------|
| `DB::pagingSql($pageNum, $perPage)`       | Returns a `LIMIT/OFFSET` SQL clause for pagination based on the page number and entries per page. Defaults are 1 and 10 |
| `DB::datetime($unixtime)`                 | Returns a string formatted as a MySQL datetime. Takes an optional Unix timestamp, defaults to the current server time   |
| `DB::raw($value)`                         | Returns a `RawSql` object wrapping the input, which can be string, int, or float. Meant for SQL literals like `NOW()`   |
| `DB::isRaw($stringOrObj)`                 | Returns a boolean indicating whether the given value is a `RawSql` instance. Private method                             |
| `DB::getFullTable($baseTable)`            | Returns the full table name with the prefix, based on a given base table name                                           |
| `DB::getBaseTable($fullTable)`            | Returns the base table name after removing the prefix from a full table name                                            |
| `DB::setTimezoneToPhpTimezone($timezone)` | Sets the timezone offset for the database connection to the given PHP timezone offset                                   |
| `DB::like($keyword)`                      | Returns a "contains keyword" pattern for SQL "column LIKE ?" searches                                                  |
| `DB::$mysqli`                             | $mysqli object                                                                                                          |

## Catching Errors

ZenDB uses exceptions to report errors. You can catch them like this:

```php
try {
    $resultSet = DB::select('users', 'username = ?', 'john');
} catch (Exception $e) {
    print "Error: " . $e->getMessage();
}
```

## Questions?

Post a message in our "CMS Builder" forum
here: [https://www.interactivetools.com/forum/](https://www.interactivetools.com/forum/)
