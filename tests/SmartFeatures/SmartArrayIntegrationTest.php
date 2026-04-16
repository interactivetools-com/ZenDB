<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\SmartFeatures;

use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests SmartArray result integration per README documentation
 *
 * @covers \Itools\ZenDB\ConnectionInternals::toSmartArray
 */
class SmartArrayIntegrationTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region ResultSet Behavior

    public function testResultSetCount(): void
    {
        $result = DB::select('users');
        $this->assertSame(20, $result->count());
    }

    public function testResultSetFirst(): void
    {
        $result = DB::select('users', 'ORDER BY num');
        $first = $result->first();

        $this->assertSame(1, $first->get('num')->value());
        $this->assertSame('John Doe', $first->get('name')->value());
    }

    public function testResultSetLast(): void
    {
        $result = DB::select('users', 'ORDER BY num');
        $last = $result->last();

        $this->assertSame(20, $last->get('num')->value());
    }

    public function testResultSetToArray(): void
    {
        $result = DB::select('users', ['num' => 1]);
        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertCount(1, $array);
        $this->assertSame('John Doe', $array[0]['name']);
    }

    public function testResultSetIsEmpty(): void
    {
        $result = DB::select('users', ['num' => 9999]);
        $this->assertTrue($result->isEmpty());

        $result2 = DB::select('users', ['num' => 1]);
        $this->assertFalse($result2->isEmpty());
    }

    public function testResultSetIteration(): void
    {
        $result = DB::select('users', 'ORDER BY num LIMIT 3');

        $names = [];
        foreach ($result as $row) {
            $names[] = $row->get('name')->value();
        }

        $this->assertCount(3, $names);
        $this->assertSame('John Doe', $names[0]);
    }

    public function testResultSetNth(): void
    {
        $result = DB::select('users', 'ORDER BY num');

        $second = $result->nth(1); // 0-indexed
        $this->assertSame(2, $second->get('num')->value());
    }

    //endregion
    //region Row Behavior

    public function testRowPropertyAccess(): void
    {
        $row = DB::selectOne('users', ['num' => 1]);

        // Property-style access returns SmartString
        $name = $row->name;
        $this->assertInstanceOf(SmartString::class, $name);
    }

    public function testRowArrayAccessIsDeprecated(): void
    {
        $row = DB::selectOne('users', ['num' => 1]);

        // $row['name'] is deprecated in favor of $row->name - row results are objects
        // and should be accessed with object semantics (no !empty($row) etc.).
        $this->expectOutputRegex("/Deprecated: Replace \\['name'\\] with ->name/");

        $name = $row['name'];
        $this->assertInstanceOf(SmartString::class, $name);
    }

    public function testRowGetWithDefault(): void
    {
        $row = DB::selectOne('users', ['num' => 1]);

        // Get with default for missing key - returns SmartString wrapping the default
        $missing = $row->get('nonexistent', 'default');
        // SmartString wraps the default
        $this->assertSame('default', $missing->value());
    }

    public function testRowToArray(): void
    {
        $row = DB::selectOne('users', ['num' => 1]);
        $array = $row->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('name', $array);
        $this->assertSame('John Doe', $array['name']);
    }

    public function testRowKeyExists(): void
    {
        $row = DB::selectOne('users', ['num' => 1]);

        // Use isset() or array key exists via offsetExists
        $this->assertTrue(isset($row['name']));
        $this->assertTrue(isset($row['num']));
        $this->assertFalse(isset($row['nonexistent']));
    }

    //endregion
    //region SmartString Value Behavior

    public function testValueAutoHtmlEncoding(): void
    {
        // User 8 has HTML in name: 'Frank <b>Miller</b>'
        $row = DB::selectOne('users', ['num' => 8]);

        // In string context, SmartString auto-encodes HTML
        $html = (string) $row->get('name');
        $this->assertSame('Frank &lt;b&gt;Miller&lt;/b&gt;', $html);
    }

    public function testValueMethodAccess(): void
    {
        $row = DB::selectOne('users', ['num' => 1]);

        // ->value() returns raw value
        $raw = $row->get('name')->value();
        $this->assertSame('John Doe', $raw);
    }

    public function testValueHtmlEncode(): void
    {
        $row = DB::selectOne('users', ['num' => 8]);

        // Explicit HTML encoding
        $html = $row->get('name')->htmlEncode();
        $this->assertSame('Frank &lt;b&gt;Miller&lt;/b&gt;', $html);
    }

    public function testValueUrlEncode(): void
    {
        $row = DB::selectOne('users', ['num' => 18]);
        // "St. John's" has apostrophe

        $url = $row->get('city')->urlEncode();
        $this->assertSame("St.+John%27s", $url);
    }

    public function testValueJsonEncode(): void
    {
        $row = DB::selectOne('users', ['num' => 1]);

        $json = $row->get('name')->jsonEncode();
        $this->assertSame('"John Doe"', $json);
    }

    public function testValueWithNull(): void
    {
        // User 2 has isAdmin = NULL
        $row = DB::selectOne('users', ['num' => 2]);

        $isAdmin = $row->get('isAdmin');
        $this->assertNull($isAdmin->value());
        $this->assertTrue($isAdmin->isNull());
    }

    //endregion
    //region MySQL Metadata Access

    public function testMysqliInsertId(): void
    {
        $insertId = DB::insert('users', [
            'name' => 'Test Insert',
            'status' => 'Active',
            'city' => 'Test City'
        ]);

        $this->assertGreaterThan(0, $insertId);

        // Clean up
        DB::delete('users', ['name' => 'Test Insert']);
    }

    public function testMysqliAffectedRows(): void
    {
        // Insert then update
        $insertId = DB::insert('users', [
            'name' => 'Affected Test',
            'status' => 'Active',
            'city' => 'Test'
        ]);

        $affected = DB::update('users', ['city' => 'Updated'], ['name' => 'Affected Test']);
        $this->assertSame(1, $affected);

        // Clean up
        DB::delete('users', ['name' => 'Affected Test']);
    }

    public function testQueryReturnsResults(): void
    {
        // Query and verify we get the expected result structure
        $result = DB::query("SELECT * FROM ::users WHERE num = ?", 1);

        // Verify the result contains expected data
        $this->assertCount(1, $result);
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    public function testResultSetFirstAndCount(): void
    {
        $result = DB::select('users', ['num' => 1]);

        // Test that we get the expected structure
        $this->assertSame(1, $result->count());
        $this->assertSame('John Doe', $result->first()->get('name')->value());
    }

    //endregion
    //region SmartStrings Toggle

    public function testUseSmartStringsTrue(): void
    {
        // Default behavior
        $result = DB::select('users', ['num' => 1]);
        $row = $result->first();

        $this->assertInstanceOf(SmartString::class, $row->get('name'));
    }

    public function testSmartStringsEnabled(): void
    {
        // Default behavior - SmartStrings enabled
        $result = DB::select('users', ['num' => 1]);
        $row = $result->first();

        // With SmartStrings enabled (default), values are SmartString
        $this->assertInstanceOf(SmartString::class, $row->get('name'));
        $this->assertSame('John Doe', $row->get('name')->value());
    }

    //endregion
    //region Collection Methods

    public function testPluck(): void
    {
        $result = DB::select('users', 'ORDER BY num LIMIT 3');
        $names = $result->pluck('name');

        $this->assertCount(3, $names);
        $this->assertSame('John Doe', $names->first()->value());
    }

    public function testPluckNth(): void
    {
        $result = DB::select('users', 'ORDER BY num LIMIT 3');
        $nums = $result->pluckNth(0); // First column (num)

        $this->assertCount(3, $nums);
    }

    public function testFilter(): void
    {
        $result = DB::select('users');
        $active = $result->filter(fn($row) => $row['status'] === 'Active');

        $this->assertCount(10, $active);
        foreach ($active as $row) {
            $this->assertSame('Active', $row->get('status')->value());
        }
    }

    public function testMap(): void
    {
        $result = DB::select('users', 'ORDER BY num LIMIT 3');
        // map() callback receives the row as array when iterating
        // The SmartString needs to be converted to value first via string cast
        $upperNames = $result->map(fn($row) => strtoupper((string) $row['name']));

        $this->assertCount(3, $upperNames);
        // first() returns SmartString, use value() to get raw value
        $this->assertSame('JOHN DOE', (string) $upperNames->first());
    }

    //endregion
}
