<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Security;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DB::h(), the HTML encoder for values in error messages
 *
 * @covers \Itools\ZenDB\DB::h
 */
class HtmlEncodeTest extends TestCase
{
    public function testPlainTextPassesThrough(): void
    {
        $this->assertSame('users', DB::h('users'));
    }

    public function testEncodesHtmlSpecialChars(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', DB::h('<script>alert(1)</script>'));
        $this->assertSame('&amp;', DB::h('&'));
    }

    public function testEncodesBothQuoteStyles(): void
    {
        $this->assertSame('&quot;', DB::h('"'));
        $this->assertSame('&apos;', DB::h("'"));
    }

    public function testSubstitutesInvalidUtf8(): void
    {
        // ENT_SUBSTITUTE: undecodable bytes become U+FFFD
        $this->assertSame("caf\u{FFFD}", DB::h("caf\xE9"));
    }

    public function testSubstitutesDisallowedCodePoints(): void
    {
        // ENT_DISALLOWED: code points HTML5 forbids (here a C1 control) become U+FFFD
        $this->assertSame("a\u{FFFD}b", DB::h("a\u{0081}b"));
    }

    public function testCoercesNonStrings(): void
    {
        $this->assertSame('', DB::h(null));
        $this->assertSame('42', DB::h(42));
    }

    public function testInvalidIdentifierMessageIsEncoded(): void
    {
        try {
            DB::assertIdentifier('<script>bad');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('&lt;script&gt;bad', $e->getMessage());
            $this->assertStringNotContainsString('<script>', $e->getMessage());
        }
    }
}
