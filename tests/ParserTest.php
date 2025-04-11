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
    #region Setup

    private array   $whereEtcParamMapDefault = [':1' => 'Bob', ':name' => 'Tom'];
    private ?Parser $parser                  = null;

    protected function setUpWhereEtcTest(): void
    {
        $this->parser                   = new Parser();
        $this->parser->params->paramMap = $this->whereEtcParamMapDefault;
    }

    #region getWhereEtc Integer Input Tests

    /**
     * @dataProvider provideGetWhereEtcWithValidInteger
     */
    public function testGetWhereEtcWithValidInteger(string $pkName, int $pkValue, string $expectedSql, array $expectedParams): void
    {
        $this->setUpWhereEtcTest();

        // Act
        $result = $this->parser->getWhereEtc($pkValue, false, $pkName);

        // Assert
        $this->assertSame($expectedSql, $result, "Expected SQL WHERE clause not formed correctly for integer input.");

        $expectedParamMap = array_merge($this->whereEtcParamMapDefault, $expectedParams);
        $this->assertSame(
            $expectedParamMap,
            $this->parser->params->paramMap,
            "Parameter map does not match expected values for integer input.",
        );
    }

    /**
     * Data provider for integer input tests
     *
     * @return array<array>
     */
    public function provideGetWhereEtcWithValidInteger(): array
    {
        return [
            'basic integer with primary key' => [
                'num',              // primary key
                5,                  // id value
                "WHERE `num` = ?",  // expected SQL
                [':2' => 5],        // expected param addition
            ],
            'zero integer value'             => [
                'id',
                0,
                "WHERE `id` = ?",
                [':2' => 0],
            ],
            'negative integer value'         => [
                'record_id',
                -10,
                "WHERE `record_id` = ?",
                [':2' => -10],
            ],
        ];
    }

    // Empty primary key case now handled in provideInvalidPrimaryKeys

    /**
     * @dataProvider provideGetWhereEtcWithInvalidInteger
     */
    public function testGetWhereEtcWithInvalidInteger(?string $pkName, mixed $pkValue, string $expectedExceptionClass, ?string $expectedMessageContains = null): void
    {
        $this->setUpWhereEtcTest();

        try {
            // If pkName is null, we're testing invalid primary key names (using pkValue as the name)
            // Otherwise, we're testing invalid primary key values
            $result = $pkName === null
                ? $this->parser->getWhereEtc(5, false, $pkValue)
                : $this->parser->getWhereEtc($pkValue, false, $pkName);

            $this->fail("Expected exception when using invalid input, but none was thrown");
        } catch (Throwable $e) {
            $this->assertInstanceOf($expectedExceptionClass, $e);
            if ($expectedMessageContains !== null) {
                $this->assertStringContainsString($expectedMessageContains, $e->getMessage());
            }
        }
    }

    /**
     * Data provider for testing all invalid inputs for getWhereEtc integer tests
     *
     * @return array<array>
     */
    public function provideGetWhereEtcWithInvalidInteger(): array
    {
        return [
            // Invalid primary key names
            'empty primary key name' => [
                null,                            // pkName (null indicates testing invalid pk name)
                '',                              // pkValue (used as invalid primary key name)
                InvalidArgumentException::class, // expected exception
                "Primary key not defined in config" // expected message
            ],
            'float primary key name' => [
                null,
                1.5,
                TypeError::class,
                null
            ],
            'bool primary key name' => [
                null,
                true,
                TypeError::class,
                null
            ],
            'null primary key name' => [
                null,
                null,
                TypeError::class,
                null
            ],
            'object primary key name' => [
                null,
                new stdClass(),
                TypeError::class,
                null
            ],
            'pure numeric string as primary key name' => [
                null,
                "123",
                DBException::class,
                "Disallowed standalone number"
            ],
            'numeric string with leading zero as primary key name' => [
                null,
                "01",
                DBException::class,
                "Disallowed standalone number"
            ],
            'large numeric string as primary key name' => [
                null,
                "9999999999",
                DBException::class,
                "Disallowed standalone number"
            ],

            // Invalid primary key values
            'string id value' => [
                'id',                            // pkName
                "5",                             // pkValue (invalid primary key value)
                InvalidArgumentException::class, // expected exception
                "Numeric string detected"        // expected message
            ],
            'array value' => [
                'id',
                [1, 2, 3],
                TypeError::class,
                null
            ],
            'object value' => [
                'id',
                new stdClass(),
                TypeError::class,
                null
            ],
            'float value' => [
                'id',
                1.5,
                TypeError::class,
                null
            ],
            'boolean value' => [
                'id',
                true,
                TypeError::class,
                null
            ],
        ];
    }
    #endregion

    #region getWhereEtc String Input Tests

    /**
     * @dataProvider provideValidSqlStrings
     */
    public function testGetWhereEtcWithValidSqlStrings(string $clause): void
    {
        $this->setUpWhereEtcTest();

        try {
            $result = $this->parser->getWhereEtc($clause, false, 'num');
            $this->assertIsString($result);
            $this->assertTrue(true); // indicate test passes if we got this far without an exception.
        } catch (Throwable $e) {
            $this->fail("An unexpected exception was thrown for clause '$clause': " . get_class($e));
        }
    }

    /**
     * Data provider for valid SQL strings
     *
     * @return array<array<string>>
     */
    public function provideValidSqlStrings(): array
    {
        return [
            'empty string'       => [""],
            'simple where'       => ["WHERE something = something"],
            'for update'         => ["FOR UPDATE"],
            'spaces in clause'   => ["  FOR  UPDATE"],
            'newlines in clause' => ["\n FOR \n UPDATE \n "],
            'order by clause'    => ["ORDER BY column ASC"],
        ];
    }

    /**
     * @dataProvider provideInvalidSqlStrings
     */
    public function testGetWhereEtcWithInvalidSqlStrings(string $clause): void
    {
        $this->setUpWhereEtcTest();

        try {
            $this->parser->getWhereEtc($clause, false, 'num');
            $this->fail("The clause '$clause' should be invalid but the test passed");
        } catch (Throwable) {
            $this->assertTrue(true); // indicate test passes
        }
    }

    /**
     * Data provider for invalid SQL strings
     *
     * @return array<array<string>>
     */
    public function provideInvalidSqlStrings(): array
    {
        return [
            'spaces only'                  => [" "], // non-empty string without keyword not allowed
            'SQL injection attempt'        => ["' OR '1'='1"],
            'escape character'             => ["\\ FOR UPDATE"],
            'no leading keyword'           => [" WHERE 1=1"],
            'limit keyword without where'  => ["LIMIT 10"],
            'offset keyword without where' => ["OFFSET 5"],
        ];
    }

    #endregion

    #region getWhereEtc Array Input Tests

    /**
     * @dataProvider provideArrayConditions
     */
    public function testGetWhereEtcWithArrayInput(array $whereCond, string $expectedSql, array $expectedParamAdditions): void
    {
        $this->setUpWhereEtcTest();

        // Act
        $result = $this->parser->getWhereEtc($whereCond, false, 'num');

        // Assert
        $this->assertSame($expectedSql, $result, "Expected SQL WHERE clause not formed correctly.");

        $expectedParamMap = array_merge($this->whereEtcParamMapDefault, $expectedParamAdditions);
        $this->assertSame(
            $expectedParamMap,
            $this->parser->params->paramMap,
            "Parameter map does not match expected values.",
        );
    }

    /**
     * Data provider for array-based conditions
     *
     * @return array<array<mixed>>
     */
    public function provideArrayConditions(): array
    {
        return [
            'empty array'          => [
                [], // condition
                "", // expected SQL
                [], // expected additions to paramMap
            ],
            'single condition'     => [
                ['name' => 'John'],
                "WHERE `name` = ?",
                [':2' => 'John'],
            ],
            'multiple conditions'  => [
                ['name' => 'John', 'age' => 25],
                "WHERE `name` = ? AND `age` = ?",
                [':2' => 'John', ':3' => 25],
            ],
            'null value condition' => [
                ['name' => null],
                "WHERE `name` IS NULL",
                [],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidColumnNames
     */
    public function testGetWhereEtcWithInvalidColumnNames(array $whereCond): void
    {
        $this->setUpWhereEtcTest();

        $this->expectException(InvalidArgumentException::class);
        $this->parser->getWhereEtc($whereCond, false, 'num');
    }

    /**
     * Data provider for testing invalid column names
     *
     * @return array<array<array<string,string>>>
     */
    public function provideInvalidColumnNames(): array
    {
        return [
            'spaces in name' => [['invalid column name' => 'John']],
            'colon in name'  => [[':name' => 'John']],
        ];
    }

    #endregion

    #region getWhereEtc Special Value Tests

    public function testGetWhereEtcSupportsRawSql(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $rawSqlValue = "DATE_SUB(NOW(), INTERVAL '7' DAY)"; // test quotes in raw sql (which would throw an exception if entered directly)
        $whereCond   = ['expires' => DB::rawSql($rawSqlValue)];

        // Act
        $result = $this->parser->getWhereEtc($whereCond, false, 'num');

        // Assert
        $expectedWhere       = "WHERE `expires` = ?";
        $paramMap            = $this->parser->params->paramMap;
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
        $this->parser->getWhereEtc($whereCond, false, 'num');
    }

    public function testGetWhereEtcRejectsArrayValues(): void
    {
        $this->setUpWhereEtcTest();

        // Arrange
        $whereCond = ['num' => [1, 2, 3]];

        // Act & Assert
        $this->expectException(TypeError::class);
        $this->parser->getWhereEtc($whereCond, false, 'num');
    }

    #endregion

    #region getWhereEtc WHERE Required Tests
    /**
     * @dataProvider provideWhereRequiredCases
     */
    public function testWhereRequiredValidation(mixed $input, bool $whereRequired, string $primaryKey, bool $shouldThrow): void
    {
        $this->setUpWhereEtcTest();

        if ($shouldThrow) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage("No where condition");
        }

        $result = $this->parser->getWhereEtc($input, $whereRequired, $primaryKey);

        if (!$shouldThrow) {
            $this->assertIsString($result);
        }
    }

    /**
     * Data provider for WHERE required validation tests
     *
     * @return array<array<mixed>>
     */
    public function provideWhereRequiredCases(): array
    {
        return [
            'empty string with WHERE not required' => ['', false, 'id', false],
            'empty string with WHERE required'     => ['', true, 'id', true],
            'WHERE clause with WHERE required'     => ['WHERE id = :id', true, 'id', false],
            'ORDER BY with WHERE required'         => ['ORDER BY id', true, 'id', true],
            'array condition with WHERE required'  => [['id' => 5], true, 'id', false],
            'integer with WHERE required'          => [5, true, 'id', false],
        ];
    }
    #endregion
}
