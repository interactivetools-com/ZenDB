#!/usr/bin/env php
<?php
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

/**
 * Check how Connection::openConnection() sets the connection charset on database
 * servers started with different charset settings, and compare the session it
 * leaves with the session a baseline release leaves.
 *
 *     php connect-charset-probe.php --variants=variants.json --json=out.json                                  # this checkout only
 *     php connect-charset-probe.php --variants=variants.json --old=/tmp/baseline/src --baseline=v1.0.0 --json=out.json
 *     php connect-charset-probe.php --proxysql=3.0.12 --old=/tmp/baseline/src --baseline=v1.0.0 --json=out.json  # through ProxySQL
 *     php connect-charset-probe.php --merge probes/*.json                                                     # one "who differs" report
 *
 * variants.json is written by connect-charset-servers.sh: one database container per
 * server variant, each on its own port. For every variant that started, this script
 * runs itself as a child process with this checkout's src/ ("new"), and again with
 * the src/ directory --old points at ("old"), because one PHP process can load only
 * one Connection class. Each child prints one "variant | fact => value" line per fact.
 *
 * Facts tagged [new] or [old] went through ZenDB; untagged facts used plain mysqli.
 * Where a fact has both tags, the parent adds an "[old vs new]" line.
 *
 * The CI workflow (.github/workflows/connect-charset-matrix.yml) runs this against
 * every database image and PHP version in the matrix, and --merge turns the JSON
 * files into docs/internal/connect-charset-matrix.md.
 *
 * Needs composer install, since it loads the library. Everything it creates is in
 * databases named zdb_probe*, on servers that exist only for the job.
 *
 * Local dry run against one server, no writes (expects root with an empty password):
 *
 *     php -d mysqli.default_port=3306 connect-charset-probe.php --child --readonly --variant=local --src=new
 */

require __DIR__ . '/ci-lib.php';

const PROBE_HOST     = '127.0.0.1';
const PROBE_DATABASE = 'zdb_probe';   // created with no charset clause, so it has the server's default
const PROBE_USER     = 'zdb_plain';   // no SUPER or CONNECTION_ADMIN: init_connect runs for this user, not for root

$options = getopt('', ['merge', 'child', 'readonly', 'proxy', 'proxysql:', 'variants:', 'old:', 'baseline:', 'json:', 'variant:', 'src:'], $restIndex);
$facts   = [];   // "variant | fact [src]" => value

if (isset($options['merge'])) {
    mergeReports(array_slice($argv, $restIndex));
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

//region Parent: one child per variant and source

if (!isset($options['child'])) {
    $oldSrc  = $options['old'] ?? '';
    $sources = $oldSrc !== '' ? ['new', 'old'] : ['new'];
    $report  = [
        'server'     => getenv('DB_LABEL') ?: 'unlabeled',
        'php'        => getenv('PHP_LABEL') ?: PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'phpVersion' => PHP_VERSION,
        'client'     => mysqli_get_client_info(),
        'baseline'   => $oldSrc !== '' ? ($options['baseline'] ?? 'baseline') : '',
    ];

    if (isset($options['proxysql'])) {
        $report['proxysql'] = $options['proxysql'];
        $report            += proxysqlFacts($sources, $oldSrc, $facts);
    } else {
        $variants = json_decode((string)file_get_contents($options['variants']), true, 16, JSON_THROW_ON_ERROR);
        foreach ($variants as $entry) {
            $name = $entry['name'];
            $facts["$name | server: mysqld flags"] = $entry['flags'] === '' ? '(none)' : $entry['flags'];
            $facts["$name | server: started"]      = $entry['status'] === 'up' ? 'yes' : "NO ($entry[status]) $entry[detail]";
            echo "$name | server: started => " . $facts["$name | server: started"] . "\n";
            if ($entry['status'] !== 'up') {
                continue;
            }

            // A database with the server's default charset, and a user without admin privileges
            try {
                $root = mysqli_init();
                $root->options(MYSQLI_SET_CHARSET_NAME, 'utf8mb4');
                $root->real_connect(PROBE_HOST, 'root', '', null, (int)$entry['port']);
                $root->set_charset('utf8mb4');
                $root->query("DROP DATABASE IF EXISTS " . PROBE_DATABASE);
                $root->query("CREATE DATABASE " . PROBE_DATABASE);
                $root->query("DROP USER IF EXISTS '" . PROBE_USER . "'@'%'");
                $root->query("CREATE USER '" . PROBE_USER . "'@'%' IDENTIFIED BY ''");
                $root->query("GRANT ALL PRIVILEGES ON `zdb\\_probe%`.* TO '" . PROBE_USER . "'@'%'");
                $root->close();
            } catch (Throwable $e) {
                $facts["$name | server: probe setup"] = 'FAILED ' . describeError($e);
                echo "$name | server: probe setup => " . $facts["$name | server: probe setup"] . "\n";
                continue;
            }

            foreach ($sources as $src) {
                $facts += runChild($name, $src, (int)$entry['port'], $oldSrc, false);
            }
        }
    }

    // Pair every [new] fact with its [old] twin
    foreach ($facts as $key => $newValue) {
        if (!str_ends_with($key, ' [new]')) {
            continue;
        }
        $base   = substr($key, 0, -6);
        $oldKey = "$base [old]";
        if (array_key_exists($oldKey, $facts)) {
            $oldValue = $facts[$oldKey];
            $facts["$base [old vs new]"] = $oldValue === $newValue ? 'identical' : "DIFFERS: old $oldValue | new $newValue";
        }
    }

    $report['facts'] = $facts;
    file_put_contents($options['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    fwrite(STDERR, "Wrote " . count($facts) . " facts to $options[json]\n");
    exit;
}

/**
 * Run this script as a child for one variant and one source, and return the facts it recorded.
 */
function runChild(string $variant, string $src, int $port, string $oldSrc, bool $viaProxy): array
{
    $childJson = tempnam(sys_get_temp_dir(), 'zdb');
    $command   = escapeshellarg(PHP_BINARY) . " -d mysqli.default_port=$port " . escapeshellarg(__FILE__)
               . " --child --variant=" . escapeshellarg($variant) . " --src=$src --json=" . escapeshellarg($childJson)
               . ($src === 'old' ? " --old=" . escapeshellarg($oldSrc) : '')
               . ($viaProxy ? ' --proxy' : '');
    passthru($command, $exitCode);
    $childFacts = json_decode((string)file_get_contents($childJson), true) ?: [];
    unlink($childJson);
    if ($exitCode !== 0) {
        $childFacts["$variant | probe process [$src]"] = "exited with code $exitCode after " . count($childFacts) . " facts";
    }
    return $childFacts;
}

/**
 * Run one statement on ProxySQL's admin interface and return the output lines. One
 * statement per client call: ProxySQL 2.5.5 answers a batch with "Commands out of
 * sync". The client inside the ProxySQL container is tried first, since it is the
 * one that release was built to work with, then the runner's own.
 * admin/admin is ProxySQL's stock admin login, reachable from localhost only.
 */
function proxysqlAdmin(string $sql): array
{
    $container = getenv('PROXYSQL_CONTAINER') ?: 'proxysql';
    $client    = "mysql -h 127.0.0.1 -P 6032 -u admin -padmin -N -B -e " . escapeshellarg($sql);
    $lastError = '';
    foreach (["docker exec " . escapeshellarg($container) . " $client", $client] as $command) {
        $output = [];
        exec("$command 2>&1", $output, $exitCode);
        $output = array_values(array_filter($output, fn($line) => !str_contains($line, 'Using a password on the command line')));
        if ($exitCode === 0) {
            return $output;
        }
        $lastError = implode(' ', $output);
    }
    throw new RuntimeException("ProxySQL admin statement failed: " . substr($lastError, 0, 300));
}

/**
 * Make ProxySQL close every connection it holds to the backend server, by taking
 * the server offline and putting it back. The next statement opens a new one.
 */
function proxysqlDropBackendConnections(): void
{
    proxysqlAdmin("UPDATE mysql_servers SET status = 'OFFLINE_HARD'");
    proxysqlAdmin("LOAD MYSQL SERVERS TO RUNTIME");
    sleep(2);
    proxysqlAdmin("UPDATE mysql_servers SET status = 'ONLINE'");
    proxysqlAdmin("LOAD MYSQL SERVERS TO RUNTIME");
}

/**
 * Statements ProxySQL logged as "Unable to parse unknown SET query ... Setting lock_hostgroup".
 */
function proxysqlLockedStatements(): array
{
    $container = getenv('PROXYSQL_CONTAINER') ?: 'proxysql';
    exec("docker logs " . escapeshellarg($container) . " 2>&1", $lines);
    $statements = [];
    foreach ($lines as $line) {
        if (preg_match('/Setting lock_hostgroup.*?enhancements:\s*(.*)$/', $line, $match)) {
            $statements[] = $match[1];
        }
    }
    return $statements;
}

/**
 * Set up a database user on the backend server and ProxySQL in front of it, run the
 * children through ProxySQL, and record what ProxySQL made of their SET statements.
 * Returns extra report fields; the facts go in $facts.
 */
function proxysqlFacts(array $sources, string $oldSrc, array &$facts): array
{
    $backendPort = (int)(getenv('ZDB_BACKEND_PORT') ?: 3306);
    $retry       = function (callable $attempt, int $seconds): mixed {
        for ($deadline = time() + $seconds; ; sleep(2)) {
            try {
                return $attempt();
            } catch (Throwable $e) {
                if (time() >= $deadline) {
                    throw $e;
                }
            }
        }
    };

    // The proxy user's password is made up here, for servers that exist only for this job
    $password = bin2hex(random_bytes(12));
    echo "::add-mask::$password\n";
    $backend = $retry(fn() => new mysqli(PROBE_HOST, 'root', '', null, $backendPort), 180);
    $backend->query("CREATE DATABASE IF NOT EXISTS " . PROBE_DATABASE);
    $backend->query("CREATE USER 'zdb_proxy'@'%' IDENTIFIED WITH mysql_native_password BY '$password'");
    $backend->query("GRANT ALL PRIVILEGES ON `zdb\\_probe%`.* TO 'zdb_proxy'@'%'");
    // ProxySQL's monitor gets its own user, so its logins don't show up as the probe user's
    $backend->query("CREATE USER 'zdb_monitor'@'%' IDENTIFIED WITH mysql_native_password BY '$password'");
    $backendVersion = (string)$backend->query("SELECT VERSION()")->fetch_row()[0];
    $backend->close();

    $retry(fn() => proxysqlAdmin("SELECT 1"), 90);
    $setup = [
        "INSERT INTO mysql_servers (hostgroup_id, hostname, port) VALUES (0, '127.0.0.1', $backendPort)",
        "INSERT INTO mysql_users (username, password, default_hostgroup) VALUES ('zdb_proxy', '$password', 0)",
        "UPDATE global_variables SET variable_value = 'zdb_monitor' WHERE variable_name = 'mysql-monitor_username'",
        "UPDATE global_variables SET variable_value = '$password' WHERE variable_name = 'mysql-monitor_password'",
        // ProxySQL announces its own version string; ZenDB's versionRequired check reads it
        "UPDATE global_variables SET variable_value = '8.0.40' WHERE variable_name = 'mysql-server_version'",
        "LOAD MYSQL SERVERS TO RUNTIME",
        "LOAD MYSQL USERS TO RUNTIME",
        "LOAD MYSQL VARIABLES TO RUNTIME",
    ];
    foreach ($setup as $statement) {
        proxysqlAdmin($statement);
    }
    $variable = fn(string $name): string => proxysqlAdmin("SELECT variable_value FROM global_variables WHERE variable_name = '$name'")[0] ?? '(not set)';
    $facts['proxysql | ProxySQL: mysql-default_charset / mysql-set_query_lock_on_hostgroup / mysql-multiplexing'] =
        $variable('mysql-default_charset') . ' / ' . $variable('mysql-set_query_lock_on_hostgroup') . ' / ' . $variable('mysql-multiplexing');

    putenv('ZDB_PROBE_USER=zdb_proxy');
    putenv("ZDB_PROBE_PASSWORD=$password");
    foreach ($sources as $src) {
        // Each source starts with no backend connections left over from the one before
        proxysqlDropBackendConnections();
        proxysqlAdmin("SELECT * FROM stats_mysql_query_digest_reset LIMIT 1");   // reading this table clears the statistics
        $lockedBefore = count(proxysqlLockedStatements());
        $facts       += runChild('proxysql', $src, 6033, $oldSrc, true);

        $digests = proxysqlAdmin("SELECT digest_text FROM stats_mysql_query_digest WHERE digest_text LIKE 'SET%' ORDER BY digest_text");
        $locked  = array_values(array_unique(array_slice(proxysqlLockedStatements(), $lockedBefore)));
        $facts["proxysql | ProxySQL: SET statements ProxySQL counted [$src]"]                         = implode(' ; ', $digests) ?: '(none)';
        $facts["proxysql | ProxySQL: SET statements it could not parse (locks the hostgroup) [$src]"] = implode(' ; ', $locked) ?: 'none';
        echo "proxysql | ProxySQL: SET statements ProxySQL counted [$src] => " . $facts["proxysql | ProxySQL: SET statements ProxySQL counted [$src]"] . "\n";
        echo "proxysql | ProxySQL: SET statements it could not parse (locks the hostgroup) [$src] => " . $facts["proxysql | ProxySQL: SET statements it could not parse (locks the hostgroup) [$src]"] . "\n";
    }

    return ['proxysqlVersion' => $variable('admin-version'), 'backendVersion' => $backendVersion];
}

//endregion
//region Child: setup

$variant  = $options['variant'];
$src      = $options['src'];
$readonly = isset($options['readonly']);              // local dry run: root user, no writes
$viaProxy = isset($options['proxy']);                 // through ProxySQL: its own user, and only the facts that make sense there
$user     = $readonly ? 'root' : (getenv('ZDB_PROBE_USER') ?: PROBE_USER);
$password = (string)getenv('ZDB_PROBE_PASSWORD');     // empty except for the ProxySQL user
$database = $readonly ? 'information_schema' : PROBE_DATABASE;

// Written on every exit path, so a crash partway through still reports the facts collected so far
register_shutdown_function(function () use ($options, &$facts) {
    if (isset($options['json'])) {
        file_put_contents($options['json'], json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
});

// +05:30 all year, so a time_zone set from PHP is easy to tell from a server default
date_default_timezone_set('Asia/Kolkata');

// ZenDB classes come from this checkout's src/ for "new" and from the baseline's src/ for "old".
// Composer puts its own autoloader first, so this one is registered after it to go ahead of it
$repoRoot = dirname(__DIR__, 2);
$srcDir   = $src === 'old' ? rtrim((string)$options['old'], '/') : "$repoRoot/src";
require getenv('ZDB_VENDOR_AUTOLOAD') ?: "$repoRoot/vendor/autoload.php";
spl_autoload_register(function (string $class) use ($srcDir): void {
    if (str_starts_with($class, 'Itools\\ZenDB\\')) {
        $file = "$srcDir/" . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);
$loadedFile = (string)(new ReflectionClass(Itools\ZenDB\Connection::class))->getFileName();
if (realpath($loadedFile) !== realpath("$srcDir/Connection.php")) {
    fwrite(STDERR, "Wrong Connection.php loaded for --src=$src: $loadedFile\n");
    exit(2);
}

/**
 * MysqliWrapper that records what charset the PHP side is on when connect() sends its
 * SET statement or calls set_charset(), and can drop the charset option to act like a
 * wrapper that never passes it on.
 */
final class ZdbTracingWrapper extends Itools\ZenDB\MysqliWrapper
{
    /** @var string[] */
    public static array $trace             = [];
    public static bool  $dropCharsetOption = false;

    public function options(int $option, $value): bool
    {
        if (self::$dropCharsetOption && $option === MYSQLI_SET_CHARSET_NAME) {
            return true;
        }
        return parent::options($option, $value);
    }

    public function set_charset(string $charset): bool
    {
        self::$trace[] = "set_charset($charset) while PHP side is " . $this->character_set_name();
        return parent::set_charset($charset);
    }

    public function real_query(string $query): bool
    {
        if (str_starts_with($query, 'SET ')) {
            self::$trace[] = "connect's SET statement while PHP side is " . $this->character_set_name();
        }
        return parent::real_query($query);
    }
}

//endregion
//region Child: helpers

/**
 * Record one fact and print it. Facts without a source tag come from plain mysqli, so
 * only the "new" child records them.
 */
function fact(string $label, string|int $value, bool $perSource = false): void
{
    global $facts, $variant, $src;
    if (!$perSource && $src !== 'new') {
        return;
    }
    $key         = "$variant | $label" . ($perSource ? " [$src]" : '');
    $value       = str_replace(["\r", "\n"], ['\r', '\n'], (string)$value);
    $facts[$key] = $value;
    echo "$key => $value\n";
}

/**
 * Run one group of facts; a failure becomes a fact instead of ending the child.
 */
function section(string $name, bool $perSource, callable $run): void
{
    try {
        $run();
    } catch (Throwable $e) {
        fact("$name: PROBE FAILED", describeError($e), $perSource);
    }
}

/**
 * Exception as "class code: message", with IP addresses removed so results compare across jobs.
 */
function describeError(Throwable $e): string
{
    $message = preg_replace('/\d+\.\d+\.\d+\.\d+/', '<ip>', $e->getMessage());
    return get_class($e) . ' ' . $e->getCode() . ': ' . $message;
}

/**
 * Plain mysqli connection on the port from mysqli.default_port, with or without the charset option.
 */
function rawConnect(string $user, ?string $database, bool $charsetOption, bool $pooled = false): mysqli
{
    $mysqli = mysqli_init();
    if ($charsetOption) {
        $mysqli->options(MYSQLI_SET_CHARSET_NAME, 'utf8mb4');
    }
    $password = $user === $GLOBALS['user'] ? $GLOBALS['password'] : '';
    $mysqli->real_connect(($pooled ? 'p:' : '') . PROBE_HOST, $user, $password, $database);
    return $mysqli;
}

/**
 * Root connection for setup and for reading stored bytes; utf8mb4 on both sides whatever the server does at login.
 */
function rootConnect(): mysqli
{
    $mysqli = rawConnect('root', null, true);
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

/**
 * One row of text values. A session whose character_set_results is ucs2 returns two
 * bytes per character, so NUL bytes are dropped to keep the values readable.
 */
function fetchValues(mysqli $mysqli, string $sql): array
{
    return array_map(fn($value) => str_replace("\0", '', (string)$value), $mysqli->query($sql)->fetch_row() ?? []);
}

/**
 * "character_set_client / character_set_connection / character_set_results / collation_connection"
 */
function sessionCharsets(mysqli $mysqli): string
{
    return implode(' / ', fetchValues($mysqli, "SELECT @@character_set_client, @@character_set_connection, @@character_set_results, @@collation_connection"));
}

/**
 * "time_zone / sql_mode"
 */
function sessionSettings(mysqli $mysqli): string
{
    return implode(' / ', fetchValues($mysqli, "SELECT @@time_zone, @@sql_mode"));
}

/**
 * Session status Questions: statements the server has received on this connection, this one included.
 */
function questions(mysqli $mysqli): int
{
    return (int)fetchValues($mysqli, "SHOW SESSION STATUS LIKE 'Questions'")[1];
}

/**
 * Questions as reported by the first statement on a new connection that sent nothing
 * else. Connect statement counts are measured against this, so the counting statement
 * and anything the server runs by itself at login (init_connect) cancel out.
 */
function firstStatementQuestions(string $user, ?string $database): int
{
    $mysqli = rawConnect($user, $database, true);
    $count  = questions($mysqli);
    $mysqli->close();
    return $count;
}

/**
 * Hex of what real_escape_string() returns for bytes that multibyte charsets treat
 * differently. mysqlnd escapes utf8mb4 and latin1 the same way; gbk, sjis and big5
 * give other results, so this shows which kind of charset the PHP side escapes for.
 */
function escapeFingerprint(mysqli $mysqli): string
{
    $inputs = ["\xbf'", "\xbf\\", "\x83\\", "\xe4'", "a\0b"];
    return implode(' ', array_map(fn($input) => bin2hex($mysqli->real_escape_string($input)), $inputs));
}

/**
 * New ZenDB connection as the probe user, raw values in results.
 */
function zenConnect(array $overrides = []): Itools\ZenDB\Connection
{
    global $user, $password, $database;
    return new Itools\ZenDB\Connection($overrides + [
        'hostname'        => PROBE_HOST,
        'username'        => $user,
        'password'        => ($overrides['username'] ?? $user) === $user ? $password : '',
        'database'        => $database,
        'useSmartStrings' => false,
    ]);
}

/**
 * "yes" when the PHP side and the three session charsets are all utf8mb4.
 */
function allUtf8mb4(mysqli $mysqli): string
{
    return $mysqli->character_set_name() === 'utf8mb4' && str_starts_with(sessionCharsets($mysqli), 'utf8mb4 / utf8mb4 / utf8mb4 / ') ? 'yes' : 'NO';
}

/**
 * Connect through ZenDB and record what the connect sent and the session it left.
 * $baseline is firstStatementQuestions() for the same user on the same kind of connection.
 */
function connectFacts(string $label, array $overrides, ?int $baseline): void
{
    try {
        $conn = zenConnect($overrides);
    } catch (Throwable $e) {
        fact("$label: result", describeError($e), true);
        return;
    }
    $mysqli    = $conn->mysqli;
    $questions = questions($mysqli);   // must be the first statement after connect
    fact("$label: result", 'connected', true);
    fact("$label: statements sent by connect", $baseline === null ? "unknown (Questions $questions)" : $questions - $baseline, true);
    fact("$label: PHP character_set_name()", $mysqli->character_set_name(), true);
    fact("$label: session charsets", sessionCharsets($mysqli), true);
    fact("$label: PHP side and all three session charsets are utf8mb4", allUtf8mb4($mysqli), true);
    fact("$label: time_zone / sql_mode", sessionSettings($mysqli), true);
    fact("$label: escape fingerprint", escapeFingerprint($mysqli), true);
    $conn->disconnect();
}

/**
 * Claim 12: the connection collation against the collation of new utf8mb4 tables and
 * databases, and whether comparing columns with literals and user variables works.
 */
function collationFacts(string $label): void
{
    $conn   = zenConnect();
    $mysqli = $conn->mysqli;
    $one    = fn(string $sql): string => fetchValues($mysqli, $sql)[0];

    $connection = $one("SELECT @@collation_connection");
    $mysqli->query("DROP DATABASE IF EXISTS zdb_probe_c12");
    $mysqli->query("CREATE DATABASE zdb_probe_c12 CHARACTER SET utf8mb4");
    $databaseCollation = $one("SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'zdb_probe_c12'");
    $mysqli->query("DROP DATABASE zdb_probe_c12");

    $mysqli->query("DROP TABLE IF EXISTS c12_mb4, c12_default, c12_literal");
    $mysqli->query("CREATE TABLE c12_mb4 (t VARCHAR(20)) DEFAULT CHARSET=utf8mb4");
    $mysqli->query("CREATE TABLE c12_default (t VARCHAR(20))");
    $mysqli->query("CREATE TABLE c12_literal AS SELECT 'a' AS t");
    $mysqli->query("INSERT INTO c12_mb4 VALUES ('a')");
    $mysqli->query("INSERT INTO c12_default VALUES ('a')");
    $columnCollation = fn(string $table): string => $one("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = 't'");
    $tableCollation  = $columnCollation('c12_mb4');

    fact("$label: @@collation_connection", $connection, true);
    fact("$label: CREATE DATABASE ... CHARACTER SET utf8mb4 collation", $databaseCollation, true);
    fact("$label: CREATE TABLE ... DEFAULT CHARSET=utf8mb4 column collation", $tableCollation, true);
    fact("$label: connection, new table and new database collations", $connection === $tableCollation && $connection === $databaseCollation ? 'all the same' : "DIFFER: connection $connection, table $tableCollation, database $databaseCollation", true);
    fact("$label: CREATE TABLE with no charset clause column collation", $columnCollation('c12_default'), true);
    fact("$label: CREATE TABLE AS SELECT 'a' column collation", $columnCollation('c12_literal'), true);

    $mysqli->query("SET @zdb_value = 'a'");
    $comparisons = [
        "utf8mb4 column = 'a'"                             => "SELECT COUNT(*) FROM c12_mb4 WHERE t = 'a'",
        "utf8mb4 column = @user_variable"                  => "SELECT COUNT(*) FROM c12_mb4 WHERE t = @zdb_value",
        "utf8mb4 column = column made from a literal"      => "SELECT COUNT(*) FROM c12_mb4 JOIN c12_literal ON c12_mb4.t = c12_literal.t",
        "server-default column = 'a'"                      => "SELECT COUNT(*) FROM c12_default WHERE t = 'a'",
        "server-default column = @user_variable"           => "SELECT COUNT(*) FROM c12_default WHERE t = @zdb_value",
        "server-default column = column made from a literal" => "SELECT COUNT(*) FROM c12_default JOIN c12_literal ON c12_default.t = c12_literal.t",
    ];
    foreach ($comparisons as $name => $sql) {
        try {
            $result = 'ok, ' . $one($sql) . ' row';
        } catch (mysqli_sql_exception $e) {
            $result = 'error ' . $e->getCode() . ': ' . $e->getMessage();
        }
        fact("$label: $name", $result, true);
    }

    $mysqli->query("DROP TABLE c12_mb4, c12_default, c12_literal");
    $conn->disconnect();
}

/**
 * Rows the server's general log holds for one connection: every command it received, in order.
 */
function generalLogOf(mysqli $root, int $threadId): string
{
    $commands = [];
    foreach ($root->query("SELECT command_type, CONVERT(argument USING utf8mb4) FROM mysql.general_log WHERE thread_id = $threadId")->fetch_all() as [$type, $argument]) {
        $commands[] = $type === 'Query' ? "Query($argument)" : $type;
    }
    return $commands ? implode(' > ', $commands) : '(no rows)';
}

//endregion
//region Child: facts through ProxySQL

// Session status describes ProxySQL's backend connections, not this client, so only
// the session a client sees, the bytes it gets back, and the statements the backend
// server logged are recorded. The parent adds ProxySQL's own statistics
if ($viaProxy) {
    section('ProxySQL', true, function () use ($user, $database, $src) {
        $state = fn(mysqli $mysqli): string => 'PHP side ' . $mysqli->character_set_name() . ', session ' . sessionCharsets($mysqli) . ' / ' . sessionSettings($mysqli);

        // The backend's general log shows what ProxySQL sends it for this client. ProxySQL's
        // monitor logs in as another user, so every zdb_proxy row is this client's doing.
        // "Connect" in the list means ProxySQL opened a new backend connection
        $backend = new mysqli(PROBE_HOST, 'root', '', null, (int)(getenv('ZDB_BACKEND_PORT') ?: 3306));
        $backend->query("SET GLOBAL log_output = 'TABLE'");
        $backend->query("SET GLOBAL general_log = 1");
        $backendLog = function () use ($backend): string {
            $commands = [];
            // a Connect row's user_host starts with "[user]", every later row's with "user[user]"
            foreach ($backend->query("SELECT command_type, CONVERT(argument USING utf8mb4) FROM mysql.general_log WHERE (user_host LIKE 'zdb_proxy%' OR user_host LIKE '[zdb_proxy]%') AND command_type IN ('Connect', 'Query', 'Init DB', 'Change user')")->fetch_all() as [$type, $argument]) {
                $commands[] = $type === 'Query' ? "Query($argument)" : $type;
            }
            return $commands ? implode(' > ', $commands) : '(none)';
        };

        $backend->query("TRUNCATE TABLE mysql.general_log");
        try {
            $conn = zenConnect();
        } catch (Throwable $e) {
            fact('ProxySQL: connect', describeError($e), true);
            return;
        }
        fact('ProxySQL: connect', 'connected', true);
        fact('ProxySQL: right after connect', $state($conn->mysqli), true);
        fact('ProxySQL: what the backend server logged for the first connect and its session query', $backendLog(), true);

        // another client on another charset between this connection's statements
        $other = rawConnect($user, $database, true);
        $other->query("SET NAMES latin1");
        $other->query("SET time_zone = '+09:00'");
        $conn->mysqli->query("SELECT 1");
        $other->query("SELECT 1");
        fact('ProxySQL: this connection after another client ran SET NAMES latin1 and statements on both', $state($conn->mysqli), true);
        fact('ProxySQL: the other client after statements on both', $state($other), true);

        $other->close();

        if ($src === 'new') {
            $values   = ["'", '"', '\\', "\0", "a'b\"c\\d", "caf\xc3\xa9", "\xf0\x9f\x98\x80'", "\xbf'", "\xbf\\", "\xa4'", "\x83\\", "\xe4'\\"];
            $failures = [];
            foreach ($values as $value) {
                try {
                    $rows = $conn->query("SELECT ? AS v", $value)->toArray();
                    if (count($rows) !== 1 || $rows[0]['v'] !== $value) {
                        $failures[] = bin2hex($value) . ' came back as ' . count($rows) . ' row(s), first value ' . bin2hex((string)($rows[0]['v'] ?? ''));
                    }
                } catch (Throwable $e) {
                    $failures[] = bin2hex($value) . ' failed with ' . describeError($e);
                }
            }
            fact('ProxySQL: ? placeholder, ' . count($values) . ' values: one row with the same bytes', $failures ? 'NOT ALL: ' . implode('; ', $failures) : 'all', true);
        }

        $conn->mysqli->query("DROP TABLE IF EXISTS zdb_proxy_roundtrip");
        $conn->mysqli->query("CREATE TABLE zdb_proxy_roundtrip (id INT NOT NULL PRIMARY KEY, t VARCHAR(100)) DEFAULT CHARSET=utf8mb4");
        $conn->insert('zdb_proxy_roundtrip', ['id' => 1, 't' => "caf\xc3\xa9 \xf0\x9f\x98\x80"]);
        $stored   = strtolower((string)fetchValues($conn->mysqli, "SELECT HEX(t) FROM zdb_proxy_roundtrip WHERE id = 1")[0]);
        $readBack = bin2hex((string)$conn->select('zdb_proxy_roundtrip', ['id' => 1])->toArray()[0]['t']);
        fact('ProxySQL: table round trip of cafe with e-acute and an emoji', "sent 636166c3a920f09f9880, stored $stored, read back $readBack", true);
        $conn->mysqli->query("DROP TABLE zdb_proxy_roundtrip");
        $conn->disconnect();

        // the connection collation against new utf8mb4 tables and databases, as a client behind the proxy sees them
        try {
            collationFacts('ProxySQL: C12');
        } catch (Throwable $e) {
            fact('ProxySQL: C12: PROBE FAILED', describeError($e), true);
        }

        foreach (["usePhpTimezone=false sqlMode=''" => ['usePhpTimezone' => false, 'sqlMode' => ''], 'usePhpTimezone=false' => ['usePhpTimezone' => false], "sqlMode=''" => ['sqlMode' => '']] as $label => $overrides) {
            try {
                $conn = zenConnect($overrides);
                fact("ProxySQL: connect with $label", $state($conn->mysqli), true);
                $conn->disconnect();
            } catch (Throwable $e) {
                fact("ProxySQL: connect with $label", describeError($e), true);
            }
        }

        // Last, because it closes every backend connection: a second connect when the only
        // backend connection ProxySQL holds was last used by a latin1 client, so ProxySQL
        // has to change that connection's charset or open another. The latin1 client's
        // statements have no @ in them, so ProxySQL takes its backend connection back
        // after each one
        try {
            proxysqlDropBackendConnections();
            $latin1 = rawConnect($user, $database, true);
            $latin1->query("SET NAMES latin1");
            $latin1->query("SELECT 1");
            $backend->query("TRUNCATE TABLE mysql.general_log");
            $second = zenConnect();
            fact('ProxySQL: second connect, when the one backend connection was last used on latin1', $state($second->mysqli), true);
            fact('ProxySQL: what the backend server logged for that second connect and its session query', $backendLog(), true);
            $second->disconnect();
            $latin1->close();
        } catch (Throwable $e) {
            fact('ProxySQL: second connect: PROBE FAILED', describeError($e), true);
        }
        $backend->query("SET GLOBAL general_log = 0");
        $backend->close();
    });
    exit;
}

//endregion
//region Child: facts

$baseline = null;   // Questions on the first statement of a fresh connection as the probe user
section('baseline', true, function () use (&$baseline, $user, $database) {
    $baseline = firstStatementQuestions($user, $database);
});

section('server', false, function () {
    $root = rootConnect();
    fact('server: version', implode(' / ', fetchValues($root, "SELECT VERSION(), @@version_comment")));
    fact('server: global character_set_server / collation_server', implode(' / ', fetchValues($root, "SELECT @@global.character_set_server, @@global.collation_server")));
    fact('server: global character_set_client / collation_connection', implode(' / ', fetchValues($root, "SELECT @@global.character_set_client, @@global.collation_connection")));
    foreach (['init_connect', 'sql_mode', 'character_set_collations', 'default_collation_for_utf8mb4', 'old_mode', 'character_set_client_handshake'] as $variable) {
        try {
            $value = fetchValues($root, "SELECT @@global.$variable")[0];
            fact("server: global $variable", $value === '' ? '(empty)' : $value);
        } catch (mysqli_sql_exception $e) {
            fact("server: global $variable", 'no such variable (error ' . $e->getCode() . ')');
        }
    }
});

// Claims 1 and 2: the PHP side with and without the charset option, plain mysqli
section('C01 C02', false, function () use ($user, $database) {
    $without          = rawConnect($user, $database, false);
    $with             = rawConnect($user, $database, true);
    $questionsWithout = questions($without);   // first statement on each connection
    $questionsWith    = questions($with);
    fact('C01 charset option: PHP character_set_name()', $with->character_set_name());
    fact('C01 charset option: escape fingerprint', escapeFingerprint($with));
    fact('C01 charset option: Questions on the first statement, minus the same on a connect without the option', $questionsWith - $questionsWithout);
    fact('C02 no option: Questions on the first statement', $questionsWithout);
    fact('C02 no option: PHP character_set_name()', $without->character_set_name());
    fact('C02 no option: escape fingerprint', escapeFingerprint($without));
    fact('C02 no option: session charsets', sessionCharsets($without));

    $previous = ini_set('default_charset', 'ISO-8859-1');
    $withIni  = rawConnect($user, $database, false);
    fact('X5 no option, ini default_charset=ISO-8859-1: PHP character_set_name()', $withIni->character_set_name());
    ini_set('default_charset', (string)$previous);
});

// Claim 7: what login alone leaves on the server, against SET NAMES and set_charset()
section('C07', false, function () use ($user, $database) {
    foreach (array_unique(['root', $user]) as $loginUser) {
        $mysqli     = rawConnect($loginUser, $database, true);
        $loginState = sessionCharsets($mysqli);
        $mysqli->query("SET NAMES utf8mb4");
        $namesState = sessionCharsets($mysqli);
        fact("C07 login only with the charset option, user $loginUser: session charsets", $loginState);
        fact("C07 after SET NAMES utf8mb4, user $loginUser: session charsets", $namesState);
        fact("C07 login only against SET NAMES utf8mb4, user $loginUser", $loginState === $namesState ? 'identical' : "DIFFERS: login $loginState | NAMES $namesState");
    }
    $mysqli = rawConnect($user, $database, false);
    $mysqli->set_charset('utf8mb4');
    fact('C07 no option then set_charset(utf8mb4): PHP side, session charsets', $mysqli->character_set_name() . ', ' . sessionCharsets($mysqli));
});

// Claim 5: each form of the merged SET statement, from a session that starts on other values
section('C05', false, function () use ($user, $database) {
    $statements = [
        "SET NAMES utf8mb4"                                                          => ['+09:00', 'PIPES_AS_CONCAT'],
        "SET NAMES utf8mb4, time_zone = '+05:30'"                                    => ['+05:30', 'PIPES_AS_CONCAT'],
        "SET NAMES utf8mb4, sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE'"          => ['+09:00', 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE'],
        "SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE'" => ['+05:30', 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE'],
    ];
    foreach ($statements as $sql => [$expectedTimeZone, $expectedSqlMode]) {
        $mysqli = rawConnect($user, $database, false);
        $mysqli->query("SET NAMES latin1");
        $mysqli->query("SET time_zone = '+09:00'");
        $mysqli->query("SET sql_mode = 'PIPES_AS_CONCAT'");
        try {
            $mysqli->query($sql);
            $charsets = sessionCharsets($mysqli);
            $settings = sessionSettings($mysqli);
            $asStated = str_starts_with($charsets, 'utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_') && $settings === "$expectedTimeZone / $expectedSqlMode";
            fact("C05 $sql", ($asStated ? 'took effect: ' : 'UNEXPECTED: ') . "$charsets / $settings");
        } catch (mysqli_sql_exception $e) {
            fact("C05 $sql", 'REJECTED ' . describeError($e));
        }
    }
});

// Claims 3, 6, 8, 9: what connect() sends and the session it leaves, as a plain user and as root
section('connect', true, function () use ($user, $database, $baseline) {
    connectFacts("connect as $user", [], $baseline);
    if ($user !== 'root') {
        connectFacts('connect as root', ['username' => 'root'], firstStatementQuestions('root', $database));
    }
});

// Claim 5, config side: usePhpTimezone and sqlMode combinations
section('C05 config', true, function () use ($baseline) {
    foreach ([true, false] as $usePhpTimezone) {
        foreach (['default' => [], "''" => ['sqlMode' => ''], 'ANSI_QUOTES,STRICT_ALL_TABLES' => ['sqlMode' => 'ANSI_QUOTES,STRICT_ALL_TABLES']] as $modeLabel => $modeConfig) {
            connectFacts('C05 config usePhpTimezone=' . ($usePhpTimezone ? 'true' : 'false') . " sqlMode=$modeLabel", ['usePhpTimezone' => $usePhpTimezone] + $modeConfig, $baseline);
        }
    }
});

// Claim 4: a pooled slot that an earlier script left on another charset
section('C04', true, function () use ($user, $database) {
    fact('C04 ini mysqli.allow_persistent', (string)ini_get('mysqli.allow_persistent'));
    $both = fn(mysqli $mysqli): string => 'PHP side ' . $mysqli->character_set_name() . ', session ' . sessionCharsets($mysqli);

    foreach (['latin1', 'gbk'] as $charset) {
        $leaveSlotOn = function () use ($user, $database, $charset): int {
            $mysqli = rawConnect($user, $database, false, true);
            $mysqli->set_charset($charset);
            $threadId = $mysqli->thread_id;
            $mysqli->close();
            return $threadId;
        };
        $label = "C04 pooled slot left on $charset";

        // plain mysqli: each fix by itself
        $threadId       = $leaveSlotOn();
        $reused         = rawConnect($user, $database, true, true);
        $pooledBaseline = questions($reused);   // first statement after reuse
        fact("$label, reopened with the charset option: same server thread", $reused->thread_id === $threadId ? 'yes' : 'NO');
        fact("$label, reopened with the charset option: Questions on the first statement", $pooledBaseline);
        fact("$label, reopened with the charset option", $both($reused));
        $reused->query("SET NAMES utf8mb4");
        fact("$label, then a SET NAMES utf8mb4 query only", $both($reused));
        $reused->close();

        $reused = rawConnect($user, $database, true, true);
        fact("$label, closed after the SET NAMES query and reopened", $both($reused));
        $reused->set_charset('utf8mb4');
        fact("$label, then set_charset(utf8mb4) only", $both($reused));
        $reused->close();

        // ZenDB connect on the same slot
        $threadId  = $leaveSlotOn();
        $conn      = zenConnect(['hostname' => 'p:' . PROBE_HOST]);
        $questions = questions($conn->mysqli);
        fact("$label, new Connection(): same server thread", $conn->mysqli->thread_id === $threadId ? 'yes' : 'NO', true);
        fact("$label, new Connection(): statements sent by connect", $questions - $pooledBaseline, true);
        fact("$label, new Connection()", $both($conn->mysqli), true);
        fact("$label, new Connection(): time_zone / sql_mode", sessionSettings($conn->mysqli), true);
        fact("$label, new Connection(): escape fingerprint", escapeFingerprint($conn->mysqli), true);
        $conn->disconnect();

        // and once more on the slot the connect above left clean
        $conn      = zenConnect(['hostname' => 'p:' . PROBE_HOST]);
        $questions = questions($conn->mysqli);
        fact("C04 pooled slot left clean after $charset, new Connection(): statements sent by connect", $questions - $pooledBaseline, true);
        fact("C04 pooled slot left clean after $charset, new Connection()", $both($conn->mysqli), true);
        $conn->disconnect();

        // a script that changed only the server side, with a query
        $mysqli = rawConnect($user, $database, true, true);
        $mysqli->query("SET NAMES $charset");
        $threadId = $mysqli->thread_id;
        $mysqli->close();
        $conn      = zenConnect(['hostname' => 'p:' . PROBE_HOST]);
        $questions = questions($conn->mysqli);
        fact("C04 pooled slot after a SET NAMES $charset query, new Connection(): same server thread", $conn->mysqli->thread_id === $threadId ? 'yes' : 'NO', true);
        fact("C04 pooled slot after a SET NAMES $charset query, new Connection(): statements sent by connect", $questions - $pooledBaseline, true);
        fact("C04 pooled slot after a SET NAMES $charset query, new Connection()", $both($conn->mysqli), true);
        $conn->disconnect();
    }
});

// Claim 10: values through placeholders come back as one row with the same bytes. This checkout only
if ($src === 'new') {
    section('C10', true, function () {
        $values = [
            "'", '"', '\\', "\0", "''", "\\'", "a'b\"c\\d", "\x1a", "\r\n", "%_", "caf\xc3\xa9", "\xf0\x9f\x98\x80'",
            "\xbf'", "\xbf\\", "\xa4'", "\xa4\\", "\x83'", "\x83\\", "\xbf\\'", "\x83\\\x83'", "\xbf' x", "x\xbf", "\xe4", "\xf0\x9f", "\xff\xfe'\\", "\xe4'\\",
        ];
        $conn   = zenConnect();
        $styles = [
            '?'     => fn(string $value) => $conn->query("SELECT ? AS v", $value),
            ':name' => fn(string $value) => $conn->query("SELECT :v AS v", [':v' => $value]),
        ];
        foreach ($styles as $style => $run) {
            $failures = [];
            foreach ($values as $value) {
                try {
                    $rows = $run($value)->toArray();
                    if (count($rows) !== 1 || array_keys($rows[0]) !== ['v'] || $rows[0]['v'] !== $value) {
                        $failures[] = bin2hex($value) . ' came back as ' . count($rows) . ' row(s), first value ' . bin2hex((string)($rows[0]['v'] ?? ''));
                    }
                } catch (Throwable $e) {
                    $failures[] = bin2hex($value) . ' failed with ' . describeError($e);
                }
            }
            fact("C10 $style placeholder, " . count($values) . ' values: one row with the same bytes', $failures ? 'NOT ALL: ' . implode('; ', $failures) : 'all', true);
        }
        $conn->disconnect();
    });
}

if (!$readonly) {
    // Claim 11: text through a real table, stored bytes read by a separate utf8mb4 connection
    section('C11', true, function () {
        $values = [
            'cafe with e-acute'  => "caf\xc3\xa9",
            'Greek capital omega' => "\xce\xa9",
            '4-byte emoji'       => "\xf0\x9f\x98\x80",
            'invalid UTF-8 ff fe' => "\xff\xfe",
            'latin1 e-acute byte' => "caf\xe9",
        ];
        $table = 'zdb_roundtrip';   // one name for both sources: MariaDB prints it in its error text
        $conn  = zenConnect();
        $root  = rootConnect();
        $conn->mysqli->query("DROP TABLE IF EXISTS $table");
        $conn->mysqli->query("CREATE TABLE $table (id INT NOT NULL PRIMARY KEY, t VARCHAR(100)) DEFAULT CHARSET=utf8mb4");
        $id = 0;
        foreach ($values as $name => $bytes) {
            $id++;
            try {
                $conn->insert($table, ['id' => $id, 't' => $bytes]);
                $stored   = strtolower((string)($root->query("SELECT HEX(t) FROM " . PROBE_DATABASE . ".$table WHERE id = $id")->fetch_row()[0] ?? 'no row'));
                $readBack = bin2hex((string)($conn->select($table, ['id' => $id])->toArray()[0]['t'] ?? ''));
                $sent     = bin2hex($bytes);
                $result   = "sent $sent, stored $stored, read back $readBack: " . ($stored === $sent && $readBack === $sent ? 'same bytes' : 'DIFFERENT');
            } catch (Throwable $e) {
                $result = 'sent ' . bin2hex($bytes) . ', failed with ' . describeError($e);
            }
            fact("C11 table round trip, $name", $result, true);
        }
        $conn->mysqli->query("DROP TABLE $table");
        $conn->disconnect();
    });

    // Claim 12
    section('C12', true, fn() => collationFacts('C12'));
}

// Claim 13: connects that fail, or connect with an unusual sql_mode
section('C13', true, function () {
    $attempt = function (array $overrides): string {
        try {
            $conn   = zenConnect($overrides);
            $result = 'connected, PHP side ' . $conn->mysqli->character_set_name() . ', session ' . sessionCharsets($conn->mysqli) . ' / ' . sessionSettings($conn->mysqli);
            $conn->disconnect();
            return $result;
        } catch (Throwable $e) {
            return describeError($e);
        }
    };
    foreach (['NO_BACKSLASH_ESCAPES', 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES', 'BOGUS_MODE', "A'B", 'ANSI_QUOTES'] as $sqlMode) {
        fact("C13 sqlMode `$sqlMode`", $attempt(['sqlMode' => $sqlMode]), true);
    }

    // PHP time zones that connect() sends by name (+14:00 and +13:45), which only work when the server has time zone tables
    foreach (['Pacific/Kiritimati', 'Pacific/Chatham'] as $timeZone) {
        date_default_timezone_set($timeZone);
        $result = $attempt([]);
        date_default_timezone_set('Asia/Kolkata');
        fact("C13 PHP time zone $timeZone", $result, true);
    }
});

// Claim 14: the charset the PHP side is on when connect() sends its SET statement, on every path
section('C14', true, function () use ($user, $database, $readonly, $baseline) {
    $factory = Itools\ZenDB\Connection::$mysqliWrapperFactory;
    Itools\ZenDB\Connection::$mysqliWrapperFactory = static fn(?callable $queryLogger): ZdbTracingWrapper => new ZdbTracingWrapper($queryLogger);
    $traced = function (string $label, array $overrides, bool $dropCharsetOption = false): void {
        ZdbTracingWrapper::$trace             = [];
        ZdbTracingWrapper::$dropCharsetOption = $dropCharsetOption;
        try {
            $conn   = zenConnect($overrides);
            $result = implode('; ', ZdbTracingWrapper::$trace) . '; ends with PHP side ' . $conn->mysqli->character_set_name() . ', session ' . sessionCharsets($conn->mysqli);
            $conn->disconnect();
        } catch (Throwable $e) {
            $result = implode('; ', ZdbTracingWrapper::$trace) . '; ' . describeError($e);
        }
        ZdbTracingWrapper::$dropCharsetOption = false;
        fact("C14 $label", $result, true);
    };
    $leaveSlotOnGbk = function (?string $slotDatabase) use ($user): void {
        $mysqli = rawConnect($user, $slotDatabase, false, true);
        $mysqli->set_charset('gbk');
        $mysqli->close();
    };

    try {
        $traced('fresh connect', []);
        $traced('fresh connect, wrapper that drops the charset option', [], true);
        $leaveSlotOnGbk($database);
        $traced('pooled slot left on gbk', ['hostname' => 'p:' . PROBE_HOST]);
        $leaveSlotOnGbk($database);
        $traced('pooled slot left on gbk, wrapper that drops the charset option', ['hostname' => 'p:' . PROBE_HOST], true);

        if (!$readonly) {
            $root = rootConnect();
            $root->query("DROP DATABASE IF EXISTS zdb_probe_auto");
            $traced('databaseAutoCreate (second real_connect), fresh connect', ['database' => 'zdb_probe_auto', 'databaseAutoCreate' => true]);
            fact('C14 databaseAutoCreate: created database collation', fetchValues($root, "SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'zdb_probe_auto'")[0] ?? 'not created', true);
            $root->query("DROP DATABASE IF EXISTS zdb_probe_auto");
            connectFacts('C14 databaseAutoCreate (second real_connect), fresh connect', ['database' => 'zdb_probe_auto', 'databaseAutoCreate' => true], $baseline);

            $root->query("DROP DATABASE IF EXISTS zdb_probe_auto");
            $leaveSlotOnGbk(null);   // the second real_connect() has no database, so it takes the slot opened without one
            $traced('databaseAutoCreate (second real_connect), pooled no-database slot left on gbk', ['hostname' => 'p:' . PROBE_HOST, 'database' => 'zdb_probe_auto', 'databaseAutoCreate' => true]);
            $root->query("DROP DATABASE IF EXISTS zdb_probe_auto");
        }
    } finally {
        Itools\ZenDB\Connection::$mysqliWrapperFactory = $factory;
    }
});

// Extra: things a script can do after connect, and a reconnect
section('extras', true, function () use ($user, $database, $readonly, $baseline) {
    $conn = zenConnect();
    $conn->mysqli->change_user($user, $GLOBALS['password'], $database);
    $settings = fetchValues($conn->mysqli, "SELECT @@time_zone, @@sql_mode, @@global.time_zone, @@global.sql_mode");
    fact('X1 after mysqli change_user() on a connected Connection: PHP side, session charsets', $conn->mysqli->character_set_name() . ', ' . sessionCharsets($conn->mysqli), true);
    fact('X1 after mysqli change_user() on a connected Connection: time_zone and sql_mode', [$settings[0], $settings[1]] === [$settings[2], $settings[3]] ? "back on the server's global values" : "time_zone $settings[0], sql_mode $settings[1]", true);
    $conn->disconnect();

    $conn = zenConnect();
    $conn->disconnect();
    $conn->connect();
    $questions = questions($conn->mysqli);
    fact('X4 disconnect() then connect(): statements sent by connect', $questions - $baseline, true);
    fact('X4 disconnect() then connect(): PHP side and all three session charsets are utf8mb4', allUtf8mb4($conn->mysqli), true);
    $conn->disconnect();

    if (!$readonly) {
        $root = rootConnect();
        $root->query("DROP DATABASE IF EXISTS zdb_probe_latin1");
        $root->query("CREATE DATABASE zdb_probe_latin1 CHARACTER SET latin1");
        $conn   = zenConnect();
        $before = sessionCharsets($conn->mysqli);
        $conn->mysqli->select_db('zdb_probe_latin1');
        $after = sessionCharsets($conn->mysqli);
        fact('X2 after select_db() to a latin1 database', 'session charsets ' . ($before === $after ? 'unchanged' : "CHANGED from $before to $after") . ', character_set_database ' . fetchValues($conn->mysqli, "SELECT @@character_set_database")[0], true);
        $conn->disconnect();
        $root->query("DROP DATABASE zdb_probe_latin1");
    }
});

if (!$readonly) {
    // Claims 1, 3, 4, 9: the commands the server logged for each kind of connect
    section('general log', true, function () use ($user, $database) {
        $root     = rootConnect();
        $original = fetchValues($root, "SELECT @@global.general_log, @@global.log_output");
        $root->query("SET GLOBAL log_output = 'TABLE'");
        $root->query("SET GLOBAL general_log = 1");
        try {
            $root->query("TRUNCATE TABLE mysql.general_log");
            $raw      = rawConnect($user, $database, true);
            $threadId = $raw->thread_id;
            $raw->query("SELECT 'first query'");
            fact('C01 general log: plain connect with the charset option, then one query', generalLogOf($root, $threadId));
            $raw->close();

            $root->query("TRUNCATE TABLE mysql.general_log");
            $conn     = zenConnect();
            $threadId = $conn->mysqli->thread_id;
            $conn->mysqli->query("SELECT 'first query'");
            fact('C03 general log: new Connection(), then one query', generalLogOf($root, $threadId), true);
            $conn->disconnect();

            $dirty = rawConnect($user, $database, false, true);
            $dirty->set_charset('gbk');
            $dirty->close();
            $root->query("TRUNCATE TABLE mysql.general_log");
            $conn     = zenConnect(['hostname' => 'p:' . PROBE_HOST]);
            $threadId = $conn->mysqli->thread_id;
            $conn->mysqli->query("SELECT 'first query'");
            fact('C04 general log: new Connection() on a pooled slot left on gbk, then one query', generalLogOf($root, $threadId), true);
            $conn->disconnect();
        } finally {
            $root->query("SET GLOBAL general_log = " . (in_array($original[0], ['1', 'ON'], true) ? 1 : 0));
            $root->query("SET GLOBAL log_output = '" . $root->real_escape_string($original[1]) . "'");
        }
    });
}

// Global variables changed at runtime, one at a time, on the variant kept for that
if ($variant === 'dyn' && !$readonly) {
    $phases = [
        'default_collation_for_utf8mb4=utf8mb4_general_ci'           => ['default_collation_for_utf8mb4', 'utf8mb4_general_ci'],
        'character_set_collations=utf8mb4=utf8mb4_unicode_ci'        => ['character_set_collations', 'utf8mb4=utf8mb4_unicode_ci'],
        // the same two statements the initconnect-* variants start with, set at runtime
        'init_connect=SET NAMES latin1'                              => ['init_connect', 'SET NAMES latin1'],
        'init_connect=SET NAMES gbk'                                 => ['init_connect', 'SET NAMES gbk'],
        'init_connect=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'  => ['init_connect', 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'],
        'init_connect=SET collation_connection=utf8mb4_bin'          => ['init_connect', 'SET collation_connection = utf8mb4_bin'],
        'old_mode empty'                                             => ['old_mode', ''],
        'sql_mode=NO_BACKSLASH_ESCAPES'                              => ['sql_mode', 'NO_BACKSLASH_ESCAPES'],
        'sql_mode=ANSI_QUOTES'                                       => ['sql_mode', 'ANSI_QUOTES'],
    ];
    foreach ($phases as $phase => [$variable, $value]) {
        section("global $phase", true, function () use ($phase, $variable, $value, $user, $database) {
            $root = rootConnect();
            try {
                $original = fetchValues($root, "SELECT @@global.$variable")[0];
                $root->query("SET GLOBAL $variable = '" . $root->real_escape_string($value) . "'");
            } catch (mysqli_sql_exception $e) {
                fact("global $phase: SET GLOBAL", 'not available: error ' . $e->getCode() . ': ' . $e->getMessage());
                return;
            }
            fact("global $phase: SET GLOBAL", 'ok');
            try {
                $phaseBaseline = firstStatementQuestions($user, $database);
                connectFacts("global $phase: connect as $user", [], $phaseBaseline);
                if ($variable === 'sql_mode') {
                    connectFacts("global $phase: connect as $user with sqlMode ''", ['sqlMode' => ''], $phaseBaseline);
                } else {
                    collationFacts("global $phase: C12");
                }
            } finally {
                $root->query("SET GLOBAL $variable = '" . $root->real_escape_string($original) . "'");
            }
        });
    }
}

//endregion
//region Merge

/**
 * Facts that go in the report, in order: section title => list of facts. Each fact is
 * [name as the probe records it, options]. Options: 'scopes' is a regex the variant
 * (or "dyn + setting") must match, 'reduce' shortens a value before servers are
 * grouped. The `dyn + old_mode empty` setting changed nothing, so it is only shown
 * where 'scopes' asks for it. Facts the probe records that aren't listed, and the
 * latin1 half of the pooled facts that the gbk half repeats, stay in the JSON files.
 */
function reportFacts(): array
{
    // "connected, PHP side ..., session ..." → "connected"; the session is reported by other facts
    $connectedOnly = fn(string $value): string => str_starts_with($value, 'connected, ') ? 'connected' : $value;
    // drop the collation name, which differs by server and is reported by other facts
    $noCollation = fn(string $value): string => preg_replace('~session utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_\w+~', 'session utf8mb4 on all three', $value);
    $tookEffect  = fn(string $value): string => preg_replace('~utf8mb4 / utf8mb4 / utf8mb4 / utf8mb4_\w+ / ~', 'utf8mb4 on all three / ', $value);
    // the steps of a traced connect, in short words
    $trace = fn(string $value): string => strtr($noCollation($value), [
        "connect's SET statement while PHP side is " => 'SET sent on ',
        'set_charset(utf8mb4) while PHP side is '    => 'set_charset() called on ',
        'ends with PHP side utf8mb4, session utf8mb4 on all three' => 'ends utf8mb4 on both sides',
        'ends with PHP side '                        => 'ends PHP side ',
    ]);
    $roundTrip = fn(string $value): string => preg_replace('~^sent (\w+), stored \1, read back \1: same bytes$~', '$1 stored and read back as sent', $value);

    $pooled = [['C04 pooled slot left on gbk, reopened with the charset option: same server thread', []]];
    foreach (['latin1', 'gbk'] as $charset) {
        $pooled[] = ["C04 pooled slot left on $charset, reopened with the charset option", []];
        $pooled[] = ["C04 pooled slot left on $charset, then a SET NAMES utf8mb4 query only", ['reduce' => $noCollation]];
        $pooled[] = ["C04 pooled slot left on $charset, closed after the SET NAMES query and reopened", []];
        $pooled[] = ["C04 pooled slot left on $charset, then set_charset(utf8mb4) only", ['reduce' => $noCollation]];
        $pooled[] = ["C04 pooled slot left on $charset, new Connection(): statements sent by connect", []];
        $pooled[] = ["C04 pooled slot left on $charset, new Connection()", ['reduce' => $noCollation]];
    }
    $pooled[] = ['C04 pooled slot left clean after gbk, new Connection(): statements sent by connect', []];
    $pooled[] = ['C04 pooled slot after a SET NAMES gbk query, new Connection(): same server thread', []];
    $pooled[] = ['C04 pooled slot after a SET NAMES gbk query, new Connection(): statements sent by connect', []];
    $pooled[] = ['C04 general log: new Connection() on a pooled slot left on gbk, then one query', []];

    $config = [];
    foreach (['true', 'false'] as $usePhpTimezone) {
        foreach (['default', "''", 'ANSI_QUOTES,STRICT_ALL_TABLES'] as $sqlMode) {
            $config[] = ["C05 config usePhpTimezone=$usePhpTimezone sqlMode=$sqlMode: statements sent by connect", []];
            $config[] = ["C05 config usePhpTimezone=$usePhpTimezone sqlMode=$sqlMode: time_zone / sql_mode", ['scopes' => '/^default$/']];
        }
    }

    return [
        'Server variants' => [
            ['server: mysqld flags', ['absolute' => true]],
            ['server: started', ['absolute' => true]],
            ['server: global character_set_server / collation_server', ['absolute' => true]],
            ['SET GLOBAL', ['absolute' => true]],
        ],
        '1. The charset option before connect (plain mysqli)' => [
            ['C01 charset option: PHP character_set_name()', []],
            ['C01 charset option: escape fingerprint', []],
            ['C01 charset option: Questions on the first statement, minus the same on a connect without the option', []],
            ['C01 general log: plain connect with the charset option, then one query', []],
        ],
        '2. No charset option (plain mysqli)' => [
            ['C02 no option: PHP character_set_name()', []],
            ['C02 no option: session charsets', []],
            ['C02 no option: escape fingerprint', []],
            ['X5 no option, ini default_charset=ISO-8859-1: PHP character_set_name()', []],
        ],
        '3. Statements connect() sends' => [
            ['connect as zdb_plain: statements sent by connect', []],
            ['connect as root: statements sent by connect', []],
            ['C03 general log: new Connection(), then one query', []],
            ['C14 databaseAutoCreate (second real_connect), fresh connect: statements sent by connect', []],
            ['X4 disconnect() then connect(): statements sent by connect', []],
        ],
        '4. Pooled connections (p: hostname)' => $pooled,
        '5. The merged SET statement' => [
            ['C05 SET NAMES utf8mb4', ['reduce' => $tookEffect]],
            ["C05 SET NAMES utf8mb4, time_zone = '+05:30'", ['reduce' => $tookEffect]],
            ["C05 SET NAMES utf8mb4, sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE'", ['reduce' => $tookEffect]],
            ["C05 SET NAMES utf8mb4, time_zone = '+05:30', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE'", ['reduce' => $tookEffect]],
            ...$config,
        ],
        '6, 8, 9. The session after connect()' => [
            ['connect as zdb_plain: PHP character_set_name()', []],
            ['connect as zdb_plain: session charsets', ['scopes' => '/./']],
            ['connect as root: session charsets', ['scopes' => '/^initconnect/']],   // init_connect doesn't run for root; elsewhere root matches zdb_plain
            ['connect as zdb_plain: time_zone / sql_mode', []],
            ['connect as zdb_plain: escape fingerprint', []],
        ],
        '7. Login alone against SET NAMES (plain mysqli)' => [
            ['C07 login only with the charset option, user root: session charsets', []],
            ['C07 after SET NAMES utf8mb4, user root: session charsets', []],
            ['C07 no option then set_charset(utf8mb4): PHP side, session charsets', []],
        ],
        '10. Values through placeholders' => [
            ['C10 ? placeholder, 26 values: one row with the same bytes', []],
            ['C10 :name placeholder, 26 values: one row with the same bytes', []],
        ],
        '11. Text through a table' => [
            ['C11 table round trip, cafe with e-acute', ['reduce' => $roundTrip]],
            ['C11 table round trip, Greek capital omega', ['reduce' => $roundTrip]],
            ['C11 table round trip, 4-byte emoji', ['reduce' => $roundTrip]],
            ['C11 table round trip, invalid UTF-8 ff fe', ['reduce' => $roundTrip]],
            ['C11 table round trip, latin1 e-acute byte', ['reduce' => $roundTrip]],
        ],
        '12. Connection collation against new tables and databases' => [
            ['C12: CREATE TABLE ... DEFAULT CHARSET=utf8mb4 column collation', []],
            ['C12: connection, new table and new database collations', []],
            ['C12: CREATE TABLE with no charset clause column collation', []],
            ["C12: utf8mb4 column = 'a'", []],
            ['C12: utf8mb4 column = @user_variable', []],
            ['C12: utf8mb4 column = column made from a literal', []],
            ["C12: server-default column = 'a'", []],
            ['C12: server-default column = @user_variable', []],
            ['C12: server-default column = column made from a literal', []],
        ],
        '13. Connects that fail, and unusual settings' => [
            ['C13 sqlMode `NO_BACKSLASH_ESCAPES`', ['reduce' => $connectedOnly]],
            ['C13 sqlMode `STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES`', ['reduce' => $connectedOnly]],
            ['C13 sqlMode `BOGUS_MODE`', ['reduce' => $connectedOnly]],
            ["C13 sqlMode `A'B`", ['reduce' => $connectedOnly]],
            ['C13 sqlMode `ANSI_QUOTES`', ['reduce' => $connectedOnly]],
            ['C13 PHP time zone Pacific/Kiritimati', ['reduce' => $connectedOnly]],
            ['C13 PHP time zone Pacific/Chatham', ['reduce' => $connectedOnly]],
            ['connect as zdb_plain: result', ['scopes' => '/\+ sql_mode=/']],
            ["connect as zdb_plain with sqlMode '': result", []],
            ["connect as zdb_plain with sqlMode '': time_zone / sql_mode", []],
        ],
        '14. The PHP side when connect() sends its SET statement' => [
            ['C14 fresh connect', ['reduce' => $trace]],
            ['C14 fresh connect, wrapper that drops the charset option', ['reduce' => $trace]],
            ['C14 pooled slot left on gbk', ['reduce' => $trace]],
            ['C14 pooled slot left on gbk, wrapper that drops the charset option', ['reduce' => $trace]],
            ['C14 databaseAutoCreate (second real_connect), fresh connect', ['reduce' => $trace]],
            ['C14 databaseAutoCreate (second real_connect), pooled no-database slot left on gbk', ['reduce' => $trace]],
            ['C14 databaseAutoCreate: created database collation', []],
        ],
        'After connect: change_user(), select_db(), reconnect' => [
            ['X1 after mysqli change_user() on a connected Connection: PHP side, session charsets', []],
            ['X1 after mysqli change_user() on a connected Connection: time_zone and sql_mode', []],
            ['X2 after select_db() to a latin1 database', []],
            ['X4 disconnect() then connect(): PHP side and all three session charsets are utf8mb4', []],
        ],
    ];
}

/**
 * Split a fact key into [scope, fact, source]. The scope is the server variant, or
 * "dyn + global <setting>" for a fact recorded while a global variable was changed.
 * Returns null for the "[old vs new]" lines, which the report works out itself.
 */
function splitFactKey(string $key): ?array
{
    if (!preg_match('/^(.*?) \| (.*?)(?: \[(new|old|old vs new)\])?$/', $key, $match)) {
        return null;
    }
    [, $scope, $fact] = $match;
    $source = $match[3] ?? '';
    if ($source === 'old vs new') {
        return null;
    }
    if (str_starts_with($fact, 'global ') && str_contains($fact, ': ')) {
        [$phase, $fact] = explode(': ', $fact, 2);
        $scope          = "$scope + " . substr($phase, 7);
    }
    return [$scope, $fact, $source];
}

/**
 * Merge the JSON files written by --json into one markdown report. PHP versions are
 * folded into one answer per server when they agree, the baseline's answer and this
 * checkout's are shown as one value when they agree, and servers and variants that
 * gave the same answer share a line, so the report shows who differs.
 */
function mergeReports(array $paths): void
{
    $jobs      = [];   // server => PHP label => report
    $proxyJobs = [];   // ProxySQL version => report
    foreach ($paths as $path) {
        $report = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (isset($report['proxysql'], $report['facts'])) {
            $proxyJobs[$report['proxysql']] = $report;
        } elseif (isset($report['server'], $report['php'], $report['facts'])) {
            $jobs[$report['server']][$report['php']] = $report;
        } else {
            fwrite(STDERR, "Skipping $path: not a connect-charset-probe JSON file\n");
        }
    }
    uksort($jobs, static fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));
    uksort($proxyJobs, 'version_compare');

    echo "# Connect Charset Matrix\n\n";
    if (!$jobs) {
        echo "No probe data found.\n";
        return;
    }

    // One answer per server: PHP versions that agree fold together, the rest are listed
    $answers     = [];   // server => fact key => value
    $phpSplits   = [];   // fact key => server => "8.1 `a`, 8.2 `b`"
    $phpVersions = [];
    $baselines   = [];
    foreach ($jobs as $server => $reports) {
        ksort($reports);
        $keys = [];
        foreach ($reports as $php => $report) {
            $keys             += array_fill_keys(array_keys($report['facts']), true);
            $phpVersions[$php] = "$php ($report[phpVersion])";
            $baselines[(string)($report['baseline'] ?? '')] = true;
        }
        foreach (array_keys($keys) as $key) {
            $byPhp = array_map(static fn($report) => (string)($report['facts'][$key] ?? '(no data)'), $reports);
            if (count(array_unique($byPhp)) === 1) {
                $answers[$server][$key] = reset($byPhp);
            } else {
                $phpSplits[$key][$server] = implode(', ', array_map(static fn($php, $value) => "$php " . mdValue($value), array_keys($byPhp), $byPhp));
            }
        }
    }
    ksort($phpVersions);
    $servers  = array_keys($jobs);
    $baseline = implode(' and ', array_filter(array_keys($baselines), static fn($label) => $label !== ''));

    echo "What `Connection::openConnection()` sends to set the connection charset and what session it leaves, on every supported database server started with default settings and with charset settings that change what login does";
    echo $baseline !== '' ? ", compared with ZenDB $baseline. " : ". ";
    echo "Generated by the \"Connect Charset Matrix\" workflow (.github/workflows/connect-charset-matrix.yml); regenerate with:\n\n";
    echo "    gh workflow run connect-charset-matrix.yml   # wait for the run to finish, then:\n";
    echo "    gh run download --dir probes                 # pick the latest Connect Charset Matrix run\n";
    echo "    php .github/scripts/connect-charset-probe.php --merge probes/*/connect-charset-probe-*.json > docs/internal/connect-charset-matrix.md\n\n";
    echo "Each job starts the same image once per server variant (see \"Server variants\" below) and runs the probe with this checkout's `src/`";
    echo $baseline !== '' ? " and again with the `src/` of the `baseline` input ($baseline). Where both gave the same answer the value is shown once; where they differ the line reads \"$baseline `a`, new `b`\". " : ". ";
    echo "Sections marked \"plain mysqli\" have no ZenDB in them. ";
    echo "Below \"Server variants\", a variant or setting that not every server has is grouped among the servers that have it (\"all servers\" and \"MySQL/Percona 8.0+\" then mean those), ";
    echo "and \"every variant\" means every variant and `dyn` setting the fact was recorded for. ";
    echo "This file holds the facts that answer the questions the change raised; every job's full fact list is in its JSON artifact.\n\n";
    echo "Last generated: " . date('Y-m-d') . " from " . count($servers) . " servers on PHP " . implode(', ', $phpVersions) . ". ";
    echo $phpSplits
        ? count($phpSplits) . " facts differed by PHP version on some server; they are listed under \"Facts that differ by PHP version\" and left out of the groups below for that server. "
        : "Every fact was the same on every PHP version on each server, so PHP versions aren't listed below. ";
    echo "Generated file - don't hand-edit. The \"Findings\" list below is maintained in the heredoc in .github/scripts/connect-charset-probe.php; edit it there.\n\n";
    echo <<<'__FINDINGS__'
        ## Findings

        "1.0.0" is ZenDB 1.0.0 (the `v1.0.0` tag), which sent `set_charset('utf8mb4')` only on MySQL/Percona 8.0+ or when a pooled connection came back on another charset. "New" sends `SET NAMES utf8mb4` on every connect, in the same statement as `time_zone` and `sql_mode`.

        - On a server with default settings, the session after the new connect is the same as after 1.0.0 on every server and PHP version: the three session charsets, `collation_connection`, `time_zone`, `sql_mode`, the PHP-side charset, and the bytes `real_escape_string()` returns. Only the number of statements differs
        - Statements sent by connect: new sends 1 on every server. 1.0.0 sent 2 on MySQL/Percona 8.0+ and 1 on MySQL/Percona 5.7 and MariaDB, or 0 there with `usePhpTimezone` off and `sqlMode` `''`. `databaseAutoCreate` adds 2 when it creates the database. A pooled connection that comes back on another charset costs 1 more (`set_charset()`)
        - The charset option set before connect puts the PHP side on utf8mb4 and sends no statement. The server's general log shows `Connect` and then the first query
        - Login sends a collation number, not a charset name, and PHP's number for utf8mb4 is `utf8mb4_general_ci`. MySQL/Percona 5.7 and MariaDB thru 10.11 use that as their default anyway, and MariaDB 11.4+ turns it into `utf8mb4_uca1400_ai_ci`, so login alone and `SET NAMES utf8mb4` leave the same session there. MySQL/Percona 8.0+ keep `utf8mb4_general_ci` after login and switch to `utf8mb4_0900_ai_ci` on `SET NAMES`
        - After the new connect, the connection collation is the collation a new `DEFAULT CHARSET=utf8mb4` table and a new `CHARACTER SET utf8mb4` database get, on every server and variant, including with `default_collation_for_utf8mb4` and `character_set_collations` changed
        - Servers started with `skip-character-set-client-handshake` ignore the charset sent at login. 1.0.0 left the session on the server's charset there (latin1, utf8mb3, gbk) on MySQL/Percona 5.7 and every MariaDB, while the PHP side was utf8mb4; text sent as utf8mb4 was stored as different bytes on the latin1 and gbk servers, and a 4-byte character was refused with error 1366 on the utf8mb3 server. New leaves all three session charsets on utf8mb4 and stores the same bytes. MySQL/Percona 8.0 was already utf8mb4 with 1.0.0
        - MySQL/Percona 8.4+ don't have that setting: `--skip-character-set-client-handshake` and `--character-set-client-handshake=0` are refused at startup as unknown
        - `init_connect` runs after login for users without SUPER or CONNECTION_ADMIN. With `init_connect='SET NAMES latin1'` (or gbk), 1.0.0 stayed on that charset on MySQL/Percona 5.7 and every MariaDB. New runs after it and ends on utf8mb4 on every server: the general log shows `Connect`, the `init_connect` statement, then the connect statement
        - `SET NAMES utf8mb4` also replaces a connection collation the server config chose. On MySQL/Percona 5.7 and every MariaDB with `skip-character-set-client-handshake` plus `collation-server=utf8mb4_unicode_ci`, or with `init_connect` setting a collation, 1.0.0 kept that collation and new ends on the utf8mb4 default (as MySQL/Percona 8.0 already did with 1.0.0). Comparing a column with a user variable, or with a column made from a literal, then changes in both directions: against a table in the server's own collation it worked with 1.0.0 and is now error 1267 "Illegal mix of collations"; against a `DEFAULT CHARSET=utf8mb4` table it was error 1267 with 1.0.0 and now works. MariaDB 11.8+ accepts the user-variable comparison either way. Comparing a column with a string literal works on every server, with 1.0.0 and with new
        - With a normal login on a server whose `collation-server` is not the utf8mb4 default (`utf8mb4_unicode_ci`, or one numbered over 255), 1.0.0 and new are both on the utf8mb4 default collation, and a table in the server's own collation gives error 1267 against a user variable or a column made from a literal with both (MariaDB 11.8+ accepts the user variable)
        - Without the charset option, PHP starts on the charset of the collation number in the server's greeting, which has one byte. On a utf8mb4 server whose collation number is over 255 (`utf8mb4_pl_0900_as_cs` is 284, MySQL/Percona 8.0+), a client that sets no charset starts on gbk, on both sides: 284 leaves 28 in one byte, and 28 is `gbk_chinese_ci`. ZenDB always sets the option, so its connections are utf8mb4 there
        - Pooled connections: a slot comes back on the charset the last script gave it with `set_charset()`, and the charset option is not applied on reuse. The server side comes back on that charset too, except on a `skip-character-set-client-handshake` server, where it is on the server's charset. A `SET NAMES` query alone fixes only the server side; `set_charset()` fixes both. Connect checks the PHP side and calls `set_charset()` when needed (2 statements), on every server and variant. A script that changed charset with a `SET NAMES` query leaves the PHP side as it was, so the slot comes back on its old PHP-side charset and connect sends 1 statement
        - The PHP side was utf8mb4 every time connect's SET statement ran: fresh connect, pooled slot left on gbk, `databaseAutoCreate`, `databaseAutoCreate` on a pooled slot left on gbk, and a wrapper that drops the charset option (connect then calls `set_charset()` first)
        - Every form of the merged statement is accepted and takes effect on every server, and every `usePhpTimezone` and `sqlMode` combination connects with 1 statement
        - Connects that fail give the same exception class and message as 1.0.0: `NO_BACKSLASH_ESCAPES` in `sqlMode` or in the server's global `sql_mode`, an unknown mode, and a quote in `sqlMode`
        - `mysqli::change_user()` on a connected Connection puts the server session back to its login state and nothing sets it again: `time_zone` and `sql_mode` are the server's, the collation is the login collation, and on a `skip-character-set-client-handshake` server the session is back on the server's charset while the PHP side stays utf8mb4. Same with 1.0.0
        - Behind ProxySQL 2.5.5, 2.7.3 and 3.0.12 (see the last section), the merged statement is parsed and does not lock the hostgroup; the only statement that did was the probe's own `SET @zdb_value = 'a'`. Sessions of two clients stay separate, and values and text come back as the same bytes
        - Behind ProxySQL the session collation is `utf8mb4_general_ci` on a MySQL 8.0 backend, with 1.0.0 and with new, so it differs from new utf8mb4 tables (`utf8mb4_0900_ai_ci`) and comparing those with a user variable is error 1267. ProxySQL reads `SET NAMES utf8mb4` itself and does not send it to the backend as written: the backend server's log shows the merged statement arriving as separate `SET sql_mode` and `SET time_zone` statements. On a new backend connection the log has no `SET NAMES` at all, and when ProxySQL has to change a backend connection that was on latin1 it sends `SET NAMES utf8mb4 COLLATE 'utf8mb4_general_ci'`. The collation number ProxySQL logs in with is not in that log
        - Not tested: MaxScale and other proxies, TiDB, Vitess, a wrapper that overrides `character_set_name()` or `set_charset()`, a server without time zone tables in CI, `COM_RESET_CONNECTION` (mysqli has no call for it), and PHP builds other than Linux x64


        __FINDINGS__;
    $versionOf = static fn(string $server): string => explode(' / ', $answers[$server]['default | server: version'] ?? '')[0];
    echo "Servers: " . implode(', ', array_map(static fn($server) => "$server (" . $versionOf($server) . ")", $servers)) . "\n\n";

    if ($phpSplits) {
        echo "## Facts that differ by PHP version\n\n";
        foreach ($phpSplits as $key => $byServer) {
            echo "### $key\n\n";
            foreach ($byServer as $server => $values) {
                echo "- $server: $values\n";
            }
            echo "\n";
        }
    }

    // fact => scope => source => server => value, and the servers each scope ran on
    $data         = [];
    $scopeServers = [];
    foreach ($answers as $server => $facts) {
        foreach ($facts as $key => $value) {
            $parts = splitFactKey($key);
            if ($parts === null) {
                continue;
            }
            [$scope, $fact, $source]               = $parts;
            $data[$fact][$scope][$source][$server] = $value;
            if (!in_array($fact, ['server: mysqld flags', 'server: started', 'SET GLOBAL'], true)) {
                $scopeServers[$scope][$server] = true;
            }
        }
    }

    foreach (reportFacts() as $title => $factList) {
        echo "## $title\n\n";
        if ($title === 'Server variants') {
            echo "Every job starts these from the same image, each on its own port. `dyn` is a default server on which the probe changes one global variable at a time; those appear below as `dyn + <setting>`.\n\n";
        }
        if (str_starts_with($title, '14.')) {
            echo "Each value lists what a traced connect did, in order, with the charset the PHP side was on at that moment: `set_charset()` if connect called it, connect's SET statement, and the state it ended in.\n\n";
        }
        foreach ($factList as [$fact, $factOptions]) {
            printFact($fact, $factOptions, $data[$fact] ?? [], $scopeServers, $servers, $baseline);
        }
    }

    if ($proxyJobs) {
        echo "## ProxySQL\n\n";
        $first = reset($proxyJobs);
        echo "The same connect through ProxySQL with a $first[server] backend ($first[backendVersion]), PHP $first[phpVersion]. ";
        echo "Versions: " . implode(', ', array_map(static fn($report) => "$report[proxysql] ($report[proxysqlVersion])", $proxyJobs)) . ". ";
        echo "Each line is the value → the ProxySQL versions that gave it.\n\n";
        $proxyData = [];   // fact => source => version => value
        foreach ($proxyJobs as $version => $report) {
            foreach ($report['facts'] as $key => $value) {
                $parts = splitFactKey($key);
                if ($parts !== null) {
                    $proxyData[$parts[1]][$parts[2]][$version] = (string)$value;
                }
            }
        }
        $versions = array_map('strval', array_keys($proxyJobs));
        $kept     = [
            'ProxySQL: mysql-default_charset / mysql-set_query_lock_on_hostgroup / mysql-multiplexing',
            'ProxySQL: connect',
            'ProxySQL: right after connect',
            'ProxySQL: what the backend server logged for the first connect and its session query',
            'ProxySQL: this connection after another client ran SET NAMES latin1 and statements on both',
            'ProxySQL: the other client after statements on both',
            'ProxySQL: second connect, when the one backend connection was last used on latin1',
            'ProxySQL: what the backend server logged for that second connect and its session query',
            'ProxySQL: second connect: PROBE FAILED',
            'ProxySQL: SET statements ProxySQL counted',
            'ProxySQL: SET statements it could not parse (locks the hostgroup)',
            'ProxySQL: ? placeholder, 12 values: one row with the same bytes',
            'ProxySQL: table round trip of cafe with e-acute and an emoji',
            'ProxySQL: C12: connection, new table and new database collations',
            'ProxySQL: C12: utf8mb4 column = \'a\'',
            'ProxySQL: C12: utf8mb4 column = @user_variable',
            'ProxySQL: C12: PROBE FAILED',
            'ProxySQL: PROBE FAILED',
        ];
        foreach ($kept as $fact) {
            $bySource = $proxyData[$fact] ?? null;
            if ($bySource === null) {
                continue;
            }
            $groups = [];
            foreach ($versions as $version) {
                $groups[displayValue($bySource, $version, null, $baseline)][] = $version;
            }
            echo "### " . preg_replace('/^ProxySQL: /', '', $fact) . "\n\n";
            foreach ($groups as $display => $group) {
                echo "- $display → " . (count($group) === count($versions) ? 'all versions' : implode(', ', $group)) . "\n";
            }
            echo "\n";
        }
    }
}

/**
 * One server's (or ProxySQL version's) answer for a fact, as markdown: the value once
 * when the baseline and this checkout agree, both when they differ.
 */
function displayValue(array $bySource, string $who, ?callable $reduce, string $baseline): string
{
    $reduce ??= static fn(string $value): string => $value;
    $new      = $bySource['new'][$who] ?? $bySource[''][$who] ?? null;
    $old      = $bySource['old'][$who] ?? null;
    $new      = $new === null ? null : $reduce($new);
    $old      = $old === null ? null : $reduce($old);
    return match (true) {
        $new === null && $old === null => '(no data)',
        $old !== null && $old !== $new => "$baseline " . mdValue($old) . ", new " . ($new === null ? '(no data)' : mdValue($new)),
        default                        => mdValue((string)$new),
    };
}

/**
 * Print one fact: servers grouped by answer, and variants that gave the same groups
 * listed together above them.
 */
function printFact(string $fact, array $factOptions, array $byScope, array $scopeServers, array $servers, string $baseline): void
{
    static $familiesBySet = [];   // server list => serverFamilies() for it
    $blocks = [];   // markdown lines => scopes
    foreach ($byScope as $scope => $bySource) {
        if (!preg_match($factOptions['scopes'] ?? '/^(?!dyn \+ old_mode)/', $scope)) {
            continue;
        }
        // the servers this variant ran on, plus any that answered this fact without it (start facts)
        $expected = array_keys(($scopeServers[$scope] ?? []) + array_fill_keys(array_keys(array_merge(...array_values($bySource))), true));
        $ranOn  = array_values(array_filter($servers, static fn($server) => in_array($server, $expected, true)));
        $groups = [];
        foreach ($ranOn as $server) {
            $groups[displayValue($bySource, $server, $factOptions['reduce'] ?? null, $baseline)][] = $server;
        }
        // Server groups are named among the servers the variant ran on, so a variant
        // that only starts on some servers folds with one that starts on all of them.
        // 'absolute' names them among all servers: the facts that say where a variant ran
        $among    = isset($factOptions['absolute']) ? $servers : $ranOn;
        $families = $familiesBySet[implode(',', $among)] ??= serverFamilies($among);
        $lines    = [];
        foreach ($groups as $display => $group) {
            $who     = count($group) === count($among) ? 'all servers' : (serverGroupLabel($group, $families) ?? implode(', ', $group));
            $lines[] = "- $display → $who";
        }
        $blocks[implode("\n", $lines)][] = $scope;
    }
    if (!$blocks) {
        return;
    }

    // A long list of variants that agree is written as "every variant" or "every other variant", last
    uasort($blocks, static fn($a, $b) => (count($a) > 5) <=> (count($b) > 5));
    $longLists = count(array_filter($blocks, static fn($scopes) => count($scopes) > 5));

    echo "### $fact\n\n";
    foreach ($blocks as $lines => $scopes) {
        if (count($scopes) > 5 && $longLists === 1) {
            echo count($blocks) > 1 ? "On every other variant:\n\n" : "On every variant:\n\n";
        } elseif (count($blocks) > 1 || $scopes !== ['default']) {
            echo "On " . implode(', ', array_map(static fn($scope) => "`$scope`", $scopes)) . ":\n\n";
        }
        echo "$lines\n\n";
    }
}

//endregion
