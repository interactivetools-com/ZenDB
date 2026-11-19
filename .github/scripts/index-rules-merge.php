#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Merge the JSON files written by index-rules-probe.php --json into the report page, or
 * with --data into its data page: one table per question, one row per server, one column
 * per case. Error and warning messages are listed in full under each table, with the
 * servers that produced them when the wording differs.
 *
 *     php .github/scripts/index-rules-merge.php probes/*.json > docs/internal/index-rules-matrix.md
 *     php .github/scripts/index-rules-merge.php --data probes/*.json > docs/internal/data/index-rules-matrix-data.md
 */

require __DIR__ . '/ci-lib.php';

$dataPage = ($argv[1] ?? '') === '--data';
$paths    = array_slice($argv, $dataPage ? 2 : 1);
if (!$paths) {
    fwrite(STDERR, "Usage: php .github/scripts/index-rules-merge.php [--data] <probe.json> [probe2.json ...]\n");
    exit(1);
}

$reports = []; // server => report
foreach ($paths as $path) {
    $report = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    if (isset($report['server'], $report['questions'])) {
        $reports[$report['server']] = $report;
    } else {
        fwrite(STDERR, "Skipping $path: not an index-rules-probe JSON file\n");
    }
}
uksort($reports, fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));

if (!$reports) {
    echo "# Index Rules Matrix" . ($dataPage ? ": Data" : "") . "\n\nNo probe data found.\n";
    exit;
}

/**
 * One markdown cell for a case result. Errors show the code only; the message is
 * listed under the table.
 */
function cell(?array $r): string
{
    if ($r === null) {
        return 'n/a'; // case needs a server variable this server doesn't have (innodb_large_prefix)
    }
    if (!$r['ok']) {
        return (!empty($r['table_error']) ? 'CREATE TABLE ' : '') . "ERR $r[code]";
    }
    $parts = ['OK'];
    if (isset($r['sub_part'])) {
        $parts[] = "Sub_part=$r[sub_part]";
    }
    if (!empty($r['warnings'])) {
        $parts[] = 'warn ' . implode('+', array_map(fn($w) => explode(':', $w, 2)[0], $r['warnings']));
    }
    if (isset($r['using_filesort'])) {
        $parts[] = "key=$r[explain_key]";
        $parts[] = "filesort=$r[using_filesort]";
    }
    if (isset($r['note']) && !isset($r['sub_part'])) {
        $parts[] = mdValue($r['note']);
    }
    return implode(' ', $parts);
}

$generated = "Generated " . date('Y-m-d') . " from " . count($reports) . " servers";

if (!$dataPage) {
    echo "# Index Rules Matrix\n\n";
    echo <<<'__INTRO__'
        An index on a TEXT, BLOB or long VARCHAR column needs a prefix length, and the servers
        don't agree on which lengths they take: one refuses a length that another shortens with a
        warning. This page checks one prefix rule for creating an index, on every supported
        database server. The rule: TEXT and BLOB types get `(768)`, `VARCHAR(n)` and
        `VARBINARY(n)` get `(min(n, 768))`, and every other type gets no prefix.


        __INTRO__;
    echo "*$generated. Every result is in [index-rules-matrix-data.md](data/index-rules-matrix-data.md).*\n\n";
    // Hand-written sections: update them when the probe data changes
    echo <<<'__FINDINGS__'
        ## The Short Version

        - **`(768)` is the longest prefix every server accepts on a utf8mb4 column.** 768
          characters at 4 bytes each is 3,072 bytes, the key limit of a DYNAMIC table. At `(769)`
          MySQL, Percona and MariaDB 10.2.6 and 10.2.7 refuse the index with error 1071; MariaDB
          10.2.44 and 10.3+ create it at 768 with a warning
        - **TEXT and BLOB columns need a prefix.** Without one, every MySQL and Percona and
          MariaDB thru 10.3 give error 1170. MariaDB 10.4+ picks a prefix itself
        - **TINYTEXT and TINYBLOB can't take `(768)` on MySQL and Percona**, which refuse a
          prefix over 255 bytes on them. `(63)` on TINYTEXT and `(255)` on TINYBLOB work on every
          server
        - **A VARCHAR prefix as long as the column isn't a prefix.** `(255)` on `VARCHAR(255)`
          indexes the whole column: SHOW INDEX and SHOW CREATE TABLE show no prefix, and ORDER BY
          uses the index. `(250)` is a real prefix index, and ORDER BY does a filesort
        - **These numbers are for ROW_FORMAT=DYNAMIC**, the default on all 19 servers. A COMPACT
          or REDUNDANT table stops at 767 bytes, which is 191 utf8mb4 characters

        ## The Prefix for Each Column Type

        On a DYNAMIC utf8mb4 table.

        | Column type | Accepted on every server | Otherwise |
        |---|---|---|
        | `TINYTEXT` | `(63)` | `(255)` and `(768)` are error 1071 on MySQL and Percona; MariaDB cuts them to 255 |
        | `TINYBLOB` | `(255)` | `(768)` is error 1071 on MySQL and Percona; MariaDB cuts it to 255 |
        | `TEXT` | `(768)` | No prefix is error 1170 on MySQL, Percona and MariaDB thru 10.3 |
        | `VARCHAR(n)` | No prefix up to `VARCHAR(768)`, `(768)` above it | `(769)`, or no prefix on `VARCHAR(769)`, is error 1071 or a shortened index |
        | `VARBINARY(n)` | No prefix up to `VARBINARY(3072)` | `VARBINARY(3073)` with no prefix is error 1071 or a shortened index |
        | `CHAR(255)`, `BINARY(255)`, `ENUM`, `SET`, `DECIMAL(10,2)`, `DATETIME` | No prefix | |
        | `JSON`, `GEOMETRY` | Nothing | See [JSON and GEOMETRY](#json-and-geometry) |

        `MEDIUMTEXT`, `LONGTEXT`, `BLOB`, `MEDIUMBLOB` and `LONGBLOB` were only run with no prefix.
        They give the same error 1170 as `TEXT` there, and MariaDB 10.4+ picks 768 for the text
        types and 3072 for the blob types.

        A lowercase type name (`tinytext`) gets the same answers as `TINYTEXT` on every server,
        and SHOW CREATE TABLE prints the type in lowercase on every server.

        ## The Rule Against the Results

        | The rule | On the 19 servers |
        |---|---|
        | TEXT and BLOB types get `(768)` | Accepted on `TEXT` everywhere. `TINYTEXT` and `TINYBLOB` need `(63)` and `(255)` on MySQL and Percona |
        | `VARCHAR(n)` and `VARBINARY(n)` get `(min(n, 768))` | Accepted everywhere. With n at 768 or under it's a whole-column index, the same as no prefix. `VARBINARY` could go to 3072 |
        | Every other type gets no prefix | Accepted for the six ordinary types. `JSON` and `GEOMETRY` can't take a plain index on MySQL and Percona |
        | Tables are DYNAMIC | True of a new table on every server. On a COMPACT or REDUNDANT table the limit is 191 characters, so `(768)` fails |

        ## What Each Server Does at the Limit

        | | MySQL, Percona, MariaDB 10.2.6 and 10.2.7 | MariaDB 10.2.44 and 10.3+ |
        |---|---|---|
        | `(769)` on a utf8mb4 column, or no prefix on `VARCHAR(769)` | Error 1071 | Index created at 768, warning 1071 |
        | No prefix on `VARBINARY(3073)` | Error 1071 | Index created at 3072, warning 1071 |

        | | MySQL, Percona, MariaDB thru 10.3 | MariaDB 10.4+ |
        |---|---|---|
        | No prefix on a TEXT or BLOB type | Error 1170 | Picks 255 for `TINYTEXT` and `TINYBLOB`, 768 for the other text types, 3072 for the other blob types. Warning 1071 on the 768 and 3072 cases |

        MariaDB shortens a prefix with a warning at most, and with none on `TINYTEXT` and
        `TINYBLOB`. SHOW INDEX reports the prefix the server used.

        ## Other Row Formats

        COMPACT and REDUNDANT tables stop at 767 bytes (191 utf8mb4 characters) on every server,
        so `VARCHAR(768)` with no prefix and `TEXT (768)` both fail there. MySQL and Percona give
        error 1071 "max key length is 767 bytes". MariaDB gives error 1709 "Index column size too
        large. The maximum column size is 767 bytes". COMPACT was the default row format before
        MySQL 5.7.

        `innodb_large_prefix` is ON by default on MySQL/Percona 5.7 and MariaDB 10.2. With it
        OFF, a DYNAMIC table stops at 767 bytes too: MySQL/Percona 5.7 give error 1071, and
        MariaDB 10.2 cuts the index to 191 with a warning (10.2.6 and 10.2.7 do that for `TEXT`
        and give error 1071 for `VARCHAR(768)`). MariaDB 10.3 to 10.5 show the variable as empty,
        and MariaDB 10.6+ and MySQL/Percona 8.0+ don't have it.

        ## JSON and GEOMETRY

        | Type | MySQL and Percona | MariaDB |
        |---|---|---|
        | `JSON` | Error 3152 on every version | 10.2.6 has no JSON type (error 1064 at CREATE TABLE). 10.2.7 thru 10.3 treat it as `LONGTEXT` and give error 1170. 10.4+ index it at 768 with warning 1071 |
        | `GEOMETRY` | Error 1170 on 5.7. On 8.0+ error 1252 "All parts of a SPATIAL index must be NOT NULL" | Error 1170 thru 10.3. 10.4+ index it at 3072 with warning 1071 |

        ## Full Results

        Every case on every server is in [index-rules-matrix-data.md](data/index-rules-matrix-data.md):
        each server's InnoDB settings, then one table per question with the prefix SHOW INDEX
        reported, the error and warning text, and the EXPLAIN and SHOW CREATE TABLE lines.

        ## Regenerating This Report

        This page and its data file are generated, so change them in the script and not by hand.
        The sections above are in the heredocs in .github/scripts/index-rules-merge.php.

            gh workflow run index-rules-matrix.yml   # wait for the run to finish, then:
            gh run download --dir probes             # pick the latest Index Rules Matrix run
            php .github/scripts/index-rules-merge.php probes/*/index-probe-*.json > docs/internal/index-rules-matrix.md
            php .github/scripts/index-rules-merge.php --data probes/*/index-probe-*.json > docs/internal/data/index-rules-matrix-data.md

        __FINDINGS__;
    exit;
}

echo "# Index Rules Matrix: Data\n\n";
echo "The results behind [index-rules-matrix.md](../index-rules-matrix.md): every case on every server. ";
echo "`OK Sub_part=N` means CREATE INDEX succeeded and SHOW INDEX reports that prefix; `Sub_part=NULL` is a whole-column index. ";
echo "`warn` lists the warning codes a successful CREATE INDEX raised. `ERR n` is the MySQL error code, and the messages are under each table.\n\n";
echo "*$generated. Generated file: to change it, see [Regenerating This Report](../index-rules-matrix.md#regenerating-this-report).*\n\n";

//
// Server settings
//
$settingNames = array_keys(reset($reports)['identity']);
echo "## Server Settings\n\n| server | " . implode(' | ', $settingNames) . " |\n|---|" . str_repeat('---|', count($settingNames)) . "\n";
foreach ($reports as $server => $report) {
    echo "| $server | " . implode(' | ', array_map(fn($k) => mdValue((string)($report['identity'][$k] ?? '(missing)')), $settingNames)) . " |\n";
}
echo "\n";

//
// One table per question
//
$questionTitles = [];
foreach ($reports as $report) {
    foreach (array_keys($report['questions']) as $title) {
        $questionTitles[$title] = true;
    }
}

foreach (array_keys($questionTitles) as $title) {
    // Column order: cases in the order the probe ran them, union across servers
    $caseNames = [];
    foreach ($reports as $report) {
        foreach (array_keys($report['questions'][$title] ?? []) as $case) {
            $caseNames[$case] = true;
        }
    }
    $caseNames = array_keys($caseNames);

    echo "## $title\n\n| server | " . implode(' | ', $caseNames) . " |\n|---|" . str_repeat('---|', count($caseNames)) . "\n";
    $messages = []; // "code: message" => [servers]
    $byCase   = []; // case => cell => [servers]
    foreach ($reports as $server => $report) {
        $cells = [];
        foreach ($caseNames as $case) {
            $r       = $report['questions'][$title][$case] ?? null;
            $cells[] = cell($r);
            if ($r !== null) {
                $byCase[$case][cell($r)][] = $server;
            }
            if ($r !== null && !$r['ok']) {
                $messages["$r[code]: $r[error]"][] = $server;
            }
            foreach ($r['warnings'] ?? [] as $w) {
                $messages["warning $w"][] = $server;
            }
        }
        echo "| $server | " . implode(' | ', $cells) . " |\n";
    }
    echo "\n";

    // Agreement summary per column
    $families = serverFamilies(array_keys($reports));
    foreach ($caseNames as $case) {
        $groups = $byCase[$case];
        $ran    = count(array_merge(...array_values($groups)));
        $scope  = $ran === count($reports) ? 'every server' : "every server that ran it ($ran)";
        if (count($groups) === 1) {
            echo "- **$case**: $scope: " . array_key_first($groups) . "\n";
            continue;
        }
        $parts = [];
        foreach ($groups as $value => $servers) {
            $parts[] = "$value (" . (serverGroupLabel($servers, $families) ?? implode(', ', $servers)) . ")";
        }
        echo "- **$case**: SPLIT: " . implode('; ', $parts) . "\n";
    }
    echo "\n";

    if ($messages) {
        echo "Messages:\n\n";
        foreach ($messages as $message => $servers) {
            $servers = array_values(array_unique($servers));
            $who     = count($servers) === count($reports) ? 'all' : (serverGroupLabel($servers, $families) ?? implode(', ', $servers));
            echo "- " . mdValue($message) . " ($who)\n";
        }
        echo "\n";
    }

    // Notes (EXPLAIN detail), grouped by identical text
    $notes = [];
    foreach ($reports as $server => $report) {
        foreach ($caseNames as $case) {
            $note = $report['questions'][$title][$case]['note'] ?? null;
            if ($note !== null && isset($report['questions'][$title][$case]['sub_part'])) {
                $notes[$case][$note][] = $server;
            }
        }
    }
    if ($notes) {
        echo "Notes:\n\n";
        foreach ($notes as $case => $byNote) {
            foreach ($byNote as $note => $servers) {
                $who = count($servers) === count($reports) ? 'all' : (serverGroupLabel($servers, $families) ?? implode(', ', $servers));
                echo "- $case: " . mdValue($note) . " ($who)\n";
            }
        }
        echo "\n";
    }

    // SHOW CREATE TABLE lines, only where servers print them differently
    $createLines = [];
    foreach ($reports as $server => $report) {
        foreach ($caseNames as $case) {
            $line = $report['questions'][$title][$case]['show_create'] ?? null;
            if ($line !== null) {
                $createLines[$case][$line][] = $server;
            }
        }
    }
    $splitLines = array_filter($createLines, fn($byLine) => count($byLine) > 1);
    if ($splitLines) {
        echo "SHOW CREATE TABLE differs:\n\n";
        foreach ($splitLines as $case => $byLine) {
            foreach ($byLine as $line => $servers) {
                echo "- $case: " . mdValue($line) . " (" . (serverGroupLabel($servers, $families) ?? implode(', ', $servers)) . ")\n";
            }
        }
        echo "\n";
    }
}
