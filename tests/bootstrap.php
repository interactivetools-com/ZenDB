<?php
declare(strict_types=1);

// Use composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Add test helpers directory to the include path
require_once __DIR__ . '/Helpers/ReflectionWrapper.php';

error_reporting(E_ALL);

// create database if it doesn't exist
use Itools\ZenDB\DB;
class_alias(\Itools\ZenDB\DB::class, '\DB');

// If running inside Windows Subsystem for Linux (WSL), automatically detect Windows host IP to connect to MySQL running on Windows (outside of WSL)
$isWSL = PHP_OS_FAMILY === 'Linux' && trim(`test -f /proc/sys/fs/binfmt_misc/WSLInterop && echo 1`);  // file only exists in WSL
if ($isWSL) {
    $_ENV['DB_HOSTNAME'] = trim(`powershell.exe -Command "(Test-Connection -ComputerName (hostname) -Count 1).IPV4Address.IPAddressToString"`);
}

// Check for required environment variables from phpunit.xml
if (!isset($_ENV['DB_HOSTNAME'], $_ENV['DB_USERNAME'], $_ENV['DB_DATABASE'])) {
    echo "\033[31mError: Required database configuration missing.\033[0m\n\n";
    echo "Please create a phpunit.xml file based on phpunit.xml.dist and define:\n\n";
    echo "<php>\n";
    echo "  <env name=\"DB_HOSTNAME\" value=\"localhost\"/>\n";
    echo "  <env name=\"DB_USERNAME\" value=\"your_username\"/>\n";
    echo "  <env name=\"DB_PASSWORD\" value=\"your_password\"/>\n";
    echo "  <env name=\"DB_DATABASE\" value=\"phpunit_test_db\"/>\n";
    echo "</php>\n\n";
    exit(1);
}

// Setup the test database
DB::config([
    'hostname'               => $_ENV['DB_HOSTNAME'],
    'username'               => $_ENV['DB_USERNAME'],
    'password'               => $_ENV['DB_PASSWORD'],
    'database'               => $_ENV['DB_DATABASE'],
    'tablePrefix'            => 'test_',
    'primaryKey'             => 'num',
    'usePhpTimezone'         => true,
    'databaseAutoCreate'     => true,
    'connectTimeout'         => 1,
    'readTimeout'            => 60,
]);

// Create the test database if it doesn't exist
    DB::connect();
    DB::query("CREATE DATABASE IF NOT EXISTS `{$_ENV['DB_DATABASE']}`");
