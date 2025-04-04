<?php
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace tests;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;

/**
 * Tests for the DB::config wrapper method
 */
class DBConfigTest extends BaseTest
{
    private array $originalValues = [];
    
    // Runs before each test method in this class
    protected function setUp(): void
    {
        DB::disconnect();
        
        // Store original values for keys we'll modify
        $this->originalValues['tablePrefix'] = DB::config('tablePrefix');
        $this->originalValues['connectTimeout'] = DB::config('connectTimeout');
        $this->originalValues['requireSSL'] = DB::config('requireSSL');
    }
    
    // Runs after each test method in this class
    protected function tearDown(): void
    {
        // Restore original values
        foreach ($this->originalValues as $key => $value) {
            DB::config($key, $value);
        }
    }

    public function testDBConfigGetSingleValue(): void
    {
        // Test getting a single configuration value
        $value = DB::config('tablePrefix');
        $this->assertIsString($value, 'Getting tablePrefix should return a string');
        $this->assertSame($this->originalValues['tablePrefix'], $value, 'Should return the correct value');
    }
    
    public function testDBConfigSetSingleValue(): void
    {
        // Test setting a single value
        $newValue = 'test_db_config_';
        DB::config('tablePrefix', $newValue);
        
        // Verify the value was set correctly
        $this->assertSame($newValue, DB::config('tablePrefix'), 'DB::config() should set value correctly');
    }
    
    public function testDBConfigGetAllValues(): void
    {
        // Get all configuration
        $allConfig = DB::config();
        
        // Verify structure of returned config array
        $this->assertIsArray($allConfig, 'Getting all config should return an array');
        $this->assertArrayHasKey('tablePrefix', $allConfig, 'Config array should include tablePrefix');
        $this->assertArrayHasKey('hostname', $allConfig, 'Config array should include hostname');
        $this->assertArrayHasKey('username', $allConfig, 'Config array should include username');
    }
    
    public function testDBConfigSetMultipleValues(): void
    {
        // Test setting multiple values at once
        $multiValues = [
            'tablePrefix' => 'multi_test_',
            'connectTimeout' => 5
        ];
        
        DB::config($multiValues);
        
        // Verify all values were set correctly
        foreach ($multiValues as $key => $expectedValue) {
            $this->assertSame($expectedValue, DB::config($key), 
                "DB::config() should set multiple values correctly for key: $key");
        }
    }
    
    public function testDBConfigInvalidKey(): void
    {
        // Test that requesting an invalid key throws an exception
        $this->expectException(InvalidArgumentException::class);
        DB::config('nonExistentKey');
    }
    
    public function testDBStaticTablePrefixUpdates(): void
    {
        // Test that static $tablePrefix is updated when config is changed
        $originalStaticPrefix = DB::$tablePrefix;
        $newPrefix = 'static_prefix_test_';
        
        // Change prefix through config
        DB::config('tablePrefix', $newPrefix);
        
        // Verify that static property was updated
        $this->assertSame($newPrefix, DB::$tablePrefix, 
            'Static DB::$tablePrefix should be updated when tablePrefix is changed via config');
        
        // Reset to original
        DB::config('tablePrefix', $originalStaticPrefix);
    }
}