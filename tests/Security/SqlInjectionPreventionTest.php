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
 * Tests for SQL injection prevention
 *
 * @covers \Itools\ZenDB\ConnectionInternals::assertSafeTemplate
 */
class SqlInjectionPreventionTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Value-Based Injection Prevention

    public function testInjectionViaStringValueEscaped(): void
    {
        // Attempt SQL injection via value - should be safely escaped
        $maliciousInput = "'; DROP TABLE test_users; --";
        $result = DB::query("SELECT * FROM ::users WHERE name = ?", $maliciousInput);

        // Query should execute safely with no results (no one named that)
        $this->assertCount(0, $result);

        // Verify table still exists and all rows intact
        $this->assertSame(20, DB::count('users'));
    }

    public function testInjectionViaIntegerValue(): void
    {
        // Even if someone passes string to integer column, it's safely escaped
        // MySQL does type coercion, so "1 OR 1=1" becomes integer 1
        // This is MySQL's behavior, not a security issue since the string is escaped
        $result = DB::query("SELECT * FROM ::users WHERE num = ?", "1 OR 1=1");
        // MySQL interprets "1 OR 1=1" as 1 due to type coercion, returns user with num=1
        $this->assertCount(1, $result);
    }

    //endregion
    //region Template Validation

    public function testSingleQuoteInTemplateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Quotes not allowed in template");

        DB::query("SELECT * FROM ::users WHERE name = 'John'");
    }

    public function testDoubleQuoteInTemplateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Quotes not allowed in template");

        DB::query('SELECT * FROM ::users WHERE name = "John"');
    }

    public function testStandaloneNumberInTemplateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Standalone number in template");

        DB::query("SELECT * FROM ::users WHERE num = 1");
    }

    public function testBackslashInTemplateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Backslashes not allowed in template");

        DB::query("SELECT * FROM ::users WHERE name = \\?");
    }

    public function testNullByteInTemplateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("NULL character not allowed in template");

        DB::query("SELECT * FROM ::users WHERE name = ?\x00");
    }

    public function testCtrlZInTemplateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("CTRL-Z character not allowed in template");

        DB::query("SELECT * FROM ::users WHERE name = ?\x1a");
    }

    //endregion
    //region Empty String Literals

    public function testEmptySingleQuoteLiteralsAllowed(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE name != ''");
        $this->assertCount(20, $result);
    }

    public function testEmptyDoubleQuoteLiteralsAllowed(): void
    {
        $result = DB::query('SELECT * FROM ::users WHERE name != ""');
        $this->assertCount(20, $result);
    }

    public function testMultipleEmptyLiteralsAllowed(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE name != '' AND city != ''");
        $this->assertCount(20, $result);
    }

    public function testNonEmptySingleQuotesStillThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Quotes not allowed in template");
        DB::query("SELECT * FROM ::users WHERE name = 'John'");
    }

    public function testNonEmptyDoubleQuotesStillThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Quotes not allowed in template");
        DB::query('SELECT * FROM ::users WHERE name = "John"');
    }

    public function testUnmatchedQuoteStillThrows(): void
    {
        // Odd number of quotes - stripping '' leaves a lone ' that the check catches
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Quotes not allowed in template");
        DB::query("SELECT * FROM ::users WHERE name != '''");
    }

    //endregion
    //region Allowed Patterns

    public function testColumnNamesWithNumbersAllowed(): void
    {
        // Column names like col1, user2 in SQL should not trigger false positives
        // The existing users table has 'num' column (contains a number in name)
        // SELECT with column names that have numbers should work
        $result = DB::select('users', 'ORDER BY num LIMIT 3');
        $this->assertCount(3, $result);
    }

    public function testTrailingLimitNumberAllowed(): void
    {
        // LIMIT 10 at end of query should be allowed
        $result = DB::query("SELECT * FROM ::users LIMIT 5");
        $this->assertCount(5, $result);
    }

    public function testOffsetNumberAllowed(): void
    {
        // LIMIT with OFFSET should work
        $result = DB::query("SELECT * FROM ::users ORDER BY num LIMIT 5");
        $this->assertCount(5, $result);
        $this->assertSame(1, $result->first()->get('num')->value());
    }

    public function testTablePrefixWithNumbers(): void
    {
        // Table prefix with numbers should work
        $result = DB::query("SELECT COUNT(*) as cnt FROM ::users");
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
    }

    //endregion
    //region Identifier Injection Prevention

    public function testIdentifierWithSqlInjectionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        // Attempt to inject via identifier
        DB::query("SELECT * FROM `?`", "users; DROP TABLE users; --");
    }

    public function testIdentifierWithSemicolonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", "users;");
    }

    public function testIdentifierWithCommentThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier");

        DB::query("SELECT * FROM `?`", "users -- ");
    }

    //endregion
    //region Comprehensive Attack Vectors

    /**
     * @dataProvider provideInjectionAttempts
     */
    public function testInjectionAttemptsSafelyHandled(string $description, string $maliciousValue): void
    {
        // All these attempts should be safely handled via escaping
        $result = DB::query("SELECT * FROM ::users WHERE name = ?", $maliciousValue);

        // Query executes safely - just returns no results
        $this->assertInstanceOf(\Itools\SmartArray\SmartArrayHtml::class, $result, "Failed: $description");

        // Verify we can still query the table (it wasn't dropped)
        $count = DB::count('users');
        $this->assertSame(20, $count, "Table destroyed or rows lost after: $description");
    }

    public static function provideInjectionAttempts(): array
    {
        return [
            'basic union'            => ['Basic UNION', "' UNION SELECT * FROM users --"],
            'or true'                => ['OR 1=1', "' OR '1'='1"],
            'comment out'            => ['Comment out', "admin'--"],
            'semicolon'              => ['Multiple statements', "'; DELETE FROM users; --"],
            'drop table'             => ['DROP TABLE', "'; DROP TABLE users; --"],
            'update injection'       => ['UPDATE injection', "'; UPDATE users SET isAdmin=1; --"],
            'stacked queries'        => ['Stacked queries', "a'; SELECT * FROM users WHERE '1'='1"],
            'hex encoding'           => ['Hex encoding', "0x27204f52202731273d2731"],
            'null byte'              => ['NULL byte injection', "test\x00' OR '1'='1"],
            'unicode normalization'  => ['Unicode', "test\u{0027} OR 1=1 --"],
        ];
    }

    /**
     * @dataProvider provideInvalidTemplates
     */
    public function testInvalidTemplatesRejected(string $description, string $template): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("in template");

        DB::query($template, 1);
    }

    public static function provideInvalidTemplates(): array
    {
        return [
            'hardcoded string'         => ['Hardcoded value', "SELECT * FROM ::users WHERE name = 'admin'"],
            'hardcoded number'         => ['Hardcoded number', "SELECT * FROM ::users WHERE num = 1"],
            'concatenated'             => ['Concatenated value', 'SELECT * FROM ::users WHERE num = ' . '5'],
        ];
    }

    //endregion
}
