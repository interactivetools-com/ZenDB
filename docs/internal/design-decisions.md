# ZenDB Design Decisions

Settled decisions with their rationale. Check here before proposing a feature,
a rename, or a refactor: if it has a heading below, it was already debated.
Decisions can be reopened, but reopen them against the reasons recorded here,
not from scratch.

---

## Design Philosophy

- **Convention over configuration** - sensible defaults, minimal setup
- **Safety by default** - injection-proof templates (`assertSafeTemplate` rejects quotes/numbers),
  XSS-safe output via SmartString, credential vault prevents serialization leaks
- **Minimal ceremony** - one method call does the right thing
- **Transparent complexity hiding** - auto-encryption for MEDIUMBLOB columns is the gold standard:
  the developer doesn't need to know anything about AES or key derivation
- **Thin layer over MySQL** - developers write SQL when they need SQL, the library handles
  escaping, prefixing, and result wrapping
- **Not an ORM** - no query builders, no relationship mapping, no fluent chaining.
  Query builders were rejected unanimously; raw SQL with safe parameterization is
  the abstraction level ZenDB wants.

---

## DB::upsert() - DECIDED: Not adding (2026-03)

Would wrap MySQL's `INSERT ... ON DUPLICATE KEY UPDATE`.

1. **Requires a unique index to work.** A developer who can set up a composite
   unique index can write the SQL.
2. **Not a repetitive pattern.** You write it once per table, not fifty times a
   day.
3. **Already a one-liner with existing tools:**
   ```php
   DB::query("INSERT INTO ::settings SET `user_id` = ?, `key` = ?, `value` = ?
              ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", $userId, $key, $val);
   ```
4. **Slippery slope** to `insertIgnore()`, `replace()`, and other MySQL-specific
   variants.
5. **Doesn't match the transparency philosophy.** Auto-encryption works because the
   developer doesn't need to understand AES. Upsert still requires understanding
   unique indexes and conflict semantics; it only shortens the syntax.

---

## DB::exists() - DECIDED: Not adding (2026-03)

`if (DB::count('users', ['email' => $email]))` already works (0 is falsy), and
the `count()` docblock shows both this and the optimized form. Performance facts,
measured on ~13,757 rows:

- `COUNT(*)` scans all matching rows; adding `LIMIT 1` does nothing (95.2ms vs 95.7ms).
- `SELECT 1 ... LIMIT 1` stops at the first match: 77.0ms on the same data.
- On indexed columns (the usual existence-check target) all forms perform the same.

```php
// The optimized form for large unindexed result sets, already a one-liner:
if (DB::queryOne("SELECT 1 FROM ::users WHERE email = ?", $email)->isNotEmpty()) { ... }
```

---

## Naming: queryLogger - DECIDED: Keep (2026-03)

Considered `queryHandler`, `onQuery`, `queryListener`, `queryHook`, and callback-
suffixed variants. Kept `queryLogger` because the suffix difference from
`loadHandler` is deliberate: `loadHandler` controls behavior (returns data),
`queryLogger` observes (fire-and-forget). Matching the names would invite
returning something from the logger expecting to influence the query.

---

## DB::clone() - DECIDED: Keep (2026-03)

Not about wanting a second connection: it's the **same connection** with
different settings. Primary use case is turning off SmartStrings or SmartJoins
for specific queries:

```php
$raw = DB::clone(['useSmartStrings' => false]);
$rows = $raw->select('users', ['status' => 'active']);
// Same MySQL connection, plain strings instead of SmartString objects
```

Shares the TCP connection; only the settings diverge. Calling `DB::connect()`
again would open a whole new MySQL connection, which is not the same thing.

---

## Config storage - DECIDED: Keep typed properties + credential vault (2026-03)

A proposal to replace the typed config properties and WeakMap credential vault
with a single `config()` get/set method was rejected: it trades type safety,
IDE support, and static analysis for uniformity, and breaks PHP's automatic
property copying on clone. Config stays as typed properties.

Cleanups worth doing if this code is ever touched (still open as of 2026-07):

1. Replace `property_exists()` constructor validation with an explicit allowlist;
   the five credential properties exist only to satisfy it and are immediately
   nulled by `sealSecrets()`.
2. Add a `SENSITIVE_KEYS` constant so `__debugInfo` masking can't silently miss
   a newly added sensitive key.
3. Simplify `sealSecrets()`'s two parameter-controlled code paths (construct vs
   clone).

---

## Naming: rawHtml() - DECIDED: Only name for unencoded output (2026-07)

`rawHtml()` is the single callable name for skipping HTML-encoding; no aliases.
"Trusted"/"safe" names claim something the method can't verify (the
mark_safe/html_safe failure mode: the name promises safety the caller may not
have established). "Raw" names the actual behavior and matches `DB::rawSql()`.
One name keeps an XSS audit to a single grep token. SmartString's error hints
catch attempts to call `unsafe()`, `unescaped()`, `trusted()`, `trustedHtml()`,
`unsafeHtml()`, `raw()`, and `html()` and suggest `rawHtml()`.

---

## Positional parameters - DECIDED: Allowlist, max 3 direct (2026-07)

Params are valid as (1) up to 3 direct non-array values for `?` placeholders,
or (2) one array of `':name' => value` pairs. Positional values passed in a
single array log `E_USER_DEPRECATED`; the max-3 error text points to named
placeholders. Unused NAMED params stay allowed so param arrays can be shared
across queries; known accepted cost: a named value whose SQL half was
forgotten goes unwarned.

---

## Smart Join alias keys - DECIDED: Self-joins only (2026-07)

Row keys for aliased tables use the base table name (`get('accounts.name')`),
not the alias (`get('a.name')`); alias keys exist only in self-joins, where
they're the only way to disambiguate. Rationale: base-table keys force more
readable template code. Memory cost was measured and ruled out as a factor
(extra keys share value zvals via copy-on-write, ~36 bytes/row per key); the
deciding factors were template readability and `print_r()` noise (a 3-table
`SELECT *` join already yields ~79 keys).

---

## SmartString selectedIf()/checkedIf() - DECIDED: Parked (2026-07)

Nothing in the existing `if()`/`ifBlank()` family fits the selected/checked
shape (their false branch keeps the original value). A template-local closure
covers the need:

```php
$selectedIf = fn($v) => (string)$v === (string)$sel ? ' selected' : '';
```

Revisit only if real demand shows up. If ever built: string-cast strict
compare (like CMS Builder's html_functions.php) and bare HTML5 `selected`
output.

---

## Undocumented on purpose - DECIDED (2026-07)

The docs deliberately omit these; the omission is a decision, not a gap
(method-reference says "every supported method" for this reason):

- **`Server`, `Table`/`TableInfo`** - internal, may change; class headers say so.
- **`DB::assertIdentifier()`** - `@internal`; the safe-identifier check every table
  and column name passes through, kept callable for identifiers placeholders can't
  cover (like a user-picked sort column).
- **`queryLogger`, `loadHandler` config keys** - internal/advanced hooks
  (loadHandler is CMS Builder plumbing); undocumented keeps the signatures
  changeable. The PII note (logged SQL contains inlined user values) lives in
  the queryLogger docblocks.
- **`escape()`, `escapef()`, `escapeCSV()`** - `@internal`, exist so ZenDB and
  CMS Builder can build their own SQL; placeholders are the supported API.
  Docblocks open with "Internal use, undocumented by design."
- **`get()` with a default argument** - de-emphasized; the default applies only
  to missing keys, never stored nulls, which misleads more than it helps in
  docs examples.

---

## Other ideas rejected (2026-03)

- **Schema awareness** (cache table schemas, ignore non-column keys, fuzzy column
  suggestions, auto type-cast): silently ignoring non-column keys hides typos,
  fuzzy suggestions are an IDE feature, auto type-casting masks bugs.
- **Query logging methods** (`DB::enableLog()` / `DB::getLog()`): the
  `queryLogger` config callback already handles this.
- **Distributed system features** (connection pooling, caching): ZenDB targets
  single-server sites. That constraint is a feature.
