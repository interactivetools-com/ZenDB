<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Escaping;

use InvalidArgumentException;
use Itools\SmartArray\SmartArray;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use RuntimeException;

/**
 * Tests for DB::escapef() method
 *
 * @covers \Itools\ZenDB\Connection::escapef
 */
class EscapefTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
    }

    //region Type Handling

    public function testEscapefStringQuoted(): void
    {
        $result = DB::escapef("name = ?", 'John');
        $this->assertSame("name = 'John'", $result);
    }

    public function testEscapefStringWithSpecialChars(): void
    {
        $result = DB::escapef("name = ?", "O'Brien");
        $this->assertSame("name = 'O\\'Brien'", $result);
    }

    public function testEscapefIntegerNotQuoted(): void
    {
        $result = DB::escapef("id = ?", 42);
        $this->assertSame("id = 42", $result);
    }

    public function testEscapefFloatNotQuoted(): void
    {
        $result = DB::escapef("price = ?", 19.99);
        $this->assertSame("price = 19.99", $result);
    }

    public function testEscapefNullBecomesKeyword(): void
    {
        $result = DB::escapef("value = ?", null);
        $this->assertSame("value = NULL", $result);
    }

    public function testEscapefBooleanTrue(): void
    {
        $result = DB::escapef("active = ?", true);
        $this->assertSame("active = TRUE", $result);
    }

    public function testEscapefBooleanFalse(): void
    {
        $result = DB::escapef("active = ?", false);
        $this->assertSame("active = FALSE", $result);
    }

    //endregion
    //region Array Handling

    public function testEscapefArrayBecomesCSV(): void
    {
        $result = DB::escapef("id IN (?)", [1, 2, 3]);
        $this->assertSame("id IN (1,2,3)", $result);
    }

    public function testEscapefArrayOfStrings(): void
    {
        $result = DB::escapef("name IN (?)", ['Alice', 'Bob']);
        $this->assertSame("name IN ('Alice','Bob')", $result);
    }

    public function testEscapefEmptyArray(): void
    {
        $result = DB::escapef("id IN (?)", []);
        $this->assertSame("id IN (NULL)", $result);
    }

    public function testEscapefSmartArray(): void
    {
        $smartArray = new SmartArray([1, 2, 3]);
        $result = DB::escapef("id IN (?)", $smartArray);
        $this->assertSame("id IN (1,2,3)", $result);
    }

    //endregion
    //region SmartString Handling

    public function testEscapefSmartString(): void
    {
        $smart = new SmartString('John');
        $result = DB::escapef("name = ?", $smart);
        $this->assertSame("name = 'John'", $result);
    }

    public function testEscapefSmartStringWithSpecialChars(): void
    {
        $smart = new SmartString("O'Brien");
        $result = DB::escapef("name = ?", $smart);
        $this->assertSame("name = 'O\\'Brien'", $result);
    }

    //endregion
    //region Multiple Placeholders

    public function testEscapefMultiplePlaceholders(): void
    {
        $result = DB::escapef("SELECT * FROM users WHERE name = ? AND age > ?", 'John', 25);
        $this->assertSame("SELECT * FROM users WHERE name = 'John' AND age > 25", $result);
    }

    public function testEscapefThreePlaceholders(): void
    {
        $result = DB::escapef("INSERT INTO users (name, age, active) VALUES (?, ?, ?)", 'Alice', 30, true);
        $this->assertSame("INSERT INTO users (name, age, active) VALUES ('Alice', 30, TRUE)", $result);
    }

    public function testEscapefMixedTypes(): void
    {
        $result = DB::escapef(
            "UPDATE users SET name = ?, age = ?, active = ?, notes = ? WHERE id = ?",
            'Bob', 25, false, null, 1
        );
        $this->assertSame(
            "UPDATE users SET name = 'Bob', age = 25, active = FALSE, notes = NULL WHERE id = 1",
            $result
        );
    }

    //endregion
    //region Error Conditions

    public function testEscapefUnsupportedTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported type");

        DB::escapef("value = ?", new \stdClass());
    }

    public function testEscapefResourceTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported type");

        $resource = fopen('php://memory', 'r');
        try {
            DB::escapef("value = ?", $resource);
        } finally {
            fclose($resource);
        }
    }

    //endregion
    //region Edge Cases

    public function testEscapefNoPlaceholders(): void
    {
        $result = DB::escapef("SELECT * FROM users");
        $this->assertSame("SELECT * FROM users", $result);
    }

    public function testEscapefEmptyString(): void
    {
        $result = DB::escapef("name = ?", '');
        $this->assertSame("name = ''", $result);
    }

    public function testEscapefZeroInteger(): void
    {
        $result = DB::escapef("count = ?", 0);
        $this->assertSame("count = 0", $result);
    }

    public function testEscapefZeroFloat(): void
    {
        $result = DB::escapef("amount = ?", 0.0);
        $this->assertSame("amount = 0", $result);
    }

    //endregion
    //region Placeholder Count Mismatch

    public function testEscapefMorePlaceholdersThanValuesThrows(): void
    {
        // BUG: missing values silently become NULL instead of throwing
        $this->expectException(InvalidArgumentException::class);
        DB::escapef("UPDATE users SET name = ?, age = ?, city = ?", 'Alice', 30);
    }

    public function testEscapefMoreValuesThanPlaceholdersThrows(): void
    {
        // BUG: extra values are silently ignored instead of throwing
        $this->expectException(InvalidArgumentException::class);
        DB::escapef("name = ?", 'Alice', 30, 'extra');
    }

    //endregion
}
