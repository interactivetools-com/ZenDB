<?php
declare(strict_types=1);

namespace Itools\ZenDB;

/**
 * Config class for managing ZenDB configuration values.
 *
 * Configuration values are stored as instance properties in this class.
 * These properties can be accessed and modified directly:
 *
 * // Direct access with an instance
 * $config = new Config();
 * $config->hostname = 'localhost';
 * $config->username = 'root';
 * $config->password = 'password';
 * $config->database = 'mydb';
 */
class Config
{
    #region Database Connection Settings

    // Required connection parameters
    public ?string $hostname    = null;      // automatically cleared after login for security - can also contain :port
    public ?string $username    = null;      // automatically cleared after login for security
    public ?string $password    = null;      // automatically cleared after login for security
    public ?string $database    = null;      // database name
    public ?string $tablePrefix = '';        // prefix for all table names; e.g.; cms_
    public ?string $primaryKey  = '';        // primary key used for shortcut where = (int) num queries

    #endregion
    #region SQL Settings

    // SQL mode settings
    public ?string $set_sql_mode           = "STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION";

    #endregion
    #region Advanced Options

    // Connection options
    public ?bool   $usePhpTimezone     = true;                    // Set mysql timezone to match PHP timezone (so MySQL NOW() matches PHP time())
    public ?string $versionRequired    = "5.7.32";                // minimum MySQL version required
    public bool    $requireSSL         = false;                   // require SSL connections
    public bool    $databaseAutoCreate = true;                    // automatically creates database if it doesn't exist
    public ?int    $connectTimeout     = 3;                       // connection timeout in seconds; sets MYSQLI_OPT_CONNECT_TIMEOUT
    public ?int    $readTimeout        = 60;                      // read timeout in seconds; sets MYSQLI_OPT_READ_TIMEOUT

    // Feature flags
    public bool    $useSmartJoins      = true;                    // enable smart joins; can be toggled at runtime

    #endregion
    #region Logging Options

    // Query logging
    public bool    $enableLogging      = false;                   // enable live logging of queries to a file
    public ?string $logFile            = "_mysql_query_log.php";  // file to log queries to

    #endregion
}
