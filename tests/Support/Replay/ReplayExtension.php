<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support\Replay;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Keeps ReplayScope::$current pointing at the running test (registered in phpunit.xml):
 *
 * - suite started        -> the suite name, so setUpBeforeClass queries file under the class
 * - test about to run    -> the test id, before setUp() so fixture queries file under the test
 * - test finished        -> back to the class, so tearDownAfterClass queries file under the class
 *
 * Always registered; outside record/replay mode the scope is set and never read.
 */
final class ReplayExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements StartedSubscriber {
                public function notify(Started $event): void
                {
                    ReplayScope::$current = $event->testSuite()->name();
                }
            },
            new class implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    ReplayScope::$current = $event->test()->id();
                }
            },
            new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    $test = $event->test();
                    if ($test->isTestMethod()) {
                        /** @var \PHPUnit\Event\Code\TestMethod $test */
                        ReplayScope::$current = $test->className();
                    }
                }
            },
        );
    }
}
