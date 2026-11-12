# Named Locks

Named locks keep two copies of the same job from running at the same time.
They're for work that goes wrong when it overlaps, such as a cron job that
starts again before the last run has finished. This page covers
`DB::withLock()`, which runs a function while holding a lock, how lock names
work, what happens to a lock when its connection ends, and `DB::getLock()`
and `DB::releaseLock()` for work that doesn't fit in one function.

Contents:

- [One Copy of a Job at a Time - `DB::withLock()`](#one-copy-of-a-job-at-a-time---dbwithlock)
- [Lock Names](#lock-names)
- [When the Connection Ends](#when-the-connection-ends)
- [Taking a Lock Yourself - `DB::getLock()` and `DB::releaseLock()`](#taking-a-lock-yourself---dbgetlock-and-dbreleaselock)

## One Copy of a Job at a Time - `DB::withLock()`

`DB::withLock()` runs a function while holding a MySQL named lock. When
another connection holds the lock, the function isn't called and
`withLock()` returns `false`:

```php
$ran = DB::withLock('send_queued_emails', function() {
    foreach (DB::select('email_queue', ['sent' => 0]) as $email) {
        mail($email->toAddress->value(), $email->subject->value(), $email->message->value());
        DB::update('email_queue', ['sent' => 1], ['num' => $email->num]);
    }
});
// in database 'my_db' with tablePrefix 'cms_' this runs:
//   SELECT GET_LOCK('my_db.cms_.send_queued_emails', 0)
//   the function
//   SELECT RELEASE_LOCK('my_db.cms_.send_queued_emails')

if (!$ran) {
    echo "Skipped, still running from last time";
}
```

Without the lock, two copies would both read the same unsent rows and send
every email twice. The lock is released when the function returns or throws.
The function's return value isn't passed back: `withLock()` returns `true`
when the function ran, and you can ignore that when a skipped run needs
nothing done.

A third argument makes `withLock()` wait for the lock. It's how long to wait
for the other copy to finish, not a limit on how long your function runs:

```php
// Wait up to 5 seconds, then give up
$ran = DB::withLock('rebuild_search_index', fn() => rebuildSearchIndex(), 5);
if (!$ran) {
    throw new RuntimeException("The search index is still being rebuilt, try again in a minute");
}
```

## Lock Names

**The name only has to be unique within your site.** ZenDB puts the database
name and `tablePrefix` in front of it and lowercases the result, so the lock
above reaches MySQL as `my_db.cms_.send_queued_emails`. That means:

- Two installs on one MySQL server, with different databases or table
  prefixes, don't share a lock.
- Two web servers that use the same database do.
- Upper and lower case are the same name for the letters A to Z, so `Import`
  and `import` are one lock.

`__FUNCTION__` makes a good name in a named function:

```php
function clearExpiredSessions(): void
{
    DB::withLock(__FUNCTION__, function() {  // the lock name is 'clearExpiredSessions'
        DB::delete('sessions', "expiresAt < NOW()");
    });
}
```

In a method, use `__METHOD__`, which has the class name in it; `__FUNCTION__`
is the method name alone there, so two classes with a `run()` method would
share one lock.

Code that runs inside a closure or arrow function needs a name of its own.
`__FUNCTION__` and `__METHOD__` return a closure name there (`{closure}`, or
`{closure:job():5}` from PHP 8.4), and ZenDB throws for it.

## When the Connection Ends

**The lock belongs to the connection that took it.** A new connection
wouldn't have it, so `DB::disconnect()` throws while you hold one. A lock
still held when the request ends (after `exit`, `die()`, or a fatal error
inside the function) is released then. A persistent `p:` connection stays
open after the request, and in three cases its lock waits until the
connection is next reused (see
[Persistent Connections](persistent-connections.md#a-crash-mid-transaction-blocks-others-briefly)).
If the lock is already gone when the
function returns, because the server closed the connection during the job,
`withLock()` throws `RuntimeException`, since another copy may have started
in the meantime. Named locks work when every connection that asks for the
lock goes to the same MySQL server.

## Taking a Lock Yourself - `DB::getLock()` and `DB::releaseLock()`

When the locked work doesn't fit in one function, `DB::getLock()` and
`DB::releaseLock()` take and release the same locks:

```php
if (!DB::getLock('import_products')) {  // true when this connection now holds the lock
    exit("Another import is running");
}
try {
    foreach ($csvRows as $row) {
        DB::insert('products', ['sku' => $row['sku'], 'name' => $row['name']]);
    }
} finally {
    DB::releaseLock('import_products');  // true when released, false when this connection didn't hold it
}
```

`getLock()` takes the same wait as `withLock()` does:
`DB::getLock('import_products', 5)` waits up to 5 seconds. A name taken twice
on one connection needs two releases.

---

[← Encryption](encryption.md) | [Documentation Index](README.md) | [Next: Security Gotchas →](security-gotchas.md)
