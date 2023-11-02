<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded when test is being run directly
require_once __DIR__ . '/../bootstrap.php';

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;
use Throwable;

class getTest extends BaseTest
{
    /**
     * @dataProvider provideValidQueries
     * @throws DBException
     */
    public function testValidGet(string $baseTable, int|array|string $idArrayOrSQL, array $params, ?array $expectedResult): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method
            $result      = DB::get($baseTable, $idArrayOrSQL, ...$params);
            $resultArray = $result->toArray();
            $this->assertSame($expectedResult, $resultArray);
            $this->assertSame($queryType, $result->mysqli('queryType'), "Query type was '{$result->mysqli('queryType')}', expected $queryType");
            $this->assertTrue($result->usingSmartStrings(), "Expected SmartStrings to be enabled for $queryType query");

            // Test instance method
            $db             = DB::getDefaultInstance();
            $instanceResult = $db->get($baseTable, $idArrayOrSQL, ...$params);
            $instanceArray  = $instanceResult->toArray();
            $this->assertSame($expectedResult, $instanceArray);
            $this->assertSame($queryType, $instanceResult->mysqli('queryType'), "Query type was '{$result->mysqli('queryType')}', expected $queryType");
            $this->assertTrue($result->usingSmartStrings(), "Expected SmartStrings to be enabled for $queryType query");

            // Verify static and instance methods return identical results
            $this->assertSame($resultArray, $instanceArray, "Static and instance methods should return identical results for $queryType query");
        }

        // Reset the flag to default for other tests
        DB::config('usePreparedStatements', true);
    }

    public function provideValidQueries(): array
    {
        return [
            'primary key as int'                                         => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 5,
                'params'         => [],
                'expectedResult' => ['num' => 5, 'name' => 'Charlie Brown', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Edmonton', 'dob' => '1989-11-11', 'age' => 34],
            ],
            'array of where conditions'                                  => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['isAdmin' => null, 'status' => 'active'],
                'params'         => [],
                'expectedResult' => ['num' => 7, 'name' => 'Erin Davis', 'isAdmin' => null, 'status' => 'Active', 'city' => 'Quebec', 'dob' => '1998-03-14', 'age' => 25],
            ],
            'sql WITHOUT where keyword'                                  => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'params'         => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult' => ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
            ],
            'sql WITH where keyword and whitespace padding'              => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'params'         => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'expectedResult' => ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
            ],
            'empty result set'                                           => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => -1,
                'params'         => [],
                'expectedResult' => [],
            ],
            'first row from full result set - empty string for selector' => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'expectedResult' => ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
            ],
            'explicit IS NULL condition'                                 => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin IS NULL',
                'params'         => [],
                'expectedResult' => ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
            ],
            'equals comparison with not-null parameter'                   => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'city = :city',
                'params'         => [[':city' => 'Vancouver']],
                'expectedResult' => ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
            ],
            'array condition with null value'                             => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['isAdmin' => null, 'city' => 'Toronto'],
                'params'         => [],
                'expectedResult' => ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
            ],
            'comparison with array containing null values'                => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'age > :minAge AND (city = :city OR status = :status)',
                'params'         => [[':minAge' => 30, ':city' => 'Toronto', ':status' => null]],
                'expectedResult' => ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
            ],
            'condition with NOT NULL explicit'                            => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin IS NOT NULL AND city = :city',
                'params'         => [[':city' => 'Vancouver']],
                'expectedResult' => ['num' => 1, 'name' => 'John Doe', 'isAdmin' => 1, 'status' => 'Active', 'city' => 'Vancouver', 'dob' => '1985-04-10', 'age' => 38],
            ],
            'null parameter for IS NULL condition'  => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin IS :value',
                'params'         => [[':value' => null]],
                'expectedResult' => ['num' => 2, 'name' => 'Jane Janey Doe', 'isAdmin' => null, 'status' => 'Inactive', 'city' => 'Toronto', 'dob' => '1990-06-15', 'age' => 33],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidQueries
     */
    public function testInvalidGet(string $baseTable, int|array|string $idArrayOrSQL, array $params, string $exceptionType, string $exceptionMatch): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method throws exception
            try {
                DB::get($baseTable, $idArrayOrSQL, ...$params);
                $this->fail("Static DB::get with $queryType queries did not throw exception");
            } catch (Throwable $e) {
                $message = "Static method with $queryType query";
                $this->assertSame($exceptionType, $e::class, $message);
                $this->assertStringContainsString($exceptionMatch, $e->getMessage(), $message);
            }

            // Test instance method throws exception
            try {
                $db = DB::getDefaultInstance();
                $db->get($baseTable, $idArrayOrSQL, ...$params);
                $this->fail("Instance \$db->get with $queryType queries did not throw exception");
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
            'query with LIMIT clause'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) LIMIT :limit',
                'params'         => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "This method doesn't support LIMIT or OFFSET, use select() instead",
            ],
            'query with OFFSET clause'              => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL) OFFSET :offset;',
                'params'         => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0, ':limit' => 3, ':offset' => 1]],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "This method doesn't support LIMIT or OFFSET, use select() instead",
            ],
            'query with unclosed single quote'      => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => "name = 'John",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template, found '",
            ],
            'query with unclosed double quote'      => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'name = "Jane',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template, found \"",
            ],
            'numeric value as column name'          => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '123 = name',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'empty table name'                      => [
                'baseTable'      => '',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "Invalid table name",
            ],
            'invalid table name with special chars' => [
                'baseTable'      => 'users;drop table',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "Invalid table name",
            ],
            'non-existent table'                    => [
                'baseTable'      => 'nonexistent_table',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'SQL injection attempt in where clause' => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => "name = 'John' OR 1=1; --",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'mixing parameter styles'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'name = ? AND city = :city',
                'params'         => ['John', [':city' => 'Vancouver']],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "Param args must be either a single array or multiple",
            ],
        ];
    }
}
