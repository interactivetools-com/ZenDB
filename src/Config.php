<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;

/**
 * Config class for managing ZenDB configuration values.
 *
 * This class allows setting and retrieving database connection settings,
 * SQL configuration, logging options, and other ZenDB behaviors.
 *
 * Configuration can be set using direct property access (preferred):
 *
 * ```php
 * $config = new Config();
 * $config->hostname = 'localhost';
 * $config->username = 'root';
 * $config->password = 'password';
 * ```
 *
 * Or via constructor for multiple values:
 *
 * ```php
 * $config = new Config([
 *     'hostname' => 'localhost',
 *     'username' => 'root',
 *     'password' => 'password'
 * ]);
 * ```
 */
class Config
{
    #region Constructor

    /**
     * Creates a new Config instance with optional initial values.
     *
     * @param array|null $config Optional configuration key-value pairs
     * @throws InvalidArgumentException If any key doesn't exist in the Config class
     */
    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            foreach ($config as $key => $value) {
                if (!property_exists($this, $key)) {
                    throw new InvalidArgumentException("Invalid configuration key: $key");
                }
                $this->$key = $value;
            }
        }
    }

    #endregion
    #region Basic Connection Settings

    /**
     * Mysql hostname (can include port, e.g. 'localhost:3306')
     */
    public ?string $hostname = null;

    /**
     * Mysql username for authentication
     */
    public ?string $username = null;

    /**
     * Mysql password for authentication
     */
    public ?string $password = null;

    /**
     * Mysql database to connect to
     */
    public ?string $database = null;

    /**
     * Table prefix automatically added to all table names (e.g. 'cms_')
     */
    public ?string $tablePrefix = '';

    /**
     * Default primary key field name used for shorthand where=$num queries
     */
    public ?string $primaryKey = '';

    #endregion
    #region Advanced Connection Settings

    /**
     * Minimum MySQL version required for compatibility
     */
    public ?string $versionRequired = "5.7.32";

    /**
     * MySQL SQL mode configuration
     * Default enforces strict mode and important error conditions
     */
    public ?string $set_sql_mode = "STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION";

    /**
     * Whether to require SSL for database connections
     */
    public bool $requireSSL = false;

    /**
     * Connection timeout in seconds (MYSQLI_OPT_CONNECT_TIMEOUT)
     */
    public ?int $connectTimeout = 3;

    /**
     * Read timeout in seconds (MYSQLI_OPT_READ_TIMEOUT)
     */
    public ?int $readTimeout = 60;

    #endregion
    #region Feature Toggles

    /**
     * Custom load handler for SmartArray integration
     *
     * @var string|callable|null
     *
     * Possible values:
     *
     * $config->smartArrayLoadHandler = '\Namespace\Class::load';           // Function name as string
     * $config->smartArrayLoadHandler = [MyLoadHandler::class, 'load'];     // Static method
     * $config->smartArrayLoadHandler = [$object, 'load'];                  // Instance method
     */
    public mixed $smartArrayLoadHandler = null;

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
     * ```php
     * // Anonymous function
     * $config->showSqlInErrors = fn() => SomeUserClass::isCurrentUserAdmin();
     *
     * // Static method
     * $config->showSqlInErrors = [UserService::class, 'isAdminUser'];
     *
     * // Instance method
     * $config->showSqlInErrors = [$securityService, 'canViewSqlErrors'];
     * ```
     */
    public mixed $showSqlInErrors = false;

    /**
     * Use prepared statements instead of escaped queries
     * Recommended for security and performance
     */
    public bool $usePreparedStatements = true;

    /**
     * Whether to synchronize MySQL timezone with PHP timezone
     * Ensures MySQL NOW() matches PHP time() function
     */
    public ?bool $usePhpTimezone = true;

    /**
     * Whether to automatically create the database if it doesn't exist
     */
    public bool $databaseAutoCreate = true;

    /**
     * Enable smart join functionality for table relationships
     * Can be toggled at runtime
     */
    public bool $useSmartJoins = true;

    #endregion
    #region Logging

    /**
     * Enable logging of SQL queries to a file
     */
    public bool $enableLogging = false;

    /**
     * Path to file for SQL query logging
     */
    public ?string $logFile = "_mysql_query_log.php";

    #endregion
    #region Magic Methods

    /**
     * Prevents setting undefined properties
     * 
     * @param string $name Property name being set
     * @param mixed $value Value being assigned to the property
     * @throws InvalidArgumentException Always throws an exception for undefined properties
     */
    public function __set(string $name, mixed $value): void
    {
        throw new InvalidArgumentException(
            "Attempting to set unknown configuration property: '$name'. " .
            "Config properties must be explicitly declared in the class."
        );
    }

    /**
     * Prevents accessing undefined properties
     * 
     * @param string $name Property name being accessed
     * @throws InvalidArgumentException Always throws an exception for undefined properties
     */
    public function __get(string $name): never
    {
        throw new InvalidArgumentException(
            "Attempting to get unknown configuration property: '$name'. " .
            "Config properties must be explicitly declared in the class."
        );
    }

    #endregion
}
