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

use Itools\ZenDB\DB;
use RuntimeException;
use Throwable;

class insertTest extends BaseTest
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

    public function testInsertSequence(): void {
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

        // Act & Assert

        // Test new record number is returned and has expected values
        $insertId = DB::insert($baseTable, $colsToValues);
        $this->assertSame(expected: 212, actual: $insertId, message: "insertId should be 212");
        $this->assertSame(
            expected: $colsToValues,
            actual:   DB::get($baseTable, $insertId)->toArray(),
            message:  "Inserted record should have expected values"
        );

        // Test inserting record with duplicate primary key throws exception
        $exceptionThrown = false;
        try {
            DB::insert($baseTable, $colsToValues);
        }
        catch (\Exception $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown, "Expected exception thrown");
    }


}
