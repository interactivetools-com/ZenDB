<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Builtin functions PHP compiles to single opcodes must be imported (or
 * \-prefixed) in src/ files. Unqualified, a builtin call in a namespaced file
 * resolves by name at runtime and compiles to a function call; imported, the
 * is_*() type checks, count(), strlen(), etc. become single opcodes (~4ns
 * faster per call, measured ~7% on escapef()). Only builtins on the
 * compiler's opcode list are enforced - importing others gains ~1ns and is
 * left to taste, but every `use function` import must have a call site so the
 * lists can't rot.
 */
class NativeCallsTest extends TestCase
{
    /**
     * Builtins the PHP compiler turns into dedicated opcodes when the name is
     * resolved at compile time, verified by opcode dump on PHP 8.1
     * (php -d opcache.opt_debug_level=0x20000 file.php - a listed name compiles
     * to TYPE_CHECK/COUNT/STRLEN/CAST etc. instead of INIT_FCALL). Compiler
     * source: zend_try_compile_special_func() in php-src Zend/zend_compile.c.
     *
     * Deliberately absent: builtins that only specialize with constant
     * arguments (in_array with a literal haystack, chr/ord/defined with
     * literal input) plus array_slice, is_callable, and call_user_func* -
     * with variable arguments those compile as normal calls, so an import
     * buys ~1ns, not an opcode.
     */
    private const COMPILER_OPTIMIZED = [
        'array_key_exists', 'boolval', 'count', 'doubleval', 'floatval', 'func_get_args', 'func_num_args',
        'get_called_class', 'get_class', 'gettype', 'intval', 'is_array', 'is_bool',
        'is_double', 'is_float', 'is_int', 'is_integer', 'is_long', 'is_null',
        'is_object', 'is_resource', 'is_scalar', 'is_string', 'sizeof', 'strlen', 'strval',
    ];

    #[DataProvider('srcFilesProvider')]
    public function testCompilerOptimizedCallsAreImported(string $file): void
    {
        ['imports' => $imports, 'calls' => $calls] = self::scanFile($file);

        $uncovered = [];
        foreach ($calls as $name => $lines) {
            if (in_array($name, self::COMPILER_OPTIMIZED, true) && !isset($imports[$name])) {
                $uncovered[] = "$name() on line " . implode(', ', $lines);
            }
        }
        $this->assertSame([], $uncovered, basename($file) . " calls compiler-optimized builtins without importing them - add to the `use function` list: " . implode('; ', $uncovered));
    }

    #[DataProvider('srcFilesProvider')]
    public function testEveryFunctionImportHasACallSite(string $file): void
    {
        ['imports' => $imports, 'calls' => $calls] = self::scanFile($file);

        $unused = array_diff_key($imports, $calls);
        $this->assertSame([], $unused, basename($file) . " imports functions it never calls - remove from the `use function` list: " . implode(', ', array_keys($unused)));
    }

    public static function srcFilesProvider(): array
    {
        $rows = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/*.php') as $file) {
            $rows[basename($file)] = [$file];
        }
        return $rows;
    }

    /**
     * Tokenizes one file into its `use function` imports and its unqualified
     * builtin call sites (\-prefixed calls resolve at compile time already, so
     * they don't need an import and don't count as one's call site).
     *
     * @return array{imports: array<string, true>, calls: array<string, int[]>}
     */
    private static function scanFile(string $file): array
    {
        $builtins = array_flip(get_defined_functions()['internal']);
        $tokens   = token_get_all(file_get_contents($file));

        $imports = [];
        $calls   = [];
        $prev    = null; // last significant token id or char
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                $prev = $token;
                continue;
            }
            [$id, $text, $line] = $token;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            // `use function a, b;` - collect names until the closing semicolon
            if ($id === T_FUNCTION && $prev === T_USE) {
                for ($j = $i + 1; $j < count($tokens) && $tokens[$j] !== ';'; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $imports[strtolower($tokens[$j][1])] = true;
                    }
                }
            }

            // unqualified call to a builtin: bare name followed by "("
            if ($id === T_STRING && isset($builtins[strtolower($text)])
                && !in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NS_SEPARATOR, T_CONST, T_USE, T_INSTANCEOF], true)
            ) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if ($tokens[$j] === '(') {
                        $calls[strtolower($text)][] = $line;
                    }
                    break;
                }
            }

            $prev = $id;
        }

        return ['imports' => $imports, 'calls' => $calls];
    }
}
