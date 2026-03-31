# ZenDB Design Discussion Notes

Notes from a design review session (2026-03-18). Covers potential new features,
naming decisions, and internal refactoring ideas.

---

## ZenDB Design Philosophy (as observed)

- **Convention over configuration** - sensible defaults, minimal setup
- **Safety by default** - injection-proof templates (`assertSafeTemplate` rejects quotes/numbers),
  XSS-safe output via SmartString, credential vault prevents serialization leaks
- **Minimal ceremony** - one method call does the right thing
- **Transparent complexity hiding** - auto-encryption for MEDIUMBLOB columns is the gold standard:
  the developer doesn't need to know anything about AES or key derivation
- **Thin layer over MySQL** - developers write SQL when they need SQL, the library handles
  escaping, prefixing, and result wrapping
- **Not an ORM** - no query builders, no relationship mapping, no fluent chaining

---

## Feature: DB::upsert() - DECIDED: Not adding

Wraps MySQL's `INSERT ... ON DUPLICATE KEY UPDATE`.

### What it would look like

```php
DB::upsert('settings', ['user_id' => $userId, 'key' => 'theme', 'value' => 'dark']);
// Generates:
// INSERT INTO cms_settings SET `user_id` = 42, `key` = 'theme', `value` = 'dark'
// ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), ...
```

Possible signature:
```php
public function upsert(string $baseTable, array $values, array $updateOnly = []): int
```

### Why not

1. **Requires a unique index to work.** If the developer knows enough to set up a
   composite unique index, they know enough to write the SQL themselves.
2. **Not a repetitive pattern.** You write it once per table, not fifty times a day.
   It's a "write once" query, not daily boilerplate.
3. **Already a one-liner with existing tools:**
   ```php
   DB::query("INSERT INTO ::settings SET `user_id` = ?, `key` = ?, `value` = ?
              ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", $userId, $key, $val);
   ```
4. **Slippery slope.** Adding upsert opens the door to `insertIgnore()`, `replace()`,
   and other MySQL-specific variants.
5. **Doesn't match ZenDB's transparency philosophy.** Auto-encryption works because
   the developer doesn't need to understand AES. Upsert still requires understanding
   unique indexes and conflict semantics - it just shortens the syntax slightly.

### Use cases (for reference)

- Settings/preferences (user_id + key unique)
- Counters/stats (page_id + date unique)
- Voting/ratings (user_id + item_id unique)
- External data sync (external_id unique)
- Draft/autosave (user_id + form_id unique)

---

## Feature: DB::exists() - DECIDED: Not adding (docblock instead)

Would return bool via `SELECT 1 FROM table WHERE ... LIMIT 1`.

### Why not

- `if (DB::count('users', ['email' => $email]))` already works because 0 is falsy in PHP.
- On indexed columns (which existence checks usually target), performance is identical.
- The optimized version is already a one-liner:
  ```php
  if (DB::queryOne("SELECT 1 FROM ::users WHERE email = ?", $email)->isNotEmpty()) { ... }
  ```
- Added usage examples to `count()` docblock instead (Connection.php).

### COUNT(*) vs SELECT 1 LIMIT 1 - when it matters

- `LIMIT 1` on `COUNT(*)` does nothing - COUNT aggregates first, LIMIT truncates the
  one-row result. Both scan all matching rows.
- `SELECT 1 ... LIMIT 1` stops at the first match - genuinely faster on large unindexed
  result sets.
- On indexed columns: identical performance regardless of approach.
- Tested on information_schema.COLUMNS (~13,757 rows):
  - `SELECT COUNT(*)`: 95.2ms
  - `SELECT COUNT(*) LIMIT 1`: 95.7ms (no improvement)
  - `SELECT 1 LIMIT 1`: 77.0ms

### Docblock added to Connection::count()

```php
/**
 * Count rows in a table.
 *
 *     $total = DB::count('users');
 *     $total = DB::count('users', ['status' => 'active']);
 *
 *     // Existence check (0 is falsy, any count is truthy)
 *     if (DB::count('users', ['email' => $email])) { ... }
 *
 *     // For large unindexed result sets, queryOne() is faster because
 *     // COUNT(*) scans all matching rows while this stops at the first:
 *     if (DB::queryOne("SELECT 1 FROM ::users WHERE status = ?", 'active')->isNotEmpty()) { ... }
 */
```

---

## Naming: queryLogger - DECIDED: Keep as-is

Config key for the callable that observes every query: `fn(string $query, float $durationSecs, ?Throwable $error)`.

### Names considered

| Name | Verdict |
|---|---|
| `queryLogger` (current) | **Winner.** Communicates purpose (logging), not just mechanism. |
| `queryHandler` | Matches `loadHandler` convention, but `loadHandler` controls behavior while this just observes. False consistency is worse than honest naming. |
| `onQuery` | Clean event-style, but introduces a new pattern not used elsewhere. |
| `queryLoggerCallback` | Redundant - the fact that it's a callable is obvious from the assignment. |
| `queryLoggerFn` | Same - redundant suffix. |
| `queryListener` | Implies event-system architecture (register/unregister). This is a static config key. |
| `queryCallback` | Generic to the point of meaningless. |
| `queryHook` | "Hook" implies ability to modify behavior. Misleading. |

### Key insight

The different suffix from `loadHandler` is a feature, not a bug. It signals the semantic
difference: `loadHandler` controls behavior (returns data), `queryLogger` observes
(fire-and-forget). A developer seeing `queryHandler` might try to return something from
it expecting to influence query execution.

---

## Feature: DB::clone() - DECIDED: Keep

### Why it exists

Not about "wanting a second connection" - it's about wanting the **same connection**
with different settings. Primary use case: temporarily turning off SmartStrings or
SmartJoins for specific queries.

```php
$raw = DB::clone(['useSmartStrings' => false]);
$rows = $raw->select('users', ['status' => 'active']);
// Same MySQL connection, plain strings instead of SmartString objects
```

Shares the TCP connection (lightweight), only diverges on settings. "Just call
DB::connect() again" would create a whole new MySQL connection - not the same thing.

---

## Refactor: Replace config properties with config() method - DECIDED: Don't do it

### The idea

Replace ~15 private properties and the credential vault with a single `config()` instance
method backed by a static WeakMap. `$this->config('hostname')` to get,
`$this->config('hostname', 'localhost')` to set.

### Debate result: 4-2 against

**For** (User Advocate, Architect):
- Current code has three storage mechanisms for one concern (public props, private props, WeakMap vault)
- `config()` gives one storage model, one access pattern
- `__debugInfo` becomes single-pass instead of reconstructing from three sources
- Credentials hidden by default rather than hidden after the fact

**Against** (Pragmatist, Skeptic, Purist, Veteran):
- **Type safety loss**: `private int $connectTimeout = 3` catches bad values at assignment.
  `config('connectTimeout')` stores `mixed`, fails at use-time (or silently).
- **IDE/static analysis loss**: Can't autocomplete, can't "Find Usages", PHPStan can't
  catch typos. `config('connectTimout')` silently returns null.
- **Clone breaks**: PHP's `clone` copies properties automatically. With WeakMap, the
  cloned object is a new key with no config - needs manual `__clone` reconstruction.
- **Industry trend**: PHP ecosystem moving toward typed properties (constructor promotion,
  readonly), not away from them. WordPress/Laravel config bags are being replaced by
  typed config objects in newer projects.
- **func_num_args() smell**: Using argument count to distinguish get vs set violates
  single-responsibility.

### The better path: surgical fixes

The Purist identified the actual code smells and proposed targeted fixes:

1. **Kill dummy properties**: Replace `property_exists()` validation in constructor with
   an explicit allowlist array. The five credential properties ($hostname, $username,
   $password, $database, $encryptionKey) currently exist only to make `property_exists()`
   work - they're immediately nulled by `sealSecrets()`.

2. **Add SENSITIVE_KEYS constant**: So `__debugInfo` isn't fragile. Currently if you add
   a new sensitive key and forget to add it to the masking list, credentials leak in logs.

3. **Simplify sealSecrets()**: The two code paths (construct vs clone) controlled by
   parameters could be cleaner.

---

## Other ideas discussed but not pursued

- **Schema awareness** (cache table schemas, ignore non-column keys, fuzzy column
  suggestions, auto type-cast): Rejected. Silently ignoring non-column keys hides typos.
  Fuzzy suggestions are an IDE feature. Auto type-casting masks bugs.

- **Query logging methods** (`DB::enableLog()` / `DB::getLog()`): Not needed. The
  `queryLogger` callback in config already handles this. Discoverability is a docs
  problem, not a code problem.

- **Query builders / fluent chaining**: Unanimous rejection across all debates. ZenDB's
  raw SQL with safe parameterization is the right abstraction level.

- **Distributed system features** (connection pooling, caching): ZenDB targets
  single-server sites. That constraint is a feature.

---

## Compared to other PHP HTTP/DB libraries

ZenDB sits in its own lane. Not trying to be fluent-chainable like Laravel's Http client.
Instead it's **compact and convention-driven** - one method call does the right thing:

```php
// ZenDB - fewer calls, more convention
$users = DB::select('users', ['status' => 'active']);
$user  = DB::selectOne('users', ['num' => 5]);
$id    = DB::insert('users', ['name' => 'Dave']);

// vs Laravel-style (more ceremony, more chaining)
$users = DB::table('users')->where('status', 'active')->get();
```

The beauty is in the brevity and the safety rails, not the chain.
