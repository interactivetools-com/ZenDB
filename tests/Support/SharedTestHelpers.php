<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support;

/**
 * Test helpers shared byte-for-byte across the library repos: SmartArray,
 * SmartString, and ZenDB each carry a copy of this file, and only the
 * namespace line differs. Edit every copy or none - the release checklist
 * in the docs repo (open-source/repo-standards.md) compares the three.
 * Repo-specific helpers (per-library assertions, message formats, level
 * masks) belong in the repo's own base test case, not here.
 */
trait SharedTestHelpers
{
    //region Output and Error Capture

    /**
     * Run $fn capturing echoed output. Returns [result, output].
     *
     * @return array{0: mixed, 1: string}
     */
    protected function captureOutput(callable $fn): array
    {
        ob_start();
        try {
            $result = $fn();
        } finally {
            $output = ob_get_clean();
        }
        return [$result, $output];
    }

    /**
     * Run $fn with an error handler registered for every level, collecting the messages
     * whose level is in $collectLevels and passing every other level to the handler
     * PHPUnit installed. Returns [result, messages].
     *
     * Registering for every level is what makes native E_WARNING and E_DEPRECATED
     * visible: PHP sends a level outside a handler's mask to its own internal handler,
     * not to the handler this one replaced, so a mask narrowed to $collectLevels hides
     * the rest from this helper and from PHPUnit both. Suppressed errors are collected
     * like any other, which is what the libraries' @trigger_error deprecations need.
     *
     * @return array{0: mixed, 1: string[]}
     */
    protected function captureErrors(callable $fn, int $collectLevels): array
    {
        $messages = [];
        $previous = set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$messages, &$previous, $collectLevels): bool {
            if (($errno & $collectLevels) !== 0) {
                $messages[] = $errstr;
                return true;
            }
            return $previous !== null && $previous($errno, $errstr, $errfile, $errline) === true;
        });
        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }
        return [$result, $messages];
    }

    /**
     * Run a command in its own process. Returns [stdout, stderr, exit code].
     *
     * @return array{0: string, 1: string, 2: int}
     */
    protected function runCommand(array $command): array
    {
        $pipes   = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process, 'could not start: ' . implode(' ', $command));

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, $stderr, proc_close($process)];
    }

    //endregion
    //region Web Requests (php -S)

    /**
     * Fetch one URL from $docRoot served by PHP's built-in server, returning
     * [responseHeaders, body].
     *
     * The built-in server is the only place a suite sees what a real response
     * looks like: header() writes nowhere under CLI, and the SAPI is 'cli'
     * everywhere else, so web-only branches are unreachable too.
     *
     *     $bin = dirname(__DIR__) . '/Support/bin';
     *     [$headers, $body] = $this->requestViaBuiltInServer($bin, 'xmp-breakout.php');
     *     [$headers, $body] = $this->requestViaBuiltInServer($bin, 'empty-guard.php?method=or404', ['ignore_errors' => true]);
     *
     * @param string $docRoot Directory php -S serves
     * @param string $pathAndQuery Script name plus any query string, relative to $docRoot
     * @param array<string, mixed> $httpOptions Extra http stream-context options
     * @return array{0: string[], 1: string}
     */
    protected function requestViaBuiltInServer(string $docRoot, string $pathAndQuery, array $httpOptions = []): array
    {
        // find a free port, then hand it to php -S (it can't pick its own)
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket, 'could not find a free port');
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $pipes  = [];
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($server, 'could not start php -S');

        try {
            $context = stream_context_create(['http' => ['timeout' => 1, ...$httpOptions]]);
            $url     = "http://127.0.0.1:$port/$pathAndQuery";
            $body    = false;
            $headers = [];
            for ($attempt = 0; $attempt < 50 && $body === false; $attempt++) {
                if ($attempt > 0) {
                    usleep(100_000); // the server is usually up on the first try
                }
                $body    = @file_get_contents($url, false, $context);
                $headers = $http_response_header ?? [];
            }
            $this->assertIsString($body, 'no response from php -S after 5 seconds');
            return [$headers, $body];
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    //endregion
}
