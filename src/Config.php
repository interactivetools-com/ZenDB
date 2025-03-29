<?php
declare(strict_types=1);

namespace Itools\ZenDB;

/**
 * Config class for managing ZenDB configuration values.
 *
 * All configuration values are stored as static properties in this class.
 * These properties can be accessed and modified directly, or through DB::config():
 *
 * // Direct access
 * Config::$hostname = 'localhost';
 * Config::$username = 'root';
 * Config::$password = 'password';
 * Config::$database = 'mydb';
 *
 * // Using DB::config() (legacy approach)
 * DB::config('hostname', 'localhost');
 * DB::config(['username' => 'root', 'password' => 'password']);
 */
class Config
{
    // required - connect to database
    public static ?string $hostname    = null;      // automatically cleared after login for security - can also contain :port
    public static ?string $username    = null;      // automatically cleared after login for security
    public static ?string $password    = null;      // automatically cleared after login for security
    public static ?string $database    = '';        // database name
    public static ?string $tablePrefix = '';        // prefix for all table names; e.g.; cms_
    public static ?string $primaryKey  = '';        // primary key used for shortcut where = (int) num queries

    // SQL mode
    public static ?string $set_sql_mode           = "STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION";
    public static ?bool   $set_innodb_strict_mode = true;               // Set to false to allow for restore and upgrade of large MyISAM or older InnoDB tables
    // Ref: https://mariadb.com/kb/en/innodb-strict-mode
    // Ref: https://mariadb.com/kb/en/troubleshooting-row-size-too-large-errors-with-innodb

    // optional/advanced
    public static ?bool   $usePhpTimezone     = true;                    // Set mysql timezone to match PHP timezone (so MySQL NOW() matches PHP time())
    public static ?string $versionRequired    = "5.7.32";                // minimum MySQL version required
    public static bool    $requireSSL         = false;                   // require SSL connections
    public static bool    $databaseAutoCreate = true;                    // automatically creates database if it doesn't exist
    public static ?int    $connectTimeout     = 3;                       // connection timeout in seconds; sets MYSQLI_OPT_CONNECT_TIMEOUT
    public static ?int    $readTimeout        = 60;                      // read timeout in seconds; sets MYSQLI_OPT_READ_TIMEOUT
    public static bool    $useSmartJoins      = true;                    // enable smart joins; can be toggled at runtime
    public static bool    $enableLogging      = false;                   // enable live logging of queries to a file
    public static ?string $logFile            = "_mysql_query_log.php";  // file to log queries to

    // internals
    public static \Closure|string|null $isConnectedCallback = "DB::isConnected";    // callback to check if we're connected to the database
}
