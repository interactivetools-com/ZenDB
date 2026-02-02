# ZenDB Multi-Connection Refactor Specification

## Overview

Refactor ZenDB to support multiple database connections while maintaining the simple static API for the common case (single CMS connection).

---

## Goals & Use Cases

### Primary Goals
1. **Multiple connections** - Connect to remote servers to import data, isolated error logging
2. **Per-connection settings** - Toggle useSmartJoins, useSmartStrings, etc. without global flags
3. **Preserve static convenience** - `DB::select()` continues to work for default CMS connection

### Use Cases
```php
// 1. Static convenience (90% of usage)
new Connection(['hostname' => 'localhost', 'database' => 'cms'], default: true);
DB::select('users');
DB::get('users', 123);

// 2. Import from remote server
$remote = new Connection([
    'hostname' => 'remote-server.com',
    'database' => 'legacy_db',
]);
foreach ($remote->select('old_users') as $row) {
    DB::insert('imported_users', $row->toArray());
}

// 3. Isolated error logging (separate connection, unaffected by transactions)
$errorLog = new Connection([
    'hostname' => 'localhost',
    'database' => 'error_logs',
]);
$errorLog->insert('errors', ['message' => $e->getMessage()]);

// 4. Temporarily change settings
$db = DB::clone(['useSmartJoins' => false, 'useSmartStrings' => false]);
$rawRows = $db->select('users');
```

---

## Architecture

### Two-Class Design

**Why two classes?**
- PHP cannot have same-name static and instance methods in one class
- `__callStatic()` adds overhead on every call (unacceptable for hot path)
- Explicit static methods are fast and IDE-friendly

```
Itools\ZenDB\
│
├── DB (static facade)
│   - Thin wrapper, delegates to internal $db
│   - Fast explicit static methods (no magic)
│   - clone() for getting instance with different settings
│
├── Connection (instance class)
│   - All query logic lives here
│   - Owns mysqli connection + settings
│   - Public properties for settings
│   - Constructor with default: flag to set as default
│
└── Supporting Classes (mostly unchanged)
    - MysqliWrapper (extends mysqli, adds logging)
    - Parser (SQL template + parameter handling)
    - Assert, DBException, RawSql
```

---

## Class Specifications

### DB (Static Facade)

```php
namespace Itools\ZenDB;

class DB {
    //
    // Internal State
    //
    private static ?Connection $db = null;             // The default connection
    public static ?MysqliWrapper $mysqli = null;       // Backwards compat

    //
    // Internal - Called by Connection constructor
    //

    /** @internal */
    public static function setDefault(Connection $conn): void {
        self::$db     = $conn;
        self::$mysqli = $conn->mysqli;
    }

    //
    // Factory Methods
    //

    /**
     * Clone the default connection (shared mysqli, copied settings).
     * Use when you need different settings on the same connection.
     *
     *     DB::clone()                                    // Clone with same settings
     *     DB::clone(['useSmartJoins' => false])          // Clone with overrides
     */
    public static function clone(array $config = []): Connection {
        return self::$db->clone($config);
    }

    //
    // Query Methods (delegate to $db)
    //

    public static function select(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArrayHtml {
        return self::$db->select($baseTable, $idArrayOrSql, ...$params);
    }

    public static function get(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArrayHtml {
        return self::$db->get($baseTable, $idArrayOrSql, ...$params);
    }

    public static function insert(string $baseTable, array $colsToValues): int {
        return self::$db->insert($baseTable, $colsToValues);
    }

    public static function update(string $baseTable, array $colsToValues, int|array|string $idArrayOrSql, ...$params): int {
        return self::$db->update($baseTable, $colsToValues, $idArrayOrSql, ...$params);
    }

    public static function delete(string $baseTable, int|array|string $idArrayOrSql, ...$params): int {
        return self::$db->delete($baseTable, $idArrayOrSql, ...$params);
    }

    public static function query(string $sqlTemplate, ...$params): SmartArrayHtml {
        return self::$db->query($sqlTemplate, ...$params);
    }

    public static function count(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): int {
        return self::$db->count($baseTable, $idArrayOrSql, ...$params);
    }

    //
    // Utility Methods (delegate to $db)
    //

    public static function escape(...$args): string {
        return self::$db->escape(...$args);
    }

    public static function tableExists(string $table): bool {
        return self::$db->tableExists($table);
    }

    // ... other utility methods delegate similarly

    //
    // Static Utility Methods (no connection needed)
    //

    public static function rawSql(string $sql): RawSql {
        return new RawSql($sql);
    }

    public static function escapeCSV(array $array): RawSql { ... }
    public static function pagingSql(...): RawSql { ... }
    public static function likeContains(...): RawSql { ... }
    public static function likeStartsWith(...): RawSql { ... }
    public static function likeEndsWith(...): RawSql { ... }
}
```

### Connection (Instance Class)

```php
namespace Itools\ZenDB;

class Connection {
    //
    // Connection State
    //
    private ?MysqliWrapper $mysqli = null;
    private bool $ownsConnection = true;  // false for clones (don't close on destruct)

    //
    // Settings - Public Properties
    //

    // Connection settings (used during connect)
    public ?string $hostname       = null;
    public ?string $username       = null;
    public ?string $password       = null;
    public ?string $database       = null;
    public string  $tablePrefix    = '';
    public string  $primaryKey     = 'num';

    // Query behavior settings
    public bool $useSmartJoins     = true;
    public bool $useSmartStrings   = true;
    public bool $usePhpTimezone    = true;

    // Result handling
    public mixed $smartArrayLoadHandler = null;  // callable|null - custom handler for loading results

    // Error handling
    public mixed $showSqlInErrors = false;  // bool|callable - show SQL in exceptions

    // Advanced settings
    public string $versionRequired    = '5.7.32';
    public bool   $requireSSL         = false;
    public bool   $databaseAutoCreate = true;
    public int    $connectTimeout     = 3;
    public int    $readTimeout        = 60;
    public bool   $enableLogging      = false;
    public string $logFile            = '_mysql_query_log.php';
    public string $sqlMode            = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    //
    // Constructor
    //

    /**
     * Create a new database connection.
     *
     *     new Connection($config)                  // Create connection
     *     new Connection($config, default: true)  // Create and set as default
     */
    public function __construct(array $config = [], bool $default = false) {
        foreach ($config as $key => $value) {
            $this->$key = $value;
        }

        // Connect immediately if connection settings provided
        if ($this->hostname !== null) {
            $this->connect();
        }

        // Set as default if requested
        if ($default) {
            DB::setDefault($this);
        }
    }

    //
    // Connection Management
    //

    public function connect(): void;
    public function disconnect(): void;
    public function isConnected(bool $doPing = false): bool;

    /**
     * Ensure connection is alive, reconnect if needed.
     * Useful for long-running processes where MySQL may drop idle connections.
     */
    public function ensureConnected(): void;

    //
    // Query Methods (real implementation)
    //

    public function select(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArrayHtml;
    public function get(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): SmartArrayHtml;
    public function insert(string $baseTable, array $colsToValues): int;
    public function update(string $baseTable, array $colsToValues, int|array|string $idArrayOrSql, ...$params): int;
    public function delete(string $baseTable, int|array|string $idArrayOrSql, ...$params): int;
    public function query(string $sqlTemplate, ...$params): SmartArrayHtml;
    public function count(string $baseTable, int|array|string $idArrayOrSql = [], ...$params): int;

    //
    // Utility Methods
    //

    public function escape(...): string;
    public function tableExists(...): bool;
    public function getTableNames(...): array;
    public function getColumnDefinitions(...): array;
    public function getBaseTable(...): string;
    public function getFullTable(...): string;

    //
    // Clone Support
    //

    /**
     * Clone this connection with optional config overrides.
     * The clone shares the mysqli connection but has its own settings.
     *
     *     $db->clone()                           // Clone with same settings
     *     $db->clone(['useSmartJoins' => false]) // Clone with overrides
     */
    public function clone(array $config = []): self {
        $clone = clone $this;
        foreach ($config as $key => $value) {
            $clone->$key = $value;
        }
        return $clone;
    }

    public function __clone(): void {
        // Share mysqli connection, mark as non-owner
        $this->ownsConnection = false;
    }

    public function __destruct() {
        // Only close connection if we own it
        if ($this->ownsConnection && $this->mysqli) {
            $this->mysqli->close();
        }
    }
}
```

---

## Clone vs New Connection

| Aspect | `DB::clone()` / `$db->clone()` | `new Connection($config)` |
|--------|--------------------------------|---------------------------|
| mysqli connection | **Shared** | **New** (own connection) |
| Settings | **Copied** from source | **Fresh** from $config |
| Transactions | Shared (affects both) | Independent |
| Use case | Different settings, same DB | Remote server, isolation |
| Destructor | Does NOT close connection | Closes connection |

```
DB::clone()                         new Connection($config)
    │                                      │
    ▼                                      ▼
┌─────────────────┐                ┌─────────────────┐
│   Connection    │                │   Connection    │
│  mysqli: ───────┼──► SHARED      │  mysqli: ───────┼──► NEW
│  settings: copy │                │  settings: new  │
│  ownsConnection │                │  ownsConnection │
│    = false      │                │    = true       │
└─────────────────┘                └─────────────────┘
```

---

## Usage Examples

### Basic Setup
```php
use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;

// Create default connection
new Connection([
    'hostname'    => 'localhost',
    'username'    => 'root',
    'password'    => '',
    'database'    => 'cms',
    'tablePrefix' => 'cms_',
], default: true);

// Query via static methods
$users = DB::select('users');
$user  = DB::get('users', 123);
$newId = DB::insert('users', ['name' => 'Bob']);
```

### Change Settings Temporarily
```php
// Clone with config overrides
$db = DB::clone(['useSmartJoins' => false, 'useSmartStrings' => false]);
$rawRows = $db->select('users');  // Plain arrays

// Or clone then modify
$db = DB::clone();
$db->useSmartJoins = false;

// Default is unchanged
$smartRows = DB::select('users');  // Still SmartArrayHtml
```

### Remote Server Import
```php
$remote = new Connection([
    'hostname'    => 'legacy-server.com',
    'username'    => 'importer',
    'password'    => 'secret',
    'database'    => 'old_system',
    'tablePrefix' => '',
]);

foreach ($remote->select('customers') as $customer) {
    DB::insert('imported_customers', [
        'legacy_id' => $customer->id->value(),
        'name'      => $customer->name->value(),
    ]);
}

// $remote closes automatically when it goes out of scope
```

### Isolated Error Logging
```php
// Create isolated connection (not affected by main transaction)
$errorDb = new Connection([
    'hostname' => 'localhost',
    'database' => 'error_logs',
]);

try {
    DB::query("START TRANSACTION");
    // ... operations that might fail ...
    DB::query("COMMIT");
} catch (Throwable $e) {
    DB::query("ROLLBACK");

    // This write succeeds even though main transaction rolled back
    $errorDb->insert('errors', [
        'message' => $e->getMessage(),
        'trace'   => $e->getTraceAsString(),
    ]);
}
```

### Instance Clone
```php
$remote = new Connection(['hostname' => 'remote', ...]);

// Clone the remote connection with different settings
$remoteRaw = $remote->clone(['useSmartStrings' => false]);
```

---

## API Summary

| What | How |
|------|-----|
| Create default | `new Connection($config, default: true)` |
| Create other connection | `new Connection($config)` |
| Clone default | `DB::clone($overrides)` |
| Clone any instance | `$db->clone($overrides)` |
| Query via default | `DB::select(...)`, `DB::insert(...)`, etc. |
| Query via instance | `$db->select(...)`, `$db->insert(...)`, etc. |
| Change settings | `$db->useSmartJoins = false` (direct property) |
| Backwards compat | `DB::$mysqli` still works |

---

## Migration Notes

### Breaking Changes
- `DB::config()` removed - use `new Connection($config, default: true)` instead
- `DB::connect()` removed - connection happens in constructor
- `DB::create()` removed - use `new Connection($config)` instead

### Backwards Compatibility - DB::$mysqli
`DB::$mysqli` is preserved for backwards compatibility. It references the default connection's mysqli and is kept in sync automatically:

```php
// These all continue to work:
DB::$mysqli->query("...");
DB::$mysqli->real_escape_string($value);
DB::$mysqli->insert_id;
DB::$mysqli->affected_rows;
```

### Migration Examples
```php
// Old way
DB::config(['hostname' => 'localhost', 'database' => 'cms']);
DB::connect();

// New way
new Connection(['hostname' => 'localhost', 'database' => 'cms'], default: true);

// Old way
$remote = DB::create(['hostname' => 'remote', ...]);

// New way
$remote = new Connection(['hostname' => 'remote', ...]);

// Old way
DB::config('useSmartJoins', false);

// New way
$db = DB::clone(['useSmartJoins' => false]);
// or
$db = DB::clone();
$db->useSmartJoins = false;
```

---

## Files to Modify

| File | Changes |
|------|---------|
| `DB.php` | Refactor to static facade, remove config/connect/create |
| `Connection.php` | **NEW** - Extract instance logic from current DB.php |
| `Parser.php` | Minor - receive mysqli from Connection instance |
| `MysqliWrapper.php` | Minor - may need adjustments for instance context |
| `Assert.php` | Minimal or none |
| `Config.php` | **DELETE** - unused, settings now on Connection |

---

## Implementation Checklist

1. [ ] Create `Connection.php` with instance methods extracted from `DB.php`
2. [ ] Add `default` parameter to `Connection::__construct()`
3. [ ] Refactor `DB.php` to thin static facade
4. [ ] Add `DB::setDefault()` internal method
5. [ ] Implement `DB::clone()`
6. [ ] Update `Parser` to work with Connection instance
7. [ ] Handle `$ownsConnection` logic in clone/destruct
8. [ ] Keep `DB::$mysqli` synced for backwards compat
9. [ ] Test: New Connection with default: true works
10. [ ] Test: Clone shares connection, copies settings
11. [ ] Test: New Connection (no default) is independent
12. [ ] Test: Destructor only closes owned connections
13. [ ] Delete unused `Config.php`

---

## Verification

### Manual Testing
```php
// 1. Verify default connection works
new Connection(['hostname' => 'localhost', 'database' => 'test'], default: true);
$rows = DB::select('users');
assert($rows instanceof SmartArrayHtml);

// 2. Verify clone shares connection
$db = DB::clone();
$db->useSmartJoins = false;
// Default is unaffected

// 3. Verify new Connection is independent
$db2 = new Connection(['hostname' => 'localhost', 'database' => 'test']);
// Should be independent connection

// 4. Verify destructor behavior
$db3 = DB::clone();
unset($db3);  // Should NOT close the shared connection
assert(DB::$mysqli->ping() === true);

$db4 = new Connection(['hostname' => 'localhost', 'database' => 'test']);
unset($db4);  // SHOULD close its own connection

// 5. Verify DB::$mysqli backwards compat
assert(DB::$mysqli !== null);
DB::$mysqli->query("SELECT 1");
```

### Unit Tests
- Existing tests should pass (with migration)
- Add tests for clone behavior
- Add tests for connection ownership
- Add tests for default: true flag
