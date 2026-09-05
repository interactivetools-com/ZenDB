<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\ValueTypes;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DB::floatToSql(), the one place ZenDB turns a PHP float into a SQL literal
 *
 * @covers \Itools\ZenDB\DB::floatToSql
 */
class FloatToSqlTest extends TestCase
{
    /**
     * @dataProvider finiteFloatProvider
     */
    public function testFiniteFloatsUseShortestRoundTripSpelling(float $value, string $expected): void
    {
        $this->assertSame($expected, DB::floatToSql($value));
    }

    public static function finiteFloatProvider(): array
    {
        return [
            'simple decimal'          => [0.1, '0.1'],
            'many digits'             => [1234567890.1234567, '1234567890.1234567'],
            'binary rounding error'   => [0.3 - 0.1, '0.19999999999999998'],
            'large exponent'          => [1.0E+20, '1.0E+20'],
            'small negative exponent' => [-2.5E-7, '-2.5E-7'],
            'whole number keeps .0'   => [3.0, '3.0'],
            'negative zero'           => [-0.0, '-0.0'],
        ];
    }

    public function testNanThrowsWithContext(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("can't escape backup value");
        DB::floatToSql(NAN, 'backup value');
    }

    public function testInfThrowsWithContext(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("can't escape backup value");
        DB::floatToSql(INF, 'backup value');
    }

    public function testNegativeInfThrowsWithDefaultContext(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("can't escape value");
        DB::floatToSql(-INF);
    }
}
