<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded
require_once __DIR__ . '/bootstrap.php';

use InvalidArgumentException, TypeError;
use Itools\ZenDB\DB, Itools\ZenDB\RawSql, Itools\ZenDB\Parser;
use Itools\ZenDB\DBException;
use stdClass;
use Throwable;

/**
 * Tests for the Parser class and SQL generation functionality
 */
class ParserTest extends BaseTest
{
    /**
     * Tests for getWhereEtc method
     */

    private array   $whereEtcParamMapDefault = [':1' => 'Bob', ':name' => 'Tom'];
    private ?Parser $whereEtcParser          = null;

    protected function setUpWhereEtcTest(): void
    {
        // Reset DB parser
        $parser = new Parser();

        // Set the initial parameter map for testing
        $reflection = new \ReflectionClass(Parser::class);
        $property   = $reflection->getProperty('paramMap');
        $property->setAccessible(true);
        $property->setValue($parser, $this->whereEtcParamMapDefault);

        // Create a parser instance for testing
        $this->whereEtcParser = $parser;
    }

    public function testValidSqlClauses(): void
    {
        $this->setUpWhereEtcTest();

        $validClauses = [
            "", // empty string allowed
            "WHERE something = something",
            "FOR UPDATE",
            "  FOR  UPDATE",
            "\n FOR \n UPDATE \n ",
            "ORDER BY column ASC",
        ];

        foreach ($validClauses as $clause) {
            try {
                $this->whereEtcParser->getWhereEtc($clause, false, 'num');
                $this->assertTrue(true); // indicate test passes if we got this far without an exception.
            } catch (Throwable $e) {
                $this->fail("An unexpected exception was thrown: " . get_class($e));
            }
        }
    }

    public function testInvalidSqlClauses(): void
    {
        $this->setUpWhereEtcTest();

        $invalidClauses = [
            " ", // non-empty string without keyword not allowed
            "' OR '1'='1",
            "\\ FOR UPDATE",
            " WHERE 1=1",
            "LIMIT 10",
            "OFFSET 5",
        ];

        foreach ($invalidClauses as $clause) {
            try {
                $this->whereEtcParser->getWhereEtc($clause, false, 'num');
                $this->fail("The clause '$clause' should be invalid but the test passed");
            } catch (Throwable) {
                $this->assertTrue(true); // indicate test passes
            }
        }
    }


    public function testGetWhereEtcEmptyArray(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $whereCond = [];

        // Act
        $result = $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');

        // Assert
        $expectedWhere    = "";
        $exceptedParamMap = $this->whereEtcParamMapDefault;
        $this->assertSame($expectedWhere, $result, "Expected an empty string for an empty array input.");
        $this->assertSame($exceptedParamMap, $this->whereEtcParser->paramMap, "Expected no changes to param map.");
    }

    public function testGetWhereEtcSingleCondition(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $whereCond = ['name' => 'John'];

        // Act
        $result = $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');

        // Assert
        $expectedWhere    = "WHERE `name` = ?";
        $expectedParamMap = array_merge($this->whereEtcParamMapDefault, [':2' => 'John']);
        $this->assertSame($expectedWhere, $result, "Expected SQL WHERE clause not formed correctly.");
        $this->assertSame($expectedParamMap, $this->whereEtcParser->paramMap, "Expected paramMap to include new parameter.");
    }

    public function testGetWhereEtcMultipleConditions(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $whereCond = ['name' => 'John', 'age' => 25];

        // Act
        $result = $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');

        // Assert
        $expectedWhere    = "WHERE `name` = ? AND `age` = ?";
        $expectedParamMap = array_merge($this->whereEtcParamMapDefault, [':2' => 'John', ':3' => 25]);
        $this->assertSame($expectedWhere, $result, "Expected SQL WHERE clause not formed correctly for multiple conditions.");
        $this->assertSame($expectedParamMap, $this->whereEtcParser->paramMap, "Expected paramMap to include new parameters.");
    }

    public function testGetWhereEtcInvalidColumnName(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $whereCond = ['invalid column name' => 'John'];

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');
    }

    public function testGetWhereEtcInvalidColumnName2(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $whereCond = [':name' => 'John'];

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');
    }

    public function testGetWhereEtcSupportsRawSql(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $rawSqlValue = "DATE_SUB(NOW(), INTERVAL '7' DAY)"; // test quotes in raw sql (which would throw an exception if entered directly)
        $whereCond   = ['expires' => DB::rawSql($rawSqlValue)];

        // Act
        $result = $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');

        // Assert
        $expectedWhere       = "WHERE `expires` = ?";
        $paramMap            = $this->whereEtcParser->paramMap;
        $expectedRawSqlParam = end($paramMap);  // Assuming the new value will be at the end
        $this->assertSame($expectedWhere, $result, "Expected SQL WHERE clause to support raw SQL.");
        $this->assertInstanceOf(RawSql::class, $expectedRawSqlParam, "Expected last parameter to be a RawSql object, got " . gettype($expectedRawSqlParam));
        $this->assertSame($rawSqlValue, (string)$expectedRawSqlParam, "Expected RawSql value to be $rawSqlValue.");
    }


    public function testGetWhereEtcRejectsRegularObjects(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $object    = new stdClass();
        $whereCond = ['object' => $object];

        // Act & Assert
        $this->expectException(Throwable::class);
        $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');
    }

    public function testGetWhereEtcSupportsNullValues(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $whereCond = ['name' => null];

        // Act
        $result = $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');

        // Assert
        $expectedWhere    = "WHERE `name` IS NULL";
        $expectedParamMap = array_merge($this->whereEtcParamMapDefault, []);
        $this->assertSame($expectedWhere, $result, "Expected SQL WHERE clause to support null values.");
        $this->assertSame($expectedParamMap, $this->whereEtcParser->paramMap, "Expected paramMap to remain unchanged.");
    }

    public function testGetWhereEtcRejectsArrayValues(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $whereCond = ['num' => [1, 2, 3]];

        // Act & Assert
        $this->expectException(TypeError::class);
        $this->whereEtcParser->getWhereEtc($whereCond, false, 'num');
    }
}