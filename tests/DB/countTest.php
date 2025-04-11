<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded when test is being run directly
require_once __DIR__ . '/../bootstrap.php';

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;
use RuntimeException;
use Throwable;

class countTest extends BaseTest
{
    /**
     * @dataProvider provideValidQueries
     * @throws DBException
     */
    public function testValidCount(string $baseTable, int|array|string $idArrayOrSQL, array $params, int $expectedResult): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method
            $result = DB::count($baseTable, $idArrayOrSQL, ...$params);
            $this->assertSame($expectedResult, $result);

            // Test instance method
            $db             = DB::newInstance();
            $instanceResult = $db->count($baseTable, $idArrayOrSQL, ...$params);
            $this->assertSame($expectedResult, $instanceResult);

            // Verify static and instance methods return identical results
            $this->assertSame($result, $instanceResult, "Static and instance methods should return identical results for $queryType query");
        }

        // Reset the flag to default for other tests
        DB::config('usePreparedStatements', true);
    }

    public function provideValidQueries(): array
    {
        return [
            'primary key as int'                             => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 5,
                'params'         => [],
                'expectedResult' => 1,
            ],
            'array of where conditions'                      => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['isAdmin' => null, 'status' => 'active'],
                'params'         => [],
                'expectedResult' => 2,
            ],
            'sql WITHOUT where keyword'                      => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '(:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'params'         => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0]],
                'expectedResult' => 5,
            ],
            'sql WITH where keyword and whitespace padding'  => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '  WHERE   (:ageMin <= age AND age <= :ageMax) AND (isAdmin = :isAdmin OR isAdmin IS NULL)',
                'params'         => [[':ageMin' => 30, ':ageMax' => 40, ':isAdmin' => 0]],
                'expectedResult' => 5,
            ],
            'empty result set'                               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => -1,
                'params'         => [],
                'expectedResult' => 0,
            ],
            'full result set - empty string for selector'    => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => '',
                'params'         => [],
                'expectedResult' => 20,
            ],
            'explicit IS NULL condition'                     => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin IS NULL',
                'params'         => [],
                'expectedResult' => 4,
            ],
            'equals comparison with named parameter'         => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'city = :city',
                'params'         => [[':city' => 'Vancouver']],
                'expectedResult' => 1,
            ],
            'equals comparison with positional parameter'    => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'city = ?',
                'params'         => ['Toronto'],
                'expectedResult' => 2,
            ],
            'array condition with null value'                => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['isAdmin' => null, 'city' => 'Toronto'],
                'params'         => [],
                'expectedResult' => 1,
            ],
            'complex WHERE with named parameters'            => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'age > :minAge AND age < :maxAge AND status = :status',
                'params'         => [[':minAge' => 30, ':maxAge' => 40, ':status' => 'Inactive']],
                'expectedResult' => 2,
            ],
            'complex WHERE with OR condition'                => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'age > :minAge AND city = :city',
                'params'         => [[':minAge' => 30, ':city' => 'Toronto']],
                'expectedResult' => 2,
            ],
            'condition with NOT NULL explicit'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin IS NOT NULL AND city = :city',
                'params'         => [[':city' => 'Vancouver']],
                'expectedResult' => 1,
            ],
            'null parameter for IS NULL condition'           => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin IS :value',
                'params'         => [[':value' => null]],
                'expectedResult' => 4,
            ],
            'count with multiple status values in IN clause' => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'status IN (:status1, :status2)',
                'params'         => [[':status1' => 'Active', ':status2' => 'Suspended']],
                'expectedResult' => 15,
            ],
            'count with range operators'                     => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'age > :minAge AND age < :maxAge',
                'params'         => [[':minAge' => 25, ':maxAge' => 35]],
                'expectedResult' => 7,
            ],
            'count with pattern matching'                    => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'name LIKE :pattern',
                'params'         => [[':pattern' => '%John%']],
                'expectedResult' => 2,
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidQueries
     */
    public function testInvalidCount(string $baseTable, int|array|string $idArrayOrSQL, array $params, string $exceptionType, string $exceptionMatch): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method throws exception
            try {
                DB::count($baseTable, $idArrayOrSQL, ...$params);
                $this->fail("Static DB::count with $queryType queries did not throw exception");
            } catch (Throwable $e) {
                $message = "Static method with $queryType query";
                $this->assertSame($exceptionType, $e::class, $message);
                $this->assertStringContainsString($exceptionMatch, $e->getMessage(), $message);
            }

            // Test instance method throws exception
            try {
                $db = DB::newInstance();
                $db->count($baseTable, $idArrayOrSQL, ...$params);
                $this->fail("Instance \$db->count with $queryType queries did not throw exception");
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
            'primary key as string'                 => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => "5",
                'params'         => [],
                'exceptionType'  => InvalidArgumentException::class,
                'exceptionMatch' => "Numeric string detected",
            ],
            'array with invalid column names'       => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => ['notThere' => null, 'missingColumn' => 'active'],
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Unknown column",
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
            'query with single quotes'              => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => "status = 'Active'",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template",
            ],
            'query with double quotes'              => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'status = "Active"',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Quotes are not allowed in sql template",
            ],
            'standalone numeric value in condition' => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'isAdmin = 1',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Disallowed standalone number in sql template",
            ],
            'sql starting with SELECT'              => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'SELECT * FROM users',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'missing position parameter'            => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'num = ?',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'missing named parameter'               => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'num = :num',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
            'table with prefix already applied'     => [
                'baseTable'      => 'test_users',
                'idArrayOrSQL'   => "",
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
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
                'exceptionMatch' => "either a single array or multiple",
            ],
            'malformed GROUP BY clause'             => [
                'baseTable'      => 'users',
                'idArrayOrSQL'   => 'GROUP BY status, ;',
                'params'         => [],
                'exceptionType'  => DBException::class,
                'exceptionMatch' => "Error executing query",
            ],
        ];
    }
}
