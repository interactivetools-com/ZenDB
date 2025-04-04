<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;

/**
 * Config class for managing ZenDB configuration values.
 *
 * Configuration values can be manipulated using getter/setter methods or directly:
 *
 * // Using methods
 * $config = new Config();
 * $config->set('hostname', 'localhost');
 * $config->setMany(['username' => 'root', 'password' => 'password']);
 * $hostname = $config->get('hostname');
 * $allConfig = $config->getAll();
 *
 * // Or direct property access
 * $config->hostname = 'localhost';
 * $config->username = 'root';
 */
class Config
{
    #region Database Connection Settings

    // Required connection parameters
    public ?string $hostname    = null;      // can also contain :port
    public ?string $username    = null;      // username
    public ?string $password    = null;      // password
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
    #region Configuration Methods

    /**
     * Get a configuration value by key.
     *
     * @param string $key The configuration key to get
     * @return mixed The value of the configuration property
     * @throws InvalidArgumentException If the key doesn't exist
     */
    public function get(string $key): mixed
    {
        if (!property_exists($this, $key)) {
            throw new InvalidArgumentException("Invalid configuration key: $key");
        }

        return $this->$key;
    }

    /**
     * Set a configuration value.
     *
     * @param string $key The configuration key to set
     * @param mixed $value The value to set
     * @throws InvalidArgumentException If the key doesn't exist
     */
    public function set(string $key, mixed $value): void
    {
        if (!property_exists($this, $key)) {
            throw new InvalidArgumentException("Invalid configuration key: $key");
        }

        $this->$key = $value;
    }

    /**
     * Set multiple configuration values at once.
     *
     * @param array $values An associative array of key-value pairs to set
     * @throws InvalidArgumentException If any key doesn't exist
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Get all configuration values as an associative array.
     *
     * @return array All configuration values
     */
    public function getAll(): array
    {
        $config = [];
        $properties = get_class_vars(self::class);

        foreach ($properties as $key => $defaultValue) {
            $config[$key] = $this->$key;
        }

        return $config;
    }

    #endregion
}
