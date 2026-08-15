<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support\Replay;

use mysqli_sql_exception;

/**
 * Collects recorded MySQL traffic during a live run and writes it to a corpus file
 * in the array shape MysqliWrapperReplayScoped takes (see that class for the format).
 * Outcomes file under the test that ran them (ReplayScope). MysqliWrapperRecorder
 * fills it; ReplayHarness saves it at shutdown.
 */
final class QueryCorpus
{
    /** Connect-time facts (server_info) plus record-time context for humans */
    public array $meta = [];

    /** scope => ['queries' => [sql => outcomes], 'multi' => [sql => sequences]] */
    public array $scopes = [];

    public function addOutcome(string $scope, string $sql, array $outcome): void
    {
        $this->scopes[$scope]['queries'][$sql][] = $outcome;
    }

    public function addMulti(string $scope, string $sql, array $sequence): void
    {
        $this->scopes[$scope]['multi'][$sql][] = $sequence;
    }

    public static function errorArray(mysqli_sql_exception $e): array
    {
        return ['errno' => $e->getCode(), 'sqlstate' => $e->getSqlState(), 'message' => $e->getMessage()];
    }

    /** The corpus in the array shape MysqliWrapperReplayScoped's constructor takes */
    public function toArray(): array
    {
        return ['meta' => $this->meta, 'scopes' => $this->scopes];
    }

    public function save(string $path): void
    {
        $header = "<?php\n// Recorded MySQL traffic for replay mode - regenerate with: ZENDB_QUERY_MODE=record vendor/bin/phpunit\nreturn ";
        file_put_contents($path, $header . var_export($this->toArray(), true) . ";\n");
    }
}
