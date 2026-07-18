#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Print a markdown report of raw database server behaviors that ZenDB normalizes or
 * works around. Probes use plain mysqli, not ZenDB, so the library's own fixes can't
 * mask what the server actually returns.
 *
 *     php .github/scripts/db-behavior-probe.php                     # markdown to stdout
 *     php .github/scripts/db-behavior-probe.php --json=probe.json   # markdown to stdout, probe values to JSON
 *
 * The CI workflow (.github/workflows/db-behavior-matrix.yml) runs this against every database
 * image in the matrix and merges the JSON files with db-behavior-merge.php to show
 * which servers differ.
 *
 * Connects with the same DB_* env vars as the test suite (see phpunit.xml.dist).
 * DB_LABEL names the server in the JSON output, e.g. "mariadb:10.6" from the CI matrix.
 */

require __DIR__ . '/ci-lib.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$hostname = getenv('DB_HOSTNAME') ?: '127.0.0.1';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_DATABASE') ?: 'phpunit_test_db';
$label    = getenv('DB_LABEL') ?: 'unlabeled';
$jsonPath = getopt('', ['json:'])['json'] ?? null;

$probes = []; // probe name => single-line value; db-behavior-merge.php compares these across servers

// The JSON is written on every exit path, so a failure partway through still reports
// the probes collected up to that point instead of dropping the server from the merge
register_shutdown_function(function () use ($jsonPath, $label, &$probes) {
    if ($jsonPath === null) {
        return;
    }
    $json = json_encode(['server' => $label, 'probes' => $probes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($jsonPath, $json . "\n");
    fwrite(STDERR, "Wrote " . count($probes) . " probe values to $jsonPath\n"); // stderr keeps stdout pure markdown
});

try {
    $mysqli = new mysqli($hostname, $username, $password);
    $mysqli->set_charset('utf8mb4');
    $mysqli->query("CREATE DATABASE IF NOT EXISTS `$database`");
    $mysqli->select_db($database);
} catch (mysqli_sql_exception $e) {
    $probes['connection'] = 'failed: ' . $e->getMessage();
    echo "## $label\n\nConnection failed: " . $e->getMessage() . "\n";
    exit(1);
}

//
// Server identity and defaults
//
try {
    [$version, $versionComment, $sqlMode, $basedir, $datadir] = $mysqli->query("SELECT VERSION(), @@version_comment, @@GLOBAL.sql_mode, @@basedir, @@datadir")->fetch_row();

    // Server::version() reduces server_info to a version number in two steps; duplicated
    // here as literals so the report shows what ZenDB parses on each raw server
    $strippedServerInfo = preg_replace('/^5\.5\.5-(?=\d)/', '', $mysqli->server_info);
    preg_match('/^[\d.]+/', $strippedServerInfo, $versionMatch);

    $identityProbes = [
        'VERSION()'          => $version,
        '@@version_comment'  => $versionComment,
        'mysqli server_info' => $mysqli->server_info,
        // mysqlnd computes this int client-side from the handshake string (major*10000 + minor*100 + patch),
        // so it reports this runner's mysqlnd parse, not a server fact. PHP before 8.0.16/8.1.3 parsed
        // MariaDB's "5.5.5-" prefix as 50505 (php-src GH-7972)
        'mysqli server_version' => (string)$mysqli->server_version,
        'server_info after Server::version() parse' => rtrim($versionMatch[0] ?? '', '.'),
        // CMS Builder fingerprints Amazon RDS by basedir/datadir path prefixes
        '@@basedir'          => $basedir,
        '@@datadir'          => $datadir,
        '@@GLOBAL.sql_mode'  => $sqlMode,
        // databaseAutoCreate hardcodes this collation in CREATE DATABASE
        'utf8mb4_unicode_ci collation' => $mysqli->query("SHOW COLLATION LIKE 'utf8mb4_unicode_ci'")->num_rows ? 'available' : 'missing',
    ];
} catch (mysqli_sql_exception $e) {
    $identityProbes = ['Server identity' => 'probe failed: ' . $e->getMessage()];
}
$probes += $identityProbes;

echo "## $label\n\n";
echo "### Server identity and defaults\n\n";
echo mdTable($identityProbes);

//
// TLS / SSL - what each server reports about encryption. Two separate questions:
// "can this server do TLS?" (@@have_ssl, @@tls_version) and "is THIS connection
// encrypted?" (session Ssl_cipher/Ssl_version, empty when plaintext - CI connects
// without TLS, so real servers with TLS in use will show values here).
// Each variable probed separately: they come and go by version (MySQL 8.4 removed
// have_ssl/have_openssl; require_secure_transport arrived in 5.7.8/MariaDB 10.5).
//
$sslProbes  = [];
$sslQueries = [
    '@@have_ssl'                 => "SELECT @@have_ssl",                  // YES = TLS available, DISABLED = built without/off
    '@@have_openssl'             => "SELECT @@have_openssl",              // alias of have_ssl
    '@@tls_version'              => "SELECT @@tls_version",               // TLS protocol versions the server accepts
    '@@require_secure_transport' => "SELECT @@require_secure_transport",  // 1 = server rejects unencrypted connections
];
foreach ($sslQueries as $name => $query) {
    try {
        $sslProbes[$name] = (string)$mysqli->query($query)->fetch_row()[0];
    } catch (mysqli_sql_exception $e) {
        $sslProbes[$name] = 'error: ' . $e->getMessage();
    }
}
try {
    foreach ($mysqli->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version', 'Ssl_cipher')")->fetch_all() as [$name, $value]) {
        $sslProbes["session status $name"] = $value === '' ? '(empty = this connection is not encrypted)' : $value;
    }
} catch (mysqli_sql_exception $e) {
    $sslProbes['session status Ssl_*'] = 'error: ' . $e->getMessage();
}
$probes += $sslProbes;

echo "### TLS / SSL\n\n";
echo mdTable($sslProbes);

//
// SHOW CREATE TABLE - raw output for a fixture that hits every getColumnDefinitions()
// normalization: display widths, tinyint(1) variants, year, column-level charset,
// timestamp default spelling, and a column comment
//
$fixtureSql = <<<__SQL__
    CREATE TABLE zdb_probe (
        num         INT NOT NULL AUTO_INCREMENT,
        isAdmin     TINYINT(1) NOT NULL DEFAULT 0,
        flags       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        counter     INT NOT NULL DEFAULT 0,
        price       DECIMAL(10,2) NOT NULL DEFAULT 1.50,
        birthYear   YEAR NOT NULL,
        title       VARCHAR(255) NOT NULL DEFAULT '',
        legacyText  VARCHAR(100) CHARACTER SET latin1 NOT NULL DEFAULT '',
        createdDate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        createdMs   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        updatedDate DATETIME NULL DEFAULT NULL COMMENT 'it''s optional',
        negDefault  INT NOT NULL DEFAULT -1,
        ratio       DOUBLE NOT NULL DEFAULT 0.00001,
        dtLiteral   DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
        bitDefault  BIT(4) NOT NULL DEFAULT b'101',
        numText     VARCHAR(10) NOT NULL DEFAULT 123,
        keywordText VARCHAR(50) NOT NULL DEFAULT 'save DEFAULT 5 each' COMMENT 'uses CHARACTER SET utf8mb4',
        PRIMARY KEY (num)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    __SQL__;

try {
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe");
    $mysqli->query($fixtureSql);
    $createTable = $mysqli->query("SHOW CREATE TABLE zdb_probe")->fetch_row()[1];
    $mysqli->query("DROP TABLE zdb_probe");
} catch (mysqli_sql_exception $e) {
    // The parse below matches no columns in this string, so the failure lands in the
    // 'column parse failed' probe and the raw message still reaches the report
    $createTable = 'CREATE TABLE rejected: ' . $e->getMessage();
}

// One probe per column so the merge report pinpoints which definitions differ; a
// failed parse becomes a probe value itself, so it reads as a loud difference in the
// comparison instead of a silent gap
$fixtureColumns = parseColumnDefinitions($createTable);
$createProbes   = [];
foreach ($fixtureColumns as $column => $definition) {
    $createProbes["SHOW CREATE: $column"] = $definition;
}
if (!$fixtureColumns) {
    $createProbes['SHOW CREATE: column parse failed'] = trim($createTable);
}
if (preg_match('/^\).*/m', $createTable, $m)) {
    $createProbes['SHOW CREATE: table options'] = $m[0];
}

// Syntax some servers reject is probed one table each, so a rejection (itself a probe
// result) doesn't cost us the main fixture
$createProbes += [
    'SHOW CREATE: oldText VARCHAR(50) CHARSET utf8'  => probeColumnDefinition($mysqli, 'oldText', "CREATE TABLE zdb_probe_special (oldText VARCHAR(50) CHARACTER SET utf8 NOT NULL DEFAULT '')"),
    'SHOW CREATE: code VARCHAR(36) DEFAULT (uuid())' => probeColumnDefinition($mysqli, 'code', "CREATE TABLE zdb_probe_special (code VARCHAR(36) NOT NULL DEFAULT (uuid()))"),
];
$probes += $createProbes;

echo "### SHOW CREATE TABLE\n\n";
echo "Sent:\n\n```sql\n$fixtureSql\n```\n\n";
echo "Server returned:\n\n```sql\n$createTable\n```\n\n";
echo "Parsed per column (the values compared across servers):\n\n";
echo mdTable($createProbes);

//
// ZEROFILL - definition round-trip, and how values come back over the text vs prepared
// (binary) protocols. ZenDB runs everything as prepared statements, so a protocol
// difference in padding is what real result sets would show
//
try {
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (padded INT(6) ZEROFILL NOT NULL DEFAULT 0)");
    $mysqli->query("INSERT INTO zdb_probe_special VALUES (42)");

    $createSpecial = $mysqli->query("SHOW CREATE TABLE zdb_probe_special")->fetch_row()[1];

    $textResult = $mysqli->query("SELECT padded FROM zdb_probe_special");
    $textValue  = $textResult->fetch_row()[0];
    $flags      = $textResult->fetch_fields()[0]->flags;

    $stmt = $mysqli->prepare("SELECT padded FROM zdb_probe_special");
    $stmt->execute();
    $preparedValue = (string)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    $mysqli->query("DROP TABLE zdb_probe_special");

    $zerofillProbes = [
        'SHOW CREATE: padded INT(6) ZEROFILL'         => parseColumnDefinitions($createSpecial)['padded'] ?? trim($createSpecial),
        'ZEROFILL: SELECT 42 via text protocol'       => $textValue,
        'ZEROFILL: SELECT 42 via prepared statement'  => $preparedValue,
        'ZEROFILL: field ZEROFILL flag'               => ($flags & MYSQLI_ZEROFILL_FLAG) ? 'set' : 'not set',
    ];
} catch (mysqli_sql_exception $e) {
    $zerofillProbes = ['SHOW CREATE: padded INT(6) ZEROFILL' => 'CREATE TABLE rejected: ' . $e->getMessage()];
}
$probes += $zerofillProbes;

echo "### ZEROFILL\n\n";
echo mdTable($zerofillProbes);

//
// Generated columns - MySQL wraps the expression in an extra layer of parens, and
// older MariaDB respells STORED as PERSISTENT
//
try {
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (num INT NOT NULL, doubled INT GENERATED ALWAYS AS (num * 2) VIRTUAL, tripled INT GENERATED ALWAYS AS (num * 3) STORED)");
    $mysqli->query("INSERT INTO zdb_probe_special (num) VALUES (21)");

    $createSpecial    = $mysqli->query("SHOW CREATE TABLE zdb_probe_special")->fetch_row()[1];
    $generatedColumns = parseColumnDefinitions($createSpecial);
    $row = $mysqli->query("SELECT doubled, tripled FROM zdb_probe_special")->fetch_row();
    $mysqli->query("DROP TABLE zdb_probe_special");

    $generatedProbes = [
        'GENERATED: doubled INT AS (num * 2) VIRTUAL' => $generatedColumns['doubled'] ?? trim($createSpecial),
        'GENERATED: tripled INT AS (num * 3) STORED'  => $generatedColumns['tripled'] ?? trim($createSpecial),
        'GENERATED: SELECT with num = 21'             => "doubled=$row[0], tripled=$row[1]",
    ];
} catch (mysqli_sql_exception $e) {
    $generatedProbes = ['GENERATED: doubled INT AS (num * 2) VIRTUAL' => 'CREATE TABLE rejected: ' . $e->getMessage()];
}
$probes += $generatedProbes;

echo "### Generated columns\n\n";
echo mdTable($generatedProbes);

//
// CHECK constraints - MySQL 5.7 parses CHECK and silently drops it, MySQL 8.0.16+ and
// MariaDB 10.2+ store and enforce it, each with its own SHOW CREATE spelling. The
// INSERT probes show enforcement, the SHOW CREATE probe shows what survived
//
try {
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (age INT NOT NULL CHECK (age >= 0), CONSTRAINT zdb_age_max CHECK (age <= 150))");

    $createSpecial = $mysqli->query("SHOW CREATE TABLE zdb_probe_special")->fetch_row()[1];
    $checkLines    = array_map(fn($line) => rtrim(trim($line), ','), preg_grep('/CHECK/i', explode("\n", $createSpecial)));

    $checkProbes = [
        'CHECK: SHOW CREATE clauses' => $checkLines ? implode('; ', $checkLines) : 'none (CHECK dropped)',
    ];
    foreach (['INSERT -5 (violates column CHECK)' => -5, 'INSERT 200 (violates named constraint)' => 200] as $name => $badValue) {
        try {
            $mysqli->query("INSERT INTO zdb_probe_special VALUES ($badValue)");
            $checkProbes["CHECK: $name"] = 'accepted (not enforced)';
        } catch (mysqli_sql_exception $e) {
            $checkProbes["CHECK: $name"] = 'rejected (enforced, error ' . $e->getCode() . ')';
        }
    }
    $mysqli->query("DROP TABLE zdb_probe_special");
} catch (mysqli_sql_exception $e) {
    $checkProbes = ['CHECK: SHOW CREATE clauses' => 'CREATE TABLE rejected: ' . $e->getMessage()];
}
$probes += $checkProbes;

echo "### CHECK constraints\n\n";
echo mdTable($checkProbes);

//
// CHECK lifecycle - every way to add, alter, drop, rename around, and list CHECK
// constraints, for a future schema-editor feature (the display question: column-attached
// vs table-level). Each probe starts from a fresh table so a failure can't cascade, and
// records acceptance plus the CHECK clauses SHOW CREATE prints afterward, so
// add/replace/accumulate/drop semantics and auto-naming are all visible per server
//
$freshCheckTable = function (string $columnsSql) use ($mysqli): void {
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_check");
    $mysqli->query("CREATE TABLE zdb_probe_check ($columnsSql)");
};
$checkClauses = function () use ($mysqli): string {
    $createTable = $mysqli->query("SHOW CREATE TABLE zdb_probe_check")->fetch_row()[1];
    // \bCHECK \( skips the CREATE TABLE line, whose table name also contains "check"
    $lines       = array_map(fn($line) => rtrim(trim($line), ','), preg_grep('/\bCHECK \(/i', explode("\n", $createTable)));
    return $lines ? implode('; ', $lines) : 'no CHECK clauses';
};
$alterAndReport = function (string $alterSql) use ($mysqli, $checkClauses): string {
    try {
        $mysqli->query($alterSql);
        return 'accepted → ' . $checkClauses();
    } catch (mysqli_sql_exception $e) {
        return "rejected (error {$e->getCode()}): {$e->getMessage()}";
    }
};
$createAndReport = function (string $columnsSql) use ($freshCheckTable, $checkClauses): string {
    try {
        $freshCheckTable($columnsSql);
        return 'accepted → ' . $checkClauses();
    } catch (mysqli_sql_exception $e) {
        return "rejected (error {$e->getCode()}): {$e->getMessage()}";
    }
};

$plainColumns    = "age INT NOT NULL, size INT NOT NULL";
$columnCheck     = "age INT NOT NULL CHECK (age >= 0), size INT NOT NULL";
$namedTableCheck = "age INT NOT NULL, size INT NOT NULL, CONSTRAINT zdb_age_max CHECK (age <= 150)";
$checkOpsProbes  = [];

try {
    // ways to create - inline column CHECK and named table CHECK are probed above
    $checkOpsProbes['CHECK add: CREATE with unnamed table CHECK (auto-name)'] = $createAndReport("age INT NOT NULL, CHECK (age >= 0)");
    $checkOpsProbes['CHECK add: CREATE with named column CHECK']              = $createAndReport("age INT NOT NULL CONSTRAINT zdb_age_min CHECK (age >= 0)");

    // ways to add to an existing table
    $freshCheckTable($plainColumns);
    $checkOpsProbes['CHECK add: ALTER ADD CONSTRAINT named'] = $alterAndReport("ALTER TABLE zdb_probe_check ADD CONSTRAINT zdb_age_max CHECK (age <= 150)");
    $freshCheckTable($plainColumns);
    $checkOpsProbes['CHECK add: ALTER ADD CHECK unnamed'] = $alterAndReport("ALTER TABLE zdb_probe_check ADD CHECK (age >= 0)");
    $freshCheckTable($plainColumns);
    $checkOpsProbes['CHECK add: MODIFY COLUMN with inline CHECK'] = $alterAndReport("ALTER TABLE zdb_probe_check MODIFY age INT NOT NULL CHECK (age >= 0)");
    // deliberately continues on the table above: does a second inline CHECK replace the first or pile up?
    $checkOpsProbes['CHECK add: second MODIFY with a different inline CHECK'] = $alterAndReport("ALTER TABLE zdb_probe_check MODIFY age INT NOT NULL CHECK (age >= 1)");

    // what MODIFY COLUMN does to existing CHECKs it doesn't restate
    $freshCheckTable($columnCheck);
    $checkOpsProbes['CHECK modify: MODIFY COLUMN without restating its column CHECK'] = $alterAndReport("ALTER TABLE zdb_probe_check MODIFY age INT NOT NULL");
    $freshCheckTable($namedTableCheck);
    $checkOpsProbes['CHECK modify: MODIFY COLUMN with a table CHECK present'] = $alterAndReport("ALTER TABLE zdb_probe_check MODIFY age INT NOT NULL");

    // enforcement toggles - no server can change an expression in place (drop + re-add)
    $freshCheckTable($namedTableCheck);
    $checkOpsProbes['CHECK alter: ALTER CHECK name NOT ENFORCED'] = $alterAndReport("ALTER TABLE zdb_probe_check ALTER CHECK zdb_age_max NOT ENFORCED");
    $freshCheckTable($namedTableCheck);
    $checkOpsProbes['CHECK alter: ALTER CONSTRAINT name NOT ENFORCED'] = $alterAndReport("ALTER TABLE zdb_probe_check ALTER CONSTRAINT zdb_age_max NOT ENFORCED");

    // ways to drop
    $freshCheckTable($namedTableCheck);
    $checkOpsProbes['CHECK drop: DROP CONSTRAINT name'] = $alterAndReport("ALTER TABLE zdb_probe_check DROP CONSTRAINT zdb_age_max");
    $freshCheckTable($namedTableCheck);
    $checkOpsProbes['CHECK drop: DROP CHECK name'] = $alterAndReport("ALTER TABLE zdb_probe_check DROP CHECK zdb_age_max");

    // the auto-name a column CHECK gets, and whether DROP CONSTRAINT accepts it
    $freshCheckTable($columnCheck);
    try {
        $autoName = $mysqli->query("SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()")->fetch_row()[0] ?? null;
        $checkOpsProbes['CHECK drop: column CHECK by its auto-name'] = $autoName === null
            ? 'no I_S.CHECK_CONSTRAINTS row to name it'
            : "I_S names it '$autoName'; DROP CONSTRAINT: " . $alterAndReport("ALTER TABLE zdb_probe_check DROP CONSTRAINT `$autoName`");
    } catch (mysqli_sql_exception $e) {
        $checkOpsProbes['CHECK drop: column CHECK by its auto-name'] = "I_S lookup failed (error {$e->getCode()}): {$e->getMessage()}";
    }

    // column operations around CHECKs - the schema-editor cases (rename or drop a field)
    $freshCheckTable($columnCheck);
    $checkOpsProbes['CHECK column: DROP COLUMN with its own column CHECK'] = $alterAndReport("ALTER TABLE zdb_probe_check DROP COLUMN age");
    $freshCheckTable($namedTableCheck);
    $checkOpsProbes['CHECK column: DROP COLUMN referenced by a table CHECK'] = $alterAndReport("ALTER TABLE zdb_probe_check DROP COLUMN age");
    $freshCheckTable($columnCheck);
    $checkOpsProbes['CHECK column: CHANGE rename with column CHECK restated under the new name'] = $alterAndReport("ALTER TABLE zdb_probe_check CHANGE age age2 INT NOT NULL CHECK (age2 >= 0)");
    $freshCheckTable($namedTableCheck);
    $checkOpsProbes['CHECK column: CHANGE rename of a column referenced by a table CHECK'] = $alterAndReport("ALTER TABLE zdb_probe_check CHANGE age age2 INT NOT NULL");

    // ways to list - what each metadata source reports for one column CHECK + one named table CHECK
    $freshCheckTable("age INT NOT NULL CHECK (age >= 0), size INT NOT NULL, CONSTRAINT zdb_age_max CHECK (age <= 150)");
    try {
        $fields = $mysqli->query("SELECT * FROM information_schema.CHECK_CONSTRAINTS LIMIT 0")->fetch_fields();
        $checkOpsProbes['CHECK list: I_S.CHECK_CONSTRAINTS columns'] = implode(', ', array_column($fields, 'name'));
        $rows = $mysqli->query("SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY CONSTRAINT_NAME")->fetch_all();
        $checkOpsProbes['CHECK list: I_S.CHECK_CONSTRAINTS rows'] = $rows ? implode('; ', array_map(fn($row) => "$row[0]: $row[1]", $rows)) : 'no rows';
    } catch (mysqli_sql_exception $e) {
        $checkOpsProbes['CHECK list: I_S.CHECK_CONSTRAINTS rows'] = "failed (error {$e->getCode()}): {$e->getMessage()}";
    }
    try {
        $rows = $mysqli->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zdb_probe_check' AND CONSTRAINT_TYPE = 'CHECK' ORDER BY CONSTRAINT_NAME")->fetch_all();
        $checkOpsProbes['CHECK list: I_S.TABLE_CONSTRAINTS type=CHECK rows'] = $rows ? implode(', ', array_column($rows, 0)) : 'no rows';
    } catch (mysqli_sql_exception $e) {
        $checkOpsProbes['CHECK list: I_S.TABLE_CONSTRAINTS type=CHECK rows'] = "failed (error {$e->getCode()}): {$e->getMessage()}";
    }

    $mysqli->query("DROP TABLE zdb_probe_check");
} catch (mysqli_sql_exception $e) {
    $checkOpsProbes['CHECK lifecycle'] = 'probe failed: ' . $e->getMessage();
}
$probes += $checkOpsProbes;

echo "### CHECK lifecycle\n\n";
echo mdTable($checkOpsProbes);

//
// Encryption interop - ZenDB encrypts in PHP with aes-128-ecb and MySQL's XOR-folded
// SHA-512 key, then decrypts server-side with AES_DECRYPT(col, @ek). That only works
// if AES_ENCRYPT runs in aes-128-ecb mode and folds over-length keys the same way on
// every server, so the ciphertexts must match byte for byte
//
try {
    $blockMode = $mysqli->query("SELECT @@block_encryption_mode")->fetch_row()[0];
} catch (mysqli_sql_exception) {
    $blockMode = 'variable not supported';
}

try {
    $mysqli->query("SET @ek = UNHEX(SHA2('zendb probe key', 512))");
    $serverHex = $mysqli->query("SELECT HEX(AES_ENCRYPT('zendb probe', @ek))")->fetch_row()[0];

    $keyBytes = hash('sha512', 'zendb probe key', true);
    $aesKey   = substr($keyBytes, 0, 16) ^ substr($keyBytes, 16, 16) ^ substr($keyBytes, 32, 16) ^ substr($keyBytes, 48, 16);
    $phpHex   = strtoupper(bin2hex(openssl_encrypt('zendb probe', 'aes-128-ecb', $aesKey, OPENSSL_RAW_DATA)));

    $encryptionProbes = [
        '@@block_encryption_mode'                     => $blockMode,
        'HEX(AES_ENCRYPT) with SHA2-512 key'          => $serverHex,
        'AES_ENCRYPT matches PHP openssl aes-128-ecb' => $serverHex === $phpHex ? 'match' : "differs: PHP produced $phpHex",
    ];
} catch (mysqli_sql_exception $e) {
    $encryptionProbes = ['HEX(AES_ENCRYPT) with SHA2-512 key' => 'probe failed: ' . $e->getMessage()];
}
$probes += $encryptionProbes;

echo "### Encryption interop\n\n";
echo mdTable($encryptionProbes);

//
// Field metadata - what fetch_fields() reports. ZenDB detects encryptable MEDIUMBLOB
// columns by type=BLOB / charsetnr=63 / length=16777215, and SmartJoin key building
// reads orgtable/orgname for aliased, view, and expression columns
//
try {
    $mysqli->query("DROP VIEW IF EXISTS zdb_probe_view");
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (num INT NOT NULL, mb MEDIUMBLOB, b BLOB, mt MEDIUMTEXT, vb VARBINARY(16))");
    $mysqli->query("CREATE VIEW zdb_probe_view AS SELECT num FROM zdb_probe_special");

    $fieldProbes    = [];
    $result         = $mysqli->query("SELECT mb, b, mt, vb FROM zdb_probe_special WHERE 1 = 0");
    $columnTypeNames = ['mb' => 'MEDIUMBLOB', 'b' => 'BLOB', 'mt' => 'MEDIUMTEXT', 'vb' => 'VARBINARY(16)'];
    foreach ($result->fetch_fields() as $field) {
        $fieldProbes["Field metadata: {$columnTypeNames[$field->name]}"] = mysqliTypeName($field->type) . ", charsetnr=$field->charsetnr, length=$field->length";
    }

    // getEncryptedColumns() matches type=BLOB / charsetnr=63 / length=16777215; if
    // AES_DECRYPT output ever comes back with that exact signature, {{column}} results
    // would get a second PHP-side decrypt
    $mysqli->query("SET @ek = UNHEX(SHA2('zendb probe key', 512))");
    $decryptField = $mysqli->query("SELECT AES_DECRYPT(mb, @ek) AS decrypted FROM zdb_probe_special WHERE 1 = 0")->fetch_fields()[0];
    $fieldProbes['Field metadata: AES_DECRYPT(mb, @ek) expression'] = mysqliTypeName($decryptField->type) . ", charsetnr=$decryptField->charsetnr, length=$decryptField->length";
    $isEncryptableSignature = $decryptField->type === MYSQLI_TYPE_BLOB && $decryptField->charsetnr === 63 && $decryptField->length === 16777215;
    $fieldProbes['AES_DECRYPT matches encryptable MEDIUMBLOB signature'] = $isEncryptableSignature ? 'yes (would double-decrypt)' : 'no';

    $result = $mysqli->query("SELECT a.num AS aliased, v.num AS viewCol, a.num + 1 AS expr FROM zdb_probe_special a JOIN zdb_probe_view v ON 1 = 0");
    foreach ($result->fetch_fields() as $field) {
        $fieldProbes["Field metadata: $field->name column"] = "table=" . ($field->table ?: "''") . ", orgtable=" . ($field->orgtable ?: "''") . ", orgname=" . ($field->orgname ?: "''");
    }

    $mysqli->query("DROP VIEW zdb_probe_view");
    $mysqli->query("DROP TABLE zdb_probe_special");
} catch (mysqli_sql_exception $e) {
    $fieldProbes = ['Field metadata' => 'probe failed: ' . $e->getMessage()];
}
$probes += $fieldProbes;

echo "### Field metadata\n\n";
echo mdTable($fieldProbes);

//
// Temporary table visibility - which listing commands include temp tables, and what
// INFORMATION_SCHEMA reports for them. getTableNames() filters INFORMATION_SCHEMA on
// TABLE_TYPE = 'BASE TABLE' because SHOW TABLES LIKE is unreliable (MDEV-32973), so
// the INFORMATION_SCHEMA rows are the ones that keep that workaround honest
//
try {
    $mysqli->query("DROP VIEW IF EXISTS zdb_probe_view");
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_real");
    $mysqli->query("CREATE TABLE zdb_probe_real (num INT)");
    $mysqli->query("CREATE VIEW zdb_probe_view AS SELECT num FROM zdb_probe_real");
    $mysqli->query("CREATE TEMPORARY TABLE zdb_probe_temp (num INT)");

    $noMatchRows = array_column($mysqli->query("SHOW TABLES LIKE 'zdb_no_match%'")->fetch_all(), 0);
    $showTables  = array_column($mysqli->query("SHOW TABLES")->fetch_all(), 0);
    $fullTypes   = array_column($mysqli->query("SHOW FULL TABLES")->fetch_all(), 1, 0);
    $infoTypes   = array_column($mysqli->query("SELECT TABLE_NAME, TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('zdb_probe_temp', 'zdb_probe_real', 'zdb_probe_view')")->fetch_all(), 1, 0);

    $mysqli->query("DROP TEMPORARY TABLE zdb_probe_temp");
    $mysqli->query("DROP VIEW zdb_probe_view");
    $mysqli->query("DROP TABLE zdb_probe_real");

    $tempProbes = [
        "SHOW TABLES LIKE 'zdb_no_match%' with temp table present (MDEV-32973)" =>
            $noMatchRows ? 'pattern ignored, returned: ' . implode(', ', $noMatchRows) : 'pattern honored, returned 0 rows',
        'SHOW TABLES: temp table'                        => in_array('zdb_probe_temp', $showTables, true) ? 'listed' : 'not listed',
        'SHOW FULL TABLES: temp table Table_type'        => $fullTypes['zdb_probe_temp'] ?? 'not listed',
        'SHOW FULL TABLES: view Table_type'              => $fullTypes['zdb_probe_view'] ?? 'not listed',
        'INFORMATION_SCHEMA.TABLES: temp table TABLE_TYPE' => $infoTypes['zdb_probe_temp'] ?? 'not listed',
        'INFORMATION_SCHEMA.TABLES: real table TABLE_TYPE' => $infoTypes['zdb_probe_real'] ?? 'not listed',
        'INFORMATION_SCHEMA.TABLES: view TABLE_TYPE'       => $infoTypes['zdb_probe_view'] ?? 'not listed',
    ];
} catch (mysqli_sql_exception $e) {
    $tempProbes = ['Temporary table visibility' => 'probe failed: ' . $e->getMessage()];
}
$probes += $tempProbes;

echo "### Temporary table visibility\n\n";
echo mdTable($tempProbes);

//
// Numeric literals - what hex/binary/scientific literals evaluate to (context for
// assertSafeTemplate() rejecting them in query templates)
//
try {
    $result = $mysqli->query("SELECT 0x1AF, 0b1010, 1e10");
    $fields = $result->fetch_fields();
    $row    = $result->fetch_row();

    $literalProbes = [];
    foreach ($fields as $i => $field) {
        $literalProbes["SELECT $field->name"] = displayValue($row[$i]) . ' (' . mysqliTypeName($field->type) . ')';
    }
} catch (mysqli_sql_exception $e) {
    $literalProbes = ['Numeric literals' => 'probe failed: ' . $e->getMessage()];
}
$probes += $literalProbes;

echo "### Numeric literals\n\n";
echo mdTable($literalProbes);

//
// NULL in IN lists - why escapeCSV() rejects null values
//
try {
    $row = $mysqli->query("SELECT 1 IN (1, NULL), 2 IN (1, NULL), 2 NOT IN (1, NULL)")->fetch_row();

    $nullInProbes = [
        '1 IN (1, NULL)'        => displayValue($row[0]),
        '2 IN (1, NULL)'        => displayValue($row[1]),
        '2 NOT IN (1, NULL)'    => displayValue($row[2]),
        'SELECT NULL field type' => mysqliTypeName($mysqli->query("SELECT NULL")->fetch_fields()[0]->type),
    ];
} catch (mysqli_sql_exception $e) {
    $nullInProbes = ['NULL behavior' => 'probe failed: ' . $e->getMessage()];
}
$probes += $nullInProbes;

echo "### NULL behavior\n\n";
echo mdTable($nullInProbes);

//
// SSL / TLS detection - CMS Builder checks have_ssl, which MySQL 8.4 removed, so a
// missing row gets assumed as "YES". tls_version and require_secure_transport are the
// newer signals; this shows which servers offer which
//
try {
    $haveSsl       = $mysqli->query("SHOW VARIABLES WHERE Variable_name = 'have_ssl'")->fetch_row();
    $requireSecure = $mysqli->query("SHOW VARIABLES WHERE Variable_name = 'require_secure_transport'")->fetch_row();
    try {
        $tlsVersion = $mysqli->query("SELECT @@tls_version")->fetch_row()[0] ?? 'NULL';
    } catch (mysqli_sql_exception) {
        $tlsVersion = 'variable not supported';
    }

    $sslPairs = [];
    foreach ($mysqli->query("SHOW STATUS WHERE Variable_name IN ('Ssl_cipher', 'Ssl_version')")->fetch_all() as [$name, $value]) {
        $sslPairs[] = "$name=" . ($value === '' ? "''" : $value);
    }

    $sslProbes = [
        "SHOW VARIABLES 'have_ssl'"                             => $haveSsl ? $haveSsl[1] : 'no rows (variable removed)',
        '@@tls_version'                                         => $tlsVersion,
        "SHOW VARIABLES 'require_secure_transport'"             => $requireSecure ? $requireSecure[1] : 'no rows (variable not present)',
        'SHOW STATUS Ssl_cipher/Ssl_version (plain connection)' => $sslPairs ? implode(', ', $sslPairs) : 'no rows',
    ];
} catch (mysqli_sql_exception $e) {
    $sslProbes = ["SHOW VARIABLES 'have_ssl'" => 'probe failed: ' . $e->getMessage()];
}
$probes += $sslProbes;

echo "### SSL / TLS detection\n\n";
echo mdTable($sslProbes);

//
// Server logging - CMS Builder refuses to enable data encryption while the general
// query log is on, and its slow-log page queries variable names that each engine
// removed at a different point
//
try {
    [$generalLog, $logOutput] = $mysqli->query("SELECT @@GLOBAL.general_log, @@GLOBAL.log_output")->fetch_row();
    $loggingProbes = ['@@GLOBAL.general_log, @@GLOBAL.log_output' => "general_log=$generalLog, log_output=$logOutput"];
} catch (mysqli_sql_exception $e) {
    $loggingProbes = ['@@GLOBAL.general_log, @@GLOBAL.log_output' => 'query failed: ' . $e->getMessage()];
}
try {
    $slowNames = array_column($mysqli->query("SHOW VARIABLES WHERE Variable_name IN ('log_slow_queries', 'slow_query_log')")->fetch_all(), 0);
    $loggingProbes['slow-log variable names present'] = $slowNames ? implode(', ', $slowNames) : 'none';
} catch (mysqli_sql_exception $e) {
    $loggingProbes['slow-log variable names present'] = 'probe failed: ' . $e->getMessage();
}
$probes += $loggingProbes;

echo "### Server logging\n\n";
echo mdTable($loggingProbes);

//
// Bare TIMESTAMP defaults - with explicit_defaults_for_timestamp OFF (MySQL 5.7
// default, MariaDB before 10.10) the server silently adds DEFAULT CURRENT_TIMESTAMP
// ON UPDATE CURRENT_TIMESTAMP to the first bare TIMESTAMP column, so the same DDL
// produces different getColumnDefinitions() results per server
//
try {
    $explicitDefaults = $mysqli->query("SHOW VARIABLES WHERE Variable_name = 'explicit_defaults_for_timestamp'")->fetch_row();
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (ts TIMESTAMP)");
    $tsDefinition = parseColumnDefinitions($mysqli->query("SHOW CREATE TABLE zdb_probe_special")->fetch_row()[1])['ts'] ?? 'parse failed';
    $mysqli->query("DROP TABLE zdb_probe_special");

    $timestampProbes = [
        'explicit_defaults_for_timestamp'  => $explicitDefaults ? $explicitDefaults[1] : 'no rows (variable not present)',
        'SHOW CREATE: ts TIMESTAMP (bare)' => $tsDefinition,
    ];
} catch (mysqli_sql_exception $e) {
    $timestampProbes = ['SHOW CREATE: ts TIMESTAMP (bare)' => 'probe failed: ' . $e->getMessage()];
}
$probes += $timestampProbes;

echo "### Bare TIMESTAMP defaults\n\n";
echo mdTable($timestampProbes);

//
// Column defaults - every way a client can read a column default, against the cases
// that historically confused tools: nullable with no DEFAULT clause, explicit
// DEFAULT NULL, and a real string 'NULL'. MySQL/Percona report I_S COLUMN_DEFAULT as
// raw values with SQL NULL meaning "no default"; MariaDB 10.2.7+ reports DDL text
// instead (MDEV-13132): string literals come back quoted, and no-default comes back
// as the text NULL (MDEV-13341, closed as intended), with views disagreeing with
// base tables in some versions (MDEV-14053). Old tools misreading these wrote string
// 'NULL' defaults into live tables, which Backup::makeUniversalCreateTable() repairs
//
try {
    $mysqli->query("DROP VIEW IF EXISTS zdb_probe_view");
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (
        noDefault    VARCHAR(10),
        explicitNull VARCHAR(10) DEFAULT NULL,
        nullString   VARCHAR(10) DEFAULT 'NULL',
        strDefault   VARCHAR(10) DEFAULT 'abc',
        numDefault   INT DEFAULT 5,
        exprDefault  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $mysqli->query("CREATE VIEW zdb_probe_view AS SELECT * FROM zdb_probe_special");
    $mysqli->query("INSERT INTO zdb_probe_special (noDefault) VALUES ('x')"); // one row so SELECT DEFAULT(col) has something to run against

    // SQL NULL and the text NULL print identically, so tag them apart explicitly;
    // 'text: NULL' vs "text: 'NULL'" then shows quoting differences byte for byte
    $fmtDefault  = fn(?string $value): string => $value === null ? 'SQL NULL' : "text: $value";
    $columnNames = ['noDefault', 'explicitNull', 'nullString', 'strDefault', 'numDefault', 'exprDefault'];

    // INFORMATION_SCHEMA, base table and through a view (MDEV-14053 had them disagreeing)
    $defaultProbes = [];
    foreach (['zdb_probe_special' => 'I_S COLUMN_DEFAULT', 'zdb_probe_view' => 'I_S via view'] as $tableName => $probeName) {
        $defaults = array_column($mysqli->query("SELECT COLUMN_NAME, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableName'")->fetch_all(), 1, 0);
        foreach ($columnNames as $column) {
            $defaultProbes["DEFAULTS $probeName: $column"] = $fmtDefault($defaults[$column] ?? null);
        }
    }

    // SHOW COLUMNS, with SHOW FULL COLUMNS collapsed to a match check (expected identical)
    $showDefaults = array_column($mysqli->query("SHOW COLUMNS FROM zdb_probe_special")->fetch_all(MYSQLI_ASSOC), 'Default', 'Field');
    $fullDefaults = array_column($mysqli->query("SHOW FULL COLUMNS FROM zdb_probe_special")->fetch_all(MYSQLI_ASSOC), 'Default', 'Field');
    foreach ($columnNames as $column) {
        $defaultProbes["DEFAULTS SHOW COLUMNS: $column"] = $fmtDefault($showDefaults[$column] ?? null);
    }
    $defaultProbes['DEFAULTS SHOW FULL COLUMNS matches SHOW COLUMNS'] = $fullDefaults === $showDefaults ? 'identical' : 'differs: ' . json_encode($fullDefaults);

    // SHOW CREATE TABLE, the executable form ZenDB reads
    $createDefaults = parseColumnDefinitions($mysqli->query("SHOW CREATE TABLE zdb_probe_special")->fetch_row()[1]);
    foreach ($columnNames as $column) {
        $defaultProbes["DEFAULTS SHOW CREATE: $column"] = $createDefaults[$column] ?? 'parse failed';
    }

    // SELECT DEFAULT(col), the value form (errors are results: some servers reject it for some defaults)
    foreach ($columnNames as $column) {
        try {
            $value = $mysqli->query("SELECT DEFAULT($column) FROM zdb_probe_special")->fetch_row()[0];

            // MariaDB evaluates DEFAULT(exprDefault) to the probe's wall-clock time, which
            // would diff on every regeneration; the signal is that it evaluates at all, so
            // normalize real datetimes to a token. MySQL's zero-date answer stays literal.
            if (is_string($value) && preg_match('/^(?!0000)\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $value)) {
                $value = '<evaluated to current datetime>';
            }
            $defaultProbes["DEFAULTS SELECT DEFAULT($column)"] = $fmtDefault($value);
        } catch (mysqli_sql_exception $e) {
            $defaultProbes["DEFAULTS SELECT DEFAULT($column)"] = 'error ' . $e->getCode();
        }
    }

    $mysqli->query("DROP VIEW zdb_probe_view");
    $mysqli->query("DROP TABLE zdb_probe_special");
} catch (mysqli_sql_exception $e) {
    $defaultProbes = ['DEFAULTS probes' => 'probe failed: ' . $e->getMessage()];
}
$probes += $defaultProbes;

echo "### Column defaults\n\n";
echo mdTable($defaultProbes);

//
// Connection collation - set_charset('utf8mb4') leaves the collation at each server's
// utf8mb4 default (general_ci, 0900_ai_ci, or uca1400 depending on engine/version),
// while databaseAutoCreate hardcodes utf8mb4_unicode_ci, so mixed comparisons and
// dump portability depend on what these actually resolve to
//
try {
    [$collConnection, $charsetResults] = $mysqli->query("SELECT @@collation_connection, @@character_set_results")->fetch_row();

    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (implicit VARCHAR(10), unicodeCi VARCHAR(10) COLLATE utf8mb4_unicode_ci) DEFAULT CHARSET=utf8mb4");
    $implicitCollation = $mysqli->query("SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zdb_probe_special' AND COLUMN_NAME = 'implicit'")->fetch_row()[0];
    try {
        $mysqli->query("SELECT 1 FROM zdb_probe_special WHERE implicit = unicodeCi");
        $mixResult = 'allowed';
    } catch (mysqli_sql_exception $e) {
        $mixResult = 'error ' . $e->getCode();
    }
    $mysqli->query("DROP TABLE zdb_probe_special");

    $collationProbes = [
        '@@collation_connection after set_charset(utf8mb4)' => $collConnection,
        '@@character_set_results'                           => $charsetResults,
        'utf8mb4 table: implicit column collation'          => $implicitCollation,
        'compare implicit vs utf8mb4_unicode_ci column'     => $mixResult,
        'utf8mb4_0900_ai_ci collation'                      => $mysqli->query("SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'")->num_rows ? 'available' : 'missing',
    ];
} catch (mysqli_sql_exception $e) {
    $collationProbes = ['@@collation_connection after set_charset(utf8mb4)' => 'probe failed: ' . $e->getMessage()];
}
$probes += $collationProbes;

echo "### Connection collation\n\n";
echo mdTable($collationProbes);

//
// Error codes - databaseAutoCreate branches on code 1049, and the same failure can
// return different codes per engine (CHECK violations: MySQL 3819, MariaDB 4025);
// this records what each server returns for canonical failures
//
$errorProbes = [];
try {
    $mysqli->select_db('zdb_no_such_db');
    $errorProbes['error code: USE unknown database'] = 'no error';
} catch (mysqli_sql_exception $e) {
    $errorProbes['error code: USE unknown database'] = $e->getCode() . ' / SQLSTATE ' . $e->getSqlState();
}
try {
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (num INT NOT NULL PRIMARY KEY)");
    $mysqli->query("INSERT INTO zdb_probe_special VALUES (1)");
    foreach (['duplicate key' => "INSERT INTO zdb_probe_special VALUES (1)", 'unknown column' => "SELECT zdb_no_such_column FROM zdb_probe_special"] as $name => $sql) {
        try {
            $mysqli->query($sql);
            $errorProbes["error code: $name"] = 'no error';
        } catch (mysqli_sql_exception $e) {
            $errorProbes["error code: $name"] = $e->getCode() . ' / SQLSTATE ' . $e->getSqlState();
        }
    }
    $mysqli->query("DROP TABLE zdb_probe_special");
} catch (mysqli_sql_exception $e) {
    $errorProbes['error code probes'] = 'probe failed: ' . $e->getMessage();
}
$probes += $errorProbes;

echo "### Error codes\n\n";
echo mdTable($errorProbes);

//
// Partitioned tables - ZenDB extracts the table-options line with a /^\)/ match
// because partition clauses follow it; MySQL 5.7 wraps them in a /*!50100 version
// comment while MySQL 8 and MariaDB emit them bare
//
try {
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
    $mysqli->query("CREATE TABLE zdb_probe_special (num INT NOT NULL) PARTITION BY RANGE (num) (PARTITION p0 VALUES LESS THAN (10), PARTITION p1 VALUES LESS THAN MAXVALUE)");
    $createSpecial = $mysqli->query("SHOW CREATE TABLE zdb_probe_special")->fetch_row()[1];
    $mysqli->query("DROP TABLE zdb_probe_special");

    $partitionProbes = ['PARTITIONED: SHOW CREATE from table options on' => substr($createSpecial, (int)strpos($createSpecial, "\n)") + 1)];
} catch (mysqli_sql_exception $e) {
    $partitionProbes = ['PARTITIONED: SHOW CREATE from table options on' => 'CREATE TABLE rejected: ' . $e->getMessage()];
}
$probes += $partitionProbes;

echo "### Partitioned tables\n\n";
echo mdTable($partitionProbes);

//
// Result typing with MYSQLI_OPT_INT_AND_FLOAT_NATIVE - connect() enables the option,
// and fetchMappedRows() reads over the text protocol, so it decides whether apps get
// PHP ints/floats or strings; engines declare different types for some expressions.
// Runs on its own connection because the option must be set before connecting
//
try {
    $native = mysqli_init();
    $native->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
    $native->real_connect($hostname, $username, $password, $database);
    $native->set_charset('utf8mb4');

    $native->query("DROP TABLE IF EXISTS zdb_probe_native");
    $native->query("CREATE TABLE zdb_probe_native (num INT NOT NULL, price DECIMAL(10,2) NOT NULL, birthYear YEAR NOT NULL, bits BIT(8) NOT NULL)");
    $native->query("INSERT INTO zdb_probe_native VALUES (42, 1.50, 1999, b'101010')");

    $nativeProbes = [];
    foreach (["SELECT num, price, birthYear, bits FROM zdb_probe_native", "SELECT SUM(num), AVG(num), 1/2 FROM zdb_probe_native"] as $sql) {
        $result = $native->query($sql);
        $fields = $result->fetch_fields();
        $row    = $result->fetch_row();
        foreach ($fields as $i => $field) {
            $value = is_string($row[$i]) ? displayValue($row[$i]) : var_export($row[$i], true);
            $nativeProbes["INT_AND_FLOAT_NATIVE: $field->name"] = get_debug_type($row[$i]) . " $value (" . mysqliTypeName($field->type) . ')';
        }
    }
    $native->query("DROP TABLE zdb_probe_native");
    $native->close();
} catch (mysqli_sql_exception $e) {
    $nativeProbes = ['INT_AND_FLOAT_NATIVE probes' => 'probe failed: ' . $e->getMessage()];
}
$probes += $nativeProbes;

echo "### Result typing with INT_AND_FLOAT_NATIVE\n\n";
echo mdTable($nativeProbes);

//
// Time zone offsets - connect() runs SET time_zone = date('P') when usePhpTimezone
// is on, and servers differ in the offset range they accept (MySQL widened
// -12:59..+13:00 to -13:59..+14:00 in 8.0.19), so a PHP zone like +14:00 can make
// connect() throw on older servers. The only real PHP offsets past +13:00 are +14:00
// (Pacific/Kiritimati, Etc/GMT-14) and +13:45 (Pacific/Chatham in DST); a named zone
// is the only way to send those to a server that rejects the offset, but named zones
// need the mysql.time_zone tables loaded, so we probe both the names and whether the
// tables are populated
//
try {
    $originalTimeZone = $mysqli->query("SELECT @@SESSION.time_zone")->fetch_row()[0];
    $timeZoneProbes   = [];
    foreach (['+05:45', '+13:00', '-13:00', '+14:00'] as $offset) {
        try {
            $mysqli->query("SET time_zone = '$offset'");
            $timeZoneProbes["SET time_zone = '$offset'"] = 'accepted';
        } catch (mysqli_sql_exception $e) {
            $timeZoneProbes["SET time_zone = '$offset'"] = 'rejected: error ' . $e->getCode();
        }
    }
    foreach (['Etc/GMT-14', 'Pacific/Kiritimati', 'Pacific/Chatham'] as $namedZone) {
        try {
            $mysqli->query("SET time_zone = '$namedZone'");
            $timeZoneProbes["SET time_zone = '$namedZone'"] = 'accepted';
        } catch (mysqli_sql_exception $e) {
            $timeZoneProbes["SET time_zone = '$namedZone'"] = 'rejected: error ' . $e->getCode();
        }
    }
    $mysqli->query("SET time_zone = '$originalTimeZone'");
} catch (mysqli_sql_exception $e) {
    $timeZoneProbes = ['SET time_zone probes' => 'probe failed: ' . $e->getMessage()];
}
try {
    $timeZoneProbes['mysql.time_zone_name row count'] = (string) (int) $mysqli->query("SELECT COUNT(*) FROM mysql.time_zone_name")->fetch_row()[0];
} catch (mysqli_sql_exception $e) {
    $timeZoneProbes['mysql.time_zone_name row count'] = 'query failed: error ' . $e->getCode();
}
$probes += $timeZoneProbes;

echo "### Time zone offsets\n\n";
echo mdTable($timeZoneProbes);

//
// Consistent-snapshot backups - CMS Builder's backupDatabase() opens its dump with
// SET TRANSACTION ISOLATION LEVEL REPEATABLE READ then START TRANSACTION WITH
// CONSISTENT SNAPSHOT, the pair mysqldump --single-transaction sends. Two server
// behaviors motivated the pair: hosts that default to READ-COMMITTED make a bare
// START silently skip the snapshot (warning 138), and a loaded RocksDB plugin turns
// it into a hard error (MariaDB 4062) even when no table uses RocksDB, because every
// transactional engine is asked to open the snapshot. Probes run on a dedicated
// connection pinned to READ-COMMITTED to mirror those hosts. "Snapshot held" is
// observed behavior - the main connection commits an INSERT mid-transaction and the
// probe checks whether it shows up - not a reading of @@transaction_isolation, which
// MariaDB reports at session scope even inside the transaction
//
$snapshotProbes = [];
try {
    $snap = new mysqli($hostname, $username, $password, $database);
    $snap->set_charset('utf8mb4');

    // which spelling of the isolation variable each server has (MySQL 5.7 has both,
    // 8.0 dropped tx_isolation, MariaDB only added transaction_isolation in 11.1.1);
    // read before pinning the session so the values show the server default
    $isolationVariable = null;
    foreach (['@@transaction_isolation', '@@tx_isolation'] as $variableName) {
        try {
            $snapshotProbes["SNAPSHOT: $variableName server default"] = $snap->query("SELECT $variableName")->fetch_row()[0];
            $isolationVariable ??= $variableName;
        } catch (mysqli_sql_exception $e) {
            $snapshotProbes["SNAPSHOT: $variableName server default"] = 'error ' . $e->getCode();
        }
    }

    $snap->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

    $snapshotProbes['SNAPSHOT: bare START at READ-COMMITTED']  = snapshotVisibility($snap, $mysqli, withSetPair: false);
    $snapshotProbes['SNAPSHOT: SET+START pair at READ-COMMITTED'] = snapshotVisibility($snap, $mysqli, withSetPair: true);

    // does the one-shot level survive an intervening statement? backupDatabase() must
    // not run anything between the SET and the START on servers where this says no
    $snapshotProbes['SNAPSHOT: pair with SELECT 1 between SET and START'] = snapshotVisibility($snap, $mysqli, withSetPair: true, betweenSql: 'SELECT 1');
    $snapshotProbes['SNAPSHOT: pair with isolation variable read between'] = $isolationVariable === null
        ? 'skipped (no isolation variable)'
        : snapshotVisibility($snap, $mysqli, withSetPair: true, betweenSql: "SELECT $isolationVariable");

    // what the variable reports while the one-shot level is active, and that the
    // session level comes back after COMMIT
    if ($isolationVariable !== null) {
        $snap->query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $snap->query("START TRANSACTION WITH CONSISTENT SNAPSHOT");
        $snapshotProbes["SNAPSHOT: $isolationVariable inside pair transaction"] = $snap->query("SELECT $isolationVariable")->fetch_row()[0];
        $snap->query("COMMIT");
        $snapshotProbes["SNAPSHOT: $isolationVariable after COMMIT"] = $snap->query("SELECT $isolationVariable")->fetch_row()[0];
    }

    // SET TRANSACTION with a transaction already open - the error backupDatabase()
    // would hit if it ever opened its dump inside one. The SESSION form is documented
    // as allowed mid-transaction (it's what mysqldump sends), probed here to confirm
    $snap->query("START TRANSACTION");
    try {
        $snap->query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $snapshotProbes['SNAPSHOT: SET TRANSACTION inside open transaction'] = 'accepted';
    } catch (mysqli_sql_exception $e) {
        $snapshotProbes['SNAPSHOT: SET TRANSACTION inside open transaction'] = 'error ' . $e->getCode() . ': ' . $e->getMessage();
    }
    try {
        $snap->query("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $snapshotProbes['SNAPSHOT: SET SESSION TRANSACTION inside open transaction'] = 'accepted';
    } catch (mysqli_sql_exception $e) {
        $snapshotProbes['SNAPSHOT: SET SESSION TRANSACTION inside open transaction'] = 'error ' . $e->getCode();
    }
    $snap->query("ROLLBACK");
    $snap->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"); // undo if the SESSION form took effect mid-transaction

    // autocommit=0 alone vs autocommit=0 plus a table read - which one counts as "a
    // transaction in progress" for that error (the read must touch a real InnoDB
    // table; SELECT 1 opens no transaction)
    $mysqli->query("DROP TABLE IF EXISTS zdb_probe_snap");
    $mysqli->query("CREATE TABLE zdb_probe_snap (num INT NOT NULL) ENGINE=InnoDB");
    $snap->query("SET autocommit = 0");
    try {
        $snap->query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $snapshotProbes['SNAPSHOT: SET TRANSACTION at autocommit=0, no prior query'] = 'accepted';
    } catch (mysqli_sql_exception $e) {
        $snapshotProbes['SNAPSHOT: SET TRANSACTION at autocommit=0, no prior query'] = 'error ' . $e->getCode();
    }
    $snap->query("ROLLBACK");
    $snap->query("SELECT COUNT(*) FROM zdb_probe_snap");
    try {
        $snap->query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $snapshotProbes['SNAPSHOT: SET TRANSACTION at autocommit=0 after a table read'] = 'accepted';
    } catch (mysqli_sql_exception $e) {
        $snapshotProbes['SNAPSHOT: SET TRANSACTION at autocommit=0 after a table read'] = 'error ' . $e->getCode();
    }
    $snap->query("ROLLBACK");
    $snap->query("SET autocommit = 1");
    $mysqli->query("DROP TABLE zdb_probe_snap");

    // the pair as a user with only SELECT - the docs say only SET GLOBAL needs
    // privileges, and shared-host backup users are never SUPER
    try {
        $mysqli->query("DROP USER IF EXISTS 'zdb_probe_min'@'%'");
        $mysqli->query("CREATE USER 'zdb_probe_min'@'%' IDENTIFIED BY 'zdb_probe_pw'");
        $mysqli->query("GRANT SELECT ON `$database`.* TO 'zdb_probe_min'@'%'");
        $minUser = new mysqli($hostname, 'zdb_probe_min', 'zdb_probe_pw', $database);
        $minUser->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        $minUser->query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $minUser->query("START TRANSACTION WITH CONSISTENT SNAPSHOT");
        $warningCount = $minUser->warning_count;
        $minUser->query("ROLLBACK");
        $minUser->close();
        $snapshotProbes['SNAPSHOT: pair as SELECT-only user'] = $warningCount ? "accepted with $warningCount warning(s)" : 'accepted, no warnings';
    } catch (mysqli_sql_exception $e) {
        $snapshotProbes['SNAPSHOT: pair as SELECT-only user'] = 'error ' . $e->getCode() . ': ' . $e->getMessage();
    }
    $mysqli->query("DROP USER IF EXISTS 'zdb_probe_min'@'%'");

    // RocksDB veto - probes only run where the engine is loaded (the +rocksdb matrix
    // entries install the plugin before probing); everywhere else the merge report
    // shows them as (no data)
    $rocksdbSupport = null;
    foreach ($snap->query("SHOW ENGINES")->fetch_all() as $engine) {
        if (strcasecmp($engine[0], 'ROCKSDB') === 0) {
            $rocksdbSupport = $engine[1];
        }
    }
    $snapshotProbes['SNAPSHOT: ROCKSDB in SHOW ENGINES'] = $rocksdbSupport ?? 'not present';
    if ($rocksdbSupport !== null) {
        $snapshotProbes['SNAPSHOT rocksdb loaded, no rocksdb table: bare START at READ-COMMITTED'] = snapshotVisibility($snap, $mysqli, withSetPair: false);
        $snapshotProbes['SNAPSHOT rocksdb loaded, no rocksdb table: pair at READ-COMMITTED']       = snapshotVisibility($snap, $mysqli, withSetPair: true);

        $snap->query("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $snapshotProbes['SNAPSHOT rocksdb loaded, no rocksdb table: bare START at REPEATABLE-READ'] = snapshotVisibility($snap, $mysqli, withSetPair: false);
        $snap->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

        try {
            $mysqli->query("DROP TABLE IF EXISTS zdb_probe_rocks");
            $mysqli->query("CREATE TABLE zdb_probe_rocks (num INT NOT NULL PRIMARY KEY) ENGINE=ROCKSDB");
            $snapshotProbes['SNAPSHOT rocksdb table exists: bare START at READ-COMMITTED'] = snapshotVisibility($snap, $mysqli, withSetPair: false);
            $snapshotProbes['SNAPSHOT rocksdb table exists: pair at READ-COMMITTED']       = snapshotVisibility($snap, $mysqli, withSetPair: true);
            $mysqli->query("DROP TABLE zdb_probe_rocks");
        } catch (mysqli_sql_exception $e) {
            $snapshotProbes['SNAPSHOT rocksdb table exists: bare START at READ-COMMITTED'] = 'CREATE TABLE ENGINE=ROCKSDB rejected: ' . $e->getMessage();
        }
    }

    $snap->close();
} catch (mysqli_sql_exception $e) {
    $snapshotProbes['SNAPSHOT probes'] = 'probe failed: ' . $e->getMessage();
}
$probes += $snapshotProbes;

echo "### Consistent-snapshot backups\n\n";
echo mdTable($snapshotProbes);

//
// Session sql_mode - connect() applies ZenDB's default modes in one SET, and one
// unrecognized name aborts the whole statement. Runs after the neutral probes since
// it changes this session's mode; the date probes below depend on it
//
try {
    $mysqli->query("SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $warningCount  = $mysqli->warning_count;
    $sqlModeProbes = [
        'SET ZenDB default sql_mode'   => $warningCount ? "accepted with $warningCount warning(s)" : 'accepted',
        '@@SESSION.sql_mode after SET' => $mysqli->query("SELECT @@SESSION.sql_mode")->fetch_row()[0],
    ];
} catch (mysqli_sql_exception $e) {
    $sqlModeProbes = ['SET ZenDB default sql_mode' => 'rejected: ' . $e->getMessage()];
}
$probes += $sqlModeProbes;

echo "### Session sql_mode\n\n";
echo mdTable($sqlModeProbes);

//
// Zero and invalid dates under ZenDB's sql_mode - the default mode includes
// NO_ZERO_IN_DATE but not NO_ZERO_DATE, and how that pair interacts with
// STRICT_ALL_TABLES has shifted across versions. Depends on the SET above, so it
// only runs when that succeeded
//
if (isset($sqlModeProbes['@@SESSION.sql_mode after SET'])) {
    try {
        $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
        $mysqli->query("CREATE TABLE zdb_probe_special (d DATE NOT NULL)");
        $zeroDateProbes = [];
        foreach (["'0000-00-00'", "'2024-00-15'", "'2024-01-00'"] as $literal) {
            try {
                $mysqli->query("INSERT INTO zdb_probe_special VALUES ($literal)");
                $warnings = $mysqli->warning_count;
                $zeroDateProbes["INSERT DATE $literal under ZenDB sql_mode"] = $warnings ? "accepted with $warnings warning(s)" : 'accepted';
                $mysqli->query("DELETE FROM zdb_probe_special");
            } catch (mysqli_sql_exception $e) {
                $zeroDateProbes["INSERT DATE $literal under ZenDB sql_mode"] = 'rejected: error ' . $e->getCode();
            }
        }
        $mysqli->query("DROP TABLE zdb_probe_special");
    } catch (mysqli_sql_exception $e) {
        $zeroDateProbes = ['INSERT DATE probes' => 'probe failed: ' . $e->getMessage()];
    }
} else {
    $zeroDateProbes = ['INSERT DATE probes' => 'skipped (ZenDB sql_mode SET was rejected)'];
}
$probes += $zeroDateProbes;

echo "### Zero and invalid dates\n\n";
echo mdTable($zeroDateProbes);

$mysqli->close();

/**
 * Render probe name => value pairs as a two-column markdown table.
 */
function mdTable(array $rows): string
{
    $out = "| Probe | Result |\n|---|---|\n";
    foreach ($rows as $name => $value) {
        $out .= "| $name | " . mdValue($value) . " |\n";
    }
    return $out . "\n";
}

/**
 * Parse SHOW CREATE TABLE output into column name => definition pairs. Index, key,
 * and constraint lines are skipped, and any leading indentation is accepted. Returns
 * an empty array when no column lines match, so callers can show the raw DDL instead.
 *
 *     parseColumnDefinitions($createTable); // ['num' => 'int NOT NULL AUTO_INCREMENT', ...]
 */
function parseColumnDefinitions(string $createTable): array
{
    preg_match_all('/^\s+`([^`]+)` (.*?),?$/m', $createTable, $matches, PREG_SET_ORDER);
    return array_column($matches, 2, 1);
}

/**
 * Create a throwaway table, return the SHOW CREATE line for one column, and drop the
 * table. Returns the server's error message when it rejects the CREATE, since which
 * servers reject which syntax is itself a probe result.
 */
function probeColumnDefinition(mysqli $mysqli, string $column, string $createSql): string
{
    try {
        $mysqli->query("DROP TABLE IF EXISTS zdb_probe_special");
        $mysqli->query($createSql);
        $createTable = $mysqli->query("SHOW CREATE TABLE zdb_probe_special")->fetch_row()[1];
        $mysqli->query("DROP TABLE zdb_probe_special");
        return parseColumnDefinitions($createTable)[$column] ?? trim($createTable);
    } catch (mysqli_sql_exception $e) {
        return 'CREATE TABLE rejected: ' . $e->getMessage();
    }
}


/**
 * Open a snapshot transaction on $snap the way backupDatabase() does and report what
 * the server did: the START's warning or error, then whether a row committed by
 * $writer mid-transaction stays invisible (snapshot held) or shows up (no snapshot).
 * $withSetPair prepends SET TRANSACTION ISOLATION LEVEL REPEATABLE READ; $betweenSql
 * runs between the SET and the START to see whether it consumes the one-shot level.
 *
 *     snapshotVisibility($snap, $mysqli, withSetPair: true);
 *     // 'accepted; concurrent INSERT invisible (snapshot held)'
 */
function snapshotVisibility(mysqli $snap, mysqli $writer, bool $withSetPair, ?string $betweenSql = null): string
{
    $writer->query("DROP TABLE IF EXISTS zdb_probe_snap");
    $writer->query("CREATE TABLE zdb_probe_snap (num INT NOT NULL) ENGINE=InnoDB");
    $writer->query("INSERT INTO zdb_probe_snap VALUES (1)");
    try {
        try {
            if ($withSetPair) {
                $snap->query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            }
            if ($betweenSql !== null) {
                $snap->query($betweenSql);
            }
            $snap->query("START TRANSACTION WITH CONSISTENT SNAPSHOT");
            $status = $snap->warning_count
                ? implode(': ', array_slice($snap->query("SHOW WARNINGS")->fetch_row(), 1)) // "138: InnoDB: WITH CONSISTENT SNAPSHOT was ignored..."
                : 'accepted';
        } catch (mysqli_sql_exception $e) {
            return "error {$e->getCode()}: {$e->getMessage()}";
        }
        $snap->query("SELECT COUNT(*) FROM zdb_probe_snap"); // the dump is already reading when the concurrent write lands
        $writer->query("INSERT INTO zdb_probe_snap VALUES (2)");
        $rowsSeen = (int)$snap->query("SELECT COUNT(*) FROM zdb_probe_snap")->fetch_row()[0];
        return "$status; " . ($rowsSeen === 1 ? 'concurrent INSERT invisible (snapshot held)' : 'concurrent INSERT visible (no snapshot)');
    } finally {
        $snap->query("ROLLBACK");
        $writer->query("DROP TABLE IF EXISTS zdb_probe_snap");
    }
}

/**
 * Format a raw result value for display: SQL NULL as the word NULL, strings with
 * unprintable bytes as 0x hex.
 */
function displayValue(?string $value): string
{
    return match (true) {
        $value === null                            => 'NULL',
        preg_match('/[^\x20-\x7E]/', $value) === 1 => '0x' . bin2hex($value),
        default                                    => $value,
    };
}

/**
 * Return the MYSQLI_TYPE_* constant name for a field type number, e.g. 253 => VAR_STRING.
 */
function mysqliTypeName(int $type): string
{
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (get_defined_constants(true)['mysqli'] as $name => $value) {
            if (str_starts_with($name, 'MYSQLI_TYPE_')) {
                $names[$value] ??= substr($name, strlen('MYSQLI_TYPE_'));
            }
        }
    }
    return $names[$type] ?? "unknown ($type)";
}
