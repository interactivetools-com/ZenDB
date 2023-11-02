<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded
require_once __DIR__ . '/bootstrap.php';

use Itools\ZenDB\DB;
use Itools\ZenDB\RawSql;

class RawSqlTest extends BaseTest
{
    /**
     * Test creating a RawSql object and converting it to string
     */
    public function testConstructAndToString(): void
    {
        // Arrange
        $sqlValue = "NOW()";

        // Act
        $rawSql = new RawSql($sqlValue);
        $result = (string)$rawSql;

        // Assert
        $this->assertSame($sqlValue, $result, 'RawSql should convert to its original value when cast to string');
    }

    /**
     * Test RawSql with various value types
     */
    public function testWithDifferentValueTypes(): void
    {
        // String value
        $rawSql1 = new RawSql("CURRENT_DATE");
        $this->assertSame("CURRENT_DATE", (string)$rawSql1);

        // Integer value (converted to string via DB::rawSql)
        $rawSql2 = DB::rawSql(123);
        $this->assertSame("123", (string)$rawSql2);

        // Empty string
        $rawSql3 = new RawSql("");
        $this->assertSame("", (string)$rawSql3);
    }

    /**
     * Test creating RawSql via DB::rawSql static method
     */
    public function testCreateViaDBStaticMethod(): void
    {
        // Act
        $rawSql = DB::rawSql("NOW()");

        // Assert
        $this->assertInstanceOf(RawSql::class, $rawSql);
        $this->assertSame("NOW()", (string)$rawSql);
    }

    /**
     * Test DB::isRawSql method for type checking
     */
    public function testIsRawSqlMethod(): void
    {
        // Arrange
        $rawSql = new RawSql("NOW()");
        $notRawSql = "NOW()";

        // Act & Assert
        $this->assertTrue(DB::isRawSql($rawSql));
        $this->assertFalse(DB::isRawSql($notRawSql));
        $this->assertFalse(DB::isRawSql(null));
        $this->assertFalse(DB::isRawSql(123));
    }

    /**
     * Test using RawSql in a query
     */
    public function testUseInQuery(): void
    {
        // Arrange
        self::resetTempTestTables();

        // Act
        $result = DB::query("SELECT ?", DB::rawSql("NOW()"))->first();

        // Assert
        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result->nth(0)->value());
    }

    /**
     * Test using RawSql with SQL functions
     */
    public function testWithSqlFunctions(): void
    {
        // Act
        $result = DB::query("SELECT ?", DB::rawSql("1+1"))->first();

        // Assert
        $this->assertSame(2, $result->nth(0)->value());
    }

    /**
     * Test using RawSql in a WHERE clause
     */
    public function testInWhereClause(): void
    {
        // Arrange
        self::resetTempTestTables();

        // Act - find users created before now
        $result = DB::select("users", "dob < ?", DB::rawSql("NOW()"));

        // Assert - should find all users
        $this->assertGreaterThan(0, $result->count());
    }
}