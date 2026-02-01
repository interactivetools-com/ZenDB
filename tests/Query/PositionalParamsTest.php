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
        $this->query              = new Query();
        $this->query->mysqli      = DB::$mysqli;
        $this->query->tablePrefix = 'test_';
        $this->query->params      = new \Itools\ZenDB\Params();
    }

    public function testPositionalParamsAreEscaped(): void
    {
        $this->query->params->addFromArgs(['value1', 'value2']);
        $sql = $this->query->getSql("SELECT * FROM users WHERE col1 = ? AND col2 = ?");

        $this->assertStringContainsString('"value1"', $sql);
        $this->assertStringContainsString('"value2"', $sql);
    }

    public function testPositionalParamWithNull(): void
    {
        $this->query->params->addFromArgs([null]);
        $sql = $this->query->getSql("SELECT * FROM users WHERE col = ?");

        $this->assertStringContainsString('NULL', $sql);
    }

    public function testPositionalParamWithBoolean(): void
    {
        $this->query->params->addFromArgs([true, false]);
        $sql = $this->query->getSql("SELECT * FROM users WHERE active = ? AND deleted = ?");

        $this->assertStringContainsString('TRUE', $sql);
        $this->assertStringContainsString('FALSE', $sql);
    }

    public function testPositionalParamWithInteger(): void
    {
        $this->query->params->addFromArgs([42]);
        $sql = $this->query->getSql("SELECT * FROM users WHERE age = ?");

        $this->assertStringContainsString('42', $sql);
    }

    public function testPositionalParamWithFloat(): void
    {
        $this->query->params->addFromArgs([3.14]);
        $sql = $this->query->getSql("SELECT * FROM users WHERE score = ?");

        $this->assertStringContainsString('3.14', $sql);
    }

    public function testMaxThreePositionalArgsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query->params->addFromArgs(['a', 'b', 'c', 'd']);
    }

    public function testThreePositionalArgsAllowed(): void
    {
        $this->query->params->addFromArgs(['a', 'b', 'c']);
        $sql = $this->query->getSql("SELECT * FROM t WHERE a = ? AND b = ? AND c = ?");

        $this->assertStringContainsString('"a"', $sql);
        $this->assertStringContainsString('"b"', $sql);
        $this->assertStringContainsString('"c"', $sql);
    }
}
