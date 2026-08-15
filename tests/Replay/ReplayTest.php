<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Replay;

use Itools\ZenDB\MysqliResultReplay;
use Itools\ZenDB\MysqliWrapperReplay;
use Itools\ZenDB\Tests\BaseTestCase;
use Itools\ZenDB\Tests\Support\Replay\MysqliWrapperRecorder;
use Itools\ZenDB\Tests\Support\Replay\QueryCorpus;
use RuntimeException;
use mysqli_sql_exception;

class ReplayTest extends BaseTestCase
{
    //region MysqliResultReplay

    private static function sampleResult(): MysqliResultReplay
    {
        $fields = [['name' => 'id'], ['name' => 'title']];
        $rows   = [[1, 'first'], [2, 'second'], [3, 'third']];
        return new MysqliResultReplay($fields, $rows);
    }

    public function testFetchAllAssoc(): void
    {
        $expected = [
            ['id' => 1, 'title' => 'first'],
            ['id' => 2, 'title' => 'second'],
            ['id' => 3, 'title' => 'third'],
        ];
        $this->assertSame($expected, self::sampleResult()->fetch_all(MYSQLI_ASSOC));
    }

    public function testFetchAllNumIsTheDefault(): void
    {
        $this->assertSame([[1, 'first'], [2, 'second'], [3, 'third']], self::sampleResult()->fetch_all());
    }

    public function testFieldCountAndNumRowsAreRealProperties(): void
    {
        $result = self::sampleResult();
        $this->assertSame(2, $result->field_count);
        $this->assertSame(3, $result->num_rows);
    }

    public function testFetchRowAdvancesCursorThenReturnsNull(): void
    {
        $result = self::sampleResult();
        $this->assertSame([1, 'first'], $result->fetch_row());
        $this->assertSame(['id' => 2, 'title' => 'second'], $result->fetch_assoc());
        $this->assertSame([3, 'third'], $result->fetch_row());
        $this->assertNull($result->fetch_row());
    }

    public function testDataSeek(): void
    {
        $result = self::sampleResult();
        $this->assertTrue($result->data_seek(2));
        $this->assertSame([3, 'third'], $result->fetch_row());
        $this->assertFalse($result->data_seek(3));
    }

    public function testDuplicateColumnNamesAreLastWinsInAssoc(): void
    {
        // Matches native mysqli_result for e.g. SELECT a.id, b.id
        $result = new MysqliResultReplay([['name' => 'id'], ['name' => 'id']], [[1, 2]]);
        $this->assertSame([0 => 1, 'id' => 2, 1 => 2], $result->fetch_array(MYSQLI_BOTH));
    }

    public function testFetchFieldsAndFreeAreSafe(): void
    {
        $result = self::sampleResult();
        $this->assertSame('title', $result->fetch_fields()[1]->name);
        $result->free();     // no live result behind the object; must be a safe no-op
        $this->assertSame([1, 'first'], $result->fetch_row());
    }

    //endregion
    //region MysqliWrapperReplay

    public function testReplaysRowsOkAndErrorOutcomes(): void
    {
        $wrapper = new MysqliWrapperReplay([
            'meta'    => ['server_info' => '8.0.99-test'],
            'queries' => [
                'SELECT id FROM t'          => [['type' => 'rows', 'fields' => [['name' => 'id']], 'rows' => [[7]], 'insert_id' => 0, 'affected_rows' => 1]],
                'INSERT INTO t () VALUES ()' => [['type' => 'ok', 'insert_id' => 8, 'affected_rows' => 1]],
                'SELECT bad'                => [['type' => 'error', 'errno' => 1054, 'sqlstate' => '42S22', 'message' => "Unknown column 'bad' in 'field list'"]],
            ],
        ]);

        $this->assertSame([['id' => 7]], $wrapper->query('SELECT id FROM t')->fetch_all(MYSQLI_ASSOC));

        $this->assertTrue($wrapper->query('INSERT INTO t () VALUES ()'));
        $this->assertSame(8, $wrapper->insert_id);
        $this->assertSame(1, $wrapper->affected_rows);
        $this->assertSame('8.0.99-test', $wrapper->server_info);

        try {
            $wrapper->query('SELECT bad');
            $this->fail('Expected mysqli_sql_exception');
        } catch (mysqli_sql_exception $e) {
            $this->assertSame(1054, $e->getCode());
            $this->assertSame('42S22', $e->getSqlState());
        }
    }

    public function testOutcomeQueueAdvancesThenLastOneSticks(): void
    {
        $wrapper = new MysqliWrapperReplay([
            'queries' => ['INSERT INTO t () VALUES ()' => [
                ['type' => 'ok', 'insert_id' => 1, 'affected_rows' => 1],
                ['type' => 'ok', 'insert_id' => 2, 'affected_rows' => 1],
            ]],
        ]);

        foreach ([1, 2, 2] as $expectedId) {   // queue advances, then the last outcome sticks
            $wrapper->query('INSERT INTO t () VALUES ()');
            $this->assertSame($expectedId, $wrapper->insert_id);
        }
    }

    public function testUnknownQueryThrowsWithReRecordHint(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Re-record');
        (new MysqliWrapperReplay([]))->query('SELECT missing');
    }

    public function testReplaysMultiQuerySequence(): void
    {
        $wrapper = new MysqliWrapperReplay(['multi' => ['SELECT 1; SELECT 2' => [[true, false]]]]);

        $this->assertFalse($wrapper->more_results());
        $this->assertTrue($wrapper->multi_query('SELECT 1; SELECT 2'));
        $this->assertTrue($wrapper->more_results());
        $this->assertTrue($wrapper->next_result());
        $this->assertFalse($wrapper->next_result());
        $this->assertFalse($wrapper->more_results());
        $this->assertFalse($wrapper->next_result());   // drained, keeps returning false
    }

    //endregion
    //region Record → replay round trip (needs live MySQL)

    public function testRecordThenReplayReturnsIdenticalData(): void
    {
        self::requiresLiveMysql();

        $c      = self::$configDefaults;
        $corpus = new QueryCorpus();

        // Record against the live server
        $recorder = new MysqliWrapperRecorder($corpus);
        $recorder->real_connect($c['hostname'], $c['username'], $c['password'], $c['database']);
        $recorder->set_charset('utf8mb4');
        $recorder->query("CREATE TEMPORARY TABLE replay_roundtrip (id INT AUTO_INCREMENT PRIMARY KEY, txt VARCHAR(50))");
        $recorder->query("INSERT INTO replay_roundtrip (txt) VALUES ('O\\'Brien')");
        $liveInsertId = $recorder->insert_id;
        $liveRows     = $recorder->query("SELECT id, txt FROM replay_roundtrip")->fetch_all(MYSQLI_ASSOC);
        $liveErrno    = 0;
        try {
            $recorder->query("SELECT * FROM replay_roundtrip_missing");
        } catch (mysqli_sql_exception $e) {
            $liveErrno = $e->getCode();
        }
        $recorder->close();

        // Replay from the corpus, no server involved
        $replayer = new MysqliWrapperReplay($corpus->toArray());
        $replayer->real_connect('no-such-host', 'nobody', 'wrong');
        $this->assertTrue($replayer->query("CREATE TEMPORARY TABLE replay_roundtrip (id INT AUTO_INCREMENT PRIMARY KEY, txt VARCHAR(50))"));
        $this->assertTrue($replayer->query("INSERT INTO replay_roundtrip (txt) VALUES ('O\\'Brien')"));
        $this->assertSame($liveInsertId, $replayer->insert_id);
        $this->assertSame($liveRows, $replayer->query("SELECT id, txt FROM replay_roundtrip")->fetch_all(MYSQLI_ASSOC));
        try {
            $replayer->query("SELECT * FROM replay_roundtrip_missing");
            $this->fail('Expected the recorded error to replay');
        } catch (mysqli_sql_exception $e) {
            $this->assertSame($liveErrno, $e->getCode());
        }
    }

    public function testRecorderReturnsTheRewoundNativeResult(): void
    {
        self::requiresLiveMysql();

        $c        = self::$configDefaults;
        $recorder = new MysqliWrapperRecorder(new QueryCorpus());
        $recorder->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);   // native ints, like ZenDB connections
        $recorder->real_connect($c['hostname'], $c['username'], $c['password'], $c['database']);

        // Recording fetches the rows to capture them; the caller must still see them all
        $this->assertSame([['n' => 1]], $recorder->query("SELECT 1 AS n")->fetch_all(MYSQLI_ASSOC));
        $recorder->close();
    }

    public function testCorpusSaveWritesLoadableFile(): void
    {
        $corpus       = new QueryCorpus();
        $corpus->meta = ['server_info' => '8.0.99-test'];
        $corpus->addOutcome("SELECT 'O\\'Brien'", ['type' => 'rows', 'fields' => [['name' => 'v']], 'rows' => [["O'Brien"]], 'insert_id' => 0, 'affected_rows' => 1]);
        $corpus->addMulti('SELECT 1; SELECT 2', [true, false]);

        $path = tempnam(sys_get_temp_dir(), 'zendb-corpus-');
        try {
            $corpus->save($path);
            $this->assertSame($corpus->toArray(), require $path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Pins MysqliWrapperReplay's PHP escape byte-identical to the live utf8mb4
     * real_escape_string(): every single byte 0-255, plus multi-byte UTF-8.
     */
    public function testEscapeMatchesLiveRealEscapeString(): void
    {
        self::requiresLiveMysql();

        $live   = self::createDefaultConnection()->mysqli;
        $replay = new MysqliWrapperReplay([]);

        for ($byte = 0; $byte < 256; $byte++) {
            $char = chr($byte);
            $this->assertSame($live->real_escape_string($char), $replay->real_escape_string($char), 'byte 0x' . dechex($byte));
        }

        foreach (["O'Brien", 'C:\\path\\"file"', "line1\nline2\r\ttab", "a\0b\x1ac", 'café ☕ 中文 𝔘nicode', ''] as $string) {
            $this->assertSame($live->real_escape_string($string), $replay->real_escape_string($string), $string);
        }
    }

    //endregion
}
