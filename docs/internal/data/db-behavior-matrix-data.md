# DB Behavior Matrix: Data

The results behind [db-behavior-matrix.md](../db-behavior-matrix.md): every probe, in the order the probe script runs them. A probe that every server answered the same way is one `all servers` line; the others list which servers gave which answer.

*Generated 2026-11-19 from 22 servers. Generated file: to change it, see [Regenerating This Report](../db-behavior-matrix.md#regenerating-this-report).*

Servers: mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## VERSION()

- `5.7.44` → mysql:5.7
- `8.0.46` → mysql:8.0
- `8.4.11` → mysql:8.4
- `9.6.0` → mysql:9.6
- `9.7.2` → mysql:9.7
- `10.2.44-MariaDB-1:10.2.44+maria~bionic` → mariadb:10.2
- `10.2.6-MariaDB-10.2.6+maria~jessie` → mariadb:10.2.6
- `10.2.7-MariaDB-10.2.7+maria~jessie` → mariadb:10.2.7
- `10.3.39-MariaDB-1:10.3.39+maria~ubu2004` → mariadb:10.3
- `10.4.34-MariaDB-1:10.4.34+maria~ubu2004` → mariadb:10.4
- `10.5.29-MariaDB-ubu2004` → mariadb:10.5
- `10.6.28-MariaDB-ubu2204` → mariadb:10.6
- `10.11.19-MariaDB-ubu2204` → mariadb:10.11
- `11.4.13-MariaDB-ubu2404` → mariadb:11.4
- `11.8.9-MariaDB-ubu2404` → mariadb:11.8+rocksdb, mariadb:11.8
- `12.3.3-MariaDB-ubu2404` → mariadb:12.3+rocksdb, mariadb:12.3
- `5.7.44-48` → percona/percona-server:5.7
- `8.0.46-37` → percona/percona-server:8.0+rocksdb, percona/percona-server:8.0
- `8.4.11-11` → percona/percona-server:8.4

## @@version_comment

- `MySQL Community Server (GPL)` → mysql:5.7
- `MySQL Community Server - GPL` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7
- `mariadb.org binary distribution` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3
- `Percona Server (GPL), Release 48, Revision 497f936a373` → percona/percona-server:5.7
- `Percona Server (GPL), Release 37, Revision 39e2b60e` → percona/percona-server:8.0+rocksdb, percona/percona-server:8.0
- `Percona Server (GPL), Release 11, Revision 57878ff8` → percona/percona-server:8.4

## mysqli server_info

- `5.7.44` → mysql:5.7
- `8.0.46` → mysql:8.0
- `8.4.11` → mysql:8.4
- `9.6.0` → mysql:9.6
- `9.7.2` → mysql:9.7
- `10.2.44-MariaDB-1:10.2.44+maria~bionic` → mariadb:10.2
- `10.2.6-MariaDB-10.2.6+maria~jessie` → mariadb:10.2.6
- `10.2.7-MariaDB-10.2.7+maria~jessie` → mariadb:10.2.7
- `10.3.39-MariaDB-1:10.3.39+maria~ubu2004` → mariadb:10.3
- `10.4.34-MariaDB-1:10.4.34+maria~ubu2004` → mariadb:10.4
- `10.5.29-MariaDB-ubu2004` → mariadb:10.5
- `10.6.28-MariaDB-ubu2204` → mariadb:10.6
- `10.11.19-MariaDB-ubu2204` → mariadb:10.11
- `11.4.13-MariaDB-ubu2404` → mariadb:11.4
- `11.8.9-MariaDB-ubu2404` → mariadb:11.8+rocksdb, mariadb:11.8
- `12.3.3-MariaDB-ubu2404` → mariadb:12.3+rocksdb, mariadb:12.3
- `5.7.44-48` → percona/percona-server:5.7
- `8.0.46-37` → percona/percona-server:8.0+rocksdb, percona/percona-server:8.0
- `8.4.11-11` → percona/percona-server:8.4

## mysqli server_version

- `50744` → MySQL/Percona 5.7
- `80046` → mysql:8.0, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0
- `80411` → mysql:8.4, percona/percona-server:8.4
- `90600` → mysql:9.6
- `90702` → mysql:9.7
- `100244` → mariadb:10.2
- `100206` → mariadb:10.2.6
- `100207` → mariadb:10.2.7
- `100339` → mariadb:10.3
- `100434` → mariadb:10.4
- `100529` → mariadb:10.5
- `100628` → mariadb:10.6
- `101119` → mariadb:10.11
- `110413` → mariadb:11.4
- `110809` → mariadb:11.8+rocksdb, mariadb:11.8
- `120303` → mariadb:12.3+rocksdb, mariadb:12.3

## server_info after Server::version() parse

- `5.7.44` → MySQL/Percona 5.7
- `8.0.46` → mysql:8.0, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0
- `8.4.11` → mysql:8.4, percona/percona-server:8.4
- `9.6.0` → mysql:9.6
- `9.7.2` → mysql:9.7
- `10.2.44` → mariadb:10.2
- `10.2.6` → mariadb:10.2.6
- `10.2.7` → mariadb:10.2.7
- `10.3.39` → mariadb:10.3
- `10.4.34` → mariadb:10.4
- `10.5.29` → mariadb:10.5
- `10.6.28` → mariadb:10.6
- `10.11.19` → mariadb:10.11
- `11.4.13` → mariadb:11.4
- `11.8.9` → mariadb:11.8+rocksdb, mariadb:11.8
- `12.3.3` → mariadb:12.3+rocksdb, mariadb:12.3

## @@basedir

- `/usr/` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `/usr` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## @@datadir

- all servers: `/var/lib/mysql/`

## @@GLOBAL.sql_mode

- `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` → MySQL/Percona 5.7
- `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## utf8mb4_unicode_ci collation

- all servers: `available`

## @@have_ssl

- `YES` → mysql:5.7, mysql:8.0, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0
- `error: Unknown system variable 'have_ssl'` → MySQL/Percona 8.4+
- `DISABLED` → MariaDB thru 10.11

## @@have_openssl

- `YES` → mysql:5.7, mysql:8.0, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0
- `error: Unknown system variable 'have_openssl'` → MySQL/Percona 8.4+

## @@tls_version

- `TLSv1,TLSv1.1,TLSv1.2` → MySQL/Percona 5.7
- `TLSv1.2,TLSv1.3` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `error: Unknown system variable 'tls_version'` → MariaDB thru 10.3

## @@require_secure_transport

- `0` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `error: Unknown system variable 'require_secure_transport'` → MariaDB thru 10.4

## session status Ssl_cipher

- all servers: `(empty = this connection is not encrypted)`

## session status Ssl_version

- all servers: `(empty = this connection is not encrypted)`

## SHOW CREATE: num

- `int(11) NOT NULL AUTO_INCREMENT` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `int NOT NULL AUTO_INCREMENT` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SHOW CREATE: isAdmin

- `tinyint(1) NOT NULL DEFAULT '0'` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `tinyint(1) NOT NULL DEFAULT 0` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: flags

- `tinyint(1) unsigned NOT NULL DEFAULT '0'` → MySQL/Percona 5.7
- `tinyint unsigned NOT NULL DEFAULT '0'` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `tinyint(1) unsigned NOT NULL DEFAULT 0` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: counter

- `int(11) NOT NULL DEFAULT '0'` → MySQL/Percona 5.7
- `int NOT NULL DEFAULT '0'` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `int(11) NOT NULL DEFAULT 0` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: price

- `decimal(10,2) NOT NULL DEFAULT '1.50'` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `decimal(10,2) NOT NULL DEFAULT 1.50` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: birthYear

- `year(4) NOT NULL` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `year NOT NULL` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SHOW CREATE: title

- all servers: `varchar(255) NOT NULL DEFAULT ''`

## SHOW CREATE: legacyText

- `varchar(100) CHARACTER SET latin1 NOT NULL DEFAULT ''` → MySQL/Percona 5.7 and MariaDB thru 10.2.7
- `varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT ''` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SHOW CREATE: createdDate

- `timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: createdMs

- `datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3)` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: updatedDate

- all servers: `datetime DEFAULT NULL COMMENT 'it''s optional'`

## SHOW CREATE: negDefault

- `int(11) NOT NULL DEFAULT '-1'` → MySQL/Percona 5.7
- `int NOT NULL DEFAULT '-1'` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `int(11) NOT NULL DEFAULT -1` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: ratio

- `double NOT NULL DEFAULT '0.00001'` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `double NOT NULL DEFAULT 0.00001` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: dtLiteral

- all servers: `datetime NOT NULL DEFAULT '2024-01-01 00:00:00'`

## SHOW CREATE: bitDefault

- all servers: `bit(4) NOT NULL DEFAULT b'101'`

## SHOW CREATE: numText

- all servers: `varchar(10) NOT NULL DEFAULT '123'`

## SHOW CREATE: body

- `mediumtext` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `mediumtext DEFAULT NULL` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: keywordText

- all servers: `varchar(50) NOT NULL DEFAULT 'save DEFAULT 5 each' COMMENT 'uses CHARACTER SET utf8mb4'`

## SHOW CREATE: table options

- `) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` → MySQL/Percona 5.7 and MariaDB thru 10.2.7
- `) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci` → mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11
- `) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: oldText VARCHAR(50) CHARSET utf8

- `varchar(50) CHARACTER SET utf8 NOT NULL DEFAULT ''` → MySQL/Percona 5.7 and MariaDB thru 10.2.7
- `varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT ''` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.6, mariadb:10.11, mariadb:11.4, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT ''` → mariadb:10.3, mariadb:10.4, mariadb:10.5
- `varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_uca1400_ai_ci NOT NULL DEFAULT ''` → mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: code VARCHAR(36) DEFAULT (uuid())

- `CREATE TABLE rejected: You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near '(uuid()))' at line 1` → MySQL/Percona 5.7
- `varchar(36) NOT NULL DEFAULT (uuid())` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `varchar(36) NOT NULL DEFAULT uuid()` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: notes MEDIUMTEXT DEFAULT 'abc'

- `CREATE TABLE rejected: BLOB, TEXT, GEOMETRY or JSON column 'notes' can't have a default value` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `mediumtext DEFAULT 'abc'` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW CREATE: padded INT(6) ZEROFILL

- `int(6) unsigned zerofill NOT NULL DEFAULT '000000'` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `int(6) unsigned zerofill NOT NULL DEFAULT 000000` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## ZEROFILL: SELECT 42 via text protocol

- all servers: `000042`

## ZEROFILL: SELECT 42 via prepared statement

- all servers: `000042`

## ZEROFILL: field ZEROFILL flag

- all servers: `set`

## GENERATED: doubled INT AS (num * 2) VIRTUAL

- `` int(11) GENERATED ALWAYS AS ((`num` * 2)) VIRTUAL `` → MySQL/Percona 5.7
- `` int GENERATED ALWAYS AS ((`num` * 2)) VIRTUAL `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` int(11) GENERATED ALWAYS AS (`num` * 2) VIRTUAL `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## GENERATED: tripled INT AS (num * 3) STORED

- `` int(11) GENERATED ALWAYS AS ((`num` * 3)) STORED `` → MySQL/Percona 5.7
- `` int GENERATED ALWAYS AS ((`num` * 3)) STORED `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` int(11) GENERATED ALWAYS AS (`num` * 3) STORED `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## GENERATED: SELECT with num = 21

- all servers: `doubled=42, tripled=63`

## CHECK: SHOW CREATE clauses

- `none (CHECK dropped)` → MySQL/Percona 5.7
- `` CONSTRAINT `zdb_age_max` CHECK ((`age` <= 150)); CONSTRAINT `zdb_probe_special_chk_1` CHECK ((`age` >= 0)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` `age` int(11) NOT NULL CHECK (`age` >= 0); CONSTRAINT `zdb_age_max` CHECK (`age` <= 150) `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK: INSERT -5 (violates column CHECK)

- `accepted (not enforced)` → MySQL/Percona 5.7
- `rejected (enforced, error 3819)` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `rejected (enforced, error 4025)` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK: INSERT 200 (violates named constraint)

- `accepted (not enforced)` → MySQL/Percona 5.7
- `rejected (enforced, error 3819)` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `rejected (enforced, error 4025)` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK add: CREATE with unnamed table CHECK (auto-name)

- `accepted → no CHECK clauses` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_probe_check_chk_1` CHECK ((`age` >= 0)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` accepted → CONSTRAINT `CONSTRAINT_1` CHECK (`age` >= 0) `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK add: CREATE with named column CHECK

- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'CONSTRAINT zdb_age_min CHECK (age >= 0))' at line 1` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_age_min` CHECK ((`age` >= 0)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'CONSTRAINT zdb_age_min CHECK (age >= 0))' at line 1` → MariaDB thru 10.4
- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'CHECK (age >= 0))' at line 1` → mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK add: ALTER ADD CONSTRAINT named

- `accepted → no CHECK clauses` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_age_max` CHECK ((`age` <= 150)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` accepted → CONSTRAINT `zdb_age_max` CHECK (`age` <= 150) `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK add: ALTER ADD CHECK unnamed

- `accepted → no CHECK clauses` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_probe_check_chk_1` CHECK ((`age` >= 0)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` accepted → CONSTRAINT `CONSTRAINT_1` CHECK (`age` >= 0) `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK add: MODIFY COLUMN with inline CHECK

- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'CHECK (age >= 0)' at line 1` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_probe_check_chk_1` CHECK ((`age` >= 0)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` accepted → `age` int(11) NOT NULL CHECK (`age` >= 0) `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK add: second MODIFY with a different inline CHECK

- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'CHECK (age >= 1)' at line 1` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_probe_check_chk_1` CHECK ((`age` >= 0)); CONSTRAINT `zdb_probe_check_chk_2` CHECK ((`age` >= 1)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` accepted → `age` int(11) NOT NULL CHECK (`age` >= 1) `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK modify: MODIFY COLUMN without restating its column CHECK

- `accepted → no CHECK clauses` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `` accepted → CONSTRAINT `zdb_probe_check_chk_1` CHECK ((`age` >= 0)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## CHECK modify: MODIFY COLUMN with a table CHECK present

- `accepted → no CHECK clauses` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_age_max` CHECK ((`age` <= 150)) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` accepted → CONSTRAINT `zdb_age_max` CHECK (`age` <= 150) `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK alter: ALTER CHECK name NOT ENFORCED

- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'CHECK zdb_age_max NOT ENFORCED' at line 1` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_age_max` CHECK ((`age` <= 150)) /*!80016 NOT ENFORCED */ `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'CHECK zdb_age_max NOT ENFORCED' at line 1` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK alter: ALTER CONSTRAINT name NOT ENFORCED

- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'CONSTRAINT zdb_age_max NOT ENFORCED' at line 1` → MySQL/Percona 5.7
- `` accepted → CONSTRAINT `zdb_age_max` CHECK ((`age` <= 150)) /*!80016 NOT ENFORCED */ `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'CONSTRAINT zdb_age_max NOT ENFORCED' at line 1` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK drop: DROP CONSTRAINT name

- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'CONSTRAINT zdb_age_max' at line 1` → MySQL/Percona 5.7
- `accepted → no CHECK clauses` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## CHECK drop: DROP CHECK name

- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'CHECK zdb_age_max' at line 1` → MySQL/Percona 5.7
- `accepted → no CHECK clauses` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'CHECK zdb_age_max' at line 1` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK drop: column CHECK by its auto-name

- `I_S lookup failed (error 1109): Unknown table 'CHECK_CONSTRAINTS' in information_schema` → mysql:5.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7
- `I_S names it 'zdb_probe_check_chk_1'; DROP CONSTRAINT: accepted → no CHECK clauses` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` I_S names it 'age'; DROP CONSTRAINT: rejected (error 1091): Can't DROP CONSTRAINT `age`; check that it exists `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK column: DROP COLUMN with its own column CHECK

- all servers: `accepted → no CHECK clauses`

## CHECK column: DROP COLUMN referenced by a table CHECK

- `accepted → no CHECK clauses` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `rejected (error 1054): Unknown column 'age' in 'CHECK'` → mariadb:10.2.6, mariadb:10.2.7

## CHECK column: CHANGE rename with column CHECK restated under the new name

- `rejected (error 1064): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'CHECK (age2 >= 0)' at line 1` → MySQL/Percona 5.7
- `rejected (error 3959): Check constraint 'zdb_probe_check_chk_1' uses column 'age', hence column cannot be dropped or renamed.` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` accepted → `age2` int(11) NOT NULL CHECK (`age2` >= 0) `` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK column: CHANGE rename of a column referenced by a table CHECK

- `accepted → no CHECK clauses` → MySQL/Percona 5.7
- `rejected (error 3959): Check constraint 'zdb_age_max' uses column 'age', hence column cannot be dropped or renamed.` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` accepted → CONSTRAINT `zdb_age_max` CHECK (`age2` <= 150) `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3
- `rejected (error 1054): Unknown column 'age' in 'CHECK'` → mariadb:10.2.6, mariadb:10.2.7

## CHECK list: I_S.CHECK_CONSTRAINTS rows

- `failed (error 1109): Unknown table 'CHECK_CONSTRAINTS' in information_schema` → mysql:5.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7
- `` zdb_age_max: (`age` <= 150); zdb_probe_check_chk_1: (`age` >= 0) `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` age: `age` >= 0; zdb_age_max: `age` <= 150 `` → mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## CHECK list: I_S.TABLE_CONSTRAINTS type=CHECK rows

- `no rows` → MySQL/Percona 5.7
- `zdb_age_max, zdb_probe_check_chk_1` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `age, zdb_age_max` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## @@block_encryption_mode

- `aes-128-ecb` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `variable not supported` → MariaDB thru 10.11

## HEX(AES_ENCRYPT) with SHA2-512 key

- all servers: `0E45A282E3006E2BB5C1578B91B1DE53`

## AES_ENCRYPT matches PHP openssl aes-128-ecb

- all servers: `match`

## Field metadata: MEDIUMBLOB

- all servers: `BLOB, charsetnr=63, length=16777215`

## Field metadata: BLOB

- all servers: `BLOB, charsetnr=63, length=65535`

## Field metadata: MEDIUMTEXT

- `BLOB, charsetnr=45, length=67108860` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `BLOB, charsetnr=255, length=67108860` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `BLOB, charsetnr=224, length=67108860` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## Field metadata: VARBINARY(16)

- all servers: `VAR_STRING, charsetnr=63, length=16`

## Field metadata: AES_DECRYPT(mb, @ek) expression

- all servers: `MEDIUM_BLOB, charsetnr=63, length=16777215`

## AES_DECRYPT matches encryptable MEDIUMBLOB signature

- all servers: `no`

## Field metadata: aliased column

- all servers: `table=a, orgtable=zdb_probe_special, orgname=num`

## Field metadata: viewCol column

- `table=v, orgtable=zdb_probe_view, orgname=num` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `table=v, orgtable=v, orgname=num` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## Field metadata: expr column

- all servers: `table='', orgtable='', orgname=''`

## SHOW TABLES LIKE 'zdb_no_match%' with temp table present (MDEV-32973)

- all servers: `pattern honored, returned 0 rows`

## SHOW TABLES: temp table

- `not listed` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `listed` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW FULL TABLES: temp table Table_type

- `not listed` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `TEMPORARY TABLE` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SHOW FULL TABLES: view Table_type

- all servers: `VIEW`

## INFORMATION_SCHEMA.TABLES: temp table TABLE_TYPE

- `not listed` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `TEMPORARY` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## INFORMATION_SCHEMA.TABLES: real table TABLE_TYPE

- all servers: `BASE TABLE`

## INFORMATION_SCHEMA.TABLES: view TABLE_TYPE

- all servers: `VIEW`

## SELECT 0x1AF

- all servers: `0x01af (VAR_STRING)`

## SELECT 0b1010

- all servers: `0x0a (VAR_STRING)`

## SELECT 1e10

- all servers: `10000000000 (DOUBLE)`

## 1 IN (1, NULL)

- all servers: `1`

## 2 IN (1, NULL)

- all servers: `NULL`

## 2 NOT IN (1, NULL)

- all servers: `NULL`

## SELECT NULL field type

- all servers: `NULL`

## SHOW VARIABLES 'have_ssl'

- `YES` → mysql:5.7, mysql:8.0, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0
- `no rows (variable removed)` → MySQL/Percona 8.4+
- `DISABLED` → MariaDB thru 10.11

## SHOW VARIABLES 'require_secure_transport'

- `OFF` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `no rows (variable not present)` → MariaDB thru 10.4

## SHOW STATUS Ssl_cipher/Ssl_version (plain connection)

- all servers: `Ssl_cipher='', Ssl_version=''`

## @@GLOBAL.general_log, @@GLOBAL.log_output

- all servers: `general_log=0, log_output=FILE`

## slow-log variable names present

- all servers: `slow_query_log`

## explicit_defaults_for_timestamp

- `OFF` → MySQL/Percona 5.7 and MariaDB thru 10.6
- `ON` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SHOW CREATE: ts TIMESTAMP (bare)

- `timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` → MySQL/Percona 5.7
- `timestamp NULL DEFAULT NULL` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` → MariaDB thru 10.6

## DEFAULTS I_S COLUMN_DEFAULT: noDefault

- `SQL NULL` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: NULL` → mariadb:10.2, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S COLUMN_DEFAULT: explicitNull

- `SQL NULL` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: NULL` → mariadb:10.2, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S COLUMN_DEFAULT: nullString

- `text: NULL` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: 'NULL'` → mariadb:10.2, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S COLUMN_DEFAULT: strDefault

- `text: abc` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: 'abc'` → mariadb:10.2, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S COLUMN_DEFAULT: numDefault

- all servers: `text: 5`

## DEFAULTS I_S COLUMN_DEFAULT: exprDefault

- `text: CURRENT_TIMESTAMP` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: current_timestamp()` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S via view: noDefault

- `SQL NULL` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: NULL` → mariadb:10.2, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S via view: explicitNull

- `SQL NULL` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: NULL` → mariadb:10.2, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S via view: nullString

- `text: NULL` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: 'NULL'` → mariadb:10.2, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S via view: strDefault

- `text: abc` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: 'abc'` → mariadb:10.2, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS I_S via view: numDefault

- all servers: `text: 5`

## DEFAULTS I_S via view: exprDefault

- `text: 0000-00-00 00:00:00` → MySQL/Percona 5.7
- `text: CURRENT_TIMESTAMP` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: current_timestamp()` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS SHOW COLUMNS: noDefault

- all servers: `SQL NULL`

## DEFAULTS SHOW COLUMNS: explicitNull

- all servers: `SQL NULL`

## DEFAULTS SHOW COLUMNS: nullString

- all servers: `text: NULL`

## DEFAULTS SHOW COLUMNS: strDefault

- all servers: `text: abc`

## DEFAULTS SHOW COLUMNS: numDefault

- all servers: `text: 5`

## DEFAULTS SHOW COLUMNS: exprDefault

- `text: CURRENT_TIMESTAMP` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: current_timestamp()` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS SHOW FULL COLUMNS matches SHOW COLUMNS

- all servers: `identical`

## DEFAULTS SHOW CREATE: noDefault

- all servers: `varchar(10) DEFAULT NULL`

## DEFAULTS SHOW CREATE: explicitNull

- all servers: `varchar(10) DEFAULT NULL`

## DEFAULTS SHOW CREATE: nullString

- all servers: `varchar(10) DEFAULT 'NULL'`

## DEFAULTS SHOW CREATE: strDefault

- all servers: `varchar(10) DEFAULT 'abc'`

## DEFAULTS SHOW CREATE: numDefault

- `int(11) DEFAULT '5'` → MySQL/Percona 5.7
- `int DEFAULT '5'` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `int(11) DEFAULT 5` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS SHOW CREATE: exprDefault

- `timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `timestamp NOT NULL DEFAULT current_timestamp()` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## DEFAULTS SELECT DEFAULT(noDefault)

- all servers: `SQL NULL`

## DEFAULTS SELECT DEFAULT(explicitNull)

- all servers: `SQL NULL`

## DEFAULTS SELECT DEFAULT(nullString)

- all servers: `text: NULL`

## DEFAULTS SELECT DEFAULT(strDefault)

- all servers: `text: abc`

## DEFAULTS SELECT DEFAULT(numDefault)

- all servers: `text: 5`

## DEFAULTS SELECT DEFAULT(exprDefault)

- `text: 0000-00-00 00:00:00` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `text: <evaluated to current datetime>` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## @@collation_connection after set_charset(utf8mb4)

- `utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4_0900_ai_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `utf8mb4_uca1400_ai_ci` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## @@character_set_results

- all servers: `utf8mb4`

## utf8mb4 table: implicit column collation

- `utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4_0900_ai_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `utf8mb4_uca1400_ai_ci` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## compare implicit vs utf8mb4_unicode_ci column

- all servers: `error 1267`

## utf8mb4_0900_ai_ci collation

- `missing` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `available` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## error code: USE unknown database

- all servers: `1049 / SQLSTATE 42000`

## error code: duplicate key

- all servers: `1062 / SQLSTATE 23000`

## error code: unknown column

- all servers: `1054 / SQLSTATE 42S22`

## PARTITIONED: SHOW CREATE from table options on

- `) ENGINE=InnoDB DEFAULT CHARSET=latin1\n/*!50100 PARTITION BY RANGE (num)\n(PARTITION p0 VALUES LESS THAN (10) ENGINE = InnoDB,\n PARTITION p1 VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */` → MySQL/Percona 5.7
- `` ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci\n/*!50100 PARTITION BY RANGE (`num`)\n(PARTITION p0 VALUES LESS THAN (10) ENGINE = InnoDB,\n PARTITION p1 VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */ `` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `` ) ENGINE=InnoDB DEFAULT CHARSET=latin1\n PARTITION BY RANGE (`num`)\n(PARTITION `p0` VALUES LESS THAN (10) ENGINE = InnoDB,\n PARTITION `p1` VALUES LESS THAN MAXVALUE ENGINE = InnoDB) `` → mariadb:10.2, mariadb:10.2.7
- `) ENGINE=InnoDB DEFAULT CHARSET=latin1\n PARTITION BY RANGE (num)\n(PARTITION p0 VALUES LESS THAN (10) ENGINE = InnoDB,\n PARTITION p1 VALUES LESS THAN MAXVALUE ENGINE = InnoDB)` → mariadb:10.2.6
- `` ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci\n PARTITION BY RANGE (`num`)\n(PARTITION `p0` VALUES LESS THAN (10) ENGINE = InnoDB,\n PARTITION `p1` VALUES LESS THAN MAXVALUE ENGINE = InnoDB) `` → mariadb:10.3, mariadb:10.4
- `` ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n PARTITION BY RANGE (`num`)\n(PARTITION `p0` VALUES LESS THAN (10) ENGINE = InnoDB,\n PARTITION `p1` VALUES LESS THAN MAXVALUE ENGINE = InnoDB) `` → mariadb:10.5, mariadb:10.6, mariadb:10.11
- `` ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci\n PARTITION BY RANGE (`num`)\n(PARTITION `p0` VALUES LESS THAN (10) ENGINE = InnoDB,\n PARTITION `p1` VALUES LESS THAN MAXVALUE ENGINE = InnoDB) `` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## INT_AND_FLOAT_NATIVE: num

- all servers: `int 42 (LONG)`

## INT_AND_FLOAT_NATIVE: price

- all servers: `string 1.50 (NEWDECIMAL)`

## INT_AND_FLOAT_NATIVE: birthYear

- all servers: `string 1999 (YEAR)`

## INT_AND_FLOAT_NATIVE: bits

- all servers: `int 42 (BIT)`

## INT_AND_FLOAT_NATIVE: SUM(num)

- all servers: `string 42 (NEWDECIMAL)`

## INT_AND_FLOAT_NATIVE: AVG(num)

- all servers: `string 42.0000 (NEWDECIMAL)`

## INT_AND_FLOAT_NATIVE: 1/2

- all servers: `string 0.5000 (NEWDECIMAL)`

## SET time_zone = '+05:45'

- all servers: `accepted`

## SET time_zone = '+13:00'

- all servers: `accepted`

## SET time_zone = '-13:00'

- `rejected: error 1298` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `accepted` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SET time_zone = '+14:00'

- `rejected: error 1298` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `accepted` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SET time_zone = 'Etc/GMT-14'

- all servers: `accepted`

## SET time_zone = 'Pacific/Kiritimati'

- all servers: `accepted`

## SET time_zone = 'Pacific/Chatham'

- all servers: `accepted`

## mysql.time_zone_name row count

- `1789` → MySQL/Percona 5.7
- `1795` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `1823` → mariadb:10.2
- `1820` → mariadb:10.2.6, mariadb:10.2.7
- `1829` → mariadb:10.3, mariadb:10.4
- `1832` → mariadb:10.5
- `1793` → mariadb:10.6, mariadb:10.11
- `498` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## SNAPSHOT: @@transaction_isolation server default

- `REPEATABLE-READ` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `error 1193` → MariaDB thru 10.11

## SNAPSHOT: @@tx_isolation server default

- `REPEATABLE-READ` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `error 1193` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SNAPSHOT: bare START at READ-COMMITTED

- `138: InnoDB: WITH CONSISTENT SNAPSHOT was ignored because this phrase can only be used with REPEATABLE READ isolation level.; concurrent INSERT visible (no snapshot)` → all MySQL/Percona and MariaDB thru 10.5
- `accepted; concurrent INSERT visible (no snapshot)` → MariaDB 10.6+
- `error 4062: Only REPEATABLE READ isolation level is supported for START TRANSACTION WITH CONSISTENT SNAPSHOT in RocksDB Storage Engine.` → mariadb:11.8+rocksdb, mariadb:12.3+rocksdb
- `138: RocksDB: Only REPEATABLE READ isolation level is supported for START TRANSACTION WITH CONSISTENT SNAPSHOT in RocksDB Storage Engine. Snapshot has not been taken.; concurrent INSERT visible (no snapshot)` → percona/percona-server:8.0+rocksdb

## SNAPSHOT: SET+START pair at READ-COMMITTED

- all servers: `accepted; concurrent INSERT invisible (snapshot held)`

## SNAPSHOT: pair with SELECT 1 between SET and START

- all servers: `accepted; concurrent INSERT invisible (snapshot held)`

## SNAPSHOT: pair with isolation variable read between

- all servers: `accepted; concurrent INSERT invisible (snapshot held)`

## SNAPSHOT: @@transaction_isolation inside pair transaction

- `READ-COMMITTED` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- (no data) → MariaDB thru 10.11

## SNAPSHOT: @@transaction_isolation after COMMIT

- `READ-COMMITTED` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- (no data) → MariaDB thru 10.11

## SNAPSHOT: SET TRANSACTION inside open transaction

- `error 1568: Transaction characteristics can't be changed while a transaction is in progress` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `error 1568: TRANSACTION ISOLATION can't be set while a transaction is in progress` → mariadb:12.3+rocksdb, mariadb:12.3

## SNAPSHOT: SET SESSION TRANSACTION inside open transaction

- all servers: `accepted`

## SNAPSHOT: SET TRANSACTION at autocommit=0, no prior query

- all servers: `accepted`

## SNAPSHOT: SET TRANSACTION at autocommit=0 after a table read

- all servers: `error 1568`

## SNAPSHOT: pair as SELECT-only user

- all servers: `accepted, no warnings`

## SNAPSHOT: ROCKSDB in SHOW ENGINES

- `not present` → all MySQL/Percona and all MariaDB
- `YES` → mariadb:11.8+rocksdb, mariadb:12.3+rocksdb, percona/percona-server:8.0+rocksdb

## SET ZenDB default sql_mode

- `accepted with 2 warning(s)` → MySQL/Percona 5.7
- `accepted with 1 warning(s)` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `accepted` → mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## @@SESSION.sql_mode after SET

- all servers: `STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`

## INSERT DATE '0000-00-00' under ZenDB sql_mode

- all servers: `accepted`

## INSERT DATE '2024-00-15' under ZenDB sql_mode

- all servers: `rejected: error 1292`

## INSERT DATE '2024-01-00' under ZenDB sql_mode

- all servers: `rejected: error 1292`

## HANDSHAKE CHARSET: client character_set_name()

- all servers: `utf8mb4`

## HANDSHAKE CHARSET: session charsets

- all servers: `utf8mb4 / utf8mb4 / utf8mb4`

## HANDSHAKE CHARSET: @@collation_connection

- `utf8mb4_general_ci` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `utf8mb4_uca1400_ai_ci` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## HANDSHAKE CHARSET: same state as set_charset route

- `identical` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `DIFFERS: handshake utf8mb4/utf8mb4/utf8mb4/utf8mb4_general_ci vs set_charset utf8mb4/utf8mb4/utf8mb4/utf8mb4_0900_ai_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## HANDSHAKE DDL: db/table collation via handshake route

- `utf8mb4_general_ci / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4_0900_ai_ci / utf8mb4_0900_ai_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `utf8mb4_uca1400_ai_ci / utf8mb4_uca1400_ai_ci` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## HANDSHAKE DDL: db/table collation via set_charset route

- `utf8mb4_general_ci / utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4_0900_ai_ci / utf8mb4_0900_ai_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `utf8mb4_uca1400_ai_ci / utf8mb4_uca1400_ai_ci` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## HANDSHAKE DDL: routes identical

- all servers: `identical`

## HANDSHAKE CHARSET: collation after SET character_set_* over utf8mb4_bin

- `utf8mb4_general_ci` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `utf8mb4_0900_ai_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## HANDSHAKE DDL: table collation with collation_connection=utf8mb4_bin

- `utf8mb4_general_ci` → MySQL/Percona 5.7 and MariaDB thru 10.11
- `utf8mb4_0900_ai_ci` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `utf8mb4_uca1400_ai_ci` → mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3

## PERSISTENT: ini mysqli.allow_persistent

- all servers: `1`

## PERSISTENT: ini mysqli.rollback_on_cached_plink

- all servers: `0`

## PERSISTENT: pool reuse

- all servers: `reused (same server thread)`

## PERSISTENT: user variable after reuse

- all servers: `cleared (lazy @ek SET re-arms)`

## PERSISTENT: session sql_mode after reuse

- all servers: `reset to server default`

## PERSISTENT: charset after reuse

- all servers: `latin1 / @@character_set_client latin1`

## PERSISTENT: temporary table after reuse

- all servers: `dropped (error 1146)`

## PERSISTENT: uncommitted INSERT after reuse

- all servers: `rolled back`

## PERSISTENT: reuse after set_charset(latin1)

- all servers: `reused; client latin1, session latin1/latin1/latin1/latin1_swedish_ci`

## PERSISTENT: reuse after SET NAMES latin1

- all servers: `reused; client latin1, session latin1/latin1/latin1/latin1_swedish_ci`

## PERSISTENT: reuse after SET CHARACTER SET

- all servers: `reused; client latin1, session latin1/latin1/latin1/latin1_swedish_ci`

## PERSISTENT: reuse after SET character_set_*

- all servers: `reused; client latin1, session latin1/latin1/latin1/latin1_swedish_ci`

## PERSISTENT: autocommit after reuse

- all servers: `reset to 1`

## PERSISTENT: read-only transaction mode after reuse

- all servers: `reset to 0 (read-write)`

## PERSISTENT: isolation level after reuse

- all servers: `reset to server default (REPEATABLE-READ)`

## PERSISTENT: default database after reuse

- all servers: `reset to connect-time database`

## PERSISTENT: GET_LOCK after reuse

- all servers: `held while pooled, released on reuse`

## PERSISTENT: LOCK TABLES after reuse

- all servers: `INSERT blocked (error 1205) while pooled, succeeded after reuse`

## PERSISTENT: FLUSH TABLES WITH READ LOCK after reuse

- all servers: `INSERT blocked (error 1205) while pooled, succeeded after reuse`

## PERSISTENT: prepared statements after reuse

- all servers: `3 while pooled, 0 after reuse`

## PERSISTENT: reuse after KILL

- all servers: `reconnected, new server thread, no error or warning`

## PERSISTENT: reuse after wait_timeout expiry

- `reconnected, new server thread, no error or warning` → mysql:5.7, mariadb:10.2, mariadb:10.2.6, mariadb:10.2.7, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7
- `reconnected, new server thread; PHP warning: Packets out of order. Expected 1 received 0. Packet size=145` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## UNION FIELDS: single SELECT control

- all servers: `id(table='zdb_probe_u1' orgtable='zdb_probe_u1'), name(table='zdb_probe_u1' orgtable='zdb_probe_u1')`

## UNION FIELDS: two-table UNION

- all servers: `id(table='' orgtable=''), name(table='' orgtable='')`

## UNION FIELDS: two-table UNION ALL

- all servers: `id(table='' orgtable=''), name(table='' orgtable='')`

## UNION FIELDS: aliased tables

- all servers: `id(table='' orgtable=''), name(table='' orgtable='')`

## UNION FIELDS: SELECT * both sides

- all servers: `id(table='' orgtable=''), name(table='' orgtable='')`

## UNION FIELDS: any union column attributed

- all servers: `no - all empty`

## EMPTY SET: 1 IN (empty)

- all servers: `0`

## EMPTY SET: 1 NOT IN (empty)

- all servers: `1`

## EMPTY SET: NULL IN (empty)

- all servers: `0`

## EMPTY SET: NULL NOT IN (empty)

- all servers: `1`

## EMPTY SET: 0 NOT IN (empty)

- all servers: `1`

## EMPTY SET CANDIDATE: DUAL WHERE 0

- `0 NOT IN=1, NULL NOT IN=1, 'Alice' IN=0 - correct` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `0 NOT IN=0, NULL NOT IN=NULL, 'Alice' IN=1 - WRONG` → mariadb:10.2.6, mariadb:10.2.7

## EMPTY SET CANDIDATE: DUAL WHERE 1=0

- `0 NOT IN=1, NULL NOT IN=1, 'Alice' IN=0 - correct` → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `0 NOT IN=0, NULL NOT IN=NULL, 'Alice' IN=1 - WRONG` → mariadb:10.2.6, mariadb:10.2.7

## EMPTY SET CANDIDATE: derived table WHERE 0

- all servers: `0 NOT IN=1, NULL NOT IN=1, 'Alice' IN=0 - correct`

## EMPTY SET CANDIDATE: information_schema

- all servers: `0 NOT IN=1, NULL NOT IN=1, 'Alice' IN=0 - correct`

## CHECK list: I_S.CHECK_CONSTRAINTS columns

- `CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, CHECK_CLAUSE` → mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4
- `CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE` → mariadb:10.2, mariadb:10.3, mariadb:10.4
- `CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, LEVEL, CHECK_CLAUSE` → mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3
- (no data) → mysql:5.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7

## SNAPSHOT: @@tx_isolation inside pair transaction

- `READ-COMMITTED` → MariaDB thru 10.11
- (no data) → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SNAPSHOT: @@tx_isolation after COMMIT

- `READ-COMMITTED` → MariaDB thru 10.11
- (no data) → mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:11.4, mariadb:11.8+rocksdb, mariadb:11.8, mariadb:12.3+rocksdb, mariadb:12.3, percona/percona-server:5.7, percona/percona-server:8.0+rocksdb, percona/percona-server:8.0, percona/percona-server:8.4

## SNAPSHOT rocksdb loaded, no rocksdb table: bare START at READ-COMMITTED

- `error 4062: Only REPEATABLE READ isolation level is supported for START TRANSACTION WITH CONSISTENT SNAPSHOT in RocksDB Storage Engine.` → mariadb:11.8+rocksdb, mariadb:12.3+rocksdb
- `138: RocksDB: Only REPEATABLE READ isolation level is supported for START TRANSACTION WITH CONSISTENT SNAPSHOT in RocksDB Storage Engine. Snapshot has not been taken.; concurrent INSERT visible (no snapshot)` → percona/percona-server:8.0+rocksdb
- (no data) → all MySQL/Percona and all MariaDB

## SNAPSHOT rocksdb loaded, no rocksdb table: pair at READ-COMMITTED

- `accepted; concurrent INSERT invisible (snapshot held)` → mariadb:11.8+rocksdb, mariadb:12.3+rocksdb, percona/percona-server:8.0+rocksdb
- (no data) → all MySQL/Percona and all MariaDB

## SNAPSHOT rocksdb loaded, no rocksdb table: bare START at REPEATABLE-READ

- `accepted; concurrent INSERT invisible (snapshot held)` → mariadb:11.8+rocksdb, mariadb:12.3+rocksdb, percona/percona-server:8.0+rocksdb
- (no data) → all MySQL/Percona and all MariaDB

## SNAPSHOT rocksdb table exists: bare START at READ-COMMITTED

- `error 4062: Only REPEATABLE READ isolation level is supported for START TRANSACTION WITH CONSISTENT SNAPSHOT in RocksDB Storage Engine.` → mariadb:11.8+rocksdb, mariadb:12.3+rocksdb
- `138: RocksDB: Only REPEATABLE READ isolation level is supported for START TRANSACTION WITH CONSISTENT SNAPSHOT in RocksDB Storage Engine. Snapshot has not been taken.; concurrent INSERT visible (no snapshot)` → percona/percona-server:8.0+rocksdb
- (no data) → all MySQL/Percona and all MariaDB

## SNAPSHOT rocksdb table exists: pair at READ-COMMITTED

- `accepted; concurrent INSERT invisible (snapshot held)` → mariadb:11.8+rocksdb, mariadb:12.3+rocksdb, percona/percona-server:8.0+rocksdb
- (no data) → all MySQL/Percona and all MariaDB

