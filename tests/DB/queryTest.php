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

use Itools\SmartArray\SmartArray;
use Throwable;
use ReflectionWrapper;
use Itools\ZenDB\DB;
use Itools\ZenDB\Parser;
use Itools\SmartString\SmartString;

class queryTest extends BaseTest
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
    public function testValidQuery(
        string $name,
        string $sqlTemplate,
        string $expectedParamQuery,
        mixed $mixedParams,
        array $expectedParamMap,
        array $expectedBindValues,
        array $expectedResult
    ): void {

        // Arrange: Set up individual test case variables


        // Act: Perform the action that we're testing
        $result = false; // initialize
        try {
            $result = DB::query($sqlTemplate, ...$mixedParams);
        } catch (Throwable $e) {
            $error = "Test: $name\nCaught Exception: ".$e->getMessage()."\n".$e->getTraceAsString();
            $this->fail($error);
        }

        // Assert
        $db = ReflectionWrapper::for(DB::$lastInstance); // makes private properties and methods accessible

        $expected = [
            'test name'   => $name,
            'sqlTemplate' => $sqlTemplate,
            'mixedParams' => $sqlTemplate,
            // Removed internal implementation details
            'paramMap'    => $expectedParamMap,
            'result'      => $expectedResult,
        ];
        // copy expected values and overwrite these
        $actual = array_merge($expected, [
            // Removed internal implementation details
            'paramMap'   => $db->parser->paramMap,
            'result'     => is_object($result) ? $result->toArray() : $result,
        ]);
        $this->assertSame($expected, $actual, "Test: $name\n");
    }

    public function provideValidQueries(): array {
        return [
            [
                'name'               => 'positional and prefix placeholder',
                'sqlTemplate'        => 'SELECT * FROM :_users WHERE num = ?',
                'expectedParamQuery' => 'SELECT * FROM test_users WHERE num = ?',
                'mixedParams'        => [1],
                'expectedParamMap'   => [':1' => 1],
                'expectedBindValues' => [1],
                'expectedResult'     => [['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38]],
            ],[
                'name'               => 'named and prefix placeholder',
                'sqlTemplate'        => 'SELECT num, name, city FROM :_users WHERE city = :city',
                'expectedParamQuery' => 'SELECT num, name, city FROM test_users WHERE city = ?',
                'mixedParams'        => [[':city' => 'Toronto']],
                'expectedParamMap'   => [':city' => 'Toronto'],
                'expectedBindValues' => ['Toronto'],
                'expectedResult' => [
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'city' => 'Toronto'],
                    ['num' => 16, 'name' => 'Nancy Allen', 'city' => 'Toronto'],
                ],
            ],[
                'name'               => 'mixed and alternating named and positional placeholders',
                'sqlTemplate'        => 'SELECT * FROM :_users WHERE (status = :status AND age > ?) OR (city = :city AND dob >= ?)',
                'expectedParamQuery' => 'SELECT * FROM test_users WHERE (status = ? AND age > ?) OR (city = ? AND dob >= ?)',
                'mixedParams'        => [[':status' => 'Suspended', 30, ':city' => 'Vancouver', '1980-01-01']],
                'expectedParamMap'   => [':status' => 'Suspended', ':1' => 30, ':city' => 'Vancouver', ':2' => '1980-01-01'],
                'expectedBindValues' => ['Suspended', 30, 'Vancouver', '1980-01-01'],
                'expectedResult' => [
                    ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
                    ['num' => 8, 'name' => 'Frank <b>Miller</b>', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Winnipeg', 'dob' => '1992-07-22', 'age' => 31],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                    ['num' => 20, 'name' => 'Rachel Carter', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Yellowknife', 'dob' => '1979-07-04', 'age' => 44],
                ]
            ],[
                'name'               => 'test all PHP var types (except array, object & null)',
                'sqlTemplate'        => 'SELECT * FROM :_users WHERE num >= :float AND age < :int AND (isAdmin = :bool OR isAdmin IS NULL) AND name != :string',
                'expectedParamQuery' => 'SELECT * FROM test_users WHERE num >= ? AND age < ? AND (isAdmin = ? OR isAdmin IS NULL) AND name != ?',
                'mixedParams'        => [[':float' => 8.234, ':int' => 36, ':bool' => true, ':string' => 'a']],
                'expectedParamMap'   => [':float' => 8.234, ':int' => 36, ':bool' => true, ':string' => 'a'],
                'expectedBindValues' => [8.234, 36, true, 'a'],
                'expectedResult'     => [
                    ['num' => 11, 'name' => 'Ivan Scott', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Victoria', 'dob' => '2000-01-01', 'age' => 24],
                    ['num' => 13, 'name' => 'Kevin Lewis', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Kitchener', 'dob' => '1988-08-19', 'age' => 35],
                    ['num' => 15, 'name' => 'Mike Nelson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Windsor', 'dob' => '1994-02-28', 'age' => 30],
                    ['num' => 17, 'name' => 'Oliver Young', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Fredericton', 'dob' => '1997-06-30', 'age' => 26],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => NULL, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32]
                ],
            ],[
                'name'               => 'join test',
                'sqlTemplate'        => <<<__SQL__
SELECT *, p.price AS unit_price, (od.quantity * p.price) AS total_price
FROM :_users         AS u
JOIN :_orders        AS o  ON u.num         = o.user_id
JOIN :_order_details AS od ON o.order_id    = od.order_id
JOIN :_products      AS p  ON od.product_id = p.product_id
WHERE u.num = :num
__SQL__,
                'expectedParamQuery' => <<<__SQL__
SELECT *, p.price AS unit_price, (od.quantity * p.price) AS total_price
FROM test_users         AS u
JOIN test_orders        AS o  ON u.num         = o.user_id
JOIN test_order_details AS od ON o.order_id    = od.order_id
JOIN test_products      AS p  ON od.product_id = p.product_id
WHERE u.num = ?
__SQL__,
                'mixedParams'        => [[':num' => 13]],
                'expectedParamMap'   => [':num' => 13],
                'expectedBindValues' => [13],
                'expectedResult'     => [
                    [
                    'num' => 13,
                    'name' => 'Kevin Lewis',
                    'isAdmin' => 1,
                    'status' => 'Active',
                    'city' => 'Kitchener',
                    'dob' => '1988-08-19',
                    'age' => 35,
                    'order_id' => 8,
                    'user_id' => 13,
                    'order_date' => '2024-01-22',
                    'total_amount' => '70.50',
                    'order_detail_id' => 8,
                    'product_id' => 3,
                    'quantity' => 1,
                    'product_name' => 'Product C',
                    'price' => '25.75',
                    'unit_price' => '25.75',
                    'total_price' => '25.75',
                    'users.num' => 13,
                    'users.name' => 'Kevin Lewis',
                    'users.isAdmin' => 1,
                    'users.status' => 'Active',
                    'users.city' => 'Kitchener',
                    'users.dob' => '1988-08-19',
                    'users.age' => 35,
                    'orders.order_id' => 8,
                    'orders.user_id' => 13,
                    'orders.order_date' => '2024-01-22',
                    'orders.total_amount' => '70.50',
                    'order_details.order_detail_id' => 8,
                    'order_details.order_id' => 8,
                    'order_details.product_id' => 3,
                    'order_details.quantity' => 1,
                    'products.product_id' => 3,
                    'products.product_name' => 'Product C',
                    'products.price' => '25.75',
                    ],
                ], // end: expected result
            ],
        ];
    }


    /** @dataProvider provideInvalidQueries */
    public function testInvalidQuery(string $sqlTemplate, array $mixedParams): void
    {
        // Expect a TypeError to be thrown
        $this->expectException(\Throwable::class);

        // Act
        $parser = new Parser();
        $parser->addParamsFromArgs($mixedParams);
        DB::query($sqlTemplate, $mixedParams);
    }


    public function provideInvalidQueries(): array {

        return [
            'Disallowed standalone number' => [
                'sqlTemplate'        => 'SELECT 1+1',
                'mixedParams'        => [],
            ],
            'Invalid SQL' => [
                'sqlTemplate'        => 'SELECT',
                'mixedParams'        => [],
            ],
            'Invalid SQL2' => [
                'sqlTemplate'        => 'SELECT * FROM :name',
                'mixedParams'        => [[':name' => 'users']],
            ],
            "unknown table" => [
                'sqlTemplate'        => 'SELECT * FROM UnknownTable',
                'mixedParams'        => [],
            ],
            'invalid params' => [
                'sqlTemplate'        => 'SELECT * FROM :name WHERE num = ?',
                'mixedParams'        => [[':name' => 'users', 123]],
            ],
        ];
    }

    public function testReturnObjects(): void {
      $resultSet = DB::query("SELECT * FROM :_products");
      $this->assertInstanceOf(SmartArray::class, $resultSet);

      foreach ($resultSet as $row) {
        $this->assertInstanceOf(SmartArray::class, $row);
        foreach ($row as $value) {
          $this->assertInstanceOf(SmartString::class, $value);
        }
      }
    }


    public function testEncoders(): void {
        // arrange
        $testString = "<Hello> 'World' & \"Goodbye\" # @ ? = + %20";
        $expectedHtml = '&lt;Hello&gt; &apos;World&apos; &amp; &quot;Goodbye&quot; # @ ? = + %20';
        $expectedJs   = '\<Hello\> \\\'World\\\' & \"Goodbye\" # @ ? = + %20';
        $expectedUrl  = '%3CHello%3E+%27World%27+%26+%22Goodbye%22+%23+%40+%3F+%3D+%2B+%2520';

        // act
        $resultSet = DB::query("SELECT ? as testString", $testString);
        $value = $resultSet->first()->testString;

        // assert
        $this->assertInstanceOf(SmartString::class, $value);
        $this->assertSame($expectedHtml, (string) $value);
        $this->assertSame($expectedHtml, (string) $value->htmlEncode());
        $this->assertSame($expectedJs, (string) $value->jsEncode());
        $this->assertSame($expectedUrl, (string) $value->urlEncode());
        $this->assertSame($testString, $value->value());
    }

}
