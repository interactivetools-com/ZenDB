<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);
namespace tests;

use Itools\ZenDB\DB;
use InvalidArgumentException;
use RuntimeException;

class connectTest extends BaseTest
{

    public static function setUpBeforeClass(): void {

    }

    // Runs before each test method in this class
    protected function setUp(): void {
        DB::disconnect(); // make sure we're disconnected before test
        DB::config(self::$configDefaults); // reset config to defaults
    }

    protected function tearDown(): void {
      DB::disconnect(); // make sure we disconnect after test
    }

    public function testNotConnectedWhenStartingTests(): void {
        $this->assertFalse(DB::isConnected());
    }

    public function testConnectWithValidCredentials(): void {
        DB::disconnect();
        DB::connect();
        $result = DB::isConnected();
        $this->assertTrue($result);
    }


    public function testConnectWithInvalidHostname(): void {
        $this->expectException(RuntimeException::class);
        DB::config('hostname', 'invalid_value');
        DB::connect();
    }


    public function testConnectWithInvalidHostname2(): void {
        $this->expectException(RuntimeException::class);
        DB::config('hostname', '0.0.0.-1');
        DB::connect();
    }


    public function testConnectWithInvalidUsername(): void {
        $this->expectException(RuntimeException::class);
        DB::config('username', 'invalid_value');
        DB::connect();
    }

    public function testConnectWithInvalidPassword(): void {
        $this->expectException(RuntimeException::class);
        DB::config("password", "invalid_value");
        DB::connect();
    }

    public function testConnectWithInvalidDatabase(): void {
        $this->expectException(InvalidArgumentException::class);
        DB::config("database", "@#$%&^");
        DB::connect();
    }
    public function testConnectWithTooLongDatabaseName(): void {
        $database = str_repeat('x', 65);

        $this->expectException(RuntimeException::class);
        DB::config('database', $database);
        DB::connect();
    }

    public function testConnectWithMissingCredentials(): void {
        $this->expectException(InvalidArgumentException::class);
        DB::config([
            'hostname' => null,
            'username' => null,
            'password' => null
        ]);
        DB::connect();
    }

    public function testConnectWhileAlreadyConnected(): void {
        $sessionVarValue = 'TestValue';

        // Set a MySQL session variable
        DB::connect();
        DB::$mysqli->query("SET @session_var = '$sessionVarValue'") || throw new RuntimeException("Error setting session variable: " . DB::$mysqli->error);

        // Call connect() again - should do nothing.
        DB::connect();

        // Fetch the session variable value
        $postConnectValue = DB::$mysqli->query("SELECT @session_var")->fetch_assoc()['@session_var'];

        // Assert session variable is still set (meaning it's the same connection)
        $this->assertSame($sessionVarValue, $postConnectValue, 'Session variable not persistent, connection might have reset.');
    }



    public function testConnectWithAutoCreateDatabase(): void {

        // Test auto creating database
        $database = "testplan_test_auto_create_database";
        DB::config('database', $database);
        DB::disconnect();
        DB::connect(); // should auto create database (or throw exception)

        // Manually check if the database exists and is selected
        $selectedDatabase = DB::$mysqli->query("SELECT DATABASE() as db")->fetch_assoc()['db'];

        // Using PHPUnit's assertSame to validate the selected database
        $this->assertSame($database, $selectedDatabase, "Couldn't auto-create and select database: " . $selectedDatabase);

        // Remove Database
        if (!DB::$mysqli->query("DROP DATABASE `$database`")) {
            throw new RuntimeException("Error dropping database: " . DB::$mysqli->error);
        }
    }

    public function testConnectWithRequiredVersion(): void {
        $this->expectException(RuntimeException::class);
        DB::config('versionRequired', '100.100.100');
        DB::connect();
    }

}
