# Security Gotchas

ZenDB is safe by default. Values go through placeholders, results HTML-encode
themselves on output, and templates with inline values are rejected. You get
that protection without doing anything special. This page documents the narrow
cases that still let you write an unsafe query, all of which require working
against the library's design, and shows the safe form for each.

If you use placeholders for values, backtick placeholders for identifiers, and
leave output encoding on, none of this applies to you.

## Escape Hatches - `DB::$mysqli` and `rawHtml()`

Two escape hatches deliberately step outside the protection: `DB::$mysqli`
skips the SQL guard, and `rawHtml()` skips output encoding. Both exist because
sometimes you need direct access, and both are named so they stand out in a
code review.

### Raw Queries - `DB::$mysqli`

`DB::$mysqli` exposes the underlying mysqli connection. Anything you send
through it runs as-is, with none of the library's protection: no template
guard, no placeholder requirement, and the results are plain mysqli rows with
no HTML-encoding.

```php
$name = $_GET['name'];

// WRONG - raw SQL with user input concatenated in, no guard, no escaping
DB::$mysqli->query("SELECT * FROM users WHERE name = '$name'");

// RIGHT - go through the library, the value can only enter via a placeholder
DB::query("SELECT * FROM ::users WHERE name = ?", $name);
```

The raw handle is a deliberate escape hatch: sometimes you need direct access,
and ZenDB does not lock you out of it. Anyone set on writing raw queries can,
through `DB::$mysqli` or by ignoring the library and calling mysqli or PDO
directly. No library can stop you from concatenating a string and handing it
to the server.

That is the point of ZenDB, though: on the normal path (`DB::query()`,
`DB::select()`, and friends), values only enter through placeholders and
inline values are rejected, so injection is hard to write even by accident.
You have to step off that path on purpose to open a hole. Stay on it and
injection is not something you have to think about.

### Unencoded Output - `rawHtml()`

Every value from ZenDB HTML-encodes itself in string context, so normal output
is XSS-safe without effort. `rawHtml()` is the single exception: it returns
the value unencoded.

```php
echo $row->bio;            // HTML-encoded, safe for any user-supplied value
echo $row->bio->rawHtml(); // raw, unencoded, only for HTML you control and trust
```

Call `rawHtml()` only on HTML you control and trust (a sanitized rich-text
field, a template fragment you built). Never call it on raw user input,
because it removes the encoding that prevents XSS. To read the underlying
value for logic rather than output, use `value()`; `rawHtml()` is an alias of
it, named for the output case.

`rawHtml()` is deliberately the only name for unencoded output, matching
`DB::rawSql()`, the parallel opt-out on the SQL side. One name per opt-out
keeps an audit to one search each: grep for `rawHtml(` to find every
deliberate unencoded output point, and `rawSql(` to find every value that
enters SQL unescaped (user input must never reach it). One caveat for the
output grep: `value()` and `string()` return the same raw data for use in
logic, so echoing their results also outputs unencoded; the grep finds the
deliberate opt-outs, not misused reads.

## The Empty-Quotes Gap

The template guard rejects quotes, so interpolating a value into quotes you
wrote yourself throws the first time it runs with real data:

```php
$name = $_GET['name']; // "Vancouver"

// This throws - the interpolated value puts quotes in the template
DB::query("SELECT * FROM ::users WHERE name = '$name'");
// Throws: Quotes not allowed in template. Replace 'Vancouver' with :paramName and add: [ ':paramName' => 'Vancouver' ]

// The placeholder form
DB::query("SELECT * FROM ::users WHERE name = ?", $name);
```

You can't ship the wrong form by accident, because it breaks the moment any
real value reaches it. That is the guard working as designed.

There is one gap. The guard allows the empty-string literal `''` (a common,
harmless thing to write, as in `WHERE name != ''`). An attacker whose value
lands inside your quotes can supply their own balanced quote pairs so the
whole expression looks, to the guard, like nothing but empty strings, and it
passes. For example `$name` set to `' OR name=name #'` produces
`... WHERE name = '' OR name=name #''`, a tautology that returns every row.

For that to actually reach production, all of the following have to be true
at once:

- You built the query by interpolating untrusted input into quotes instead of
  using a placeholder (the mistake above).
- That exact code path never ran with a non-empty value during development,
  because a real value throws immediately and loudly. The only way it stays
  quiet is if the field is always empty until a user fills it, for example an
  optional filter that defaults to `''`.
- An attacker then finds that field and constructs a precisely quote-balanced
  payload.

In other words, you have to write it the wrong way, never once exercise it
with real data, ship it, and be targeted. Using a placeholder removes the
whole chain.

```php
// If you need the "is not empty" idiom, use a placeholder for the empty string too
DB::query("SELECT * FROM ::users WHERE name != :empty", [':empty' => '']);
```

## Dynamic Identifiers - ORDER BY and Column Names

The template guard inspects *values* (numbers, quoted strings, literals). It
does not inspect identifiers or keywords, because it has no schema to check
them against. So interpolating user input into an identifier position slips
past it.

```php
// WRONG - $sort is an identifier, the guard does not validate it
$sort = $_GET['sort'] ?? 'name';
DB::query("SELECT * FROM ::members ORDER BY $sort");
```

A value placeholder does not fit here: `ORDER BY 'name'` orders by a constant
string, so the query runs but sorts nothing, silently.
Use the backtick identifier placeholder, which accepts only identifier
characters (letters, numbers, `_`, `-`) and throws on anything with spaces,
quotes, parentheses, or other punctuation:

```php
// RIGHT - backtick placeholder validates the identifier
DB::query("SELECT * FROM ::members ORDER BY `:sort`", [':sort' => $sort]);
```

For a direction (`ASC`/`DESC`) or a fixed set of sortable columns, map the
input to a known-good value in PHP before it reaches SQL:

```php
$column    = ['name', 'date', 'city'][$_GET['sortIndex'] ?? 0] ?? 'name';
$direction = ($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
DB::query("SELECT * FROM ::members ORDER BY `:col` " . $direction, [':col' => $column]);
```

## Encryption Threat Model

When `encryptionKey` is set, ZenDB encrypts every `MEDIUMBLOB` column with
AES-128-ECB, matching MySQL's `AES_ENCRYPT()`, so values encrypted in PHP can
be decrypted in MySQL with the `{{column}}` shorthand (setup and searching
are covered in [Encryption](encryption.md)). That compatibility comes with
tradeoffs worth knowing:

- **ECB is deterministic.** The same plaintext always encrypts to the same
  ciphertext. That is what makes `WHERE token = DB::encryptValue($token)`
  work, but it also means an attacker who reads the table can tell which rows
  share a value, and can spot repeated 16-byte blocks within a value.
- **No integrity.** ECB is unauthenticated, so ciphertext can be tampered
  with. There is no built-in check that a value was not altered.
- **The key is global to all MEDIUMBLOBs.** There is no per-column opt-out. A
  `MEDIUMBLOB` holds either encrypted or raw data, not a mix. If you turn
  encryption on, do not also store non-encrypted binaries (images, files) in a
  `MEDIUMBLOB`, they will be treated as ciphertext.

This protects a database dump at rest: someone who steals the `.sql` file
cannot read the values without the key. It does not protect against an
attacker who can compare or modify ciphertext in place. If you need that,
encrypt with an authenticated cipher in your application before storing.

---

[← Encryption](encryption.md) | [Documentation Index](README.md) | [Next: Troubleshooting →](troubleshooting.md)
