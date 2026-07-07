<?php
declare(strict_types=1);

/**
 * Shared helpers for the CI reporting tools: ci-timing-summary.php,
 * db-behavior-merge.php, and db-behavior-report.php load this with require and
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
