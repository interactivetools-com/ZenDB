<?php
declare(strict_types=1);

// Use composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';


error_reporting(E_ALL);

// create database if it doesn't exist
use Itools\ZenDB\DB;
class_alias(\Itools\ZenDB\DB::class, '\DB');

// Configure the database
DB::config([
    'hostname'           => $_ENV['DB_HOSTNAME'],
    'username'           => $_ENV['DB_USERNAME'] ?? 'root',
    'password'           => $_ENV['DB_PASSWORD'] ?? '',
    'database'           => $_ENV['DB_DATABASE'] ?? 'test_database',
    'tablePrefix'        => 'test_',
    'primaryKey'         => 'num',
    'usePhpTimezone'     => true,
    'databaseAutoCreate' => true,
    'connectTimeout'     => 1,
    'readTimeout'        => 60,
    'showSqlInErrors'    => true,
]);

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
