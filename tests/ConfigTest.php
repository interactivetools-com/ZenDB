<?php
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace tests;

use InvalidArgumentException;
use Itools\ZenDB\Config;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Config class directly (not through DB::config wrapper)
 */
class ConfigTest extends TestCase
{
    private Config $config;
    private array $defaultValues;

    // Runs before each test method in this class
    protected function setUp(): void
    {
        // Initialize a new Config instance
        $this->config = new Config();
        
        // Setup default values that match the BaseTest defaults
        $this->defaultValues = [
            'hostname'               => $_ENV['DB_HOSTNAME'],     // hostname can also contain :port
            'username'               => $_ENV['DB_USERNAME'],     // automatically cleared after login for security
            'password'               => $_ENV['DB_PASSWORD'],     // automatically cleared after login for security
            'database'               => $_ENV['DB_DATABASE'],     // database name
            'tablePrefix'            => 'test_',                  // prefix for all table names; e.g.; cms_
            'primaryKey'             => 'num',                    // primary key used for shortcut where = (int) num queries
            'usePhpTimezone'         => true,                     // Set MySQL timezone to the same offset as current PHP timezone
            'set_sql_mode'           => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY',
            'versionRequired'        => '5.7.32',                 // minimum MySQL version required
            'requireSSL'             => false,                    // require SSL connections
            'databaseAutoCreate'     => true,                     // automatically creates database if it doesn't exist
            'connectTimeout'         => 1,                        // (low timeout for testing) connection timeout in seconds
            'readTimeout'            => 60,                       // read timeout in seconds
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
    
    public function testGetAllReturnsCopy(): void
    {
        // Get all configuration
        $allConfig = $this->config->getAll();
        
        // Modify the array (not the Config object)
        $originalValue = $allConfig['tablePrefix'];
        $allConfig['tablePrefix'] = 'modified_through_array_';
        
        // Verify that modifying the array doesn't affect the Config object
        $this->assertSame($originalValue, $this->config->get('tablePrefix'), 
            'The Config object should not be affected by changes to the array returned by getAll()');
        
        // Get the config again and verify it's unchanged
        $newConfig = $this->config->getAll();
        $this->assertSame($originalValue, $newConfig['tablePrefix'],
            'The getAll() method should return a fresh copy of the configuration');
    }
    
    public function testSetNumericBoundaries(): void
    {
        // Test extreme values for numeric properties
        $this->config->set('connectTimeout', 0);
        $this->assertSame(0, $this->config->get('connectTimeout'), 'Zero should be a valid timeout value');
        
        $this->config->set('connectTimeout', PHP_INT_MAX);
        $this->assertSame(PHP_INT_MAX, $this->config->get('connectTimeout'), 'PHP_INT_MAX should be a valid timeout value');
        
        // Reset value
        $this->config->set('connectTimeout', $this->defaultValues['connectTimeout']);
        
        // Test boolean values
        $this->config->set('requireSSL', true);
        $this->assertTrue($this->config->get('requireSSL'), 'Setting true boolean value should work');
        
        $this->config->set('requireSSL', false);
        $this->assertFalse($this->config->get('requireSSL'), 'Setting false boolean value should work');
        
        // Reset value
        $this->config->set('requireSSL', $this->defaultValues['requireSSL']);
    }
    
    public function testSetManyWithInvalidKey(): void
    {
        // Create a values array with an invalid key
        $mixedValues = [
            'nonExistentKey' => 'some value'  // This key doesn't exist
        ];
        
        // The setMany method should validate keys individually
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nonExistentKey');
        
        $this->config->setMany($mixedValues);
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
}