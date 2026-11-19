# Connect Charset Matrix

A ZenDB connection is on utf8mb4 in two places: the PHP side, which `real_escape_string()`
escapes for, and the server session, which decides how the server reads the text it's sent
and which collation compares it. The two have to match, and a server's config can change the
second one at login. This page shows what `Connection::openConnection()` sends to put both on
utf8mb4 and the session it leaves, on every supported database server, started with default
settings and with the settings that change what login does. Each result is
shown next to ZenDB v1.0.0's, so the page also shows what an upgrade changes.

*Generated 2026-11-19 from 19 servers on PHP 8.1, 8.2, 8.3, 8.4, 8.5, 8.6. Every result is in [connect-charset-matrix-data.md](data/connect-charset-matrix-data.md).*

## The Short Version

- **Connect sends one statement on every server:** `SET NAMES utf8mb4`, with `time_zone`
  and `sql_mode` in the same statement. The charset option set before login puts the PHP
  side on utf8mb4 and sends nothing
- **On a server with default settings the session is the same as with 1.0.0**, on all
  19 servers and every PHP version: the charsets, the collation, `time_zone`,
  `sql_mode` and the bytes `real_escape_string()` returns. MySQL/Percona 8.0+ get there
  in 1 statement where 1.0.0 sent 2
- **The session ends on utf8mb4 when the server's config starts it on another charset.**
  A server started with `skip-character-set-client-handshake` ignores the charset asked
  for at login, and `init_connect` can change it after login. With 1.0.0 the session
  stayed on the server's charset there (latin1, utf8mb3 or gbk) on MySQL/Percona 5.7 and
  every MariaDB
- **The connection collation is the server's default for utf8mb4**, the one a new
  `DEFAULT CHARSET=utf8mb4` table gets. A connection collation chosen by the server's
  config is replaced, and on those servers comparing a column with a user variable can
  change from working to error 1267 "Illegal mix of collations", or from error 1267 to
  working
- **A pooled connection (`p:` hostname) can come back on another charset.** Connect
  checks the PHP side and calls `set_charset()` first when it isn't utf8mb4, because
  `SET NAMES` in a query changes only the server side
- **Behind ProxySQL the connection collation is `utf8mb4_general_ci`** on a MySQL 8.0
  backend, where new utf8mb4 tables are `utf8mb4_0900_ai_ci`. ProxySQL handles
  `SET NAMES` itself. It was the same with 1.0.0

"1.0.0" is ZenDB 1.0.0 (the `v1.0.0` tag). Each section number below links to the
results it quotes, in the [data file](data/connect-charset-matrix-data.md).

## What Connect Sends

`openConnection()` sets the charset in three steps.

| Step | When | What the server gets |
|---|---|---|
| `options(MYSQLI_SET_CHARSET_NAME, 'utf8mb4')` | Before login, always | No statement. The PHP side is on utf8mb4 from the start, and login asks the server for utf8mb4 |
| `set_charset('utf8mb4')` | After login, only when the PHP side isn't on utf8mb4 | `SET NAMES utf8mb4` |
| The SET statement below | After login, always | 1 statement |

```sql
SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
```

`time_zone` is left out when `usePhpTimezone` is off, and `sql_mode` when `sqlMode` is
`''`; `NAMES` is always sent. Each form was accepted and took effect on every server,
and every `usePhpTimezone` and `sqlMode` combination connected with 1 statement
([section 5](data/connect-charset-matrix-data.md#5-the-merged-set-statement)). The `+05:30` is the PHP time zone the probe runs in.

Statements sent by one connect ([section 3](data/connect-charset-matrix-data.md#3-statements-connect-sends)).

| Connect | 1.0.0 | Now |
|---|---|---|
| MySQL/Percona 5.7 and MariaDB | 1 | 1 |
| MySQL/Percona 8.0+ | 2 | 1 |
| `usePhpTimezone` off and `sqlMode` `''` | 0 on MySQL/Percona 5.7 and MariaDB, 1 on MySQL/Percona 8.0+ | 1 |
| `databaseAutoCreate`, when it creates the database | 2 more | 2 more |
| Pooled connection that comes back on another charset | 2 | 2 |

1.0.0 sent `SET time_zone = ..., sql_mode = ...` with no `NAMES`, and called
`set_charset('utf8mb4')` before it on MySQL/Percona 8.0+ or when a pooled connection
came back on another charset.

### Why `SET NAMES` When Login Already Asked for utf8mb4

- **The server may not be on utf8mb4 after login.** Its config can ignore the charset
  login asks for, or change it right after
- **Login can't ask for the right collation on MySQL/Percona 8.0+.** Login sends a
  collation number, not a charset name, and PHP's number for utf8mb4 is
  `utf8mb4_general_ci`. A `SET NAMES utf8mb4` statement has only the charset name in
  it, so the server uses its own default collation for utf8mb4 ([section 7](data/connect-charset-matrix-data.md#7-login-alone-against-set-names-plain-mysqli))

| Server | Collation after login alone | After `SET NAMES utf8mb4` |
|---|---|---|
| MySQL/Percona 5.7, MariaDB thru 10.11 | `utf8mb4_general_ci` | `utf8mb4_general_ci` |
| MySQL/Percona 8.0+ | `utf8mb4_general_ci` | `utf8mb4_0900_ai_ci` |
| MariaDB 11.4+ | `utf8mb4_uca1400_ai_ci` | `utf8mb4_uca1400_ai_ci` |

The right-hand column is the "default collation" in the rest of this page. A new
`DEFAULT CHARSET=utf8mb4` table and a new `CHARACTER SET utf8mb4` database get the same
collation as the connection on every server and variant, including with
`default_collation_for_utf8mb4` or `character_set_collations` changed ([section 12](data/connect-charset-matrix-data.md#12-connection-collation-against-new-tables-and-databases)).

`SET NAMES` in a query changes only the server side, so it's only right when the PHP
side is already on utf8mb4. It was, every time connect's SET statement ran: a fresh
connect, a pooled connection left on gbk, `databaseAutoCreate` with and without one,
and a wrapper class that drops the charset option ([section 14](data/connect-charset-matrix-data.md#14-the-php-side-when-connect-sends-its-set-statement)).

## Server Settings That Change the Session

The PHP side is on utf8mb4 in every row, with 1.0.0 and now ([section 6](data/connect-charset-matrix-data.md#6-8-9-the-session-after-connect)).

| Server started with | Session with 1.0.0 | Session now |
|---|---|---|
| Default settings, or another `character-set-server` or `collation-server` | utf8mb4, default collation | Same |
| `skip-character-set-client-handshake` | latin1, utf8mb3 or gbk: the server's charset | utf8mb4, default collation |
| `skip-character-set-client-handshake` and `collation-server=utf8mb4_unicode_ci` | utf8mb4, `utf8mb4_unicode_ci` | utf8mb4, default collation |
| `init_connect='SET NAMES latin1'`, or gbk | latin1, or gbk | utf8mb4, default collation |
| `init_connect` that sets a collation | utf8mb4, that collation | utf8mb4, default collation |

- The "1.0.0" column is MySQL/Percona 5.7 and every MariaDB. On MySQL/Percona 8.0+,
  1.0.0 called `set_charset('utf8mb4')` on every connect, so it was on utf8mb4 and the
  default collation in every row
- MySQL/Percona 8.4+ don't have `skip-character-set-client-handshake`. The server
  doesn't start with it, or with `--character-set-client-handshake=0`, and logs an
  unknown option ([Server Variants section](data/connect-charset-matrix-data.md#server-variants))
- The server runs `init_connect` after login for users without SUPER or
  CONNECTION_ADMIN, and connect's SET statement runs after it. The general log shows
  `Connect`, the `init_connect` statement, then connect's statement ([section 3](data/connect-charset-matrix-data.md#3-statements-connect-sends))
- With the current connect, text sent as utf8mb4 is stored and read back as the same
  bytes in every row ([section 11](data/connect-charset-matrix-data.md#11-text-through-a-table))

### When the Server's Config Chose the Connection Collation

`SET NAMES utf8mb4` replaces a connection collation the server's config chose (the third
and fifth rows above). A user variable has the connection's collation, and so does a
column created from a literal. Comparing either with a column in another collation is
error 1267 "Illegal mix of collations", so the error moves from one kind of table to the
other. The table is a server started with `skip-character-set-client-handshake` and
`collation-server=utf8mb4_unicode_ci` ([section 12](data/connect-charset-matrix-data.md#12-connection-collation-against-new-tables-and-databases)).

| Comparison | 1.0.0 | Now |
|---|---|---|
| Column in a table created with no charset clause (`utf8mb4_unicode_ci`) `= @user_variable` | Works | Error 1267 |
| Column in a `DEFAULT CHARSET=utf8mb4` table (default collation) `= @user_variable` | Error 1267 | Works |
| Either column `= 'a'` | Works | Works |

- The table is MySQL/Percona 5.7 and MariaDB thru 11.4. MariaDB 11.8+ accepts the
  user-variable comparison in both rows, with 1.0.0 and now. With a column created from
  a literal in place of the variable, it gives the table's results
- MySQL/Percona 8.0 gave the "Now" column with 1.0.0 too
- With a normal login nothing changed. When `collation-server` isn't the default
  collation (`utf8mb4_unicode_ci`, or one numbered over 255), 1.0.0 and the current
  connect are both on the default collation, and the first row is error 1267 with both
  (MariaDB 11.8+ accepts the user variable)

## Pooled Connections

With a `p:` hostname, a connection comes back on the charset the last script gave it
with `set_charset()`, and the charset option set before login isn't applied again.
Escaping follows the PHP side's charset, so the PHP side has to go back to utf8mb4, not
only the server session ([section 4](data/connect-charset-matrix-data.md#4-pooled-connections-p-hostname)).

| A pooled connection left on gbk | PHP side | Server session |
|---|---|---|
| Reopened with the charset option | gbk | gbk |
| Then a `SET NAMES utf8mb4` query | gbk | utf8mb4 |
| Closed after that query and reopened | gbk | gbk |
| Then `set_charset('utf8mb4')` | utf8mb4 | utf8mb4 |
| `new Connection()` | utf8mb4 | utf8mb4 |

- Connect reads `character_set_name()`, which sends nothing, and calls
  `set_charset('utf8mb4')` when it isn't utf8mb4: 2 statements on every server and variant
- On a `skip-character-set-client-handshake` server, the server session comes back on
  the server's charset in the first and third rows
- A script that changed charset with a `SET NAMES gbk` query, and not `set_charset()`,
  leaves its PHP side on utf8mb4, so connect sends 1 statement on that connection

## Without the Charset Option

ZenDB always sets the option, and it sends no statement: the server's general log shows
`Connect`, then the first query ([section 1](data/connect-charset-matrix-data.md#1-the-charset-option-before-connect-plain-mysqli)). Without it, plain mysqli starts on the
charset of the collation number in the server's greeting. With default settings that's
latin1 on MySQL/Percona 5.7 and MariaDB thru 10.4, and utf8mb4 on MySQL/Percona 8.0+
and MariaDB 10.5+ ([section 2](data/connect-charset-matrix-data.md#2-no-charset-option-plain-mysqli)).

The number in the greeting has one byte. On a MySQL/Percona 8.0+ server started with
`collation-server=utf8mb4_pl_0900_as_cs`, the number is 284, which leaves 28 in one
byte, and 28 is `gbk_chinese_ci`. A client that sets no charset is on gbk there on both
sides, on a utf8mb4 server.

## After Connect

`mysqli::change_user()` on a connected Connection puts the server session back to its
login state, and nothing sets it again ([After Connect section](data/connect-charset-matrix-data.md#after-connect-change_user-select_db-reconnect)). Its `time_zone` and
`sql_mode` are the server's, the collation is the one login left, and on a
`skip-character-set-client-handshake` server the session is back on the server's charset
while the PHP side stays on utf8mb4. It was the same with 1.0.0.

## Connects That Fail

Each gave the same exception class and message as 1.0.0, on every server and variant
([section 13](data/connect-charset-matrix-data.md#13-connects-that-fail-and-unusual-settings)).

- `NO_BACKSLASH_ESCAPES` in `sqlMode` is a `RuntimeException` whose message starts
  "NO_BACKSLASH_ESCAPES is on for this connection". So is `NO_BACKSLASH_ESCAPES` in the
  server's global `sql_mode` when `sqlMode` is `''`. With the default `sqlMode` that
  server connects, because `sqlMode` replaces the server's value
- An unknown mode in `sqlMode` is `mysqli_sql_exception` 1231, and a quote in it is
  `mysqli_sql_exception` 1064

## Behind ProxySQL

The same connect through ProxySQL 2.5.5, 2.7.3 and 3.0.12, with a MySQL 8.0 backend
([ProxySQL section](data/connect-charset-matrix-data.md#proxysql)).

- Connect's statement is one ProxySQL parses, so it doesn't lock the hostgroup. The
  only statement that did was the probe's own `SET @zdb_value = 'a'`
- Two clients' sessions stay separate, and values and text come back as the same bytes
- The connection collation is `utf8mb4_general_ci`, with 1.0.0 and now. New utf8mb4
  tables are `utf8mb4_0900_ai_ci`, so comparing one of their columns with a user variable
  is error 1267
- ProxySQL reads `SET NAMES utf8mb4` itself and doesn't send it to the backend as
  written. The backend server's log shows connect's statement arriving as separate
  `SET sql_mode` and `SET time_zone` statements, with no `SET NAMES` on a new backend
  connection. When ProxySQL has to change a backend connection that was last used on
  latin1, it sends `SET NAMES utf8mb4 COLLATE 'utf8mb4_general_ci'`
- The collation number ProxySQL logs in with isn't in that log

## Not Tested

MaxScale and other proxies, TiDB, Vitess, a wrapper class that overrides
`character_set_name()` or `set_charset()`, a server without time zone tables,
`COM_RESET_CONNECTION` (mysqli has no call for it), and PHP builds other than Linux x64.

## Full Results

Every fact on every server is in [connect-charset-matrix-data.md](data/connect-charset-matrix-data.md). It starts
with how each server variant was started, then has the session charsets, statement
counts, general log lines, stored bytes, comparison results and exception messages
behind the sections above, and the ProxySQL results. Where 1.0.0 and the current code
gave different answers, the line shows both.

## Regenerating This Report

This page and its data file are generated, so change them in the script and not by
hand. The sections above are in the heredoc in
.github/scripts/connect-charset-probe.php. They quote values from the data file, so
check them against it after a run that changes any.

    gh workflow run connect-charset-matrix.yml   # wait for the run to finish, then:
    gh run download --dir probes                 # pick the latest Connect Charset Matrix run
    php .github/scripts/connect-charset-probe.php --merge probes/*/connect-charset-probe-*.json > docs/internal/connect-charset-matrix.md
    php .github/scripts/connect-charset-probe.php --merge --data probes/*/connect-charset-probe-*.json > docs/internal/data/connect-charset-matrix-data.md

The "Connect Charset Matrix" workflow (.github/workflows/connect-charset-matrix.yml)
runs one job per server and PHP version, and one per ProxySQL version. Each job starts
the same image once per server variant and runs the probe with this checkout's
`src/`, and again with the `src/` of the `baseline` input (v1.0.0).
