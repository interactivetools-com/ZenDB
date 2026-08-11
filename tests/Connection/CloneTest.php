<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use Itools\SmartString\SmartString;
use InvalidArgumentException;

/**
 * Tests for Connection clone behavior and settings independence
 */
class CloneTest extends BaseTestCase
{
    protected static Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = self::createDefaultConnection();
        self::resetTestTables();
    }

    protected function tearDown(): void
    {
        // Reset default connection settings after each test
        self::$conn->useSmartJoins   = true;
        self::$conn->useSmartStrings = true;
    }

    //region Clone Shares Connection

    public function testCloneSharesMysqliConnection(): void
    {
        $clone = DB::clone();

        $this->assertSame(DB::$mysqli, $clone->mysqli);
    }

    public function testCloneCanExecuteQueries(): void
    {
        $clone  = DB::clone();
        $result = $clone->select('users', ['num' => 1]);

        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testParentCanExecuteQueriesAfterClone(): void
    {
        $clone  = DB::clone();
        $result = DB::select('users', ['num' => 1]);

        $this->assertSame('John Doe', $result->first()->get('name')->value());
        $this->assertInstanceOf(\Itools\ZenDB\Connection::class, $clone);
    }

    public function testBothParentAndCloneCanExecuteSequentially(): void
    {
        $clone = DB::clone();

        $parentResult = DB::select('users', ['num' => 1]);
        $cloneResult  = $clone->select('users', ['num' => 2]);

        $this->assertSame('John Doe', $parentResult->first()->get('name')->value());
        $this->assertSame('Jane Janey Doe', $cloneResult->first()->get('name')->value());
    }

    //endregion
    //region Clone Has Independent Settings

    public function testCloneHasIndependentUseSmartJoins(): void
    {
        $joinSql = "SELECT u.name, o.total_amount FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?";

        $clone     = DB::clone(['useSmartJoins' => false]);
        $cloneRow  = $clone->query($joinSql, 6)->first();
        $parentRow = self::$conn->query($joinSql, 6)->first();

        $this->assertFalse(isset($cloneRow['users.name']), "Clone with useSmartJoins=false must not add qualified keys");
        $this->assertSame('Dave Williams', $parentRow->get('users.name')->value(), "Parent keeps smart joins enabled");
    }

    public function testCloneHasIndependentUseSmartStrings(): void
    {
        $clone = DB::clone(['useSmartStrings' => false]);

        $this->assertIsString($clone->select('users', ['num' => 1])->first()->name, "Clone with useSmartStrings=false must return raw values");
        $this->assertInstanceOf(SmartString::class, self::$conn->select('users', ['num' => 1])->first()->name, "Parent keeps SmartString values");
    }

    public function testCloneHasIndependentTablePrefix(): void
    {
        // A same-name table under a different prefix; only the reprefixed clone should reach it
        self::$conn->mysqli->query("CREATE TEMPORARY TABLE other_users (num INT PRIMARY KEY, name VARCHAR(255))");
        self::$conn->mysqli->query("INSERT INTO other_users VALUES (1, 'Other Olive')");

        $clone = DB::clone(['tablePrefix' => 'other_']);

        $this->assertSame('Other Olive', $clone->select('users', ['num' => 1])->first()->name->value(), "Clone must prefix table names with its own tablePrefix");
        $this->assertSame('John Doe', self::$conn->select('users', ['num' => 1])->first()->name->value(), "Parent keeps its own tablePrefix");
    }

    public function testChangingCloneSettingsDoesNotAffectParent(): void
    {
        $clone = DB::clone();

        $clone->useSmartJoins   = false;
        $clone->useSmartStrings = false;
        $clone->tablePrefix     = 'changed_';

        $this->assertTrue(self::$conn->useSmartJoins);
        $this->assertTrue(self::$conn->useSmartStrings);
        $this->assertSame('test_', self::$conn->tablePrefix);
    }

    public function testChangingParentSettingsDoesNotAffectClone(): void
    {
        $clone = DB::clone();

        self::$conn->useSmartJoins = false;

        $this->assertTrue($clone->useSmartJoins);
    }

    //endregion
    //region Clone Destructor Behavior

    public function testCloneDestructorDoesNotCloseConnection(): void
    {
        $originalMysqli = DB::$mysqli;

        $clone = DB::clone();
        unset($clone);

        $this->assertTrue(DB::isConnected(true));
        $this->assertSame($originalMysqli, DB::$mysqli);
    }

    public function testMultipleClonesCanBeDestroyedSafely(): void
    {
        $clone1 = DB::clone();
        $clone2 = DB::clone();
        $clone3 = DB::clone();

        unset($clone1, $clone2, $clone3);

        $this->assertTrue(DB::isConnected(true));
    }

    //endregion
    //region Clone Error Handling

    public function testCloneWithUnknownConfigKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("clone() only supports:");

        DB::clone(['invalidKey' => 'value']);
    }

    //endregion
    //region Instance Clone Method

    public function testInstanceCloneMethod(): void
    {
        $clone = self::$conn->clone();

        $this->assertSame(self::$conn->mysqli, $clone->mysqli);
        $this->assertNotSame(self::$conn, $clone);
    }

    public function testInstanceCloneWithOverrides(): void
    {
        $clone = self::$conn->clone(['useSmartJoins' => false]);
        $row   = $clone->query("SELECT u.name, o.total_amount FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?", 6)->first();

        $this->assertFalse(isset($row['users.name']), "Instance clone with useSmartJoins=false must not add qualified keys");
        $this->assertTrue(self::$conn->useSmartJoins);
    }

    //endregion
}
