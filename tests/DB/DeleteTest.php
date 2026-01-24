<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Connection;
use Itools\ZenDB\Tests\BaseTestCase;
use InvalidArgumentException;

/**
 * Tests for DB::delete() static method
 */
class DeleteTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        new Connection(self::$configDefaults, default: true);
    }

    protected function setUp(): void
    {
        self::resetTempTestTables();
    }

    public function testDeleteSingleRow(): void
    {
        $countBefore = DB::count('users');
        $affected    = DB::delete('users', 1);

        $this->assertSame(1, $affected);
        $this->assertSame($countBefore - 1, DB::count('users'));
    }

    public function testDeleteWithArrayCondition(): void
    {
        $affected = DB::delete('users', ['name' => 'John Doe']);
        $this->assertSame(1, $affected);
        $this->assertTrue(DB::get('users', ['name' => 'John Doe'])->isEmpty());
    }

    public function testDeleteMultipleRows(): void
    {
        $countBefore = DB::count('users', 'status = ?', 'Suspended');
        $affected    = DB::delete('users', 'status = ?', 'Suspended');

        $this->assertSame($countBefore, $affected);
        $this->assertSame(0, DB::count('users', 'status = ?', 'Suspended'));
    }

    public function testDeleteRequiresWhereCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::delete('users', '');
    }

    public function testDeleteNonExistentRowReturnsZero(): void
    {
        $affected = DB::delete('users', 9999);
        $this->assertSame(0, $affected);
    }
}
