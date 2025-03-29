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

use Exception;
use Itools\ZenDB\DB;
use ReflectionWrapper;
use Throwable;

class updateTest extends BaseTest
{
    public static function setUpBeforeClass(): void {

        // reset config to defaults
        DB::disconnect();
        DB::config(self::$configDefaults);
        DB::connect();
    }

    protected function setUp(): void {
        // create tables
        self::resetTempTestTables();
    }

    protected function tearDown(): void {
    }

    public function testUpdateManyFields(): void {

        // Arrange: Set up individual test case variables
        $baseTable    = "users";
        $colsToValues = [
            'num'     => 212,
            'name'    => 'Jillian Ty lair',
            'isAdmin' => 1,
            'status'  => 'Active',
            'city'    => 'New York',
            'dob'     => '1989-01-02',
            'age'     => 35,
        ];
        $idArrayOrSQL = 12;
        $mixedParams  = [];
        $expectedResult = 1; // affected rows

        // Act: Perform the action that we're testing
        $result = false; // initialize
        try {
            $result = DB::update($baseTable, $colsToValues, $idArrayOrSQL, ...$mixedParams);
        } catch (Throwable $e) {
            $error  = __FUNCTION__ . " exception: ".$e->getMessage();
            $error .= "\n".$e->getTraceAsString(); // optional show stacktrace
            $this->fail($error);
        }

        // Assert
        // affected rows
        $this->assertSame($expectedResult, $result);

        // actual record
        $record = DB::get($baseTable, $colsToValues['num']);
        $this->assertSame($colsToValues, $record->toArray());
    }


    /** @dataProvider provideValidQueries */
    public function testValidUpdate(string $testName, string $baseTable, array $colsToValues, int|array|string $idArrayOrSQL, array $mixedParams, int $expectedResult): void {

        // Arrange: Set up individual test case variables

        // Act: Perform the action that we're testing
        $result = false; // initialize
        try {
            $result = DB::update($baseTable, $colsToValues, $idArrayOrSQL, ...$mixedParams);
        } catch (Throwable $e) {
            $error  = "$testName exception: ".$e->getMessage();
            $error .= "\n".$e->getTraceAsString(); // optional show stacktrace
            $this->fail($error);
        }

        // Assert
        $db = ReflectionWrapper::for(DB::$lastInstance); // makes private properties and methods accessible
        $expected = [
            'testName'     => $testName,
            'baseTable'    => $baseTable,
            'idArrayOrSQL' => $idArrayOrSQL,
            'mixedParams'  => $mixedParams,
            'paramQuery'   => $db->parser->paramQuery,
            'bindValues'   => $db->parser->bindValues,
            'result'       => $expectedResult,
        ];
        $actual = array_merge($expected, [
            'result' => $result,
        ]);
        $this->assertSame($expected, $actual);
    }

    public function provideValidQueries(): array {
        return [
            [
                'testName'           => 'match zero rows',
                'baseTable'          => 'users',
                'colsToValues'       => ['city' => 'Vancouver'],
                'idArrayOrSQL'       => 0,
                'mixedParams'        => [],
                'expectedResult'     => 0,
            ],
            [
                'testName'           => 'update by primary key int',
                'baseTable'          => 'users',
                'colsToValues'       => ['city' => 'Vancouver'],
                'idArrayOrSQL'       => 12,
                'mixedParams'        => [],
                'expectedResult'     => 1,
            ],
            [
                'testName'           => 'update by whereArray',
                'baseTable'          => 'users',
                'colsToValues'       => ['city' => 'Vancouver'],
                'idArrayOrSQL'       => ['isAdmin' => null, 'status' => 'active'],
                'mixedParams'        => [],
                'expectedResult'     => 2,
            ],
            [
                'testName'           => 'sql WITHOUT where keyword',
                'baseTable'          => 'users',
                'colsToValues'       => ['city' => 'Vancouver'],
                'idArrayOrSQL'       => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'mixedParams'        => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult'     => 5,
            ],
            [
                'testName'           => 'sql WITH where keyword and whitespace padding',
                'baseTable'          => 'users',
                'colsToValues'       => ['city' => 'Vancouver'],
                'idArrayOrSQL'       => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'mixedParams'        => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult'     => 5,
            ],
            [
                'testName'           => 'update everything',
                'baseTable'          => 'users',
                'colsToValues'       => ['city' => 'New Vancouver'],
                'idArrayOrSQL'       => 'WHERE TRUE',
                'mixedParams'        => [],
                'expectedResult'     => 20,
            ],
        ];
    }


    /** @dataProvider provideInvalidQueries */
    public function testInvalidUpdate(string $testName, string $baseTable, array $colsToValues, int|array|string $idArrayOrSQL, array $mixedParams): void {

        // Arrange: Set up individual test case variables
        //$db       = ReflectionWrapper::for('DB'); // makes private properties and methods accessible

        // Act & assert
        try {
            DB::update($baseTable, $colsToValues, $idArrayOrSQL, ...$mixedParams);
        }
        catch (Exception $e) {
            $this->assertTrue(true);
            return;
        }

        // assert
        $this->fail("Exception NOT thrown for: $testName");
    }

    public function provideInvalidQueries(): array {
        return [
            [
                'testName'           => 'update with no conditions',
                'baseTable'          => 'users',
                'colsToValues'       => ['city' => 'Vancouver'],
                'idArrayOrSQL'       => "",
                'mixedParams'        => [],
            ],
            [
                'testName'           => 'update with no column values',
                'baseTable'          => 'users',
                'colsToValues'       => [],
                'idArrayOrSQL'       => 0,
                'mixedParams'        => [],
                'expectedResult'     => 0,
            ],
        ];
    }

}
