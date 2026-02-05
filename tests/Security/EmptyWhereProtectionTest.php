<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Security;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for empty WHERE clause protection (prevents accidental bulk UPDATE/DELETE)
 *
 * @covers \Itools\ZenDB\ConnectionInternals::rejectEmptyWhere
 */
class EmptyWhereProtectionTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    protected function setUp(): void
    {
        // Reset tables before each test to ensure clean state
        self::resetTempTestTables();
    }

    //region UPDATE Protection

    public function testUpdateWithEmptyStringWhereThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");

        DB::update('users', ['name' => 'Changed'], '');
    }

    public function testUpdateWithEmptyArrayWhereThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");

        DB::update('users', ['name' => 'Changed'], []);
    }

    public function testUpdateWithWhitespaceOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");

        DB::update('users', ['name' => 'Changed'], '   ');
    }

    public function testUpdateWithOrderByOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");

        DB::update('users', ['name' => 'Changed'], 'ORDER BY num');
    }

    public function testUpdateWithLimitOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");

        DB::update('users', ['name' => 'Changed'], 'LIMIT 5');
    }

    public function testUpdateWithOffsetOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");

        DB::update('users', ['name' => 'Changed'], 'OFFSET 5');
    }

    public function testUpdateWithForOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");

        DB::update('users', ['name' => 'Changed'], 'FOR UPDATE');
    }

    //endregion
    //region DELETE Protection

    public function testDeleteWithEmptyStringWhereThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DELETE requires a WHERE condition");

        DB::delete('users', '');
    }

    public function testDeleteWithEmptyArrayWhereThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DELETE requires a WHERE condition");

        DB::delete('users', []);
    }

    public function testDeleteWithWhitespaceOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DELETE requires a WHERE condition");

        DB::delete('users', '   ');
    }

    public function testDeleteWithLimitOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DELETE requires a WHERE condition");

        DB::delete('users', 'LIMIT 1');
    }

    public function testDeleteWithOrderByOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DELETE requires a WHERE condition");

        DB::delete('users', 'ORDER BY num');
    }

    //endregion
    //region Valid Operations

    public function testUpdateWithValidStringWhereSucceeds(): void
    {
        $affected = DB::update('users', ['city' => 'New City'], 'num = ?', 1);
        $this->assertSame(1, $affected);

        // Verify only one row changed
        $result = DB::get('users', ['num' => 1]);
        $this->assertSame('New City', $result->get('city')->value());
    }

    public function testUpdateWithValidArrayWhereSucceeds(): void
    {
        $affected = DB::update('users', ['city' => 'Array City'], ['num' => 2]);
        $this->assertSame(1, $affected);
    }

    public function testDeleteWithValidStringWhereSucceeds(): void
    {
        $this->assertSame(20, DB::count('users'));
        $affected = DB::delete('users', 'num = ?', 20);
        $this->assertSame(1, $affected);
        $this->assertSame(19, DB::count('users'));
    }

    public function testDeleteWithValidArrayWhereSucceeds(): void
    {
        $this->assertSame(20, DB::count('users'));
        $affected = DB::delete('users', ['num' => 19]);
        $this->assertSame(1, $affected);
        $this->assertSame(19, DB::count('users'));
    }

    public function testUpdateWithWhereAndLimitSucceeds(): void
    {
        // WHERE condition + LIMIT is valid
        // Note: status is ENUM('Active', 'Inactive', 'Suspended'), so use a valid value
        $affected = DB::update(
            'users',
            ['status' => 'Inactive'],
            "status = ? ORDER BY num LIMIT ?",
            ['Active', 3]
        );
        $this->assertSame(3, $affected);
    }

    public function testDeleteWithWhereAndLimitSucceeds(): void
    {
        $this->assertSame(5, DB::count('users', ['status' => 'Inactive']));
        $initialCount = DB::count('users', ['status' => 'Inactive']);

        // WHERE + ORDER BY + LIMIT is valid
        $affected = DB::delete(
            'users',
            "status = ? ORDER BY num LIMIT ?",
            ['Inactive', 1]
        );
        $this->assertSame(1, $affected);
        $this->assertSame($initialCount - 1, DB::count('users', ['status' => 'Inactive']));
    }

    //endregion
    //region Legacy Integer WHERE (Deprecated but Supported)

    public function testUpdateWithIntegerWhereSucceeds(): void
    {
        // Integer WHERE (deprecated) should still work
        $affected = @DB::update('users', ['city' => 'Int City'], 3);
        $this->assertSame(1, $affected);

        // Verify the right row was updated
        $row = DB::get('users', ['num' => 3]);
        $this->assertSame('Int City', $row->get('city')->value());
    }

    public function testDeleteWithIntegerWhereSucceeds(): void
    {
        // Integer WHERE (deprecated) should still work
        $this->assertSame(20, DB::count('users'));
        $affected = @DB::delete('users', 18);
        $this->assertSame(1, $affected);
        $this->assertSame(19, DB::count('users'));
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider provideEmptyWhereVariants
     */
    public function testUpdateRejectsEmptyWhereVariants(mixed $where, string $description): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("UPDATE requires a WHERE condition");

        DB::update('users', ['name' => 'Test'], $where);
    }

    /**
     * @dataProvider provideEmptyWhereVariants
     */
    public function testDeleteRejectsEmptyWhereVariants(mixed $where, string $description): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DELETE requires a WHERE condition");

        DB::delete('users', $where);
    }

    public static function provideEmptyWhereVariants(): array
    {
        return [
            ['', 'empty string'],
            ['   ', 'whitespace only'],
            [[], 'empty array'],
            ['ORDER BY num', 'ORDER BY only'],
            ['LIMIT 10', 'LIMIT only'],
            ['OFFSET 5', 'OFFSET only'],
            ['FOR UPDATE', 'FOR UPDATE only'],
            ['  ORDER BY num  ', 'ORDER BY with whitespace'],
            ['  LIMIT 5  ', 'LIMIT with whitespace'],
        ];
    }

    //endregion
}
