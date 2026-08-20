<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support\Replay;

use Itools\ZenDB\MysqliWrapperReplay;
use RuntimeException;

/**
 * MysqliWrapperReplay that looks queries up under ReplayScope::$current, so each test
 * replays only what it recorded (see ReplayScope). This is what makes replay runs
 * order-independent: two tests running the same SQL never consume each other's
 * outcomes, except a same-class fallback for cached metadata probes (see scopeWith()).
 * Takes the scoped corpus QueryCorpus writes:
 *
 *     [
 *         'meta'   => ['server_info' => '8.0.43'],
 *         'scopes' => [
 *             'Foo\BarTest::testBaz' => [
 *                 'queries' => ['SELECT id FROM users' => [ ...outcomes... ]],
 *                 'multi'   => ['SELECT 1; SELECT 2' => [[true, false]]],
 *             ],
 *         ],
 *     ]
 *
 * Outcome and sequence shapes are unchanged from MysqliWrapperReplay.
 */
final class MysqliWrapperReplayScoped extends MysqliWrapperReplay
{
    /** scope => ['queries' => [sql => outcomes], 'multi' => [sql => sequences]] */
    private array $scopes;

    /** Per-scope replay cursors: scope => sql => next index */
    private array $queryCursors = [];
    private array $multiCursors = [];

    public function __construct(array $corpus, ?callable $queryLogger = null)
    {
        parent::__construct(['meta' => $corpus['meta'] ?? []], $queryLogger);
        $this->scopes = $corpus['scopes'] ?? [];
    }

    protected function nextOutcome(string $sql): array
    {
        $scope    = $this->scopeWith('queries', $sql)
            ?? throw new RuntimeException("Replay corpus has no recording under '" . ReplayScope::$current . "' (or its class) for query: $sql\nRe-record with: composer test:record");
        $outcomes = $this->scopes[$scope]['queries'][$sql];
        $cursor   = $this->queryCursors[$scope][$sql] ?? 0;
        $this->queryCursors[$scope][$sql] = $cursor + 1;
        return $outcomes[$cursor] ?? $outcomes[count($outcomes) - 1];   // queue empty: last outcome keeps serving
    }

    protected function nextMulti(string $sql): array
    {
        $scope     = $this->scopeWith('multi', $sql)
            ?? throw new RuntimeException("Replay corpus has no recording under '" . ReplayScope::$current . "' (or its class) for multi_query: $sql\nRe-record with: composer test:record");
        $sequences = $this->scopes[$scope]['multi'][$sql];
        $cursor    = $this->multiCursors[$scope][$sql] ?? 0;
        $this->multiCursors[$scope][$sql] = $cursor + 1;
        return $sequences[$cursor] ?? $sequences[count($sequences) - 1];
    }

    /**
     * The scope to serve a query from: the current test when it has a recording, else
     * another scope in the same test class. The fallback covers cached metadata probes
     * (e.g. "SELECT * FROM `t` LIMIT 0"): whichever test in a class runs first issues
     * the probe and ZenDB caches the result for its siblings, so when replay order
     * differs from record order the recording lives under a sibling test.
     */
    private function scopeWith(string $section, string $sql): ?string
    {
        $scope = ReplayScope::$current;
        if (isset($this->scopes[$scope][$section][$sql])) {
            return $scope;
        }
        $class = strstr($scope, '::', true) ?: $scope;
        foreach ($this->scopes as $otherScope => $sections) {
            $sameClass = $otherScope === $class || str_starts_with((string)$otherScope, "$class::");
            if ($sameClass && isset($sections[$section][$sql])) {
                return (string)$otherScope;
            }
        }
        return null;
    }
}
