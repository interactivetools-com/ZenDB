<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Support\Replay;

/**
 * The test the suite is currently running, e.g. "Foo\BarTest::testBaz" while a test
 * method runs (fixtures included) and "Foo\BarTest" during setUpBeforeClass and
 * tearDownAfterClass. ReplayExtension keeps it current; the recorder files each query
 * under it and scoped replay looks queries up under it, so every test replays its own
 * recording no matter what order the suite runs in.
 */
final class ReplayScope
{
    public static string $current = '';
}
