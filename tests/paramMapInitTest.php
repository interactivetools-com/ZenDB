<?php
/** @noinspection PhpUndefinedFieldInspection */
declare(strict_types=1);

namespace tests;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use ReflectionWrapper;

class addParamsFromArgsTest extends BaseTest
{
    public function testBuildParamMapWithEmptyParams(): void {
        // arrange

        // act
        $db = ReflectionWrapper::for('DB'); // makes private properties and method accessible
        $db->parser->addParamsFromArgs([]);

        // assert
        $this->assertSame(
            expected: [],
            actual:   $db->parser->paramMap,
            message:  'Failed on empty params scenario'
        );
    }

    public function testBuildParamMapWithNoParams(): void { // TODO: Rename this one
        // arrange
        $db = ReflectionWrapper::for('DB'); // makes private properties and method accessible

        // act
        $db->parser->addParamsFromArgs([]);
        $db->parser->addNamedParam(':City', 'Vancouver');

        // assert
        $this->assertSame(
            expected: [':City' => 'Vancouver'],
            actual  : $db->parser->paramMap,
            message : 'Failed on no params scenario'
        );
    }

    public function testBuildParamMapWith1Strings(): void {
        // arrange
        $input    = ['str1'];
        $expected = [':1' => 'str1'];

        // act
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);

        $this->assertSame(
            expected: $expected,
            actual:   $db->parser->paramMap,
            message:  'Failed on 1 strings scenario');

    }
    public function testBuildParamMapWith2Strings(): void {
        $input    = ['str1', 'str2'];
        $expected = [':1' => 'str1', ':2' => 'str2'];

        // act
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);

        $this->assertSame(
            expected: $expected,
            actual:   $db->parser->paramMap,
            message:  'Failed on multiple 2 scenario');
    }
    public function testBuildParamMapWith3Strings(): void {
        $input    = ['str1', 'str2', 'str3'];
        $expected = [':1' => 'str1', ':2' => 'str2', ':3' => 'str3'];

        // act
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);

        $this->assertSame(
            expected: $expected,
            actual:   $db->parser->paramMap,
            message:  'Failed on 3 strings scenario');
    }
    public function testBuildParamMapWithMixedStringAndObjects(): void {
        $obj      = DB::rawSql('NOW()');
        $input    = ['str1', $obj];
        $expected = [':1' => 'str1', ':2' => $obj];

        // act
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);

        $this->assertSame(
            expected: $expected,
            actual:   $db->parser->paramMap,
            message:  'Failed on mixed string and objects scenario');
    }
    public function testBuildParamMapWithTooManyParams(): void {
                // arrange
        $input = ['str1', 'str2', 'str3', 'str4'];

        // act & assert
        $this->expectException(InvalidArgumentException::class);
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);
    }
    public function testBuildParamMapWithArrayAsSecondArg(): void {
        // arrange
        $input = ['str1', ['name' => 'bob']];

        // act && assert
        $this->expectException(InvalidArgumentException::class);
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);
    }

    public function testBuildParamMapWithArrayEmpty(): void {
        $input    = [];
        $expected = [];

        // act
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);

        $this->assertSame(
            expected: $expected,
            actual:   $db->parser->paramMap,
            message:  'Failed on empty array');
    }

    public function testBuildParamMapWithSingleArray(): void {
        // arrange
        $input    = [[':name' => 'John', ':city' => 'NewYork']];
        $expected = [':name' => 'John', ':city' => 'NewYork'];

        // act
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);

        // assert
        $this->assertSame(
            expected: $expected,
            actual:   $db->parser->paramMap,
            message:  'Failed on single array scenario');
    }


    public function testBuildParamMapWithArrayInvalidTypes(): void {
        // arrange
        $input = [[], DB::rawSql('NOW()'), 'str'];

        // act & assert
        $this->expectException(InvalidArgumentException::class);
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);
    }


    public function testBuildParamMapWithArrayInvalidNamedParams(): void {
        // arrange
        $input = [[':name' => 'John', 'invalidKey' => 'NewYork']];

        // act & assert
        $this->expectException(InvalidArgumentException::class);
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);
    }

    public function testBuildParamMapWithArrayMixedNamedAndPositional(): void {
        // arrange
        $input    = [['str1', 'str2', ':name' => 'John', 'str3', 'str4']];
        $expected = [':1' => 'str1', ':2' => 'str2', ':name' => 'John', ':3' => 'str3', ':4' => 'str4'];

        // act
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);

        // assert
        $this->assertSame(
            expected: $expected,
            actual:   $db->parser->paramMap,
            message:  'Failed on mixed named and positional scenario');
    }

    // Scenario where the first argument is an array, and no other arguments are passed
    public function testBuildParamMapWithOnlyArrayArg(): void {
        // arrange
        $input    = [[':name' => 'John', ':city' => 'NewYork']];
        $expected = [':name' => 'John', ':city' => 'NewYork'];

        // act
        $db = ReflectionWrapper::for('DB');
        $db->parser->addParamsFromArgs($input);

        // assert
        $this->assertSame(
            expected: $expected,
            actual:   $db->parser->paramMap,
            message:  'Failed on only array argument scenario');
    }

    // Test for invalid characters in key names in named params
    public function testBuildParamMapWithInvalidCharactersInKeys(): void {
        // arrange
        $input = [[':name' => 'John', ':invalid#key' => 'NewYork']];

        // act & assert
        $db = ReflectionWrapper::for('DB');
        $this->expectException(InvalidArgumentException::class);
        $db->parser->addParamsFromArgs($input);
    }


}
