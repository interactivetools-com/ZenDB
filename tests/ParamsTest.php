<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded
require_once __DIR__ . '/bootstrap.php';

use InvalidArgumentException;
use RuntimeException;
use Itools\ZenDB\Params;
use Itools\ZenDB\RawSql;
use Itools\SmartString\SmartString;
use Itools\SmartArray\SmartArray;
use Itools\SmartArray\SmartNull;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Params class functionality
 */
class ParamsTest extends TestCase
{
    /**
     * @dataProvider provideParamsFromArgsScenarios
     */
    public function testBuildParamMapFromArgs(array $input, array $expected, ?string $exceptionClass = null): void
    {
        // arrange
        $params = new Params();

        // If we expect an exception, test for it
        if ($exceptionClass) {
            $this->expectException($exceptionClass);
            $params->addParamsFromArgs($input);
            return;
        }

        // act
        $params->addParamsFromArgs($input);

        // assert
        $this->assertSame(
            expected: $expected,
            actual: $params->paramMap,
            message: 'Parameter map does not match expected values'
        );
    }

    public function provideParamsFromArgsScenarios(): array
    {
        $rawSql = new RawSql('NOW()');
        $smartArray = $this->createMock(SmartArray::class);
        
        return [
            'empty params' => [
                'input' => [],
                'expected' => [],
            ],
            'single string' => [
                'input' => ['str1'],
                'expected' => [':1' => 'str1'],
            ],
            'two strings' => [
                'input' => ['str1', 'str2'],
                'expected' => [':1' => 'str1', ':2' => 'str2'],
            ],
            'three strings' => [
                'input' => ['str1', 'str2', 'str3'],
                'expected' => [':1' => 'str1', ':2' => 'str2', ':3' => 'str3'],
            ],
            'mixed string and object' => [
                'input' => ['str1', $rawSql],
                'expected' => [':1' => 'str1', ':2' => $rawSql],
            ],
            'too many params' => [
                'input' => ['str1', 'str2', 'str3', 'str4'],
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
            'array as second arg' => [
                'input' => ['str1', ['name' => 'bob']],
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
            'single array with named params' => [
                'input' => [[':name' => 'John', ':city' => 'NewYork']],
                'expected' => [':name' => 'John', ':city' => 'NewYork'],
            ],
            'array with invalid types' => [
                'input' => [[], $rawSql, 'str'],
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
            'array with invalid named params' => [
                'input' => [[':name' => 'John', 'invalidKey' => 'NewYork']],
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
            'array with mixed named and positional params' => [
                'input' => [['str1', 'str2', ':name' => 'John', 'str3', 'str4']],
                'expected' => [':1' => 'str1', ':2' => 'str2', ':name' => 'John', ':3' => 'str3', ':4' => 'str4'],
            ],
            'array with invalid character in key name' => [
                'input' => [[':name' => 'John', ':invalid#key' => 'NewYork']],
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
        ];
    }

    /**
     * @dataProvider providePositionalParamScenarios
     */
    public function testAddPositionalParam($value, array $expected, ?string $exceptionClass = null): void
    {
        // arrange
        $params = new Params();

        // If we expect an exception, test for it
        if ($exceptionClass) {
            $this->expectException($exceptionClass);
            $params->addPositionalParam($value);
            return;
        }

        // act
        $params->addPositionalParam($value);

        // assert
        $this->assertSame(
            expected: $expected,
            actual: $params->paramMap
        );
    }

    public function providePositionalParamScenarios(): array
    {
        $smartString = $this->createMock(SmartString::class);
        $smartString->method('value')->willReturn('extracted_value');
        
        $smartArray = $this->createMock(SmartArray::class);
        
        $rawSql = new RawSql('NOW()');
        
        return [
            'string value' => [
                'value' => 'value1',
                'expected' => [':1' => 'value1'],
            ],
            'integer value' => [
                'value' => 123,
                'expected' => [':1' => 123],
            ],
            'boolean value' => [
                'value' => true,
                'expected' => [':1' => true],
            ],
            'null value' => [
                'value' => null,
                'expected' => [':1' => null],
            ],
            'RawSql object' => [
                'value' => $rawSql,
                'expected' => [':1' => $rawSql],
            ],
            'SmartString object' => [
                'value' => $smartString,
                'expected' => [':1' => 'extracted_value'],
            ],
            'SmartArray object' => [
                'value' => $smartArray,
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
        ];
    }

    /**
     * Test chaining of positional parameters
     */
    public function testChainedPositionalParams(): void
    {
        // arrange
        $params = new Params();

        // act
        $result = $params->addPositionalParam('value1')
                         ->addPositionalParam(123)
                         ->addPositionalParam(true);

        // assert
        $this->assertSame(
            expected: [':1' => 'value1', ':2' => 123, ':3' => true],
            actual: $params->paramMap
        );
        $this->assertInstanceOf(Params::class, $result, 'Method chaining should return instance of Params');
    }
    
    /**
     * @dataProvider provideNamedParamScenarios
     */
    public function testAddNamedParam(string $name, $value, array $initialParams, array $expected, ?string $exceptionClass = null): void
    {
        // arrange
        $params = new Params();
        
        // Set up any initial parameters
        foreach ($initialParams as $paramName => $paramValue) {
            $params->addNamedParam($paramName, $paramValue);
        }

        // If we expect an exception, test for it
        if ($exceptionClass) {
            $this->expectException($exceptionClass);
            $params->addNamedParam($name, $value);
            return;
        }

        // act
        $params->addNamedParam($name, $value);

        // assert
        $this->assertSame(
            expected: $expected,
            actual: $params->paramMap
        );
    }

    public function provideNamedParamScenarios(): array
    {
        $smartString = $this->createMock(SmartString::class);
        $smartString->method('value')->willReturn('extracted_value');
        
        $rawSql = new RawSql('NOW()');
        
        return [
            'string value' => [
                'name' => ':name',
                'value' => 'John',
                'initialParams' => [],
                'expected' => [':name' => 'John'],
            ],
            'integer value' => [
                'name' => ':age',
                'value' => 30,
                'initialParams' => [],
                'expected' => [':age' => 30],
            ],
            'boolean value' => [
                'name' => ':active',
                'value' => true,
                'initialParams' => [],
                'expected' => [':active' => true],
            ],
            'null value' => [
                'name' => ':nullable',
                'value' => null,
                'initialParams' => [],
                'expected' => [':nullable' => null],
            ],
            'RawSql object' => [
                'name' => ':created',
                'value' => $rawSql,
                'initialParams' => [],
                'expected' => [':created' => $rawSql],
            ],
            'SmartString object' => [
                'name' => ':smartstring',
                'value' => $smartString,
                'initialParams' => [],
                'expected' => [':smartstring' => 'extracted_value'],
            ],
            'invalid name without colon' => [
                'name' => 'name',
                'value' => 'John',
                'initialParams' => [],
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
            'reserved prefix' => [
                'name' => ':zdb_reserved',
                'value' => 'value',
                'initialParams' => [],
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
            'duplicate name' => [
                'name' => ':name',
                'value' => 'Different',
                'initialParams' => [':name' => 'John'],
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
        ];
    }

    /**
     * Test chaining of named parameters
     */
    public function testChainedNamedParams(): void
    {
        // arrange
        $params = new Params();

        // act
        $result = $params->addNamedParam(':name', 'John')
                         ->addNamedParam(':age', 30)
                         ->addNamedParam(':active', true);

        // assert
        $this->assertSame(
            expected: [':name' => 'John', ':age' => 30, ':active' => true],
            actual: $params->paramMap
        );
        $this->assertInstanceOf(Params::class, $result, 'Method chaining should return instance of Params');
    }

    /**
     * @dataProvider provideInternalParamScenarios
     */
    public function testAddInternalParam(string $name, $value, array $expected, ?string $exceptionClass = null): void
    {
        // arrange
        $params = new Params();

        // If we expect an exception, test for it
        if ($exceptionClass) {
            $this->expectException($exceptionClass);
            $params->addInternalParam($name, $value);
            return;
        }

        // act
        $params->addInternalParam($name, $value);

        // assert
        $this->assertSame(
            expected: $expected,
            actual: $params->paramMap
        );
    }

    public function provideInternalParamScenarios(): array
    {
        return [
            'valid internal param string' => [
                'name' => ':zdb_param1',
                'value' => 'value1',
                'expected' => [':zdb_param1' => 'value1'],
            ],
            'valid internal param integer' => [
                'name' => ':zdb_param2',
                'value' => 123,
                'expected' => [':zdb_param2' => 123],
            ],
            'invalid prefix' => [
                'name' => ':regular_param',
                'value' => 'value',
                'expected' => [],
                'exceptionClass' => InvalidArgumentException::class,
            ],
        ];
    }

    /**
     * Test chaining of internal parameters
     */
    public function testChainedInternalParams(): void
    {
        // arrange
        $params = new Params();

        // act
        $result = $params->addInternalParam(':zdb_param1', 'value1')
                         ->addInternalParam(':zdb_param2', 123);

        // assert
        $this->assertSame(
            expected: [':zdb_param1' => 'value1', ':zdb_param2' => 123],
            actual: $params->paramMap
        );
        $this->assertInstanceOf(Params::class, $result, 'Method chaining should return instance of Params');
    }

    /**
     * Test mixed parameter types and chaining
     */
    public function testMixedParamTypesAndChaining(): void
    {
        // arrange
        $params = new Params();
        $rawSql = new RawSql('NOW()');

        // act
        $result = $params->addPositionalParam('value1')
                         ->addNamedParam(':name', 'John')
                         ->addPositionalParam($rawSql)
                         ->addInternalParam(':zdb_param', 123)
                         ->finalizeQuery(); // Verify finalizeQuery continues the chain

        // assert
        $this->assertSame(
            expected: [
                ':1' => 'value1',
                ':name' => 'John',
                ':2' => $rawSql,
                ':zdb_param' => 123
            ],
            actual: $params->paramMap
        );
        $this->assertInstanceOf(Params::class, $result, 'Method chaining should return instance of Params');
    }
}