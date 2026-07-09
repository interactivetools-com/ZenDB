#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Merge the JSON files written by ci-timing.php into a markdown summary for the GitHub
 * run-summary page: a grid of databases (rows) by PHP versions (columns) with PHPUnit
 * test-plan seconds in the cells, followed by the slowest individual tests. Cells at
 * or above 1.5x the median are bolded; jobs with no data show "-".
 *
 *     php .github/scripts/ci-timing-summary.php timings/*.json >> "$GITHUB_STEP_SUMMARY"
 */

require __DIR__ . '/ci-lib.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php .github/scripts/ci-timing-summary.php <timing.json> [timing2.json ...]\n");
    exit(1);
}

$jobs = [];
foreach (array_slice($argv, 1) as $path) {
    $job = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    if (isset($job['php'], $job['database'])) {
        $jobs[] = $job;
    } else {
        fwrite(STDERR, "Skipping $path: not a ci-timing JSON file\n");
    }
}

if (!$jobs) {
    echo "# Test Timing\n\nNo timing data was uploaded by the test jobs.\n";
    exit;
}

$phpVersions = array_unique(array_column($jobs, 'php'));
$databases   = array_unique(array_column($jobs, 'database'));
usort($phpVersions, 'version_compare');
usort($databases, fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));

$byCombo = []; // database => php => job
foreach ($jobs as $job) {
    $byCombo[$job['database']][$job['php']] = $job;
}

$allSeconds = array_filter(array_column($jobs, 'seconds'), fn($s) => $s !== null);
sort($allSeconds);
$median = $allSeconds ? $allSeconds[intdiv(count($allSeconds), 2)] : 0.0;

echo "# Test Timing\n\n";
echo "Test-plan seconds as measured by PHPUnit itself; container startup, composer install, ";
echo "and the database wait are not included. " . count($jobs) . " jobs, median " . sprintf('%.1fs', $median) . ".\n\n";

echo '| Database | PHP ' . implode(' | PHP ', $phpVersions) . " |\n";
echo '|---|' . str_repeat('---:|', count($phpVersions)) . "\n";
foreach ($databases as $database) {
    $cells = [];
    foreach ($phpVersions as $php) {
        $cells[] = formatCell($byCombo[$database][$php] ?? null, $median);
    }
    echo "| $database | " . implode(' | ', $cells) . " |\n";
}
echo "\nBold: at or above 1.5x the median.\n\n";

// Slowest individual tests across all jobs (each job reports its top 15)
$slowest = [];
foreach ($jobs as $job) {
    foreach ($job['slowest'] ?? [] as $slow) {
        $slow['job'] = "PHP {$job['php']} / {$job['database']}";
        $slowest[]   = $slow;
    }
}
usort($slowest, fn($a, $b) => $b['seconds'] <=> $a['seconds']);

echo "## Slowest tests\n\n";
echo "| Seconds | Test | Job |\n|---:|---|---|\n";
foreach (array_slice($slowest, 0, 10) as $slow) {
    echo "| {$slow['seconds']} | " . mdValue($slow['test']) . " | {$slow['job']} |\n";
}
echo "\n";

// Client-side facts per PHP version - mysqlnd version and whether the native
// int/float fetch option exists (absent on non-mysqlnd builds)
$clients = [];
foreach ($jobs as $job) {
    if (isset($job['mysqliClient'])) {
        $native = ($job['intFloatNative'] ?? true) ? '' : ' (no MYSQLI_OPT_INT_AND_FLOAT_NATIVE)';
        $clients[$job['php']] = "PHP {$job['phpFull']}: {$job['mysqliClient']}$native";
    }
}
if ($clients) {
    uksort($clients, 'version_compare');
    echo "## mysqli client per PHP version\n\n";
    foreach ($clients as $line) {
        echo "- $line\n";
    }
    echo "\n";
}

/**
 * Format one grid cell: seconds, bolded when at or above 1.5x the median, with a
 * failure count when the job had failures or errors.
 */
function formatCell(?array $job, float $median): string
{
    if ($job === null || $job['seconds'] === null) {
        return '-';
    }
    $cell = sprintf('%.1fs', $job['seconds']);
    if ($median > 0 && $job['seconds'] >= 1.5 * $median) {
        $cell = "**$cell**";
    }
    if ($job['failed']) {
        $cell .= " ({$job['failed']} failed)";
    }
    return $cell;
}
