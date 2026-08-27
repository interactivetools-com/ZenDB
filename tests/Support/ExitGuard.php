<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Fail the run when a test calls exit() or die() before PHPUnit finishes. Registered in phpunit.xml.dist.
 *
 * exit() ends the whole PHP process with exit code 0 and no summary line, so the rest of the suite never
 * runs and CI still reports green. At shutdown this prints the test that was running and exits with 1,
 * unless PHPUnit reached the end of the run first.
 *
 * Shared byte-for-byte across SmartArray, SmartString, and ZenDB like SharedTestHelpers.php: only the
 * namespace line differs. Edit every copy or none.
 */
final class ExitGuard implements Extension
{
    public static ?string $runningTest = null;
    public static bool    $runFinished = false;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparedSubscriber {
            public function notify(Prepared $event): void
            {
                ExitGuard::$runningTest = $event->test()->id();
            }
        });
        $facade->registerSubscriber(new class implements ExecutionFinishedSubscriber {
            public function notify(ExecutionFinished $event): void
            {
                ExitGuard::$runFinished = true;
            }
        });
        register_shutdown_function(static function (): void {
            if (self::$runFinished) {
                return;
            }
            $test = self::$runningTest ?? '(no test was running)';
            fwrite(STDERR, "\n\nERROR: PHP stopped before PHPUnit finished the run. A test probably called exit() or die(): $test\n");
            exit(1);
        });
    }
}
