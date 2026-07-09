# Encryption

Set `encryptionKey` in your connection config and every `MEDIUMBLOB` column is
encrypted with AES-128 on `insert()` and `update()` and decrypted on read, with
no changes to your queries. This page covers setup, searching encrypted
columns, the `{{column}}` syntax for decrypting in MySQL, and exactly what this
encryption does and does not protect against.

## Turning Encryption On

Using CMS Builder? Encryption is already integrated: set the key in
Admin > Security, then check "Automatically encrypt data stored in this
column" on each field in the Field Editor. The rest of this page still
applies when you query those columns yourself.

Encryption is off until you set `encryptionKey` at connect time:

```php
DB::connect([
    'hostname'      => 'localhost',
    'username'      => 'dbuser',
    'password'      => 'secret',
    'database'      => 'my_app',
    'encryptionKey' => getenv('DB_ENCRYPTION_KEY'),
]);
```

Store the key outside your code (environment variable or secrets manager).
Like your database password, it's kept in the connection's credential vault, so
it doesn't show up in `var_dump()` output or stack traces, and it's masked as
`********` in logged SQL.

ZenDB decides which columns to encrypt by column type: every `MEDIUMBLOB` is
encrypted, everything else is left alone (including `TINYBLOB`, `BLOB`, and
`LONGBLOB`):

```sql
CREATE TABLE users (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    name     VARCHAR(255),
    apiToken MEDIUMBLOB,     -- encrypted
    ssn      MEDIUMBLOB      -- encrypted
);
```

With the key set, `MEDIUMBLOB` is reserved for encrypted data: every
`MEDIUMBLOB` in every table is encrypted on write and treated as ciphertext
on read. For regular binary data (images, uploaded files), use a neighboring
type ZenDB leaves alone: `BLOB` holds up to 64 KB and `LONGBLOB` up to 4 GB,
either side of `MEDIUMBLOB`'s 16 MB.

Turning on encryption does not encrypt existing rows. If your `MEDIUMBLOB`
columns already hold data, re-encrypt those rows first (read without the key,
write back with it).

## Writing and Reading Encrypted Data

With the key set, nothing about your queries changes. `insert()` and
`update()` encrypt `MEDIUMBLOB` values before they reach MySQL, and every read
method (`select()`, `selectOne()`, `query()`, `queryOne()`) decrypts them in
the results:

```php
DB::insert('users', ['name' => 'Alice', 'apiToken' => 'secret-token-value']);
// INSERT INTO `users` SET `name` = 'Alice', `apiToken` = '<16-byte ciphertext>'

DB::update('users', ['apiToken' => 'new-token'], ['id' => 1]);
// UPDATE `users` SET `apiToken` = '<ciphertext>' WHERE `id` = 1

$user = DB::selectOne('users', ['id' => 1]);
echo $user->apiToken;  // "new-token" - already decrypted
```

`NULL` passes through unencrypted in both directions: a `NULL` token is stored
as `NULL` and read back as `NULL`.

## Searching Encrypted Columns - `DB::encryptValue()`

ZenDB uses AES in ECB mode, which is **deterministic**: the same plaintext with
the same key always produces the same ciphertext. That makes exact-match
searches work: encrypt the search value in PHP with `DB::encryptValue()` and
MySQL compares the stored bytes directly, no decryption needed:

```php
$user = DB::selectOne('users', ['apiToken' => DB::encryptValue('secret-token-value')]);
// WHERE `apiToken` = '<ciphertext>' - byte comparison, nothing decrypted
```

`encryptValue()` produces the same ciphertext that `insert()` and `update()`
generate, so it's also the way to write encrypted values through raw SQL,
where auto-encryption doesn't apply:

```php
DB::query("UPDATE ::users SET apiToken = ? WHERE id = ?", DB::encryptValue('new-token'), 1);
// UPDATE `users` SET apiToken = '<ciphertext>' WHERE id = 1
```

`NULL` input returns `NULL`, and `SmartString` values unwrap automatically.
Calling it on a connection without `encryptionKey` throws `RuntimeException`.

Determinism is also the tradeoff: anyone who can read the table can see which
rows share a value, without knowing what the value is. See
[What This Protects](#what-this-protects) below.

## Decrypting in MySQL with `{{column}}`

Exact match compares bytes, but `LIKE`, string functions, and range
comparisons need the plaintext, which means asking MySQL to decrypt the
column inside the query: an `AES_DECRYPT()` call around every column
reference, typed by hand. ZenDB makes that easier with a shorthand: wrap the
column name in `{{...}}` and it expands to exactly that call:

```php
$users = DB::select('users', "{{apiToken}} LIKE ?", '%token%');
// SELECT * FROM `users` WHERE AES_DECRYPT(`apiToken`, @ek) LIKE '%token%'
```

`{{table.column}}` works too, for joins.

> **Internal detail, safe to ignore:** `@ek` is a MySQL session variable
> holding the key. ZenDB sets it once per connection, before the first query
> that mentions `@ek`, so the key isn't repeated in every statement (and the
> `SET @ek` line is masked as `********` in the query log).

This decrypts every row scanned, so it's slower than exact match and can't use
an index. On large tables, prefer exact match with `encryptValue()` where the
query allows it.

## Decrypting Raw mysqli Results - `DB::decryptRows()`

Every regular ZenDB read method decrypts results automatically; there's
nothing to do. But results fetched through `DB::$mysqli` directly come back
as raw ciphertext, and `decryptRows()` decrypts them in place:

```php
$result = DB::$mysqli->query("SELECT * FROM users");
$rows   = $result->fetch_all(MYSQLI_ASSOC);
DB::decryptRows($rows, $result->fetch_fields());  // detects MEDIUMBLOB columns from field metadata
```

Instead of field metadata you can name the keys yourself: pass column names
for associative rows (`['apiToken', 'ssn']`) or field indexes for numeric rows
(`[2, 3]`). The related helper `DB::getEncryptedColumns($result->fetch_fields())`
returns the detected `MEDIUMBLOB` columns as an array keyed by field index,
e.g. `[2 => 'apiToken', 3 => 'ssn']`.

## When Decryption Fails

A value that fails to decrypt (wrong `encryptionKey`, or the column holds data
that was never encrypted) passes through as its raw bytes, and the first
failure triggers one `E_USER_WARNING` per connection:

```
ZenDB: can't decrypt MEDIUMBLOB column 'apiToken', returning raw bytes. Wrong encryptionKey, or the column holds unencrypted data.
```

One warning, not one per row, so a table of pre-encryption data doesn't flood
the log. If you see this warning, either the key changed or the column still
holds unencrypted rows from before encryption was turned on; both mean stop
and re-encrypt, not ignore.

## How the Keys Line Up

ZenDB uses AES-128-ECB because it's the strongest encryption that works on
every database ZenDB supports: MySQL 5.7+ and MariaDB both implement it as
the `AES_ENCRYPT()` / `AES_DECRYPT()` default, and on most MariaDB versions
it's the only mode those functions offer. Newer servers add CBC and 256-bit
modes, but they aren't available everywhere, they need a per-value IV stored
alongside the data, and a random IV breaks the exact-match search above.
Using the shared default also means PHP-side and MySQL-side produce
identical ciphertext. On the PHP side, `encryptionKey` is hashed with SHA-512
and XOR-folded into a 16-byte AES key. On the MySQL side, `@ek` is set to
`UNHEX(SHA2(key, 512))` and `AES_DECRYPT()` does the same folding internally.
Same effective key both places: data encrypted in PHP decrypts in MySQL and
vice versa.

## What This Protects

This encryption protects a database dump at rest: someone who steals the
`.sql` file or a backup cannot read the encrypted values without the key. Be
clear about where that ends:

- **ECB is deterministic.** Equal plaintexts produce equal ciphertexts, which
  is what makes exact-match search work, and also means an attacker reading
  the table can tell which rows share a value and can spot repeated 16-byte
  blocks inside a value.
- **No integrity check.** ECB is unauthenticated; nothing detects ciphertext
  that was modified in place.
- **One key for everything.** Every `MEDIUMBLOB` on the connection uses the
  same key; there's no per-column opt-out.

If you need protection against an attacker who can compare or tamper with
ciphertext in the live database, encrypt with an authenticated cipher (such as
AES-GCM via `openssl_encrypt()`) in your application before storing, and give
up in-SQL search on those columns.

---

[← Multiple Connections](multiple-connections.md) | [Documentation Index](README.md) | [Next: Security Gotchas →](security-gotchas.md)
