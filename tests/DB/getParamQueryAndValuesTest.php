<?php
/** @noinspection SqlWithoutWhere */
/** @noinspection SqlIdentifier */
/** @noinspection JsonEncodingApiUsageInspection */
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection SqlResolve */
declare(strict_types=1);

namespace tests;

use Itools\ZenDB\DB;
use InvalidArgumentException;
use Itools\ZenDB\DBException;
use ReflectionWrapper;
use Throwable;

class getParamQueryTest extends BaseTest
{
    public static function setUpBeforeClass(): void {
        // reset config values
        DB::disconnect();
        DB::config(self::$configDefaults); // sets tablePrefix to 'test_'

        // reconnect
        DB::connect();
    }

    /** @dataProvider provideValidInputs */
    public function testValidInputs(string $sqlTemplate, array $mixedParams, string $expectedParamQuery, array $expectedBindValues): void {

        // arrange
        $db = ReflectionWrapper::for('DB'); // makes private properties and methods accessible

        // arrange
        $db->parser->sqlTemplate = $sqlTemplate;
        $db->parser->paramMap    = []; // reset paramMap
        $db->parser->addParamsFromArgs($mixedParams); // sets paramMap
        // Act
        $db->parser->getParamQuery(); // updates $db->parser->paramQuery and $db->parser->bindValues

        // Assert
        $this->assertSame(
            expected: ['paramQuery' => $expectedParamQuery,      'bindValues' => $expectedBindValues],
            actual  : ['paramQuery' => $db->parser->paramQuery, 'bindValues' => $db->parser->bindValues],
        );
    }



    /** @dataProvider provideInvalidInputs */
    public function testInvalidInputs(string $sqlTemplate, array $mixedParams): void {
        // arrange
        $db = ReflectionWrapper::for('DB'); // makes private properties and methods accessible
        $db->parser->sqlTemplate = $sqlTemplate;
        $db->parser->paramMap    = []; // reset paramMap
        $inputAsJSON = json_encode(['sqlTemplate' => $sqlTemplate, 'mixedParams' => $mixedParams]);

        // Act & Assert
        try {
            $db->parser->addParamsFromArgs($mixedParams); // sets paramMap
            $db->parser->getParamQuery(); // updates $db->parser->paramQuery and $db->parser->bindValues

            // If the above line doesn't throw an exception, then it's a test failure.
            $this->fail("Test didn't throw InvalidArgumentException as expected for input:\n$inputAsJSON");
        }
        catch (Throwable) {
            // if we caught an exception it means the test passed
            $this->assertTrue(true); // indicate test passes
        }

    }

    public function provideValidInputs(): array {
        // sqlTemplate, mixedParams,
        // expectedParamQuery, expectedBindValues
        $tests = [
                    '1 positional params' => [
                        'SELECT * FROM `::table` WHERE num = ?',    [7, 6, 5],  // includes unused params
                        'SELECT * FROM `test_table` WHERE num = ?', [7],
                    ],
                    '3 positional params' => [
                        'SELECT * FROM `::table` WHERE num = ? AND division = ? AND city = ?',     ['4', 'east', 'New York'],  // includes unused params
                        'SELECT * FROM `test_table` WHERE num = ? AND division = ? AND city = ?',  ['4', 'east', 'New York'],
                    ],
                    '1 named params' => [
                        'SELECT * FROM `::table` WHERE num = :num', [[':division' => 'east', ':num' => 16, ':city' => 'New York']], // includes unused params
                        'SELECT * FROM `test_table` WHERE num = ?', [16],
                    ],
                    '3 named params' => [
                        'SELECT * FROM `::table` WHERE num = :num AND division = :division AND city = :city', [[':name' => 'unused', ':division' => 'east', ':num' => 16, ':city' => 'New York']], // includes unused params
                        'SELECT * FROM `test_table` WHERE num = ? AND division = ? AND city = ?', [16, 'east', 'New York'],
                    ],
                    'mixed positional and named params' => [
                        'SELECT * FROM `::table` WHERE num = ? AND division = :division AND city = ?', [[':name' => 'unused', ':division' => 'east', 16, 'New York', ':position' => 'top']], // includes unused params
                        'SELECT * FROM `test_table` WHERE num = ? AND division = ? AND city = ?', [16, 'east', 'New York'],
                    ],
                    [
                        'SELECT * FROM table', [],
                        'SELECT * FROM table', []
                    ],[
                        'SELECT * FROM table', [[':unused' => 'value']],
                        'SELECT * FROM table', []
                    ],[
                        'SELECT * FROM `table` WHERE id = :id', [[':id' => 1]],
                        'SELECT * FROM `table` WHERE id = ?', [1]
                    ],[
                        'SELECT * FROM table WHERE `id` = :id AND name = :name', [[':id' => 1, ':name' => 'John']],
                        'SELECT * FROM table WHERE `id` = ? AND name = ?', [1, 'John']
                    ],[
                        'SELECT * FROM `table` WHERE id = ? OR id = ?', [[':1' => 1, ':2' => 2]],
                        'SELECT * FROM `table` WHERE id = ? OR id = ?', [1, 2]
                    ],[
                        'SELECT * FROM ::table', [],
                        'SELECT * FROM test_table', []
                    ],[
                        'SELECT * FROM `table` WHERE id = ? AND name = :name', [[':1' => 1, ':name' => 'John']],
                        'SELECT * FROM `table` WHERE id = ? AND name = ?', [1, 'John']
                    ],[
                        'SELECT * FROM `table` WHERE DATE(id) = DATE(:id)', [[':id' => '2021-01-01']],
                        'SELECT * FROM `table` WHERE DATE(id) = DATE(?)', ['2021-01-01']
                    ],[
                        'SELECT * FROM table WHERE `id` IN (:id, :id)', [[':id' => 1]],
                        'SELECT * FROM table WHERE `id` IN (?, ?)', [1, 1]
                    ],[
                        'UPDATE `table` SET active = :active', [[':active' => true]],
                        'UPDATE `table` SET active = ?', [true]
                    ],[
                        'SELECT * FROM table WHERE id IS :null_value2', [[':null_value2' => null]],
                        'SELECT * FROM table WHERE id IS ?', [null]  // Assuming NULL gets literal substitution
                    ],[
                        'SELECT * FROM `table` WHERE id < :id LIMIT 10', [[':id' => 100]],
                        'SELECT * FROM `table` WHERE id < ? LIMIT 10', [100]
                    ],[
                        'SELECT * FROM table1; SELECT * FROM table2 WHERE id = :id', [[':id' => 1]],
                        'SELECT * FROM table1; SELECT * FROM table2 WHERE id = ?', [1]
                    ],
        ];

        return $tests;
    }



    public function provideInvalidInputs(): array {
        // sqlTemplate, mixedParams,
        $tests = [
            'missing value' => [
                'SELECT * FROM table WHERE `id` = :missing',
                []
            ],

            //
            'Missing second positional parameter' => [
                'SELECT * FROM `table` WHERE id = ? AND name = ?',
                [1]
            ],

            'Exceeds max ? parameters' => [
                'SELECT * FROM `table` WHERE id = ? OR id = ? OR id = ? OR id = ? OR id = ?',
                [1,2,3,4,5]
            ],
        ];

        return $tests;
    }

}
