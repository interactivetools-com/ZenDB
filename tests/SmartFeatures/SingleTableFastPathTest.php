<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\SmartFeatures;

use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use ReflectionClass;

/**
 * Tests for the query() single-table fast path: field metadata is skipped when the
 * template (and any RawSql params) can only produce columns from one table.
 *
 * @covers \Itools\ZenDB\ConnectionInternals::isSingleTableQuery
 * @covers \Itools\ZenDB\ConnectionInternals::fetchMappedRows
 */
class SingleTableFastPathTest extends BaseTestCase
{
    private static Connection $connection;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::createDefaultConnection();
        self::resetTestTables();
    }

    //region Gate: isSingleTableQuery

    /**
     * @dataProvider provideGateVerdicts
     */
    public function testGateVerdict(string $description, string $template, array $paramValues, bool $expected): void
    {
        $ref    = new ReflectionClass(Connection::class);
        $method = $ref->getMethod('isSingleTableQuery');
        $ref->getProperty('paramValues')->setValue(self::$connection, $paramValues);

        $this->assertSame($expected, $method->invoke(self::$connection, $template), "Failed: $description");
    }

    public static function provideGateVerdicts(): array
    {
        return [
            'simple single table'      => ['Plain single-table query', 'SELECT * FROM users WHERE num = ?', [':1' => 5], true],
            'comma in SELECT list'     => ['Commas before FROM are column lists, not joins', 'SELECT num, name FROM users WHERE num = ?', [':1' => 5], true],
            'no FROM at all'           => ['Expression-only query', 'SELECT DATABASE() AS db', [], true],
            'join in template'         => ['JOIN needs SmartJoins metadata', 'SELECT * FROM users u JOIN orders o ON u.num = o.user_id', [], false],
            'union in template'        => ['UNION columns are never table-attributed (behavior matrix), so no metadata needed', 'SELECT num FROM users UNION SELECT num FROM orders', [], true],
            'comma join'               => ['Comma after FROM could be a comma-join', 'SELECT * FROM users, orders WHERE users.num = orders.user_id', [], false],
            'multi-column ORDER BY'    => ['Conservative: any comma after FROM says no', 'SELECT * FROM users ORDER BY name, num', [], false],
            'join substring in name'   => ['Conservative: JOIN as substring says no', 'SELECT num, title AS joined_at FROM users', [], false],
            'rawsql paging'            => ['pagingSql LIMIT/OFFSET fragment is safe', 'SELECT * FROM users ORDER BY num :paging', [':paging' => DB::rawSql('LIMIT 3 OFFSET 1')], true],
            'rawsql NOW()'             => ['Function-call fragment is safe', 'SELECT * FROM users WHERE created < :cutoff', [':cutoff' => DB::rawSql('NOW()')], true],
            'rawsql join'              => ['JOIN entering via RawSql param', 'SELECT * FROM users u :j', [':j' => DB::rawSql('JOIN orders o ON u.num = o.user_id')], false],
            'rawsql comma tables'      => ['Comma-join entering via RawSql param', 'SELECT * FROM :tbls', [':tbls' => DB::rawSql('users, orders')], false],
            'rawsql subquery FROM'     => ['FROM inside a RawSql fragment says no', 'SELECT * FROM users WHERE num = :max', [':max' => DB::rawSql('(SELECT MAX(user_id) FROM orders)')], false],
            'rawsql join in IN-list'   => ['RawSql inside an IN-list array is checked too', 'SELECT * FROM users WHERE num IN (:ids)', [':ids' => [1, DB::rawSql('(SELECT MAX(user_id) FROM orders)')]], false],
        ];
    }

    //endregion
    //region End-to-End: fast path and fallbacks return identical results

    public function testRawSqlJoinParamKeepsSmartJoins(): void
    {
        // The gate must catch a JOIN that enters via RawSql AFTER the template sniff:
        // if it doesn't, these qualified keys silently disappear
        $row = DB::query(
            "SELECT u.num, o.total_amount FROM ::users u :j WHERE u.num = :num",
            [':j' => DB::rawSql('JOIN test_orders o ON u.num = o.user_id'), ':num' => 6]
        )->first();

        $this->assertSame(6, $row->get('users.num')->value());
        $this->assertSame('80.00', $row->get('orders.total_amount')->value());
    }

    public function testRawSqlPagingParamReturnsCorrectRows(): void
    {
        $result = DB::query(
            "SELECT num FROM ::users ORDER BY num :paging",
            [':paging' => DB::rawSql('LIMIT 3 OFFSET 1')]
        );

        $this->assertSame([2, 3, 4], $result->pluck('num')->toArray());
    }

    public function testDuplicateColumnsFallBackToFirstWins(): void
    {
        // 'SELECT num, name AS num' passes the gate (comma is before FROM), so the fast
        // path must detect the collapsed duplicate structurally and refetch: first wins
        $row = DB::query("SELECT num, name AS num FROM ::users WHERE num = ?", 1)->first();

        $this->assertSame(1, $row->get('num')->value());
    }

    public function testEmptyResultReturnsEmptySmartArray(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = ?", -1);

        $this->assertCount(0, $result);
    }

    public function testUnionReturnsMergedRows(): void
    {
        // UNIONs take the fast path (no server table-attributes union columns);
        // rows from both arms must come through with the first arm's column names
        $result = DB::query(
            "SELECT num FROM ::users WHERE num = :a UNION SELECT user_id FROM ::orders WHERE user_id = :b ORDER BY num",
            [':a' => 1, ':b' => 6]
        );

        $this->assertSame([1, 6], $result->pluck('num')->toArray());
    }

    public function testUnionWithDuplicateColumnsFallsBack(): void
    {
        // Duplicate columns in the first arm collapse on assoc fetch, which the
        // structural check catches: refetch through the metadata path, first wins.
        // Second arm reads a different table: TEMPORARY tables (the CI test tables)
        // can't be opened twice in one query
        $row = DB::query(
            "SELECT num, name AS num FROM ::users WHERE num = :a UNION SELECT user_id, order_id FROM ::orders WHERE user_id = :b",
            [':a' => 1, ':b' => -1]
        )->first();

        $this->assertSame(1, $row->get('num')->value());
    }

    //endregion
}
