<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Query;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Query;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for Query :_ and :: table prefix placeholders
 */
class TablePrefixTest extends BaseTestCase
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

    public function testTablePrefixPlaceholder(): void
    {
        $this->query->addParamsFromArgs([]);
        $this->query->setSqlTemplate("SELECT * FROM `:_users`");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('`test_users`', $query);
    }

    public function testTablePrefixWithMultipleTables(): void
    {
        $this->query->addParamsFromArgs([]);
        $this->query->setSqlTemplate("SELECT * FROM `:_users` JOIN `:_orders` ON `:_users`.id = `:_orders`.user_id");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('`test_users`', $query);
        $this->assertStringContainsString('`test_orders`', $query);
    }

    public function testDoubleColonPrefixPlaceholder(): void
    {
        $this->query->addParamsFromArgs(['tablename']);
        $this->query->setSqlTemplate("SELECT * FROM `::?`");
        $query = $this->query->getEscapedQuery();

        $this->assertStringContainsString('`test_tablename`', $query);
    }

    public function testEmptyTablePrefix(): void
    {
        $parserNoPrefix = new Query(DB::$mysqli, '');
        $parserNoPrefix->addParamsFromArgs([]);
        $parserNoPrefix->setSqlTemplate("SELECT * FROM `:_users`");
        $query = $parserNoPrefix->getEscapedQuery();

        $this->assertStringContainsString('`users`', $query);
    }
}
