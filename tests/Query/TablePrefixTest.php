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
        $this->query = $this->createQuery('test_');
    }

    private function createQuery(string $tablePrefix): Query
    {
        $query              = new Query();
        $query->mysqli      = DB::$mysqli;
        $query->tablePrefix = $tablePrefix;
        $query->params      = new \Itools\ZenDB\Params();
        return $query;
    }

    public function testTablePrefixPlaceholder(): void
    {
        $this->query->params->addFromArgs([]);
        $sql = $this->query->getSql("SELECT * FROM `:_users`");

        $this->assertStringContainsString('`test_users`', $sql);
    }

    public function testTablePrefixWithMultipleTables(): void
    {
        $this->query->params->addFromArgs([]);
        $sql = $this->query->getSql("SELECT * FROM `:_users` JOIN `:_orders` ON `:_users`.id = `:_orders`.user_id");

        $this->assertStringContainsString('`test_users`', $sql);
        $this->assertStringContainsString('`test_orders`', $sql);
    }

    public function testDoubleColonPrefixPlaceholder(): void
    {
        $this->query->params->addFromArgs(['tablename']);
        $sql = $this->query->getSql("SELECT * FROM `::?`");

        $this->assertStringContainsString('`test_tablename`', $sql);
    }

    public function testEmptyTablePrefix(): void
    {
        $queryNoPrefix = $this->createQuery('');
        $queryNoPrefix->params->addFromArgs([]);
        $sql = $queryNoPrefix->getSql("SELECT * FROM `:_users`");

        $this->assertStringContainsString('`users`', $sql);
    }
}
