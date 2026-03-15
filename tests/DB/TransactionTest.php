<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\DB;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use RuntimeException;

/**
 * Tests for DB::transaction()
 */
class TransactionTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTempTestTables();
    }

    public function testCommitsOnSuccess(): void
    {
        self::resetTempTestTables();

        $insertId = DB::transaction(function () {
            return DB::insert('users', [
                'name'    => 'Transaction User',
                'isAdmin' => 0,
                'status'  => 'Active',
                'city'    => 'TestCity',
                'dob'     => '2000-01-01',
                'age'     => 24,
            ]);
        });

        $this->assertSame(21, $insertId);
        $row = DB::selectOne('users', ['num' => 21]);
        $this->assertSame('Transaction User', $row->get('name')->value());
    }

    public function testRollbackRevertsData(): void
    {
        self::resetTempTestTables();
        $before = DB::count('users');

        try {
            DB::transaction(function () {
                DB::insert('users', [
                    'name'    => 'Should Not Exist',
                    'isAdmin' => 0,
                    'status'  => 'Active',
                    'city'    => 'Nowhere',
                    'dob'     => '2000-01-01',
                    'age'     => 1,
                ]);
                throw new RuntimeException("force rollback");
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, DB::count('users'), "Row should not exist after rollback");
        $this->assertCount(0, DB::select('users', ['name' => 'Should Not Exist']));
    }

    public function testRethrowsExceptionAfterRollback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Intentional failure");

        DB::transaction(function () {
            throw new RuntimeException("Intentional failure");
        });
    }

    public function testReturnsCallableResult(): void
    {
        $result = DB::transaction(fn() => 'hello');
        $this->assertSame('hello', $result);
    }

    public function testNestedTransactionThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("cannot be nested");

        DB::transaction(function () {
            DB::transaction(fn() => null);
        });
    }

    public function testFlagResetsAfterSuccess(): void
    {
        DB::transaction(fn() => null);

        // Should not throw - flag was reset
        $result = DB::transaction(fn() => 42);
        $this->assertSame(42, $result);
    }

    public function testFlagResetsAfterException(): void
    {
        try {
            DB::transaction(function () {
                throw new RuntimeException("fail");
            });
        } catch (RuntimeException) {
            // expected
        }

        // Should not throw - flag was reset after rollback
        $result = DB::transaction(fn() => 99);
        $this->assertSame(99, $result);
    }

    public function testFlagResetsAfterNestedAttempt(): void
    {
        try {
            DB::transaction(function () {
                DB::transaction(fn() => null);
            });
        } catch (RuntimeException) {
            // expected - nested transaction rejected
        }

        // Should not throw - flag was reset by finally block
        $result = DB::transaction(fn() => 'recovered');
        $this->assertSame('recovered', $result);
    }
}
