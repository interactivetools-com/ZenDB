# Connect Charset Matrix: Data

The results behind [connect-charset-matrix.md](../connect-charset-matrix.md): every fact on every server. Each line is a value → the servers that gave it.

- Where v1.0.0 and this checkout gave the same answer, the value is shown once. Where they differ, the line reads "v1.0.0 `a`, new `b`"
- Sections marked "Plain mysqli" have no ZenDB in them
- A variant is one way of starting the server, listed under Server Variants. A variant or setting that not every server has is grouped among the servers that have it ("all servers" and "MySQL/Percona 8.0+" then mean those), and "every variant" means every variant and `dyn` setting the fact was recorded for
- Session charsets are written `character_set_client / character_set_connection / character_set_results / collation_connection`
- An escape fingerprint is what `real_escape_string()` returns, in hex, for the bytes `bf27`, `bf5c`, `835c`, `e427` and `610062`. gbk and ucs2 give other bytes than utf8mb4 and latin1 do, so it shows which kind of charset the PHP side escapes for
- Every fact was the same on every PHP version on each server, so PHP versions aren't listed
- This page holds the facts that answer the questions the change raised. Every job's full fact list is in its JSON artifact

*Generated 2026-11-19 from 19 servers. Generated file: to change it, see [Regenerating This Report](../connect-charset-matrix.md#regenerating-this-report).*

Servers: mysql:5.7 (5.7.44), mysql:8.0 (8.0.46), mysql:8.4 (8.4.13), mysql:9.6 (9.6.0), mysql:9.7 (9.7.4), mariadb:10.2 (10.2.44-MariaDB-1:10.2.44+maria~bionic), mariadb:10.2.6 (10.2.6-MariaDB-10.2.6+maria~jessie), mariadb:10.2.7 (10.2.7-MariaDB-10.2.7+maria~jessie), mariadb:10.3 (10.3.39-MariaDB-1:10.3.39+maria~ubu2004), mariadb:10.4 (10.4.34-MariaDB-1:10.4.34+maria~ubu2004), mariadb:10.5 (10.5.29-MariaDB-ubu2004), mariadb:10.6 (10.6.28-MariaDB-ubu2204), mariadb:10.11 (10.11.19-MariaDB-ubu2204), mariadb:11.4 (11.4.13-MariaDB-ubu2404), mariadb:11.8 (11.8.9-MariaDB-ubu2404), mariadb:12.3 (12.3.3-MariaDB-ubu2404), percona/percona-server:5.7 (5.7.44-48), percona/percona-server:8.0 (8.0.46-37), percona/percona-server:8.4 (8.4.11-12)

PHP: 8.1 (8.1.34), 8.2 (8.2.35), 8.3 (8.3.36), 8.4 (8.4.27), 8.5 (8.5.12), 8.6 (8.6.0-dev)

## Server Variants

Every job starts these from the same image, each on its own port. `dyn` is a default server on which the probe changes one global variable at a time; those appear below as `dyn + <setting>`.

### server: mysqld flags

On `default`, `dyn`:

- `(none)` → all servers

On `cs-latin1`:

- `--character-set-server=latin1` → all MySQL/Percona
- `--character-set-server=latin1 --collation-server=latin1_swedish_ci` → all MariaDB

On `cs-utf8mb3`:

- `--character-set-server=utf8` → MySQL/Percona thru 8.4
- `--character-set-server=utf8mb3` → MySQL/Percona 9.6+
- `--character-set-server=utf8 --collation-server=utf8_general_ci` → all MariaDB

On `cs-utf8mb4`:

- `--character-set-server=utf8mb4` → all servers

On `skip-latin1`:

- `--skip-character-set-client-handshake --character-set-server=latin1` → all MySQL/Percona
- `--skip-character-set-client-handshake --character-set-server=latin1 --collation-server=latin1_swedish_ci` → all MariaDB

On `skip-utf8mb3`:

- `--skip-character-set-client-handshake --character-set-server=utf8` → MySQL/Percona thru 8.4
- `--skip-character-set-client-handshake --character-set-server=utf8mb3` → MySQL/Percona 9.6+
- `--skip-character-set-client-handshake --character-set-server=utf8 --collation-server=utf8_general_ci` → all MariaDB

On `skip-gbk`:

- `--skip-character-set-client-handshake --character-set-server=gbk` → all MySQL/Percona
- `--skip-character-set-client-handshake --character-set-server=gbk --collation-server=gbk_chinese_ci` → all MariaDB

On `handshake0-latin1`:

- `--character-set-client-handshake=0 --character-set-server=latin1` → all MySQL/Percona
- `--character-set-client-handshake=0 --character-set-server=latin1 --collation-server=latin1_swedish_ci` → all MariaDB

On `skip-ucs2`:

- `--skip-character-set-client-handshake --character-set-server=ucs2` → all MySQL/Percona
- `--skip-character-set-client-handshake --character-set-server=ucs2 --collation-server=ucs2_general_ci` → all MariaDB

On `skip-coll-unicode`:

- `--skip-character-set-client-handshake --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci` → all servers

On `initconnect-latin1`:

- `--init-connect=SET NAMES latin1` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4
- `(config file, not a flag) init_connect='SET NAMES latin1'` → mariadb:10.2, mariadb:10.3

On `initconnect-gbk`:

- `--init-connect=SET NAMES gbk` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4
- `(config file, not a flag) init_connect='SET NAMES gbk'` → mariadb:10.2, mariadb:10.3

On `coll-unicode`:

- `--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci` → all servers

On `coll-over-255`:

- `--character-set-server=utf8mb4 --collation-server=utf8mb4_pl_0900_as_cs` → MySQL/Percona 8.0+

### server: started

On `skip-latin1`, `skip-utf8mb3`, `skip-gbk`, `skip-ucs2`, `skip-coll-unicode`:

- `yes` → MySQL/Percona thru 8.0 and all MariaDB
- `NO (exited) last connect error: 2002: Connection refused; server log: 0 [ERROR] [MY-000068] [Server] unknown option '--skip-character-set-client-handshake'. 0 [ERROR] [MY-013236] [Server] The designated data directory /var/lib/mysql/ is unusable. You can remove all files that the server added to it. 0 [ERROR] [MY-010119] [Server] Aborting ` → MySQL/Percona 8.4+

On `handshake0-latin1`:

- `yes` → MySQL/Percona thru 8.0 and all MariaDB
- `NO (exited) last connect error: 2002: Connection refused; server log: 0 [ERROR] [MY-000067] [Server] unknown variable 'character-set-client-handshake=0'. 0 [ERROR] [MY-013236] [Server] The designated data directory /var/lib/mysql/ is unusable. You can remove all files that the server added to it. 0 [ERROR] [MY-010119] [Server] Aborting ` → MySQL/Percona 8.4+

On `coll-over-255`:

- `yes` → MySQL/Percona 8.0+

On every other variant:

- `yes` → all servers

### server: global character_set_server / collation_server

On `default`, `initconnect-latin1`, `initconnect-gbk`, `dyn`:

- `latin1 / latin1_swedish_ci` → MySQL/Percona 5.7 and MariaDB thru 10.4
- `utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4 / utf8mb4_general_ci` → mariadb:10.5, mariadb:10.6, mariadb:10.11
- `utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `cs-latin1`:

- `latin1 / latin1_swedish_ci` → all servers

On `cs-utf8mb3`:

- `utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `utf8mb3 / utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `cs-utf8mb4`:

- `utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `skip-latin1`, `handshake0-latin1`:

- `latin1 / latin1_swedish_ci` → MySQL/Percona thru 8.0 and all MariaDB

On `skip-utf8mb3`:

- `utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `utf8mb3 / utf8mb3_general_ci` → mysql:8.0, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3, percona/percona-server:8.0

On `skip-gbk`:

- `gbk / gbk_chinese_ci` → MySQL/Percona thru 8.0 and all MariaDB

On `skip-ucs2`:

- `ucs2 / ucs2_general_ci` → MySQL/Percona thru 8.0 and all MariaDB

On `skip-coll-unicode`:

- `utf8mb4 / utf8mb4_unicode_ci` → MySQL/Percona thru 8.0 and all MariaDB

On `coll-unicode`:

- `utf8mb4 / utf8mb4_unicode_ci` → all servers

On `coll-over-255`:

- `utf8mb4 / utf8mb4_pl_0900_as_cs` → MySQL/Percona 8.0+

### SET GLOBAL

On `dyn + default_collation_for_utf8mb4=utf8mb4_general_ci`:

- `not available: error 1193: Unknown system variable 'default_collation_for_utf8mb4'` → MySQL/Percona 5.7 and all MariaDB
- `ok` → MySQL/Percona 8.0+

On `dyn + character_set_collations=utf8mb4=utf8mb4_unicode_ci`:

- `not available: error 1193: Unknown system variable 'character_set_collations'` → all MySQL/Percona and MariaDB thru 10.11
- `ok` → MariaDB 11.4+

On every other variant:

- `ok` → all servers

## 1. The Charset Option Before Connect (Plain mysqli)

### C01 charset option: PHP character_set_name()

On every variant:

- `utf8mb4` → all servers

### C01 charset option: escape fingerprint

On every variant:

- `bf5c27 bf5c5c 835c5c e45c27 615c3062` → all servers

### C01 charset option: Questions on the first statement, minus the same on a connect without the option

On every variant:

- `0` → all servers

### C01 general log: plain connect with the charset option, then one query

On `initconnect-latin1`:

- `Connect > Query(SET NAMES latin1) > Query(SELECT 'first query')` → all servers

On `initconnect-gbk`:

- `Connect > Query(SET NAMES gbk) > Query(SELECT 'first query')` → all servers

On every other variant:

- `Connect > Query(SELECT 'first query')` → all servers

## 2. No Charset Option (Plain mysqli)

### C02 no option: PHP character_set_name()

On `default`, `initconnect-latin1`, `initconnect-gbk`, `dyn`:

- `latin1` → MySQL/Percona 5.7 and MariaDB thru 10.4
- `utf8mb4` → MySQL/Percona 8.0+ and MariaDB 10.5+

On `cs-latin1`, `skip-latin1`, `handshake0-latin1`:

- `latin1` → all servers

On `cs-utf8mb3`, `skip-utf8mb3`:

- `utf8` → all servers

On `cs-utf8mb4`, `skip-coll-unicode`, `coll-unicode`:

- `utf8mb4` → all servers

On `skip-gbk`, `coll-over-255`:

- `gbk` → all servers

On `skip-ucs2`:

- `ucs2` → all servers

### C02 no option: session charsets

On `default`, `dyn`:

- `latin1 / latin1 / latin1 / latin1_swedish_ci` → MySQL/Percona 5.7 and MariaDB thru 10.4
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → mariadb:10.5, mariadb:10.6, mariadb:10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `cs-latin1`, `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`:

- `latin1 / latin1 / latin1 / latin1_swedish_ci` → all servers

On `cs-utf8mb3`:

- `utf8 / utf8 / utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.6, mariadb:10.11, mariadb:11.4, percona/percona-server:8.0, percona/percona-server:8.4
- `utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_uca1400_ai_ci` → MariaDB 11.8+

On `cs-utf8mb4`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `skip-utf8mb3`:

- `utf8 / utf8 / utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `skip-gbk`, `initconnect-gbk`, `coll-over-255`:

- `gbk / gbk / gbk / gbk_chinese_ci` → all servers

On `skip-coll-unicode`, `coll-unicode`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci` → all servers

### C02 no option: escape fingerprint

On `skip-gbk`, `coll-over-255`:

- `5cbf5c27 bf5c 835c 5ce45c27 615c3062` → all servers

On `skip-ucs2`:

- `bf27 bf5c 835c e427 61006200` → all servers

On every other variant:

- `bf5c27 bf5c5c 835c5c e45c27 615c3062` → all servers

### X5 no option, ini default_charset=ISO-8859-1: PHP character_set_name()

On `default`, `initconnect-latin1`, `initconnect-gbk`, `dyn`:

- `latin1` → MySQL/Percona 5.7 and MariaDB thru 10.4
- `utf8mb4` → MySQL/Percona 8.0+ and MariaDB 10.5+

On `cs-latin1`, `skip-latin1`, `handshake0-latin1`:

- `latin1` → all servers

On `cs-utf8mb3`, `skip-utf8mb3`:

- `utf8` → all servers

On `cs-utf8mb4`, `skip-coll-unicode`, `coll-unicode`:

- `utf8mb4` → all servers

On `skip-gbk`, `coll-over-255`:

- `gbk` → all servers

On `skip-ucs2`:

- `ucs2` → all servers

## 3. Statements connect() Sends

### connect as zdb_plain: statements sent by connect

On `coll-over-255`, `dyn + default_collation_for_utf8mb4=utf8mb4_general_ci`:

- v1.0.0 `2`, new `1` → all servers

On `dyn + character_set_collations=utf8mb4=utf8mb4_unicode_ci`:

- `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### connect as root: statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### C03 general log: new Connection(), then one query

On `initconnect-latin1`:

- v1.0.0 `Connect > Query(SET NAMES latin1) > Query(SET time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')`, new `Connect > Query(SET NAMES latin1) > Query(SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `Connect > Query(SET NAMES latin1) > Query(SET NAMES utf8mb4) > Query(SET time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')`, new `Connect > Query(SET NAMES latin1) > Query(SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')` → MySQL/Percona 8.0+

On `initconnect-gbk`:

- v1.0.0 `Connect > Query(SET NAMES gbk) > Query(SET time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')`, new `Connect > Query(SET NAMES gbk) > Query(SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `Connect > Query(SET NAMES gbk) > Query(SET NAMES utf8mb4) > Query(SET time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')`, new `Connect > Query(SET NAMES gbk) > Query(SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')` → MySQL/Percona 8.0+

On `coll-over-255`:

- v1.0.0 `Connect > Query(SET NAMES utf8mb4) > Query(SET time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')`, new `Connect > Query(SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')` → all servers

On every other variant:

- v1.0.0 `Connect > Query(SET time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')`, new `Connect > Query(SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `Connect > Query(SET NAMES utf8mb4) > Query(SET time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')`, new `Connect > Query(SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')` → MySQL/Percona 8.0+

### C14 databaseAutoCreate (second real_connect), fresh connect: statements sent by connect

On `coll-over-255`:

- v1.0.0 `4`, new `3` → all servers

On every other variant:

- `3` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `4`, new `3` → MySQL/Percona 8.0+

### X4 disconnect() then connect(): statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

## 4. Pooled Connections (p: Hostname)

### C04 pooled slot left on gbk, reopened with the charset option: same server thread

On every variant:

- `yes` → all servers

### C04 pooled slot left on latin1, reopened with the charset option

On `skip-utf8mb3`:

- `PHP side latin1, session utf8 / utf8 / utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `PHP side latin1, session utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `skip-gbk`:

- `PHP side latin1, session gbk / gbk / gbk / gbk_chinese_ci` → all servers

On `skip-coll-unicode`:

- `PHP side latin1, session utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci` → all servers

On every other variant:

- `PHP side latin1, session latin1 / latin1 / latin1 / latin1_swedish_ci` → all servers

### C04 pooled slot left on latin1, then a SET NAMES utf8mb4 query only

On every variant:

- `PHP side latin1, session utf8mb4 on all three` → all servers

### C04 pooled slot left on latin1, closed after the SET NAMES query and reopened

On `skip-utf8mb3`:

- `PHP side latin1, session utf8 / utf8 / utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `PHP side latin1, session utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `skip-gbk`:

- `PHP side latin1, session gbk / gbk / gbk / gbk_chinese_ci` → all servers

On `skip-coll-unicode`:

- `PHP side latin1, session utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci` → all servers

On every other variant:

- `PHP side latin1, session latin1 / latin1 / latin1 / latin1_swedish_ci` → all servers

### C04 pooled slot left on latin1, then set_charset(utf8mb4) only

On every variant:

- `PHP side utf8mb4, session utf8mb4 on all three` → all servers

### C04 pooled slot left on latin1, new Connection(): statements sent by connect

On every variant:

- `2` → all servers

### C04 pooled slot left on latin1, new Connection()

On every variant:

- `PHP side utf8mb4, session utf8mb4 on all three` → all servers

### C04 pooled slot left on gbk, reopened with the charset option

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`:

- `PHP side gbk, session latin1 / latin1 / latin1 / latin1_swedish_ci` → all servers

On `skip-utf8mb3`:

- `PHP side gbk, session utf8 / utf8 / utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `PHP side gbk, session utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `skip-coll-unicode`:

- `PHP side gbk, session utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci` → all servers

On every other variant:

- `PHP side gbk, session gbk / gbk / gbk / gbk_chinese_ci` → all servers

### C04 pooled slot left on gbk, then a SET NAMES utf8mb4 query only

On every variant:

- `PHP side gbk, session utf8mb4 on all three` → all servers

### C04 pooled slot left on gbk, closed after the SET NAMES query and reopened

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`:

- `PHP side gbk, session latin1 / latin1 / latin1 / latin1_swedish_ci` → all servers

On `skip-utf8mb3`:

- `PHP side gbk, session utf8 / utf8 / utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `PHP side gbk, session utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `skip-coll-unicode`:

- `PHP side gbk, session utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci` → all servers

On every other variant:

- `PHP side gbk, session gbk / gbk / gbk / gbk_chinese_ci` → all servers

### C04 pooled slot left on gbk, then set_charset(utf8mb4) only

On every variant:

- `PHP side utf8mb4, session utf8mb4 on all three` → all servers

### C04 pooled slot left on gbk, new Connection(): statements sent by connect

On every variant:

- `2` → all servers

### C04 pooled slot left on gbk, new Connection()

On every variant:

- `PHP side utf8mb4, session utf8mb4 on all three` → all servers

### C04 pooled slot left clean after gbk, new Connection(): statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### C04 pooled slot after a SET NAMES gbk query, new Connection(): same server thread

On every variant:

- `yes` → all servers

### C04 pooled slot after a SET NAMES gbk query, new Connection(): statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### C04 general log: new Connection() on a pooled slot left on gbk, then one query

On every variant:

- v1.0.0 `Change user > Query(SET NAMES utf8mb4) > Query(SET time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')`, new `Change user > Query(SET NAMES utf8mb4) > Query(SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SELECT 'first query')` → all servers

## 5. The Merged SET Statement

### C05 SET NAMES utf8mb4

On every variant:

- `took effect: utf8mb4 on all three / +09:00 / PIPES_AS_CONCAT` → all servers

### C05 SET NAMES utf8mb4, time_zone = '+05:30'

On every variant:

- `took effect: utf8mb4 on all three / +05:30 / PIPES_AS_CONCAT` → all servers

### C05 SET NAMES utf8mb4, sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE'

On every variant:

- `took effect: utf8mb4 on all three / +09:00 / STRICT_ALL_TABLES,NO_ZERO_IN_DATE` → all servers

### C05 SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE'

On every variant:

- `took effect: utf8mb4 on all three / +05:30 / STRICT_ALL_TABLES,NO_ZERO_IN_DATE` → all servers

### C05 config usePhpTimezone=true sqlMode=default: statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### C05 config usePhpTimezone=true sqlMode=default: time_zone / sql_mode

- `+05:30 / STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → all servers

### C05 config usePhpTimezone=true sqlMode='': statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### C05 config usePhpTimezone=true sqlMode='': time_zone / sql_mode

- `+05:30 / ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` → MySQL/Percona 5.7
- `+05:30 / ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → MySQL/Percona 8.0+
- `+05:30 / STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` → all MariaDB

### C05 config usePhpTimezone=true sqlMode=ANSI_QUOTES,STRICT_ALL_TABLES: statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### C05 config usePhpTimezone=true sqlMode=ANSI_QUOTES,STRICT_ALL_TABLES: time_zone / sql_mode

- `+05:30 / ANSI_QUOTES,STRICT_ALL_TABLES` → all servers

### C05 config usePhpTimezone=false sqlMode=default: statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### C05 config usePhpTimezone=false sqlMode=default: time_zone / sql_mode

- `SYSTEM / STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → all servers

### C05 config usePhpTimezone=false sqlMode='': statements sent by connect

On `coll-over-255`:

- `1` → all servers

On every other variant:

- v1.0.0 `0`, new `1` → MySQL/Percona 5.7 and all MariaDB
- `1` → MySQL/Percona 8.0+

### C05 config usePhpTimezone=false sqlMode='': time_zone / sql_mode

- `SYSTEM / ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` → MySQL/Percona 5.7
- `SYSTEM / ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → MySQL/Percona 8.0+
- `SYSTEM / STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` → all MariaDB

### C05 config usePhpTimezone=false sqlMode=ANSI_QUOTES,STRICT_ALL_TABLES: statements sent by connect

On `coll-over-255`:

- v1.0.0 `2`, new `1` → all servers

On every other variant:

- `1` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `2`, new `1` → MySQL/Percona 8.0+

### C05 config usePhpTimezone=false sqlMode=ANSI_QUOTES,STRICT_ALL_TABLES: time_zone / sql_mode

- `SYSTEM / ANSI_QUOTES,STRICT_ALL_TABLES` → all servers

## 6, 8, 9. The Session After connect()

### connect as zdb_plain: PHP character_set_name()

On every variant:

- `utf8mb4` → all servers

### connect as zdb_plain: session charsets

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`, `dyn + init_connect=SET NAMES latin1`:

- v1.0.0 `latin1 / latin1 / latin1 / latin1_swedish_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- v1.0.0 `latin1 / latin1 / latin1 / latin1_swedish_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `skip-utf8mb3`:

- v1.0.0 `utf8 / utf8 / utf8 / utf8_general_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- v1.0.0 `utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → mariadb:10.6, mariadb:10.11
- v1.0.0 `utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `skip-gbk`, `initconnect-gbk`, `dyn + init_connect=SET NAMES gbk`:

- v1.0.0 `gbk / gbk / gbk / gbk_chinese_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- v1.0.0 `gbk / gbk / gbk / gbk_chinese_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `skip-coll-unicode`, `dyn + init_connect=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`:

- v1.0.0 `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- v1.0.0 `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `dyn + init_connect=SET collation_connection=utf8mb4_bin`:

- v1.0.0 `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_bin`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- v1.0.0 `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_bin`, new `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `coll-over-255`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → all servers

On `dyn + default_collation_for_utf8mb4=utf8mb4_general_ci`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → all servers

On `dyn + old_mode empty`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `dyn + character_set_collations=utf8mb4=utf8mb4_unicode_ci`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci` → all servers

On every other variant:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

### connect as root: session charsets

On `initconnect-latin1`, `initconnect-gbk`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

### connect as zdb_plain: time_zone / sql_mode

On every variant:

- `+05:30 / STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → all servers

### connect as zdb_plain: escape fingerprint

On every variant:

- `bf5c27 bf5c5c 835c5c e45c27 615c3062` → all servers

## 7. Login Alone Against SET NAMES (Plain mysqli)

### C07 login only with the charset option, user root: session charsets

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`:

- `latin1 / latin1 / latin1 / latin1_swedish_ci` → all servers

On `skip-utf8mb3`:

- `utf8 / utf8 / utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `skip-gbk`:

- `gbk / gbk / gbk / gbk_chinese_ci` → all servers

On `skip-coll-unicode`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci` → all servers

On `coll-over-255`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → all servers

On every other variant:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → all MySQL/Percona and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

### C07 after SET NAMES utf8mb4, user root: session charsets

On `coll-over-255`:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → all servers

On every other variant:

- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

### C07 no option then set_charset(utf8mb4): PHP side, session charsets

On `coll-over-255`:

- `utf8mb4, utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → all servers

On every other variant:

- `utf8mb4, utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4, utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4, utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

## 10. Values Through Placeholders

### C10 ? placeholder, 26 values: one row with the same bytes

On every variant:

- `all` → all servers

### C10 :name placeholder, 26 values: one row with the same bytes

On every variant:

- `all` → all servers

## 11. Text Through a Table

### C11 table round trip, cafe with e-acute

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`:

- v1.0.0 `sent 636166c3a9, stored 636166c383c2a9, read back 636166c3a9: DIFFERENT`, new `636166c3a9 stored and read back as sent` → MySQL/Percona 5.7 and all MariaDB
- `636166c3a9 stored and read back as sent` → MySQL/Percona 8.0+

On `skip-gbk`, `initconnect-gbk`:

- v1.0.0 `sent 636166c3a9, stored 636166e88c85, read back 636166c3a9: DIFFERENT`, new `636166c3a9 stored and read back as sent` → MySQL/Percona 5.7 and all MariaDB
- `636166c3a9 stored and read back as sent` → MySQL/Percona 8.0+

On every other variant:

- `636166c3a9 stored and read back as sent` → all servers

### C11 table round trip, Greek capital omega

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`:

- v1.0.0 `sent cea9, stored c38ec2a9, read back cea9: DIFFERENT`, new `cea9 stored and read back as sent` → MySQL/Percona 5.7 and all MariaDB
- `cea9 stored and read back as sent` → MySQL/Percona 8.0+

On `skip-gbk`, `initconnect-gbk`:

- v1.0.0 `sent cea9, stored e6839f, read back cea9: DIFFERENT`, new `cea9 stored and read back as sent` → MySQL/Percona 5.7 and all MariaDB
- `cea9 stored and read back as sent` → MySQL/Percona 8.0+

On every other variant:

- `cea9 stored and read back as sent` → all servers

### C11 table round trip, 4-byte emoji

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`:

- v1.0.0 `sent f09f9880, stored c3b0c5b8cb9ce282ac, read back f09f9880: DIFFERENT`, new `f09f9880 stored and read back as sent` → MySQL/Percona 5.7 and all MariaDB
- `f09f9880 stored and read back as sent` → MySQL/Percona 8.0+

On `skip-utf8mb3`:

- v1.0.0 `sent f09f9880, failed with mysqli_sql_exception 1366: Incorrect string value: '\xF0\x9F\x98\x80' for column 't' at row 1`, new `f09f9880 stored and read back as sent` → mysql:5.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7
- `f09f9880 stored and read back as sent` → MySQL/Percona 8.0+
- v1.0.0 `` sent f09f9880, failed with mysqli_sql_exception 1366: Incorrect string value: '\xF0\x9F\x98\x80' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 ``, new `f09f9880 stored and read back as sent` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

On `skip-gbk`, `initconnect-gbk`:

- v1.0.0 `sent f09f9880, stored e9a683e6a6be, read back f09f9880: DIFFERENT`, new `f09f9880 stored and read back as sent` → MySQL/Percona 5.7 and all MariaDB
- `f09f9880 stored and read back as sent` → MySQL/Percona 8.0+

On every other variant:

- `f09f9880 stored and read back as sent` → all servers

### C11 table round trip, invalid UTF-8 ff fe

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`:

- v1.0.0 `sent fffe, stored c3bfc3be, read back fffe: DIFFERENT`, new `sent fffe, failed with mysqli_sql_exception 1366: Incorrect string value: '\xFF\xFE' for column 't' at row 1` → mysql:5.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7
- `sent fffe, failed with mysqli_sql_exception 1366: Incorrect string value: '\xFF\xFE' for column 't' at row 1` → MySQL/Percona 8.0+
- v1.0.0 `sent fffe, stored c3bfc3be, read back fffe: DIFFERENT`, new `` sent fffe, failed with mysqli_sql_exception 1366: Incorrect string value: '\xFF\xFE' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

On `skip-utf8mb3`, `skip-gbk`, `skip-coll-unicode`:

- `sent fffe, failed with mysqli_sql_exception 1366: Incorrect string value: '\xFF\xFE' for column 't' at row 1` → mysql:5.7, mysql:8.0, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0
- `` sent fffe, failed with mysqli_sql_exception 1366: Incorrect string value: '\xFF\xFE' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

On `coll-over-255`:

- `sent fffe, failed with mysqli_sql_exception 1366: Incorrect string value: '\xFF\xFE' for column 't' at row 1` → all servers

On every other variant:

- `sent fffe, failed with mysqli_sql_exception 1366: Incorrect string value: '\xFF\xFE' for column 't' at row 1` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4
- `` sent fffe, failed with mysqli_sql_exception 1366: Incorrect string value: '\xFF\xFE' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

### C11 table round trip, latin1 e-acute byte

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`:

- v1.0.0 `sent 636166e9, stored 636166c3a9, read back 636166e9: DIFFERENT`, new `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → mysql:5.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7
- `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → MySQL/Percona 8.0+
- v1.0.0 `sent 636166e9, stored 636166c3a9, read back 636166e9: DIFFERENT`, new `` sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

On `skip-utf8mb3`, `skip-gbk`:

- v1.0.0 `sent 636166e9, failed with mysqli_sql_exception 1406: Data too long for column 't' at row 1`, new `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → MySQL/Percona 5.7
- `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → mysql:8.0, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:8.0
- `` sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

On `skip-coll-unicode`:

- `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → mysql:5.7, mysql:8.0, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0
- `` sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

On `initconnect-gbk`:

- v1.0.0 `sent 636166e9, failed with mysqli_sql_exception 1406: Data too long for column 't' at row 1`, new `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → MySQL/Percona 5.7
- `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:8.0, percona/percona-server:8.4
- `` sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

On `coll-over-255`:

- `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → all servers

On every other variant:

- `sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column 't' at row 1` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4
- `` sent 636166e9, failed with mysqli_sql_exception 1366: Incorrect string value: '\xE9' for column `zdb_probe`.`zdb_roundtrip`.`t` at row 1 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3

## 12. Connection Collation Against New Tables and Databases

### C12: CREATE TABLE ... DEFAULT CHARSET=utf8mb4 column collation

On `coll-over-255`:

- `utf8mb4_0900_ai_ci` → all servers

On `dyn + default_collation_for_utf8mb4=utf8mb4_general_ci`:

- `utf8mb4_general_ci` → all servers

On `dyn + character_set_collations=utf8mb4=utf8mb4_unicode_ci`:

- `utf8mb4_unicode_ci` → all servers

On every other variant:

- `utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

### C12: connection, new table and new database collations

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`, `dyn + init_connect=SET NAMES latin1`:

- v1.0.0 `DIFFER: connection latin1_swedish_ci, table utf8mb4_general_ci, database utf8mb4_general_ci`, new `all the same` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `all the same` → MySQL/Percona 8.0+
- v1.0.0 `DIFFER: connection latin1_swedish_ci, table utf8mb4_uca1400_ai_ci, database utf8mb4_uca1400_ai_ci`, new `all the same` → MariaDB 11.4+

On `skip-utf8mb3`:

- v1.0.0 `DIFFER: connection utf8_general_ci, table utf8mb4_general_ci, database utf8mb4_general_ci`, new `all the same` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `all the same` → MySQL/Percona 8.0+
- v1.0.0 `DIFFER: connection utf8mb3_general_ci, table utf8mb4_general_ci, database utf8mb4_general_ci`, new `all the same` → mariadb:10.6, mariadb:10.11
- v1.0.0 `DIFFER: connection utf8mb3_general_ci, table utf8mb4_uca1400_ai_ci, database utf8mb4_uca1400_ai_ci`, new `all the same` → MariaDB 11.4+

On `skip-gbk`, `initconnect-gbk`, `dyn + init_connect=SET NAMES gbk`:

- v1.0.0 `DIFFER: connection gbk_chinese_ci, table utf8mb4_general_ci, database utf8mb4_general_ci`, new `all the same` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `all the same` → MySQL/Percona 8.0+
- v1.0.0 `DIFFER: connection gbk_chinese_ci, table utf8mb4_uca1400_ai_ci, database utf8mb4_uca1400_ai_ci`, new `all the same` → MariaDB 11.4+

On `skip-coll-unicode`, `dyn + init_connect=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`:

- v1.0.0 `DIFFER: connection utf8mb4_unicode_ci, table utf8mb4_general_ci, database utf8mb4_general_ci`, new `all the same` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `all the same` → MySQL/Percona 8.0+
- v1.0.0 `DIFFER: connection utf8mb4_unicode_ci, table utf8mb4_uca1400_ai_ci, database utf8mb4_uca1400_ai_ci`, new `all the same` → MariaDB 11.4+

On `dyn + init_connect=SET collation_connection=utf8mb4_bin`:

- v1.0.0 `DIFFER: connection utf8mb4_bin, table utf8mb4_general_ci, database utf8mb4_general_ci`, new `all the same` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `all the same` → MySQL/Percona 8.0+
- v1.0.0 `DIFFER: connection utf8mb4_bin, table utf8mb4_uca1400_ai_ci, database utf8mb4_uca1400_ai_ci`, new `all the same` → MariaDB 11.4+

On every other variant:

- `all the same` → all servers

### C12: CREATE TABLE with no charset clause column collation

On `cs-latin1`, `skip-latin1`, `handshake0-latin1`:

- `latin1_swedish_ci` → all servers

On `cs-utf8mb3`, `skip-utf8mb3`:

- `utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `cs-utf8mb4`:

- `utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

On `skip-gbk`:

- `gbk_chinese_ci` → all servers

On `skip-ucs2`:

- `ucs2_general_ci` → all servers

On `skip-coll-unicode`, `coll-unicode`:

- `utf8mb4_unicode_ci` → all servers

On `coll-over-255`:

- `utf8mb4_pl_0900_as_cs` → all servers

On `dyn + default_collation_for_utf8mb4=utf8mb4_general_ci`:

- `utf8mb4_0900_ai_ci` → all servers

On `dyn + character_set_collations=utf8mb4=utf8mb4_unicode_ci`:

- `utf8mb4_uca1400_ai_ci` → all servers

On every other variant:

- `latin1_swedish_ci` → MySQL/Percona 5.7 and MariaDB thru 10.4
- `utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4_general_ci` → mariadb:10.5, mariadb:10.6, mariadb:10.11
- `utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

### C12: utf8mb4 column = 'a'

On every variant:

- `ok, 1 row` → all servers

### C12: utf8mb4 column = @user_variable

On `skip-coll-unicode`, `dyn + init_connect=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`:

- v1.0.0 `error 1267: Illegal mix of collations (utf8mb4_general_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `ok, 1 row` → MySQL/Percona 8.0+ and MariaDB 11.8+
- v1.0.0 `error 1267: Illegal mix of collations (utf8mb4_uca1400_ai_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → mariadb:11.4

On every other variant:

- `ok, 1 row` → all servers

### C12: utf8mb4 column = column made from a literal

On `skip-coll-unicode`, `dyn + init_connect=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`:

- v1.0.0 `error 1267: Illegal mix of collations (utf8mb4_general_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `ok, 1 row` → MySQL/Percona 8.0+
- v1.0.0 `error 1267: Illegal mix of collations (utf8mb4_uca1400_ai_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → MariaDB 11.4+

On every other variant:

- `ok, 1 row` → all servers

### C12: server-default column = 'a'

On every variant:

- `ok, 1 row` → all servers

### C12: server-default column = @user_variable

On `skip-ucs2`:

- `ok, 1 row` → all MySQL/Percona and MariaDB 11.8+
- v1.0.0 `ok, 1 row`, new `error 1267: Illegal mix of collations (ucs2_general_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → MariaDB thru 10.11
- v1.0.0 `ok, 1 row`, new `error 1267: Illegal mix of collations (ucs2_general_ci,IMPLICIT) and (utf8mb4_uca1400_ai_ci,IMPLICIT) for operation '='` → mariadb:11.4

On `skip-coll-unicode`:

- v1.0.0 `ok, 1 row`, new `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT) for operation '='` → MySQL/Percona 8.0+
- v1.0.0 `ok, 1 row`, new `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_uca1400_ai_ci,IMPLICIT) for operation '='` → mariadb:11.4
- `ok, 1 row` → MariaDB 11.8+

On `initconnect-gbk`, `dyn + init_connect=SET NAMES gbk`:

- v1.0.0 `error 1267: Illegal mix of collations (latin1_swedish_ci,IMPLICIT) and (gbk_chinese_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → MySQL/Percona 5.7 and MariaDB thru 10.4
- `ok, 1 row` → MySQL/Percona 8.0+ and MariaDB 10.5+

On `coll-unicode`:

- `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT) for operation '='` → MySQL/Percona 8.0+
- `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_uca1400_ai_ci,IMPLICIT) for operation '='` → mariadb:11.4
- `ok, 1 row` → MariaDB 11.8+

On `dyn + init_connect=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`:

- `ok, 1 row` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:11.8, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4
- v1.0.0 `error 1267: Illegal mix of collations (utf8mb4_general_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → mariadb:10.5, mariadb:10.6, mariadb:10.11
- v1.0.0 `error 1267: Illegal mix of collations (utf8mb4_uca1400_ai_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → mariadb:11.4

On `coll-over-255`:

- `error 1267: Illegal mix of collations (utf8mb4_pl_0900_as_cs,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT) for operation '='` → all servers

On `dyn + default_collation_for_utf8mb4=utf8mb4_general_ci`:

- `error 1267: Illegal mix of collations (utf8mb4_0900_ai_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → all servers

On `dyn + character_set_collations=utf8mb4=utf8mb4_unicode_ci`:

- `error 1267: Illegal mix of collations (utf8mb4_uca1400_ai_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='` → mariadb:11.4
- `ok, 1 row` → MariaDB 11.8+

On every other variant:

- `ok, 1 row` → all servers

### C12: server-default column = column made from a literal

On `skip-ucs2`:

- `ok, 1 row` → all MySQL/Percona
- v1.0.0 `ok, 1 row`, new `error 1267: Illegal mix of collations (ucs2_general_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → MariaDB thru 10.11
- v1.0.0 `ok, 1 row`, new `error 1267: Illegal mix of collations (ucs2_general_ci,IMPLICIT) and (utf8mb4_uca1400_ai_ci,IMPLICIT) for operation '='` → MariaDB 11.4+

On `skip-coll-unicode`:

- v1.0.0 `ok, 1 row`, new `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT) for operation '='` → MySQL/Percona 8.0+
- v1.0.0 `ok, 1 row`, new `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_uca1400_ai_ci,IMPLICIT) for operation '='` → MariaDB 11.4+

On `initconnect-gbk`, `dyn + init_connect=SET NAMES gbk`:

- v1.0.0 `error 1267: Illegal mix of collations (latin1_swedish_ci,IMPLICIT) and (gbk_chinese_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → MySQL/Percona 5.7 and MariaDB thru 10.4
- `ok, 1 row` → MySQL/Percona 8.0+ and MariaDB 10.5+

On `coll-unicode`:

- `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT) for operation '='` → MySQL/Percona 8.0+
- `error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_uca1400_ai_ci,IMPLICIT) for operation '='` → MariaDB 11.4+

On `dyn + init_connect=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`:

- `ok, 1 row` → all MySQL/Percona and MariaDB thru 10.4
- v1.0.0 `error 1267: Illegal mix of collations (utf8mb4_general_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → mariadb:10.5, mariadb:10.6, mariadb:10.11
- v1.0.0 `error 1267: Illegal mix of collations (utf8mb4_uca1400_ai_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`, new `ok, 1 row` → MariaDB 11.4+

On `coll-over-255`:

- `error 1267: Illegal mix of collations (utf8mb4_pl_0900_as_cs,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT) for operation '='` → all servers

On `dyn + default_collation_for_utf8mb4=utf8mb4_general_ci`:

- `error 1267: Illegal mix of collations (utf8mb4_0900_ai_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → all servers

On `dyn + character_set_collations=utf8mb4=utf8mb4_unicode_ci`:

- `error 1267: Illegal mix of collations (utf8mb4_uca1400_ai_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='` → all servers

On every other variant:

- `ok, 1 row` → all servers

## 13. Connects That Fail, and Unusual Settings

### C13 sqlMode `NO_BACKSLASH_ESCAPES`

On every variant:

- `RuntimeException 0: NO_BACKSLASH_ESCAPES is on for this connection, and ZenDB escapes values with backslashes. Remove it from sqlMode, or from the server's global sql_mode if sqlMode is ''.` → all servers

### C13 sqlMode `STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES`

On every variant:

- `RuntimeException 0: NO_BACKSLASH_ESCAPES is on for this connection, and ZenDB escapes values with backslashes. Remove it from sqlMode, or from the server's global sql_mode if sqlMode is ''.` → all servers

### C13 sqlMode `BOGUS_MODE`

On every variant:

- `mysqli_sql_exception 1231: Variable 'sql_mode' can't be set to the value of 'BOGUS_MODE'` → all servers

### C13 sqlMode `A'B`

On `coll-over-255`:

- `mysqli_sql_exception 1064: You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'B'' at line 1` → all servers

On every other variant:

- `mysqli_sql_exception 1064: You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'B'' at line 1` → all MySQL/Percona
- `mysqli_sql_exception 1064: You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'B'' at line 1` → all MariaDB

### C13 sqlMode `ANSI_QUOTES`

On every variant:

- `connected` → all servers

### C13 PHP time zone Pacific/Kiritimati

On every variant:

- `connected` → all servers

### C13 PHP time zone Pacific/Chatham

On every variant:

- `connected` → all servers

### connect as zdb_plain: result

On `dyn + sql_mode=NO_BACKSLASH_ESCAPES`, `dyn + sql_mode=ANSI_QUOTES`:

- `connected` → all servers

### connect as zdb_plain with sqlMode '': result

On `dyn + sql_mode=NO_BACKSLASH_ESCAPES`:

- `RuntimeException 0: NO_BACKSLASH_ESCAPES is on for this connection, and ZenDB escapes values with backslashes. Remove it from sqlMode, or from the server's global sql_mode if sqlMode is ''.` → all servers

On `dyn + sql_mode=ANSI_QUOTES`:

- `connected` → all servers

### connect as zdb_plain with sqlMode '': time_zone / sql_mode

On `dyn + sql_mode=ANSI_QUOTES`:

- `+05:30 / ANSI_QUOTES` → all servers

## 14. The PHP Side When connect() Sends Its SET Statement

Each value lists what a traced connect did, in order, with the charset the PHP side was on at that moment: `set_charset()` if connect called it, connect's SET statement, and the state it ended in.

### C14 fresh connect

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`:

- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session latin1 / latin1 / latin1 / latin1_swedish_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+

On `skip-utf8mb3`:

- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session utf8 / utf8 / utf8 / utf8_general_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and MariaDB thru 10.5
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+
- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MariaDB 10.6+

On `skip-gbk`, `initconnect-gbk`:

- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session gbk / gbk / gbk / gbk_chinese_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+

On `coll-over-255`:

- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

On every other variant:

- `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+

### C14 fresh connect, wrapper that drops the charset option

On `default`, `dyn`:

- `set_charset() called on latin1; SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and MariaDB thru 10.4
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+
- `SET sent on utf8mb4; ends utf8mb4 on both sides` → MariaDB 10.5+

On `cs-latin1`, `skip-latin1`, `handshake0-latin1`:

- `set_charset() called on latin1; SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

On `cs-utf8mb3`, `skip-utf8mb3`:

- `set_charset() called on utf8; SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

On `cs-utf8mb4`, `skip-coll-unicode`, `coll-unicode`:

- `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+

On `skip-gbk`, `coll-over-255`:

- `set_charset() called on gbk; SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

On `skip-ucs2`:

- `set_charset() called on ucs2; SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

On `initconnect-latin1`:

- `set_charset() called on latin1; SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and MariaDB thru 10.4
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+
- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session latin1 / latin1 / latin1 / latin1_swedish_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MariaDB 10.5+

On `initconnect-gbk`:

- `set_charset() called on latin1; SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and MariaDB thru 10.4
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+
- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session gbk / gbk / gbk / gbk_chinese_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MariaDB 10.5+

### C14 pooled slot left on gbk

On every variant:

- `set_charset() called on gbk; SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

### C14 pooled slot left on gbk, wrapper that drops the charset option

On every variant:

- `set_charset() called on gbk; SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

### C14 databaseAutoCreate (second real_connect), fresh connect

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`:

- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session latin1 / latin1 / latin1 / latin1_swedish_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+

On `skip-utf8mb3`:

- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session utf8 / utf8 / utf8 / utf8_general_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and MariaDB thru 10.5
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+
- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MariaDB 10.6+

On `skip-gbk`, `initconnect-gbk`:

- v1.0.0 `SET sent on utf8mb4; ends PHP side utf8mb4, session gbk / gbk / gbk / gbk_chinese_ci`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+

On `coll-over-255`:

- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

On every other variant:

- `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 5.7 and all MariaDB
- v1.0.0 `set_charset() called on utf8mb4; SET sent on utf8mb4; ends utf8mb4 on both sides`, new `SET sent on utf8mb4; ends utf8mb4 on both sides` → MySQL/Percona 8.0+

### C14 databaseAutoCreate (second real_connect), pooled no-database slot left on gbk

On every variant:

- `set_charset() called on gbk; SET sent on utf8mb4; ends utf8mb4 on both sides` → all servers

### C14 databaseAutoCreate: created database collation

On `coll-over-255`:

- `utf8mb4_0900_ai_ci` → all servers

On every other variant:

- `utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4_0900_ai_ci` → MySQL/Percona 8.0+
- `utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

## After Connect: change_user(), select_db(), Reconnect

### X1 after mysqli change_user() on a connected Connection: PHP side, session charsets

On `skip-latin1`, `handshake0-latin1`, `skip-ucs2`:

- `utf8mb4, latin1 / latin1 / latin1 / latin1_swedish_ci` → all servers

On `skip-utf8mb3`:

- `utf8mb4, utf8 / utf8 / utf8 / utf8_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.5
- `utf8mb4, utf8mb3 / utf8mb3 / utf8mb3 / utf8mb3_general_ci` → MySQL/Percona 8.0+ and MariaDB 10.6+

On `skip-gbk`:

- `utf8mb4, gbk / gbk / gbk / gbk_chinese_ci` → all servers

On `skip-coll-unicode`:

- `utf8mb4, utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_unicode_ci` → all servers

On `coll-over-255`:

- `utf8mb4, utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → all servers

On every other variant:

- `utf8mb4, utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci` → all MySQL/Percona and MariaDB thru 10.11
- `utf8mb4, utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_uca1400_ai_ci` → MariaDB 11.4+

### X1 after mysqli change_user() on a connected Connection: time_zone and sql_mode

On every variant:

- `back on the server's global values` → all servers

### X2 after select_db() to a latin1 database

On every variant:

- `session charsets unchanged, character_set_database latin1` → all servers

### X4 disconnect() then connect(): PHP side and all three session charsets are utf8mb4

On `default`, `cs-latin1`, `cs-utf8mb3`, `cs-utf8mb4`, `skip-coll-unicode`, `coll-unicode`, `dyn`, `coll-over-255`:

- `yes` → all servers

On `skip-latin1`, `skip-utf8mb3`, `skip-gbk`, `handshake0-latin1`, `skip-ucs2`, `initconnect-latin1`, `initconnect-gbk`:

- v1.0.0 `NO`, new `yes` → MySQL/Percona 5.7 and all MariaDB
- `yes` → MySQL/Percona 8.0+

## ProxySQL

The same connect through ProxySQL with a mysql:8.0 backend (8.0.46), PHP 8.3.36. Versions: 2.5.5 (2.5.5-10-g195bd70), 2.7.3 (2.7.3-12-g50b7f85), 3.0.12 (3.0.12-1732-ge2f317a). Each line is the value → the ProxySQL versions that gave it.

### mysql-default_charset / mysql-set_query_lock_on_hostgroup / mysql-multiplexing

- `utf8 / 1 / true` → all versions

### connect

- `connected` → all versions

### right after connect

- `PHP side utf8mb4, session utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci / +05:30 / STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → all versions

### what the backend server logged for the first connect and its session query

- `Connect > Query(SET sql_mode='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SET time_zone='+05:30') > Query(SELECT @@character_set_client, @@character_set_connection, @@character_set_results, @@collation_connection) > Query(SELECT @@time_zone, @@sql_mode)` → 2.5.5, 3.0.12
- v1.0.0 `Query(SET NAMES utf8mb4 COLLATE 'utf8mb4_general_ci') > Query(SET sql_mode='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SET time_zone='+05:30') > Query(SELECT @@character_set_client, @@character_set_connection, @@character_set_results, @@collation_connection) > Query(SELECT @@time_zone, @@sql_mode)`, new `Connect > Query(SET sql_mode='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SET time_zone='+05:30') > Query(SELECT @@character_set_client, @@character_set_connection, @@character_set_results, @@collation_connection) > Query(SELECT @@time_zone, @@sql_mode)` → 2.7.3

### this connection after another client ran SET NAMES latin1 and statements on both

- `PHP side utf8mb4, session utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci / +05:30 / STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → all versions

### the other client after statements on both

- `PHP side utf8mb4, session latin1 / latin1 / latin1 / latin1_swedish_ci / +09:00 / ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → all versions

### second connect, when the one backend connection was last used on latin1

- `PHP side utf8mb4, session utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_general_ci / +05:30 / STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → all versions

### what the backend server logged for that second connect and its session query

- `Query(SET NAMES utf8mb4 COLLATE 'utf8mb4_general_ci') > Query(SET sql_mode='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') > Query(SET time_zone='+05:30') > Query(SELECT @@character_set_client, @@character_set_connection, @@character_set_results, @@collation_connection) > Query(SELECT @@time_zone, @@sql_mode)` → all versions

### SET statements ProxySQL counted

- v1.0.0 `SET @zdb_value = ? ; SET NAMES latin1 ; SET NAMES utf8mb4 ; SET sql_mode = ? ; SET time_zone = ? ; SET time_zone = ?,sql_mode = ?`, new `SET @zdb_value = ? ; SET NAMES latin1 ; SET NAMES utf8mb4 ; SET NAMES utf8mb4,sql_mode = ? ; SET NAMES utf8mb4,time_zone = ? ; SET NAMES utf8mb4,time_zone = ?,sql_mode = ? ; SET time_zone = ?` → all versions

### SET statements it could not parse (locks the hostgroup)

- `SET @zdb_value = 'a'` → all versions

### ? placeholder, 12 values: one row with the same bytes

- `all` → all versions

### table round trip of cafe with e-acute and an emoji

- `sent 636166c3a920f09f9880, stored 636166c3a920f09f9880, read back 636166c3a920f09f9880` → all versions

### C12: connection, new table and new database collations

- `DIFFER: connection utf8mb4_general_ci, table utf8mb4_0900_ai_ci, database utf8mb4_0900_ai_ci` → all versions

### C12: utf8mb4 column = 'a'

- `ok, 1 row` → all versions

### C12: utf8mb4 column = @user_variable

- `error 1267: Illegal mix of collations (utf8mb4_0900_ai_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='` → all versions

