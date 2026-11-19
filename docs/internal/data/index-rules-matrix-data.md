# Index Rules Matrix: Data

The results behind [index-rules-matrix.md](../index-rules-matrix.md): every case on every server. `OK Sub_part=N` means CREATE INDEX succeeded and SHOW INDEX reports that prefix; `Sub_part=NULL` is a whole-column index. `warn` lists the warning codes a successful CREATE INDEX raised. `ERR n` is the MySQL error code, and the messages are under each table.

*Generated 2026-11-19 from 19 servers. Generated file: to change it, see [Regenerating This Report](../index-rules-matrix.md#regenerating-this-report).*

## Server Settings

| server | VERSION() | @@innodb_page_size | @@innodb_default_row_format | @@innodb_large_prefix | @@innodb_strict_mode | @@sql_mode | @@collation_server | table options as created |
|---|---|---|---|---|---|---|---|---|
| mysql:5.7 | `5.7.44` | `16384` | `dynamic` | `ON` | `ON` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `latin1_swedish_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC` |
| mysql:8.0 | `8.0.46` | `16384` | `dynamic` | `(missing)` | `ON` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | `utf8mb4_0900_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC` |
| mysql:8.4 | `8.4.11` | `16384` | `dynamic` | `(missing)` | `ON` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | `utf8mb4_0900_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC` |
| mysql:9.6 | `9.6.0` | `16384` | `dynamic` | `(missing)` | `ON` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | `utf8mb4_0900_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC` |
| mysql:9.7 | `9.7.2` | `16384` | `dynamic` | `(missing)` | `ON` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | `utf8mb4_0900_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC` |
| mariadb:10.2 | `10.2.44-MariaDB-1:10.2.44+maria~bionic` | `16384` | `dynamic` | `ON` | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `latin1_swedish_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC` |
| mariadb:10.2.6 | `10.2.6-MariaDB-10.2.6+maria~jessie` | `16384` | `dynamic` | `ON` | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `latin1_swedish_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC` |
| mariadb:10.2.7 | `10.2.7-MariaDB-10.2.7+maria~jessie` | `16384` | `dynamic` | `ON` | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `latin1_swedish_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC` |
| mariadb:10.3 | `10.3.39-MariaDB-1:10.3.39+maria~ubu2004` | `16384` | `dynamic` | (empty string) | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `latin1_swedish_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC` |
| mariadb:10.4 | `10.4.34-MariaDB-1:10.4.34+maria~ubu2004` | `16384` | `dynamic` | (empty string) | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `latin1_swedish_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC` |
| mariadb:10.5 | `10.5.29-MariaDB-ubu2004` | `16384` | `dynamic` | (empty string) | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `utf8mb4_general_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC` |
| mariadb:10.6 | `10.6.28-MariaDB-ubu2204` | `16384` | `dynamic` | `(missing)` | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `utf8mb4_general_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC` |
| mariadb:10.11 | `10.11.19-MariaDB-ubu2204` | `16384` | `dynamic` | `(missing)` | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `utf8mb4_general_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC` |
| mariadb:11.4 | `11.4.13-MariaDB-ubu2404` | `16384` | `dynamic` | `(missing)` | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `utf8mb4_uca1400_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci ROW_FORMAT=DYNAMIC` |
| mariadb:11.8 | `11.8.9-MariaDB-ubu2404` | `16384` | `dynamic` | `(missing)` | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `utf8mb4_uca1400_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci ROW_FORMAT=DYNAMIC` |
| mariadb:12.3 | `12.3.3-MariaDB-ubu2404` | `16384` | `dynamic` | `(missing)` | `ON` | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `utf8mb4_uca1400_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci ROW_FORMAT=DYNAMIC` |
| percona/percona-server:5.7 | `5.7.44-48` | `16384` | `dynamic` | `ON` | `ON` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` | `latin1_swedish_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC` |
| percona/percona-server:8.0 | `8.0.46-37` | `16384` | `dynamic` | `(missing)` | `ON` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | `utf8mb4_0900_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC` |
| percona/percona-server:8.4 | `8.4.11-11` | `16384` | `dynamic` | `(missing)` | `ON` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | `utf8mb4_0900_ai_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC` |

## 1. Key length required

| server | TINYTEXT no prefix | TEXT no prefix | MEDIUMTEXT no prefix | LONGTEXT no prefix | TINYBLOB no prefix | BLOB no prefix | MEDIUMBLOB no prefix | LONGBLOB no prefix |
|---|---|---|---|---|---|---|---|---|
| mysql:5.7 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mysql:8.0 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mysql:8.4 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mysql:9.6 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mysql:9.7 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mariadb:10.2 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mariadb:10.2.6 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mariadb:10.2.7 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mariadb:10.3 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| mariadb:10.4 | OK Sub_part=255 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=255 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:10.5 | OK Sub_part=255 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=255 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:10.6 | OK Sub_part=255 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=255 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:10.11 | OK Sub_part=255 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=255 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:11.4 | OK Sub_part=255 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=255 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:11.8 | OK Sub_part=255 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=255 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:12.3 | OK Sub_part=255 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=255 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 | OK Sub_part=3072 warn 1071 |
| percona/percona-server:5.7 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| percona/percona-server:8.0 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |
| percona/percona-server:8.4 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 | ERR 1170 |

- **TINYTEXT no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=255 (MariaDB 10.4+)
- **TEXT no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=768 warn 1071 (MariaDB 10.4+)
- **MEDIUMTEXT no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=768 warn 1071 (MariaDB 10.4+)
- **LONGTEXT no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=768 warn 1071 (MariaDB 10.4+)
- **TINYBLOB no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=255 (MariaDB 10.4+)
- **BLOB no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=3072 warn 1071 (MariaDB 10.4+)
- **MEDIUMBLOB no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=3072 warn 1071 (MariaDB 10.4+)
- **LONGBLOB no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=3072 warn 1071 (MariaDB 10.4+)

Messages:

- `1170: BLOB/TEXT column 'col' used in key specification without a key length` (all MySQL/Percona and MariaDB thru 10.3)
- `warning 1071: Specified key was too long; max key length is 3072 bytes` (MariaDB 10.4+)

## 2. Prefix longer than the column

| server | TINYTEXT (768) | TINYBLOB (768) | TINYTEXT (255) | TINYTEXT (63) | TINYBLOB (255) |
|---|---|---|---|---|---|
| mysql:5.7 | ERR 1071 | ERR 1071 | ERR 1071 | OK Sub_part=63 | OK Sub_part=255 |
| mysql:8.0 | ERR 1071 | ERR 1071 | ERR 1071 | OK Sub_part=63 | OK Sub_part=255 |
| mysql:8.4 | ERR 1071 | ERR 1071 | ERR 1071 | OK Sub_part=63 | OK Sub_part=255 |
| mysql:9.6 | ERR 1071 | ERR 1071 | ERR 1071 | OK Sub_part=63 | OK Sub_part=255 |
| mysql:9.7 | ERR 1071 | ERR 1071 | ERR 1071 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:10.2 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:10.2.6 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:10.2.7 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:10.3 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:10.4 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:10.5 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:10.6 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:10.11 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:11.4 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:11.8 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| mariadb:12.3 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK Sub_part=255 |
| percona/percona-server:5.7 | ERR 1071 | ERR 1071 | ERR 1071 | OK Sub_part=63 | OK Sub_part=255 |
| percona/percona-server:8.0 | ERR 1071 | ERR 1071 | ERR 1071 | OK Sub_part=63 | OK Sub_part=255 |
| percona/percona-server:8.4 | ERR 1071 | ERR 1071 | ERR 1071 | OK Sub_part=63 | OK Sub_part=255 |

- **TINYTEXT (768)**: SPLIT: ERR 1071 (all MySQL/Percona); OK Sub_part=255 (all MariaDB)
- **TINYBLOB (768)**: SPLIT: ERR 1071 (all MySQL/Percona); OK Sub_part=255 (all MariaDB)
- **TINYTEXT (255)**: SPLIT: ERR 1071 (all MySQL/Percona); OK Sub_part=255 (all MariaDB)
- **TINYTEXT (63)**: every server: OK Sub_part=63
- **TINYBLOB (255)**: every server: OK Sub_part=255

Messages:

- `1071: Specified key was too long; max key length is 255 bytes` (all MySQL/Percona)

## 3. VARCHAR prefix equal to the column length

| server | VARCHAR(255) no prefix | VARCHAR(255) (255) | VARCHAR(255) (250) |
|---|---|---|---|
| mysql:5.7 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mysql:8.0 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mysql:8.4 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mysql:9.6 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mysql:9.7 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:10.2 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:10.2.6 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:10.2.7 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:10.3 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:10.4 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:10.5 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:10.6 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:10.11 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:11.4 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:11.8 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| mariadb:12.3 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| percona/percona-server:5.7 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| percona/percona-server:8.0 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |
| percona/percona-server:8.4 | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=NULL key=_auto_col filesort=no | OK Sub_part=250 key=NULL filesort=yes |

- **VARCHAR(255) no prefix**: every server: OK Sub_part=NULL key=_auto_col filesort=no
- **VARCHAR(255) (255)**: every server: OK Sub_part=NULL key=_auto_col filesort=no
- **VARCHAR(255) (250)**: every server: OK Sub_part=250 key=NULL filesort=yes

Notes:

- VARCHAR(255) no prefix: `EXPLAIN key=_auto_col Extra=Using index` (MySQL/Percona 5.7 and all MariaDB)
- VARCHAR(255) no prefix: `EXPLAIN key=_auto_col Extra=Using index; TREE: -> Limit: 10 row(s)  (cost=0.0163 rows=10) /     -> Index scan on t using _auto_col  (cost=0.0163 rows=10)` (mysql:8.0, percona/percona-server:8.0)
- VARCHAR(255) no prefix: `EXPLAIN key=_auto_col Extra=Using index; TREE: -> Limit: 10 row(s)  (cost=0.0163 rows=10) /     -> Covering index scan on t using _auto_col  (cost=0.0163 rows=10)` (mysql:8.4, percona/percona-server:8.4)
- VARCHAR(255) no prefix: `EXPLAIN key=NULL Extra=(empty); TREE: -> Limit: 10 row(s)  (cost=0.0163 rows=10) /     -> Covering index scan on t using _auto_col  (cost=0.0163 rows=10)` (MySQL/Percona 9.6+)
- VARCHAR(255) (255): `EXPLAIN key=_auto_col Extra=Using index` (MySQL/Percona 5.7 and all MariaDB)
- VARCHAR(255) (255): `EXPLAIN key=_auto_col Extra=Using index; TREE: -> Limit: 10 row(s)  (cost=0.0163 rows=10) /     -> Index scan on t using _auto_col  (cost=0.0163 rows=10)` (mysql:8.0, percona/percona-server:8.0)
- VARCHAR(255) (255): `EXPLAIN key=_auto_col Extra=Using index; TREE: -> Limit: 10 row(s)  (cost=0.0163 rows=10) /     -> Covering index scan on t using _auto_col  (cost=0.0163 rows=10)` (mysql:8.4, percona/percona-server:8.4)
- VARCHAR(255) (255): `EXPLAIN key=NULL Extra=(empty); TREE: -> Limit: 10 row(s)  (cost=0.0163 rows=10) /     -> Covering index scan on t using _auto_col  (cost=0.0163 rows=10)` (MySQL/Percona 9.6+)
- VARCHAR(255) (250): `EXPLAIN key=NULL Extra=Using filesort` (MySQL/Percona 5.7 and all MariaDB)
- VARCHAR(255) (250): `EXPLAIN key=NULL Extra=Using filesort; TREE: -> Limit: 10 row(s)  (cost=202 rows=10) /     -> Sort: t.col, limit input to 10 row(s) per chunk  (cost=202 rows=2000) /         -> Table scan on t  (cost=202 rows=2000)` (mysql:8.0, mysql:8.4, percona/percona-server:8.0, percona/percona-server:8.4)
- VARCHAR(255) (250): `EXPLAIN key=NULL Extra=(empty); TREE: -> Limit: 10 row(s)  (cost=202 rows=10) /     -> Sort: t.col, limit input to 10 row(s) per chunk  (cost=202 rows=2000) /         -> Table scan on t  (cost=202 rows=2000)` (MySQL/Percona 9.6+)

## 4. Where the cap is (DYNAMIC)

| server | VARCHAR(768) no prefix | VARCHAR(769) no prefix | VARCHAR(2000) no prefix | VARBINARY(3072) no prefix | VARBINARY(3073) no prefix | VARCHAR(2000) (768) | VARCHAR(2000) (769) |
|---|---|---|---|---|---|---|---|
| mysql:5.7 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| mysql:8.0 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| mysql:8.4 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| mysql:9.6 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| mysql:9.7 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| mariadb:10.2 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| mariadb:10.2.6 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| mariadb:10.2.7 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| mariadb:10.3 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| mariadb:10.4 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| mariadb:10.5 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| mariadb:10.6 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| mariadb:10.11 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| mariadb:11.4 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| mariadb:11.8 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| mariadb:12.3 | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=768 warn 1071 | OK Sub_part=NULL | OK Sub_part=3072 warn 1071 | OK Sub_part=768 | OK Sub_part=768 warn 1071 |
| percona/percona-server:5.7 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| percona/percona-server:8.0 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |
| percona/percona-server:8.4 | OK Sub_part=NULL | ERR 1071 | ERR 1071 | OK Sub_part=NULL | ERR 1071 | OK Sub_part=768 | ERR 1071 |

- **VARCHAR(768) no prefix**: every server: OK Sub_part=NULL
- **VARCHAR(769) no prefix**: SPLIT: ERR 1071 (mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4); OK Sub_part=768 warn 1071 (mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3)
- **VARCHAR(2000) no prefix**: SPLIT: ERR 1071 (mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4); OK Sub_part=768 warn 1071 (mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3)
- **VARBINARY(3072) no prefix**: every server: OK Sub_part=NULL
- **VARBINARY(3073) no prefix**: SPLIT: ERR 1071 (mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4); OK Sub_part=3072 warn 1071 (mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3)
- **VARCHAR(2000) (768)**: every server: OK Sub_part=768
- **VARCHAR(2000) (769)**: SPLIT: ERR 1071 (mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4); OK Sub_part=768 warn 1071 (mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3)

Messages:

- `1071: Specified key was too long; max key length is 3072 bytes` (mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4)
- `warning 1071: Specified key was too long; max key length is 3072 bytes` (mariadb:10.2, mariadb:10.3, mariadb:10.4, mariadb:10.5, mariadb:10.6, mariadb:10.11, mariadb:11.4, mariadb:11.8, mariadb:12.3)

## 5. Other row formats

| server | DYNAMIC VARCHAR(768) no prefix | DYNAMIC TEXT (768) | COMPACT VARCHAR(768) no prefix | COMPACT TEXT (768) | REDUNDANT VARCHAR(768) no prefix | REDUNDANT TEXT (768) | large_prefix=OFF DYNAMIC VARCHAR(768) no prefix | large_prefix=OFF DYNAMIC TEXT (768) | large_prefix=OFF COMPACT VARCHAR(768) no prefix | large_prefix=OFF COMPACT TEXT (768) |
|---|---|---|---|---|---|---|---|---|---|---|
| mysql:5.7 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 |
| mysql:8.0 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | n/a | n/a | n/a | n/a |
| mysql:8.4 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | n/a | n/a | n/a | n/a |
| mysql:9.6 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | n/a | n/a | n/a | n/a |
| mysql:9.7 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | n/a | n/a | n/a | n/a |
| mariadb:10.2 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | OK Sub_part=191 warn 1071 | OK Sub_part=191 warn 1071 | OK Sub_part=191 warn 1071 | OK Sub_part=191 warn 1071 |
| mariadb:10.2.6 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1071 | OK Sub_part=191 warn 1071 | ERR 1071 | OK Sub_part=191 warn 1071 |
| mariadb:10.2.7 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1071 | OK Sub_part=191 warn 1071 | ERR 1071 | OK Sub_part=191 warn 1071 |
| mariadb:10.3 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | n/a | n/a | n/a | n/a |
| mariadb:10.4 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | n/a | n/a | n/a | n/a |
| mariadb:10.5 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | n/a | n/a | n/a | n/a |
| mariadb:10.6 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | n/a | n/a | n/a | n/a |
| mariadb:10.11 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | n/a | n/a | n/a | n/a |
| mariadb:11.4 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | n/a | n/a | n/a | n/a |
| mariadb:11.8 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | n/a | n/a | n/a | n/a |
| mariadb:12.3 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1709 | ERR 1709 | ERR 1709 | ERR 1709 | n/a | n/a | n/a | n/a |
| percona/percona-server:5.7 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 |
| percona/percona-server:8.0 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | n/a | n/a | n/a | n/a |
| percona/percona-server:8.4 | OK Sub_part=NULL | OK Sub_part=768 | ERR 1071 | ERR 1071 | ERR 1071 | ERR 1071 | n/a | n/a | n/a | n/a |

- **DYNAMIC VARCHAR(768) no prefix**: every server: OK Sub_part=NULL
- **DYNAMIC TEXT (768)**: every server: OK Sub_part=768
- **COMPACT VARCHAR(768) no prefix**: SPLIT: ERR 1071 (all MySQL/Percona); ERR 1709 (all MariaDB)
- **COMPACT TEXT (768)**: SPLIT: ERR 1071 (all MySQL/Percona); ERR 1709 (all MariaDB)
- **REDUNDANT VARCHAR(768) no prefix**: SPLIT: ERR 1071 (all MySQL/Percona); ERR 1709 (all MariaDB)
- **REDUNDANT TEXT (768)**: SPLIT: ERR 1071 (all MySQL/Percona); ERR 1709 (all MariaDB)
- **large_prefix=OFF DYNAMIC VARCHAR(768) no prefix**: SPLIT: ERR 1071 (mysql:5.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7); OK Sub_part=191 warn 1071 (mariadb:10.2)
- **large_prefix=OFF DYNAMIC TEXT (768)**: SPLIT: ERR 1071 (MySQL/Percona 5.7); OK Sub_part=191 warn 1071 (MariaDB thru 10.2.7)
- **large_prefix=OFF COMPACT VARCHAR(768) no prefix**: SPLIT: ERR 1071 (mysql:5.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7); OK Sub_part=191 warn 1071 (mariadb:10.2)
- **large_prefix=OFF COMPACT TEXT (768)**: SPLIT: ERR 1071 (MySQL/Percona 5.7); OK Sub_part=191 warn 1071 (MariaDB thru 10.2.7)

Messages:

- `1071: Specified key was too long; max key length is 767 bytes` (mysql:5.7, mysql:8.0, mysql:8.4, mysql:9.6, mysql:9.7, mariadb:10.2.6, mariadb:10.2.7, percona/percona-server:5.7, percona/percona-server:8.0, percona/percona-server:8.4)
- `1709: Index column size too large. The maximum column size is 767 bytes` (all MariaDB)
- `warning 1071: Specified key was too long; max key length is 767 bytes` (MariaDB thru 10.2.7)

## 6. Types that cannot take a plain index

| server | CHAR(255) no prefix | BINARY(255) no prefix | ENUM('a','b') no prefix | SET('a','b') no prefix | DECIMAL(10,2) no prefix | DATETIME no prefix | JSON no prefix | GEOMETRY no prefix |
|---|---|---|---|---|---|---|---|---|
| mysql:5.7 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 3152 | ERR 1170 |
| mysql:8.0 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 3152 | ERR 1252 |
| mysql:8.4 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 3152 | ERR 1252 |
| mysql:9.6 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 3152 | ERR 1252 |
| mysql:9.7 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 3152 | ERR 1252 |
| mariadb:10.2 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 1170 | ERR 1170 |
| mariadb:10.2.6 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | CREATE TABLE ERR 1064 | ERR 1170 |
| mariadb:10.2.7 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 1170 | ERR 1170 |
| mariadb:10.3 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 1170 | ERR 1170 |
| mariadb:10.4 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:10.5 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:10.6 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:10.11 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:11.4 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:11.8 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=3072 warn 1071 |
| mariadb:12.3 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=768 warn 1071 | OK Sub_part=3072 warn 1071 |
| percona/percona-server:5.7 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 3152 | ERR 1170 |
| percona/percona-server:8.0 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 3152 | ERR 1252 |
| percona/percona-server:8.4 | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | OK Sub_part=NULL | ERR 3152 | ERR 1252 |

- **CHAR(255) no prefix**: every server: OK Sub_part=NULL
- **BINARY(255) no prefix**: every server: OK Sub_part=NULL
- **ENUM('a','b') no prefix**: every server: OK Sub_part=NULL
- **SET('a','b') no prefix**: every server: OK Sub_part=NULL
- **DECIMAL(10,2) no prefix**: every server: OK Sub_part=NULL
- **DATETIME no prefix**: every server: OK Sub_part=NULL
- **JSON no prefix**: SPLIT: ERR 3152 (all MySQL/Percona); ERR 1170 (mariadb:10.2, mariadb:10.2.7, mariadb:10.3); CREATE TABLE ERR 1064 (mariadb:10.2.6); OK Sub_part=768 warn 1071 (MariaDB 10.4+)
- **GEOMETRY no prefix**: SPLIT: ERR 1170 (MySQL/Percona 5.7 and MariaDB thru 10.3); ERR 1252 (MySQL/Percona 8.0+); OK Sub_part=3072 warn 1071 (MariaDB 10.4+)

Messages:

- `3152: JSON column 'col' cannot be used in key specification.` (MySQL/Percona 5.7)
- `1170: BLOB/TEXT column 'col' used in key specification without a key length` (MySQL/Percona 5.7 and MariaDB thru 10.3)
- `3152: JSON column 'col' supports indexing only via generated columns on a specified JSON path.` (MySQL/Percona 8.0+)
- `1252: All parts of a SPATIAL index must be NOT NULL` (MySQL/Percona 8.0+)
- `1064: You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'JSON) ENGINE=InnoDB ROW_FORMAT=DYNAMIC CHARSET=utf8mb4' at line 1` (mariadb:10.2.6)
- `warning 1071: Specified key was too long; max key length is 3072 bytes` (MariaDB 10.4+)

## 7. Case of the type name

| server | tinytext no prefix | tinytext (768) | tinytext (63) | SHOW CREATE TABLE column lines |
|---|---|---|---|---|
| mysql:5.7 | ERR 1170 | ERR 1071 | OK Sub_part=63 | OK `` `col` tinytext / `col2` tinytext `` |
| mysql:8.0 | ERR 1170 | ERR 1071 | OK Sub_part=63 | OK `` `col` tinytext / `col2` tinytext `` |
| mysql:8.4 | ERR 1170 | ERR 1071 | OK Sub_part=63 | OK `` `col` tinytext / `col2` tinytext `` |
| mysql:9.6 | ERR 1170 | ERR 1071 | OK Sub_part=63 | OK `` `col` tinytext / `col2` tinytext `` |
| mysql:9.7 | ERR 1170 | ERR 1071 | OK Sub_part=63 | OK `` `col` tinytext / `col2` tinytext `` |
| mariadb:10.2 | ERR 1170 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:10.2.6 | ERR 1170 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:10.2.7 | ERR 1170 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:10.3 | ERR 1170 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:10.4 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:10.5 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:10.6 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:10.11 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:11.4 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:11.8 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| mariadb:12.3 | OK Sub_part=255 | OK Sub_part=255 | OK Sub_part=63 | OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` |
| percona/percona-server:5.7 | ERR 1170 | ERR 1071 | OK Sub_part=63 | OK `` `col` tinytext / `col2` tinytext `` |
| percona/percona-server:8.0 | ERR 1170 | ERR 1071 | OK Sub_part=63 | OK `` `col` tinytext / `col2` tinytext `` |
| percona/percona-server:8.4 | ERR 1170 | ERR 1071 | OK Sub_part=63 | OK `` `col` tinytext / `col2` tinytext `` |

- **tinytext no prefix**: SPLIT: ERR 1170 (all MySQL/Percona and MariaDB thru 10.3); OK Sub_part=255 (MariaDB 10.4+)
- **tinytext (768)**: SPLIT: ERR 1071 (all MySQL/Percona); OK Sub_part=255 (all MariaDB)
- **tinytext (63)**: every server: OK Sub_part=63
- **SHOW CREATE TABLE column lines**: SPLIT: OK `` `col` tinytext / `col2` tinytext `` (all MySQL/Percona); OK `` `col` tinytext DEFAULT NULL / `col2` tinytext DEFAULT NULL `` (all MariaDB)

Messages:

- `1170: BLOB/TEXT column 'col' used in key specification without a key length` (all MySQL/Percona and MariaDB thru 10.3)
- `1071: Specified key was too long; max key length is 255 bytes` (all MySQL/Percona)

