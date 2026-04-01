<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Parameters;

use InvalidArgumentException;
use Itools\SmartArray\SmartArray;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for array parameter handling (IN clauses)
 *
 * @covers \Itools\ZenDB\ConnectionInternals::getPlaceholderValue
 */
class ArrayParamsTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Array as IN Clause

    public function testArrayBecomesInClause(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids)", [':ids' => [1, 2, 3]]);
        $this->assertCount(3, $result);
    }

    public function testArrayOfIntegers(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids) ORDER BY num", [':ids' => [5, 10, 15]]);
        $this->assertCount(3, $result);
        $nums = array_column($result->toArray(), 'num');
        $this->assertSame([5, 10, 15], $nums);
    }

    public function testArrayOfStrings(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users WHERE city IN (:cities) ORDER BY num",
            [':cities' => ['Toronto', 'Vancouver']]
        );
        $this->assertCount(3, $result); // Users 1 (Vancouver), 2 (Toronto), 16 (Toronto)
    }

    public function testArrayOfMixedTypes(): void
    {
        // MySQL will handle type coercion
        $result = DB::query(
            "SELECT * FROM ::users WHERE num IN (:ids)",
            [':ids' => [1, '2', 3]]
        );
        $this->assertCount(3, $result);
    }

    public function testEmptyArrayBecomesNull(): void
    {
        // Empty array becomes NULL, which matches nothing in IN clause
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids)", [':ids' => []]);
        $this->assertCount(0, $result);
    }

    public function testArrayDeduplication(): void
    {
        // Duplicate values should be deduplicated
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids)", [':ids' => [1, 1, 2, 2, 3]]);
        $this->assertCount(3, $result);
    }

    //endregion
    //region SmartArray Conversion

    public function testSmartArrayConverted(): void
    {
        $smartArray = new SmartArray([1, 2, 3]);
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids)", [':ids' => $smartArray]);
        $this->assertCount(3, $result);
    }

    public function testSmartArrayFromQueryResult(): void
    {
        // Get some IDs from a query, then use them in another query
        $ids = DB::query("SELECT num FROM ::users WHERE status = ? LIMIT 3", 'Active')->pluckNth(0);
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids)", [':ids' => $ids]);
        $this->assertCount(3, $result);
    }

    //endregion
    //region Array with Positional Placeholders

    public function testArrayWithPositionalPlaceholderThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Arrays not allowed with positional ? placeholders");

        DB::query("SELECT * FROM ::users WHERE num IN (?)", [[1, 2, 3]]);
    }

    //endregion
    //region Array in WHERE Array Syntax

    public function testArrayInWhereArrayBecomesIn(): void
    {
        $result = DB::select('users', ['num' => [1, 2, 3]]);
        $this->assertCount(3, $result);
    }

    public function testEmptyArrayInWhereArrayBecomesInNull(): void
    {
        $result = DB::select('users', ['num' => []]);
        $this->assertCount(0, $result);
    }

    //endregion
    //region Data Provider Tests

    /**
     * @dataProvider provideArrayParamScenarios
     */
    public function testArrayParamScenarios(string $description, string $sql, array $params, int $expectedCount): void
    {
        $result = DB::query($sql, $params);
        $this->assertSame($expectedCount, $result->count(), "Failed: $description");
    }

    public static function provideArrayParamScenarios(): array
    {
        return [
            'small array' => [
                'description'   => 'Array with 3 elements',
                'sql'           => 'SELECT * FROM ::users WHERE num IN (:ids)',
                'params'        => [':ids' => [1, 2, 3]],
                'expectedCount' => 3,
            ],
            'large array' => [
                'description'   => 'Array with 10 elements',
                'sql'           => 'SELECT * FROM ::users WHERE num IN (:ids)',
                'params'        => [':ids' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
                'expectedCount' => 10,
            ],
            'single element array' => [
                'description'   => 'Array with single element',
                'sql'           => 'SELECT * FROM ::users WHERE num IN (:ids)',
                'params'        => [':ids' => [5]],
                'expectedCount' => 1,
            ],
            'string array' => [
                'description'   => 'Array of strings',
                'sql'           => 'SELECT * FROM ::users WHERE status IN (:statuses)',
                'params'        => [':statuses' => ['Active', 'Inactive']],
                'expectedCount' => 15, // 10 Active + 5 Inactive
            ],
            'negation with array' => [
                'description'   => 'NOT IN with array',
                'sql'           => 'SELECT * FROM ::users WHERE num NOT IN (:ids)',
                'params'        => [':ids' => [1, 2, 3, 4, 5]],
                'expectedCount' => 15,
            ],
        ];
    }

    //endregion
}
