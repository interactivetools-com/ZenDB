<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use Itools\SmartArray\SmartArray;
use Itools\SmartString\SmartString;
use mysqli;
use Throwable, InvalidArgumentException, RuntimeException, Exception;

/**
 * DB is a wrapper for mysqli that provides a simple, secure, and consistent interface for database access.
 */
class DB
{
    public static string       $tablePrefix       = '';    // table prefix, set by config()
    public static ?mysqli      $mysqli            = null;  // mysqli object
    private static ?Config     $defaultConfig     = null;  // default config
    private static ?Connection $defaultConnection = null;  // shared connection
    private static ?Instance   $instance          = null;  // cached default instance

    #region Config & Connection

    /**
     * Configure database configuration settings.
     *
     * This is a convenience wrapper around the Config class methods.
     *
     * - To get the entire config: self::config()
     * - To get a single value: self::config('key')
     * - To set a single value: self::config('key', 'value')
     * - To set multiple values: self::config(['key1' => 'value1', 'key2' => 'value2'])
     * - To set configuration from a Config object: self::config($configObj)
     *
     * @param string|array|null $keyOrArrayOrConfig Key to retrieve, key-value pairs to set, or a Config object.
     * @param mixed $keyValue Value to set for the given key. Ignored if first parameter is an array or Config.
     *
     * @return mixed The requested configuration value, array of all settings, or null after setting values.
     */
    public static function config(string|array|null $keyOrArrayOrConfig = null, mixed $keyValue = null): mixed
    {
        self::$defaultConfig ??= new Config();  // Initialize config instance if not already created
        $args       = func_num_args();
        [$key, $value] = [$keyOrArrayOrConfig, $keyValue]; // aliases

        // Track if instance-affecting configs changed
        $instanceAffectingKeys = ['usePreparedStatements', 'tablePrefix', 'primaryKey', 'useSmartJoins'];
        $shouldClearInstance = false;

        // set values and check if we need to clear instance
        if ($args === 2 && is_string($key)) {
            if (in_array($key, $instanceAffectingKeys) && self::$defaultConfig->$key !== $value) {
                $shouldClearInstance = true;
            }
            self::$defaultConfig->$key = $value;
        } elseif ($args === 1 && is_array($key)) {
            foreach ($key as $k => $v) {
                if (in_array($k, $instanceAffectingKeys) && self::$defaultConfig->$k !== $v) {
                    $shouldClearInstance = true;
                }
                self::$defaultConfig->$k = $v;
            }
        }

        // Clear cached instance if config changed
        if ($shouldClearInstance && self::$instance !== null) {
            self::$instance = null;
        }

        // Update table prefix alias property in case it changed
        self::$tablePrefix = &self::$defaultConfig->tablePrefix;

        // return values
        return match (true) {
            $args === 0                    => get_object_vars(self::$defaultConfig),                            // get all config values
            $args === 1 && is_string($key) => self::$defaultConfig->$key,                                       // get single value
            default                        => null,
        };
    }

    /**
     * Create connection and set defaults
     * @throws Exception
     */
    public static function connect(): void
    {
        // Ensure DB::config() was already called
        if (self::$defaultConfig === null) {
            throw new RuntimeException("Call DB::config() first to set your defaults");
        }

        // Ensure DB::connect() is only called once
        if (self::$defaultConnection !== null) {
            throw new RuntimeException("DB::connect() has already been called.");
        }

        // Create connection
        $connectionOptions = self::$defaultConfig->getConnectionProperties();
        self::$defaultConnection = new Connection($connectionOptions);

        // Update the mysqli reference
        self::$mysqli = self::$defaultConnection->mysqli;
    }

    /**
     * Check if database connection was made and optionally check if it's still active.
     *
     * This method verifies if the MySQLi connection is established.
     * If $doPing is true, it will ping the database server to confirm the connection is still alive.
     *
     *  Consider using $doPing for:
     *  - Long-running scripts where the connection might time out
     *  - When the application has been idle for an extended period
     *  - Other factors may have caused you to lose the connection
     *
     * @param bool $doPing Whether to ping the server to check for active connection. Default is false.
     * @return bool True if the connection is valid (and responsive if $doPing is true), false otherwise.
     */
    public static function isConnected(bool $doPing = false): bool
    {
        if (self::$mysqli === null) {
            return false;
        }

        return match (true) {
            self::$mysqli->connect_errno,                                      // Connection attempted but failed
            empty(self::$mysqli->host_info) => false,                           // Connection not attempted yet
            $doPing                         => self::$mysqli->stat() !== false, // Replacement for deprecated ping()
            default                         => true,
        };
    }


    /**
     * Reconnect to the database server if the connection is lost.
     *
     * This method checks if the connection is active, and if not, it attempts to reconnect.
     *
     * @return bool True if reconnected successfully or already connected, false if unable to reconnect
     */
    public function reconnectIfNeeded(): bool
    {
        $isConnected = $this->isConnected(true); // check if connection is still alive with stat() (like ping but not deprecated)

        // Reconnect if not connected
        if (!$isConnected) {
            try {
                $this->disconnect();  // Clean up any existing connection resources
                $this->connect();
                $isConnected = $this->isConnected(true);
            } catch (Throwable $e) {
                // Log the error but don't throw during reconnect
                // This allows reconnecting without immediately throwing an exception
                $isConnected = false; // Important: set to false on connection failure
            }
        }

        return $isConnected;
    }

    /**
     * Close the database connection.
     *
     * @return void
     */
    public static function disconnect(): void
    {
        self::$tablePrefix = '';
        self::$mysqli?->close();
        self::$mysqli            = null;
        self::$defaultConnection = null;
        self::$instance          = null;
    }

    #endregion

    #endregion
    #region Magic Methods

    /**
     * Get the default cached Instance (singleton pattern).
     * Creates it on first access using default config and connection.
     * 
     * @return Instance The default database instance
     * @throws Exception
     */
    private static function defaultInstance(): Instance
    {
        if (self::$instance === null) {
            // Ensure DB::config() was already called
            if (self::$defaultConfig === null) {
                throw new RuntimeException("Call DB::config() first to set your defaults");
            }

            // Ensure DB::connect() is called to create the default connection
            if (self::$defaultConnection === null) {
                self::connect(); // sets self::$defaultConnection
            }

            // Create and cache the default instance
            $instanceOptions = self::$defaultConfig->getInstanceProperties();
            self::$instance  = new Instance($instanceOptions, self::$defaultConnection);
        }

        return self::$instance;
    }

    /**
     * Creates a new DBInstance that shares the same Connection.  Supports overrides for instance properties by passing an array.
     * If no connection exists yet, it will be created.  You must call DB::config() first to set the defaults.  If you want to create
     * a new connection instead, use DB::newConnection().
     *
     * @return Instance A new database instance with shared connection
     * @throws Exception
     */
    public static function newInstance(array $overrides = []): Instance
    {
        // Ensure DB::config() was already called
        if (self::$defaultConfig === null) {
            throw new RuntimeException("Call DB::config() first to set your defaults");
        }

        // Ensure DB::connect() is called to create the default connection
        if (self::$defaultConnection === null) {
            self::connect(); // sets self::$defaultConnection
        }

        // Create new Instance with default connection
        $instanceOptions = array_merge(self::$defaultConfig->getInstanceProperties(), $overrides);
        $instance        = new Instance($instanceOptions, self::$defaultConnection);    // use default connection

        return $instance;
    }

    /**
     * Creates a new DBInstance with a fresh database connection.
     *
     * Use this method when you need a completely separate database connection,
     * for example when working with different databases or requiring different
     * connection settings.
     *
     * @param array $overrides Optional configuration overrides to merge with the default config
     * @return Instance A new database instance with its own connection
     * @throws Exception
     */
    public static function newConnection(array $overrides = [], $connectionOverrides = []): Instance
    {
        // Error checking
        if (self::$defaultConfig === null) {
            throw new RuntimeException("Call DB::config() first to set your defaults");
        }

        // create new connection and new instance
        $instanceOptions   = array_merge(self::$defaultConfig->getInstanceProperties(), $overrides);
        $connectionOptions = array_merge(self::$defaultConfig->getConnectionProperties(), $connectionOverrides);
        $connection        = new Connection($connectionOptions);
        $instance          = new Instance($instanceOptions, $connection);

        return $instance;
    }

    /**
     * Constructor for object instance usage.
     * Instance methods will delegate to the singleton DBInstance:
     * - $db = DB::newInstance(); $db->select('table')
     */
    public function __construct()
    {
        throw new RuntimeException("Use DB::newInstance() instead.");
    }

    /**
     * Handles static calls to delegate to Instance methods.
     *
     * All query and utility methods are delegated to a default instance.
     * This provides a clean static API while maintaining proper object-oriented design.
     */
    public static function __callStatic(string $name, array $args)
    {
        return match ($name) {
            // Query methods
            'query', 'select', 'get', 'insert', 'update', 'delete', 'count',
            // SQL generation
            'escape', 'escapeCSV', 'rawSql', 'isRawSql', 'pagingSql',
            'likeContains', 'likeContainsTSV', 'likeStartsWith', 'likeEndsWith',
            // Table helpers
            'tableExists', 'getTableNames', 'getColumnDefinitions',
            // Utility methods
            'debug', 'setTimezoneToPhpTimezone', 'occurredInFile'
                => self::defaultInstance()->$name(...$args),

            // Table prefix helpers - use static property directly
            'getBaseTable' => match (true) {
                !str_starts_with($args[0], self::$tablePrefix) => $args[0],
                ($args[1] ?? false) && self::tableExists($args[0], false) => $args[0],
                default => substr($args[0], strlen(self::$tablePrefix)),
            },
            'getFullTable' => match (true) {
                ($args[1] ?? false) && !self::tableExists($args[0], true) => self::$tablePrefix . $args[0],
                str_starts_with($args[0], self::$tablePrefix) => $args[0],
                default => self::$tablePrefix . $args[0],
            },

            // Legacy methods for backwards compatibility
            'like', 'escapeLikeWildcards' => addcslashes((string)($args[0] ?? ''), '%_'),
            'identifier'                  => self::rawSql("`" . self::$mysqli->real_escape_string(...$args) . "`"),
            'getTablePrefix'              => self::$tablePrefix,
            'raw'                         => self::rawSql(...$args),
            'datetime'                    => date('Y-m-d H:i:s', ($args[0] ?? time())),
            default                       => throw new InvalidArgumentException("Unknown static method: $name"),
        };
    }

    #endregion
}
