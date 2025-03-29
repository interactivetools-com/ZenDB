<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection SqlIdentifier */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);
namespace tests;

use Itools\ZenDB\DB;
use ReflectionWrapper;
use Throwable;

class selectTest extends BaseTest
{
    public static function setUpBeforeClass(): void {

        // reset config to defaults
        DB::disconnect();
        DB::config(self::$configDefaults);
        DB::connect();

        // create tables
        self::resetTempTestTables();


    }

    protected function setUp(): void {
    }

    protected function tearDown(): void {
    }


    /** @dataProvider provideValidQueries */
    public function testValidSelect(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams, array $expectedResult): void {

        // Arrange: Set up individual test case variables

        // Act: Perform the action that we're testing
        $result = false; // initialize
        try {
            $result = DB::select($baseTable, $idArrayOrSQL, ...$mixedParams);
        } catch (Throwable $e) {
            $error  = "$testName exception: ".$e->getMessage();
            $error .= "\n".$e->getTraceAsString(); // optional show stacktrace
            $this->fail($error);
        }

        // Assert
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

    public function provideValidQueries(): array {
        return [
            [
                'testName'           => 'primary key as int',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => (int) 5,
                'mixedParams'        => [],
                'expectedResult'     => [['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34]],
            ],
            [
                'testName'           => 'array of where conditions',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => ['isAdmin' => null, 'status' => 'active'],
                'mixedParams'        => [],
                'expectedResult'     => [
                    ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                ],
            ],
            [
                'testName'           => 'sql WITHOUT where keyword',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) LIMIT :limit OFFSET :offset;',
                'mixedParams'        => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult'     => [
                    ['num' => 8, 'name' => 'Frank <b>Miller</b>', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Winnipeg', 'dob' => '1992-07-22', 'age' => 31],
                    ['num' => 10, 'name' => 'Helen Clark', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Saskatoon', 'dob' => '1986-05-16', 'age' => 37],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                ],
            ],
            [
                'testName'           => 'sql WITH where keyword and whitespace padding',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) LIMIT :limit OFFSET :offset;',
                'mixedParams'        => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult'     => [
                    ['num' => 8, 'name' => 'Frank <b>Miller</b>', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Winnipeg', 'dob' => '1992-07-22', 'age' => 31],
                    ['num' => 10, 'name' => 'Helen Clark', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Saskatoon', 'dob' => '1986-05-16', 'age' => 37],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                ],
            ],
            [
                'testName'           => 'empty result set',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => (int) -1,
                'mixedParams'        => [],
                'expectedResult'     => [],
            ],
            [
                'testName'           => 'full result set - empty string for selector',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => '',
                'mixedParams'        => [],
                'expectedResult'     => [
                    ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
                    ['num' => 3, 'name' => 'Alice Smith', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Montreal', 'dob' => '1980-12-20', 'age' => 43],
                    ['num' => 4, 'name' => 'Bob Johnson', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Calgary', 'dob' => '1995-02-25', 'age' => 28],
                    ['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34],
                    ['num' => 6, 'name' => 'Dave Williams', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Ottawa', 'dob' => '1975-09-30', 'age' => 48],
                    ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
                    ['num' => 8, 'name' => 'Frank <b>Miller</b>', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Winnipeg', 'dob' => '1992-07-22', 'age' => 31],
                    ['num' => 9, 'name' => 'George Wilson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Halifax', 'dob' => '1970-10-05', 'age' => 53],
                    ['num' => 10, 'name' => 'Helen Clark', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Saskatoon', 'dob' => '1986-05-16', 'age' => 37],
                    ['num' => 11, 'name' => 'Ivan Scott', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Victoria', 'dob' => '2000-01-01', 'age' => 24],
                    ['num' => 12, 'name' => 'Jill Taylor', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Hamilton', 'dob' => '1999-04-08', 'age' => 25],
                    ['num' => 13, 'name' => 'Kevin Lewis', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Kitchener', 'dob' => '1988-08-19', 'age' => 35],
                    ['num' => 14, 'name' => 'Linda Harris', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'London', 'dob' => '1978-11-21', 'age' => 45],
                    ['num' => 15, 'name' => 'Mike Nelson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Windsor', 'dob' => '1994-02-28', 'age' => 30],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                    ['num' => 17, 'name' => 'Oliver Young', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Fredericton', 'dob' => '1997-06-30', 'age' => 26],
                    ['num' => 18, 'name' => 'Paula Hall', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'St. John\'s', 'dob' => '1982-10-15', 'age' => 41],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                    ['num' => 20, 'name' => 'Rachel Carter', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Yellowknife', 'dob' => '1979-07-04', 'age' => 44],
                ],
            ],
        ];
    }


    /** @dataProvider provideInvalidQueries */
    public function testInvalidSelect(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams): void {

        // Arrange: Set up individual test case variables

        // Act & assert
        try {
            DB::select($baseTable, $idArrayOrSQL, ...$mixedParams);
        }
        catch (\Exception $e) {
            $this->assertTrue(true);
            return;
        }

        // assert
        $this->fail("Exception NOT thrown for: $testName");
    }

    public function provideInvalidQueries(): array {
        return [
            [
                'testName'           => 'primary key as string',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => "5",
                'mixedParams'        => [],
            ],
            [
                'testName'           => 'array with invalid column names',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => ['notThere' => null, 'missingColumn' => 'active'],
                'mixedParams'        => [],
            ],
            [
                'testName'           => 'sql starting with SELECT',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => 'SELECT * FROM users',
                'mixedParams'        => [],
            ],
            [
                'testName'           => 'missing position param',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => 'num = ?',
                'mixedParams'        => [],
            ],
            [
                'testName'           => 'missing named param',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => 'num = :num',
                'mixedParams'        => [],
            ],
            [
                'testName'           => 'table with prefix already applied',
                'baseTable'          => 'test_users',
                'idArrayOrSQL'       => "",
                'mixedParams'        => [],
            ],
        ];
    }

}
