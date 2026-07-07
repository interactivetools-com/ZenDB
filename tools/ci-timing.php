#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Write a timing JSON for one CI matrix job, parsed from PHPUnit's JUnit XML. The
 * seconds value is PHPUnit's own test-plan time; container startup, composer install,
 * and the database wait are not included. ci-timing-summary.php merges these files
 * into the run-summary grid.
 *
 *     php tools/ci-timing.php junit.xml 8.1 mysql:5.7 timing-8.1-mysql-5.7.json
 *
 * A missing or unreadable junit.xml (job crashed before PHPUnit finished) still writes
 * a JSON file, with null seconds, so the summary grid shows the job as "no data".
 */

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php tools/ci-timing.php <junit.xml> <php-version> <database> <output.json>\n");
    exit(1);
}
[, $junitPath, $phpVersion, $dbLabel, $outPath] = $argv;

$data = [
    'php'      => $phpVersion,
    'database' => $dbLabel,
    'seconds'  => null,
    'tests'    => null,
    'failed'   => null,
    'slowest'  => [],
    // Client-side facts: the probe workflow pins one PHP version, so this PHP x DB
    // matrix is the only place client differences (mysqlnd version, native int/float
    // fetch support) show up
    'phpFull'        => PHP_VERSION,
    'mysqliClient'   => extension_loaded('mysqli') ? mysqli_get_client_info() : 'mysqli not loaded',
    'intFloatNative' => defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE'),
];

$xml = is_file($junitPath) ? simplexml_load_file($junitPath) : false;
if ($xml !== false && isset($xml->testsuite[0])) {
    $suite = $xml->testsuite[0];

    $data['seconds'] = round((float)$suite['time'], 2);
    $data['tests']   = (int)$suite['tests'];
    $data['failed']  = (int)$suite['failures'] + (int)$suite['errors'];

    $testcases = [];
    foreach ($xml->xpath('//testcase') as $testcase) {
        $class       = (string)($testcase['class'] ?? $testcase['classname'] ?? '');
        $shortClass  = substr(strrchr('\\' . str_replace('.', '\\', $class), '\\'), 1);
        $testcases[] = [
            'test'    => $shortClass . '::' . $testcase['name'],
            'seconds' => round((float)$testcase['time'], 3),
        ];
    }
    usort($testcases, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
    $data['slowest'] = array_slice($testcases, 0, 15);
}

file_put_contents($outPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Wrote $outPath (" . ($data['seconds'] === null ? 'no junit data' : "{$data['seconds']}s, {$data['tests']} tests") . ")\n";
