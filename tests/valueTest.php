<?php
/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection SqlIdentifier */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);
namespace tests;

use Itools\SmartString\SmartString;

class valueTest extends BaseTest
{

    public function testEncoders(): void {
        // arrange
        $testString = "<Hello> 'World' & \"Goodbye\" # @ ? = + %20";
        $expectedHtml = '&lt;Hello&gt; &apos;World&apos; &amp; &quot;Goodbye&quot; # @ ? = + %20';
        $expectedJs   = '\<Hello\> \\\'World\\\' & \"Goodbye\" # @ ? = + %20';
        $expectedUrl  = '%3CHello%3E+%27World%27+%26+%22Goodbye%22+%23+%40+%3F+%3D+%2B+%2520';

        // act
        $value = new SmartString($testString);

        // assert
        $this->assertInstanceOf(SmartString::class, $value);
        $this->assertSame($expectedHtml, (string) $value);
        $this->assertSame($expectedHtml, $value->htmlEncode());
        $this->assertSame($expectedJs, $value->jsEncode());
        $this->assertSame($expectedUrl, $value->urlEncode());
        $this->assertSame($testString, $value->value());
    }

    public function testNonStringValues(): void {
        // arrange
        $int   = 123;
        $float = 123.456;
        $null  = null;

        // act & assert

        // test int
        $original = $int;
        $actual   = new SmartString($original);
        $expected = (string) $int;
        $this->assertSame($expected, (string)$actual);
        $this->assertSame($expected, $actual->htmlEncode());
        $this->assertSame($expected, (string)$actual->jsEncode());
        $this->assertSame($expected, $actual->urlEncode());
        $this->assertSame($original, $actual->value());

        // test float
        $original = $float;
        $actual   = new SmartString($original);
        $expected = (string) $float;
        $this->assertSame($expected, (string)$actual);
        $this->assertSame($expected, $actual->htmlEncode());
        $this->assertSame($expected, (string)$actual->jsEncode());
        $this->assertSame($expected, $actual->urlEncode());
        $this->assertSame($original, $actual->value());

        // test null
        $original = $null;
        $actual   = new SmartString($original);
        $expected = (string) $null;
        $this->assertSame($expected, (string)$actual);
        $this->assertSame($expected, $actual->htmlEncode());
        $this->assertSame($expected, (string)$actual->jsEncode());
        $this->assertSame($expected, $actual->urlEncode());
        $this->assertSame($original, $actual->value());
    }

}
