<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Debugging;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests that the password and encryption key stay out of exception stack traces.
 * PHP 8.1 ignores #[\SensitiveParameter], so the redaction applies on 8.2+.
 */
class TraceRedactionTest extends BaseTestCase
{
    private string|false $savedIgnoreArgs;

    protected function setUp(): void
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('#[\SensitiveParameter] needs PHP 8.2+');
        }

        // Production php.ini drops trace args entirely; turn them on so there's something to check
        $this->savedIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
        DB::disconnect();
    }

    protected function tearDown(): void
    {
        if (PHP_VERSION_ID >= 80200) {
            ini_set('zend.exception_ignore_args', (string)$this->savedIgnoreArgs);
            DB::disconnect();
            DB::connect(self::$configDefaults);
        }
    }

    public function testConnectFailureTraceHasNoSecrets(): void
    {
        $config = array_merge(self::$configDefaults, [
            'password'      => 'sentinel-password-7f3a',
            'encryptionKey' => 'sentinel-key-9c1e',
            'unknownOption' => true,  // throws after the credentials are read
        ]);

        try {
            DB::connect($config);
            $this->fail('Expected InvalidArgumentException for the unknown config key');
        } catch (InvalidArgumentException $e) {
            // Only ZenDB's frames; PHPUnit's frames above them don't see the config
            $zendbArgs = [];
            foreach ($e->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (str_starts_with($class, 'Itools\ZenDB\\') && !str_starts_with($class, 'Itools\ZenDB\Tests\\')) {
                    $zendbArgs["{$class}::{$frame['function']}"] = $frame['args'] ?? [];
                }
            }
            $dump = print_r($zendbArgs, true);

            $this->assertArrayHasKey('Itools\ZenDB\DB::connect', $zendbArgs, 'Trace should include the DB::connect frame');
            $this->assertStringContainsString('SensitiveParameterValue', $dump, 'Trace should show redacted args');
            $this->assertStringNotContainsString('sentinel-password-7f3a', $dump, 'Password must not appear in trace args');
            $this->assertStringNotContainsString('sentinel-key-9c1e', $dump, 'Encryption key must not appear in trace args');
        }
    }
}
