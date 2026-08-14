<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support\Replay;

use Itools\ZenDB\MysqliWrapper;
use RuntimeException;
use mysqli_result;
use mysqli_sql_exception;

/**
 * MysqliWrapper that runs against a live server and records every query's outcome
 * (rows and field metadata, insert_id, affected_rows, errors) into a QueryCorpus
 * for MysqliWrapperReplay to serve back later with no server.
 *
 * Result sets are captured with fetch_all() and rewound with data_seek(0) before
 * returning, so callers consume the untouched native mysqli_result.
 */
final class MysqliWrapperRecorder extends MysqliWrapper
{
    private QueryCorpus $corpus;

    /** next_result() returns for the in-progress multi_query(), null when none */
    private ?array $multiSequence = null;
    private string $multiSql = '';

    public function __construct(QueryCorpus $corpus, ?callable $queryLogger = null)
    {
        $this->corpus = $corpus;
        parent::__construct($queryLogger);
    }

    /** @noinspection PhpFullyQualifiedNameUsageInspection */
    public function real_connect(
        #[\SensitiveParameter] ?string $hostname = null,
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        ?string                        $database = null,
        ?int                           $port = null,
        ?string                        $socket = null,
        int                            $flags = 0,
    ): bool {
        $result = parent::real_connect($hostname, $username, $password, $database, $port, $socket, $flags);

        // First connect wins; later reconnects hit the same server
        $this->corpus->meta += [
            'server_info' => $this->server_info,
            'recorded_at' => date('Y-m-d H:i:s'),
            'php'         => PHP_VERSION,
        ];

        return $result;
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        if ($result_mode !== MYSQLI_STORE_RESULT) {
            throw new RuntimeException("Recording only supports MYSQLI_STORE_RESULT (got mode $result_mode)");
        }

        try {
            $result = parent::query($query);
        } catch (mysqli_sql_exception $e) {
            $this->corpus->addOutcome($query, ['type' => 'error'] + QueryCorpus::errorArray($e));
            throw $e;
        }

        $insertId     = $this->insert_id;
        $affectedRows = $this->affected_rows;

        if ($result instanceof mysqli_result) {
            $this->corpus->addOutcome($query, [
                'type'          => 'rows',
                'fields'        => array_map(static fn(object $f): array => (array)$f, $result->fetch_fields()),
                'rows'          => $result->fetch_all(MYSQLI_NUM),
                'insert_id'     => $insertId,
                'affected_rows' => $affectedRows,
            ]);
            $result->data_seek(0);   // rewind so the caller fetches the untouched result
            return $result;
        }

        $this->corpus->addOutcome($query, ['type' => 'ok', 'insert_id' => $insertId, 'affected_rows' => $affectedRows]);
        return $result;
    }

    public function multi_query(string $query): bool
    {
        try {
            $result = parent::multi_query($query);
        } catch (mysqli_sql_exception $e) {
            $this->corpus->addMulti($query, [['throw' => QueryCorpus::errorArray($e)]]);
            throw $e;
        }

        $this->multiSql      = $query;
        $this->multiSequence = [];

        return $result;
    }

    public function next_result(): bool
    {
        try {
            $result = parent::next_result();
        } catch (mysqli_sql_exception $e) {
            if ($this->multiSequence !== null) {
                $this->multiSequence[] = ['error' => QueryCorpus::errorArray($e)];
                $this->finalizeMulti();
            }
            throw $e;
        }

        if ($this->multiSequence !== null) {
            $this->multiSequence[] = $result;
            if (!$result) {
                $this->finalizeMulti();
            }
        }

        return $result;
    }

    private function finalizeMulti(): void
    {
        $this->corpus->addMulti($this->multiSql, $this->multiSequence);
        $this->multiSequence = null;
    }
}
