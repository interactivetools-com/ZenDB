<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\RawSql;

use Itools\ZenDB\DB;
use Itools\ZenDB\RawSql;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for RawSql value object: factory methods, toString
 */
class RawSqlTest extends BaseTestCase
{
    public function testRawSqlToString(): void
    {
        $raw = new RawSql('NOW()');
        $this->assertSame('NOW()', (string) $raw);
    }

    public function testDbRawSqlFactory(): void
    {
        $raw = DB::rawSql('CURRENT_TIMESTAMP');
        $this->assertInstanceOf(RawSql::class, $raw);
        $this->assertSame('CURRENT_TIMESTAMP', (string) $raw);
    }

    public function testIsRawSql(): void
    {
        $raw = DB::rawSql('NOW()');
        $this->assertTrue(DB::isRawSql($raw));
        $this->assertFalse(DB::isRawSql('NOW()'));
        $this->assertFalse(DB::isRawSql(null));
    }

    public function testRawSqlWithNumericValue(): void
    {
        $raw = DB::rawSql(123);
        $this->assertSame('123', (string) $raw);
    }

    public function testRawSqlWithFloatValue(): void
    {
        $raw = DB::rawSql(123.45);
        $this->assertSame('123.45', (string) $raw);
    }

    public function testRawSqlWithSqlFunction(): void
    {
        $raw = DB::rawSql('DATE_ADD(NOW(), INTERVAL 1 DAY)');
        $this->assertSame('DATE_ADD(NOW(), INTERVAL 1 DAY)', (string) $raw);
    }

    public function testRawSqlWithExpression(): void
    {
        $raw = DB::rawSql('column + 1');
        $this->assertSame('column + 1', (string) $raw);
    }

    public function testRawSqlWithNullProducesNull(): void
    {
        $raw = DB::rawSql(null);
        $this->assertSame('NULL', (string) $raw, "rawSql(null) should produce 'NULL', not an empty string");
    }
}
