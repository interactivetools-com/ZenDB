<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Query;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Query;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for Query :named parameter handling
 */
class NamedParamsTest extends BaseTestCase
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

    public function testNamedParamsAreEscaped(): void
    {
        $this->query->params->addFromArgs([[':name' => 'John', ':age' => 30]]);
        $sql = $this->query->getSql("SELECT * FROM users WHERE name = :name AND age = :age");

        $this->assertStringContainsString('"John"', $sql);
        $this->assertStringContainsString('30', $sql);
    }

    public function testNamedParamWithSpecialCharacters(): void
    {
        $this->query->params->addFromArgs([[':name' => "O'Brien"]]);
        $sql = $this->query->getSql("SELECT * FROM users WHERE name = :name");

        $this->assertStringContainsString("O\\'Brien", $sql);
    }

    public function testNamedParamWithNull(): void
    {
        $this->query->params->addFromArgs([[':value' => null]]);
        $sql = $this->query->getSql("SELECT * FROM users WHERE col = :value");

        $this->assertStringContainsString('NULL', $sql);
    }

    public function testMultipleNamedParams(): void
    {
        $this->query->params->addFromArgs([[':a' => 'one', ':b' => 'two', ':c' => 'three']]);
        $sql = $this->query->getSql("SELECT * FROM t WHERE a = :a AND b = :b AND c = :c");

        $this->assertStringContainsString('"one"', $sql);
        $this->assertStringContainsString('"two"', $sql);
        $this->assertStringContainsString('"three"', $sql);
    }

    public function testRawSqlNamedParamNotEscaped(): void
    {
        $this->query->params->addFromArgs([[':raw' => DB::rawSql('NOW()')]]);
        $sql = $this->query->getSql("SELECT :raw");

        $this->assertStringContainsString('NOW()', $sql);
        $this->assertStringNotContainsString('"NOW()"', $sql);
    }
}
