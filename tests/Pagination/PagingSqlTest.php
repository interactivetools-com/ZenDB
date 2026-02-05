<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Pagination;

use Itools\ZenDB\DB;
use Itools\ZenDB\RawSql;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::pagingSql() method
 *
 * @covers \Itools\ZenDB\DB::pagingSql
 */
class PagingSqlTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Basic Pagination

    public function testPageOneOffset(): void
    {
        $sql = DB::pagingSql(1, 10);
        $this->assertSame('LIMIT 10 OFFSET 0', (string) $sql);
    }

    public function testPageTwoOffset(): void
    {
        $sql = DB::pagingSql(2, 10);
        $this->assertSame('LIMIT 10 OFFSET 10', (string) $sql);
    }

    public function testPageThreeOffset(): void
    {
        $sql = DB::pagingSql(3, 10);
        $this->assertSame('LIMIT 10 OFFSET 20', (string) $sql);
    }

    public function testCustomPerPage(): void
    {
        $sql = DB::pagingSql(1, 25);
        $this->assertSame('LIMIT 25 OFFSET 0', (string) $sql);

        $sql = DB::pagingSql(2, 25);
        $this->assertSame('LIMIT 25 OFFSET 25', (string) $sql);
    }

    //endregion
    //region Edge Cases

    public function testZeroPageDefaultsToOne(): void
    {
        $sql = DB::pagingSql(0, 10);
        // 0 becomes 1 via abs() + ||1
        $this->assertSame('LIMIT 10 OFFSET 0', (string) $sql);
    }

    public function testNegativePageUsesAbsolute(): void
    {
        $sql = DB::pagingSql(-3, 10);
        // abs(-3) = 3
        $this->assertSame('LIMIT 10 OFFSET 20', (string) $sql);
    }

    public function testZeroPerPageDefaultsToTen(): void
    {
        $sql = DB::pagingSql(1, 0);
        // 0 becomes 10 via abs() + ||10
        $this->assertSame('LIMIT 10 OFFSET 0', (string) $sql);
    }

    public function testNegativePerPageUsesAbsolute(): void
    {
        $sql = DB::pagingSql(1, -25);
        // abs(-25) = 25
        $this->assertSame('LIMIT 25 OFFSET 0', (string) $sql);
    }

    public function testDefaultPerPage(): void
    {
        $sql = DB::pagingSql(1);
        $this->assertSame('LIMIT 10 OFFSET 0', (string) $sql);
    }

    //endregion
    //region Type Handling

    public function testStringInputsCastToInt(): void
    {
        $sql = DB::pagingSql('2', '15');
        $this->assertSame('LIMIT 15 OFFSET 15', (string) $sql);
    }

    public function testFloatInputsCastToInt(): void
    {
        $sql = DB::pagingSql(2.7, 10.9);
        // Cast to int: 2, 10
        $this->assertSame('LIMIT 10 OFFSET 10', (string) $sql);
    }

    public function testNumericStringInputs(): void
    {
        $sql = DB::pagingSql('3', '20');
        $this->assertSame('LIMIT 20 OFFSET 40', (string) $sql);
    }

    //endregion
    //region Return Type

    public function testReturnsRawSql(): void
    {
        $sql = DB::pagingSql(1, 10);
        $this->assertInstanceOf(RawSql::class, $sql);
    }

    //endregion
    //region Integration Tests

    public function testPagingSqlInRealQuery(): void
    {
        // Use placeholder to pass pagingSql result
        $page1 = DB::query("SELECT * FROM ::users ORDER BY num ?", DB::pagingSql(1, 5));
        $this->assertCount(5, $page1);
        $this->assertSame(1, $page1->first()->get('num')->value());

        $page2 = DB::query("SELECT * FROM ::users ORDER BY num ?", DB::pagingSql(2, 5));
        $this->assertCount(5, $page2);
        $this->assertSame(6, $page2->first()->get('num')->value());
    }

    public function testPagingSqlWithSelectMethod(): void
    {
        // Use placeholder to pass pagingSql result
        $page1 = DB::select('users', "ORDER BY num ?", DB::pagingSql(1, 5));
        $this->assertCount(5, $page1);

        $page2 = DB::select('users', "ORDER BY num ?", DB::pagingSql(2, 5));
        $this->assertCount(5, $page2);

        // Verify exact first user on each page
        $this->assertSame(1, $page1->first()->get('num')->value());
        $this->assertSame(6, $page2->first()->get('num')->value());
    }

    public function testPagingSqlLastPage(): void
    {
        // Total 20 users, 5 per page = 4 pages
        $page4 = DB::select('users', "ORDER BY num ?", DB::pagingSql(4, 5));
        $this->assertCount(5, $page4);
        $this->assertSame(16, $page4->first()->get('num')->value());

        // Page 5 should have 0 results (beyond data)
        $page5 = DB::select('users', "ORDER BY num ?", DB::pagingSql(5, 5));
        $this->assertCount(0, $page5);
    }

    public function testPagingSqlWithCondition(): void
    {
        // Paginate only Active users
        $activeCount = DB::count('users', ['status' => 'Active']);

        // Use named placeholder for status and positional for pagingSql
        $page1 = DB::select('users', "status = :status ORDER BY num ?", [':status' => 'Active', DB::pagingSql(1, 5)]);
        $this->assertCount(5, $page1);

        foreach ($page1 as $row) {
            $this->assertSame('Active', $row->get('status')->value());
        }
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider providePagingScenarios
     */
    public function testPagingScenarios(mixed $page, mixed $perPage, string $expected): void
    {
        $sql = DB::pagingSql($page, $perPage);
        $this->assertSame($expected, (string) $sql);
    }

    public static function providePagingScenarios(): array
    {
        return [
            'page 1, 10 per page'  => [1, 10, 'LIMIT 10 OFFSET 0'],
            'page 2, 10 per page'  => [2, 10, 'LIMIT 10 OFFSET 10'],
            'page 5, 20 per page'  => [5, 20, 'LIMIT 20 OFFSET 80'],
            'page 1, 1 per page'   => [1, 1, 'LIMIT 1 OFFSET 0'],
            'page 100, 10 per page'=> [100, 10, 'LIMIT 10 OFFSET 990'],
            'zero page'            => [0, 10, 'LIMIT 10 OFFSET 0'],
            'zero per page'        => [1, 0, 'LIMIT 10 OFFSET 0'],
            'both zero'            => [0, 0, 'LIMIT 10 OFFSET 0'],
            'negative page'        => [-2, 10, 'LIMIT 10 OFFSET 10'],
            'negative per page'    => [1, -5, 'LIMIT 5 OFFSET 0'],
            'string inputs'        => ['3', '15', 'LIMIT 15 OFFSET 30'],
        ];
    }

    //endregion
}
