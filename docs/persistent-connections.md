# Persistent Connections

Opening a MySQL connection costs TCP setup and an authentication handshake
on every request, plus TLS negotiation when `requireSSL` is on. A persistent
connection skips that: each PHP worker keeps its connection open between
requests and reuses it. Reuse replaces the whole setup sequence with a
single reset command.

> **Before you read on:** persistent connections are an advanced setup with
> real gotchas, and this page is deliberately not linked from the
> documentation index. Plain hostnames are the right default. If you do
> want this, start with [Should You Use It](#should-you-use-it): it covers
> when reuse pays off and what to have ready before enabling it.

Contents:

- [Should You Use It](#should-you-use-it)
- [Enabling It](#enabling-it)
- [Reuse Stays Clean](#reuse-stays-clean)
- [The Sizing Check](#the-sizing-check)
- [The One-Time Trial](#the-one-time-trial)
- [Timeouts](#timeouts)
- [Turning It Off](#turning-it-off)
- [A Crash Mid-Transaction Blocks Others Briefly](#a-crash-mid-transaction-blocks-others-briefly)
- [Good to Know](#good-to-know)

## Should You Use It

Most sites don't need this. It pays off when the database is on another
host or the site is busy enough that milliseconds add up.

### Use It When

- **The database is on another host, or the connection has `requireSSL` on.**
  Fresh setup costs several network round trips plus TLS negotiation; reuse
  costs one round trip, saving several milliseconds on every request.
- **The site is busy.** Measured locally, connecting dropped from 447µs to
  131µs and a typical page (connect, three queries, disconnect) finished
  about 0.3ms sooner; the saving multiplies by request volume.

### Skip It When

- **The code runs from CLI or cron.** Connections are kept per process and
  never shared between processes: a cron job can't pick up a web worker's
  connection, and its own process exits when the script finishes, closing
  its connection with it. The prefix is harmless there, just no benefit.
- **You are on shared hosting with a low `max_user_connections` cap.** Hosts
  often cap connections at 20-50 per account, which a modest worker pool
  fills by itself. If the cap is at or below your worker count, stick with
  the plain hostname; every request over the limit fails with "User has
  exceeded max_user_connections".
- **The host disables persistent connections** (`mysqli.allow_persistent`
  off in php.ini). The prefix then downgrades to a normal connection:
  everything works, connections just aren't reused, and ZenDB suppresses
  PHP's "Downgrading to normal" warning on each connect (see
  [Timeouts](#timeouts) for how suppressed warnings are handled).

### Before You Start

- Run [the sizing check](#the-sizing-check) below. Getting it wrong fills
  the server's connection caps.
- Confirm you can reload PHP, and find your exact command now, not during
  an outage. The reload is both part of the trial and the instant undo.
- No reload access (managed hosting)? There is a slower exit that may be
  enough: removing the prefix switches new requests back instantly, and
  many PHP-FPM hosts recycle idle workers within minutes, closing their
  kept connections with them. Confirm that on your server before relying
  on it: run [the single-IP trial](#trial-on-a-single-ip-first) below and
  watch how long the sleeping connection survives after you stop browsing.

Typical reload commands:

| Setup               | Command                                              |
|---------------------|------------------------------------------------------|
| PHP-FPM             | `sudo systemctl reload php8.3-fpm`                   |
| Apache with mod_php | `sudo apachectl graceful`                            |
| Managed hosting     | the hosting panel's option to restart PHP or PHP-FPM |

The PHP-FPM service name varies by PHP version and distro;
`systemctl list-units | grep fpm` finds yours.

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
- **Dead connections are replaced silently.** A kept connection that died
  while idle (server restart, idle timeout, a KILL) is detected at connect
  time and replaced with a fresh one. No error reaches your code; the one
  warning a server can raise here is suppressed by ZenDB (see
  [Timeouts](#timeouts)).
- **Verified on all 22 supported servers.** Permanent probes in ZenDB's CI
  behavior matrix confirm every behavior in this section - the reset, the
  re-setup, lock release, prepared statement cleanup, and dead-connection
  replacement - on MySQL 5.7-9.7, MariaDB 10.2-12.3, and Percona 5.7-8.4.

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

A few more values are worth looking up while you're here. Where they live
varies by stack, and the names are enough to find them.

| Value                                    | Where                                | What it tells you                                                                              |
|------------------------------------------|--------------------------------------|------------------------------------------------------------------------------------------------|
| `pm.max_children` / `MaxRequestWorkers`  | PHP-FPM pool config / Apache config  | The worker cap: the most kept connections you can ever hold                                    |
| `pm.process_idle_timeout`                | PHP-FPM `ondemand` pools             | Idle workers exit after this, closing their connections; usually the fastest drain after a revert |
| `pm.max_requests`                        | PHP-FPM pool config                  | Workers recycle after this many requests and reconnect; sets your leftover new-connection rate |
| `wait_timeout`                           | MySQL                                | The server closes idle connections after this; the slowest drain (default 8 hours)             |
| `Connections`                            | `SHOW GLOBAL STATUS`                 | Total connects since the server started; sample it twice a minute apart and subtract for new connections per minute |

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
- **Reality-check the estimate.** `Max_used_connections` is the most
  connections ever open at once since the server started. If that observed
  peak is already higher than your estimate accounts for, raise the
  "everything else" term before adding the prefix.
- **A traffic spike cannot exceed the estimate.** Each worker serves one
  request at a time, so workers x configs is also the most that plain
  hostnames open at full load; requests beyond the worker count queue at
  the web server, not at MySQL. Persistent connections raise the idle
  connection count to that ceiling, never the ceiling itself.

## The One-Time Trial

The connection count doesn't depend on traffic, so one test settles the
question.

### Trial on a Single IP First

The `p:` prefix and the plain hostname are different pool keys, so requests
connecting with the plain hostname can never pick up a kept connection.
Enabling the prefix for one test IP affects nobody else:

```php
$hostname = 'localhost';
if (($_SERVER['REMOTE_ADDR'] ?? '') === '203.0.113.7') {  // your IP
    $hostname = "p:$hostname";
}
```

Browse the site from that IP and run `SHOW PROCESSLIST` between requests:
your connection shows as one sleeping thread whose Id stays the same, while
everyone else's connections open and close as before. When you stop
browsing, the time until that sleeper disappears is how fast your host
recycles idle workers.

### The Site-Wide Trial

1. **Confirm your way out.** A PHP reload is the quick exit if the sizing
   is wrong; without reload access, confirm fast worker recycling first
   (the single-IP trial above shows it).
2. **Take a churn baseline.** Sample
   `SHOW GLOBAL STATUS LIKE 'Connections';` twice, a minute apart; the
   difference is your new connections per minute.
3. **Add the `p:` prefix.**
4. **Watch the count climb and plateau.** Run
   `SHOW STATUS LIKE 'Threads_connected';` to see the current count: as
   traffic spreads across workers, connections rise toward workers x
   configs, then level off.

- **Success: the count plateaus under the caps.** If it fits, it keeps
  fitting: only a higher worker count or another connection config changes
  the answer, not traffic. Resample the churn rate from step 2 to see the
  payoff: most new connections disappear, and what's left is worker
  recycling plus CLI and cron.
- **Failure: connect attempts error** with "Too many connections" or "User
  has exceeded max_user_connections". Remove the prefix and reload PHP
  together; the reload closes every kept connection at once, and you are
  back to normal in seconds.

## Timeouts

Reuse changes only the connect timeout; the other two behave exactly as
before.

| Timeout     | Setting (default)                     | With reuse                                                   |
|-------------|---------------------------------------|--------------------------------------------------------------|
| Connect     | ZenDB `connectTimeout` (3s)           | Skipped; no setup runs, so most requests never start this clock |
| Query       | ZenDB `readTimeout` (60s)             | Applies per query, identical on fresh and reused connections |
| Server idle | MySQL `wait_timeout` (8h, often less) | Closes connections idle too long; a quiet period trims kept connections on its own |

mysqli replaces a dead kept connection on the next request, but only at
connect time; a connection that dies mid-request errors the same as a
fresh connection would.

One server difference: MySQL and Percona 8.0+ write a final "disconnected
because of inactivity" error packet before closing a timed-out connection,
and mysqli raises a PHP warning when it finds that packet on the kept
socket ("Packets out of order. Expected 1 received 0. Packet size=145"),
then discards the connection and reconnects. ZenDB suppresses the warning,
so nothing is displayed or logged; error log handlers are still called for
suppressed warnings and decide for themselves whether to record them, and
CMS Builder's doesn't log intentionally suppressed warnings. With raw
mysqli the warning is live: it reaches the PHP error log once per worker
after each quiet period longer than `wait_timeout`. MariaDB and
MySQL/Percona 5.7 close the socket without writing anything, so there is
nothing to suppress, and a KILLed connection is silent on every server,
because KILL writes no packet.

## Turning It Off

- **Remove the `p:` prefix.** Every new request opens a fresh connection;
  the plain hostname is a different pool key, so the kept connections are
  never asked for again. If your sizing fit, this alone is a safe exit any
  time: the leftover sleepers are harmless while they drain, and the
  reload below is only needed after overflowing a cap.
- **Idle connections do not close immediately.** Each worker holds its
  connection until the server's `wait_timeout` closes it (the clock runs
  from last use) or the worker recycles (an `ondemand` pool's idle
  timeout, a `pm.max_requests` rollover, a deploy, a reload). Until then
  they show as sleeping in `SHOW PROCESSLIST` and still count against the
  caps.
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
  the reload command you confirmed in
  [Before You Start](#before-you-start). The reload closes every kept
  connection at once; recovery takes seconds.
- **Removing the prefix alone briefly makes it worse.** The sleeping
  connections still hold their slots, so every request now needs a fresh
  slot that isn't free. Left alone, slots clear slowly as workers recycle
  (`pm.max_requests`) and finally as `wait_timeout` closes the sleepers, up
  to 8 hours later.
- **An admin can always get in.** The server reserves one connection slot
  for an account with `SUPER` or `CONNECTION_ADMIN` privilege, so you can
  still log in and KILL the sleepers when the cap is full.

## A Crash Mid-Transaction Blocks Others Briefly

The reset cleans a kept connection when it is next handed out, not when a
request ends. For session state (variables, temp tables, charset) that gap
changes nothing. For locks, it does:

- A request that dies between START TRANSACTION and COMMIT (a fatal error,
  a timeout, an `exit()`) leaves its row locks held by the sleeping
  connection.
- Anyone writing the same rows during the gap waits - no error, their query
  just completes on its own once the locks clear.
- The gap closes when the crashed worker serves its next request. On a busy
  site that is under a second: the crashed worker is free, so it is first
  in line for incoming requests. On a quiet site the gap is longer, but
  nobody is waiting on the lock either.

`DB::transaction()` rolls back when the callback throws, so ordinary
exceptions never leave a transaction open; only hard failures can. Named
locks (`GET_LOCK()`) and table locks clear the same way, at next reuse. The
one pattern worth changing: code that takes a global lock and can die
inside it (a backup running FLUSH TABLES WITH READ LOCK) belongs in a CLI
script, where the process exit releases everything immediately.

To roll back transactions at request end instead, set
`mysqli.rollback_on_cached_plink = 1` in php.ini and reload PHP. It covers
transactions only, not locks, and the default is fine for almost everyone.

## Good to Know

- **One sleeper per idle worker in `SHOW PROCESSLIST`.** That is reuse
  working as designed, worth mentioning to whoever watches the server before
  it surprises them.
- **Overhead is memory, not CPU.** An idle connection costs the server a
  thread and its buffers, roughly 0.5-1MB of memory. Fifty PHP workers hold
  about fifty connections and a few tens of MB of server memory while idle.
- **The reset relies on a stock PHP build.** PHP compiled with the
  `MYSQLI_NO_CHANGE_USER_ON_PCONNECT` flag skips the reset on reuse, so the
  previous request's transactions, temporary tables, and variables survive
  into the next one. Distribution and package-manager builds do not set
  that flag; this only applies to custom-compiled PHP.
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
