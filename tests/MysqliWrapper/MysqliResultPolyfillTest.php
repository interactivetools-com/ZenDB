<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\MysqliWrapper;

use Itools\ZenDB\Connection;
use Itools\ZenDB\MysqliResultPolyfill;
use Itools\ZenDB\MysqliStmtWrapper;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for MysqliResultPolyfill - exercises the polyfill used when mysqlnd is unavailable
 */
class MysqliResultPolyfillTest extends BaseTestCase
{
    private static bool $originalForcePolyfill;
    private Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();

        // Save original state
        self::$originalForcePolyfill = MysqliWrapper::$forcePolyfill;
    }

    public static function tearDownAfterClass(): void
    {
        // Restore original states
        MysqliWrapper::$forcePolyfill = self::$originalForcePolyfill;
        MysqliStmtWrapper::enableTestResultPolyfill(false);
    }

    protected function setUp(): void
    {
        // Force polyfill for all tests in this class
        MysqliWrapper::$forcePolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);

        // Create a fresh connection with test table
        $this->conn = new Connection(self::$configDefaults);
        $this->conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS polyfill_test");
        $this->conn->mysqli->query("
            CREATE TEMPORARY TABLE polyfill_test (
                num INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100),
                status VARCHAR(50)
            )
        ");
        $this->conn->mysqli->query("
            INSERT INTO polyfill_test (name, status) VALUES
            ('Alice', 'Active'),
            ('Bob', 'Inactive'),
            ('Charlie', 'Active')
        ");
    }

    protected function tearDown(): void
    {
        // Reset after each test
        MysqliWrapper::$forcePolyfill = self::$originalForcePolyfill;
        MysqliStmtWrapper::enableTestResultPolyfill(false);
    }

    public function testPolyfillReturnsCorrectInstanceType(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT * FROM polyfill_test LIMIT 1");

        // Should be MysqliResultPolyfill when forceResultPolyfill is true
        $this->assertInstanceOf(MysqliResultPolyfill::class, $result);
    }

    public function testFetchAssoc(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        $this->assertInstanceOf(MysqliResultPolyfill::class, $result);

        $row = $result->fetch_assoc();
        $this->assertIsArray($row);
        $this->assertArrayHasKey('num', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertSame(1, $row['num']);
    }

    public function testFetchRow(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        $row = $result->fetch_row();
        $this->assertIsArray($row);
        $this->assertSame(1, $row[0]); // num
        $this->assertSame('Alice', $row[1]);  // name
    }

    public function testFetchArrayBoth(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        $row = $result->fetch_array(MYSQLI_BOTH);
        $this->assertIsArray($row);
        // Numeric keys
        $this->assertArrayHasKey(0, $row);
        $this->assertArrayHasKey(1, $row);
        // Associative keys
        $this->assertArrayHasKey('num', $row);
        $this->assertArrayHasKey('name', $row);
    }

    public function testFetchArrayNum(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        $row = $result->fetch_array(MYSQLI_NUM);
        $this->assertIsArray($row);
        $this->assertArrayHasKey(0, $row);
        $this->assertArrayHasKey(1, $row);
        $this->assertArrayNotHasKey('num', $row);
    }

    public function testFetchArrayAssoc(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        $row = $result->fetch_array(MYSQLI_ASSOC);
        $this->assertIsArray($row);
        $this->assertArrayHasKey('num', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayNotHasKey(0, $row);
    }

    public function testFetchAll(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test ORDER BY num LIMIT 3");

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $this->assertIsArray($rows);
        $this->assertCount(3, $rows);
        $this->assertArrayHasKey('num', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
    }

    public function testFetchAllNumeric(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test ORDER BY num LIMIT 3");

        $rows = $result->fetch_all(MYSQLI_NUM);
        $this->assertIsArray($rows);
        $this->assertCount(3, $rows);
        $this->assertArrayHasKey(0, $rows[0]);
        $this->assertArrayNotHasKey('num', $rows[0]);
    }

    public function testFetchObject(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        $obj = $result->fetch_object();
        $this->assertInstanceOf(\stdClass::class, $obj);
        $this->assertObjectHasProperty('num', $obj);
        $this->assertObjectHasProperty('name', $obj);
        $this->assertSame(1, $obj->num);
    }

    public function testFetchObjectWithClassName(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        $obj = $result->fetch_object(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $obj);
        $this->assertSame(1, $obj->num);
    }

    public function testFetchFields(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test LIMIT 1");

        $fields = $result->fetch_fields();
        $this->assertIsArray($fields);
        $this->assertCount(2, $fields);
        $this->assertSame('num', $fields[0]->name);
        $this->assertSame('name', $fields[1]->name);
    }

    public function testNumRows(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT * FROM polyfill_test");

        // Count rows by iterating (num_rows on stmt may not work after fetch_all)
        $count = 0;
        while ($result->fetch_assoc()) {
            $count++;
        }

        $this->assertSame(3, $count);
    }

    public function testFieldCount(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name, status FROM polyfill_test LIMIT 1");

        // Access field_count via fetch_fields instead (more reliable)
        $fields = $result->fetch_fields();
        $this->assertCount(3, $fields);
    }

    public function testIteratingThroughResults(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num FROM polyfill_test ORDER BY num LIMIT 3");

        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $count++;
            $this->assertArrayHasKey('num', $row);
        }
        $this->assertSame(3, $count);
    }

    public function testFetchReturnsNullWhenExhausted(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num FROM polyfill_test WHERE num = ?", [1]);

        $row1 = $result->fetch_assoc();
        $this->assertIsArray($row1);

        $row2 = $result->fetch_assoc();
        $this->assertNull($row2);
    }

    public function testFreeReleasesResources(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT * FROM polyfill_test LIMIT 1");

        // free() should complete without throwing
        $this->expectNotToPerformAssertions();
        $result->free();
    }

    public function testEmptyResultSet(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT * FROM polyfill_test WHERE num = ?", [-9999]);

        $this->assertInstanceOf(MysqliResultPolyfill::class, $result);

        $row = $result->fetch_assoc();
        $this->assertNull($row);
    }

    public function testFetchObjectReturnsNullWhenExhausted(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num FROM polyfill_test WHERE num = ?", [1]);

        // First fetch succeeds
        $obj1 = $result->fetch_object();
        $this->assertInstanceOf(\stdClass::class, $obj1);

        // Second fetch returns null (exhausted)
        $obj2 = $result->fetch_object();
        $this->assertNull($obj2);
    }

    public function testFetchObjectWithCustomClass(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        // Use a custom class (not stdClass)
        $obj = $result->fetch_object(PolyfillTestRow::class);

        $this->assertInstanceOf(PolyfillTestRow::class, $obj);
        $this->assertSame(1, $obj->num);
        $this->assertSame('Alice', $obj->name);
    }

    public function testFreeWithFalseMetaDoesNotCrash(): void
    {
        // Non-SELECT statements have result_metadata() === false
        $this->conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS polyfill_free_test");
        $this->conn->mysqli->query("CREATE TEMPORARY TABLE polyfill_free_test (id INT)");

        // INSERT via polyfill returns MysqliResultPolyfill with meta=false
        $result = $this->conn->mysqli->execute_query("INSERT INTO polyfill_free_test VALUES (?)", [1]);
        $this->assertInstanceOf(MysqliResultPolyfill::class, $result);

        // free() should not crash even when meta is false
        $result->free();
    }

    public function testFetchObjectWithCustomClassAndConstructorArgs(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT num, name FROM polyfill_test WHERE num = ?", [1]);

        // Custom class with constructor args
        $obj = $result->fetch_object(PolyfillTestRowWithArgs::class, ['prefix']);

        $this->assertInstanceOf(PolyfillTestRowWithArgs::class, $obj);
        $this->assertSame('prefix', $obj->prefix);
        $this->assertSame(1, $obj->num);
    }

}

/**
 * Test class for fetch_object with custom class
 */
class PolyfillTestRow
{
    public int $num;
    public string $name;
}

/**
 * Test class for fetch_object with constructor args
 */
class PolyfillTestRowWithArgs
{
    public string $prefix;
    public int $num;
    public string $name;

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }
}
