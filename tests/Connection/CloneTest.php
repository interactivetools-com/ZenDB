<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
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
        self::resetTempTestTables();
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
        $this->assertNotNull($clone);
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
        $this->assertTrue(self::$conn->useSmartJoins);

        $clone = DB::clone(['useSmartJoins' => false]);

        $this->assertFalse($clone->useSmartJoins);
        $this->assertTrue(self::$conn->useSmartJoins);
    }

    public function testCloneHasIndependentUseSmartStrings(): void
    {
        $this->assertTrue(self::$conn->useSmartStrings);

        $clone = DB::clone(['useSmartStrings' => false]);

        $this->assertFalse($clone->useSmartStrings);
        $this->assertTrue(self::$conn->useSmartStrings);
    }

    public function testCloneHasIndependentTablePrefix(): void
    {
        $clone = DB::clone(['tablePrefix' => 'other_']);

        $this->assertSame('other_', $clone->tablePrefix);
        $this->assertSame('test_', self::$conn->tablePrefix);
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
        $this->expectExceptionMessage("Unknown configuration key: 'invalidKey'");

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

        $this->assertFalse($clone->useSmartJoins);
        $this->assertTrue(self::$conn->useSmartJoins);
    }

    //endregion
}
