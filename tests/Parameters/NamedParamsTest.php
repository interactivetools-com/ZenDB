<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Parameters;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for named parameter handling (:name placeholders)
 *
 * @covers \Itools\ZenDB\ConnectionInternals::parseParams
 * @covers \Itools\ZenDB\ConnectionInternals::replacePlaceholders
 */
class NamedParamsTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Basic Named Parameters

    public function testSingleNamedParam(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE num = :num", [':num' => 1]);
        $this->assertCount(1, $result);
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testMultipleNamedParams(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users WHERE num >= :min AND num <= :max",
            [':min' => 1, ':max' => 5]
        );
        $this->assertCount(5, $result);
    }

    public function testNamedParamsWithDifferentTypes(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users WHERE status = :status AND isAdmin = :isAdmin",
            [':status' => 'Active', ':isAdmin' => 1]
        );
        $this->assertCount(8, $result);
    }

    public function testNamedParamUsedMultipleTimes(): void
    {
        // Named params can be referenced multiple times in query
        $result = DB::query(
            "SELECT * FROM ::users WHERE num = :id OR (num = :id + :offset)",
            [':id' => 1, ':offset' => 4]
        );
        $this->assertCount(2, $result); // num = 1 and num = 5
    }

    //endregion
    //region Edge Cases

    public function testNamedParamWithNull(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE isAdmin = :admin OR :admin IS NULL", [':admin' => null]);
        // When :admin is NULL, the second condition ":admin IS NULL" is true for all rows
        $this->assertCount(20, $result);
    }

    public function testNamedParamWithBoolean(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE isAdmin = :admin", [':admin' => true]);
        $this->assertCount(8, $result);
    }

    public function testNamedParamWithInteger(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE age > :age", [':age' => 40]);
        $this->assertCount(6, $result);
        foreach ($result as $row) {
            $this->assertGreaterThan(40, $row->get('age')->value());
        }
    }

    public function testNamedParamWithFloat(): void
    {
        $result = DB::query("SELECT * FROM ::orders WHERE total_amount >= :amount", [':amount' => 100.50]);
        $this->assertCount(3, $result);
    }

    public function testNamedParamWithSpecialCharsInValue(): void
    {
        $result = DB::query("SELECT * FROM ::users WHERE name = :name", [':name' => "Frank <b>Miller</b>"]);
        $this->assertCount(1, $result);
        $this->assertSame(8, $result->first()->get('num')->value());
    }

    //endregion
    //region Error Conditions

    public function testNamedParamWithoutColonInArrayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid param name 'num'");

        DB::query("SELECT * FROM ::users WHERE num = :num", ['num' => 1]);
    }

    public function testReservedZdbPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Names can't start with :zdb_ (reserved prefix)");

        DB::query("SELECT * FROM ::users WHERE num = :zdb_internal", [':zdb_internal' => 1]);
    }

    public function testDuplicateParamNameIsLastValue(): void
    {
        // In PHP, duplicate array keys just use the last value - no exception thrown
        // ZenDB doesn't detect duplicate param names in the same array (PHP behavior)
        $result = DB::query("SELECT * FROM ::users WHERE num = :num", [':num' => 1, ':num' => 2]);
        // The last value ':num' => 2 is used
        $this->assertCount(1, $result);
        $this->assertSame(2, $result->first()->get('num')->value());
    }

    public function testMissingNamedParamThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing value for ':name' parameter");

        DB::query("SELECT * FROM ::users WHERE name = :name", [':num' => 1]);
    }

    public function testInvalidParamNameCharsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid param name");

        DB::query("SELECT * FROM ::users WHERE num = :num", [':user-name' => 'test']);
    }

    public function testParamNameWithSpaceThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid param name");

        DB::query("SELECT * FROM ::users WHERE num = :num", [':my param' => 1]);
    }

    //endregion
    //region Data Provider Tests

    /**
     * @dataProvider provideNamedParamScenarios
     */
    public function testNamedParamScenarios(string $description, string $sql, array $params, int $expectedCount): void
    {
        $result = DB::query($sql, $params);
        $this->assertSame($expectedCount, $result->count(), "Failed: $description");
    }

    public static function provideNamedParamScenarios(): array
    {
        return [
            'single param' => [
                'description'   => 'Single named parameter',
                'sql'           => 'SELECT * FROM ::users WHERE num = :id',
                'params'        => [':id' => 5],
                'expectedCount' => 1,
            ],
            'multiple params' => [
                'description'   => 'Multiple named parameters',
                'sql'           => 'SELECT * FROM ::users WHERE age >= :minAge AND age <= :maxAge',
                'params'        => [':minAge' => 30, ':maxAge' => 40],
                'expectedCount' => 9, // ages 30,31,32,33,34,35,37,38,38 = 9 users
            ],
            'string param' => [
                'description'   => 'String named parameter',
                'sql'           => 'SELECT * FROM ::users WHERE city = :city',
                'params'        => [':city' => 'Toronto'],
                'expectedCount' => 2,
            ],
            'null handling' => [
                'description'   => 'NULL parameter becomes SQL NULL',
                'sql'           => 'SELECT * FROM ::users WHERE isAdmin = :admin',
                'params'        => [':admin' => null],
                'expectedCount' => 0, // NULL = NULL is false in SQL
            ],
        ];
    }

    //endregion
}
