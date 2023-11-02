<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded
require_once __DIR__ . '/bootstrap.php';

use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\Instance;
use Itools\SmartArray\SmartArray;
use PHPUnit\Framework\TestCase;

class InstanceTest extends BaseTest
{
    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance = new Instance(self::getConfigDefaults());
    }

    #region DBInstance Core Tests

    public function testInstanceCreation(): void
    {
        // Test creating with config array
        $instance1 = new Instance(self::getConfigDefaults());
        $this->assertInstanceOf(Instance::class, $instance1);

        // Test creating with Connection object
        $connection = new Connection(self::getConfigDefaults());
        $instance2 = new Instance($connection);
        $this->assertInstanceOf(Instance::class, $instance2);
    }

    public function testConnectionProperty(): void
    {
        $connection = $this->instance->connection;
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertTrue($connection->isConnected());
    }

    public function testQueryMethod(): void
    {
        // Reset test tables
        self::resetTempTestTables();

        // Test direct query
        $result = $this->instance->query("SELECT * FROM test_users WHERE num = ?", 1);
        $this->assertInstanceOf(SmartArray::class, $result);
        $this->assertEquals(1, $result->count());
        $this->assertEquals('John Doe', $result->first()->name->value());
    }

    public function testSelectMethod(): void
    {
        // Reset test tables
        self::resetTempTestTables();

        // Test select with array conditions
        $result = $this->instance->select('users', ['name' => 'John Doe']);
        $this->assertInstanceOf(SmartArray::class, $result);
        $this->assertEquals(1, $result->count());
        $this->assertEquals(1, $result->first()->num->value());
    }

    public function testGetMethod(): void
    {
        // Reset test tables
        self::resetTempTestTables();

        // Test get with ID
        $result = $this->instance->get('users', 1);
        $this->assertInstanceOf(SmartArray::class, $result);
        $this->assertEquals('John Doe', $result->name->value());
    }

    public function testFactoryMethod(): void
    {
        // Test DB::newInstance factory method
        $instance = DB::getDefaultInstance(self::getConfigDefaults());
        $this->assertInstanceOf(Instance::class, $instance);

        // Verify it works
        self::resetTempTestTables();
        $result = $instance->select('users', ['name' => 'John Doe']);
        $this->assertEquals(1, $result->count());
    }

    public function testDefaultInstance(): void
    {
        // Test getting default instance
        $instance = DB::getDefaultInstance();
        $this->assertInstanceOf(Instance::class, $instance);

        // Verify it works
        self::resetTempTestTables();
        $result = $instance->select('users', ['name' => 'John Doe']);
        $this->assertEquals(1, $result->count());
    }

    public function testMultipleInstancesWithDifferentConnections(): void
    {
        // Create two instances with different table prefixes
        $config1 = self::getConfigDefaults();
        $config1->tablePrefix = 'test1_';
        
        $config2 = self::getConfigDefaults();
        $config2->tablePrefix = 'test2_';

        $instance1 = new Instance($config1);
        $instance2 = new Instance($config2);

        // Verify they have different configurations
        $this->assertEquals('test1_', $instance1->connection->config->tablePrefix);
        $this->assertEquals('test2_', $instance2->connection->config->tablePrefix);
    }

    #endregion DBInstance Core Tests

    #region Static and Instance Method Tests

    /**
     * Test that both static and instance methods work correctly
     */
    public function testStaticAndInstanceMethodsEquivalent(): void
    {
        // Reset test tables
        self::resetTempTestTables();

        // Get a count using static method
        $staticCount = DB::count('users');

        // Create an instance and use instance method
        $db = DB::getDefaultInstance();
        $instanceCount = $db->count('users');

        // Both should return the same result
        $this->assertSame($staticCount, $instanceCount);
        $this->assertGreaterThan(0, $staticCount);
    }

    /**
     * Test that DB::newInstance() creates a DB object that delegates to DBInstance
     */
    public function testConstructorReturnsInstanceObject(): void
    {
        $db = DB::newInstance();
        $this->assertInstanceOf(Instance::class, $db);
    }

    /**
     * Test that DB::getInstance() returns a DBInstance
     */
    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = DB::getDefaultInstance();
        $instance2 = DB::getDefaultInstance();

        // Both should be the same instance
        $this->assertSame($instance1, $instance2);
        $this->assertInstanceOf(Instance::class, $instance1);
    }

    /**
     * Test that DB::newInstance() delegates to the same instance as getInstance()
     */
    public function testNewDBDelegatesToSameInstance(): void
    {
        // Reset test tables
        self::resetTempTestTables();

        // Make a change using static method
        DB::update('users', ['name' => 'Updated Name'], 1);

        // Verify the change using instance method
        $db = DB::newInstance();
        $result = $db->get('users', 1);

        // The change should be visible
        $this->assertSame('Updated Name', $result->name->value());
    }

    #endregion Static and Instance Method Tests
}
