# Method Reference

Both `DB::` (static) and `Connection` (instance) expose the same query
methods. `DB::` delegates to its internal singleton connection.

|                                                              **Queries** |                                                                          |
|-------------------------------------------------------------------------:|--------------------------------------------------------------------------|
|                `DB::select($table, $where, ...$params)`                  | Select matching rows → `SmartArrayHtml`                                  |
|             `DB::selectOne($table, $where, ...$params)`                  | Select first matching row (adds LIMIT 1) → `SmartArrayHtml`             |
|                 `DB::count($table, $where, ...$params)`                  | Count matching rows → `int`                                              |
|                      `DB::query($sql, ...$params)`                       | Execute custom SQL → `SmartArrayHtml`                                    |
|                   `DB::queryOne($sql, ...$params)`                       | Execute custom SQL, first row (adds LIMIT 1) → `SmartArrayHtml`         |
|                                                               **Modify** |                                                                          |
|                      `DB::insert($table, $values)`                       | Insert a row → `int` (new auto-increment ID)                            |
| `DB::update($table, $values, $where, ...$params)`                       | Update matching rows → `int` (affected rows)                            |
|                `DB::delete($table, $where, ...$params)`                  | Delete matching rows → `int` (affected rows)                            |
|                                                           **Connection** |                                                                          |
|                                       `DB::connect($config)`             | Connect and set the global default connection                            |
|                              `DB::isConnected($doPing)`                  | Check if default connection is active, optionally ping server            |
|                                          `DB::disconnect()`              | Close the default connection                                             |
|                                        `DB::clone($config)`             | Clone default connection with different settings → `Connection`          |
|                                     `new Connection($config)`            | Create a standalone connection to a different database                   |
|                                                              **Helpers** |                                                                          |
|                                        `DB::rawSql($value)`             | Wrap a SQL expression to bypass escaping (e.g., `NOW()`)                |
|                            `DB::pagingSql($page, $perPage)`              | Generate `LIMIT`/`OFFSET` clause for pagination                         |
|                                  `DB::likeContains($input)`              | LIKE pattern: `%value%`                                                  |
|                                `DB::likeStartsWith($input)`              | LIKE pattern: `value%`                                                   |
|                                  `DB::likeEndsWith($input)`              | LIKE pattern: `%value`                                                   |
|                               `DB::likeContainsTSV($input)`              | LIKE pattern for tab-delimited values: `%\tvalue\t%`                     |
|                                                               **Schema** |                                                                          |
|                           `DB::getBaseTable($table, $checkDb)`           | Strip table prefix from a name, optionally check DB for ambiguity        |
|                           `DB::getFullTable($table, $checkDb)`           | Prepend table prefix to a name, optionally check DB for ambiguity        |
|                     `DB::hasTable($table, $isPrefixed)`                | Check if a table, view, or temp table exists                                  |
|                          `DB::getTableNames($includePrefix)`             | List all tables matching the configured prefix                           |
|                        `DB::getColumnDefinitions($baseTable)`            | Column name-to-definition pairs from SHOW CREATE TABLE                   |
|                                                             **Escaping** |                                                                          |
|                       `DB::escape($input, $escapeLikeWildcards)`         | Escape a value for safe use in raw SQL                                   |
|                              `DB::escapef($format, ...$values)`          | Substitute `?` placeholders with escaped/quoted values                   |
|                                       `DB::escapeCSV($array)`            | Convert array to safe comma-separated list for `IN (...)` clauses        |

### Parameter Types

**`$table`** - Base table name (without prefix). The configured `tablePrefix`
is added automatically.

**`$where`** - WHERE condition in one of three forms:
- `int` - Match by primary key: `['id' => $value]`
- `array` - Column-value pairs: `['status' => 'active', 'city' => 'Vancouver']`
- `string` - SQL with placeholders: `"status = ? AND city = ?"` (values passed as `...$params`)

**`$values`** - Associative array of column names to values:
`['name' => 'Alice', 'city' => 'Toronto']`. Use `DB::rawSql()` for SQL
expressions like `NOW()`.

**`$sql`** / **`$sqlTemplate`** - Raw SQL string. Use `::tableName` to insert
the table prefix, `?` for positional placeholders, and `:name` for named
placeholders.

**`$config`** - Connection configuration array. See
[Getting Started](01-getting-started.md#all-configuration-options) for all options.

---

[← Back to README](../README.md)
