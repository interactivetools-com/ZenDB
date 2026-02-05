<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Parameters;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for mixed positional and named parameter handling
 *
 * @covers \Itools\ZenDB\ConnectionInternals::parseParams
 */
class MixedParamsTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    public function testMixedPositionalAndNamed(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users WHERE num >= ? AND status = :status",
            [10, ':status' => 'Active']
        );
        $this->assertCount(5, $result);
        foreach ($result as $row) {
            $this->assertGreaterThanOrEqual(10, $row->get('num')->value());
            $this->assertSame('Active', $row->get('status')->value());
        }
    }

    public function testPositionalBeforeNamed(): void
    {
        // Positional params should be counted in order, named can be anywhere
        $result = DB::query(
            "SELECT * FROM ::users WHERE num > ? AND city = :city AND age < ?",
            [5, 50, ':city' => 'Toronto']
        );
        // Query: num > 5 AND city = 'Toronto' AND age < 50
        // Toronto users: 2 (Jane, 33) - num=2 does not match num > 5
        // 16 (Nancy, 38) - num=16 > 5, city=Toronto, age=38 < 50 - matches!
        $this->assertCount(1, $result);
    }

    public function testComplexMixedQuery(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users WHERE (num BETWEEN ? AND ?) OR (status = :status AND isAdmin = :admin)",
            [1, 3, ':status' => 'Suspended', ':admin' => 0]
        );
        // (num 1-3) OR (Suspended AND isAdmin=0)
        // Num 1-3: John, Jane, Alice
        // Suspended+isAdmin=0: Bob(4), Frank(8), Jill(12), Nancy(16), Rachel(20)
        $this->assertCount(8, $result);
    }

    public function testMultiplePositionalWithNamed(): void
    {
        // Use the proper array format for mixed params (no nested array)
        $result = DB::query(
            "SELECT * FROM ::users WHERE num = ? OR num = ? OR num = ? OR city = :city",
            [1, 2, 3, ':city' => 'Edmonton']
        );
        // Users 1, 2, 3, and Edmonton (user 5)
        $this->assertCount(4, $result);
    }

    /**
     * @dataProvider provideMixedParamScenarios
     */
    public function testMixedParamScenarios(string $description, string $sql, array $params, int $expectedCount): void
    {
        $result = DB::query($sql, $params);
        $this->assertSame($expectedCount, $result->count(), "Failed: $description");
    }

    public static function provideMixedParamScenarios(): array
    {
        return [
            'positional then named' => [
                'description'   => 'Positional followed by named',
                'sql'           => 'SELECT * FROM ::users WHERE num = ? AND status = :status',
                'params'        => [1, ':status' => 'Active'],
                'expectedCount' => 1,
            ],
            'three positional one named' => [
                'description'   => 'Three positional with one named',
                'sql'           => 'SELECT * FROM ::users WHERE (num = ? OR num = ? OR num = ?) AND status = :status',
                'params'        => [1, 5, 9, ':status' => 'Active'],
                'expectedCount' => 3, // All three are Active
            ],
            'interleaved usage' => [
                'description'   => 'Named params interleaved in SQL',
                'sql'           => 'SELECT * FROM ::users WHERE status = :status AND num > ? AND city = :city',
                'params'        => [10, ':status' => 'Active', ':city' => 'Victoria'],
                'expectedCount' => 1, // User 11
            ],
        ];
    }
}
