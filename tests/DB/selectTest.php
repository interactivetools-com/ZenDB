<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded when test is being run directly
require_once __DIR__ . '/../bootstrap.php';

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;
use Throwable;

class selectTest extends BaseTest
{
    /**
     * @dataProvider provideValidQueries
     * @throws DBException
     */
    public function testValidSelect(string $baseTable, int|array|string $idArrayOrSQL, array $params, ?array $expectedResult): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method
            $result      = DB::select($baseTable, $idArrayOrSQL, ...$params);
            $resultArray = $result->toArray();
            $this->assertSame($expectedResult, $resultArray);
            $this->assertSame($queryType, $result->mysqli('queryType'), "Query type was '{$result->mysqli('queryType')}', expected $queryType");
            $this->assertTrue($result->usingSmartStrings(), "Expected SmartStrings to be enabled for $queryType query");

            // Test instance method
            $db             = DB::getDefaultInstance();
            $instanceResult = $db->select($baseTable, $idArrayOrSQL, ...$params);
            $instanceArray  = $instanceResult->toArray();
            $this->assertSame($expectedResult, $instanceArray);
            $this->assertSame($queryType, $instanceResult->mysqli('queryType'), "Query type was '{$result->mysqli('queryType')}', expected $queryType");
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
            'primary key as int'                                        => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 5,
                'params'         => [],
                'expectedResult' => [
                    ['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34],
                ],
            ],
            'array of where conditions'                                 => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['isAdmin' => null, 'status' => 'active'],
                'params'         => [],
                'expectedResult' => [
                    ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                ],
            ],
            'sql WITHOUT where keyword with named params'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) LIMIT :limit OFFSET :offset',
                'params'         => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult' => [
                    ['num' => 8, 'name' => 'Frank <b>Miller</b>', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Winnipeg', 'dob' => '1992-07-22', 'age' => 31],
                    ['num' => 10, 'name' => 'Helen Clark', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Saskatoon', 'dob' => '1986-05-16', 'age' => 37],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                ],
            ],
            'sql WITH where keyword and whitespace padding with params' => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) LIMIT :limit OFFSET :offset',
                'params'         => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult' => [
                    ['num' => 8, 'name' => 'Frank <b>Miller</b>', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Winnipeg', 'dob' => '1992-07-22', 'age' => 31],
                    ['num' => 10, 'name' => 'Helen Clark', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Saskatoon', 'dob' => '1986-05-16', 'age' => 37],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                ],
            ],
            'empty result set'                                          => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => -1,
                'params'         => [],
                'expectedResult' => [],
            ],
            'empty string for selector - all rows'                      => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'expectedResult' => [
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
            'explicit IS NULL condition'                                => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin IS NULL',
                'params'         => [],
                'expectedResult' => [
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
                    ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
                    ['num' => 14, 'name' => 'Linda Harris', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'London', 'dob' => '1978-11-21', 'age' => 45],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                ],
            ],
            'equals comparison with named parameter'                    => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'city = :city',
                'params'         => [[':city' => 'Vancouver']],
                'expectedResult' => [
                    ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
                ],
            ],
            'equals comparison with positional parameter'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'city = ?',
                'params'         => ['Toronto'],
                'expectedResult' => [
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
                    ['num' => 16, 'name' => 'Nancy Allen', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Toronto', 'dob' => '1985-12-24', 'age' => 38],
                ],
            ],
            'array condition with null value'                           => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['isAdmin' => null, 'city' => 'Toronto'],
                'params'         => [],
                'expectedResult' => [
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
                ],
            ],
            'complex WHERE with named parameters'                       => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'age > :minAge AND age < :maxAge AND status = :status',
                'params'         => [[':minAge' => 30, ':maxAge' => 40, ':status' => 'Inactive']],
                'expectedResult' => [
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
                    ['num' => 10, 'name' => 'Helen Clark', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Saskatoon', 'dob' => '1986-05-16', 'age' => 37],
                ],
            ],
            'ORDER BY with parameter'                                   => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin = :isAdmin ORDER BY age DESC',
                'params'         => [[':isAdmin' => 1]],
                'expectedResult' => [
                    ['num' => 9, 'name' => 'George Wilson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Halifax', 'dob' => '1970-10-05', 'age' => 53],
                    ['num' => 3, 'name' => 'Alice Smith', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Montreal', 'dob' => '1980-12-20', 'age' => 43],
                    ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
                    ['num' => 13, 'name' => 'Kevin Lewis', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Kitchener', 'dob' => '1988-08-19', 'age' => 35],
                    ['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34],
                    ['num' => 15, 'name' => 'Mike Nelson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Windsor', 'dob' => '1994-02-28', 'age' => 30],
                    ['num' => 17, 'name' => 'Oliver Young', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Fredericton', 'dob' => '1997-06-30', 'age' => 26],
                    ['num' => 11, 'name' => 'Ivan Scott', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Victoria', 'dob' => '2000-01-01', 'age' => 24],
                ],
            ],
            'LIMIT with parameter'                                      => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'status = :status LIMIT :limit',
                'params'         => [[':status' => 'Active', ':limit' => 3]],
                'expectedResult' => [
                    ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
                    ['num' => 3, 'name' => 'Alice Smith', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Montreal', 'dob' => '1980-12-20', 'age' => 43],
                    ['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34],
                ],
            ],
            'LIMIT with OFFSET parameters'                              => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'status = :status LIMIT :limit OFFSET :offset',
                'params'         => [[':status' => 'Active', ':limit' => 2, ':offset' => 3]],
                'expectedResult' => [
                    ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
                    ['num' => 9, 'name' => 'George Wilson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Halifax', 'dob' => '1970-10-05', 'age' => 53],
                ],
            ],
            'LIMIT with OFFSET using parameters'                        => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'age > :minAge LIMIT :limit OFFSET :offset',
                'params'         => [[':minAge' => 40, ':limit' => 3, ':offset' => 1]],
                'expectedResult' => [
                    ['num' => 6, 'name' => 'Dave Williams', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => 'Ottawa', 'dob' => '1975-09-30', 'age' => 48],
                    ['num' => 9, 'name' => 'George Wilson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Halifax', 'dob' => '1970-10-05', 'age' => 53],
                    ['num' => 14, 'name' => 'Linda Harris', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'London', 'dob' => '1978-11-21', 'age' => 45],
                ],
            ],
            'ORDER BY with LIMIT and OFFSET parameters'                 => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'ORDER BY age DESC LIMIT :limit OFFSET :offset',
                'params'         => [[':limit' => 3, ':offset' => 2]],
                'expectedResult' => [
                    ['num' => 14, 'name' => 'Linda Harris', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'London', 'dob' => '1978-11-21', 'age' => 45],
                    ['num' => 20, 'name' => 'Rachel Carter', 'isAdmin' => 0, 'status' => 'Suspended', 'city' => 'Yellowknife', 'dob' => '1979-07-04', 'age' => 44],
                    ['num' => 3, 'name' => 'Alice Smith', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Montreal', 'dob' => '1980-12-20', 'age' => 43],
                ],
            ],
            'LIKE operator with wildcard'                               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'name LIKE :pattern',
                'params'         => [[':pattern' => '%Smith%']],
                'expectedResult' => [
                    ['num' => 3, 'name' => 'Alice Smith', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Montreal', 'dob' => '1980-12-20', 'age' => 43],
                ],
            ],
            'Complex query with multiple conditions and params'         => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '(age BETWEEN :minAge AND :maxAge) AND (status = :status1 OR status = :status2) ORDER BY age ASC LIMIT :limit',
                'params'         => [[':minAge' => 30, ':maxAge' => 40, ':status1' => 'Active', ':status2' => 'Inactive', ':limit' => 4]],
                'expectedResult' => [
                    ['num' => 15, 'name' => 'Mike Nelson', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Windsor', 'dob' => '1994-02-28', 'age' => 30],
                    ['num' => 19, 'name' => 'Quentin Adams', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Charlottetown', 'dob' => '1991-03-31', 'age' => 32],
                    ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
                    ['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34],
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidQueries
     */
    public function testInvalidSelect(string $baseTable, int|array|string $idArrayOrSQL, array $params, string $exceptionType, string $exceptionMatch): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method throws exception
            try {
                DB::select($baseTable, $idArrayOrSQL, ...$params);
                $this->fail("Static DB::select with $queryType queries did not throw exception");
            } catch (Throwable $e) {
                $message = "Static method with $queryType query";
                $this->assertSame($exceptionType, $e::class, $message);
                $this->assertStringContainsString($exceptionMatch, $e->getMessage(), $message);
            }

            // Test instance method throws exception
            try {
                $db = DB::getDefaultInstance();
                $db->select($baseTable, $idArrayOrSQL, ...$params);
                $this->fail("Instance \$db->select with $queryType queries did not throw exception");
            } catch (Throwable $e) {
                $message = "Instance method with $queryType query";
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
            'primary key as string'                  => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => "5",
                'params'         => [],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "Numeric string detected",
            ],
            'array with invalid column names'        => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['notThere' => null, 'missingColumn' => 'active'],
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Unknown column",
            ],
            'query with unclosed single quote'       => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => "name = 'John",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template, found '",
            ],
            'query with unclosed double quote'       => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'name = "Jane',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template, found \"",
            ],
            'query with single quotes'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => "status = 'Active'",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template",
            ],
            'query with double quotes'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'status = "Active"',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template",
            ],
            'standalone numeric value in condition'  => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin = 1',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'numeric value in LIMIT'                 => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'LIMIT 10',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'numeric value in OFFSET'                => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'OFFSET 5',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'numeric value in ORDER BY LIMIT OFFSET' => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'ORDER BY age DESC LIMIT 3 OFFSET 2',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'sql starting with SELECT'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'SELECT * FROM users',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'missing position parameter'             => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'num = ?',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'missing named parameter'                => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'num = :num',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'table with prefix already applied'      => [
                'baseTable'      => 'test_users',
                'idArrayOrSQL'   => "",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'numeric value as column name'           => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '123 = name',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'empty table name'                       => [
                'baseTable'      => '',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "Invalid table name",
            ],
            'invalid table name with special chars'  => [
                'baseTable'      => 'users;drop table',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "Invalid table name",
            ],
            'non-existent table'                     => [
                'baseTable'      => 'nonexistent_table',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'SQL injection attempt in where clause'  => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => "name = 'John' OR 1=1; --",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'mixing parameter styles'                => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'name = ? AND city = :city',
                'params'         => ['John', [':city' => 'Vancouver']],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "either a single array or multiple",
            ],
            'malformed GROUP BY clause'              => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'GROUP BY status, ;',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'GROUP BY with aggregate function'       => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'SELECT status, COUNT(*) as count GROUP BY status ORDER BY count DESC',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
        ];
    }
}
