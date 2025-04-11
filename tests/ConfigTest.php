<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded
require_once __DIR__ . '/bootstrap.php';

use InvalidArgumentException;
use RuntimeException;
use Itools\ZenDB\Config;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Config class directly
 */
class ConfigTest extends TestCase
{
    #region Setup

    private Config $config;
    private array $defaultValues;
    private array $expectedClassDefaults;

    // Runs before each test method in this class
    protected function setUp(): void
    {
        // Define the expected class default values
        $this->expectedClassDefaults = [
            'hostname'               => null,
            'username'               => null,
            'password'               => null,
            'database'               => null,
            'tablePrefix'            => '',
            'primaryKey'             => '',
            'usePhpTimezone'         => true,
            'sqlMode'           => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
            'versionRequired'        => '5.7.32',
            'requireSSL'             => false,
            'databaseAutoCreate'     => true,
            'connectTimeout'         => 3,
            'readTimeout'            => 60,
            'usePreparedStatements'  => true,
            'useSmartJoins'          => true,
            'enableLogging'          => false,
            'logFile'                => '_mysql_query_log.php',
            'showSqlInErrors'        => false,
            'smartArrayLoadHandler'  => null,
        ];

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
            'sqlMode'           => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY',
            'versionRequired'        => '5.7.32',                 // minimum MySQL version required
            'requireSSL'             => false,                    // require SSL connections
            'databaseAutoCreate'     => true,                     // automatically creates database if it doesn't exist
            'connectTimeout'         => 1,                        // (low timeout for testing) connection timeout in seconds
            'readTimeout'            => 60,                       // read timeout in seconds
        ];

        // Set default values on the config directly
        foreach ($this->defaultValues as $key => $value) {
            $this->config->$key = $value;
        }
    }

    #endregion
    #region Config Class Tests

    public function testClassDefaultValues(): void
    {
        // Create a fresh Config instance with no values set
        $config = new Config();

        // Verify that the default values in the class match what we expect
        foreach ($this->expectedClassDefaults as $key => $expectedValue) {
            $this->assertSame(
                $expectedValue,
                $config->$key,
                "Default class value for $key should be " .
                (is_bool($expectedValue) ? ($expectedValue ? 'true' : 'false') :
                (is_null($expectedValue) ? 'null' : $expectedValue))
            );
        }

        // Check if all properties are accounted for in our test
        $objectVars = get_object_vars($config);
        $arrayCast = (array)$config;

        // Check that we've tested all public properties
        foreach ($objectVars as $key => $value) {
            $this->assertArrayHasKey(
                $key,
                $this->expectedClassDefaults,
                "Public property '$key' exists in Config but is not being tested"
            );
        }

        // Check for any non-public properties that might exist
        $nonPublicCount = count($arrayCast) - count($objectVars);
        $this->assertEquals(
            0,
            $nonPublicCount,
            "Found $nonPublicCount non-public properties in Config class. All properties should be public."
        );
    }


    public function testConstructorWithConfig(): void
    {
        $configData = [
            'hostname' => 'localhost',
            'username' => 'root',
            'password' => 'password',
            'database' => 'test_db',
            'connectTimeout' => 10,
            'readTimeout' => 30,
        ];

        $config = new Config($configData);

        $this->assertEquals('localhost', $config->hostname);
        $this->assertEquals('root', $config->username);
        $this->assertEquals('password', $config->password);
        $this->assertEquals('test_db', $config->database);
        $this->assertEquals(10, $config->connectTimeout);
        $this->assertEquals(30, $config->readTimeout);
    }

    public function testConfigurationAcceptsAnyValues(): void
    {
        $config = new Config();

        // Now we can set any values without validation directly
        $config->connectTimeout = -1;
        $this->assertEquals(-1, $config->connectTimeout, 'Should accept negative connect timeout');

        $config->readTimeout = 0;
        $this->assertEquals(0, $config->readTimeout, 'Should accept zero read timeout');

        // Set without requiring all fields
        $config->database = 'test_db';
        // No need for username or hostname
        $this->assertEquals('test_db', $config->database, 'Should accept database without hostname and username');
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

    public function testConfigDirectPropertySet(): void
    {
        $key = 'tablePrefix';
        $newValue = "changed_";

        // Setting a new value directly
        $this->config->$key = $newValue;

        // Retrieving to confirm it has changed
        $actualValue = $this->config->$key;
        $this->assertSame($newValue, $actualValue, "After setting a property directly, the value should match the newly set value.");

        // reset values
        $this->config->$key = $this->defaultValues[$key];
    }

    public function testConfigMultiplePropertySet(): void
    {
        // Define a set of key-value pairs to change in the config
        $newValues = [
            'tablePrefix'        => 'new_test_',
            'usePhpTimezone'     => true,
            'databaseAutoCreate' => false,
        ];

        // Apply these new settings directly
        foreach ($newValues as $key => $value) {
            $this->config->$key = $value;
        }

        // Verify that each setting was updated as expected
        foreach ($newValues as $key => $expectedValue) {
            $actualValue = $this->config->$key;
            $this->assertSame($expectedValue, $actualValue, sprintf("The value for the key '%s' should be '%s', but got '%s'", $key, $expectedValue, $actualValue));
        }

        // reset values
        foreach ($this->defaultValues as $key => $value) {
            $this->config->$key = $value;
        }
    }

    public function testConfigGetAllProperties(): void
    {
        // Get all configuration as an array using reflection
        $allConfig = get_object_vars($this->config);

        // Verify all default values are included
        foreach ($this->defaultValues as $key => $expectedValue) {
            $this->assertArrayHasKey($key, $allConfig, "Configuration array should contain key: $key");
            $this->assertSame($expectedValue, $allConfig[$key], "Value for key $key should match expected value");
        }
    }

    public function testPropertyCopy(): void
    {
        // Get all configuration as an array using reflection
        $allConfig = get_object_vars($this->config);

        // Modify the array (not the Config object)
        $originalValue = $allConfig['tablePrefix'];
        $allConfig['tablePrefix'] = 'modified_through_array_';

        // Verify that modifying the array doesn't affect the Config object
        $this->assertSame($originalValue, $this->config->tablePrefix,
            'The Config object should not be affected by changes to the copy of properties');
    }

    public function testSetNumericBoundaries(): void
    {
        // Test extreme values for numeric properties - now we can set any values since there's no validation
        $this->config->connectTimeout = 1;
        $this->assertSame(1, $this->config->connectTimeout, 'Setting to 1 should be valid for timeout');

        $this->config->connectTimeout = 0;
        $this->assertSame(0, $this->config->connectTimeout, 'Zero should be a valid timeout value');

        $this->config->connectTimeout = -1;
        $this->assertSame(-1, $this->config->connectTimeout, 'Negative values should be accepted');

        $this->config->connectTimeout = PHP_INT_MAX;
        $this->assertSame(PHP_INT_MAX, $this->config->connectTimeout, 'PHP_INT_MAX should be a valid timeout value');

        // Reset value
        $this->config->connectTimeout = $this->defaultValues['connectTimeout'];

        // Test boolean values
        $this->config->requireSSL = true;
        $this->assertTrue($this->config->requireSSL, 'Setting true boolean value should work');

        $this->config->requireSSL = false;
        $this->assertFalse($this->config->requireSSL, 'Setting false boolean value should work');

        // Reset value
        $this->config->requireSSL = $this->defaultValues['requireSSL'];
    }

    public function testInstancesAreIndependent(): void
    {
        // Create a second instance with different values
        $config2 = new Config();
        $config2->tablePrefix = 'different_prefix_';
        $config2->connectTimeout = 10;

        // Verify original instance is unchanged
        $this->assertSame($this->defaultValues['tablePrefix'], $this->config->tablePrefix, 'Original instance tablePrefix should be unchanged');
        $this->assertSame($this->defaultValues['connectTimeout'], $this->config->connectTimeout, 'Original instance connectTimeout should be unchanged');

        // Verify second instance has different values
        $this->assertSame('different_prefix_', $config2->tablePrefix, 'Second instance should have different tablePrefix');
        $this->assertSame(10, $config2->connectTimeout, 'Second instance should have different connectTimeout');
    }

    public function testUndefinedPropertyAssignment(): void
    {
        // Test that using undefined properties throws appropriate exceptions
        $config = new Config();
        
        // Setting an undefined property should throw InvalidArgumentException
        try {
            $config->undefinedProperty = 'test value';
            $this->fail('Setting an undefined property should throw an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'unknown configuration property',
                $e->getMessage(),
                'Exception message should indicate unknown property'
            );
        }
        
        // Getting an undefined property should also throw InvalidArgumentException
        try {
            $value = $config->undefinedProperty;
            $this->fail('Getting an undefined property should throw an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'unknown configuration property',
                $e->getMessage(),
                'Exception message should indicate unknown property'
            );
        }
        
        // Testing isset behavior with undefined property
        $this->assertFalse(
            isset($config->undefinedProperty),
            'isset() should return false for undefined properties'
        );
    }

    #endregion Config Class Tests
}
