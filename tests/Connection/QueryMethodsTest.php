<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for Connection instance query methods
 */
class QueryMethodsTest extends BaseTestCase
{
    protected static Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = self::createDefaultConnection();
        self::resetTempTestTables();
    }

    protected function setUp(): void
    {
        self::resetTempTestTables();
    }

    //region Instance select() and selectOne()

    public function testInstanceSelect(): void
    {
        $result = self::$conn->select('users', ['num' => 1]);
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testInstanceSelectWithConditions(): void
    {
        $result = self::$conn->select('users', ['status' => 'Active']);
        $this->assertSame(10, $result->count());
    }

    public function testInstanceSelectOne(): void
    {
        $result = self::$conn->selectOne('users', ['num' => 2]);
        $this->assertSame('Jane Janey Doe', $result->get('name')->value());
    }

    public function testInstanceSelectOneReturnsEmptyForNoMatch(): void
    {
        $result = self::$conn->selectOne('users', ['num' => 9999]);
        $this->assertTrue($result->isEmpty());
    }

    //endregion
    //region Instance insert()

    public function testInstanceInsert(): void
    {
        $insertId = self::$conn->insert('users', [
            'name'    => 'Instance Insert Test',
            'isAdmin' => 0,
            'status'  => 'Active',
            'city'    => 'TestCity',
            'dob'     => '2000-01-01',
            'age'     => 24,
        ]);

        $this->assertSame(21, $insertId);

        // Read back and verify inserted data
        $row = self::$conn->selectOne('users', ['num' => 21]);
        $this->assertSame('Instance Insert Test', $row->get('name')->value());
        $this->assertSame('TestCity', $row->get('city')->value());
    }

    //endregion
    //region Instance update()

    public function testInstanceUpdate(): void
    {
        $affected = self::$conn->update('users', ['name' => 'Updated via Instance'], ['num' => 1]);

        $this->assertSame(1, $affected);
        $this->assertSame('Updated via Instance', self::$conn->selectOne('users', ['num' => 1])->get('name')->value());
    }

    //endregion
    //region Instance delete()

    public function testInstanceDelete(): void
    {
        $this->assertSame(20, self::$conn->count('users'));
        $affected = self::$conn->delete('users', ['num' => 1]);

        $this->assertSame(1, $affected);
        $this->assertSame(19, self::$conn->count('users'));
        $this->assertTrue(self::$conn->selectOne('users', ['num' => 1])->isEmpty());
    }

    //endregion
    //region Instance count()

    public function testInstanceCount(): void
    {
        $count = self::$conn->count('users');
        $this->assertSame(20, $count);
    }

    public function testInstanceCountWithCondition(): void
    {
        $count = self::$conn->count('users', ['status' => 'Active']);
        $this->assertSame(10, $count);
    }

    //endregion
    //region Instance query()

    public function testInstanceQuery(): void
    {
        $result = self::$conn->query("SELECT * FROM `:_users` WHERE num = ?", 1);
        $this->assertSame(1, $result->count());
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    //endregion
}
