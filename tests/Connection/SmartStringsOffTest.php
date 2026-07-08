<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Connection;

use Exception;
use Itools\SmartArray\SmartArray;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Queries on useSmartStrings=false connections must return plain SmartArrays with raw PHP
 * values, and every internal helper must work the same on them as on wrapped connections.
 * Pins the regression where toSmartArray() built a SmartArrayHtml unconditionally, which
 * throws for useSmartStrings=false, so flipping the setting broke every query.
 */
class SmartStringsOffTest extends BaseTestCase
{
    //region Setup & Teardown

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();

        DB::$mysqli->query("DROP TABLE IF EXISTS test_sstest");
        DB::$mysqli->query("CREATE TABLE test_sstest (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL DEFAULT '')");
        DB::$mysqli->query("INSERT INTO test_sstest (name) VALUES ('Alice'), ('Bob')");
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::$mysqli->query("DROP TABLE IF EXISTS test_sstest");
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    //endregion
    //region Query Methods

    public function testQueryReturnsPlainSmartArrayWithRawValues(): void
    {
        $rows = DB::clone(['useSmartStrings' => false])->query("SELECT * FROM `::?` ORDER BY num", 'sstest');

        $this->assertInstanceOf(SmartArray::class, $rows);
        $this->assertNotInstanceOf(SmartArrayHtml::class, $rows);
        $this->assertSame('Alice', $rows->first()->get('name'), 'values are raw strings, not SmartStrings');
    }

    public function testQueryOneReturnsPlainSmartArrayWithRawValues(): void
    {
        $row = DB::clone(['useSmartStrings' => false])->queryOne("SELECT * FROM `::?` ORDER BY num", 'sstest');

        $this->assertInstanceOf(SmartArray::class, $row);
        $this->assertNotInstanceOf(SmartArrayHtml::class, $row);
        $this->assertSame('Alice', $row->get('name'));
    }

    public function testSelectReturnsPlainSmartArrayWithRawValues(): void
    {
        $rows = DB::clone(['useSmartStrings' => false])->select('sstest');

        $this->assertInstanceOf(SmartArray::class, $rows);
        $this->assertNotInstanceOf(SmartArrayHtml::class, $rows);
        $this->assertCount(2, $rows);
    }

    public function testDefaultConnectionStillReturnsSmartStrings(): void
    {
        $rows = DB::query("SELECT * FROM `::?` ORDER BY num", 'sstest');

        $this->assertInstanceOf(SmartArrayHtml::class, $rows);
        $this->assertInstanceOf(SmartString::class, $rows->first()->get('name'), 'wrapped connections keep SmartString values');
    }

    //endregion
    //region Internal Helpers

    public function testTableInfoWorksWithSmartStringsOff(): void
    {
        $table = DB::clone(['useSmartStrings' => false])->table;

        $this->assertTrue($table->exists('sstest'));
        $this->assertSame('num', $table->primaryKey('sstest'));
        $this->assertSame(['num', 'name'], $table->columnNames('sstest'));
        $this->assertSame("varchar(50) NOT NULL DEFAULT ''", $table->columnDefinitions('sstest')['name']);
        $this->assertArrayHasKey('PRIMARY', $table->indexes('sstest'));
    }

    public function testGetColumnDefinitionsWorksWithSmartStringsOff(): void
    {
        $definitions = DB::clone(['useSmartStrings' => false])->getColumnDefinitions('sstest');

        $this->assertSame("varchar(50) NOT NULL DEFAULT ''", $definitions['name']);
    }

    //endregion
}
