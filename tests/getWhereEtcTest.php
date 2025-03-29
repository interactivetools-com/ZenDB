<?php
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUndefinedFieldInspection */
declare(strict_types=1);

namespace tests;

use InvalidArgumentException, TypeError;
use Itools\ZenDB\DB, Itools\ZenDB\RawSql;
use ReflectionWrapper;
use stdClass;
use Throwable;

class getWhereEtcTest extends BaseTest
{
    private ReflectionWrapper|null $db = null; // DB object initialized in setUp() before each test

    private array $paramMapDefault = [':1' => 'Bob', ':name' => 'Tom'];

    protected function setUp(): void {
        $db = ReflectionWrapper::for('DB'); // makes private properties and method accessible

        // reset default paramMap so we can ensure it doesn't get overwritten.
        $db->parser->paramMap = $this->paramMapDefault;
        $this->db = $db;
    }
    protected function tearDown(): void {
        $this->db = null;
    }

    public function testValidSqlClauses(): void {
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
                $this->db->getWhereEtc($clause);
                $this->assertTrue(true); // indicate test passes if we got this far without an exception.
            } catch (Throwable $e) {
                $this->fail("An unexpected exception was thrown: " . get_class($e));
            }
        }
    }

    public function testInvalidSqlClauses(): void {
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
                $this->db->getWhereEtc($clause);
                $this->fail("The clause '$clause' should be invalid but the test passed");
            } catch (Throwable) {
                $this->assertTrue(true); // indicate test passes
            }
        }
    }


    public function testGetWhereEtcEmptyArray(): void {
        // Arrange
        $whereCond = [];

        // Act
        $result = $this->db->getWhereEtc($whereCond);

        // Assert
        $expectedWhere    = "";
        $exceptedParamMap = $this->paramMapDefault;
        $this->assertSame($expectedWhere, $result, "Expected an empty string for an empty array input.");
        $this->assertSame($exceptedParamMap, $this->db->parser->paramMap, "Expected no changes to param map.");
    }

    public function testGetWhereEtcSingleCondition(): void {
        // Arrange
        $whereCond = ['name' => 'John'];

        // Act
        $result = $this->db->getWhereEtc($whereCond);

        // Assert
        $expectedWhere = "WHERE `name` = ?";
        $expectedParamMap = array_merge($this->paramMapDefault, [':2' => 'John']);
        $this->assertSame($expectedWhere, $result, "Expected SQL WHERE clause not formed correctly.");
        $this->assertSame($expectedParamMap, $this->db->parser->paramMap, "Expected paramMap to include new parameter.");
    }

    public function testGetWhereEtcMultipleConditions(): void {
        // Arrange
        $whereCond = ['name' => 'John', 'age' => 25];

        // Act
        $result = $this->db->getWhereEtc($whereCond);

        // Assert
        $expectedWhere    = "WHERE `name` = ? AND `age` = ?";
        $expectedParamMap = array_merge($this->paramMapDefault, [':2' => 'John', ':3' => 25]);
        $this->assertSame($expectedWhere, $result, "Expected SQL WHERE clause not formed correctly for multiple conditions.");
        $this->assertSame($expectedParamMap, $this->db->parser->paramMap, "Expected paramMap to include new parameters.");
    }

    public function testGetWhereEtcInvalidColumnName(): void {
        // Arrange
        $whereCond = ['invalid column name' => 'John'];

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->db->getWhereEtc($whereCond);
    }

    public function testGetWhereEtcInvalidColumnName2(): void {
        // Arrange
        $whereCond = [':name' => 'John'];

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->db->getWhereEtc($whereCond);
    }

    public function testGetWhereEtcSupportsRawSql(): void {
        // Arrange
        $rawSqlValue = "DATE_SUB(NOW(), INTERVAL '7' DAY)"; // test quotes in raw sql (which would throw an exception if entered directly)
        $whereCond = ['expires' => DB::rawSql($rawSqlValue)];

        // Act
        $result = $this->db->getWhereEtc($whereCond);

        // Assert
        $expectedWhere = "WHERE `expires` = ?";
        $paramMap = $this->db->parser->paramMap;
        $expectedRawSqlParam = end($paramMap);  // Assuming the new value will be at the end
        $this->assertSame($expectedWhere, $result, "Expected SQL WHERE clause to support raw SQL.");
        $this->assertInstanceOf(RawSql::class, $expectedRawSqlParam, "Expected last parameter to be a RawSql object.");
        $this->assertSame($rawSqlValue, (string) $expectedRawSqlParam, "Expected RawSql value to be $rawSqlValue.");
    }


    public function testGetWhereEtcRejectsRegularObjects(): void {
        // Arrange
        $object = new stdClass();
        $whereCond = ['object' => $object];

        // Act & Assert
        $this->expectException(Throwable::class);
        $this->db->getWhereEtc($whereCond);
    }

    public function testGetWhereEtcSupportsNullValues(): void {
        // Arrange
        $whereCond = ['name' => null];

        // Act
        $result = $this->db->getWhereEtc($whereCond);

        // Assert
        $expectedWhere = "WHERE `name` IS NULL";
        $expectedParamMap = array_merge($this->paramMapDefault, []);
        $this->assertSame($expectedWhere, $result, "Expected SQL WHERE clause to support null values.");
        $this->assertSame($expectedParamMap, $this->db->parser->paramMap, "Expected paramMap to remain unchanged.");
    }

    public function testGetWhereEtcRejectsArrayValues(): void {
        // Arrange
        $whereCond = ['num' => [1, 2, 3]];

        // Act & Assert
        $this->expectException(TypeError::class);
        $this->db->getWhereEtc($whereCond);
    }




}
