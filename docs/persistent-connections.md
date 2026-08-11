# Persistent Connections

Opening a MySQL connection costs TCP setup and an authentication handshake
on every request, plus TLS negotiation when `requireSSL` is on. A persistent
connection skips that: each PHP worker keeps its connection open between
requests and reuses it, replacing the whole setup sequence with a single
reset command.

> **Before you read on:** this page is intentionally not linked from the
> documentation index. Persistent connections are an advanced configuration
> with real gotchas: they only pay off in certain setups, the sizing math
> has to be done before enabling them, and afterward someone needs to watch
> the server (connection counts, the process list) and be able to reload
> PHP, or KILL leftover connections in MySQL, if the sizing turns out
> wrong. Plain hostnames are the right default. If you do want this, run
> [the sizing check](#the-sizing-check) first and enable it with
> [the one-time trial](#the-one-time-trial), at a moment when you can
> reload PHP.

Contents:

- [Should You Use It](#should-you-use-it)
- [Enabling It](#enabling-it)
- [Reuse Stays Clean](#reuse-stays-clean)
- [The Sizing Check](#the-sizing-check)
- [The One-Time Trial](#the-one-time-trial)
- [Timeouts](#timeouts)
- [Turning It Off](#turning-it-off)
- [Good to Know](#good-to-know)

## Should You Use It

**Use it when:**

- **The database is on another host, or the connection has `requireSSL` on.**
  Fresh setup costs several network round trips plus TLS negotiation; reuse
  costs one round trip, saving several milliseconds on every request.
- **The site is busy.** Measured locally, connecting dropped from 447µs to
  131µs and a typical page (connect, three queries, disconnect) finished
  about 0.3ms sooner; the saving multiplies by request volume.

**Skip it when:**

- **The code runs from CLI or cron.** The reused connection lives inside a
  PHP worker process, and a script's process exits when it finishes, taking
  its connection with it. No benefit.
- **You are on shared hosting with a low `max_user_connections` cap.** Hosts
  often cap connections at 20-50 per account, which a modest worker pool
  fills by itself. If the cap is at or below your worker count, stick with
  the plain hostname; exceeding it produces "User has exceeded
  max_user_connections" errors for every request over the limit. Some hosts
  also disable persistent connections entirely (`mysqli.allow_persistent`
  off in php.ini); the prefix then downgrades to a normal connection and
  PHP raises a warning ("Persistent connections are disabled. Downgrading
  to normal") on every connect.

**Before you start:**

- Run [the sizing check](#the-sizing-check) below. Getting it wrong fills
  the server's connection caps.
- Confirm you can reload PHP, and find your exact command now, not during
  an outage. The reload is both part of the trial and the instant undo.
  Typical forms:
  - `sudo systemctl reload php8.3-fpm` - PHP-FPM; the service name varies
    by PHP version and distro, and `systemctl list-units | grep fpm` finds
    yours.
  - `sudo apachectl graceful` - Apache with mod_php.
  - Your hosting panel's option to restart PHP or PHP-FPM.

## Enabling It

Prefix the hostname with `p:` and the connection becomes persistent. This is
mysqli's own syntax; there is no separate config option, and it works the
same in `DB::connect()` and `new Connection()`:

```php
DB::connect([
    'hostname' => 'p:localhost',  // the "p:" prefix is the whole change
    'username' => 'myuser',
    'password' => 'mypassword',
    'database' => 'mydatabase',
]);
```

Queries, results, and transactions behave identically either way; the prefix
changes how the connection is obtained, nothing about how queries run.

## Reuse Stays Clean

The natural worry is inheriting the previous request's state: its
transaction, its temp tables, its variables. That does not happen.

- **mysqli resets the connection before handing it out.** The reset command
  (`COM_CHANGE_USER`) makes the server roll back any open transaction,
  release locks, drop temporary tables, deallocate prepared statements, and
  clear user and session variables.
- **ZenDB reapplies its connect-time setup.** The reset carries over one
  thing, the previous session's charset; ZenDB's connect-time check resets
  it to utf8mb4, so `sqlMode`, the session timezone, and the charset all
  match a fresh connection exactly.
- **Encryption keys don't leak.** With `encryptionKey` set, the same reset
  clears the session key variable, and it is set again on the next query
  that needs it.
- **Verified on all 22 supported servers.** Permanent probes in ZenDB's CI
  behavior matrix confirm the reset and re-setup behavior above,
  unanimously, on MySQL 5.7-9.7, MariaDB 10.2-12.3, and Percona 5.7-8.4.
- **Dead connections are replaced silently.** A kept connection that died
  while idle (server restart, idle timeout, a KILL) is detected at connect
  time and replaced with a fresh one. No error reaches your code.

## The Sizing Check

Each worker keeps one connection open per connection config, even when the
site is quiet, so estimate your peak before adding the prefix:

    peak connections = (PHP workers x distinct connection configs)
                       + everything else that connects

- **PHP workers** is `pm.max_children` (PHP-FPM) or `MaxRequestWorkers`
  (Apache with mod_php). Typical small servers run 10-50.
- **Distinct connection configs** is how many hostname/username/database
  combinations use the prefix. Most sites have exactly one; each additional
  one keeps its own connection per worker.
- **Everything else** is cron jobs, backups, monitoring, and any other
  application on the same database server.

Compare against the server's limits:

```sql
SHOW VARIABLES LIKE 'max_connections';          -- e.g. 151  (server-wide cap)
SHOW VARIABLES LIKE 'max_user_connections';     -- e.g. 0    (per-account cap, 0 = none)
SHOW GLOBAL STATUS LIKE 'Max_used_connections'; -- e.g. 32   (most ever open at once)
```

- **Stay under about 80% of the smallest nonzero cap**, leaving room for
  cron jobs and your own emergency logins. Worked example: 30 FPM workers,
  one config, and a nightly backup peaks around 31, comfortable under the
  default `max_connections` of 151.
- **Reality-check the estimate.** `Max_used_connections` is the high-water
  mark since the server started. If that observed peak is already higher
  than your estimate accounts for, raise the "everything else" term before
  adding the prefix.
- **A traffic spike cannot exceed the estimate.** Each worker serves one
  request at a time, so workers x configs is also the most that plain
  hostnames open at full load; requests beyond the worker count queue at the
  web server, not at MySQL. Persistent connections raise the idle connection
  count to that ceiling, never the ceiling itself; if the estimate did not
  fit, the same overload was already possible at peak load with plain
  hostnames.

## The One-Time Trial

The connection count is deterministic, so one test settles the question.

1. **Confirm you can reload PHP.** That reload is the quick way out if the
   sizing is wrong, so do not start without it.
2. **Add the `p:` prefix.**
3. **Watch the count climb and plateau.** Run
   `SHOW STATUS LIKE 'Threads_connected';` to see the current count: as
   traffic spreads across workers, connections rise toward workers x
   configs, then level off.

- **Success: the count plateaus under the caps.** If it fits, no traffic
  pattern changes that; only raising the worker count or adding another
  connection config would change the answer.
- **Failure: connect attempts error** with "Too many connections" or "User
  has exceeded max_user_connections". Remove the prefix and reload PHP
  together; the reload closes every kept connection at once, and you are
  back to normal in seconds.

## Timeouts

Reuse changes only the first of these three:

- **Connect timeout.** ZenDB's `connectTimeout` (default 3s) applies when a
  real connection is being built; a reused connection skips the setup, so
  most requests never start that clock.
- **Query timeout.** ZenDB's `readTimeout` (default 60s) applies per query,
  identical on fresh and reused connections.
- **Server idle timeout.** The server closes connections idle longer than
  its `wait_timeout` (default 8 hours, often set lower). For kept
  connections that is self-cleaning: a quiet period trims them, and mysqli
  replaces a timed-out connection transparently on the next request. The
  replacement happens only at connect time; a connection that dies
  mid-request errors the same as a fresh connection would.

## Turning It Off

- **Remove the `p:` prefix.** Every new request opens a fresh connection;
  the kept connections are never asked for again.
- **Idle connections do not close immediately.** Each worker holds its
  connection until the server's `wait_timeout` closes it (the clock runs
  from last use) or the worker recycles (a `pm.max_requests` rollover, a
  deploy, a reload). Until then they show as sleeping in `SHOW PROCESSLIST`
  and still count against the caps.
- **A PHP reload closes them all instantly.** Worker processes exit and
  every kept connection closes with them. KILLing the sleeping thread IDs
  from an admin session is equally safe, because nothing will ever try to
  reuse them.

### If You Overflowed a Cap

If workers x configs exceeds a cap, the kept connections climb toward the
worker count and, once the cap fills, connect attempts fail with "Too many
connections" or "User has exceeded max_user_connections".

- **The failure is partial, not total.** Workers already holding a
  connection keep serving requests, and MySQL keeps running.
- **The serious case is the server-wide cap.** When `max_connections` fills,
  every other application on that database server is locked out of new
  connections too. That is why the sizing math comes first.
- **Recovery is one move: remove the prefix and reload PHP together.** Run
  the reload command you confirmed before starting
  (`sudo systemctl reload php8.3-fpm`, `sudo apachectl graceful`, or the
  hosting panel's PHP restart). The reload closes every kept connection at
  once; recovery takes seconds.
- **Removing the prefix alone briefly makes it worse.** The sleeping
  connections still hold their slots, so every request now needs a fresh
  slot that isn't free. Left alone, slots clear slowly as workers recycle
  (`pm.max_requests`) and finally as `wait_timeout` closes the sleepers, up
  to 8 hours later.
- **An admin can always get in.** The server reserves one connection slot
  for an account with `SUPER` or `CONNECTION_ADMIN` privilege, so you can
  still log in and KILL the sleepers when the cap is full.

## Good to Know

- **An abandoned transaction holds its locks until the slot is reused.** The
  rollback in the reset runs when the connection is next handed out, not
  when the request ends. Code that stops between START TRANSACTION and
  COMMIT (a fatal error, an `exit()`) leaves row locks held until the same
  worker serves another request or `wait_timeout` closes the connection.
  ZenDB's `DB::transaction()` rolls back automatically when the callback
  throws, so this only applies to code that exits mid-transaction. Servers
  can roll back at request end instead (`mysqli.rollback_on_cached_plink` in
  php.ini), but the default is fine for almost everyone.
- **The reset relies on a stock PHP build.** PHP compiled with the
  `MYSQLI_NO_CHANGE_USER_ON_PCONNECT` flag skips the reset on reuse, so the
  previous request's transactions, temporary tables, and variables survive
  into the next one. Distribution and package-manager builds do not set
  that flag; this only applies to custom-compiled PHP.
- **One sleeper per idle worker in `SHOW PROCESSLIST`.** That is reuse
  working as designed, worth mentioning to whoever watches the server before
  it surprises them.
- **Overhead is memory, not CPU.** An idle connection costs the server a
  thread and its buffers, roughly 0.5-1MB of memory. Fifty PHP workers hold
  about fifty connections and a few tens of MB of server memory while idle.
- **A proxy can pool connections instead.** A proxy such as
  [ProxySQL](https://proxysql.com/) runs between PHP and MySQL: PHP makes
  cheap plain connections to the local proxy, and the proxy shares a small
  pool of persistent server connections across all workers and
  applications. Server connection counts go down instead of up and the caps
  are managed in one place, at the cost of another service to install and
  configure. Worth considering when you control the server and several
  applications share one database.

---

[← Documentation Index](README.md)
