<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;

/**
 * Tests for DB::delete() static method
 */
class DeleteTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
    }

    protected function setUp(): void
    {
        self::resetTempTestTables();
    }

    public function testDeleteSingleRow(): void
    {
        $this->assertSame(20, DB::count('users'));
        $affected = DB::delete('users', ['num' => 1]);

        $this->assertSame(1, $affected);
        $this->assertSame(19, DB::count('users'));
        $this->assertTrue(DB::get('users', ['num' => 1])->isEmpty());
    }

    public function testDeleteWithArrayCondition(): void
    {
        $affected = DB::delete('users', ['name' => 'John Doe']);
        $this->assertSame(1, $affected);
        $this->assertTrue(DB::get('users', ['name' => 'John Doe'])->isEmpty());
    }

    public function testDeleteMultipleRows(): void
    {
        $this->assertSame(5, DB::count('users', 'status = ?', 'Suspended'));
        $affected = DB::delete('users', 'status = ?', 'Suspended');

        $this->assertSame(5, $affected);
        $this->assertSame(0, DB::count('users', 'status = ?', 'Suspended'));
    }

    public function testDeleteRequiresWhereCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::delete('users', '');
    }

    public function testDeleteNonExistentRowReturnsZero(): void
    {
        $affected = DB::delete('users', ['num' => 9999]);
        $this->assertSame(0, $affected);
    }
}
