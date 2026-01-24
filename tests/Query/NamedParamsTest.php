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
        $this->query = new Query(DB::$mysqli, 'test_');
    }

    public function testNamedParamsAreEscaped(): void
    {
        $this->query->addParamsFromArgs([[':name' => 'John', ':age' => 30]]);
        $this->query->setSqlTemplate("SELECT * FROM users WHERE name = :name AND age = :age");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('"John"', $query);
        $this->assertStringContainsString('30', $query);
    }

    public function testNamedParamWithSpecialCharacters(): void
    {
        $this->query->addParamsFromArgs([[':name' => "O'Brien"]]);
        $this->query->setSqlTemplate("SELECT * FROM users WHERE name = :name");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString("O\\'Brien", $query);
    }

    public function testNamedParamWithNull(): void
    {
        $this->query->addParamsFromArgs([[':value' => null]]);
        $this->query->setSqlTemplate("SELECT * FROM users WHERE col = :value");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('NULL', $query);
    }

    public function testMultipleNamedParams(): void
    {
        $this->query->addParamsFromArgs([[':a' => 'one', ':b' => 'two', ':c' => 'three']]);
        $this->query->setSqlTemplate("SELECT * FROM t WHERE a = :a AND b = :b AND c = :c");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('"one"', $query);
        $this->assertStringContainsString('"two"', $query);
        $this->assertStringContainsString('"three"', $query);
    }

    public function testRawSqlNamedParamNotEscaped(): void
    {
        $this->query->addParamsFromArgs([[':raw' => DB::rawSql('NOW()')]]);
        $this->query->setSqlTemplate("SELECT :raw");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('NOW()', $query);
        $this->assertStringNotContainsString('"NOW()"', $query);
    }
}
