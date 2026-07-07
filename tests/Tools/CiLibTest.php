<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Tools;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tools/ci-lib.php';

/**
 * Tests for the helpers shared by the CI reporting tools (tools/ci-lib.php).
 */
class CiLibTest extends TestCase
{
    public function testDatabaseSortKeyOrdersVendorsThenVersions(): void
    {
        $servers = ['unlabeled', 'percona/percona-server:5.7', 'mariadb:10.11', 'mariadb:10.2', 'mysql:8.0', 'mysql:5.7'];
        usort($servers, fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));

        $this->assertSame(
            ['mysql:5.7', 'mysql:8.0', 'mariadb:10.2', 'mariadb:10.11', 'percona/percona-server:5.7', 'unlabeled'],
            $servers,
        );
    }

    public function testDatabaseSortKeyLengthIsFixed(): void
    {
        // PHP's array <=> compares element count before contents, so every key must
        // have the same length or labels without ':' sort first instead of last
        $this->assertCount(4, databaseSortKey('unlabeled'));
        $this->assertCount(4, databaseSortKey('mysql:8.0'));
        $this->assertCount(4, databaseSortKey('mysql:8.0.42.1'));
    }

    public function testServerFamiliesBuildsVendorAndThresholdSets(): void
    {
        $servers  = ['mysql:5.7', 'mysql:8.0', 'mariadb:10.2', 'mariadb:10.11', 'mariadb:11.4', 'percona/percona-server:5.7'];
        $families = serverFamilies($servers);

        $this->assertSame(['mysql:5.7', 'mysql:8.0', 'percona/percona-server:5.7'], $families['all MySQL/Percona']);
        $this->assertSame(['mysql:5.7', 'percona/percona-server:5.7'],              $families['MySQL/Percona 5.7']);
        $this->assertSame(['mariadb:10.11', 'mariadb:11.4'],                        $families['MariaDB 10.11+']);
        $this->assertSame(['mariadb:10.2', 'mariadb:10.11'],                        $families['MariaDB thru 10.11']);
    }

    public function testServerGroupLabelMatchesFamiliesAndPairs(): void
    {
        $servers  = ['mysql:5.7', 'mysql:8.0', 'mysql:8.4', 'mariadb:10.2', 'mariadb:10.11', 'mariadb:11.4', 'percona/percona-server:5.7', 'percona/percona-server:8.0'];
        $families = serverFamilies($servers);

        $this->assertSame('all MariaDB', serverGroupLabel(['mariadb:11.4', 'mariadb:10.2', 'mariadb:10.11'], $families));
        $this->assertSame(
            'MySQL/Percona 8.0+ and MariaDB 10.11+',
            serverGroupLabel(['mysql:8.0', 'mysql:8.4', 'percona/percona-server:8.0', 'mariadb:10.11', 'mariadb:11.4'], $families),
        );
        $this->assertNull(serverGroupLabel(['mysql:5.7', 'mariadb:10.2'], $families), 'odd groupings fall back to explicit lists');
    }

    public function testMdValueEscapesTableBreakers(): void
    {
        $this->assertSame('(empty string)', mdValue(''));
        $this->assertSame('`a\|b`', mdValue('a|b'));
        $this->assertSame('`a\nb`', mdValue("a\nb"));
        $this->assertSame('`` `age` >= 0 ``', mdValue('`age` >= 0'));
    }
}
