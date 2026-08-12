<?php
declare(strict_types=1);

namespace InteractiveTools\Standards;

use ReflectionFunction;

use function array_column, array_diff_key, array_filter, array_keys, array_pop, array_slice, array_values, basename, count, end, explode, file_get_contents, file_put_contents, function_exists, fwrite, implode, in_array, is_array, is_file, ksort, ltrim, preg_match, preg_match_all, preg_replace_callback, printf, realpath, sort, str_contains, strlen, strtolower, substr_replace, token_get_all, trim, usort;

/**
 * Keeps built-in function calls in namespaced files resolved at compile time,
 * with one import line per file and no backslashes at the call sites.
 *
 * Inside a namespace, `strlen($s)` compiles to a runtime lookup: PHP checks for
 * `YourNamespace\strlen` first and falls back to the global one. Importing the
 * name settles it at compile time. `\strlen($s)` settles it too and costs the
 * same, but scatters the decision across every call site, so the house style is
 * the import. See micro-optimizations-namespaced-calls.md for what it is worth.
 *
 * Three things are reported, and the import line ends up listing exactly the
 * built-ins the file calls:
 *
 *   missing    a built-in called unqualified with no import
 *   qualified  a built-in called with a leading backslash; import it instead
 *   unused     a built-in imported but never called
 *
 * The tokenizer says how each call was written and the interpreter says which
 * names are built-in, so this mostly stays correct on PHP versions that did not
 * exist when it was written. The one list it carries, LATE_BUILTINS, covers the
 * gap that opens when the test runs on an older PHP than the code supports.
 *
 *     $findings = NamespacedCallsCheck::scanPath('src/');
 *     NamespacedCallsCheck::fixFile('src/Thing.php');
 *
 * The original of this file lives in the internal docs repo under
 * programming/; SmartString, SmartArray, ZenDB, and CMS Builder each carry a
 * byte-identical copy next to their NamespacedCallsTest.php. Edit the
 * original and re-copy it to every repo rather than editing a copy; the
 * release checklist in open-source/repo-standards.md compares the copies.
 *
 * Point a test at it, or run it directly:
 *
 *     php NamespacedCallsCheck.php src/ lib/          # report
 *     php NamespacedCallsCheck.php --fix src/         # rewrite the imports
 */
class NamespacedCallsCheck
{
    /**
     * Built-ins that do not exist in every supported PHP version.
     *
     * Everything else is detected by asking the interpreter, which is what keeps
     * this correct when PHP adds a function. That breaks down in one place: a
     * test running on 8.1 cannot see that `str_increment` is a built-in on 8.3,
     * so a call to it looks like userland code and never gets flagged, and the
     * lookup then costs on newer versions forever with nothing to catch it.
     *
     * These names close that gap. `use function` is only a compile-time alias,
     * so importing a name the running version does not have is harmless.
     *
     * Regenerate by diffing `get_defined_functions()['internal']` across every
     * supported version, keeping only functions from extensions that all of
     * those builds load, so a build difference is not mistaken for a version
     * one. Language constructs (exit, die, clone) are left out: they never
     * arrive as a plain name token, so they can never be matched here.
     */
    private const LATE_BUILTINS = [
        'array_all', 'array_any', 'array_find', 'array_find_key', 'array_first', 'array_is_list',
        'array_last', 'enum_exists', 'fdatasync', 'fpow', 'fsync', 'get_error_handler',
        'get_exception_handler', 'http_clear_last_response_headers',
        'http_get_last_response_headers', 'ini_parse_quantity', 'json_validate',
        'libxml_get_external_entity_loader', 'memory_reset_peak_usage',
        'openssl_cipher_key_length', 'pcntl_getcpu', 'pcntl_getcpuaffinity',
        'pcntl_setcpuaffinity', 'pcntl_setns', 'pcntl_waitid', 'request_parse_body',
        'sodium_crypto_core_ristretto255_add', 'sodium_crypto_core_ristretto255_from_hash',
        'sodium_crypto_core_ristretto255_is_valid_point', 'sodium_crypto_core_ristretto255_random',
        'sodium_crypto_core_ristretto255_scalar_add',
        'sodium_crypto_core_ristretto255_scalar_complement',
        'sodium_crypto_core_ristretto255_scalar_invert',
        'sodium_crypto_core_ristretto255_scalar_mul',
        'sodium_crypto_core_ristretto255_scalar_negate',
        'sodium_crypto_core_ristretto255_scalar_random',
        'sodium_crypto_core_ristretto255_scalar_reduce',
        'sodium_crypto_core_ristretto255_scalar_sub', 'sodium_crypto_core_ristretto255_sub',
        'sodium_crypto_scalarmult_ristretto255', 'sodium_crypto_scalarmult_ristretto255_base',
        'sodium_crypto_stream_xchacha20', 'sodium_crypto_stream_xchacha20_keygen',
        'sodium_crypto_stream_xchacha20_xor', 'sodium_crypto_stream_xchacha20_xor_ic',
        'str_decrement', 'str_increment', 'stream_context_set_options',
    ];

    #region Scanning

    /**
     * @return list<array{type: string, function: string, line: int}> Empty when
     *         the file is clean or is not in a namespace.
     */
    public static function scanFile(string $path): array
    {
        return self::scanSource((string)file_get_contents($path));
    }

    /** @return list<array{type: string, function: string, line: int}> */
    public static function scanSource(string $source): array
    {
        $tokens = token_get_all($source);
        if (!self::isNamespaced($tokens)) {
            return [];
        }

        $called   = self::calledBuiltins($tokens);
        $imported = self::importedBuiltins($tokens);
        $declared = self::declaredFunctions($tokens);

        // The file defines these itself, so the global versions are not what its
        // calls mean and importing them would change or break the code.
        $called   = array_diff_key($called, $declared);
        $imported = array_diff_key($imported, $declared);
        $findings = [];

        foreach ($called as $name => $call) {
            if ($call['qualified']) {
                $findings[] = ['type' => 'qualified', 'function' => $name, 'line' => $call['line']];
            } elseif (!isset($imported[$name])) {
                $findings[] = ['type' => 'missing', 'function' => $name, 'line' => $call['line']];
            }
        }

        foreach ($imported as $name => $line) {
            if (!isset($called[$name])) {
                $findings[] = ['type' => 'unused', 'function' => $name, 'line' => $line];
            }
        }

        usort($findings, static fn(array $a, array $b): int => $a['line'] <=> $b['line']);

        return $findings;
    }

    /**
     * Scans a directory tree, or a single file, for .php files.
     *
     * @return array<string, list<array{type: string, function: string, line: int}>>
     *         Keyed by path, containing only files with findings.
     */
    public static function scanPath(string $path): array
    {
        $results = [];
        foreach (self::phpFiles($path) as $file) {
            $found = self::scanFile($file);
            if ($found !== []) {
                $results[$file] = $found;
            }
        }
        ksort($results);
        return $results;
    }

    #endregion
    #region Reading the tokens

    /**
     * Every built-in function this file calls, however it was written.
     *
     * A name is recorded as qualified if any call site backslashes it, so a file
     * that writes it both ways is reported rather than half-fixed.
     *
     * @return array<string, array{line: int, qualified: bool}>
     */
    private static function calledBuiltins(array $tokens): array
    {
        $called = [];

        foreach ($tokens as $i => $token) {
            $name = self::calledName($tokens, $i);
            if ($name === null) {
                continue;
            }
            [$bare, $qualified] = $name;
            if (!self::isBuiltin($bare)) {
                continue;
            }
            $called[$bare] = [
                'line'      => $called[$bare]['line'] ?? $token[2],
                'qualified' => ($called[$bare]['qualified'] ?? false) || $qualified,
            ];
        }

        return $called;
    }

    /**
     * The function name called at token $i, and whether it was backslashed.
     *
     * PHP 8 hands us most of this: `\trim(` arrives as T_NAME_FULLY_QUALIFIED
     * and `Other\helper(` as T_NAME_QUALIFIED, so only a bare T_STRING can be
     * the unqualified case. What is left to exclude is everything else a bare
     * name can be: a method, a static call, a declaration, a class after `new`.
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function calledName(array $tokens, int $i): ?array
    {
        $token = $tokens[$i];
        if (!is_array($token)) {
            return null;
        }
        if (!in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        if (self::nextSignificant($tokens, $i) !== '(') {
            return null;
        }

        if ($token[0] === \T_NAME_FULLY_QUALIFIED) {
            $bare = ltrim($token[1], '\\');
            // \Foo\bar() is somebody else's function, not a built-in with a backslash.
            return str_contains($bare, '\\') ? null : [strtolower($bare), true];
        }

        $previous = self::previousSignificant($tokens, $i);
        if ($previous === null || $previous === '\\' || $previous === '&') {
            return null;
        }
        $excluded = [
            \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON,
            \T_FUNCTION, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM, \T_NEW,
            \T_CONST, \T_USE, \T_NAMESPACE, \T_ATTRIBUTE, \T_GOTO,
        ];

        return in_array($previous, $excluded, true) ? null : [strtolower($token[1]), false];
    }

    /** True when the file declares a namespace, since global-scope code has nothing to fix. */
    private static function isNamespaced(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === \T_NAMESPACE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Built-in function names already imported, and the line they sit on.
     *
     * Userland imports are left out on purpose: this rule is about built-ins,
     * and removing somebody's `use function App\Helpers\slugify;` because it
     * looked unused is not this tool's business.
     *
     * @return array<string, int>
     */
    private static function importedBuiltins(array $tokens): array
    {
        $imported = [];
        $count    = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== \T_USE) {
                continue;
            }
            $next = self::nextSignificantIndex($tokens, $i);
            if ($next === null || !is_array($tokens[$next]) || $tokens[$next][0] !== \T_FUNCTION) {
                continue;   // a class import, or a closure's `use (...)`
            }

            for ($j = $next + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                if (!is_array($tokens[$j])) {
                    continue;
                }
                if (!in_array($tokens[$j][0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = strtolower(ltrim($tokens[$j][1], '\\'));
                if (!str_contains($name, '\\') && self::isBuiltin($name)) {
                    $imported[$name] = $tokens[$j][2];
                }
            }
        }

        return $imported;
    }

    /** The previous token's type, or its literal text when it is punctuation. */
    private static function previousSignificant(array $tokens, int $i): string|int|null
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j])) {
                if (in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                return $tokens[$j][0];
            }
            return $tokens[$j];
        }
        return null;
    }

    /** The next token's literal text, for checking that a name is followed by '('. */
    private static function nextSignificant(array $tokens, int $i): ?string
    {
        $next = self::nextSignificantIndex($tokens, $i);
        if ($next === null) {
            return null;
        }
        return is_array($tokens[$next]) ? $tokens[$next][1] : $tokens[$next];
    }

    private static function nextSignificantIndex(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }
        return null;
    }

    /**
     * True for functions PHP itself provides.
     *
     * Asking the interpreter rather than carrying a list keeps this correct on
     * versions that added functions after this file was written. Functions
     * defined by an extension count: they are equally subject to the lookup.
     */
    private static function isBuiltin(string $name): bool
    {
        static $known = [];
        if (!isset($known[$name])) {
            $known[$name] = (function_exists($name) && (new ReflectionFunction($name))->isInternal())
                || in_array($name, self::LATE_BUILTINS, true);
        }
        return $known[$name];
    }

    /**
     * Function names the file declares itself, lowercased.
     *
     * Methods do not claim a name: a class can have its own count() method
     * while the file imports and calls the built-in count(), and the two never
     * collide. A function declared anywhere else does claim it (including one
     * nested in an if or another function, which PHP registers at the namespace
     * level when the declaration runs), so importing the global one would
     * change which function the calls reach, or fail outright with "cannot use
     * function because the name is already in use". Declared names are left
     * alone.
     *
     * @return array<string, true>
     */
    private static function declaredFunctions(array $tokens): array
    {
        $declared   = [];
        $bodyStack  = [];    // one entry per open '{': true when it opens a class-like body
        $parenDepth = 0;
        $classDepth = null;  // paren depth at a class keyword, until its body brace opens

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                if ($token === '(') {
                    $parenDepth++;
                } elseif ($token === ')') {
                    $parenDepth--;
                } elseif ($token === '{') {
                    // `new class(function () { ... }) { ... }`: the closure body brace
                    // sits inside the argument parens, so only a brace back at the
                    // keyword's own paren depth opens the class body.
                    $bodyStack[] = $classDepth === $parenDepth;
                    if ($classDepth === $parenDepth) {
                        $classDepth = null;
                    }
                } elseif ($token === '}') {
                    array_pop($bodyStack);
                }
                continue;
            }

            if (in_array($token[0], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true)) {
                // Foo::class is the class-name constant, not a declaration opening a body.
                if (self::previousSignificant($tokens, $i) !== \T_DOUBLE_COLON) {
                    $classDepth = $parenDepth;
                }
                continue;
            }

            // '{' inside string interpolation opens no body but is closed by a plain '}'
            if ($token[0] === \T_CURLY_OPEN || $token[0] === \T_DOLLAR_OPEN_CURLY_BRACES) {
                $bodyStack[] = false;
                continue;
            }

            if ($token[0] !== \T_FUNCTION || end($bodyStack) === true) {
                continue;
            }
            // `use function strlen;` is also T_FUNCTION followed by a name, and
            // importing a name is the opposite of declaring one.
            if (self::previousSignificant($tokens, $i) === \T_USE) {
                continue;
            }
            $next = self::nextSignificantIndex($tokens, $i);
            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === \T_STRING) {
                $declared[strtolower($tokens[$next][1])] = true;
            }
        }
        return $declared;
    }

    #endregion
    #region Fixing

    /**
     * Rewrites a file to the house style: backslashes removed from built-in
     * calls, and one import line naming exactly the built-ins the file calls.
     *
     * Returns the names now imported, or an empty list when nothing changed.
     * Files whose imports use the grouped `use function Foo\{a, b};` form are
     * left alone, because rewriting those blind is not worth the risk; they are
     * still reported by the scan.
     *
     * @return list<string>
     */
    public static function fixFile(string $path): array
    {
        $source  = (string)file_get_contents($path);
        $patched = self::fixSource($source);
        if ($patched === null || $patched === $source) {
            return [];
        }
        file_put_contents($path, $patched);

        $tokens = token_get_all($patched);
        return array_keys(array_diff_key(self::calledBuiltins($tokens), self::declaredFunctions($tokens)));
    }

    /** Returns the corrected source, or null when there is no safe way to edit it. */
    public static function fixSource(string $source): ?string
    {
        $tokens = token_get_all($source);
        if (!self::isNamespaced($tokens)) {
            return null;
        }

        $wanted = array_keys(array_diff_key(self::calledBuiltins($tokens), self::declaredFunctions($tokens)));
        sort($wanted);

        $stripped = self::stripBackslashes($tokens);

        return $wanted === [] ? self::removeImports($stripped) : self::setImports($stripped, $wanted);
    }

    /**
     * Rebuilds the source with `\builtin(` written as `builtin(`.
     *
     * Rebuilding from tokens rather than a regex means a backslashed name inside
     * a string or a comment is never touched, because it never arrives as a
     * name token in the first place.
     */
    private static function stripBackslashes(array $tokens): string
    {
        $declared = self::declaredFunctions($tokens);
        $out      = '';
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }
            $name  = self::calledName($tokens, $i);
            $strip = $name !== null && $name[1] && self::isBuiltin($name[0]) && !isset($declared[$name[0]]);
            $out  .= $strip ? ltrim($token[1], '\\') : $token[1];
        }
        return $out;
    }

    /** Replaces the file's built-in imports with exactly $names, or returns it unchanged when the form is unsupported. */
    private static function setImports(string $source, array $names): string
    {
        $imports = 'use function ' . implode(', ', $names) . ";\n";

        // Replace the first simple `use function a, b;` statement, keeping its
        // indentation, and drop any others.
        if (preg_match('/^([ \t]*)use\s+function\s+[^;{]+;[ \t]*\R/mi', $source, $m, \PREG_OFFSET_CAPTURE)) {
            $source = substr_replace($source, $m[1][0] . $imports, (int)$m[0][1], strlen((string)$m[0][0]));
            return self::removeImports($source, true);
        }

        // No import line yet. Go after the last existing `use`, because PSR-12
        // puts class imports before function ones, and only fall back to the
        // namespace declaration when the file imports nothing at all.
        [$at, $indent] = self::afterLastUse($source) ?? self::afterNamespace($source) ?? [null, ''];

        return $at === null
            ? $source   // brace-style namespace and no imports: too varied to edit blind
            : substr_replace($source, "\n" . $indent . $imports, $at, 0);
    }

    /**
     * Byte offset just past the last top-level `use ...;` statement and that
     * line's indentation, or null when there is none. Top-level means column 0:
     * an indented `use` is a trait inside a class body, and the import line must
     * not end up in there.
     *
     * @return array{int, string}|null
     */
    private static function afterLastUse(string $source): ?array
    {
        if (!preg_match_all('/^use\s+[^;{]+;[ \t]*\R/m', $source, $all, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $last = end($all[0]);

        return [(int)$last[1] + strlen((string)$last[0]), ''];
    }

    /**
     * Byte offset just past the namespace declaration and that line's
     * indentation, or null for the brace form. The line may be indented; the
     * inserted import line then matches it.
     *
     * @return array{int, string}|null
     */
    private static function afterNamespace(string $source): ?array
    {
        if (!preg_match('/^([ \t]*)namespace\s+[^;{]+;[ \t]*\R/m', $source, $m, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return [(int)$m[0][1] + strlen((string)$m[0][0]), (string)$m[1][0]];
    }

    /** Removes built-in `use function` statements, optionally keeping the first. */
    private static function removeImports(string $source, bool $keepFirst = false): string
    {
        $seen = false;

        return (string)preg_replace_callback(
            '/^[ \t]*use\s+function\s+([^;{]+);[ \t]*\R/mi',
            static function (array $m) use (&$seen, $keepFirst): string {
                if ($keepFirst && !$seen) {
                    $seen = true;
                    return $m[0];
                }
                // Keep statements that import somebody's userland function.
                foreach (explode(',', $m[1]) as $name) {
                    $name = strtolower(ltrim(trim($name), '\\'));
                    if (str_contains($name, '\\') || !self::isBuiltin($name)) {
                        return $m[0];
                    }
                }
                return '';
            },
            $source,
        );
    }

    #endregion
    #region Files

    /** @return list<string> */
    private static function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    #endregion
}

// Command line entry point. Skipped when this file is included by a test.
if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $paths = array_slice($argv, 1);
    $fix   = in_array('--fix', $paths, true);
    $paths = array_values(array_filter($paths, static fn(string $a): bool => $a !== '--fix'));

    if ($paths === []) {
        fwrite(STDERR, "Usage: php " . basename(__FILE__) . " [--fix] <path> [path...]\n");
        exit(1);
    }

    $files   = 0;
    $skipped = 0;
    foreach ($paths as $path) {
        foreach (NamespacedCallsCheck::scanPath($path) as $file => $findings) {
            $files++;
            if ($fix) {
                $imported = NamespacedCallsCheck::fixFile($file);
                if (NamespacedCallsCheck::scanFile($file) !== []) {
                    $skipped++;
                    printf("%s\n  skipped: no place to put the import line, add it by hand\n", $file);
                    continue;
                }
                printf("%s\n  imports: %s\n", $file, $imported ? implode(', ', $imported) : '(none needed)');
                continue;
            }
            echo "$file\n";
            foreach (['missing', 'qualified', 'unused'] as $type) {
                $names = array_column(array_filter($findings, static fn(array $f): bool => $f['type'] === $type), 'function');
                if ($names !== []) {
                    sort($names);
                    printf("  %-10s %s\n", $type, implode(', ', $names));
                }
            }
        }
    }

    if ($files === 0) {
        echo "All namespaced files import exactly the built-ins they call.\n";
        exit(0);
    }
    printf("\n%d file(s).%s\n", $files, $fix ? '' : ' Run again with --fix to rewrite the imports.');
    exit($fix && $skipped === 0 ? 0 : 1);
}
