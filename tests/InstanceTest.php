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
        // Get connection first, then create instance
        DB::disconnect();
        DB::config(self::getConfigArray());
        DB::connect();
        $this->instance = DB::newInstance();
    }

    #region DBInstance Core Tests

    public function testInstanceCreation(): void
    {
        // Test creating via DB::newInstance
        $instance1 = DB::newInstance();
        $this->assertInstanceOf(Instance::class, $instance1);

        // Test creating a second instance (not the same instance)
        $instance2 = DB::newInstance();
        $this->assertNotSame($instance1, $instance2);
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
        $instance = DB::newInstance();
        $this->assertInstanceOf(Instance::class, $instance);

        // Verify it works
        self::resetTempTestTables();
        $result = $instance->select('users', ['name' => 'John Doe']);
        $this->assertEquals(1, $result->count());
    }

    public function testNewInstance(): void
    {
        // Test getting a new instance
        $instance = DB::newInstance();
        $this->assertInstanceOf(Instance::class, $instance);

        // Verify it works
        self::resetTempTestTables();
        $result = $instance->select('users', ['name' => 'John Doe']);
        $this->assertEquals(1, $result->count());
    }

    public function testMultipleInstancesWithDifferentConnections(): void
    {
        // Create two instances with different table prefixes
        $instance1 = DB::newInstance(['tablePrefix' => 'test1_']);
        $instance2 = DB::newInstance(['tablePrefix' => 'test2_']);

        // Verify they have different configurations
        $this->assertEquals('test1_', $instance1->tablePrefix);
        $this->assertEquals('test2_', $instance2->tablePrefix);
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
        $db = DB::newInstance();
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
     * Test that DB::newInstance() returns instances that share the same connection
     */
    public function testNewInstanceSharesConnection(): void
    {
        $instance1 = DB::newInstance();
        $instance2 = DB::newInstance();

        // newInstance() creates a fresh instance each time but shares the connection
        // So we validate they're NOT the same instance but have the same connection
        $this->assertNotSame($instance1, $instance2, 'Instances should not be the same object');
        $this->assertInstanceOf(Instance::class, $instance1);
        $this->assertSame($instance1->connection, $instance2->connection, 'Connections should be the same');
    }

    /**
     * Test that DB::newInstance() delegates queries correctly and shares data
     */
    public function testNewInstanceSharesData(): void
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
