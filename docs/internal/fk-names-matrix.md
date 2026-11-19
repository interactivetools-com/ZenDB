# Foreign Key Names Matrix

A foreign key's name used to be unique in its database, so code could find a key by its
name alone. MariaDB 12.1 changed that: a name only has to be unique in its table. This
page shows how each supported server names foreign keys, where two keys can share a
name, and what `Table::foreignKeys()` and `Table::foreignKeysReferencing()` return when
they do.

*Generated 2026-11-19 from 22 servers. Every result is in [fk-names-matrix-data.md](data/fk-names-matrix-data.md).*

## The Short Version

- **A name doesn't identify one foreign key.** On MariaDB 12.1+ two tables in one
  database can each have a key named `1` or `fk_same`, and on every server a key in
  another database can have the same name as one in this database. Identify a key by
  database, table and name, and sort `KEY_COLUMN_USAGE` rows by all three
- **MariaDB 12.1+ names unnamed keys `1`, `2`, `3` in every table.** Every other server
  names them `<table>_ibfk_1`, `<table>_ibfk_2`, so code that looks for `_ibfk_` in a
  name finds nothing there
- **`Table::foreignKeys()` and `Table::foreignKeysReferencing()` return one entry per
  key on all 22 servers**, same-named keys included
- **Dropping a key by a shared name is safe.** `ALTER TABLE ... DROP FOREIGN KEY <name>`
  drops only that table's key, on every server

## How Each Server Names Keys

| | MySQL, Percona and MariaDB thru 12.0 | MariaDB 12.1+ |
|---|---|---|
| An unnamed key is named | `<table>_ibfk_1`, `<table>_ibfk_2` | `1`, `2`, `3`, in every table |
| A name has to be unique in | The database | Its table |
| A second table uses a name another table has | Refused | Accepted |
| The same name twice in one table | Error 1061 | Error 1061 |
| A key in another database uses the same name | Accepted | Accepted |

The second table is refused with error 1022 on MySQL/Percona 5.7, 1826 on MySQL/Percona
8.0+ and 1005 on MariaDB thru 12.0.

Two databases that each have a table of the same name give their unnamed keys the same
generated name, on every server: `fkp_event_ibfk_1` in both, or `1` in both on MariaDB
12.1+.

MariaDB's announcement is [Per-table unique FOREIGN KEY constraint names - new feature in MariaDB 12.1](https://mariadb.org/per-table-unique-foreign-key-constraint-names-new-feature-in-mariadb-12-1/)
([MDEV-28933](https://jira.mariadb.org/browse/MDEV-28933)). A request to go back to the
old generated names was closed as Won't Fix
([MDEV-37592](https://jira.mariadb.org/browse/MDEV-37592)).

## What ZenDB Returns

`Table::foreignKeysReferencing()` lists the keys in other tables that point at a table.
It groups rows by database, table and name, so two keys with one name are two entries,
and each entry says which database and table the key is in. On MariaDB 12.1+, with a
key named `fk_same` in two tables:

```php
$keys = Table::foreignKeysReferencing('item');
// [
//   ['name' => 'fk_same', 'database' => 'zdb_fk_probe', 'fullTable' => 'fkp_event',    'cols' => ['item_id'], 'refCols' => ['num']],
//   ['name' => 'fk_same', 'database' => 'zdb_fk_probe', 'fullTable' => 'fkp_warranty', 'cols' => ['item_id'], 'refCols' => ['num']],
// ]
```

A key in another database that references the table is in the list too, with its own
`database`.

`Table::foreignKeys()` reads one table at a time, so a shared name can't mix two keys.
It returned the right columns and ON DELETE and ON UPDATE rules on every server, and
`refDatabase` names the other database when a key references a table there.

ZenDB 1.0.0 grouped `foreignKeysReferencing()` rows by name alone, so two keys with one
name came back as one entry. That affects two tables in one database on MariaDB 12.1+,
and a key in another database on every server.

## Gotchas

- **`KEY_COLUMN_USAGE` order:** rows for same-named keys come back in no fixed order
  under `ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION`. The same query run twice on one
  server returned the two tables in a different order, so sort by schema and table too
- **`DROP TABLE` on a referenced table** is refused with a different error on each
  server family: 1217 on MySQL/Percona 5.7, 3730 on MySQL/Percona 8.0+, 1451 on every
  MariaDB. A key in another database blocks the drop the same way. Only the 3730
  message names the key and its table, and it leaves out the database
- **A table referenced only by its own key** drops without an error

## Full Results

Every check on every server is in [fk-names-matrix-data.md](data/fk-names-matrix-data.md):
the names each server gives, the `KEY_COLUMN_USAGE` rows, what the two ZenDB methods
returned, and the error text of every refused statement.

## Regenerating This Report

This page and its data file are generated, so change them in the script and not by
hand. The sections above are in the heredocs in .github/scripts/fk-names-probe.php.

    gh workflow run fk-names-matrix.yml   # wait for the run to finish, then:
    gh run download --dir probes          # pick the latest FK Names Matrix run
    php .github/scripts/fk-names-probe.php --merge probes/*/fk-probe-*.json > docs/internal/fk-names-matrix.md
    php .github/scripts/fk-names-probe.php --merge --data probes/*/fk-probe-*.json > docs/internal/data/fk-names-matrix-data.md

MariaDB 12.0, 12.1 and 12.2 aren't in db-matrix.json. To include them, do a second run
with `-f databases="mariadb:12.0 mariadb:12.1 mariadb:12.2"` and pass both runs' JSON
files to --merge.
