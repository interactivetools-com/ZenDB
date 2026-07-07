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
    //region Public API

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

    /**
     * Database vendor as a lowercase token: "mysql", "mariadb", "percona", or "aurora".
     *
     *     DB::$server->vendor();  // "mariadb"
     *
     * MariaDB and Aurora name themselves in the handshake string, so detecting them is
     * free. Percona's handshake is identical to MySQL's ("8.0.46-37" vs "8.0.46"), and
     * @@version_comment is the only place it says its name - so telling MySQL from
     * Percona costs one query. The answer is cached; the query runs at most once per
     * connection. What each vendor reports:
     *
     *   $mysqli->server_info   "10.6.27-MariaDB-ubu2204"                     mariadb, free
     *   $mysqli->server_info   "8.0.mysql_aurora.3.05.2"                     aurora, free
     *   @@basedir              "/rdsdbbin/oscar-8.0.mysql_aurora.3.04.0..."  aurora, one query
     *   @@version_comment      "Percona Server (GPL), Release 37, ..."       percona, one query
     *   @@version_comment      "MySQL Community Server - GPL"                mysql, one query
     *
     * Aurora doesn't always put its name in the handshake (some versions report a plain
     * "8.0.28"), so the query also pulls @@basedir and @@datadir, where Aurora's install
     * path names it.
     *
     * Anything unrecognized reports "mysql" on purpose: every server speaking this
     * protocol is MySQL-compatible, and the MySQL branch is what callers want for it
     * anyway. Hosting is not a vendor - RDS-hosted stock MySQL reports "mysql".
     *
     * @see \Itools\ZenDB\Tests\Connection\ServerTest every vendor's strings and the token each returns
     * @see docs/db-behavior-report.md @@version_comment values for all 17 supported servers
     */
    public function vendor(): string
    {
        // Return cached answer if available
        if ($this->vendor !== null) {
            return $this->vendor;
        }

        // Free detection first: these vendors name themselves in the handshake
        $serverInfo = strtolower($this->mysqli->server_info);
        if (str_contains($serverInfo, 'mariadb')) {
            return $this->vendor = 'mariadb';
        }
        if (str_contains($serverInfo, 'aurora')) {
            return $this->vendor = 'aurora';
        }

        // MySQL and Percona send identical handshakes; only @@version_comment tells them apart
        $vendorStrings = $this->vendorStrings();

        return $this->vendor = match (true) {
            str_contains($vendorStrings, 'percona') => 'percona',  // "percona server (gpl), release 37, revision 39e2b60e | /usr/ | /var/lib/mysql/"
            str_contains($vendorStrings, 'aurora')  => 'aurora',   // "source distribution | /rdsdbbin/oscar-8.0.mysql_aurora.3.04.0.0.32961.0/ | /rdsdbdata/db/"
            default                                 => 'mysql',    // "mysql community server - gpl | /usr/ | /var/lib/mysql/"
        };
    }

    /**
     * Display name of the database product and its hosting, ready for server info pages.
     *
     *     DB::$server->vendorName();                                  // "MariaDB"
     *     DB::$server->vendorName() . " v" . DB::$server->version();  // "MariaDB v10.6.27"
     *
     * All possible names, most specific first:
     *
     *   Amazon RDS (Aurora)    Amazon's MySQL-compatible engine
     *   Amazon RDS (MariaDB)   MariaDB on RDS hosting
     *   Amazon RDS (MySQL)     stock MySQL on RDS hosting
     *   Percona
     *   Tencent                TencentDB / TXSQL
     *   MariaDB
     *   MySQL                  also anything unrecognized (see vendor())
     *
     * RDS hosting is detected from install paths: "/rdsdbbin/" (@@basedir) or
     * "/rdsdbdata/" (@@datadir). Costs the same single cached query as vendor().
     *
     * This string is for humans; code that branches on behavior should use vendor()
     * or version() instead.
     */
    public function vendorName(): string
    {
        $vendorStrings = $this->vendorStrings();
        $isAmazonRds   = str_contains($vendorStrings, '/rdsdbbin/') || str_contains($vendorStrings, '/rdsdbdata/');

        return match (true) {
            $this->vendor() === 'aurora'                   => 'Amazon RDS (Aurora)',
            $this->vendor() === 'mariadb' && $isAmazonRds  => 'Amazon RDS (MariaDB)',
            $isAmazonRds                                   => 'Amazon RDS (MySQL)',
            $this->vendor() === 'percona'                  => 'Percona',
            str_contains($vendorStrings, 'tencent')        => 'Tencent',
            $this->vendor() === 'mariadb'                  => 'MariaDB',
            default                                        => 'MySQL',
        };
    }

    //endregion
    //region Internals

    /** Live connection, or a stdClass with a server_info property in tests */
    public mysqli|stdClass $mysqli;

    /** Cached vendor() answer, so the fingerprint query runs at most once per connection */
    private ?string $vendor = null;

    /** Cached vendorStrings() answer, e.g. "mysql community server - gpl | /usr/ | /var/lib/mysql/" */
    private ?string $vendorStrings = null;

    /**
     * Lowercase "@@version_comment | @@basedir | @@datadir" for vendor pattern matching,
     * queried once per connection.
     */
    private function vendorStrings(): string
    {
        if ($this->vendorStrings === null) {
            $row = $this->mysqli->query("SELECT @@version_comment, @@basedir, @@datadir")->fetch_row();
            $this->vendorStrings = strtolower(implode(' | ', $row));
        }
        return $this->vendorStrings;
    }

    public function __construct(mysqli|stdClass $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    //endregion
}
