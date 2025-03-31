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

class countTest extends BaseTest
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
    public function testValidCount(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams, int $expectedResult): void {

        // Arrange: Set up individual test case variables

        // Act: Perform the action that we're testing
        $result = false; // initialize
        try {
            $result = DB::count($baseTable, $idArrayOrSQL, ...$mixedParams);
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
                'testName'           => 'primary key as int',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => 5,
                'mixedParams'        => [],
                'expectedResult'     => 1,
            ],
            [
                'testName'           => 'array of where conditions',
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
                'testName'           => 'empty result set',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => -1,
                'mixedParams'        => [],
                'expectedResult'     => 0,
            ],
            [
                'testName'           => 'full result set - empty string for selector',
                'baseTable'          => 'users',
                'idArrayOrSQL'       => '',
                'mixedParams'        => [],
                'expectedResult'     => 20,
            ],
        ];
    }


    /** @dataProvider provideInvalidQueries */
    public function testInvalidCount(string $testName, string $baseTable, int|array|string $idArrayOrSQL, array $mixedParams): void {

        // Arrange: Set up individual test case variables

        // Act & assert
        try {
            DB::count($baseTable, $idArrayOrSQL, ...$mixedParams);
        }
        catch (Exception) {
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
