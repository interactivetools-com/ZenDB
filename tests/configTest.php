<?php
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace tests;


use InvalidArgumentException, TypeError;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;

class configTest extends BaseTest
{

    // Runs before each test method in this class
    protected function setUp(): void {

        DB::disconnect();
        // reset config to defaults
        DB::config(self::$configDefaults);

    }

//    public function testConfigCanGetEntireConfig(): void {
//        $actualConfig = DB::config();
//        $this->assertSame(self::$configDefaults, $actualConfig, "The retrieved config should match the expected default config.");
//    }


    public function testConfigCanGetSingleValue(): void {
        $key = 'tablePrefix';
        $expectedValue = self::$configDefaults[$key];
        $actualValue   = DB::config($key);
        $this->assertSame($expectedValue, $actualValue, "Retrieved single config value should match the expected value.");
    }

    public function testConfigCanSetSingleValue(): void {
        $key = 'tablePrefix';
        $newValue = "changed_";

        // Setting a new value
        DB::config($key, $newValue);

        // Retrieving to confirm it has changed
        $actualValue = DB::config($key);
        $this->assertSame($newValue, $actualValue, "After setting a new value, the retrieved value should match the newly set value.");

        // reset values
        DB::config(self::$configDefaults);

    }

    public function testConfigCanSetMultipleValues(): void {
        // Define a set of key-value pairs to change in the config
        $newValues = [
            'tablePrefix'        => 'new_test_',
            'usePhpTimezone'     => true,
            'databaseAutoCreate' => false,
        ];

        // Apply these new settings
        DB::config($newValues);

        // Verify that each setting was updated as expected
        foreach ($newValues as $key => $expectedValue) {
            $actualValue = DB::config($key);
            $this->assertSame($expectedValue, $actualValue, sprintf("The value for the key '%s' should be '%s', but got '%s'", $key, $expectedValue, $actualValue));
        }

        // reset values
        DB::config(self::$configDefaults);
    }

    public function testConfigThrowsExceptionForInvalidKey(): void {
        $this->expectException(InvalidArgumentException::class);
        DB::config("invalidKey", "someValue");
    }

    public function testConfigPreConnectOnlyVars(): void {
        $this->expectException(InvalidArgumentException::class);

        // test setting vars that can only be set before connecting
        DB::connect();
        DB::config("hostname", "example.com");
    }

    public function testConfigSetValidatesKeyType(): void {
        $this->expectException(TypeError::class);
        DB::config(['tablePrefix'], "someValue");  // passing an array instead of string as key
    }

    public function testConfigSetValidatesValueType(): void {
        $this->expectException(InvalidArgumentException::class);
        DB::config('connectTimeout', "string_value");  // should be int
    }

    public function testConfigSetChecksForUnsafeCharacters(): void {
        $this->expectException(DBException::class);
        DB::config("hostname", "'bad"."host");  // using unsafe character like single quote, concatenating to avoid IDE warning
    }

    public function testConfigSetValidatesTimeZone(): void {
        $this->expectException(InvalidArgumentException::class);
        DB::config('usePhpTimezone', "invalid/timezone");  // using invalid timezone
    }

}
