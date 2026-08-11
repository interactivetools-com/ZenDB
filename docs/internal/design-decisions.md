# ZenDB Design Decisions

Settled decisions with their rationale. Check here before proposing a feature,
a rename, or a refactor: if it has a heading below, it was already debated.
Decisions can be reopened, but reopen them against the reasons recorded here,
not from scratch.

Only the road-not-taken half lives here; current behavior is self-documenting
in signatures, docblocks, the changelog, and tests.

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

Also ruled out as a docs recipe (2026-07): CMS Builder has no easy way to
create a multi-column unique index, so the main audience can't meet the
precondition. The one-liner above stays here, not in common-patterns.

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
again throws (already connected), and `new Connection()` opens a whole new
MySQL connection, which is not the same thing.

---

## Config Storage - DECIDED: Keep typed properties + credential vault (2026-03)

A proposal to replace the typed config properties and WeakMap credential vault
with a single `config()` get/set method was rejected: it trades type safety,
IDE support, and static analysis for uniformity, and breaks PHP's automatic
property copying on clone. Config stays as typed properties.

Cleanups worth doing if this code is ever touched (still open as of 2026-07):

1. Replace `property_exists()` constructor validation with an explicit allowlist.
   Note: the five credential properties never reach that check; `sealSecrets()`
   consumes their config keys first. They exist so the `$this->$key = null`
   write in `sealSecrets()` isn't a deprecated dynamic property on PHP 8.2+,
   so an allowlist doesn't make them deletable.
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

## Positional Parameters - DECIDED: Allowlist, max 3 direct (2026-07)

Params are valid as (1) up to 3 direct non-array values for `?` placeholders,
or (2) one array of `':name' => value` pairs. Positional values passed in a
single array raise `E_USER_DEPRECATED`; the max-3 error text points to named
placeholders. Unused NAMED params stay allowed so param arrays can be shared
across queries; known accepted cost: a named value whose SQL half was
forgotten goes unwarned.

---

## Smart Join Alias Keys - DECIDED: Self-joins only (2026-07)

Row keys for aliased tables use the base table name (`get('accounts.name')`),
not the alias (`get('a.name')`); alias keys exist only in self-joins, where
they're the only way to disambiguate. Rationale: base-table keys force more
readable template code. Memory cost was measured and ruled out as a factor
(extra keys share value zvals via copy-on-write, ~36 bytes/row per key); the
deciding factors were template readability and `print_r()` noise (a 3-table
`SELECT *` join already yields ~79 keys).

---

## Encrypted-Column Qualifiers - DECIDED: `::` works inside `{{}}` (2026-07)

`{{...}}` contents are SQL, not a method argument: write the column reference
exactly as you would without encryption, then wrap it in braces. `::` applies
`tablePrefix` inside the braces just as it does outside (`{{::users.apiToken}}`
matches `FROM ::users`); alias and already-prefixed qualifiers stay as written
(`{{u.apiToken}}`).

Auto-prefixing the table half (matching how `select('users')` takes base
names) was rejected: the expansion can't tell an alias from a table name, so
`FROM ::users u ... {{u.apiToken}}` would become `cms_u.apiToken` and break
the common join form. Guessing from the schema is the schema-awareness magic
rejected under Other Ideas Rejected. Method arguments take base names because
the whole argument is a table name by definition; inside SQL text, `::` is
the marker, and `{{}}` lives inside SQL text.

---

## Dotted Table Prefixes - DECIDED: Banned (2026-08)

CMS Builder's installer used to accept prefixes like `client2.cms_`, naming
tables literally (`client2.cms_news`, dot included - CMS Builder quotes full
names in one backtick pair, so that's the only reading that ever worked
there). Full literal-dot support was built and working (validation, `::`
emitting backticked names, `{{::table.column}}`, the test suite on a dotted
default prefix), then reverted after researching what the rest of the world
does:

- WordPress hit this exact issue in 2004 (Trac #910) and hard-bans dots:
  "$table_prefix can only contain numbers, letters, and underscores".
- Drupal supports dotted prefixes with the OPPOSITE meaning: `'shared.'` is
  a `database.` qualifier for cross-database table sharing, and identifier
  quoting for MySQL 8 broke it (#3186120) - the same collision from the
  other side.
- Laravel, Doctrine, and CodeIgniter all read a dot in a name as a
  qualifier. No mainstream library treats it as a literal prefix character.

So a dot in a prefix is ambiguous on arrival - a user writing `'shared.'`
more likely means cross-database than literal-dot tables - and ZenDB refuses
at config time instead of guessing. Existing dotted installs migrate with
RENAME TABLE (steps in UPGRADING.md), reviewed as part of the CMS Builder
upgrade notes.

Kept from the exploration (correct regardless of dots):

- Base names are validated before the prefix is added; `columns()`,
  `hasColumn()`/`columnNames()`, and the FOREIGN KEY methods fail fast on
  invalid names like the other schema helpers.
- `exists()` and `existsFull()` throw for invalid names, dots included,
  same as every other method that takes a table name. A dot carve-out for
  existsFull() was considered and dropped: it was really motivated by our
  own dotted-prefix support, existsFull() already answered false for other
  legal MySQL names like `my table`, and both probes only ever see the
  current database (clone() can't switch databases; a clone shares the
  mysqli connection, so `USE` would switch the original too). Checking a
  name ZenDB couldn't have created is an information_schema one-liner,
  shown in the existsFull() docblock. The deprecated hasTable() and
  tableExists() shims keep answering false for invalid names.
- `decryptExpr()` takes one dot at most (column or table.column); a second
  dot would build a database-qualified reference no caller means.

---

## HTML Composition - DECIDED: SmartString's appendHtml()/wrapHtml() are the answer (2026-08)

The question started here: templates wrapping a query-result field in markup
only when the field has a value. The ruling lives with the string type:
SmartString's `appendHtml()`/`wrapHtml()` cover the idiom (missing value
returns "", so the whole wrapper vanishes), and result fields are
SmartStrings so they get both directly - ZenDB adds nothing. Rejected across
the family: a SmartHtml type, encode-on-append, entity-sniffing, and further
`*Html()` name variants. If richer safe-HTML composition is ever needed, the
design is a dedicated safe-HTML type (details in SmartString's
design-decisions entry).

---

## Undocumented on Purpose - DECIDED (2026-07)

The docs deliberately omit these; the omission is a decision, not a gap
(method-reference says "every supported method" for this reason):

- **`Server`, `Table`/`TableInfo`** - internal, may change; class headers say so.
- **`DB::assertIdentifier()`** - `@internal`; the safe-identifier check every table
  and column name passes through, kept callable for code that builds SQL outside
  templates (alongside `escape()`/`escapef()`), where backtick placeholders
  aren't available.
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
- **`DB::connection()`, `DB::phpTimezoneForMysql()`** - `@internal` (2026-07):
  plumbing for code that already knows the internals, not regular-use methods.
  Rule of thumb: methods living in DBInternals.php are internal unless ruled
  otherwise.

---

## Deprecation Notices - DECIDED: The `@` on `trigger_error()` stays (2026-08)

`DBDeprecations::logDeprecation()` sends notices as `@trigger_error(...)`. The
`@` mutes PHP's own display *and* its logging, so only a `set_error_handler()`
ever sees them. That is the intent: notices are for handlers that collect them
(CMS Builder's developer log), never for page output or PHP's default error
log. Don't remove the `@` to make PHP log them.

SmartString and SmartArray follow the same rule and state it at the call site;
ZenDB's `logDeprecation()` has no comment saying so.

---

## Empty-Quotes Gap - DECIDED: Keep allowing `''` (2026-08)

`assertSafeTemplate()` strips `''` and `""` before the quote check, so
`WHERE name != ''` runs. That exception is what lets a balanced payload
through: `' OR name=name #'` interpolated into `WHERE name = '$name'` reaches
the guard as two empty-string literals and runs as a tautology. Documented in
[The Empty-Quotes Gap](../security-gotchas.md). Raised again by a 2026-08
security scan, which proposed rejecting every quote and requiring `''` from a
placeholder.

Not doing it. The gap needs one specific shape, and it's the only shape that
survives development:

| Developer wrote | blank value | real value | balanced payload |
| --- | --- | --- | --- |
| `WHERE name = $name` | SQL syntax error | Unknown column 'Alice' | runs |
| `WHERE name = '$name'` | 0 rows, looks fine | guard throws | runs |

Unquoted interpolation is broken for every value a developer would test with,
so it can't ship. Quoted interpolation throws the moment a real value arrives.
What's left is a field that is always blank until a user fills it, such as an
optional filter, where the throw never fires. Digits still throw (`' OR 1=1 #'`
is caught by the standalone-number check) and mysqli refuses a second
statement, so the payload has to be digit-free and single-statement too.

Closing it costs more than it buys: CMS Builder core alone has `= ''` and
`!= ''` in `upload_functions.php`, `upgrade_functions.php`,
`viewer_functions.php`, and two plugins, all of which would start throwing on
upgrade, plus every customer template and third-party plugin we can't see or
fix.

The guard is a tripwire, not a parser. It reads the finished SQL string and
can't tell which characters the developer typed and which came from a
variable. It catches the common mistake early and loudly; placeholders are
what make interpolation safe. Same reason the identifier gap
(`ORDER BY $sort`) exists and is documented next to this one.

---

## Other Ideas Rejected (2026-03)

- **Schema awareness** (cache table schemas, ignore non-column keys, fuzzy column
  suggestions, auto type-cast): silently ignoring non-column keys hides typos,
  fuzzy suggestions are an IDE feature, auto type-casting masks bugs. (The
  narrow encrypted-column type lookup that encryption uses is separate and
  stands.)
- **Query logging methods** (`DB::enableLog()` / `DB::getLog()`): the
  `queryLogger` config callback already handles this.
- **Distributed system features** (connection pooling, caching): ZenDB targets
  single-server sites. That constraint is a feature.
