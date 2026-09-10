<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Closure;
use InvalidArgumentException;
use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Tests for the literal stripper the RawSql WHERE checks run on. selectOne() and count()
 * refuse LIMIT, OFFSET, row locks, trailing comments, and trailing semicolons in a WHERE.
 * On a RawSql the values are inline, so those checks look at the SQL with every string
 * literal and backtick identifier blanked out. Comments stay so the trailing-comment check
 * still works.
 *
 * @covers \Itools\ZenDB\ConnectionInternals::withoutStringLiterals
 */
class WithoutStringLiteralsTest extends BaseTestCase
{
    private static Closure $strip;

    public static function setUpBeforeClass(): void
    {
        $conn = self::createDefaultConnection();
        self::resetTestTables();

        $method      = new ReflectionMethod(Connection::class, 'withoutStringLiterals');
        self::$strip = fn(string $sql): string => $method->invoke($conn, $sql);
    }

    //region Literals

    public static function literalProvider(): array
    {
        // [input, expected]
        return [
            'plain'                     => ["name = 'abc'", "name = ''"],
            'backslash-escaped quote'   => ["name = 'O\\'Brien'", "name = ''"],
            'doubled quote'             => ["name = 'O''Brien'", "name = ''"],
            'only a doubled quote'      => ["name = ''''", "name = ''"],
            'empty literal'             => ["name = ''", "name = ''"],
            'ends with escaped bslash'  => ["name = 'abc\\\\'", "name = ''"],
            'escaped bslash then quote' => ["name = 'a\\\\' AND b = 'c'", "name = '' AND b = ''"],
            'double-quoted'             => ['name = "abc"', "name = ''"],
            'single quote inside dq'    => ['name = "it\'s"', "name = ''"],
            'double quote inside sq'    => ["name = 'say \"hi\"'", "name = ''"],
            'escaped dq inside dq'      => ['name = "say \\"hi\\""', "name = ''"],
            'doubled dq inside dq'      => ['name = "say ""hi"""', "name = ''"],
            'two literals'              => ["a = 'x' AND b = 'y'", "a = '' AND b = ''"],
            'adjacent literals'         => ["a = 'x' 'y'", "a = '' ''"],
            'mixed quote styles'        => ["a = 'x' AND b = \"y\"", "a = '' AND b = ''"],
            'IN list'                   => ["a IN ('x', 'y', 'z')", "a IN ('', '', '')"],
            'multiline literal'         => ["a = 'x\ny'", "a = ''"],
            'escaped newline'           => ["a = 'x\\\ny'", "a = ''"],
            'NUL byte'                  => ["a = 'x\0y'", "a = ''"],
            'CTRL-Z byte'               => ["a = 'x\x1ay'", "a = ''"],
            'escaped NUL and CTRL-Z'    => ["a = 'x\\0y\\Zz'", "a = ''"],
            'non-UTF8 bytes'            => ["a = 'x\xff\xfey'", "a = ''"],
            'tab and CR'                => ["a = 'x\t\ry'", "a = ''"],
            'keywords inside value'     => ["a = 'LIMIT 5 OFFSET 2 FOR UPDATE -- ; #'", "a = ''"],
            'quote-only content'        => ["a = '\\'' AND b = '\"'", "a = '' AND b = ''"],
        ];
    }

    #[DataProvider('literalProvider')]
    public function testLiteralIsBlanked(string $sql, string $expected): void
    {
        $this->assertSame($expected, (self::$strip)($sql));
    }

    //endregion
    //region Unterminated Input

    public static function unterminatedProvider(): array
    {
        // MySQL reads an unterminated literal to the end of the statement; so does the stripper
        return [
            'single quote'             => ["a = 'abc", "a = ''"],
            'single quote then clause' => ["a = 'abc LIMIT 5", "a = ''"],
            'double quote'             => ['a = "abc LIMIT 5', "a = ''"],
            'escaped quote at end'     => ["a = 'abc\\'", "a = ''"],
            'doubled quote at end'     => ["a = 'abc''", "a = ''"],
            'lone quote'               => ["a = '", "a = ''"],
            'backtick'                 => ["`abc = 'x'", "``"],
            'block comment'            => ["num = 1 /* it's", "num = 1 /* it's"],
            'closed then open'         => ["a = 'x' AND b = 'y", "a = '' AND b = ''"],
        ];
    }

    #[DataProvider('unterminatedProvider')]
    public function testUnterminatedRunsToEnd(string $sql, string $expected): void
    {
        $this->assertSame($expected, (self::$strip)($sql));
    }

    //endregion
    //region Comments And Identifiers

    public static function commentProvider(): array
    {
        return [
            'dash comment kept'             => ["num = 1 -- it's", "num = 1 -- it's"],
            'hash comment kept'             => ["num = 1 # it's", "num = 1 # it's"],
            'block comment kept'            => ["num = 1 /* it's */ AND a = 'x'", "num = 1 /* it's */ AND a = ''"],
            'block comment with stars'      => ["/* a * b ** c */ a = 'x'", "/* a * b ** c */ a = ''"],
            'quote in comment, then value'  => ["/* don't */ AND name = 'no limit'", "/* don't */ AND name = ''"],
            'dash comment ends at newline'  => ["num = 1 -- 'x'\nAND a = 'y'", "num = 1 -- 'x'\nAND a = ''"],
            'hash comment ends at newline'  => ["num = 1 # 'x'\r\nAND a = 'y'", "num = 1 # 'x'\r\nAND a = ''"],
            'dashes inside literal'         => ["name = 'a -- b'", "name = ''"],
            'hash inside literal'           => ["name = '#1 fan'", "name = ''"],
            'block comment inside literal'  => ["name = 'a /* b */ c'", "name = ''"],
            'block comment holds dashes'    => ["/* -- */ a = 'x'", "/* -- */ a = ''"],
            'backtick with quote'           => ["`it's` = 'no limit'", "`` = ''"],
            'backtick doubled'              => ["`we``ird` = 'x'", "`` = ''"],
            'backtick with keyword'         => ["`limit` = 'x'", "`` = ''"],
            'quote in backtick then value'  => ["`a'b` = 'no limit' AND `c` = 'y'", "`` = '' AND `` = ''"],
        ];
    }

    #[DataProvider('commentProvider')]
    public function testCommentsKeptAndIdentifiersBlanked(string $sql, string $expected): void
    {
        $this->assertSame($expected, (self::$strip)($sql));
    }

    //endregion
    //region Surrounding SQL

    public function testSqlOutsideLiteralsIsUntouched(): void
    {
        $sql = "num IN (1,2,3) AND age >= 0x1F AND (a IS NULL OR b != 'x') ORDER BY num DESC LIMIT 5 OFFSET 2 FOR UPDATE;";
        $this->assertSame(str_replace("'x'", "''", $sql), (self::$strip)($sql));
    }

    public function testNoLiteralsMeansNoChange(): void
    {
        $sql = "num = 1 AND name IS NOT NULL -- done";
        $this->assertSame($sql, (self::$strip)($sql));
    }

    public function testEmptyString(): void
    {
        $this->assertSame('', (self::$strip)(''));
    }

    public function testRealClausesStayVisibleAfterLiterals(): void
    {
        $this->assertSame("name = '' LIMIT 5", (self::$strip)("name = 'no limit' LIMIT 5"));
        $this->assertSame("name = '' FOR UPDATE", (self::$strip)("name = 'no limit' FOR UPDATE"));
        $this->assertSame("name = '' -- done", (self::$strip)("name = 'a -- b' -- done"));
        $this->assertSame("name = '';", (self::$strip)("name = 'a;';"));
    }

    public function testIdempotent(): void
    {
        foreach (array_merge(self::literalProvider(), self::unterminatedProvider(), self::commentProvider()) as [$sql,]) {
            $once = (self::$strip)($sql);
            $this->assertSame($once, (self::$strip)($once), $sql);
        }
    }

    //endregion
    //region Size And Limits

    public static function bigInputProvider(): array
    {
        return [
            '10MB literal'                        => [fn() => "'" . str_repeat('a', 10_000_000) . "'", "''"],
            // escapes and doubled quotes are one loop step each; 100k stays under pcre.backtrack_limit even without JIT
            '100k doubled quotes'                 => [fn() => "'" . str_repeat("''", 100_000) . "'", "''"],
            '100k escaped quotes'                 => [fn() => "'" . str_repeat("\\'", 100_000) . "'", "''"],
            '200k escaped quotes, unterminated'   => [fn() => "'" . str_repeat("\\'", 200_000), "''"],
            '100k lone quotes'                    => [fn() => str_repeat("'", 100_001), "''"],
            '500k quote-space pairs'              => [fn() => str_repeat("' ", 500_000), str_repeat("'' ", 250_000)],
            '50k small literals'                  => [fn() => str_repeat("'a',", 50_000), str_repeat("'',", 50_000)],
            '1MB block comment'                   => [fn() => '/*' . str_repeat('*', 1_000_000) . '*/', '/*' . str_repeat('*', 1_000_000) . '*/'],
        ];
    }

    #[DataProvider('bigInputProvider')]
    public function testBigInputIsFastAndCorrect(Closure $build, string $expected): void
    {
        $sql   = $build();
        $start = microtime(true);
        $out   = (self::$strip)($sql);
        $this->assertLessThan(1.0, microtime(true) - $start, 'took over a second');
        $this->assertSame($expected, $out, 'preg_last_error: ' . preg_last_error_msg());
    }

    public function testFallsBackToRawSqlWhenPcreGivesUp(): void
    {
        self::requiresLiveMysql();

        // A child process is the only safe way to run with JIT off: PHP caches compiled patterns
        // with the JIT state they were compiled under, so toggling pcre.jit here would leak into
        // every later test in this process.
        [$stdout, $stderr, $exitCode] = $this->runCommand([
            PHP_BINARY, '-d', 'pcre.jit=0',
            dirname(__DIR__) . '/Support/bin/strip-literals-no-jit.php',
            self::$configDefaults['hostname'],
            self::$configDefaults['username'],
            self::$configDefaults['password'],
            self::$configDefaults['database'],
        ]);

        $this->assertSame(0, $exitCode, "child exited with $exitCode: $stderr$stdout");
        [$inputLength, $outputLength, $pcreError] = explode("\n", trim($stdout));
        $this->assertSame('Backtrack limit exhausted', $pcreError, 'the child was meant to make PCRE give up');
        $this->assertSame($inputLength, $outputLength, 'when PCRE gives up the raw SQL comes back unchanged');
    }

    //endregion
    //region Through selectOne And count

    public function testKeywordsInValuesPassOnceCommentsAndIdentifiersAreHandled(): void
    {
        $wheres = [
            "/* don't */ name = 'no limit'",
            "`name` = 'no limit' -- match\n AND num > 0",
            "name = 'a -- b' AND num > 0",
            "name = '#1 fan' AND num > 0",
            "name = 'FOR UPDATE' AND num > 0",
            "name = 'x;' AND num > 0",
        ];
        foreach ($wheres as $where) {
            $this->assertSame(0, DB::count('users', DB::rawSql($where)), $where);
            $this->assertTrue(DB::selectOne('users', DB::rawSql($where))->isEmpty(), $where);
        }
    }

    public function testRealTrailingCommentStillRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("trailing '--' or '#' comment");
        DB::selectOne('users', DB::rawSql("name = 'a -- b' -- real comment"));
    }

    public function testRealLimitAfterKeywordValueStillRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support LIMIT or OFFSET");
        DB::count('users', DB::rawSql("name = 'no limit' LIMIT 5"));
    }

    public function testRealRowLockAfterQuoteInCommentStillRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support FOR UPDATE");
        DB::selectOne('users', DB::rawSql("/* it's */ num = 1 FOR UPDATE"));
    }

    //endregion
}
