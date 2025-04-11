<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTest;

/**
 * Tests for the DB::config methods
 */
class configTest extends BaseTest
{
    private array $originalValues = [];

    // Runs before each test method in this class
    protected function setUp(): void
    {
        parent::setUp();
        
        // Store original values for keys we'll modify
        $this->originalValues['tablePrefix'] = DB::config('tablePrefix');
        $this->originalValues['connectTimeout'] = DB::config('connectTimeout');
        $this->originalValues['requireSSL'] = DB::config('requireSSL');
    }
    
    // Runs after each test method in this class
    protected function tearDown(): void
    {
        // Restore original values
        if (!empty($this->originalValues)) {
            foreach ($this->originalValues as $key => $value) {
                DB::config($key, $value);
            }
        }
        
        parent::tearDown();
    }
    
    public function testGetSingleValue(): void
    {
        // Test getting a single configuration value
        $value = DB::config('tablePrefix');
        $this->assertIsString($value, 'Getting tablePrefix should return a string');
        $this->assertSame($this->originalValues['tablePrefix'], $value, 'Should return the correct value');
    }

    public function testSetSingleValue(): void
    {
        // Test setting a single value
        $newValue = 'test_db_config_';
        DB::config('tablePrefix', $newValue);

        // Verify the value was set correctly
        $this->assertSame($newValue, DB::config('tablePrefix'), 'DB::config() should set value correctly');
    }

    public function testGetAllValues(): void
    {
        // Get all configuration
        $allConfig = DB::config();

        // Verify structure of returned config array
        $this->assertIsArray($allConfig, 'Getting all config should return an array');
        $this->assertArrayHasKey('tablePrefix', $allConfig, 'Config array should include tablePrefix');
        $this->assertArrayHasKey('hostname', $allConfig, 'Config array should include hostname');
        $this->assertArrayHasKey('username', $allConfig, 'Config array should include username');
    }

    public function testSetMultipleValues(): void
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

    public function testInvalidKey(): void
    {
        // Test that requesting an invalid key throws an exception
        $this->expectException(InvalidArgumentException::class);
        DB::config('nonExistentKey');
    }

    public function testStaticTablePrefixUpdates(): void
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