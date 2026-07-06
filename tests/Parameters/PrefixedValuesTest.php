<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Parameters;

use InvalidArgumentException;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for prefixed value placeholders (::?, :::name) - table prefix prepended
 * inside the quoted, escaped value. For LIKE patterns and string comparisons
 * against prefixed table names, e.g. SHOW TABLES LIKE ::? or TABLE_NAME = :::table.
 *
 * @covers \Itools\ZenDB\ConnectionInternals::replacePlaceholders
 */
class PrefixedValuesTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region Basic Substitution

    public function testPrefixedNamedValue(): void
    {
        // :::name - prefix + value, quoted as a single string
        $result = DB::query("SELECT :::table AS val", [':table' => 'users']);
        $this->assertSame('test_users', $result->first()->get('val')->value());
        $this->assertStringContainsString("'test_users'", DB::$mysqli->lastQuery);
    }

    public function testPrefixedPositionalValue(): void
    {
        // ::? - prefix + value, quoted as a single string
        $result = DB::query("SELECT ::? AS val", 'users');
        $this->assertSame('test_users', $result->first()->get('val')->value());
        $this->assertStringContainsString("'test_users'", DB::$mysqli->lastQuery);
    }

    public function testPrefixedValueInLikeClause(): void
    {
        // The main use case: matching prefixed table names as string values
        $result = DB::query("SELECT COUNT(*) AS cnt FROM ::users WHERE :::table LIKE :pattern", [
            ':table'   => 'users',
            ':pattern' => 'test_user%',
        ]);
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
    }

    //endregion
    //region Escaping

    public function testPrefixedValueIsEscaped(): void
    {
        // Prefix lands inside the quotes, value still escaped
        $result = DB::query("SELECT :::name AS val", [':name' => "O'Brien"]);
        $this->assertSame("test_O'Brien", $result->first()->get('val')->value());
        $this->assertStringContainsString("'test_O\\'Brien'", DB::$mysqli->lastQuery);
    }

    //endregion
    //region Type Handling

    public function testPrefixedIntThrows(): void
    {
        // The prefix is a table prefix, so only strings make sense; pass '42' if you want 'test_42'
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a string or array value, got int');

        DB::query("SELECT :::num AS val", [':num' => 42]);
    }

    public function testPrefixedFloatThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a string or array value, got float');

        DB::query("SELECT :::num AS val", [':num' => 3.14]);
    }

    public function testPrefixedSmartStringUnwraps(): void
    {
        // SmartString params unwrap to their raw value before prefixing
        $result = DB::query("SELECT :::table AS val", [':table' => new SmartString('users')]);
        $this->assertSame('test_users', $result->first()->get('val')->value());
    }

    public function testPrefixedArrayPrefixesEachElement(): void
    {
        // Arrays prefix each element, then expand to CSV like :name does
        $result = DB::query("SELECT COUNT(*) AS cnt FROM ::users WHERE :::table IN (:::tables)", [
            ':table'  => 'users',
            ':tables' => ['users', 'orders'],
        ]);
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
        $this->assertStringContainsString("'test_users'", DB::$mysqli->lastQuery);
        $this->assertStringContainsString("'test_orders'", DB::$mysqli->lastQuery);
    }

    public function testPrefixedRawSqlThrows(): void
    {
        // Throws for now - callers can build DB::rawSql(DB::$tablePrefix . '...') themselves.
        // Loosening later is backward compatible; silent behavior wouldn't be
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't support RawSql");

        DB::query("SELECT COUNT(*) AS cnt FROM :::table", [':table' => DB::rawSql('users')]);
    }

    public function testPrefixedBoolThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a string or array value, got bool');

        DB::query("SELECT :::flag AS val", [':flag' => true]);
    }

    public function testPrefixedNullThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a string or array value, got null');

        DB::query("SELECT :::name AS val", [':name' => null]);
    }

    public function testPrefixedPositionalBoolThrows(): void
    {
        // Positional message doesn't list array (arrays are rejected for all positional placeholders)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a string value, got bool');

        DB::query("SELECT ::? AS val", true);
    }

    public function testPrefixedPositionalArrayThrows(): void
    {
        // Arrays with positional placeholders are rejected before prefixing (same as plain ?)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Arrays not allowed with positional');

        DB::query("SELECT ::? AS val", [['users', 'orders']]);
    }

    public function testPrefixedEmptyArrayBecomesNull(): void
    {
        // Same as plain :name - empty array becomes IN (NULL), which matches nothing
        $result = DB::query("SELECT COUNT(*) AS cnt FROM ::users WHERE name IN (:::names)", [':names' => []]);
        $this->assertSame(0, (int) $result->first()->get('cnt')->value());
    }

    public function testPrefixedArraySmartStringElementUnwraps(): void
    {
        // SmartString elements unwrap to their raw value, not their HTML-encoded form
        $result = DB::query("SELECT :::names AS val", [':names' => [new SmartString("O'Brien")]]);
        $this->assertSame("test_O'Brien", $result->first()->get('val')->value());
    }

    public function testPrefixedArraySmartStringNullElementThrows(): void
    {
        // SmartString can wrap null; it unwraps before the type check, so it throws like a raw null
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array elements must be strings, got null');

        DB::query("SELECT :::names AS val", [':names' => [new SmartString(null)]]);
    }

    public function testPrefixedArraySmartStringBoolElementThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array elements must be strings, got bool');

        DB::query("SELECT :::names AS val", [':names' => [new SmartString(true)]]);
    }

    public function testPrefixedArrayBoolElementThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array elements must be strings, got bool');

        DB::query("SELECT :::names AS val", [':names' => ['users', true]]);
    }

    public function testPrefixedArrayNullElementThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array elements must be strings, got null');

        DB::query("SELECT :::names AS val", [':names' => ['users', null]]);
    }

    public function testPrefixedArrayRawSqlElementThrows(): void
    {
        // The prefixed-array element check rejects RawSql before escapeCSV runs
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array elements must be strings, got Itools\ZenDB\RawSql');

        DB::query("SELECT :::names AS val", [':names' => [DB::rawSql('NOW()')]]);
    }

    public function testPrefixedArrayNestedArrayElementThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('array elements must be strings, got array');

        DB::query("SELECT :::names AS val", [':names' => [['nested']]]);
    }

    //endregion
    //region Error Reporting

    public function testPrefixedMissingParamThrows(): void
    {
        // Error message references the underlying param name
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing value for ':table' parameter");

        DB::query("SELECT :::table AS val", [':wrongName' => 'users']);
    }

    //endregion
    //region Tokenizer Regression

    public function testBarePrefixBeforeLiteralUnaffected(): void
    {
        // ::users must still tokenize as bare :: + literal text, not a prefixed placeholder
        $result = DB::query("SELECT COUNT(*) AS cnt FROM ::users");
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
    }

    public function testBacktickPrefixedFormsUnchanged(): void
    {
        // `:::table` still resolves as a backticked identifier, not a quoted value
        $result = DB::query("SELECT COUNT(*) AS cnt FROM `:::table`", [':table' => 'users']);
        $this->assertSame(20, (int) $result->first()->get('cnt')->value());
        $this->assertStringContainsString('`test_users`', DB::$mysqli->lastQuery);
    }

    //endregion
    //region Deprecated :_ Syntax

    public function testDeprecatedUnderscoreSyntaxGetsNewBehavior(): void
    {
        // :_ normalizes to :: before tokenizing, so :_? and :_:name resolve as prefixed values
        $result = DB::query("SELECT :_? AS val", 'users');
        $this->assertSame('test_users', $result->first()->get('val')->value());

        $result = DB::query("SELECT :_:table AS val", [':table' => 'users']);
        $this->assertSame('test_users', $result->first()->get('val')->value());
    }

    //endregion
    //region Empty Table Prefix

    public function testEmptyPrefixReturnsValueUnchanged(): void
    {
        // With tablePrefix '' the placeholder still works, output is just the quoted value
        try {
            self::createDefaultConnection(['tablePrefix' => '']);
            $result = DB::query("SELECT :::table AS val", [':table' => 'users']);
            $this->assertSame('users', $result->first()->get('val')->value());
        } finally {
            // Reconnecting dropped the temp tables, restore both for any tests that run after
            self::createDefaultConnection();
            self::resetTempTestTables();
        }
    }

    //endregion
}
