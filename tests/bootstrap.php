<?php
declare(strict_types=1);

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Require mysqlnd driver for native type support (int/float instead of strings)
if (!defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
    throw new RuntimeException("Tests require mysqlnd driver (MYSQLI_OPT_INT_AND_FLOAT_NATIVE not defined). mysqlnd is default since PHP 5.4 and mandatory in PHP 8.2+.");
}
