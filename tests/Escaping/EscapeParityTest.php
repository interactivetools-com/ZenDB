<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Escaping;

use Closure;
use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;

/**
 * whereFromArray(), buildSetClause(), and the replacePlaceholders() fast arms
 * carry inlined copies of escapeValue()'s string/int output for speed. These
 * tests pin every copy byte-identical to escapeValue() so they can't drift:
 * change how one path escapes and the matching test fails.
 */
class EscapeParityTest extends BaseTestCase
{
    private static Closure $escapeValue;
    private static Closure $whereFromArray;
    private static Closure $buildSetClause;
    private static Closure $replacePlaceholders;

    public static function setUpBeforeClass(): void
    {
        $conn = self::createDefaultConnection();

        $method = function (string $name) use ($conn): Closure {
            $reflection = new ReflectionMethod(Connection::class, $name);
            return fn(...$args) => $reflection->invoke($conn, ...$args);
        };

        self::$escapeValue    = $method('escapeValue');
        self::$whereFromArray = $method('whereFromArray');
        self::$buildSetClause = $method('buildSetClause');

        self::$replacePlaceholders = function (string $tpl, array $params, int $positionalCount) use ($conn, $method) {
            (new ReflectionProperty(Connection::class, 'paramValues'))->setValue($conn, $params);
            (new ReflectionProperty(Connection::class, 'positionalParamCount'))->setValue($conn, $positionalCount);
            (new ReflectionProperty(Connection::class, 'paramsFromPositionalArray'))->setValue($conn, false);
            return $method('replacePlaceholders')($tpl);
        };
    }

    public static function valueProvider(): array
    {
        return [
            'plain string'      => ['hello'],
            'single quote'      => ["O'Brien"],
            'double quote'      => ['say "hi"'],
            'backslash'         => ['C:\\path\\file'],
            'newline + control' => ["line1\nline2\rtab\ttail"],
            'null + ctrl-z'     => ["a\0b\x1ac"],
            'utf8'              => ['café ☕'],
            'empty string'      => [''],
            'numeric string'    => ['00123'],
            'int'               => [42],
            'negative int'      => [-7],
            'zero'              => [0],
            'float'             => [3.14],
            'bool true'         => [true],
            'bool false'        => [false],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testWhereFromArrayMatchesEscapeValue(mixed $value): void
    {
        $expected = 'WHERE `col` = ' . (self::$escapeValue)($value);
        $this->assertSame($expected, (self::$whereFromArray)(['col' => $value]));
    }

    #[DataProvider('valueProvider')]
    public function testBuildSetClauseMatchesEscapeValue(mixed $value): void
    {
        $expected = 'SET `col` = ' . (self::$escapeValue)($value);
        $this->assertSame($expected, (self::$buildSetClause)(['col' => $value]));
    }

    #[DataProvider('valueProvider')]
    public function testPositionalPlaceholderMatchesEscapeValue(mixed $value): void
    {
        $expected = 'x = ' . (self::$escapeValue)($value);
        $this->assertSame($expected, (self::$replacePlaceholders)('x = ?', [':1' => $value], 1));
    }

    #[DataProvider('valueProvider')]
    public function testNamedPlaceholderMatchesEscapeValue(mixed $value): void
    {
        $expected = 'x = ' . (self::$escapeValue)($value);
        $this->assertSame($expected, (self::$replacePlaceholders)('x = :val', [':val' => $value], 0));
    }

    #[DataProvider('valueProvider')]
    public function testEscapeCsvMatchesEscapeValue(mixed $value): void
    {
        $this->assertSame((self::$escapeValue)($value), (string)DB::escapeCSV([$value]));
    }

    public function testWhereFromArrayNullBecomesIsNull(): void
    {
        $this->assertSame('WHERE `col` IS NULL', (self::$whereFromArray)(['col' => null]));
    }

    public function testBuildSetClauseNullMatchesEscapeValue(): void
    {
        $this->assertSame('SET `col` = NULL', (self::$buildSetClause)(['col' => null]));
    }
}
