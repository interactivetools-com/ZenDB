<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use mysqli;
use stdClass;

/**
 * Identity facts about the connected database server.
 *
 * @experimental Method names and return values may change between releases.
 */
class Server
{
    public function __construct(
        /** @var mysqli|stdClass Live connection, or a stdClass with a server_info property in tests */
        public mysqli|stdClass $mysqli,
    ) {
    }

    /**
     * Numeric server version, e.g. "10.6.27" - safe for version_compare().
     *
     *     DB::$server->version();  // "10.6.27"
     *
     * Every server reports its version in four places. Example values from mariadb:10.6;
     * docs/db-behavior-report.md compares all four across all 17 supported servers:
     *
     *   $mysqli->server_info     "10.6.27-MariaDB-ubu2204"          free with the handshake, no query
     *   SELECT VERSION()         "10.6.27-MariaDB-ubu2204"          same string, costs a query (@@version too)
     *   $mysqli->server_version  100627                             int computed by PHP, see below
     *   @@version_comment        "mariadb.org binary distribution"  vendor text, no version number
     *
     * This method reads server_info and reduces it to just the version number, so callers
     * get one comparable value instead of per-vendor strings:
     *
     *   - Build and distro suffixes are dropped:  "8.0.46-37" → "8.0.46"
     *   - MariaDB 10.x prepends "5.5.5-" so old MySQL clients accept the handshake;
     *     the marker is stripped:  "5.5.5-10.6.16-MariaDB" → "10.6.16"
     *   - Aurora omits its MySQL patch level:  "8.0.mysql_aurora.3.05.2" → "8.0"
     *
     * Why not $mysqli->server_version? PHP computes that int from the same handshake
     * string, and PHP 8.1.0-8.1.2 turns MariaDB's "5.5.5-" marker into 50505 (php-src
     * GH-7972; Ubuntu 22.04 ships 8.1.2 patched but never re-versioned). Parsing
     * server_info ourselves returns the same answer on every PHP version.
     *
     * @see \Itools\ZenDB\Tests\Connection\ServerTest every server's string and its parsed version
     * @see docs/db-behavior-report.md CI probes every supported server and reports where they disagree
     */
    public function version(): string
    {
        $serverInfo = $this->mysqli->server_info;

        // mysqlnd strips MariaDB's "5.5.5-" marker from PHP 8.1.3+, so it only reaches us on PHP 8.1.0-8.1.2 (e.g. Ubuntu 22.04's stock PHP)
        // TODO-PHP82: Remove this strip; every PHP from 8.2 strips the marker before we see it
        $serverInfo = preg_replace('/^5\.5\.5-(?=\d)/', '', $serverInfo);  // "5.5.5-10.6.16-MariaDB" → "10.6.16-MariaDB"

        // The version is everything before the first character that isn't a digit or a dot
        preg_match('/^[\d.]+/', $serverInfo, $match);
        $leadingDigitsAndDots = $match[0] ?? '';                    // "8.0.46-37" → "8.0.46", "8.0.mysql_aurora.3.05.2" → "8.0."
        $versionNumber        = rtrim($leadingDigitsAndDots, '.');  // drop Aurora's trailing dot: "8.0." → "8.0"

        return $versionNumber;
    }
}
