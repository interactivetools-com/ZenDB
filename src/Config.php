<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;

/**
 * Config class for managing ZenDB configuration values.
 *
 * Configuration values can be manipulated using getter/setter methods or directly:
 *
 * // Using methods (to be deprecated)
 * $config = new Config();
 * $config->set('hostname', 'localhost');
 * $config->setMany(['username' => 'root', 'password' => 'password']);
 * $hostname = $config->get('hostname');
 * $allConfig = $config->getAll();
 *
 * // Or direct property access (preferred)
 * $config->hostname = 'localhost';
 * $config->username = 'root';
 */
class Config
{
    #region Configuration Methods

    /**
     * Creates a new Config instance.
     *
     * @param array|null $config Optional array of configuration key-value pairs to set
     * @throws InvalidArgumentException If any key doesn't exist in the Config class
     */
    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            // Direct property setting instead of using setMany
            foreach ($config as $key => $value) {
                if (!property_exists($this, $key)) {
                    throw new InvalidArgumentException("Invalid configuration key: $key");
                }
                $this->$key = $value;
            }
        }
    }

    #endregion
    #region Database Connection Settings

    // Required connection parameters
    public ?string $hostname    = null;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          // can also contain :port
    public ?string $username    = null;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              // username
    public ?string $password    = null;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              // password
    public ?string $database    = null;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              // database name
    public ?string $tablePrefix = '';                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      // prefix for all table names; e.g.; cms_
    public ?string $primaryKey  = '';                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      // primary key used for shortcut where = (int) num queries

    #endregion
    #region SQL Settings

    // SQL mode settings
    public ?string $set_sql_mode = "STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION";

    #endregion
    #region Advanced Options

    // Connection options
    public ?bool   $usePhpTimezone     = true;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               // Set mysql timezone to match PHP timezone (so MySQL NOW() matches PHP time())
    public ?string $versionRequired    = "5.7.32";                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            // minimum MySQL version required
    public bool    $requireSSL         = false;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  // require SSL connections
    public bool    $databaseAutoCreate = true;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                // automatically creates database if it doesn't exist
    public ?int    $connectTimeout     = 3;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      // connection timeout in seconds; sets MYSQLI_OPT_CONNECT_TIMEOUT
    public ?int    $readTimeout        = 60;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     // read timeout in seconds; sets MYSQLI_OPT_READ_TIMEOUT

    // Feature flags
    public bool $useSmartJoins         = true;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     // enable smart joins; can be toggled at runtime
    public bool $usePreparedStatements = true;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     // use prepared statements instead of escaped queries

    #endregion
    #region Logging Options

    // Query logging
    public bool    $enableLogging = false;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         // enable live logging of queries to a file
    public ?string $logFile       = "_mysql_query_log.php";                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        // file to log queries to

    // Error display settings
    /**
     * Controls whether to show SQL in error messages
     *
     * @var bool|callable
     *
     * Possible values:
     * - false = never show SQL (default)
     * - true = always show SQL
     * - callable = function(): bool - custom logic to determine if SQL should be shown
     *
     * Examples:
     *   $config->showSqlInErrors = fn() => SomeUserClass::isCurrentUserAdmin(); // Anonymous function
     *   $config->showSqlInErrors = [UserService::class, 'isAdminUser'];         // Static method
     *   $config->showSqlInErrors = [$securityService, 'canViewSqlErrors'];      // Instance method
     */
    public mixed $showSqlInErrors = false;

    // SmartArray integration
    /**
     * Custom load handler for SmartArray integration
     *
     * @var string|callable|null
     *
     * Possible values:
     * - null = no custom handler (default)
     * - string = fully qualified function name
     * - callable = custom load handler function
     *
     * Examples:
     *   $config->smartArrayLoadHandler = '\Namespace\Class::load';             // Function name as string
     *   $config->smartArrayLoadHandler = [MyLoadHandler::class, 'load'];         // Static method
     *   $config->smartArrayLoadHandler = [$loadService, 'handleLoad'];           // Instance method
     */
    public mixed $smartArrayLoadHandler = null;

    #endregion
}
