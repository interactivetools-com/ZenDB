<?php
declare(strict_types=1);

/**
 * Shared helpers for the CI reporting scripts: ci-timing-summary.php,
 * db-behavior-merge.php, and db-behavior-probe.php load this with require and
 * have no other dependencies.
 */

/**
 * Sort key that groups servers by vendor (mysql, mariadb, percona) then version,
 * matching the CI matrix order. Compare keys with <=>.
 *
 *     usort($servers, fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));
 *
 * PHP's array <=> compares element count before contents, so the version is padded
 * to a fixed three parts; without that, "unlabeled" ([4, 0]) would sort before
 * "mysql:5.7" ([1, 5, 7]) on length alone.
 */
function databaseSortKey(string $database): array
{
    [$vendor, $version] = explode(':', $database, 2) + [1 => '0'];
    $vendorRank = match ($vendor) {
        'mysql'                  => 1,
        'mariadb'                => 2,
        'percona/percona-server' => 3,
        default                  => 4,
    };
    $versionParts = array_pad(array_slice(array_map('intval', explode('.', $version)), 0, 3), 3, 0);
    return [$vendorRank, ...$versionParts];
}

/**
 * Sort a server list by vendor then version, matching the CI matrix order.
 */
function sortedServerList(array $servers): array
{
    usort($servers, fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));
    return $servers;
}

/**
 * Build named server sets from the servers present, so reports can print a label
 * instead of a long server list when a probe's answer splits along one.
 *
 * MySQL and Percona count as one family because Percona is a rebuild of the same
 * MySQL version and has matched it on every probe so far.
 *
 *     serverFamilies(['mysql:5.7', 'mysql:8.0', 'percona/percona-server:5.7', ...])
 *     // ['all MySQL/Percona' => [...], 'MySQL/Percona 5.7' => [...], 'MySQL/Percona 8.0+' => [...], ...]
 *
 * @return array<string, list<string>> label => server list, sorted by matrix order
 */
function serverFamilies(array $servers): array
{
    $versionsByFamily = [];
    foreach ($servers as $server) {
        [$vendor, $version] = explode(':', $server, 2) + [1 => ''];
        $family = match ($vendor) {
            'mysql', 'percona/percona-server' => 'MySQL/Percona',
            'mariadb'                         => 'MariaDB',
            default                           => null,
        };
        if ($family !== null) {
            $versionsByFamily[$family][$server] = $version;
        }
    }

    $families = [];
    foreach ($versionsByFamily as $family => $versionByServer) {
        if (count($versionByServer) < 2) {
            continue;
        }
        $families["all $family"] = sortedServerList(array_keys($versionByServer));

        $cuts = array_unique(array_values($versionByServer));
        usort($cuts, 'version_compare');
        foreach ($cuts as $i => $cut) {
            $atOrAbove = array_keys(array_filter($versionByServer, fn($v) => version_compare($v, $cut, '>=')));
            $atOrBelow = array_keys(array_filter($versionByServer, fn($v) => version_compare($v, $cut, '<=')));
            if ($i > 0 && count($atOrAbove) >= 2) {
                $families["$family $cut+"] = sortedServerList($atOrAbove);
            }
            if ($i < count($cuts) - 1 && count($atOrBelow) >= 2) {
                $label = $i === 0 ? "$family $cut" : "$family thru $cut";
                $families[$label] = sortedServerList($atOrBelow);
            }
        }
    }
    return $families;
}

/**
 * Match a server group to a family label, or a "MySQL/Percona X and MariaDB Y"
 * pair of labels. Returns null when the group doesn't line up with any family,
 * so the caller can fall back to listing the servers.
 */
function serverGroupLabel(array $servers, array $families): ?string
{
    $sorted = sortedServerList($servers);
    foreach ($families as $label => $set) {
        if ($set === $sorted) {
            return $label;
        }
    }
    foreach ($families as $mysqlLabel => $mysqlSet) {
        if (str_contains($mysqlLabel, 'MariaDB')) {
            continue;
        }
        foreach ($families as $mariadbLabel => $mariadbSet) {
            if (!str_contains($mariadbLabel, 'MariaDB')) {
                continue;
            }
            if (sortedServerList([...$mysqlSet, ...$mariadbSet]) === $sorted) {
                return "$mysqlLabel and $mariadbLabel";
            }
        }
    }
    return null;
}

/**
 * Backtick-wrap a value for a markdown table cell, escaping characters that would
 * break the table layout.
 */
function mdValue(string $value): string
{
    if ($value === '') {
        return '(empty string)';
    }
    $value = str_replace(['|', "\r", "\n"], ['\|', '\r', '\n'], $value);

    // Values with backticks need a double-backtick code span: `` `age` >= 0 ``
    return str_contains($value, '`') ? "`` $value ``" : "`$value`";
}
