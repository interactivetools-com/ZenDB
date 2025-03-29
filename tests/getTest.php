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

class getTest extends BaseTest
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
    public function testValidSelect(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams, ?array $expectedResult): void {

        // Arrange: Set up individual test case variables
        //$db = ReflectionWrapper::for('DB'); // makes private properties and methods accessible

        // Act: Perform the action that we're testing
        $result = false; // initialize
        try {
            $result = DB::get($baseTable, $idArrayOrSQL, ...$mixedParams);
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
                'testName'       => 'primary key as int',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => (int)5,
                'mixedParams'    => [],
                'expectedResult' => ['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34],
            ],
            [
                'testName'       => 'array of where conditions',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['isAdmin' => null, 'status' => 'active'],
                'mixedParams'    => [],
                'expectedResult' => ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
            ],
            [
                'testName'       => 'sql WITHOUT where keyword',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'mixedParams'    => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult' => ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
            ],
            [
                'testName'       => 'sql WITH where keyword and whitespace padding',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'mixedParams'    => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult' => ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
            ],
            [
                'testName'       => 'empty result set',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => (int)-1,
                'mixedParams'    => [],
                'expectedResult' => [],
            ],
            [
                'testName'       => 'first row from full result set - empty string for selector',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '',
                'mixedParams'    => [],
                'expectedResult' => ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
            ],
        ];
    }


    /** @dataProvider provideInvalidQueries */
    public function testInvalidGet(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams): void {

        // Arrange: Set up individual test case variables
        //$db       = ReflectionWrapper::for('DB'); // makes private properties and methods accessible

        // Act & assert
        try {
            DB::get($baseTable, $idArrayOrSQL, ...$mixedParams);
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
                'testName'       => 'with LIMIT',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) LIMIT :limit',
                'mixedParams'    => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
            ],
            [
                'testName'       => 'with OFFSET',
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) OFFSET :offset;',
                'mixedParams'    => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
            ],
        ];
    }


    /*

Fail tests:




     * */


}
