<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\Server;
use Itools\ZenDB\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ServerTest extends BaseTestCase
{
    public static function versionStringProvider(): array
    {
        return [
            // server_info from every server in docs/db-behavior-report.md (2026-07-07 run)
            ['5.7.44',                                     '5.7.44'],   // mysql:5.7
            ['8.0.46',                                     '8.0.46'],   // mysql:8.0
            ['8.4.10',                                     '8.4.10'],   // mysql:8.4
            ['9.6.0',                                      '9.6.0'],    // mysql:9.6
            ['9.7.1',                                      '9.7.1'],    // mysql:9.7
            ['10.2.44-MariaDB-1:10.2.44+maria~bionic',     '10.2.44'],  // mariadb:10.2
            ['10.3.39-MariaDB-1:10.3.39+maria~ubu2004',    '10.3.39'],  // mariadb:10.3
            ['10.4.34-MariaDB-1:10.4.34+maria~ubu2004',    '10.4.34'],  // mariadb:10.4
            ['10.5.29-MariaDB-ubu2004',                    '10.5.29'],  // mariadb:10.5
            ['10.6.27-MariaDB-ubu2204',                    '10.6.27'],  // mariadb:10.6
            ['10.11.18-MariaDB-ubu2204',                   '10.11.18'], // mariadb:10.11
            ['11.4.12-MariaDB-ubu2404',                    '11.4.12'],  // mariadb:11.4
            ['11.8.8-MariaDB-ubu2404',                     '11.8.8'],   // mariadb:11.8
            ['12.3.2-MariaDB-ubu2404',                     '12.3.2'],   // mariadb:12.3
            ['5.7.44-48',                                  '5.7.44'],   // percona:5.7
            ['8.0.46-37',                                  '8.0.46'],   // percona:8.0
            ['8.4.10-10',                                  '8.4.10'],   // percona:8.4

            // Not probeable in CI, seen in the wild
            ['5.5.5-10.6.5-MariaDB-1:10.6.5+maria~focal',  '10.6.5'],   // MariaDB 10.x on PHP <= 8.1.2, exact string from php-src GH-7972
            ['8.0.mysql_aurora.3.05.2',                    '8.0'],      // AWS Aurora MySQL v3 engine version format
            ['11.3.2-MariaDB',                             '11.3.2'],   // MariaDB 11.x, no suffix (local Windows install)
        ];
    }

    #[DataProvider('versionStringProvider')]
    public function testVersionParsing(string $serverInfo, string $expected): void
    {
        $server = new Server((object)['server_info' => $serverInfo]);

        $this->assertSame($expected, $server->version());
    }
}
