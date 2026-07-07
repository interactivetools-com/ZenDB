#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Merge the JSON files written by db-behavior-report.php --json into one markdown
 * comparison. Identical answers collapse to one line per probe, so the report reads
 * as "who differs" instead of a 14-column grid.
 *
 *     php tools/db-behavior-merge.php reports/*.json
 *     php tools/db-behavior-merge.php reports/*.json >> "$GITHUB_STEP_SUMMARY"
 */

require __DIR__ . '/ci-lib.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/db-behavior-merge.php <report.json> [report2.json ...]\n");
    exit(1);
}

// server => [probe => value], ordered mysql, mariadb, percona, each oldest version first
$reports = [];
foreach (array_slice($argv, 1) as $path) {
    $report = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    if (isset($report['server'], $report['probes'])) {
        $reports[$report['server']] = $report['probes'];
    } else {
        fwrite(STDERR, "Skipping $path: not a db-behavior-report JSON file\n");
    }
}
uksort($reports, fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));

if (!$reports) {
    echo "# DB Behavior Report\n\nNo probe data found.\n";
    exit;
}

echo "# DB Behavior Report\n\n";
echo count($reports) . " servers probed: " . implode(', ', array_keys($reports)) . "\n\n";

// Probe order follows first appearance across the reports
$probeNames = [];
foreach ($reports as $probes) {
    foreach ($probes as $name => $value) {
        $probeNames[$name] = true;
    }
}

foreach (array_keys($probeNames) as $name) {
    $serversByValue = [];
    $missing        = [];
    foreach ($reports as $server => $probes) {
        if (array_key_exists($name, $probes)) {
            $serversByValue[$probes[$name]][] = $server;
        } else {
            $missing[] = $server;
        }
    }

    echo "### $name\n\n";
    if (count($serversByValue) === 1 && !$missing) {
        echo "- all servers: " . mdValue((string)array_key_first($serversByValue)) . "\n";
    } else {
        foreach ($serversByValue as $value => $servers) {
            echo "- " . mdValue((string)$value) . " → " . implode(', ', $servers) . "\n";
        }
        if ($missing) {
            echo "- (no data) → " . implode(', ', $missing) . "\n";
        }
    }
    echo "\n";
}
