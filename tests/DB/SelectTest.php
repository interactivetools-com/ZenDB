<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection SqlIdentifier */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use Throwable;

/**
 * Tests for DB::select() and DB::selectOne() static methods
 */
class SelectTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTempTestTables();
    }

    //region DB::select() Tests

    /**
     * @dataProvider provideValidQueries
     */
    public function testValidSelect(string $testName, string $baseTable, array|string $where, array $mixedParams, array $expectedResult): void
    {
        $result = false;
        try {
            $result = DB::select($baseTable, $where, ...$mixedParams);
        } catch (Throwable $e) {
            $error  = "$testName exception: " . $e->getMessage();
            $error .= "\n" . $e->getTraceAsString();
            $this->fail($error);
        }

        $expected = [
            'testName'     => $testName,
            'baseTable'    => $baseTable,
            'where'        => $where,
            'mixedParams'  => $mixedParams,
            'result'       => $expectedResult,
        ];
        $actual = array_merge($expected, [
            'result' => is_object($result) ? $result->toArray() : $result,
        ]);
        $this->assertSame($expected, $actual);
    }

    public static function provideValidQueries(): array
    {
        return [
            [
                'testName'       => 'primary key as array',
                'baseTable'      => 'users',
                'where'          => ['num' => 5],
                'mixedParams'    => [],
                'expectedResult' => [['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34]],
            ],
            [
                'testName'       => 'array of where conditions',
                'baseTable'      => 'users',
                'where'          => ['isAdmin' => null, 'status' => 'active'],
                'mixedParams'    => [],
                'expectedResult' => [
                    ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                ],
            ],
            [
                'testName'       => 'sql WITHOUT where keyword',
                'baseTable'      => 'users',
                'where'          => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) LIMIT :limit OFFSET :offset;',
                'mixedParams'    => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult' => [
                    ['num' => 8, 'name' => 'Frank <b>Miller</b>', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Winnipeg', 'dob' => '1992-07-22', 'age' => 31],
                    ['num' => 10, 'name' => 'Helen Clark', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Saskatoon', 'dob' => '1986-05-16', 'age' => 37],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                ],
            ],
            [
                'testName'       => 'empty result set',
                'baseTable'      => 'users',
                'where'          => ['num' => -1],
                'mixedParams'    => [],
                'expectedResult' => [],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidQueries
     */
    public function testInvalidSelect(string $testName, string $baseTable, array|string $where, array $mixedParams, string $expectedMessage): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($expectedMessage);
        DB::select($baseTable, $where, ...$mixedParams);
    }

    public static function provideInvalidQueries(): array
    {
        return [
            [
                'testName'        => 'numeric string throws',
                'baseTable'       => 'users',
                'where'           => "5",
                'mixedParams'     => [],
                'expectedMessage' => "Numeric string '5' detected",
            ],
            [
                'testName'        => 'array with invalid column names',
                'baseTable'       => 'users',
                'where'           => ['notThere' => null, 'missingColumn' => 'active'],
                'mixedParams'     => [],
                'expectedMessage' => "Unknown column 'notThere'",
            ],
            [
                'testName'        => 'sql starting with SELECT',
                'baseTable'       => 'users',
                'where'           => 'SELECT * FROM users',
                'mixedParams'     => [],
                'expectedMessage' => "You have an error in your SQL syntax",
            ],
            [
                'testName'        => 'missing position param',
                'baseTable'       => 'users',
                'where'           => 'num = ?',
                'mixedParams'     => [],
                'expectedMessage' => "Missing value for ? parameter",
            ],
            [
                'testName'        => 'missing named param',
                'baseTable'       => 'users',
                'where'           => 'num = :num',
                'mixedParams'     => [],
                'expectedMessage' => "Missing value for ':num' parameter",
            ],
            [
                'testName'        => 'table with prefix already applied',
                'baseTable'       => 'test_users',
                'where'           => "",
                'mixedParams'     => [],
                'expectedMessage' => "doesn't exist",
            ],
        ];
    }

    //endregion
    //region DB::selectOne() Tests

    public function testSelectOneSingleRow(): void
    {
        $result = DB::selectOne('users', ['num' => 1]);
        $this->assertSame('John Doe', $result->get('name')->value());
        $this->assertSame(1, $result->get('num')->value());
    }

    public function testSelectOneWithArrayCondition(): void
    {
        $result = DB::selectOne('users', ['name' => 'Charlie Brown']);
        $this->assertSame(5, $result->get('num')->value());
    }

    public function testSelectOneReturnsEmptyForNoMatch(): void
    {
        $result = DB::selectOne('users', ['num' => 9999]);
        $this->assertTrue($result->isEmpty());
    }

    public function testSelectOneWithLimitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        DB::selectOne('users', 'LIMIT 5');
    }

    public function testSelectOneAutoAddsLimit(): void
    {
        // selectOne() adds LIMIT 1, so result should be a single row
        $result = DB::selectOne('users', '');
        $this->assertFalse($result->isEmpty());
        $this->assertTrue($result->isFirst());
        $this->assertTrue($result->isLast());
    }

    //endregion
}
