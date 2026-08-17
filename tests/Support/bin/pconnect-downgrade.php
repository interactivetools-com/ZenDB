<?php
declare(strict_types=1);

/**
 * Child for PersistentReconnectTest: connect with a p: hostname while
 * mysqli.allow_persistent=0 (set with -d by the spawning test), printing one JSON
 * line per PHP diagnostic - errno, message, and whether it was @-suppressed when
 * raised - then CONNECTED once DB::connect returns a working connection.
 *
 *     php -d mysqli.allow_persistent=0 pconnect-downgrade.php <hostname> <username> <password> <database>
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Itools\ZenDB\DB;

set_error_handler(static function (int $errno, string $errstr): bool {
    echo json_encode(['errno' => $errno, 'message' => $errstr, 'suppressed' => (error_reporting() & $errno) === 0]), "\n";
    return true;
});

[, $hostname, $username, $password, $database] = $argv;
DB::connect([
    'hostname' => "p:$hostname",
    'username' => $username,
    'password' => $password,
    'database' => $database,
]);
echo DB::isConnected(true) ? "CONNECTED\n" : "NOT CONNECTED\n";
