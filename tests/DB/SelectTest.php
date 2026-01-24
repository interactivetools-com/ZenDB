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
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use Throwable;

/**
 * Tests for DB::select() and DB::get() static methods
 */
class SelectTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        new Connection(self::$configDefaults, default: true);
        self::resetTempTestTables();
    }

    //region DB::select() Tests

    /**
     * @dataProvider provideValidQueries
     */
    public function testValidSelect(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams, array $expectedResult): void
    {
        $result = false;
        try {
            $result = DB::select($baseTable, $idArrayOrSQL, ...$mixedParams);
        } catch (Throwable $e) {
            $error  = "$testName exception: " . $e->getMessage();
            $error .= "\n" . $e->getTraceAsString();
            $this->fail($error);
        }

        $expected = [
            'testName'     => $testName,
            'baseTable'    => $baseTable,
            'idArrayOrSQL' => $idArrayOrSQL,
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
                'testName'       => 'primary key as int',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => (int) 5,
                'mixedParams'    => [],
                'expectedResult' => [['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34]],
            ],
            [
                'testName'       => 'array of where conditions',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['isAdmin' => null, 'status' => 'active'],
                'mixedParams'    => [],
                'expectedResult' => [
                    ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                ],
            ],
            [
                'testName'       => 'sql WITHOUT where keyword',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) LIMIT :limit OFFSET :offset;',
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
                'idArrayOrSQL'   => (int) -1,
                'mixedParams'    => [],
                'expectedResult' => [],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidQueries
     */
    public function testInvalidSelect(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams): void
    {
        try {
            DB::select($baseTable, $idArrayOrSQL, ...$mixedParams);
        } catch (\Exception) {
            $this->assertTrue(true);
            return;
        }

        $this->fail("Exception NOT thrown for: $testName");
    }

    public static function provideInvalidQueries(): array
    {
        return [
            [
                'testName'     => 'primary key as string',
                'baseTable'    => 'users',
                'idArrayOrSQL' => "5",
                'mixedParams'  => [],
            ],
            [
                'testName'     => 'array with invalid column names',
                'baseTable'    => 'users',
                'idArrayOrSQL' => ['notThere' => null, 'missingColumn' => 'active'],
                'mixedParams'  => [],
            ],
            [
                'testName'     => 'sql starting with SELECT',
                'baseTable'    => 'users',
                'idArrayOrSQL' => 'SELECT * FROM users',
                'mixedParams'  => [],
            ],
            [
                'testName'     => 'missing position param',
                'baseTable'    => 'users',
                'idArrayOrSQL' => 'num = ?',
                'mixedParams'  => [],
            ],
            [
                'testName'     => 'missing named param',
                'baseTable'    => 'users',
                'idArrayOrSQL' => 'num = :num',
                'mixedParams'  => [],
            ],
            [
                'testName'     => 'table with prefix already applied',
                'baseTable'    => 'test_users',
                'idArrayOrSQL' => "",
                'mixedParams'  => [],
            ],
        ];
    }

    //endregion
    //region DB::get() Tests

    public function testGetSingleRow(): void
    {
        $result = DB::get('users', 1);
        $this->assertSame('John Doe', $result->get('name')->value());
        $this->assertSame(1, $result->get('num')->value());
    }

    public function testGetWithArrayCondition(): void
    {
        $result = DB::get('users', ['name' => 'Charlie Brown']);
        $this->assertSame(5, $result->get('num')->value());
    }

    public function testGetReturnsEmptyForNoMatch(): void
    {
        $result = DB::get('users', 9999);
        $this->assertTrue($result->isEmpty());
    }

    public function testGetWithLimitThrowsException(): void
    {
        $this->expectException(\Itools\ZenDB\DBException::class);
        DB::get('users', 'LIMIT 5');
    }

    public function testGetAutoAddsLimit(): void
    {
        $result = DB::get('users', '');
        $this->assertFalse($result->isFirst() && $result->isLast() === false);
    }

    //endregion
}
