<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli_sql_exception;

/**
 * Tests for DB::query() static method
 */
class QueryTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTestTables();
    }

    public function testQuerySelect(): void
    {
        $result = DB::query("SELECT * FROM `:_users` WHERE num = ?", 1);
        $this->assertSame(1, $result->count());
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testQueryWithNamedParams(): void
    {
        $result = DB::query("SELECT * FROM `:_users` WHERE age > :age", [':age' => 40]);
        $this->assertSame(6, $result->count());
    }

    public function testQueryShowTables(): void
    {
        // the test fixtures are temporary tables, which SHOW TABLES hides on MySQL/Percona and
        // MariaDB thru 10.11 but lists on MariaDB 11.4+, so the result count is server-dependent
        // and only the return type is asserted. See docs/internal/db-behavior-matrix.md (2026-07)
        $result = DB::query("SHOW TABLES");
        $this->assertInstanceOf(\Itools\SmartArray\SmartArrayHtml::class, $result);
    }

    public function testQueryInvalidSqlThrows(): void
    {
        $this->expectException(mysqli_sql_exception::class);
        $this->expectExceptionMessage("You have an error in your SQL syntax");
        DB::query("INVALID SQL STATEMENT");
    }

    public function testQueryOneRejectsLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        DB::queryOne("SELECT * FROM ::users LIMIT 5");
    }

    /**
     * queryOne() appends LIMIT 1, so any locking clause MySQL requires *after* LIMIT
     * (FOR UPDATE, FOR SHARE, LOCK IN SHARE MODE) must be rejected up front,
     * otherwise the final SQL is a parse error.
     *
     * @dataProvider queryOneTrailingClauseProvider
     */
    public function testQueryOneRejectsTrailingClauses(string $sql, string $expectedClause): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support $expectedClause");
        DB::queryOne($sql);
    }

    public static function queryOneTrailingClauseProvider(): array
    {
        return [
            'FOR UPDATE'             => ["SELECT qty FROM ::products WHERE id = ? FOR UPDATE",                  'FOR UPDATE'],
            'FOR UPDATE NOWAIT'      => ["SELECT qty FROM ::products WHERE id = ? FOR UPDATE NOWAIT",           'FOR UPDATE'],
            'FOR UPDATE SKIP LOCKED' => ["SELECT qty FROM ::products WHERE id = ? FOR UPDATE SKIP LOCKED",      'FOR UPDATE'],
            'FOR UPDATE OF t'        => ["SELECT qty FROM ::products WHERE id = ? FOR UPDATE OF ::products",    'FOR UPDATE'],
            'FOR SHARE'              => ["SELECT qty FROM ::products WHERE id = ? FOR SHARE",                   'FOR SHARE'],
            'LOCK IN SHARE MODE'     => ["SELECT qty FROM ::products WHERE id = ? LOCK IN SHARE MODE",          'LOCK IN SHARE MODE'],
        ];
    }

    /**
     * queryOne() appends ` LIMIT 1`, so a trailing line comment would swallow it on the same line,
     * causing MySQL to silently run the full query without the LIMIT (silent full-table scan).
     *
     * @dataProvider queryOneTrailingLineCommentProvider
     */
    public function testQueryOneRejectsTrailingLineComment(string $sql): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("trailing '--' or '#' comment would swallow it");
        DB::queryOne($sql);
    }

    public static function queryOneTrailingLineCommentProvider(): array
    {
        return [
            'trailing -- comment'        => ["SELECT * FROM ::users -- debug"],
            'trailing # comment'         => ["SELECT * FROM ::users # debug"],
            'trailing -- after a clause' => ["SELECT * FROM ::users WHERE num = 1 -- TODO"],
        ];
    }

    public function testQueryOneRejectsTrailingSemicolon(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("trailing ';' would produce '; LIMIT 1'");
        DB::queryOne("SELECT * FROM ::users WHERE num = 1;");
    }

    /** @dataProvider queryOneUnsafeTemplateProvider */
    public function testQueryOneRejectsUnsafeTemplates(string $sql, string $expectedMessage): void
    {
        $queries = DB::$queryCount;
        try {
            DB::queryOne($sql);
            $this->fail('Unsafe template should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
            $this->assertSame($queries, DB::$queryCount, 'rejected before the query runs');
            $this->assertSame($sql, DB::$mysqli->lastQuery, 'lastQuery holds the template as the caller wrote it');
        }
    }

    public static function queryOneUnsafeTemplateProvider(): array
    {
        return [
            'quoted'     => ["SELECT * FROM ::users WHERE name = 'unsafe'", "Quotes not allowed in template"],
            'number'     => ['SELECT * FROM ::users WHERE num = 7', "Standalone number in template"],
            'hex'        => ['SELECT * FROM ::users WHERE num = 0x41', "Numeric literal '0x41'"],
            'scientific' => ['SELECT * FROM ::users WHERE num = 1e2', "Numeric literal '1e2'"],
            'backslash'  => ["SELECT * FROM ::users WHERE name = \\?", "Backslashes not allowed in template"],
            'null byte'  => ["SELECT * FROM ::users WHERE name = ?\x00", "NULL character not allowed in template"],
            'control z'  => ["SELECT * FROM ::users WHERE name = ?\x1a", "CTRL-Z character not allowed in template"],
        ];
    }

    public function testQueryOneAllowsEmptyLiteralsAndQuotedParams(): void
    {
        $row = DB::queryOne("SELECT ? AS value, '' AS blank", "' OR TRUE --");
        $this->assertSame("' OR TRUE --", $row->value->value());
        $this->assertSame('', $row->blank->value());
    }

}
