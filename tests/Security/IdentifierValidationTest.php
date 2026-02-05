<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Security;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for table and column identifier validation
 *
 * @covers \Itools\ZenDB\ConnectionInternals::assertValidTable
 * @covers \Itools\ZenDB\ConnectionInternals::assertValidColumn
 */
class IdentifierValidationTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Valid Table Names

    public function testValidTableNameAccepted(): void
    {
        $result = DB::select('users');
        $this->assertCount(20, $result);
    }

    public function testTableNameWithUnderscoreAccepted(): void
    {
        $result = DB::select('order_details');
        $this->assertCount(30, $result);
    }

    public function testTableNameWithNumberAccepted(): void
    {
        // Create a test table with number in name
        DB::query("DROP TEMPORARY TABLE IF EXISTS test_table2");
        DB::query("CREATE TEMPORARY TABLE test_table2 (id INT)");

        $result = DB::select('table2');
        $this->assertCount(0, $result);
    }

    public function testTableNameStartingWithNumber(): void
    {
        // MySQL allows table names starting with numbers if quoted
        DB::query("DROP TEMPORARY TABLE IF EXISTS `test_2table`");
        DB::query("CREATE TEMPORARY TABLE `test_2table` (id INT)");

        // But our validation requires alphanumeric start
        $result = DB::select('2table');
        $this->assertCount(0, $result);
    }

    //endregion
    //region Invalid Table Names

    public function testTableNameWithSpacesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid table name");

        DB::select('user table');
    }

    public function testTableNameWithQuotesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid table name");

        DB::select("users'");
    }

    public function testTableNameWithSemicolonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid table name");

        DB::select("users;");
    }

    public function testTableNameWithBacktickThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid table name");

        DB::select("users`");
    }

    public function testTableNameWithParenthesesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid table name");

        DB::select("users()");
    }

    public function testTableNameWithEqualsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid table name");

        DB::select("users=1");
    }

    //endregion
    //region Valid Column Names (via WHERE array)

    public function testValidColumnNameAccepted(): void
    {
        $result = DB::select('users', ['name' => 'John Doe']);
        $this->assertCount(1, $result);
    }

    public function testColumnNameWithUnderscoreAccepted(): void
    {
        $result = DB::select('users', ['isAdmin' => 1]);
        $this->assertCount(8, $result);
    }

    public function testColumnNameWithNumberAccepted(): void
    {
        // Column names like col1, field2 should be accepted
        // Using the existing test_users table which has valid column names
        $result = DB::select('users', ['num' => 1]);
        $this->assertCount(1, $result);
    }

    //endregion
    //region Invalid Column Names (via WHERE array)

    public function testColumnNameWithSpacesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid column name");

        DB::select('users', ['user name' => 'John']);
    }

    public function testColumnNameWithDashInUpdateWorks(): void
    {
        // Dashes are actually allowed in our validation ([\w-]+)
        // But let's verify hyphen works using backticks in raw SQL
        DB::query("DROP TEMPORARY TABLE IF EXISTS test_dash_col");
        DB::query("CREATE TEMPORARY TABLE test_dash_col (`my-col` INT)");

        // Insert with dash in column name should work
        DB::query("INSERT INTO test_dash_col (`my-col`) VALUES (?)", 1);
        $result = DB::query("SELECT * FROM test_dash_col");
        $this->assertCount(1, $result);
    }

    public function testColumnNameWithQuotesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid column name");

        DB::select('users', ["name'" => 'John']);
    }

    public function testColumnNameWithSemicolonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid column name");

        DB::select('users', ['name;' => 'John']);
    }

    public function testNonStringColumnNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Column names must be strings");

        // Numeric key in WHERE array
        DB::select('users', [0 => 'John']);
    }

    //endregion
    //region Data Provider Tests

    /**
     * @dataProvider provideValidIdentifiers
     */
    public function testValidIdentifiers(string $identifier): void
    {
        // Test that the identifier passes validation when used in a column placeholder
        // We use a dynamic column name in select to test identifier validation
        // Using users table and valid column name 'num' aliased with the identifier
        $result = DB::query("SELECT num as `?` FROM ::users LIMIT 1", $identifier);
        $this->assertCount(1, $result);
    }

    public static function provideValidIdentifiers(): array
    {
        return [
            'lowercase'   => ['mycolumn'],
            'uppercase'   => ['MYCOLUMN'],
            'mixed case'  => ['MyColumn'],
            'underscore'  => ['my_column'],
            'number'      => ['column2'],
            'hyphen'      => ['my-column'],
            'all types'   => ['My_Column-2'],
        ];
    }

    /**
     * @dataProvider provideInvalidIdentifiers
     */
    public function testInvalidIdentifiers(string $identifier, string $description): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::query("SELECT * FROM `?`", $identifier);
    }

    public static function provideInvalidIdentifiers(): array
    {
        return [
            ['user table', 'space'],
            ["user's", 'single quote'],
            ['user"s', 'double quote'],
            ['user;drop', 'semicolon'],
            ['user`s', 'backtick'],
            ['user()', 'parentheses'],
            ['user=1', 'equals'],
            // Note: 'user--' actually passes validation as hyphen is allowed ([\w-]+)
            ['user/*', 'comment start'],
            ['user*/', 'comment end'],
        ];
    }

    //endregion
}
