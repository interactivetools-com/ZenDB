<?php
declare(strict_types=1);

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Support/functions.php';    // http_response_code_clear()

// Require mysqlnd driver for native type support (int/float instead of strings)
if (!defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
    throw new RuntimeException("Tests require mysqlnd driver (MYSQLI_OPT_INT_AND_FLOAT_NATIVE not defined). mysqlnd is default since PHP 5.4 and mandatory in PHP 8.2+.");
}

// Optional record/replay of MySQL traffic: ZENDB_QUERY_MODE=record|replay (see tests/Support/Replay/ReplayHarness.php)
Itools\ZenDB\Tests\Support\Replay\ReplayHarness::initFromEnv();
