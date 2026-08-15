<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support\Replay;

use Itools\ZenDB\Connection;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\MysqliWrapperReplay;
use RuntimeException;

/**
 * Wires recording and replay into the test suite via Connection's factory seam.
 *
 * Usage (from tests/bootstrap.php, driven by environment variables):
 *
 *     ZENDB_QUERY_MODE=record vendor/bin/phpunit   # run live, write the corpus
 *     ZENDB_QUERY_MODE=replay vendor/bin/phpunit   # serve the corpus, no MySQL
 *
 * ZENDB_QUERY_CORPUS overrides the corpus path (default: tests/Support/Replay/corpus.php).
 * Outcomes file under the test that ran them (see ReplayScope), so replay runs work in
 * any test order. Tests that need a live server (raw mysqli connections, wrapper
 * internals) skip in replay mode by calling BaseTestCase::requiresLiveMysql() first thing.
 */
final class ReplayHarness
{
    public static function initFromEnv(): void
    {
        $mode = getenv('ZENDB_QUERY_MODE') ?: '';
        if ($mode === '') {
            return;
        }
        $path = getenv('ZENDB_QUERY_CORPUS') ?: __DIR__ . '/corpus.php';
        match ($mode) {
            'record' => self::record($path),
            'replay' => self::replay($path),
            default  => throw new RuntimeException("ZENDB_QUERY_MODE must be 'record' or 'replay', got '$mode'"),
        };
    }

    /** Route all connections through a recording wrapper; the corpus saves at shutdown */
    public static function record(string $corpusPath): void
    {
        $corpus = new QueryCorpus();
        Connection::$mysqliWrapperFactory = static fn(?callable $queryLogger): MysqliWrapper => new MysqliWrapperRecorder($corpus, $queryLogger);
        register_shutdown_function(static fn() => $corpus->save($corpusPath));
    }

    /** Route all connections through a replay wrapper serving the saved corpus */
    public static function replay(string $corpusPath): void
    {
        if (!is_file($corpusPath)) {
            throw new RuntimeException("Replay corpus not found: $corpusPath\nRecord one first with: ZENDB_QUERY_MODE=record vendor/bin/phpunit");
        }
        $corpus = require $corpusPath;
        if (!isset($corpus['scopes'])) {
            throw new RuntimeException("Replay corpus predates per-test scoping: $corpusPath\nRe-record it with: ZENDB_QUERY_MODE=record vendor/bin/phpunit");
        }
        Connection::$mysqliWrapperFactory = static fn(?callable $queryLogger): MysqliWrapperReplay => new MysqliWrapperReplayScoped($corpus, $queryLogger);
    }
}
