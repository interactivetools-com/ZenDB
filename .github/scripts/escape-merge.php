<?php
declare(strict_types=1);

/**
 * Merge per-cell escape-probe JSON artifacts into one comparison grid.
 *
 *     php .github/scripts/escape-merge.php probes/*.json >> "$GITHUB_STEP_SUMMARY"
 *
 * Serves both probe families: CPU cells (escape-probe.php) label as "os-arch php",
 * end-to-end cells (escape-e2e-probe.php) label with their server (DB_LABEL), so
 * one merge script handles either workflow's artifacts or a local mixed batch.
 *
 * Rows = tests, columns = cells, values = "B vs A" ratio. Ratio > 1.00 means the
 * B side (the candidate) is faster; >= 1.05 bold, <= 0.95 flagged as a regression.
 * CORPUS_FAIL and missing cells are called out.
 */

require __DIR__ . '/ci-lib.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "Usage: php escape-merge.php probe-*.json\n");
    exit(1);
}

$cells = []; // label => decoded json
foreach ($files as $file) {
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || !isset($data['tests'])) {
        fwrite(STDERR, "skipping unreadable probe file: $file\n");
        continue;
    }
    if (isset($data['server_label'])) {
        $label = (string)$data['server_label'];
    } else {
        $phpShort = preg_replace('/^(\d+\.\d+)\..*$/', '$1', (string)$data['php']);
        $arch     = str_contains((string)$data['arch'], 'arm') || str_contains((string)$data['arch'], 'aarch') ? 'arm' : 'x64';
        $label    = strtolower((string)$data['os']) . "-$arch $phpShort"
            . (!empty($data['zts']) ? ' zts' : '')
            . (($data['jit'] ?? '') === 'tracing' ? ' jit' : '');
    }
    $cells[$label] = $data;
}
if ($cells === []) {
    fwrite(STDERR, "no valid probe files\n");
    exit(1);
}

// Server-labeled cells sort in matrix order (ci-lib), OS cells alphabetically
uksort($cells, static function (string $a, string $b) use ($cells): int {
    $aServer = isset($cells[$a]['server_label']);
    $bServer = isset($cells[$b]['server_label']);
    if ($aServer !== $bServer) {
        return $aServer <=> $bServer;
    }
    if ($aServer) {
        return databaseSortKey($a) <=> databaseSortKey($b);
    }
    return strcmp($a, $b);
});

// Collect the union of test ids in first-seen order, and A/B labels for the legend
$testIds = [];
$legend  = [];
foreach ($cells as $data) {
    foreach ($data['tests'] as $id => $t) {
        if (!in_array($id, $testIds, true)) {
            $testIds[]   = $id;
            $legend[$id] = [$t['a_label'] ?? '?', $t['b_label'] ?? '?', $t['sink'] ?? ''];
        }
    }
}

echo "## Escape matrix: B-vs-A ratios (>1.00 = candidate faster)\n\n";

// Correctness status: any failure anywhere is a headline, not a footnote
$corpusBad = [];
foreach ($cells as $label => $data) {
    foreach (($data['corpus']['escapers'] ?? []) as $fn => $ok) {
        if (!$ok) {
            $corpusBad[] = "$label:$fn";
        }
    }
    foreach (($data['corpus']['identity'] ?? []) as $fn => $ok) {
        if (!$ok) {
            $corpusBad[] = "$label:identity:$fn";
        }
    }
    if (isset($data['corpus']['canary_ok']) && !$data['corpus']['canary_ok']) {
        $corpusBad[] = "$label:canary[" . ($data['corpus']['canary'] ?? '?') . "]";
    }
    if (!empty($data['xdebug'])) {
        $corpusBad[] = "$label:XDEBUG-LOADED";
    }
}
echo $corpusBad === []
    ? "Correctness: every escaper passes its gate on every cell.\n\n"
    : "**CORRECTNESS FAILURES: " . implode(', ', $corpusBad) . "** - affected timings withheld or invalid.\n\n";

// Grid
echo '| test |';
foreach (array_keys($cells) as $label) {
    echo " $label |";
}
echo "\n|---|" . str_repeat('---|', count($cells)) . "\n";
foreach ($testIds as $id) {
    echo "| $id |";
    foreach ($cells as $data) {
        $t = $data['tests'][$id] ?? null;
        if ($t === null) {
            echo ' - |';
        } elseif (($t['verdict'] ?? '') === 'CORPUS_FAIL') {
            echo ' **FAIL** |';
        } else {
            $r    = (float)$t['ratio'];
            $text = sprintf('%.2fx', $r);
            echo ' ' . ($r >= 1.05 ? "**$text**" : ($r <= 0.95 ? "$text (slower)" : $text)) . ' |';
        }
    }
    echo "\n";
}

// Legend
echo "\n<details><summary>Test legend (A vs B, sink)</summary>\n\n";
foreach ($legend as $id => [$a, $b, $sink]) {
    echo "- **$id**: $a vs $b" . ($sink !== '' ? " [$sink sink]" : '') . "\n";
}
echo "\n</details>\n";
