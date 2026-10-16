<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Security;

use Closure;
use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exception messages that repeat caller text keep that text before any quote character in the
 * message. An error handler can print a message inside a quoted HTML attribute, and a quote the
 * message puts before the text would end that attribute and turn the text into new attributes.
 */
class MessageQuotingTest extends BaseTestCase
{
    private const PAYLOAD = 'x autofocus onfocus=alert(1) y';

    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTestTables();
    }

    public static function messageProvider(): array
    {
        return [
            'value quoted in the template' => [static fn() => DB::select('users', "name = '" . self::PAYLOAD . "'")],
            'backtick identifier value'    => [static fn() => DB::select('users', "ORDER BY `:sort`", [':sort' => self::PAYLOAD])],
        ];
    }

    #[DataProvider('messageProvider')]
    public function testCallerTextComesBeforeAnyQuote(Closure $run): void
    {
        $message = '';
        try {
            $run();
            $this->fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $valueEnd = strpos($message, self::PAYLOAD);
        $this->assertNotFalse($valueEnd, "the message should name the value: $message");
        $throughValue = substr($message, 0, $valueEnd + strlen(self::PAYLOAD));
        $this->assertDoesNotMatchRegularExpression('/[\'"]/', $throughValue, "a quote before the value can end an HTML attribute: $message");
    }
}
