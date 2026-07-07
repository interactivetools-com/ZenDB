<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\DB;
use Itools\ZenDB\Server;
use Itools\ZenDB\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

class ServerTest extends BaseTestCase
{
    public static function versionStringProvider(): array
    {
        return [
            // server_info from every server in tools/db-behavior-report.md (2026-07-07 run)
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

    public static function vendorProvider(): array
    {
        return [
            // server_info, @@version_comment (null = must answer from the handshake alone), expected vendor
            ['10.6.27-MariaDB-ubu2204',                    null,                                                  'mariadb'],
            ['5.5.5-10.6.5-MariaDB-1:10.6.5+maria~focal',  null,                                                  'mariadb'],
            ['5.7.44',                                     'MySQL Community Server (GPL)',                        'mysql'],
            ['8.0.46',                                     'MySQL Community Server - GPL',                        'mysql'],
            ['5.7.44-48',                                  'Percona Server (GPL), Release 48, Revision 497f936a373', 'percona'],
            ['8.0.46-37',                                  'Percona Server (GPL), Release 37, Revision 39e2b60e', 'percona'],
            ['8.4.10-10',                                  'Percona Server (GPL), Release 10, Revision d76e81f4', 'percona'],
            ['8.0.mysql_aurora.3.05.2',                    null,                                                  'aurora'],
            ['9.9.9-SomeFutureFork',                       'SomeFutureFork Server (GPL)',                         'mysql'],   // unrecognized servers fall back to mysql
        ];
    }

    #[DataProvider('vendorProvider')]
    public function testVendorParsing(string $serverInfo, ?string $versionComment, string $expected): void
    {
        $server = new Server(new FakeMysqli($serverInfo, $versionComment));

        $this->assertSame($expected, $server->vendor());
    }

    public static function vendorNameProvider(): array
    {
        return [
            // server_info, @@version_comment, @@basedir, @@datadir, expected name
            // Docker values from tools/db-behavior-report.md; hosted values are representative, not CI-probed
            ['8.0.46',                  'MySQL Community Server - GPL',                        '/usr/',                                             '/var/lib/mysql/', 'MySQL'],
            ['10.6.27-MariaDB-ubu2204', 'mariadb.org binary distribution',                     '/usr',                                              '/var/lib/mysql/', 'MariaDB'],
            ['8.0.46-37',               'Percona Server (GPL), Release 37, Revision 39e2b60e', '/usr/',                                             '/var/lib/mysql/', 'Percona'],
            ['8.0.28',                  'Source distribution',                                 '/rdsdbbin/oscar-8.0.mysql_aurora.3.04.0.0.32961.0/', '/rdsdbdata/db/', 'Amazon RDS (Aurora)'],
            ['8.0.42',                  'Source distribution',                                 '/rdsdbbin/mysql-8.0.42.R1/',                        '/rdsdbdata/db/',  'Amazon RDS (MySQL)'],
            ['10.11.9-MariaDB-log',     'managed by https://aws.amazon.com/rds/',              '/rdsdbbin/mariadb-10.11.9.R1/',                     '/rdsdbdata/db/',  'Amazon RDS (MariaDB)'],
            ['8.0.30-txsql',            'TencentDB for MySQL(TXSQL)',                          '/usr/local/mysql/',                                 '/data/mysql/',    'Tencent'],
        ];
    }

    #[DataProvider('vendorNameProvider')]
    public function testVendorNameParsing(string $serverInfo, string $versionComment, string $basedir, string $datadir, string $expected): void
    {
        $server = new Server(new FakeMysqli($serverInfo, $versionComment, $basedir, $datadir));

        $this->assertSame($expected, $server->vendorName());
    }

    public function testVendorDetectsAuroraFromBasedir(): void
    {
        // Some Aurora versions send a plain MySQL handshake; the install path is what names them
        $fakeMysqli = new FakeMysqli('8.0.28', 'Source distribution', '/rdsdbbin/oscar-8.0.mysql_aurora.3.04.0.0.32961.0/');
        $server     = new Server($fakeMysqli);

        $this->assertSame('aurora', $server->vendor());
    }

    public function testVendorIsCachedAfterFirstQuery(): void
    {
        $fakeMysqli = new FakeMysqli('8.0.46-37', 'Percona Server (GPL), Release 37, Revision 39e2b60e');
        $server     = new Server($fakeMysqli);

        $server->vendor();
        $server->vendor();
        $this->assertSame(1, $fakeMysqli->queries);
    }

    public static function sslConnectionProvider(): array
    {
        return [
            ['',                       false],  // empty Ssl_cipher = plaintext connection
            ['TLS_AES_256_GCM_SHA384', true],   // TLS 1.3 cipher name as reported by MySQL 8
        ];
    }

    #[DataProvider('sslConnectionProvider')]
    public function testIsSSLConnection(string $sslCipher, bool $expected): void
    {
        $server = new Server(new FakeMysqli('8.0.46', sslCipher: $sslCipher));

        $this->assertSame($expected, $server->isSSLConnection());
    }

    public static function sslAvailableProvider(): array
    {
        return [
            // @@have_ssl value (null = variable doesn't exist), expected
            ['YES',      true],   // MySQL/Percona thru 8.0, MariaDB 11.4+, configured production servers
            ['DISABLED', false],  // stock MariaDB thru 10.11: compiled in but no certificates
            ['NO',       false],  // compiled without TLS support
            [null,       true],   // MySQL/Percona 8.4+: variable removed, TLS always on
        ];
    }

    #[DataProvider('sslAvailableProvider')]
    public function testIsSSLAvailable(?string $haveSSL, bool $expected): void
    {
        $server = new Server(new FakeMysqli('8.0.46', haveSSL: $haveSSL));

        $this->assertSame($expected, $server->isSSLAvailable());
    }

    public function testServerIsWiredToConnectionLifecycle(): void
    {
        $db = self::createDefaultConnection();

        $this->assertInstanceOf(Server::class, DB::$server);
        $this->assertSame(DB::$server, $db->server, 'clones share the connection Server instance');
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', DB::$server->version());
        $this->assertContains(DB::$server->vendor(), ['mysql', 'mariadb', 'percona', 'aurora']);
        $this->assertFalse(DB::$server->isSSLConnection(), 'test suite connects without TLS');
        $this->assertIsBool(DB::$server->isSSLAvailable());

        DB::disconnect();
        $this->assertNull(DB::$server);
    }
}

/**
 * Fakes the mysqli members Server reads: the server_info property and the queries
 * behind vendor detection and the SSL checks. Extends stdClass to satisfy Server's
 * mysqli|stdClass type. $haveSSL null = variable doesn't exist (MySQL/Percona 8.4+).
 */
class FakeMysqli extends stdClass
{
    public int $queries = 0;

    public function __construct(
        public string $server_info,
        private ?string $versionComment = null,
        private string $basedir = '/usr/',
        private string $datadir = '/var/lib/mysql/',
        private string $sslCipher = '',
        private ?string $haveSSL = null,
    ) {
    }

    public function query(string $sql): object
    {
        $this->queries++;
        $row = match (true) {
            str_contains($sql, 'Ssl_cipher') => ['Ssl_cipher', $this->sslCipher],
            str_contains($sql, 'have_ssl')   => $this->haveSSL === null ? null : ['have_ssl', $this->haveSSL],
            default                          => $this->vendorRow($sql),
        };
        return new class ($row) {
            public function __construct(private readonly ?array $row)
            {
            }

            public function fetch_row(): ?array
            {
                return $this->row;
            }
        };
    }

    private function vendorRow(string $sql): array
    {
        if ($this->versionComment === null) {
            throw new RuntimeException("Unexpected query \"$sql\" for '$this->server_info': vendor() should answer from the handshake alone");
        }
        return [$this->versionComment, $this->basedir, $this->datadir];
    }
}
