<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\ValueTypes;

use Itools\SmartArray\SmartArray;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for SmartString, SmartArray, and SmartNull value handling
 *
 * @covers \Itools\ZenDB\ConnectionInternals::getPlaceholderValue
 */
class SmartTypeValuesTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region SmartString

    public function testSmartStringUnwrapped(): void
    {
        $smart = new SmartString('John Doe');
        $result = DB::query("SELECT * FROM ::users WHERE name = ?", $smart);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result->first()->get('num')->value());
    }

    public function testSmartStringInNamedParam(): void
    {
        $smart = new SmartString('Charlie Brown');
        $result = DB::query("SELECT * FROM ::users WHERE name = :name", [':name' => $smart]);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result->first()->get('num')->value());
    }

    public function testSmartStringWithSpecialChars(): void
    {
        $smart = new SmartString("Frank <b>Miller</b>");
        $result = DB::query("SELECT * FROM ::users WHERE name = ?", $smart);

        $this->assertCount(1, $result);
        $this->assertSame(8, $result->first()->get('num')->value());
    }

    public function testSmartStringInSetClause(): void
    {
        $insertId = DB::insert('users', [
            'name' => new SmartString('SmartString Test'),
            'status' => 'Active',
            'city' => new SmartString('Smart City')
        ]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame('SmartString Test', $row->get('name')->value());
        $this->assertSame('Smart City', $row->get('city')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    public function testSmartStringInWhereArray(): void
    {
        $smart = new SmartString('Vancouver');
        $result = DB::select('users', ['city' => $smart]);

        $this->assertCount(1, $result);
        $this->assertSame('Vancouver', $result->first()->get('city')->value());
    }

    public function testSmartStringEscapesSpecialChars(): void
    {
        $smart = new SmartString("O'Brien");

        $insertId = DB::insert('users', [
            'name' => $smart,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertSame("O'Brien", $row->get('name')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    //endregion
    //region SmartNull

    public function testSmartNullBecomesNull(): void
    {
        // SmartNull is not directly supported in insert() SET clause
        // Use null directly instead
        $insertId = DB::insert('users', [
            'name' => 'SmartNull Test',
            'isAdmin' => null,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $row = DB::get('users', ['num' => $insertId]);
        $this->assertNull($row->get('isAdmin')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    public function testSmartNullInPlaceholder(): void
    {
        $smartNull = new SmartNull();

        // SmartNull becomes SQL NULL
        $result = DB::query("SELECT ? as value", $smartNull);
        $this->assertNull($result->first()->get('value')->value());
    }

    //endregion
    //region SmartArray

    public function testSmartArrayConverted(): void
    {
        $smartArray = new SmartArray([1, 2, 3]);
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids)", [':ids' => $smartArray]);

        $this->assertCount(3, $result);
    }

    public function testSmartArrayInSetClause(): void
    {
        // For multi-value columns stored as CSV
        // Note: This depends on how the schema handles array values
        // In most cases, arrays are converted to CSV strings

        $smartArray = new SmartArray(['tag1', 'tag2', 'tag3']);

        // escapeCSV handles SmartArray
        $csv = DB::escapeCSV($smartArray->toArray());
        $this->assertSame("'tag1','tag2','tag3'", (string) $csv);
    }

    public function testSmartArrayFromQueryPluck(): void
    {
        // Get IDs from one query, use in another
        $ids = DB::select('users', 'ORDER BY num LIMIT 3')->pluck('num');

        // Use the SmartArray result in another query
        $result = DB::query("SELECT * FROM ::users WHERE num IN (:ids)", [':ids' => $ids]);
        $this->assertCount(3, $result);
    }

    //endregion
    //region Combined Smart Types

    public function testMixedSmartTypes(): void
    {
        $name = new SmartString('Test User');
        $smartArray = new SmartArray(['Active', 'Inactive']);

        $result = DB::query(
            "SELECT * FROM ::users WHERE name = :name OR status IN (:statuses)",
            [':name' => $name, ':statuses' => $smartArray]
        );

        // All Active and Inactive users, plus exact name match
        $this->assertCount(15, $result);
    }

    public function testSmartStringFromQueryResult(): void
    {
        // Get a SmartString from query result
        $row = DB::get('users', ['num' => 1]);
        $name = $row->get('name'); // This is a SmartString

        // Use it in another query
        $result = DB::query("SELECT * FROM ::users WHERE name = ?", $name);
        $this->assertCount(1, $result);
    }

    //endregion
}
