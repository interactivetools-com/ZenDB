#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Check how one database server names foreign keys, and whether ZenDB's
 * Table::foreignKeys() and Table::foreignKeysReferencing() report them correctly.
 *
 *     php .github/scripts/fk-names-probe.php --json=probe.json   # probe one server
 *     php .github/scripts/fk-names-probe.php --merge probes/*.json   # one "who differs" report
 *
 * MariaDB 12.1+ only needs a foreign key name to be unique within its table
 * (MDEV-28933) and names unnamed keys 1, 2, 3 in every table. Every other server
 * needs the name to be unique in the database. foreignKeysReferencing() reads keys
 * from many tables at once, so this probe creates each way two keys can share a
 * name and records what the server stores and what ZenDB returns.
 *
 * The CI workflow (.github/workflows/fk-names-matrix.yml) runs this against every
 * database image in the matrix.
 *
 * Tables are created in two scratch databases (zdb_fk_probe, zdb_fk_probe_other)
 * that are dropped on exit. Needs composer install, since it loads the library.
 * Connects with the same DB_* env vars as the test suite (see phpunit.xml.dist).
 * DB_LABEL names the server in the JSON output, e.g. "mariadb:12.3" from the CI matrix.
 */

require __DIR__ . '/ci-lib.php';

$opts = getopt('', ['json:', 'merge'], $restIndex);
if (isset($opts['merge'])) {
    mergeReports(array_slice($argv, $restIndex));
    exit;
}

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found - run: composer install\n");
    exit(1);
}
require $autoload;

use Itools\ZenDB\DB;
use Itools\ZenDB\Table;

// A PHP warning inside ZenDB (an undefined array key, say) is a finding, so report it as one
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $level, $file, $line);
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$hostname = (getenv('DB_HOSTNAME') ?: '127.0.0.1') . (getenv('DB_PORT') ? ':' . getenv('DB_PORT') : '');
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$label    = getenv('DB_LABEL') ?: 'unlabeled';
$jsonPath = $opts['json'] ?? null;
$scratch  = 'zdb_fk_probe';
$otherDb  = 'zdb_fk_probe_other';
$prefix   = 'fkp_';

$report = ['server' => $label, 'version' => '', 'probes' => []]; // probe name => answer

// Plain mysqli creates the tables and reads information_schema, so ZenDB's answers
// are compared with what the server stores and not with ZenDB's own reading of it
$mysqli = new mysqli($hostname, $username, $password);
$mysqli->set_charset('utf8mb4');
foreach ([$scratch, $otherDb] as $db) {
    $mysqli->query("DROP DATABASE IF EXISTS `$db`");
    $mysqli->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
}
$mysqli->select_db($scratch);
$report['version'] = $mysqli->query("SELECT VERSION()")->fetch_row()[0];

// Drop the scratch databases and write the JSON on every exit path, including a fatal
// partway through, so a broken probe still reports what it collected
register_shutdown_function(static function () use ($mysqli, $scratch, $otherDb, $jsonPath, &$report) {
    try {
        $mysqli->query("DROP DATABASE IF EXISTS `$otherDb`");
        $mysqli->query("DROP DATABASE IF EXISTS `$scratch`");
    } catch (Throwable) {
        fwrite(STDERR, "Could not drop the scratch databases\n");
    }
    if ($jsonPath !== null) {
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        fwrite(STDERR, "Wrote " . count($report['probes']) . " probes to $jsonPath\n");
    }
});

DB::connect([
    'hostname'       => $hostname,
    'username'       => $username,
    'password'       => $password,
    'database'       => $scratch,
    'tablePrefix'    => $prefix,
    'connectTimeout' => 5,
]);

//region Helpers

/**
 * Run a statement and report 'ok' or the server's error.
 */
function run(mysqli $mysqli, string $sql): string
{
    try {
        $mysqli->query($sql);
        return 'ok';
    } catch (mysqli_sql_exception $e) {
        return "error {$e->getCode()}: {$e->getMessage()}";
    }
}

/**
 * Drop every table in both scratch databases, so each scenario starts empty.
 */
function dropAllTables(mysqli $mysqli, array $databases): void
{
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($databases as $db) {
        foreach ($mysqli->query("SHOW TABLES FROM `$db`")->fetch_all() as [$table]) {
            $mysqli->query("DROP TABLE `$db`.`$table`");
        }
    }
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
}

/**
 * Foreign key names from SHOW CREATE TABLE, in the order the server prints them.
 */
function fkNames(mysqli $mysqli, string $table): string
{
    try {
        $createSql = $mysqli->query("SHOW CREATE TABLE $table")->fetch_row()[1];
    } catch (mysqli_sql_exception $e) {
        return "(no table: error {$e->getCode()})";
    }
    preg_match_all('/CONSTRAINT `([^`]+)` FOREIGN KEY/', $createSql, $matches); // each CONSTRAINT `name` FOREIGN KEY
    return $matches[1] ? implode(', ', $matches[1]) : '(none)';
}

/**
 * The KEY_COLUMN_USAGE rows foreignKeysReferencing() reads for one referenced table,
 * with the same WHERE and ORDER BY, as "schema.table name col→refCol" joined by "; ".
 */
function inboundRows(mysqli $mysqli, string $fullTable): string
{
    $rows = $mysqli->query(
        "SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = '$fullTable'
          ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION",
    )->fetch_all(MYSQLI_NUM);
    $lines = array_map(static fn($r) => "$r[0].$r[1] $r[2] $r[3]→$r[4]", $rows);
    return $lines ? implode('; ', $lines) : '(no rows)';
}

/**
 * Call a ZenDB method and return its result as compact JSON, or the exception it threw.
 */
function zenJson(callable $call): string
{
    try {
        return json_encode($call(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return 'threw ' . get_class($e) . ": {$e->getMessage()}";
    }
}

/**
 * Compare foreignKeysReferencing() with the foreign keys the scenario created.
 * $expected is a list of [fullTable, cols] pairs, one per foreign key.
 */
function inboundVerdict(string $baseTable, array $expected): string
{
    try {
        $actual = Table::foreignKeysReferencing($baseTable);
    } catch (Throwable $e) {
        return 'threw ' . get_class($e) . ": {$e->getMessage()}";
    }
    $found = 0;
    foreach ($expected as [$fullTable, $cols]) {
        foreach ($actual as $fk) {
            if ($fk['fullTable'] === $fullTable && $fk['cols'] === $cols) {
                $found++;
                break;
            }
        }
    }
    $total = count($expected);
    return $found === $total && count($actual) === $total
        ? "correct: $total of $total foreign keys"
        : "WRONG: " . count($actual) . " entries returned, $found of $total foreign keys reported correctly";
}

//endregion
//region Scenario 1: unnamed keys in two tables

$parent   = "{$prefix}item";
$event    = "{$prefix}event";
$warranty = "{$prefix}warranty";
$probes   = &$report['probes'];

$mysqli->query("CREATE TABLE $parent (num INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$mysqli->query("CREATE TABLE $event (num INT NOT NULL PRIMARY KEY, item_id INT, owner_id INT, FOREIGN KEY (item_id) REFERENCES $parent (num) ON DELETE CASCADE) ENGINE=InnoDB");
$mysqli->query("CREATE TABLE $warranty (num INT NOT NULL PRIMARY KEY, item_id INT, FOREIGN KEY (item_id) REFERENCES $parent (num) ON DELETE SET NULL) ENGINE=InnoDB");

$probes['1a. Unnamed keys: names the server gives (event / warranty)'] = fkNames($mysqli, $event) . ' / ' . fkNames($mysqli, $warranty);
$probes['1b. Unnamed keys: KEY_COLUMN_USAGE rows that reference item'] = inboundRows($mysqli, $parent);
$probes['1c. Unnamed keys: foreignKeysReferencing(item) result']       = zenJson(static fn() => Table::foreignKeysReferencing('item'));
$probes['1d. Unnamed keys: foreignKeysReferencing(item) verdict']      = inboundVerdict('item', [[$event, ['item_id']], [$warranty, ['item_id']]]);
$probes['1e. Unnamed keys: foreignKeys(event) result']                 = zenJson(static fn() => Table::foreignKeys('event'));
$probes['1f. Unnamed keys: foreignKeys(warranty) result']              = zenJson(static fn() => Table::foreignKeys('warranty'));

// REFERENTIAL_CONSTRAINTS is where foreignKeys() reads ON DELETE from, filtered by table
$rules = $mysqli->query(
    "SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE
       FROM information_schema.REFERENTIAL_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
      ORDER BY TABLE_NAME, CONSTRAINT_NAME",
)->fetch_all(MYSQLI_NUM);
$probes['1g. Unnamed keys: REFERENTIAL_CONSTRAINTS rows'] = implode('; ', array_map(static fn($r) => "$r[0] $r[1] $r[2]", $rules));

// A second unnamed key on event, added later, then drop event's first key by name.
// warranty's key must survive: CMS Builder drops keys with ALTER TABLE ... DROP FOREIGN KEY <name>
$probes['1h. Second unnamed key added with ALTER TABLE']    = run($mysqli, "ALTER TABLE $event ADD FOREIGN KEY (owner_id) REFERENCES $parent (num)");
$probes['1i. Names after the ALTER (event / warranty)']     = fkNames($mysqli, $event) . ' / ' . fkNames($mysqli, $warranty);
$firstEventKey                                              = explode(', ', fkNames($mysqli, $event))[0];
$probes['1j. DROP FOREIGN KEY on event, first key by name'] = run($mysqli, "ALTER TABLE $event DROP FOREIGN KEY `$firstEventKey`");
$probes['1k. Names after the DROP (event / warranty)']      = fkNames($mysqli, $event) . ' / ' . fkNames($mysqli, $warranty);
$probes['1l. foreignKeysReferencing(item) verdict after the DROP'] = inboundVerdict('item', [[$event, ['owner_id']], [$warranty, ['item_id']]]);

//endregion
//region Scenario 2: the same explicit name in two tables

dropAllTables($mysqli, [$scratch, $otherDb]);
$mysqli->query("CREATE TABLE $parent (num INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$mysqli->query("CREATE TABLE $event (num INT NOT NULL PRIMARY KEY, item_id INT, owner_id INT, CONSTRAINT fk_same FOREIGN KEY (item_id) REFERENCES $parent (num)) ENGINE=InnoDB");

$probes['2a. Same name twice in one table']                  = run($mysqli, "ALTER TABLE $event ADD CONSTRAINT fk_same FOREIGN KEY (owner_id) REFERENCES $parent (num)");
$secondCreate                                                = run($mysqli, "CREATE TABLE $warranty (num INT NOT NULL PRIMARY KEY, item_id INT, CONSTRAINT fk_same FOREIGN KEY (item_id) REFERENCES $parent (num)) ENGINE=InnoDB");
$probes['2b. Same name in a second table, same database']    = $secondCreate;
$probes['2c. Same name: KEY_COLUMN_USAGE rows that reference item'] = inboundRows($mysqli, $parent);
$probes['2d. Same name: foreignKeysReferencing(item) result']       = zenJson(static fn() => Table::foreignKeysReferencing('item'));
$probes['2e. Same name: foreignKeysReferencing(item) verdict']      = $secondCreate === 'ok'
    ? inboundVerdict('item', [[$event, ['item_id']], [$warranty, ['item_id']]])
    : 'not possible: the server refused the second table';

//endregion
//region Scenario 3: unnamed composite keys in two tables

dropAllTables($mysqli, [$scratch, $otherDb]);
// MySQL 8.4+ needs a UNIQUE index on the referenced columns (restrict_fk_on_non_standard_key)
$mysqli->query("CREATE TABLE $parent (num INT NOT NULL PRIMARY KEY, x INT NOT NULL, y INT NOT NULL, UNIQUE KEY xy (x, y)) ENGINE=InnoDB");
$mysqli->query("CREATE TABLE $event (num INT NOT NULL PRIMARY KEY, ex INT, ey INT, FOREIGN KEY (ex, ey) REFERENCES $parent (x, y)) ENGINE=InnoDB");
$mysqli->query("CREATE TABLE $warranty (num INT NOT NULL PRIMARY KEY, wx INT, wy INT, FOREIGN KEY (wx, wy) REFERENCES $parent (x, y)) ENGINE=InnoDB");

$probes['3a. Composite keys: names the server gives (event / warranty)'] = fkNames($mysqli, $event) . ' / ' . fkNames($mysqli, $warranty);
$probes['3b. Composite keys: KEY_COLUMN_USAGE rows that reference item'] = inboundRows($mysqli, $parent);
$probes['3c. Composite keys: foreignKeysReferencing(item) result']       = zenJson(static fn() => Table::foreignKeysReferencing('item'));
$probes['3d. Composite keys: foreignKeysReferencing(item) verdict']      = inboundVerdict('item', [[$event, ['ex', 'ey']], [$warranty, ['wx', 'wy']]]);
$probes['3e. Composite keys: foreignKeys(event) result']                 = zenJson(static fn() => Table::foreignKeys('event'));

//endregion
//region Scenario 4: a key in another database that references this one

// Names only ever had to be unique per database, so this pair can share a name on
// every server. The other database's table also has the same table name.
dropAllTables($mysqli, [$scratch, $otherDb]);
$mysqli->query("CREATE TABLE $parent (num INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$mysqli->query("CREATE TABLE $event (num INT NOT NULL PRIMARY KEY, item_id INT, CONSTRAINT fk_cross FOREIGN KEY (item_id) REFERENCES $parent (num)) ENGINE=InnoDB");

$probes['4a. Other database: same explicit name on a key that references item'] = run($mysqli, "CREATE TABLE `$otherDb`.$event (num INT NOT NULL PRIMARY KEY, other_item_id INT, CONSTRAINT fk_cross FOREIGN KEY (other_item_id) REFERENCES `$scratch`.$parent (num)) ENGINE=InnoDB");
$probes['4b. Other database: KEY_COLUMN_USAGE rows that reference item']         = inboundRows($mysqli, $parent);
$probes['4c. Other database: foreignKeysReferencing(item) result']               = zenJson(static fn() => Table::foreignKeysReferencing('item'));
$probes['4d. Other database: entries returned for 2 foreign keys']               = zenJson(static fn() => count(Table::foreignKeysReferencing('item')));

// Unnamed this time: two tables with the same name in two databases get the same generated name
dropAllTables($mysqli, [$scratch, $otherDb]);
$mysqli->query("CREATE TABLE $parent (num INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$mysqli->query("CREATE TABLE $event (num INT NOT NULL PRIMARY KEY, item_id INT, FOREIGN KEY (item_id) REFERENCES $parent (num)) ENGINE=InnoDB");

$probes['4e. Other database, unnamed: create the key']                         = run($mysqli, "CREATE TABLE `$otherDb`.$event (num INT NOT NULL PRIMARY KEY, other_item_id INT, FOREIGN KEY (other_item_id) REFERENCES `$scratch`.$parent (num)) ENGINE=InnoDB");
$probes['4f. Other database, unnamed: names (this database / other database)'] = fkNames($mysqli, $event) . ' / ' . fkNames($mysqli, "`$otherDb`.$event");
$probes['4g. Other database, unnamed: KEY_COLUMN_USAGE rows that reference item'] = inboundRows($mysqli, $parent);
$probes['4h. Other database, unnamed: foreignKeysReferencing(item) result']       = zenJson(static fn() => Table::foreignKeysReferencing('item'));
$probes['4i. Other database, unnamed: entries returned for 2 foreign keys']       = zenJson(static fn() => count(Table::foreignKeysReferencing('item')));

//endregion
//region Output

echo "## $label ({$report['version']})\n\n";
foreach ($probes as $name => $answer) {
    echo "- $name: " . mdValue($answer) . "\n";
}

//endregion

/**
 * Merge the JSON files written by --json into one markdown report. Servers that
 * gave the same answer share a line, so the report shows who differs.
 */
function mergeReports(array $paths): void
{
    $reports  = []; // server => [probe => answer]
    $versions = []; // server => VERSION()
    foreach ($paths as $path) {
        $report = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (isset($report['server'], $report['probes'])) {
            $reports[$report['server']]  = $report['probes'];
            $versions[$report['server']] = $report['version'] ?? '';
        } else {
            fwrite(STDERR, "Skipping $path: not an fk-names-probe JSON file\n");
        }
    }
    uksort($reports, static fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));

    echo "# Foreign Key Names Matrix\n\n";
    if (!$reports) {
        echo "No probe data found.\n";
        return;
    }
    echo "Servers: " . implode(', ', array_map(static fn($s) => "$s ($versions[$s])", array_keys($reports))) . "\n\n";

    $families   = serverFamilies(array_keys($reports));
    $probeNames = [];
    foreach ($reports as $probes) {
        $probeNames += array_fill_keys(array_keys($probes), true);
    }
    ksort($probeNames);

    foreach (array_keys($probeNames) as $name) {
        $serversByAnswer = [];
        $missing         = [];
        foreach ($reports as $server => $probes) {
            if (array_key_exists($name, $probes)) {
                $serversByAnswer[$probes[$name]][] = $server;
            } else {
                $missing[] = $server;
            }
        }

        echo "### $name\n\n";
        if (count($serversByAnswer) === 1 && !$missing) {
            echo "- all servers: " . mdValue((string)array_key_first($serversByAnswer)) . "\n";
        } else {
            foreach ($serversByAnswer as $answer => $servers) {
                echo "- " . mdValue((string)$answer) . " → " . (serverGroupLabel($servers, $families) ?? implode(', ', $servers)) . "\n";
            }
            if ($missing) {
                echo "- (no data) → " . (serverGroupLabel($missing, $families) ?? implode(', ', $missing)) . "\n";
            }
        }
        echo "\n";
    }
}
