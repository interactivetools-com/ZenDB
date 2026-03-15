<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Parameters;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for backtick identifier placeholders (`?`, `:name`, `::?`, `:::name`)
 *
 * @covers \Itools\ZenDB\ConnectionInternals::replacePlaceholders
 * @covers \Itools\ZenDB\ConnectionInternals::assertValidTable
 * @covers \Itools\ZenDB\ConnectionInternals::assertValidColumn
 */
class BacktickIdentifiersTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Table Identifiers

    public function testBacktickPositionalTableIdentifier(): void
    {
        // `?` - table name placeholder
        $result = DB::query("SELECT COUNT(*) as cnt FROM `?`", 'test_users');
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
    }

    public function testBacktickNamedTableIdentifier(): void
    {
        // `:table` - named table placeholder
        $result = DB::query("SELECT COUNT(*) as cnt FROM `:table`", [':table' => 'test_users']);
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
    }

    public function testBacktickPrefixedPositional(): void
    {
        // `::?` - table name with prefix placeholder
        $result = DB::query("SELECT COUNT(*) as cnt FROM `::?`", 'users');
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
    }

    public function testBacktickPrefixedNamed(): void
    {
        // `:::table` - named table with prefix placeholder
        $result = DB::query("SELECT COUNT(*) as cnt FROM `:::table`", [':table' => 'users']);
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
    }

    //endregion
    //region Column Identifiers

    public function testBacktickColumnIdentifier(): void
    {
        // `?` for column name
        $result = DB::query("SELECT `:col` FROM ::users WHERE num = :num", [':col' => 'name', ':num' => 1]);
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testBacktickColumnWithTableAlias(): void
    {
        $result = DB::query("SELECT u.`:col` FROM ::users u WHERE u.num = :num", [':col' => 'name', ':num' => 1]);
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    //endregion
    //region Table Prefix (::)

    public function testBarePrefixSubstitution(): void
    {
        // :: alone gets replaced with table prefix
        $result = DB::query("SELECT COUNT(*) as cnt FROM ::users");
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
    }

    public function testPrefixInFromClause(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = ?", 1);
        $this->assertCount(1, $result);
    }

    public function testPrefixInJoinClause(): void
    {
        $result = DB::query(
            "SELECT u.name, o.total_amount FROM ::users u JOIN ::orders o ON u.num = o.user_id WHERE u.num = ?",
            6
        );
        $this->assertCount(1, $result);
    }

    public function testPrefixWithUnderscoreTable(): void
    {
        // Tables starting with underscore should work: ::_special becomes test__special
        // First create the table
        DB::query("DROP TEMPORARY TABLE IF EXISTS test__special");
        DB::query("CREATE TEMPORARY TABLE test__special (id INT PRIMARY KEY)");
        DB::query("INSERT INTO test__special VALUES (?)", 1);

        $result = DB::query("SELECT * FROM ::_special");
        $this->assertCount(1, $result);
    }

    //endregion
    //region Security Tests

    public function testUnsafeTableIdentifierThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", 'users; DROP TABLE users');
    }

    public function testSqlInjectionInIdentifierBlocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", "users` WHERE 1=1; --");
    }

    public function testIdentifierWithSpacesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", "test users");
    }

    public function testIdentifierWithQuotesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", "users'");
    }

    public function testNonStringIdentifierThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", 123);
    }

    public function testNullIdentifierThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", null);
    }

    //endregion
    //region Valid Identifier Names

    public function testIdentifierWithUnderscoreAllowed(): void
    {
        $result = DB::query("SELECT * FROM `?` WHERE num = ?", ['test_users', 1]);
        $this->assertCount(1, $result);
    }

    public function testIdentifierWithNumbersAllowed(): void
    {
        // Create a table with numbers in name
        DB::query("DROP TEMPORARY TABLE IF EXISTS test_users2");
        DB::query("CREATE TEMPORARY TABLE test_users2 (id INT PRIMARY KEY)");
        DB::query("INSERT INTO test_users2 VALUES (?)", 1);

        $result = DB::query("SELECT * FROM `?`", 'test_users2');
        $this->assertCount(1, $result);
    }

    public function testIdentifierWithHyphenAllowed(): void
    {
        // Create a table with hyphen in name
        DB::query("DROP TEMPORARY TABLE IF EXISTS `test-users`");
        DB::query("CREATE TEMPORARY TABLE `test-users` (id INT PRIMARY KEY)");
        DB::query("INSERT INTO `test-users` VALUES (?)", 1);

        $result = DB::query("SELECT * FROM `?`", 'test-users');
        $this->assertCount(1, $result);
    }

    //endregion
}
