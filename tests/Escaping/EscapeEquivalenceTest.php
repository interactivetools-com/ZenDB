<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Escaping;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli_sql_exception;

require_once dirname(__DIR__, 2) . '/.github/scripts/escape-corpus.php';

/**
 * Correctness gates for the fast-escape candidates, run against the live
 * connection on every push (the full PHP x DB matrix in tests.yml re-verifies
 * the gate on every supported server for free). The same corpus feeds the
 * benchmark workflows via .github/scripts/escape-corpus.php, so the CI matrix
 * and this suite can never drift apart.
 */
class EscapeEquivalenceTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
    }

    protected function tearDown(): void
    {
        // Tests below flip charset and sql_mode; always hand the next test a
        // connection in ZenDB's guaranteed state
        DB::$mysqli->set_charset('utf8mb4');
        DB::$mysqli->query("SET sql_mode = '" . self::$configDefaults['sqlMode'] . "'");
    }

    /**
     * The whole fast path rests on real_escape_string modifying exactly these
     * 7 bytes; a server or driver that changes the set fails here first.
     */
    public function testEscapeSetCanary(): void
    {
        self::requiresLiveMysql();

        $this->assertSame(ZENDB_ESCAPE_CANARY_EXPECTED, escape_set_canary(DB::$mysqli));
    }

    /**
     * Byte-identity over the full ~107k-string corpus for the shipping
     * candidate and its backup primitive.
     */
    public function testByteIdentityOnCorpus(): void
    {
        self::requiresLiveMysql();

        $corpus = escape_corpus();
        foreach (['str_replace' => static fn(string $s): string => str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s),
                  'strtr'       => static fn(string $s): string => strtr($s, ZENDB_ESCAPE_MAP)] as $name => $escaper) {
            $res = escape_corpus_assert($escaper, DB::$mysqli, $corpus);
            $this->assertSame(0, $res['fail'],
                "$name diverged from real_escape_string: " . implode('; ', $res['samples']));
        }
    }

    /**
     * Invalid-UTF-8 and binary values (the encrypted-MEDIUMBLOB case) must
     * restore byte-identically through the fast path even where escaped BYTES
     * may legally differ from real_escape_string.
     */
    public function testRoundTripBinaryCorpus(): void
    {
        $mysqli = DB::$mysqli;
        $mysqli->query('CREATE TEMPORARY TABLE test_escape_bin (id INT PRIMARY KEY AUTO_INCREMENT, data MEDIUMBLOB NOT NULL)');

        $samples = [''];
        for ($b = 0; $b <= 0xFF; $b++) {
            $samples[] = chr($b);
        }
        foreach (["\xBF\x27", "\xBF\x5C", "\xC0\xAF", "\xED\xA0\x80", "\xF0\x9F\x98", "\xFF\xFE\xFD", "\x80\x80\x80"] as $s) {
            $samples[] = $s;
            $samples[] = "text $s text";
        }
        mt_srand(20260802);
        for ($i = 0; $i < 500; $i++) {
            $len = mt_rand(0, 64);
            $s   = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= chr(mt_rand(0, 255));
            }
            $samples[] = $s;
        }

        $sql = 'INSERT INTO test_escape_bin (data) VALUES ';
        foreach ($samples as $s) {
            $sql .= "('" . str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $s) . "'),";
        }
        $mysqli->query(substr($sql, 0, -1));

        $back = [];
        $res  = $mysqli->query('SELECT data FROM test_escape_bin ORDER BY id');
        while ($row = $res->fetch_row()) {
            $back[] = $row[0];
        }
        $this->assertSame(count($samples), count($back));
        foreach ($samples as $i => $s) {
            $this->assertSame($s, $back[$i], 'restore mismatch at sample ' . $i . ' (' . bin2hex($s) . ')');
        }
        $mysqli->query('DROP TEMPORARY TABLE test_escape_bin');
    }

    /**
     * The runtime guard must detect a NO_BACKSLASH_ESCAPES flip the moment it
     * happens (mysqlnd tracks the server status flag per statement) and recover
     * when the mode is reset.
     */
    public function testProbeCatchesSqlModeFlip(): void
    {
        self::requiresLiveMysql();

        $mysqli = DB::$mysqli;
        $this->assertTrue(escape_fast_path_ok($mysqli), 'probe must pass on a fresh default connection');

        $mysqli->query("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
        $this->assertFalse(escape_fast_path_ok($mysqli), 'probe must fail immediately after the mode flip');

        $mysqli->query("SET sql_mode = '" . self::$configDefaults['sqlMode'] . "'");
        $this->assertTrue(escape_fast_path_ok($mysqli), 'probe must recover after the mode is reset');
    }

    /**
     * The classic GBK injection (CVE-2006-2753 shape): under gbk the charset
     * check must disable the fast path, and str_replace must visibly diverge
     * from real_escape_string on the 0xBF27 shape - proof the suite would catch
     * an unsafe charset rather than quietly passing ASCII probes.
     */
    public function testGbkNegativeControl(): void
    {
        self::requiresLiveMysql();

        // Raw mysqli on purpose: MysqliWrapper::set_charset() rejects non-utf8mb4, and this
        // test needs a genuinely-gbk connection to prove the fast-path check catches one
        $mysqli = new \mysqli(
            self::$configDefaults['hostname'],
            self::$configDefaults['username'],
            self::$configDefaults['password'],
            self::$configDefaults['database'],
        );
        try {
            $mysqli->set_charset('gbk');
        } catch (mysqli_sql_exception) {
            $this->markTestSkipped('server has no gbk charset');
        }

        $this->assertFalse(escape_fast_path_ok($mysqli), 'charset check must disable the fast path under gbk');

        $inject = "\xBF\x27 OR 1=1 -- ";
        $this->assertNotSame(
            $mysqli->real_escape_string($inject),
            str_replace(ZENDB_ESCAPE_FROM, ZENDB_ESCAPE_TO, $inject),
            'under gbk the naive replacement must diverge from the charset-aware escaper',
        );
    }

    /**
     * PHP 8.6's mysqli::quote_string must equal quote-wrapped real_escape_string
     * (both route through mysqlnd's escaper) - the cross-API canary.
     */
    public function testQuoteStringIdentity(): void
    {
        $mysqli = DB::$mysqli;
        if (!method_exists($mysqli, 'quote_string')) {
            $this->markTestSkipped('mysqli::quote_string requires PHP 8.6+');
        }
        foreach (escape_corpus() as $s) {
            if ($mysqli->{'quote_string'}($s) !== "'" . $mysqli->real_escape_string($s) . "'") {
                $this->fail('quote_string mismatch on input ' . bin2hex($s));
            }
        }
        $this->assertTrue(true);
    }
}
