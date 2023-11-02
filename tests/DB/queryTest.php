<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded when test is being run directly
require_once __DIR__ . '/../bootstrap.php';

use InvalidArgumentException;
use Itools\SmartArray\SmartArray;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;
use Throwable;

class queryTest extends BaseTest
{
    /**
     * @dataProvider provideValidQueries
     * @throws DBException
     */
    public function testValidQuery(string $name, string $sqlTemplate, array $params, array $expectedResult): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method
            $result      = DB::query($sqlTemplate, ...$params);
            $resultArray = $result->toArray();
            $this->assertSame($expectedResult, $resultArray, "Test: $name - static method with $queryType query failed");
            $this->assertSame($queryType, $result->mysqli('queryType'), "Query type was '{$result->mysqli('queryType')}', expected $queryType");
            $this->assertTrue($result->usingSmartStrings(), "Expected SmartStrings to be enabled for $queryType query");

            // Test instance method
            $db             = DB::getDefaultInstance();
            $instanceResult = $db->query($sqlTemplate, ...$params);
            $instanceArray  = $instanceResult->toArray();
            $this->assertSame($expectedResult, $instanceArray, "Test: $name - instance method with $queryType query failed");
            $this->assertSame($queryType, $instanceResult->mysqli('queryType'), "Query type was '{$instanceResult->mysqli('queryType')}', expected $queryType");
            $this->assertTrue($instanceResult->usingSmartStrings(), "Expected SmartStrings to be enabled for $queryType query");

            // Verify static and instance methods return identical results
            $this->assertSame($resultArray, $instanceArray, "Static and instance methods should return identical results for $queryType query");
        }

        // Reset the flag to default for other tests
        DB::config('usePreparedStatements', true);
    }

    public function provideValidQueries(): array
    {
        return [
            'positional and prefix placeholder'                                    => [
                'name'           => 'positional and prefix placeholder',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE num = ?',
                'params'         => [1],
                'expectedResult' => [
                    ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
                ],
            ],
            'named and prefix placeholder'                                         => [
                'name'           => 'named and prefix placeholder',
                'sqlTemplate'    => 'SELECT num, name, city FROM ::users WHERE city = :city',
                'params'         => [[':city' => 'Toronto']],
                'expectedResult' => [
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'city' => 'Toronto'],
                    ['num' => 16, 'name' => 'Nancy Allen', 'city' => 'Toronto'],
                ],
            ],
            'mixed and alternating named and positional placeholders'              => [
                'name'           => 'mixed and alternating named and positional placeholders',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE (status = :status AND age > ?) OR (city = :city AND dob >= ?)',
                'params'         => [[':status' => 'Suspended', 30, ':city' => 'Vancouver', '1980-01-01']],
                'expectedResult' => [
                    ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
                    ['num' => 8, 'name' => 'Frank <b>Miller</b>', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Winnipeg', 'dob' => '1992-07-22', 'age' => 31],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                    ['num' => 20, 'name' => 'Rachel Carter', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Yellowknife', 'dob' => '1979-07-04', 'age' => 44],
                ],
            ],
            'test all PHP var types (except array, object & null)'                 => [
                'name'           => 'test all PHP var types (except array, object & null)',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE num >= :float AND age < :int AND (isAdmin = :bool OR isAdmin IS NULL) AND name != :string',
                'params'         => [[':float' => 8.234, ':int' => 36, ':bool' => true, ':string' => 'a']],
                'expectedResult' => [
                    ['num' => 11, 'name' => 'Ivan Scott', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Victoria', 'dob' => '2000-01-01', 'age' => 24],
                    ['num' => 13, 'name' => 'Kevin Lewis', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Kitchener', 'dob' => '1988-08-19', 'age' => 35],
                    ['num' => 15, 'name' => 'Mike Nelson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Windsor', 'dob' => '1994-02-28', 'age' => 30],
                    ['num' => 17, 'name' => 'Oliver Young', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Fredericton', 'dob' => '1997-06-30', 'age' => 26],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                ],
            ],
            'join test'                                                            => [
                'name'           => 'join test',
                'sqlTemplate'    => <<<__SQL__
                                        SELECT *, p.price AS unit_price, (od.quantity * p.price) AS total_price
                                        FROM ::users         AS u
                                        JOIN ::orders        AS o  ON u.num         = o.user_id
                                        JOIN ::order_details AS od ON o.order_id    = od.order_id
                                        JOIN ::products      AS p  ON od.product_id = p.product_id
                                        WHERE u.num = :num
                                        __SQL__,
                'params'         => [[':num' => 13]],
                'expectedResult' => [
                    [
                        'num'                           => 13,
                        'name'                          => 'Kevin Lewis',
                        'isAdmin'                       => 1,
                        'status'                        => 'Active',
                        'city'                          => 'Kitchener',
                        'dob'                           => '1988-08-19',
                        'age'                           => 35,
                        'order_id'                      => 8,
                        'user_id'                       => 13,
                        'order_date'                    => '2024-01-22',
                        'total_amount'                  => '70.50',
                        'order_detail_id'               => 8,
                        'product_id'                    => 3,
                        'quantity'                      => 1,
                        'product_name'                  => 'Product C',
                        'price'                         => '25.75',
                        'unit_price'                    => '25.75',
                        'total_price'                   => '25.75',
                        'users.num'                     => 13,
                        'users.name'                    => 'Kevin Lewis',
                        'users.isAdmin'                 => 1,
                        'users.status'                  => 'Active',
                        'users.city'                    => 'Kitchener',
                        'users.dob'                     => '1988-08-19',
                        'users.age'                     => 35,
                        'orders.order_id'               => 8,
                        'orders.user_id'                => 13,
                        'orders.order_date'             => '2024-01-22',
                        'orders.total_amount'           => '70.50',
                        'order_details.order_detail_id' => 8,
                        'order_details.order_id'        => 8,
                        'order_details.product_id'      => 3,
                        'order_details.quantity'        => 1,
                        'products.product_id'           => 3,
                        'products.product_name'         => 'Product C',
                        'products.price'                => '25.75',
                    ],
                ],
            ],
            'empty result set'                                                     => [
                'name'           => 'empty result set',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE num = ?',
                'params'         => [-999],
                'expectedResult' => [],
            ],
            'LIKE operator with wildcard'                                          => [
                'name'           => 'LIKE operator with wildcard',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE name LIKE :pattern',
                'params'         => [[':pattern' => '%Smith%']],
                'expectedResult' => [
                    ['num' => 3, 'name' => 'Alice Smith', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Montreal', 'dob' => '1980-12-20', 'age' => 43],
                ],
            ],
            'ORDER BY with parameter'                                              => [
                'name'           => 'ORDER BY with parameter',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE isAdmin = ? ORDER BY age DESC LIMIT 3',
                'params'         => [1],
                'expectedResult' => [
                    ['num' => 9, 'name' => 'George Wilson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Halifax', 'dob' => '1970-10-05', 'age' => 53],
                    ['num' => 3, 'name' => 'Alice Smith', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Montreal', 'dob' => '1980-12-20', 'age' => 43],
                    ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
                ],
            ],
            'GROUP BY with aggregate function'                                     => [
                'name'           => 'GROUP BY with aggregate function',
                'sqlTemplate'    => 'SELECT status, COUNT(*) as count FROM ::users GROUP BY status ORDER BY count DESC',
                'params'         => [],
                'expectedResult' => [
                    ['status' => 'Active', 'count' => 10],
                    ['status' => 'Inactive', 'count' => 5],
                    ['status' => 'Suspended', 'count' => 5],
                ],
            ],
            'IS NULL condition'                                                    => [
                'name'           => 'IS NULL condition',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE isAdmin IS NULL',
                'params'         => [],
                'expectedResult' => [
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
                    ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
                    ['num' => 14, 'name' => 'Linda Harris', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'London', 'dob' => '1978-11-21', 'age' => 45],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                ],
            ],
            ':: prefix placeholder with multiple tables and named parameters'      => [
                'name'           => ':: prefix placeholder with multiple tables and named parameters',
                'sqlTemplate'    => 'SELECT u.name, p.product_name FROM ::users u JOIN ::products p ON p.product_id = :id WHERE u.num = :num',
                'params'         => [[':id' => 1, ':num' => 1]],
                'expectedResult' => [
                    [
                        'name'                  => 'John Doe',
                        'product_name'          => 'Product A',
                        'users.name'            => 'John Doe',
                        'products.product_name' => 'Product A',
                    ],
                ],
            ],
            ':_ prefix placeholder with multiple tables and positional parameters' => [
                'name'           => ':_ prefix placeholder with multiple tables and positional parameters',
                'sqlTemplate'    => 'SELECT u.name, p.product_name FROM :_users u JOIN :_products p ON p.product_id = ? WHERE u.num = ?',
                'params'         => [1, 1],
                'expectedResult' => [
                    [
                        'name'                  => 'John Doe',
                        'product_name'          => 'Product A',
                        'users.name'            => 'John Doe',
                        'products.product_name' => 'Product A',
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidQueries
     */
    public function testInvalidQuery(string $name, string $sqlTemplate, array $params, string $exceptionType, string $exceptionMatch): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method throws exception
            try {
                DB::query($sqlTemplate, ...$params);
                $this->fail("Static DB::query with $queryType queries did not throw exception for test: $name");
            } catch (Throwable $e) {
                $message = "Static method with $queryType query - test: $name";
                $this->assertSame($exceptionType, $e::class, $message);
                $this->assertStringContainsString($exceptionMatch, $e->getMessage(), $message);
            }

            // Test instance method throws exception
            try {
                $db = DB::getDefaultInstance();
                $db->query($sqlTemplate, ...$params);
                $this->fail("Instance \$db->query with $queryType queries did not throw exception for test: $name");
            } catch (Throwable $e) {
                $message = "Instance method with $queryType query - test: $name";
                $this->assertSame($exceptionType, $e::class, $message);
                $this->assertStringContainsString($exceptionMatch, $e->getMessage(), $message);
            }
        }

        // Reset the flag to default for other tests
        DB::config('usePreparedStatements', true);
    }

    public function provideInvalidQueries(): array
    {
        return [
            'Disallowed standalone number'        => [
                'name'           => 'Disallowed standalone number',
                'sqlTemplate'    => 'SELECT 1+1',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'Invalid SQL'                         => [
                'name'           => 'Invalid SQL',
                'sqlTemplate'    => 'SELECT',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'Invalid SQL with placeholder'        => [
                'name'           => 'Invalid SQL with placeholder',
                'sqlTemplate'    => 'SELECT * FROM :name',
                'params'         => [[':name' => 'users']],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'unknown table'                       => [
                'name'           => 'Unknown table',
                'sqlTemplate'    => 'SELECT * FROM UnknownTable',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'query with unclosed single quote'    => [
                'name'           => 'Query with unclosed single quote',
                'sqlTemplate'    => "SELECT * FROM ::users WHERE name = 'John",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template, found '",
            ],
            'query with unclosed double quote'    => [
                'name'           => 'Query with unclosed double quote',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE name = "Jane',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template, found \"",
            ],
            'query with single quotes'            => [
                'name'           => 'Query with single quotes',
                'sqlTemplate'    => "SELECT * FROM ::users WHERE status = 'Active'",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template",
            ],
            'query with double quotes'            => [
                'name'           => 'Query with double quotes',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE status = "Active"',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template",
            ],
            'missing position parameter'          => [
                'name'           => 'Missing position parameter',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE num = ?',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'missing named parameter'             => [
                'name'           => 'Missing named parameter',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE num = :num',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'mixing parameter styles incorrectly' => [
                'name'           => 'Mixing parameter styles incorrectly',
                'sqlTemplate'    => 'SELECT * FROM ::users WHERE name = ? AND city = :city',
                'params'         => ['John', [':city' => 'Vancouver']],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "Param args must be either a single array or multiple",
            ],
            'SQL injection attempt'               => [
                'name'           => 'SQL injection attempt',
                'sqlTemplate'    => "SELECT * FROM ::users WHERE name = 'John' OR 1=1; --",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
        ];
    }

}
