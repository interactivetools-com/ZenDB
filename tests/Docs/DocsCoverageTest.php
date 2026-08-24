<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Docs;

use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every public method on DB and Connection must appear in the docs method
 * reference, so new methods can't ship undocumented.
 *
 * Exemptions come from the source itself: magic methods are skipped, and so
 * is any method whose docblock carries @internal (library plumbing) or
 * @deprecated (old names keep working but stay out of the docs by design -
 * docs show only the current names).
 */
class DocsCoverageTest extends BaseTestCase
{
    #[DataProvider('publicMethodsProvider')]
    public function testMethodIsInMethodReference(string $method): void
    {
        // Only a backticked signature opening a table row counts as documentation
        // (plain or linked, e.g. `DB::select(...)` or [`DB::select(...)`](guide.md));
        // the method's name appearing inside some other method's example does not
        $reference  = file_get_contents(dirname(__DIR__, 2) . '/docs/method-reference.md');
        $documented = preg_match('/^\|\s*\[?`[^`|]*(::|->)' . preg_quote($method, '/') . '\(/m', $reference);
        $this->assertSame(
            1,
            $documented,
            "Public method $method() has no table row in docs/method-reference.md - document it, or mark it @internal or @deprecated in the source"
        );
    }

    public static function publicMethodsProvider(): array
    {
        $rows = [];
        foreach ([DB::class, Connection::class] as $class) {
            $methods = (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                $name = $method->getName();
                $doc  = $method->getDocComment() ?: '';
                if (str_starts_with($name, '__') || str_contains($doc, '@internal') || str_contains($doc, '@deprecated')) {
                    continue;
                }
                $rows[$name] = [$name]; // same name on DB and Connection = one row; the reference documents them together
            }
        }
        return $rows;
    }
}
