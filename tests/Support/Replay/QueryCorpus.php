<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support\Replay;

use mysqli_sql_exception;

/**
 * Collects recorded MySQL traffic during a live run and writes it to a corpus file
 * in the array shape MysqliWrapperReplay takes (see that class for the format).
 * MysqliWrapperRecorder fills it; ReplayHarness saves it at shutdown.
 */
final class QueryCorpus
{
    /** Connect-time facts (server_info) plus record-time context for humans */
    public array $meta = [];

    /** sql => list of outcome arrays; type is 'rows', 'ok', or 'error' */
    public array $queries = [];

    /** sql => list of next_result() sequences, one per multi_query() call */
    public array $multi = [];

    public function addOutcome(string $sql, array $outcome): void
    {
        $this->queries[$sql][] = $outcome;
    }

    public function addMulti(string $sql, array $sequence): void
    {
        $this->multi[$sql][] = $sequence;
    }

    public static function errorArray(mysqli_sql_exception $e): array
    {
        return ['errno' => $e->getCode(), 'sqlstate' => $e->getSqlState(), 'message' => $e->getMessage()];
    }

    /** The corpus in the array shape MysqliWrapperReplay's constructor takes */
    public function toArray(): array
    {
        return ['meta' => $this->meta, 'queries' => $this->queries, 'multi' => $this->multi];
    }

    public function save(string $path): void
    {
        $header = "<?php\n// Recorded MySQL traffic for replay mode - regenerate with: ZENDB_QUERY_MODE=record vendor/bin/phpunit\nreturn ";
        file_put_contents($path, $header . var_export($this->toArray(), true) . ";\n");
    }
}
