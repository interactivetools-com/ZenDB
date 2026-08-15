<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Source;

use InteractiveTools\Standards\NamespacedCallsCheck;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_unique, class_exists, dirname, file_get_contents, file_put_contents, implode, is_file, ltrim, sort, sprintf, str_replace, sys_get_temp_dir, tempnam, unlink;

/**
 * Fails when a namespaced file does not import exactly the built-in functions
 * and constants it uses.
 *
 * Three things are reported: a built-in used with no import, a built-in written
 * with a leading backslash, and a built-in imported but never used. The result
 * is a `use function` line per file listing exactly what it calls, with a
 * `use const` line directly below when the file reads built-in constants.
 *
 * The originals of this file and NamespacedCallsCheck.php live in the
 * internal docs repo under programming/; each repo carries copies side by
 * side in its source-tests folder (tests/Source, or tests/Integration where
 * that split exists). The checker is byte-identical in every copy including
 * the original; this file changes only the namespace line (CMS Builder also
 * adjusts SCAN_PATHS to its layout). Edit the originals and re-copy rather
 * than editing a copy; the release checklist in
 * open-source/repo-standards.md compares them.
 *
 * To fix everything the test reports:
 *
 *     php path/to/NamespacedCallsCheck.php --fix src/
 *
 * Background, and what the saving actually is:
 * micro-optimizations.md
 */
class NamespacedCallsTest extends TestCase
{
    /**
     * Paths to scan, relative to the repo root: the shipped source folders
     * only, on purpose. The import is a hot-path optimization, and test code
     * should not pay an import-line tax on every new file. This file and the
     * checker still follow the rule; the self-test below holds them to it.
     */
    private const SCAN_PATHS = ['src'];

    /**
     * Files exempt from the rule, relative to the repo root.
     *
     * Add a reason on every entry. An exemption without one becomes permanent
     * by accident, because nobody knows whether it is safe to remove.
     */
    private const ALLOWED = [
        // 'src/Legacy/Whatever.php' => 'generated file, regenerated on build',
    ];

    /** Where NamespacedCallsCheck.php sits, relative to this file. Ignored when an autoloader finds it. */
    private const CHECKER_PATH = '/NamespacedCallsCheck.php';

    public static function setUpBeforeClass(): void
    {
        if (class_exists(NamespacedCallsCheck::class)) {
            return;
        }

        $path = __DIR__ . self::CHECKER_PATH;
        if (!is_file($path)) {
            self::fail("NamespacedCallsCheck.php is not at $path. Point CHECKER_PATH at it in " . __FILE__ . '.');
        }
        require_once $path;
    }

    public function testProjectFilesImportExactlyWhatTheyCall(): void
    {
        $root     = dirname(__DIR__, 2);
        $problems = [];

        foreach (self::SCAN_PATHS as $path) {
            foreach (NamespacedCallsCheck::scanPath("$root/$path") as $file => $findings) {
                $relative = ltrim(str_replace($root, '', $file), '/\\');
                if (isset(self::ALLOWED[$relative])) {
                    continue;
                }
                $problems[$relative] = self::describe($findings);
            }
        }

        $this->assertSame([], $problems, self::explain($problems));
    }

    /**
     * The checker and this test follow the rule they enforce.
     *
     * SCAN_PATHS covers them in most layouts, but a repo that narrows it, or
     * keeps the checker somewhere else, would quietly stop checking the one file
     * whose whole job is this. Naming both files directly keeps that honest.
     */
    public function testTheCheckerAndThisTestFollowTheirOwnRule(): void
    {
        $files = [
            (new ReflectionClass(NamespacedCallsCheck::class))->getFileName(),
            (new ReflectionClass(self::class))->getFileName(),
        ];

        foreach ($files as $file) {
            $findings = NamespacedCallsCheck::scanFile((string)$file);
            $this->assertSame(
                [],
                $findings,
                sprintf("%s does not follow the rule it enforces:\n  %s", $file, self::describe($findings)),
            );
        }
    }

    /**
     * A method named after a built-in does not claim the name for its file: the
     * class keeps its count() method while the file imports and calls the global
     * count(). The method declaration used to blacklist the name, which made
     * --fix silently drop the import.
     */
    public function testMethodNamedAfterABuiltinDoesNotClaimTheName(): void
    {
        $source = <<<'__PHP__'
            <?php
            namespace Example;

            class Collection
            {
                public function count(): int
                {
                    return count($this->items);
                }
            }
            __PHP__;

        $file = (string)tempnam(sys_get_temp_dir(), 'ncc');
        try {
            file_put_contents($file, $source);
            $this->assertSame('missing: count', self::describe(NamespacedCallsCheck::scanFile($file)));

            $this->assertSame(['count'], NamespacedCallsCheck::fixFile($file));
            $this->assertSame([], NamespacedCallsCheck::scanFile($file));
            $this->assertStringContainsString('use function count;', (string)file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    /**
     * A file whose namespace line is indented still gets its import line, and
     * the inserted line matches that indentation. The insert used to require
     * the namespace at column 0, so --fix printed "(none needed)" on such a
     * file while the scan kept flagging it.
     */
    public function testIndentedNamespaceStillGetsAnImportLine(): void
    {
        $source = <<<'__PHP__'
            <?php
                namespace Example;

                $names = array_map('trim', $names);
            __PHP__;

        $file = (string)tempnam(sys_get_temp_dir(), 'ncc');
        try {
            file_put_contents($file, $source);
            $this->assertSame(['array_map'], NamespacedCallsCheck::fixFile($file));
            $this->assertSame([], NamespacedCallsCheck::scanFile($file));
            $this->assertStringContainsString("\n    use function array_map;\n", (string)file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    /**
     * Built-in constants follow the same rule as functions: an unqualified
     * read gets a `use const` line, placed directly below `use function` with
     * no blank line between, and a backslashed read loses its backslash.
     */
    public function testBuiltinConstantsGetTheirOwnImportLine(): void
    {
        $source = <<<'__PHP__'
            <?php
            namespace Example;

            function newest(array $names): array
            {
                sort($names, \SORT_STRING);
                return $names;
            }
            __PHP__;

        $file = (string)tempnam(sys_get_temp_dir(), 'ncc');
        try {
            file_put_contents($file, $source);
            $this->assertSame('missing: sort; qualified: const SORT_STRING', self::describe(NamespacedCallsCheck::scanFile($file)));

            $this->assertSame(['sort', 'const SORT_STRING'], NamespacedCallsCheck::fixFile($file));
            $this->assertSame([], NamespacedCallsCheck::scanFile($file));
            $fixed = (string)file_get_contents($file);
            $this->assertStringContainsString("use function sort;\nuse const SORT_STRING;\n", $fixed);
            $this->assertStringContainsString('sort($names, SORT_STRING);', $fixed);
        } finally {
            unlink($file);
        }
    }

    /**
     * A constant the file declares at namespace level keeps its name, because
     * the reads mean the namespaced one and importing the global one would
     * change them. A class constant claims nothing, same as a method: a bare
     * read next to it still means the global constant.
     */
    public function testDeclaredConstantsKeepTheirName(): void
    {
        $source = <<<'__PHP__'
            <?php
            namespace Example;

            const SORT_STRING = 'name';

            class Page
            {
                public const PHP_EOL = '<br>';

                public function render(): string
                {
                    return SORT_STRING . PHP_EOL;
                }
            }
            __PHP__;

        $file = (string)tempnam(sys_get_temp_dir(), 'ncc');
        try {
            file_put_contents($file, $source);
            $this->assertSame('missing: const PHP_EOL', self::describe(NamespacedCallsCheck::scanFile($file)));

            $this->assertSame(['const PHP_EOL'], NamespacedCallsCheck::fixFile($file));
            $this->assertSame([], NamespacedCallsCheck::scanFile($file));
            $this->assertStringContainsString("use const PHP_EOL;\n", (string)file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    /** @param list<array{type: string, kind: string, name: string, line: int}> $findings */
    private static function describe(array $findings): string
    {
        $parts = [];
        foreach (['missing', 'qualified', 'unused'] as $type) {
            $names = [];
            foreach ($findings as $finding) {
                if ($finding['type'] === $type) {
                    $names[] = $finding['kind'] === 'constant' ? "const $finding[name]" : $finding['name'];
                }
            }
            if ($names !== []) {
                $names = array_unique($names);
                sort($names);
                $parts[] = "$type: " . implode(', ', $names);
            }
        }
        return implode('; ', $parts);
    }

    /** @param array<string, string> $problems */
    private static function explain(array $problems): string
    {
        if ($problems === []) {
            return '';
        }

        $lines   = ['These namespaced files do not import exactly the built-in functions'];
        $lines[] = 'and constants they use, so PHP resolves those names at runtime:';
        $lines[] = '';
        foreach ($problems as $file => $detail) {
            $lines[] = "  $file";
            $lines[] = "    $detail";
        }
        $lines[] = '';
        $lines[] = 'Fix with:  php ' . (new ReflectionClass(NamespacedCallsCheck::class))->getFileName()
            . ' --fix ' . implode(' ', self::SCAN_PATHS);

        return implode("\n", $lines);
    }
}
