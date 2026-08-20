<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\Tests\BaseTestCase;
use mysqli;

/**
 * Tests that connect()'s handshake charset plus the version-gated set_charset call
 * land the exact session state a plain set_charset('utf8mb4') connection gets,
 * on whatever server the suite runs against.
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

        // Reference route: plain mysqli + set_charset, the way connect() worked for every server
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
