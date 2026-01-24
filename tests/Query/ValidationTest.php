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
        $this->query = new Query(DB::$mysqli, 'test_');
    }

    public function testReservedPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query->addParamsFromArgs([[':zdb_internal' => 'value']]);
    }

    public function testReservedPrefixVariation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query->addParamsFromArgs([[':zdb_test' => 'value']]);
    }

    public function testNonReservedPrefixAllowed(): void
    {
        $this->query->addParamsFromArgs([[':myprefix' => 'value']]);
        $this->query->setSqlTemplate("SELECT :myprefix");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('"value"', $query);
    }
}
