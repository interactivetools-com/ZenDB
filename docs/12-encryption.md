# Encryption

ZenDB transparently encrypts and decrypts column data using AES. Once
configured, `insert()` and `update()` auto-encrypt, all query methods
auto-decrypt. No query changes needed.


## Setup

### 1. Add an Encryption Key

Pass `encryptionKey` in your connection config:

```php
DB::connect([
    'hostname'      => 'localhost',
    'username'      => 'dbuser',
    'password'      => 'secret',
    'database'      => 'my_app',
    'encryptionKey' => getenv('DB_ENCRYPTION_KEY'),
]);
```

The key is stored in a WeakMap vault with your other credentials. It never
appears in `var_dump()`, `print_r()`, or stack traces.

Store your encryption key outside of code (environment variable, `.env` file,
or secrets manager). Don't hardcode it.

### 2. Use MEDIUMBLOB Columns

ZenDB identifies encrypted columns by their MySQL column type. Any column
defined as `MEDIUMBLOB` is treated as encrypted:

```sql
CREATE TABLE users (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(255),
    token MEDIUMBLOB,      -- encrypted
    ssn   MEDIUMBLOB       -- encrypted
);
```

Only `MEDIUMBLOB` triggers encryption. `BLOB`, `TINYBLOB`, and `LONGBLOB`
are left alone.

**Without an encryption key:** If you have MEDIUMBLOB columns but no
`encryptionKey` configured, data is stored and read as plaintext. Encryption
is a no-op until a key is provided, and adding a key later won't decrypt
existing rows.

**Note:** ZenDB uses AES-128-ECB for MySQL compatibility. Identical
plaintexts produce identical ciphertexts, which enables exact-match search
but means an observer could detect duplicate values. For most application
data (tokens, SSNs, API keys), this is an acceptable trade-off. See
[How It Works](#how-it-works) for details.

## Automatic Encryption and Decryption

### Writing Data

`DB::insert()` and `DB::update()` detect MEDIUMBLOB columns and encrypt
their values automatically:

```php
// 'token' is auto-encrypted because it's a MEDIUMBLOB column
DB::insert('users', [
    'name'  => 'Alice',
    'token' => 'secret-token-value',
]);

// 'token' is auto-encrypted here too
DB::update('users', ['token' => 'new-token'], ['id' => 1]);
```

Non-MEDIUMBLOB columns like `name` pass through unchanged. NULL values
are stored as NULL (not encrypted).

### Reading Data

All query methods (`select()`, `selectOne()`, `query()`, `queryOne()`)
auto-decrypt MEDIUMBLOB columns in the result:

```php
$user = DB::selectOne('users', ['id' => 1]);
echo $user->token;  // "secret-token-value" - already decrypted
```

If a MEDIUMBLOB column contains data that wasn't encrypted (for example,
pre-existing binary data), ZenDB leaves it as-is. Decryption only replaces
values that successfully decrypt.

## Searching Encrypted Columns

### Exact Match

To find rows where an encrypted column equals a specific value, encrypt the
search term with `DB::encryptValue()`:

```php
$users = DB::select('users', ['token' => DB::encryptValue('secret-token-value')]);
```

This works because identical plaintext with the same key always produces
identical ciphertext, so MySQL can compare the raw bytes directly. This is
fast and uses indexes normally.

### Partial Match (LIKE)

Encrypted columns store raw bytes, so LIKE can't match against plaintext
characters directly. The `{{column}}` syntax decrypts in MySQL before
comparing:

```php
$users = DB::select('users', "{{token}} LIKE ?", '%secret%');
```

`{{token}}` expands to `AES_DECRYPT(`token`, @ek)`, which decrypts the
column on the MySQL side so LIKE, comparison operators, and other string
functions work normally. The `@ek` session variable is set automatically
the first time a query uses it.

This is slower than exact match because MySQL must decrypt every row to
compare. For large tables, consider whether partial matching on encrypted
data is really needed.

You can also write the `AES_DECRYPT` call directly in raw queries:

```php
$users = DB::query(
    "SELECT *, AES_DECRYPT(`token`, @ek) AS token_plain
       FROM ::users
      WHERE AES_DECRYPT(`token`, @ek) LIKE ?",
    '%secret%'
);
```

For details on `{{column}}` and `::table` prefix syntax, see
[Placeholders & Parameters](05-placeholders-and-parameters.md).

## Manual Encryption and Decryption

For raw queries or direct `$mysqli` access where auto-encrypt/decrypt
doesn't apply.

### `DB::encryptValue()` - Encrypt a Single Value

```php
// Exact-match search
$users = DB::select('users', ['token' => DB::encryptValue($searchToken)]);

// Raw SQL insert via DB::query()
DB::query("UPDATE ::users SET token = ? WHERE id = ?", DB::encryptValue($token), $id);
```

NULL input returns NULL. SmartString values are unwrapped automatically.
Throws `RuntimeException` if no `encryptionKey` is configured.

### `DB::decryptRows()` - Decrypt Raw Results

If you bypass ZenDB's query methods and use `$mysqli->query()` directly,
you can still decrypt the results:

```php
$result = $mysqli->query("SELECT * FROM users");
$rows   = $result->fetch_all(MYSQLI_ASSOC);
DB::decryptRows($rows, $result->fetch_fields());
// $rows now has decrypted values
```

### `DB::getEncryptedColumns()` - Detect Encrypted Columns

Returns the column names identified as encrypted (MEDIUMBLOB) from field
metadata:

```php
$result = $mysqli->query("SELECT * FROM users LIMIT 0");
$cols   = DB::getEncryptedColumns($result->fetch_fields());
// e.g., ['token', 'ssn']
```

## How It Works

ZenDB uses AES-128-ECB to match MySQL's built-in `AES_ENCRYPT()` /
`AES_DECRYPT()`. PHP-side encryption and MySQL-side decryption produce
identical results.

**Key derivation:** On the PHP side, the encryption key is hashed with
SHA-512 and XOR-folded into a 16-byte AES key. On the MySQL side, the raw
SHA-512 hash is passed to `AES_DECRYPT()`, which does its own internal
folding. The end result is the same key, so data encrypted in PHP can be
decrypted in MySQL and vice versa.

**ECB mode** means identical plaintexts produce identical ciphertexts with
the same key. This enables exact-match search but also means an attacker who
sees encrypted data can tell when two values are the same. For most
application data (tokens, SSNs, API keys), this is acceptable. For data
where patterns matter (such as images), it is not.

**NULL handling:** NULL values pass through unencrypted in both directions.

---

[← Back to README](../README.md) | [← Method Reference](11-method-reference.md)
