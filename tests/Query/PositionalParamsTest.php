<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Query;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Query;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;

/**
 * Tests for Query ? positional parameter handling
 */
class PositionalParamsTest extends BaseTestCase
{
    private Query $query;

    public static function setUpBeforeClass(): void
    {
        new Connection(self::$configDefaults, default: true);
    }

    protected function setUp(): void
    {
        $this->query = new Query(DB::$mysqli, 'test_');
    }

    public function testPositionalParamsAreEscaped(): void
    {
        $this->query->addParamsFromArgs(['value1', 'value2']);
        $this->query->setSqlTemplate("SELECT * FROM users WHERE col1 = ? AND col2 = ?");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('"value1"', $query);
        $this->assertStringContainsString('"value2"', $query);
    }

    public function testPositionalParamWithNull(): void
    {
        $this->query->addParamsFromArgs([null]);
        $this->query->setSqlTemplate("SELECT * FROM users WHERE col = ?");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('NULL', $query);
    }

    public function testPositionalParamWithBoolean(): void
    {
        $this->query->addParamsFromArgs([true, false]);
        $this->query->setSqlTemplate("SELECT * FROM users WHERE active = ? AND deleted = ?");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('TRUE', $query);
        $this->assertStringContainsString('FALSE', $query);
    }

    public function testPositionalParamWithInteger(): void
    {
        $this->query->addParamsFromArgs([42]);
        $this->query->setSqlTemplate("SELECT * FROM users WHERE age = ?");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('42', $query);
    }

    public function testPositionalParamWithFloat(): void
    {
        $this->query->addParamsFromArgs([3.14]);
        $this->query->setSqlTemplate("SELECT * FROM users WHERE score = ?");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('3.14', $query);
    }

    public function testMaxThreePositionalArgsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query->addParamsFromArgs(['a', 'b', 'c', 'd']);
    }

    public function testThreePositionalArgsAllowed(): void
    {
        $this->query->addParamsFromArgs(['a', 'b', 'c']);
        $this->query->setSqlTemplate("SELECT * FROM t WHERE a = ? AND b = ? AND c = ?");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('"a"', $query);
        $this->assertStringContainsString('"b"', $query);
        $this->assertStringContainsString('"c"', $query);
    }
}
