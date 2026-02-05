<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Escaping;

use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::escape() method
 *
 * @covers \Itools\ZenDB\Connection::escape
 */
class EscapeTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
    }

    //region Basic Types

    public function testEscapeString(): void
    {
        $result = DB::escape('test');
        $this->assertSame('test', $result);
    }

    public function testEscapeInteger(): void
    {
        $result = DB::escape(123);
        $this->assertSame('123', $result);
    }

    public function testEscapeFloat(): void
    {
        $result = DB::escape(123.45);
        $this->assertSame('123.45', $result);
    }

    public function testEscapeNull(): void
    {
        $result = DB::escape(null);
        $this->assertSame('', $result);
    }

    public function testEscapeEmptyString(): void
    {
        $result = DB::escape('');
        $this->assertSame('', $result);
    }

    public function testEscapeZero(): void
    {
        $result = DB::escape(0);
        $this->assertSame('0', $result);
    }

    //endregion
    //region Special Characters

    public function testEscapeSingleQuote(): void
    {
        $result = DB::escape("O'Brien");
        $this->assertSame("O\\'Brien", $result);
    }

    public function testEscapeDoubleQuote(): void
    {
        $result = DB::escape('Say "Hello"');
        $this->assertSame('Say \\"Hello\\"', $result);
    }

    public function testEscapeBackslash(): void
    {
        $result = DB::escape('C:\\path\\to\\file');
        $this->assertSame('C:\\\\path\\\\to\\\\file', $result);
    }

    public function testEscapeNewline(): void
    {
        $result = DB::escape("Line1\nLine2");
        $this->assertSame("Line1\\nLine2", $result);
    }

    public function testEscapeCarriageReturn(): void
    {
        $result = DB::escape("Line1\rLine2");
        $this->assertSame("Line1\\rLine2", $result);
    }

    public function testEscapeTab(): void
    {
        // Note: mysqli::real_escape_string does not escape tabs
        $result = DB::escape("Col1\tCol2");
        $this->assertSame("Col1\tCol2", $result);
    }

    public function testEscapeNullByte(): void
    {
        $result = DB::escape("test\0null");
        $this->assertSame("test\\0null", $result);
    }

    public function testEscapeBackspace(): void
    {
        // \x08 is backspace character
        // Note: mysqli::real_escape_string does not escape backspace
        $result = DB::escape("test\x08back");
        $this->assertSame("test\x08back", $result);
    }

    //endregion
    //region LIKE Wildcards

    public function testEscapeLikeWildcardsTrue(): void
    {
        $result = DB::escape('100%', true);
        $this->assertSame('100\\%', $result);
    }

    public function testEscapeLikeWildcardsFalse(): void
    {
        $result = DB::escape('100%', false);
        $this->assertSame('100%', $result);
    }

    public function testEscapePercentSign(): void
    {
        $result = DB::escape('50% off', true);
        $this->assertSame('50\\% off', $result);
    }

    public function testEscapeUnderscore(): void
    {
        $result = DB::escape('user_name', true);
        $this->assertSame('user\\_name', $result);
    }

    public function testEscapeBothLikeWildcards(): void
    {
        $result = DB::escape('%_test_%', true);
        $this->assertSame('\\%\\_test\\_\\%', $result);
    }

    public function testEscapeWildcardsDefaultFalse(): void
    {
        // By default, wildcards should NOT be escaped
        $result = DB::escape('%_test_%');
        $this->assertSame('%_test_%', $result);
    }

    //endregion
    //region SmartString Integration

    public function testEscapeSmartString(): void
    {
        $smart = new SmartString('test');
        $result = DB::escape($smart);
        $this->assertSame('test', $result);
    }

    public function testEscapeSmartStringWithSpecialChars(): void
    {
        $smart = new SmartString("O'Brien");
        $result = DB::escape($smart);
        $this->assertSame("O\\'Brien", $result);
    }

    public function testEscapeSmartStringWithHtml(): void
    {
        $smart = new SmartString('<script>alert("xss")</script>');
        $result = DB::escape($smart);
        // SmartString value is extracted, then escaped for SQL (not HTML)
        $this->assertSame('<script>alert(\\"xss\\")</script>', $result);
    }

    public function testEscapeSmartStringWithWildcards(): void
    {
        $smart = new SmartString('100%');
        $result = DB::escape($smart, true);
        $this->assertSame('100\\%', $result);
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider provideEscapeScenarios
     */
    public function testEscapeScenarios(mixed $input, bool $escapeWildcards, string $expected): void
    {
        $result = DB::escape($input, $escapeWildcards);
        $this->assertSame($expected, $result);
    }

    public static function provideEscapeScenarios(): array
    {
        return [
            'simple string'        => ['hello', false, 'hello'],
            'with quotes'          => ["it's", false, "it\\'s"],
            'with double quotes'   => ['"quoted"', false, '\\"quoted\\"'],
            'integer'              => [42, false, '42'],
            'float'                => [3.14, false, '3.14'],
            'null'                 => [null, false, ''],
            'empty'                => ['', false, ''],
            'percent no escape'    => ['50%', false, '50%'],
            'percent with escape'  => ['50%', true, '50\\%'],
            'underscore no escape' => ['a_b', false, 'a_b'],
            'underscore escape'    => ['a_b', true, 'a\\_b'],
            'complex'              => ["O'Brien said \"hi\"", false, "O\\'Brien said \\\"hi\\\""],
        ];
    }

    //endregion
}
