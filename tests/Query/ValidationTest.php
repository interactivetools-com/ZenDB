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
 * Tests for Query validation: reserved prefixes, safety checks
 */
class ValidationTest extends BaseTestCase
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

    public function testReservedPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query->params->addFromArgs([[':zdb_internal' => 'value']]);
    }

    public function testReservedPrefixVariation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query->params->addFromArgs([[':zdb_test' => 'value']]);
    }

    public function testNonReservedPrefixAllowed(): void
    {
        $this->query->params->addFromArgs([[':myprefix' => 'value']]);
        $sql = $this->query->getSql("SELECT :myprefix");

        $this->assertStringContainsString('"value"', $sql);
    }
}
