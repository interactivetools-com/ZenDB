<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::getColumnDefinitions() method
 *
 * @covers \Itools\ZenDB\Connection::getColumnDefinitions
 */
class GetColumnDefinitionsTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    public function testReturnsColumnDefinitions(): void
    {
        $columns = DB::getColumnDefinitions('users');

        $this->assertIsArray($columns);
        $this->assertArrayHasKey('num', $columns);
        $this->assertArrayHasKey('name', $columns);
        $this->assertArrayHasKey('status', $columns);
    }

    public function testIncludesAllColumns(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // Check for expected columns
        $expectedColumns = ['num', 'name', 'isAdmin', 'status', 'city', 'dob', 'age'];
        foreach ($expectedColumns as $col) {
            $this->assertArrayHasKey($col, $columns, "Missing column: $col");
        }
    }

    public function testStripsIntDisplayWidth(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // INT should not have display width like int(11)
        // Note: MySQL 8.0.17+ deprecated display width for int types
        $numDef = $columns['num'] ?? '';
        $this->assertStringNotContainsString('int(', strtolower($numDef));
    }

    public function testColumnTypesAreCorrect(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // num should be INT
        $this->assertStringContainsStringIgnoringCase('int', $columns['num']);

        // name should be VARCHAR
        $this->assertStringContainsStringIgnoringCase('varchar', $columns['name']);

        // status should be ENUM
        $this->assertStringContainsStringIgnoringCase('enum', $columns['status']);

        // dob should be DATE
        $this->assertStringContainsStringIgnoringCase('date', $columns['dob']);
    }

    public function testStripsDefaultCharset(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // Default charset/collation should be stripped
        foreach ($columns as $definition) {
            // Common default charset patterns that should be removed
            $this->assertStringNotContainsString('CHARACTER SET utf8mb4', $definition);
            $this->assertStringNotContainsString('COLLATE utf8mb4_unicode_ci', $definition);
        }
    }

    public function testNonExistingTableReturnsEmpty(): void
    {
        $columns = DB::getColumnDefinitions('nonexistent_table');

        $this->assertIsArray($columns);
        $this->assertEmpty($columns);
    }

    public function testOrdersTableColumns(): void
    {
        $columns = DB::getColumnDefinitions('orders');

        $this->assertArrayHasKey('order_id', $columns);
        $this->assertArrayHasKey('user_id', $columns);
        $this->assertArrayHasKey('order_date', $columns);
        $this->assertArrayHasKey('total_amount', $columns);
    }

    public function testDecimalTypePreserved(): void
    {
        $columns = DB::getColumnDefinitions('orders');

        // total_amount is DECIMAL(10,2)
        $amountDef = $columns['total_amount'] ?? '';
        $this->assertStringContainsStringIgnoringCase('decimal', $amountDef);
    }

    public function testNullableInfo(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // isAdmin is TINYINT(1) NULL
        $isAdminDef = $columns['isAdmin'] ?? '';
        $this->assertStringContainsString('NULL', $isAdminDef);
    }

    public function testAutoIncrementInfo(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // num should have AUTO_INCREMENT
        $numDef = $columns['num'] ?? '';
        $this->assertStringContainsStringIgnoringCase('auto_increment', $numDef);
    }

    public function testPrimaryKeyColumn(): void
    {
        // Primary key info might be in the column definition
        $columns = DB::getColumnDefinitions('users');
        $numDef = $columns['num'] ?? '';

        // Either has PRIMARY KEY in definition or auto_increment (which implies PK for InnoDB)
        $hasPrimaryInfo = stripos($numDef, 'auto_increment') !== false;
        $this->assertTrue($hasPrimaryInfo);
    }

    //region Data Provider

    /**
     * @dataProvider provideTableColumnScenarios
     */
    public function testTableColumnScenarios(string $table, string $column, string $expectedType): void
    {
        $columns = DB::getColumnDefinitions($table);

        $this->assertArrayHasKey($column, $columns);
        $this->assertStringContainsStringIgnoringCase($expectedType, $columns[$column]);
    }

    public static function provideTableColumnScenarios(): array
    {
        return [
            ['users', 'num', 'int'],
            ['users', 'name', 'varchar'],
            ['users', 'status', 'enum'],
            ['users', 'dob', 'date'],
            ['users', 'age', 'int'],
            ['orders', 'order_id', 'int'],
            ['orders', 'total_amount', 'decimal'],
            ['products', 'price', 'decimal'],
            ['products', 'product_name', 'varchar'],
        ];
    }

    //endregion
}
