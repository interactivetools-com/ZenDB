<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\ValueTypes;

use InvalidArgumentException;
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
        self::resetTestTables();
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

        $row = DB::selectOne('users', ['num' => $insertId]);
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

    public function testSmartStringNullInSetClause(): void
    {
        // SmartString unwraps to its original type, so a wrapped null writes SQL NULL
        $insertId = DB::insert('users', [
            'name' => 'SmartString Null Test',
            'status' => 'Active',
            'city' => new SmartString(null)
        ]);

        $row = DB::selectOne('users', ['num' => $insertId]);
        $this->assertNull($row->get('city')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    public function testSmartStringNullInWhereArray(): void
    {
        // A wrapped null matches like a raw null: `column` IS NULL
        $result = DB::select('users', ['isAdmin' => new SmartString(null)]);
        $this->assertCount(4, $result);
    }

    public function testSmartStringEscapesSpecialChars(): void
    {
        $smart = new SmartString("O'Brien");

        $insertId = DB::insert('users', [
            'name' => $smart,
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $row = DB::selectOne('users', ['num' => $insertId]);
        $this->assertSame("O'Brien", $row->get('name')->value());

        // Clean up
        DB::delete('users', ['num' => $insertId]);
    }

    //endregion
    //region SmartNull

    public function testSmartNullInSetClauseThrows(): void
    {
        // Placeholders unwrap SmartNull to SQL NULL (see testSmartNullInPlaceholder);
        // the SET clause does not - it rejects SmartNull as an unsupported type
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported type for column 'isAdmin'");

        DB::insert('users', [
            'name' => 'SmartNull Test',
            'isAdmin' => new SmartNull(),
            'status' => 'Active',
            'city' => 'Test'
        ]);
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

    public function testSmartArrayEscapeCsv(): void
    {
        // Multi-value columns stored as CSV go through escapeCSV()
        $smartArray = new SmartArray(['tag1', 'tag2', 'tag3']);

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
        // Bob Johnson is Suspended, so the name match adds a row the status list doesn't
        $name = new SmartString('Bob Johnson');
        $smartArray = new SmartArray(['Active', 'Inactive']);

        $result = DB::query(
            "SELECT * FROM ::users WHERE name = :name OR status IN (:statuses)",
            [':name' => $name, ':statuses' => $smartArray]
        );

        // 10 Active + 5 Inactive + 1 Suspended name match
        $this->assertCount(16, $result);
    }

    public function testSmartStringFromQueryResult(): void
    {
        // Get a SmartString from query result
        $row = DB::selectOne('users', ['num' => 1]);
        $name = $row->get('name'); // This is a SmartString

        // Use it in another query
        $result = DB::query("SELECT * FROM ::users WHERE name = ?", $name);
        $this->assertCount(1, $result);
    }

    //endregion
}
