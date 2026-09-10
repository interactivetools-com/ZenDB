<?php
declare(strict_types=1);

/**
 * Child for WithoutStringLiteralsTest: run the literal stripper on a WHERE with a
 * million escaped quotes while PCRE JIT is off (set with -d by the spawning test).
 * Without JIT that many escapes exhaust pcre.backtrack_limit and preg gives up; the
 * stripper must then hand back the raw SQL instead of crashing on a null. Prints the
 * input length, the output length, and PCRE's last error, one per line.
 *
 *     php -d pcre.jit=0 strip-literals-no-jit.php <hostname> <username> <password> <database>
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Itools\ZenDB\DB;

[, $hostname, $username, $password, $database] = $argv;
DB::connect(['hostname' => $hostname, 'username' => $username, 'password' => $password, 'database' => $database]);

$conn   = DB::connection();
$method = new ReflectionMethod($conn, 'withoutStringLiterals');
$sql    = "name = '" . str_repeat("\\'", 1_000_000) . "' LIMIT 5";
$out    = $method->invoke($conn, $sql);

echo strlen($sql), "\n", strlen($out), "\n", preg_last_error_msg(), "\n";
