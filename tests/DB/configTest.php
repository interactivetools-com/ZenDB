<?php
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace tests;

use InvalidArgumentException, TypeError;
use Itools\ZenDB\Config;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;

class configTest extends BaseTest
{
    private Config $config;
    private array $defaultValues;

    // Runs before each test method in this class
    protected function setUp(): void
    {
        DB::disconnect();
        // Initialize a new Config instance
        $this->config = new Config();
        
        // Setup default values that match the BaseTest defaults
        $this->defaultValues = [
            'hostname'               => $_ENV['DB_HOSTNAME'],
            'username'               => $_ENV['DB_USERNAME'],
            'password'               => $_ENV['DB_PASSWORD'],
            'database'               => $_ENV['DB_DATABASE'],
            'tablePrefix'            => 'test_',     // prefix for all table names, e.g., 'cms_'
            'primaryKey'             => 'num',       // primary key used for shortcut where = (int) num queries
            'usePhpTimezone'         => true,        // Set MySQL timezone to the same offset as current PHP timezone
            'set_sql_mode'           => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY',
            'versionRequired'        => '5.7.32',    // minimum MySQL version required. An exception will be thrown if the server version is lower than this.
            'requireSSL'             => false,       // require SSL connections
            'databaseAutoCreate'     => true,        // automatically creates database if it doesn't exist
            'connectTimeout'         => 1,           // (low timeout for testing) connection timeout in seconds, sets MYSQLI_OPT_CONNECT_TIMEOUT
            'readTimeout'            => 60,          // read timeout in seconds, sets MYSQLI_OPT_READ_TIMEOUT
        ];
        
        // Set default values on the config
        $this->config->setMany($this->defaultValues);
    }

    public function testConfigProperties(): void
    {
        // Verify default values are set correctly
        foreach ($this->defaultValues as $key => $expectedValue) {
            $this->assertSame($expectedValue, $this->config->$key, "Default value for $key should match expected value");
        }
    }

    public function testConfigDirectPropertyAccess(): void
    {
        $key = 'tablePrefix';
        $newValue = "changed_";

        // Setting a new value directly
        $this->config->$key = $newValue;

        // Retrieving to confirm it has changed
        $actualValue = $this->config->$key;
        $this->assertSame($newValue, $actualValue, "After setting a new value directly, the retrieved value should match the newly set value.");

        // reset values
        $this->config->$key = $this->defaultValues[$key];
    }

    public function testConfigSet(): void
    {
        $key = 'tablePrefix';
        $newValue = "changed_";

        // Setting a new value using set()
        $this->config->set($key, $newValue);

        // Retrieving to confirm it has changed
        $actualValue = $this->config->get($key);
        $this->assertSame($newValue, $actualValue, "After using set(), the value retrieved with get() should match the newly set value.");

        // reset values
        $this->config->set($key, $this->defaultValues[$key]);
    }

    public function testConfigSetMany(): void
    {
        // Define a set of key-value pairs to change in the config
        $newValues = [
            'tablePrefix'        => 'new_test_',
            'usePhpTimezone'     => true,
            'databaseAutoCreate' => false,
        ];

        // Apply these new settings using setMany()
        $this->config->setMany($newValues);

        // Verify that each setting was updated as expected
        foreach ($newValues as $key => $expectedValue) {
            $actualValue = $this->config->get($key);
            $this->assertSame($expectedValue, $actualValue, sprintf("The value for the key '%s' should be '%s', but got '%s'", $key, $expectedValue, $actualValue));
        }

        // reset values
        $this->config->setMany($this->defaultValues);
    }
    
    public function testConfigGetAll(): void
    {
        // Get all configuration
        $allConfig = $this->config->getAll();
        
        // Verify all default values are included
        foreach ($this->defaultValues as $key => $expectedValue) {
            $this->assertArrayHasKey($key, $allConfig, "Configuration array should contain key: $key");
            $this->assertSame($expectedValue, $allConfig[$key], "Value for key $key should match expected value");
        }
    }
    
    public function testInstancesAreIndependent(): void
    {
        // Create a second instance with different values
        $config2 = new Config();
        $config2->set('tablePrefix', 'different_prefix_');
        $config2->set('connectTimeout', 10);
        
        // Verify original instance is unchanged
        $this->assertSame($this->defaultValues['tablePrefix'], $this->config->get('tablePrefix'), 'Original instance tablePrefix should be unchanged');
        $this->assertSame($this->defaultValues['connectTimeout'], $this->config->get('connectTimeout'), 'Original instance connectTimeout should be unchanged');
        
        // Verify second instance has different values
        $this->assertSame('different_prefix_', $config2->get('tablePrefix'), 'Second instance should have different tablePrefix');
        $this->assertSame(10, $config2->get('connectTimeout'), 'Second instance should have different connectTimeout');
    }
    
    public function testInvalidKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->config->get('nonexistentKey');
    }
    
    public function testDBConfigWrapper(): void
    {
        // Test DB::config wrapper for setting and getting values
        $testKey = 'tablePrefix';
        $originalValue = DB::config($testKey);
        $newValue = 'test_db_config_';
        
        // Test setting a single value
        DB::config($testKey, $newValue);
        $this->assertSame($newValue, DB::config($testKey), 'DB::config() should set and get single values correctly');
        
        // Test setting multiple values
        $multiValues = [
            'tablePrefix' => 'multi_test_',
            'connectTimeout' => 5
        ];
        DB::config($multiValues);
        
        foreach ($multiValues as $key => $expectedValue) {
            $this->assertSame($expectedValue, DB::config($key), "DB::config() should set multiple values correctly for key: $key");
        }
        
        // Reset to original value
        DB::config($testKey, $originalValue);
    }
}