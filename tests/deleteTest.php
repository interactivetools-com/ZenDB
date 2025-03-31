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

class deleteTest extends BaseTest
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


    /** @dataProvider provideValidQueries */
    public function testValidDelete(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams, int $expectedResult): void {

        // Arrange: Set up individual test case variables

        // Act: Perform the action that we're testing
        $result = false; // initialize
        try {
            $result = DB::delete($baseTable, $idArrayOrSQL, ...$mixedParams);
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
//            'paramQuery'   => $db->parser->paramQuery,
//            'bindValues'   => $db->parser->bindValues,
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
                'testName'           => 'match nothing',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => 0,
                'mixedParams'        => [],
                'expectedResult'     => 0,
            ],
            [
                'testName'           => 'erase by primary key int',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => 12,
                'mixedParams'        => [],
                'expectedResult'     => 1,
            ],
            [
                'testName'           => 'erase by whereArray',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => ['isAdmin' => null, 'status' => 'active'],
                'mixedParams'        => [],
                'expectedResult'     => 2,
            ],
            [
                'testName'           => 'sql WITHOUT where keyword',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'mixedParams'        => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult'     => 5,
            ],
            [
                'testName'           => 'sql WITH where keyword and whitespace padding',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'mixedParams'        => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult'     => 5,
            ],
            [
                'testName'           => 'erase everything',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => 'WHERE TRUE',
                'mixedParams'        => [],
                'expectedResult'     => 20,
            ],
        ];
    }


    /** @dataProvider provideInvalidQueries */
    public function testInvalidDelete(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams): void {

        // Arrange: Set up individual test case variables

        // Act & assert
        try {
            DB::delete($baseTable, $idArrayOrSQL, ...$mixedParams);
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
                'testName'           => 'delete with no conditions',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => "",
                'mixedParams'        => [],
            ],
        ];
    }

}
