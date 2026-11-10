<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\Connection;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli;

/**
 * Tests that connect()'s handshake charset plus its SET NAMES statement produce the
 * same session state a plain set_charset('utf8mb4') connection gets, on whatever
 * server the suite runs against.
 *
 * @covers \Itools\ZenDB\Connection::connect
 */
class ConnectCharsetTest extends BaseTestCase
{
    public function testSessionStateMatchesSetCharsetRoute(): void
    {
        $stateSql = "SELECT @@character_set_client, @@character_set_connection, @@character_set_results, @@collation_connection";

        $conn         = self::createDefaultConnection();
        $zendbCharset = $conn->mysqli->character_set_name();
        $zendbState   = $conn->mysqli->query($stateSql)->fetch_row();

        // Reference: plain mysqli + set_charset('utf8mb4')
        $reference = new mysqli(
            self::$configDefaults['hostname'],
            self::$configDefaults['username'],
            self::$configDefaults['password'],
            self::$configDefaults['database'],
        );
        $reference->set_charset('utf8mb4');
        $referenceCharset = $reference->character_set_name();
        $referenceState   = $reference->query($stateSql)->fetch_row();
        $reference->close();

        $this->assertSame('utf8mb4', $zendbCharset);
        $this->assertSame($referenceCharset, $zendbCharset);
        $this->assertSame($referenceState, $zendbState);
    }

    public function testServerOnAnotherCharsetAfterLoginEndsOnUtf8mb4(): void
    {
        self::requiresLiveMysql();

        // A server with a charset in init_connect, or one started with skip-character-set-client-handshake,
        // has the session on its own charset when login returns, while the PHP side is utf8mb4.
        // This wrapper gives any server that state: it sends SET NAMES latin1 right after login
        $factory = Connection::$mysqliWrapperFactory;
        Connection::$mysqliWrapperFactory = static fn(?callable $queryLogger): MysqliWrapper => new class($queryLogger) extends MysqliWrapper {
            public function real_connect(?string $hostname = null, ?string $username = null, ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null, int $flags = 0): bool
            {
                $connected = parent::real_connect($hostname, $username, $password, $database, $port, $socket, $flags);
                parent::real_query("SET NAMES latin1");
                return $connected;
            }
        };
        try {
            $conn = new Connection(self::$configDefaults);
        } finally {
            Connection::$mysqliWrapperFactory = $factory;
        }

        $this->assertSame('utf8mb4', $conn->mysqli->character_set_name());
        $this->assertSame(
            ['utf8mb4', 'utf8mb4', 'utf8mb4'],
            $conn->mysqli->query("SELECT @@character_set_client, @@character_set_connection, @@character_set_results")->fetch_row(),
        );
    }

    public function testConnectSendsOneStatement(): void
    {
        self::requiresLiveMysql();

        $conn = new Connection(self::$configDefaults);

        // Questions counts every statement this session has sent, including the SHOW that reads it
        $questions = (int)$conn->mysqli->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];
        $this->assertSame(2, $questions, "connect() sends one SET statement; the second is this SHOW");
    }

    public function testPooledReuseRestoresCharset(): void
    {
        self::requiresLiveMysql();

        if (!ini_get('mysqli.allow_persistent')) {
            $this->markTestSkipped('mysqli.allow_persistent is off');
        }

        // Pool reuse goes through COM_CHANGE_USER, which carries the previous session's
        // charset instead of honoring the handshake option: dirty a pooled slot with
        // latin1, then a ZenDB connect on the same slot must come back utf8mb4
        $dirty = new mysqli(
            'p:' . self::$configDefaults['hostname'],
            self::$configDefaults['username'],
            self::$configDefaults['password'],
            self::$configDefaults['database'],
        );
        $dirty->set_charset('latin1');
        $dirtyThreadId = $dirty->thread_id;
        $dirty->close();

        $conn = self::createDefaultConnection(['hostname' => 'p:' . self::$configDefaults['hostname']]);

        // Same server thread = same pooled slot; a fresh slot would be utf8mb4
        // from the handshake alone and prove nothing about COM_CHANGE_USER
        $this->assertSame($dirtyThreadId, $conn->mysqli->thread_id, "Pool must hand back the dirtied slot for this test to mean anything");
        $this->assertSame('utf8mb4', $conn->mysqli->character_set_name());
        $this->assertSame('utf8mb4', $conn->mysqli->query("SELECT @@character_set_client")->fetch_row()[0]);

        // Leave a clean default connection for later test classes
        self::createDefaultConnection();
    }
}
