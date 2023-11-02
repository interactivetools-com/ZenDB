<?php
declare(strict_types=1);
namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded
require_once __DIR__ . '/../bootstrap.php';

use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;
use Itools\ZenDB\RawSql;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class insertTest extends BaseTest
{
    protected function setUp(): void
    {
        DB::config(self::getConfigDefaults());
        self::resetTempTestTables();
        // overrides parent BaseTest class setUp
    }

    /**
     * @dataProvider provideValidInserts
     * @throws DBException
     */
    public function testValidInsert(string $baseTable, array $colsToValues, int $expectedId, array $expectedRecord): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Reset the test tables to ensure each test has a clean slate
            self::resetTempTestTables();

            // Test static method
            $insertId = DB::insert($baseTable, $colsToValues);
            $this->assertSame($expectedId, $insertId, "Insert ID mismatch for $queryType query");

            // Verify the record was inserted correctly
            $result = DB::get($baseTable, $insertId);
            $this->assertTrue($result->usingSmartStrings(), "Expected SmartStrings to be enabled for $queryType query");
            $this->assertSame($expectedRecord, $result->toArray(), "Inserted record data mismatch for $queryType query");

            // Test instance method
            self::resetTempTestTables();
            $db = DB::getDefaultInstance();
            $instanceInsertId = $db->insert($baseTable, $colsToValues);
            $this->assertSame($expectedId, $instanceInsertId, "Instance insert ID mismatch for $queryType query");

            // Verify the record was inserted correctly via instance method
            $instanceResult = $db->get($baseTable, $instanceInsertId);
            $this->assertTrue($instanceResult->usingSmartStrings(), "Expected SmartStrings to be enabled for instance method with $queryType query");
            $this->assertSame($expectedRecord, $instanceResult->toArray(), "Inserted record data mismatch for instance method with $queryType query");
        }

        // Reset the flag to default for other tests
        DB::config('usePreparedStatements', true);
    }

    public function provideValidInserts(): array
    {
        return [
            'basic insert with specified primary key' => [
                'baseTable' => 'users',
                'colsToValues' => [
                    'num'     => 212,
                    'name'    => 'Jillian Ty lair',
                    'isAdmin' => 1,
                    'status'  => 'Active',
                    'city'    => 'New York',
                    'dob'     => '1989-01-02',
                    'age'     => 35,
                ],
                'expectedId' => 212,
                'expectedRecord' => [
                    'num'     => 212,
                    'name'    => 'Jillian Ty lair',
                    'isAdmin' => 1,
                    'status'  => 'Active',
                    'city'    => 'New York',
                    'dob'     => '1989-01-02',
                    'age'     => 35,
                ],
            ],
            'auto-increment primary key' => [
                'baseTable' => 'users',
                'colsToValues' => [
                    'name'    => 'Auto Increment Test',
                    'isAdmin' => 0,
                    'status'  => 'Inactive',
                    'city'    => 'Chicago',
                    'dob'     => '1990-05-15',
                    'age'     => 33,
                ],
                'expectedId' => 21,  // This should be the next auto-increment value after existing records
                'expectedRecord' => [
                    'num'     => 21,
                    'name'    => 'Auto Increment Test',
                    'isAdmin' => 0,
                    'status'  => 'Inactive',
                    'city'    => 'Chicago',
                    'dob'     => '1990-05-15',
                    'age'     => 33,
                ],
            ],
            'insert with NULL values' => [
                'baseTable' => 'users',
                'colsToValues' => [
                    'name'    => 'Null Values Test',
                    'isAdmin' => null,
                    'status'  => 'Active',
                    'city'    => 'Boston',
                    'dob'     => null,
                    'age'     => 25,
                ],
                'expectedId' => 21,  // Assuming previous tests have been reset
                'expectedRecord' => [
                    'num'     => 21,
                    'name'    => 'Null Values Test',
                    'isAdmin' => null,
                    'status'  => 'Active',
                    'city'    => 'Boston',
                    'dob'     => null,
                    'age'     => 25,
                ],
            ],
            'insert with RawSql value' => [
                'baseTable' => 'users',
                'colsToValues' => [
                    'name'    => 'RawSql Test',
                    'isAdmin' => 0,
                    'status'  => 'Active',
                    'city'    => 'San Francisco',
                    'dob'     => DB::rawSql('CURDATE()'),
                    'age'     => 28,
                ],
                'expectedId' => 21,  // Assuming previous tests have been reset
                'expectedRecord' => [
                    'num'     => 21,
                    'name'    => 'RawSql Test',
                    'isAdmin' => 0,
                    'status'  => 'Active',
                    'city'    => 'San Francisco',
                    'dob'     => date('Y-m-d'),  // Current date in Y-m-d format to match MySQL CURDATE()
                    'age'     => 28,
                ],
            ],
            'insert with special characters' => [
                'baseTable' => 'users',
                'colsToValues' => [
                    'name'    => "O'Reilly & Sons",  // Has SQL injection characters
                    'isAdmin' => 1,
                    'status'  => 'Active',
                    'city'    => 'Dublin, Ireland',
                    'dob'     => '1975-03-17',
                    'age'     => 48,
                ],
                'expectedId' => 21,  // Assuming previous tests have been reset
                'expectedRecord' => [
                    'num'     => 21,
                    'name'    => "O'Reilly & Sons",
                    'isAdmin' => 1,
                    'status'  => 'Active',
                    'city'    => 'Dublin, Ireland',
                    'dob'     => '1975-03-17',
                    'age'     => 48,
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidInserts
     */
    public function testInvalidInsert(string $baseTable, array $colsToValues, string $exceptionType, string $exceptionMatch): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Test static method throws exception
            try {
                DB::insert($baseTable, $colsToValues);
                $this->fail("Static DB::insert with $queryType query did not throw exception");
            } catch (Throwable $e) {
                $message = "Static method with $queryType query";
                $this->assertStringContainsString($exceptionMatch, $e->getMessage(), $message);
                $this->assertSame($exceptionType, $e::class, $message);
            }

            // Test instance method throws exception
            try {
                $db = DB::getDefaultInstance();
                $db->insert($baseTable, $colsToValues);
                $this->fail("Instance \$db->insert with $queryType query did not throw exception");
            } catch (Throwable $e) {
                $message = "Instance method with $queryType query";
                $this->assertSame($exceptionType, $e::class, $message);
                $this->assertStringContainsString($exceptionMatch, $e->getMessage(), $message);
            }
        }

        // Reset the flag to default for other tests
        DB::config('usePreparedStatements', true);
    }

    public function provideInvalidInserts(): array
    {
        return [
            'duplicate primary key' => [
                'baseTable' => 'users',
                'colsToValues' => [
                    'num'     => 1,  // This primary key already exists
                    'name'    => 'Duplicate Test',
                    'isAdmin' => 1,
                    'status'  => 'Active',
                    'city'    => 'New York',
                ],
                'exceptionType' => DBException::class,
                'exceptionMatch' => 'Duplicate entry',
            ],
            'invalid table name' => [
                'baseTable' => 'nonexistent_table',
                'colsToValues' => [
                    'name'    => 'Invalid Table Test',
                    'isAdmin' => 1,
                ],
                'exceptionType' => DBException::class,
                'exceptionMatch' => 'Error executing query',
            ],
            'empty column values' => [
                'baseTable' => 'users',
                'colsToValues' => [],
                'exceptionType' => InvalidArgumentException::class,
                'exceptionMatch' => 'No colsToValues',
            ],
            'invalid column name' => [
                'baseTable' => 'users',
                'colsToValues' => [
                    'nonexistent_column' => 'Invalid Column Test',
                    'name'              => 'Valid Column',
                ],
                'exceptionType' => DBException::class,
                'exceptionMatch' => 'Unknown column',
            ],
            'invalid enum value' => [
                'baseTable' => 'users',
                'colsToValues' => [
                    'name'    => 'Invalid Enum Test',
                    'status'  => 'InvalidStatus',  // Not a valid enum value
                ],
                'exceptionType' => DBException::class,
                'exceptionMatch' => 'Error executing query',
            ],
        ];
    }

    /**
     * Test that DB::rawSql() works correctly in insert queries
     */
    public function testRawSqlInInsert(): void
    {
        foreach ([true, false] as $usePrepared) {
            DB::config('usePreparedStatements', $usePrepared);
            $queryType = $usePrepared ? 'prepared' : 'escaped';

            // Reset the test tables to ensure each test has a clean slate
            self::resetTempTestTables();

            // Test with multiple raw SQL functions
            $colsToValues = [
                'name'    => 'Raw SQL Test',
                'isAdmin' => DB::rawSql('1'),
                'status'  => 'Active',
                'city'    => DB::rawSql("CONCAT('New', ' ', 'York')"),
                'dob'     => DB::rawSql('CURDATE()'),
                'age'     => DB::rawSql('YEAR(CURDATE()) - YEAR(CURDATE()) + 30'),
            ];

            $insertId = DB::insert('users', $colsToValues);
            $this->assertIsInt($insertId, "Insert with raw SQL should return an integer ID for $queryType query");

            $result = DB::get('users', $insertId);
            $this->assertTrue($result->usingSmartStrings(), "Expected SmartStrings to be enabled for $queryType query");

            $insertedData = $result->toArray();
            $this->assertSame('Raw SQL Test', $insertedData['name'], "Name should match for $queryType query");
            $this->assertSame(1, $insertedData['isAdmin'], "isAdmin should be 1 for $queryType query");
            $this->assertSame('Active', $insertedData['status'], "Status should match for $queryType query");
            $this->assertSame('New York', $insertedData['city'], "City should be 'New York' for $queryType query");
            $this->assertSame(date('Y-m-d'), $insertedData['dob'], "Dob should be current date for $queryType query");
            $this->assertSame(30, $insertedData['age'], "Age should be 30 for $queryType query");
        }

        // Reset the flag to default for other tests
        DB::config('usePreparedStatements', true);
    }
}
