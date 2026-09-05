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
 * @covers \Itools\ZenDB\DB::assertIdentifier
 * @covers \Itools\ZenDB\DB::isIdentifier
 */
class IdentifierValidationTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTestTables();
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
        DB::query("INSERT INTO test_table2 VALUES (?)", 42);

        // A row comes back, so the name was accepted and the right table queried
        $result = DB::select('table2');
        $this->assertCount(1, $result);
        $this->assertSame(42, $result->first()->get('id')->value());
    }

    public function testTableNameStartingWithNumber(): void
    {
        DB::query("DROP TEMPORARY TABLE IF EXISTS `test_2table`");
        DB::query("CREATE TEMPORARY TABLE `test_2table` (id INT)");
        DB::query("INSERT INTO `test_2table` VALUES (?)", 42);

        // A leading digit is a valid identifier; a row comes back to prove the right table was queried
        $result = DB::select('2table');
        $this->assertCount(1, $result);
        $this->assertSame(42, $result->first()->get('id')->value());
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

    public function testTableNameWithTrailingNewlineThrows(): void
    {
        // With a $ anchor instead of \z, "users\n" would pass validation ($ also matches before a trailing newline)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid table name");

        DB::select("users\n");
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
        $this->assertSame(1, $result->first()->get('my-col')->value());
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

    public function testColumnNameWithTrailingNewlineThrows(): void
    {
        // With a $ anchor instead of \z, "name\n" would pass validation ($ also matches before a trailing newline)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid column name");

        DB::select('users', ["name\n" => 'John']);
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
        // Alias 'num' to the identifier; reading the value back under that exact
        // key proves the alias survived quoting unmangled
        $row = DB::query("SELECT num as `?` FROM ::users ORDER BY num LIMIT 1", $identifier)->first();
        $this->assertSame(1, $row->get($identifier)->value());
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
    public function testInvalidIdentifiers(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", $identifier);
    }

    public static function provideInvalidIdentifiers(): array
    {
        return [
            'space'            => ['user table'],
            'single quote'     => ["user's"],
            'double quote'     => ['user"s'],
            'semicolon'        => ['user;drop'],
            'backtick'         => ['user`s'],
            'parentheses'      => ['user()'],
            'equals'           => ['user=1'],
            // Note: 'user--' actually passes validation as hyphen is allowed ([\w-]+)
            'comment start'    => ['user/*'],
            'comment end'      => ['user*/'],
            'trailing newline' => ["users\n"],
        ];
    }

    //endregion
    //region assertIdentifier()

    /**
     * @dataProvider provideValidIdentifiers
     */
    public function testAssertIdentifierAcceptsValidNames(string $identifier): void
    {
        $this->expectNotToPerformAssertions();
        DB::assertIdentifier($identifier);
    }

    /**
     * @dataProvider provideInvalidIdentifiers
     */
    public function testAssertIdentifierRejectsInvalidNames(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid identifier");

        DB::assertIdentifier($identifier);
    }

    public function testAssertIdentifierRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid identifier");

        DB::assertIdentifier('');
    }

    public function testAssertIdentifierNamesTheValueInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid sort column 'title; DROP TABLE users'");

        DB::assertIdentifier('title; DROP TABLE users', 'sort column');
    }

    public function testAssertIdentifierAddsValidNamesToSafeIdentifiers(): void
    {
        unset(DB::$safeIdentifiers['brand_new_column']);
        DB::assertIdentifier('brand_new_column');
        $this->assertArrayHasKey('brand_new_column', DB::$safeIdentifiers);
    }

    public function testAssertIdentifierRejectsInvalidNameOnRepeatCalls(): void
    {
        try {
            DB::assertIdentifier('bad name');
        } catch (InvalidArgumentException) {
            // first call throws; the repeat below must throw too
        }

        $this->expectException(InvalidArgumentException::class);
        DB::assertIdentifier('bad name');
    }

    public function testSafeIdentifiersPrepopulatedNamesAreValid(): void
    {
        foreach (array_keys(DB::$safeIdentifiers) as $identifier) {
            $this->assertTrue(DB::isIdentifier((string)$identifier), "preloaded name '$identifier' must pass isIdentifier()");
        }
    }

    public function testEveryAssertIdentifierCallSiteChecksSafeIdentifiersFirst(): void
    {
        $violations = [];
        foreach (glob(__DIR__ . '/../../src/*.php') as $file) {
            if (basename($file) === 'DBInternals.php') { // defines assertIdentifier and shows the form in its docblock
                continue;
            }
            foreach (file($file) as $index => $line) {
                if (str_contains($line, 'DB::assertIdentifier(') && !str_contains($line, 'isset(DB::$safeIdentifiers[')) {
                    $violations[] = basename($file) . ':' . ($index + 1);
                }
            }
        }
        $this->assertSame([], $violations, 'Call sites must skip known names: isset(DB::$safeIdentifiers[$name]) || DB::assertIdentifier($name, \'...\')');
    }

    //endregion
    //region isIdentifier()

    /** @dataProvider identifierProvider */
    public function testIsIdentifier(string $identifier, bool $expected): void
    {
        $this->assertSame($expected, DB::isIdentifier($identifier));
    }

    public static function identifierProvider(): array
    {
        return [
            'plain'            => ['users', true],
            'underscore'       => ['order_details', true],
            'hyphen'           => ['order-2024', true],
            'leading digit'    => ['2table', true],
            'single char'      => ['a', true],
            'empty'            => ['', false],
            'space'            => ['user table', false],
            'dot'              => ['users.name', false],
            'backtick'         => ['users`', false],
            'semicolon'        => ['users;', false],
            'trailing newline' => ["users\n", false],
            'unicode letter'   => ["caf\u{E9}", false], // no /u flag, \w stays ASCII
        ];
    }

    public function testIsIdentifierAddsValidNamesToSafeIdentifiers(): void
    {
        unset(DB::$safeIdentifiers['checked_once_column']);
        DB::isIdentifier('checked_once_column');
        $this->assertArrayHasKey('checked_once_column', DB::$safeIdentifiers);
    }

    public function testIsIdentifierDoesNotCacheInvalidNames(): void
    {
        DB::isIdentifier('bad name');
        $this->assertArrayNotHasKey('bad name', DB::$safeIdentifiers);
    }

    //endregion
}
