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
 * Tests that mixing positional and named parameters is rejected
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

    public function testMixedPositionalAndNamedThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Can't mix positional (?) and named (:param) placeholders");
        DB::query(
            "SELECT * FROM ::users WHERE num >= ? AND status = :status",
            [10, ':status' => 'Active']
        );
    }

    public function testMixedMultiplePositionalWithNamedThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Can't mix positional (?) and named (:param) placeholders");
        DB::query(
            "SELECT * FROM ::users WHERE num = ? OR num = ? OR city = :city",
            [1, 2, ':city' => 'Edmonton']
        );
    }

    public function testMixedNamedBeforePositionalThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Can't mix positional (?) and named (:param) placeholders");
        DB::query(
            "SELECT * FROM ::users WHERE status = :status AND num > ?",
            [':status' => 'Active', 10]
        );
    }

    public function testPurePositionalStillWorks(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users WHERE num >= ? AND status = ?",
            [10, 'Active']
        );
        $this->assertCount(5, $result);
    }

    public function testPureNamedStillWorks(): void
    {
        $result = DB::query(
            "SELECT * FROM ::users WHERE num >= :minNum AND status = :status",
            [':minNum' => 10, ':status' => 'Active']
        );
        $this->assertCount(5, $result);
    }
}
