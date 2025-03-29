<?php
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUndefinedFieldInspection */
declare(strict_types=1);

namespace tests;

use InvalidArgumentException, TypeError;
use Itools\ZenDB\DB, Itools\ZenDB\RawSql;
use ReflectionWrapper;

class getSetClauseTest extends BaseTest
{
    private ?ReflectionWrapper $db = null; // DB object initialized in setUp() before each test

    protected function setUp(): void {
        $this->db = ReflectionWrapper::for('DB'); // makes private properties and method accessible
    }

    // Runs after each test method in this class
    protected function tearDown(): void {
        $this->db = null;
    }

    public function testGetSetClauseWithValidInputs(): void {
        // arrange
        $colsToValues = ['name' => 'John', 'age' => 30, 'email' => 'john@example.com'];

        // act

        // assert
        $this->assertSame(
            expected: 'SET `name` = :zdb_1, `age` = :zdb_2, `email` = :zdb_3',
            actual: $this->db->getSetClause($colsToValues),
        );
        $this->assertSame(
            expected: [':zdb_1' => 'John', ':zdb_2' => 30, ':zdb_3' => 'john@example.com'],
            actual: $this->db->parser->paramMap
        );
    }

    public function testGetSetClauseWithEmptyArray(): void {
        $this->expectException(InvalidArgumentException::class);
        //$this->expectExceptionMessage("No columns provided");

        $this->db->getSetClause([]);
    }

    public function testGetSetClauseWithInvalidColumnNames(): void {
        $this->expectException(InvalidArgumentException::class);
        $colsToValues = ['name' => 'John', '!@#Age' => 30, 'email' => 'john@example.com'];
        $this->db->getSetClause($colsToValues);
    }


    public function testGetSetClauseWithRawSqlValues(): void {
        $colsToValues = ['name' => DB::rawSql('NOW()'), 'age' => 30];

        $this->assertSame(
            'SET `name` = :zdb_1, `age` = :zdb_2',
            $this->db->getSetClause($colsToValues)
        );

        $paramMap = $this->db->parser->paramMap;
        $this->assertInstanceOf(RawSql::class, $paramMap[':zdb_1']);
        $this->assertSame(30, $paramMap[':zdb_2']);
    }


    public function testGetSetClauseCalledMultipleTimes(): void {
        $this->db->getSetClause(['name' => 'John']);
        $this->expectException(InvalidArgumentException::class);
        $this->db->getSetClause(['age' => 30]);
    }

    public function testGetSetClauseWithNullValues(): void {
        $colsToValues = ['name' => null, 'age' => 30];

        $this->assertSame(
            'SET `name` = :zdb_1, `age` = :zdb_2',
            $this->db->getSetClause($colsToValues)
        );
        $this->assertSame(
            [':zdb_1' => null, ':zdb_2' => 30],
            $this->db->parser->paramMap
        );
    }

    public function testGetSetClauseWithNumericColumnNames(): void {
        $colsToValues = [123 => 'John', 456 => 30];
        $this->expectException(TypeError::class);
        $this->db->getSetClause($colsToValues);
    }

    public function testGetSetClauseWithEmptyStringsAsColumnNames(): void {
        $colsToValues = ['' => 'John'];
        $this->expectException(InvalidArgumentException::class);
        $this->db->getSetClause($colsToValues);
    }


    public function testGetSetClauseWithEmptyValue(): void {
        // Prepare
        $colsToValues = ['name' => ''];

        // Execute
        $this->db->getSetClause($colsToValues);

        // Assert
        $this->assertSame(
            [':zdb_1' => ''],
            $this->db->parser->paramMap
        );
    }


    public function testGetSetClauseWithArrayValue(): void {
        $colsToValues = ['name' => ['John', 'Doe']];
        $this->expectException(TypeError::class);
        $this->db->getSetClause($colsToValues);
    }


    public function testGetSetClauseWithAssociativeAndIndexedArrayMixed(): void {
        $colsToValues = ['name' => 'John', 'age' => 30, 999 => 'invalid'];
        $this->expectException(TypeError::class);
        $this->db->getSetClause($colsToValues);
    }

}
