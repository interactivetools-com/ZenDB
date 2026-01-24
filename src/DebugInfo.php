<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use ReflectionClass, ReflectionMethod;
use SplFileObject;

/**
 * DebugInfo
 */
class DebugInfo
{
    //region Errors

    public static function throwUnknownMethodException(string $method, string $class, ?array $excludedMethods = []): string
    {
        $publicMethods = self::getPublicMethods($class, $excludedMethods);

        //
        $error = "Unknown method ->$method(), available methods for $class:\n\n";
        foreach ($publicMethods as $publicMethod) {
            $error .= "->$publicMethod()\n";
        }
        $error .= "\nPlease update your code\n";

        throw new InvalidArgumentException($error);
    }

    /**
     * Log deprecated error message but don't show it to the user.
     *
     * @UsedBy DebugInfo::logDeprecatedError("error message")
     * @noinspection OverridingDeprecatedMethodInspection
     */
    public static function logDeprecatedError($error): void
    {
        @trigger_error($error, E_USER_DEPRECATED);
    }

    //endregion
    //region Utility Methods

    /**
     * Get a list of public methods for a class.
     *
     * @throws \ReflectionException
     */
    public static function getPublicMethods(string $class, ?array $excludeMethods = []): array
    {
        // get a list of available public methods for this class
        $reflectionMethods = (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC);
        $publicMethodNames = array_map(fn($method) => $method->name, $reflectionMethods);

        // remove magic methods
        $publicMethodNames = array_filter($publicMethodNames, fn($method) => !str_starts_with($method, '__'));

        // remove excluded methods
        $publicMethodNames = array_diff($publicMethodNames, $excludeMethods);

        //
        sort($publicMethodNames);

        //
        return $publicMethodNames;
    }

    /**
     * We try to get the actual variable name the developer is using to make the output more useful and relevant.
     */
    private static function getCallerVarName(): string|null
    {
        // get caller index that triggered __debugInfo()
        $callerIndex     = null;
        $possibleCallers = ['help', 'showme', 'print_r', 'var_dump', 'var_export'];
        $backtrace       = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $functionToIndex = array_flip(array_column($backtrace, 'function'));
        foreach ($possibleCallers as $function) {
            if (isset($functionToIndex[$function])) {
                $callerIndex = $functionToIndex[$function];
                break;
            }
        }

        // get caller
        $caller = isset($callerIndex) ? $backtrace[$callerIndex] : null;
        if (empty($caller) || empty($caller['file']) || empty($caller['line']) || empty($caller['function'])) {
            return null;
        }

        // get line of code
        $fileObj = new SplFileObject($caller['file']);
        $fileObj->seek($caller['line'] - 1);
        $lineText = $fileObj->current();

        //
        $arguments = match (strtolower($caller['function'])) {
            'help'  => self::getHelpMethodChainPrefix($lineText),
            default => self::getFunctionArgsFromLine($lineText, $caller['function']),
        };

        // returns vars only but without the leading $, eg: 'user->name' instead of '$user->name'
        return match (true) {
            str_starts_with((string)$arguments, '$') => ltrim($arguments, '$'), // remove leading $
            default                                  => null,                   // ignore functions, eg: DB::select(...)->first()->name
        };
    }

    private static function getFunctionArgsFromLine($phpCodeLine, $functionName): string
    {
        $arguments  = '';
        $tokens     = token_get_all("<?php $phpCodeLine"); // tokenize PHP code
        $capturing  = false;
        $parenCount = 0;
        foreach ($tokens as $token) {
            $tokenValue = is_array($token) ? $token[1] : $token;

            if ($capturing) {
                if ($tokenValue === '(') {
                    if (++$parenCount === 1) {  // Start capturing after the first opening parenthesis
                        continue;
                    }
                } elseif ($tokenValue === ')') {
                    if (--$parenCount === 0) { // Stop capturing before the last closing parenthesis
                        break;
                    }
                }

                $arguments .= $tokenValue;
            }

            if (!$capturing) {
                $isCallerToken = is_array($token) && $token[0] === T_STRING && $token[1] === $functionName;
                $capturing     = $isCallerToken;
            }
        }

        return $arguments;
    }

    private static function getHelpMethodChainPrefix($phpCodeLine): string|null
    {
        $tokens       = token_get_all("<?php $phpCodeLine");
        $capturedCode = '';
        $parenCount   = 0;
        $capturing    = false;

        // Reverse the tokens array to start from the end
        $tokens = array_reverse($tokens);

        foreach ($tokens as $token) {
            $tokenValue = is_array($token) ? $token[1] : $token;

            if (!$capturing && $tokenValue === 'help') {
                $capturing = true;
                continue;
            }

            if ($capturing) {
                if ($tokenValue === ')') {
                    $parenCount++;
                } elseif ($tokenValue === '(') {
                    if ($parenCount === 0) {
                        break; // Stop capturing when an unmatched "(" is found
                    }
                    $parenCount--;
                } elseif ($tokenValue === ';') {
                    break; // Stop capturing when a ";" is found
                }

                // Prepend the token value to build the chain in reverse order
                $capturedCode = $tokenValue.$capturedCode;
            }
        }

        return $capturedCode ? rtrim($capturedCode, '->') : null;
    }

    private static function xmpWrap($output): string
    {
        // is text/plain header set?
        $headerLines = implode("\n", headers_list());
        $textPlainRx = '|^\s*Content-Type:\s*text/plain\b|im'; // Content-Type: text/plain or text/plain;charset=utf-8
        $isTextPlain = (bool)preg_match($textPlainRx, $headerLines);

        // wrap output in <xmp> tag if not text/plain or called from showme()
        if (!$isTextPlain && !self::inCallStack('showme')) {
            $output = "\n<xmp>".trim($output, "\n")."</xmp>\n";
        }

        return $output;
    }

    private static function getOutputAsDebugInfoArray(string $output): array
    {
        $output = str_replace("\n", "\n        ", "\n$output"); // left pad with spaces

        // var_dump wraps output in "" so final line looks like: Field Value: "string"", so we add a \n for clarity
        if (self::inCallStack('var_dump')) {
            $output .= "\n";
        }

        return ["__DEVELOPERS__" => self::xmpWrap($output)];
    }

    private static function getPrettyVarValue($value): string|int|float
    {
        return match (true) {
            is_string($value) => sprintf('"%s"', $value),
            is_bool($value)   => ($value ? "TRUE" : "FALSE"), // not returned by MySQL but let's use this in general Collections
            is_null($value)   => "NULL",
            default           => $value, // includes ints and floats
        };
    }

    /**
     * Return human-readable var info like print_r, but more nicely formatted.
     */
    private static function getPrettyVarDump($var): string|int|float
    {
        // single value
        if (!is_array($var)) {
            return self::getPrettyVarValue($var);
        }

        // empty array
        if (empty($var)) {
            return "None";
        }

        // array of values (which aren't arrays)
        $elements      = $var; // at this point, $var is an array since we checked above
        $output        = "";
        $isNestedArray = in_array(true, array_map('is_array', $elements));
        if (!$isNestedArray) {
            $maxKeyLength = strlen("->") + max(array_map('strlen', array_keys($elements)));
            foreach ($elements as $key => $value) {
                $output .= sprintf("%-{$maxKeyLength}s = %s\n", "->$key", self::getPrettyVarValue($value));
            }
            return trim($output);
        }

        // nested arrays - at this point we know $var is an array of arrays
        foreach ($elements as $index => $row) {
            $output       .= "[$index] => [\n";
            $maxKeyLength = max(array_map('strlen', array_keys($row)));
            foreach ($row as $key => $value) {
                if (is_array($value)) { // workaround to show nested-nested arrays as returned by groupBy()
                    $data = self::getPrettyVarDump($row);
                    $output .= preg_replace("/^/m", "    ", "$data\n");
                    break;
                }
                $output .= sprintf("    %-{$maxKeyLength}s => %s\n", $key, self::getPrettyVarValue($value));
            }
            $output .= "]\n";
        }
        return trim($output);
    }

    /**
     * Check if a function is in the call stack.
     */
    private static function inCallStack($function): bool
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($backtrace as $trace) {
            if ($trace['function'] === $function) {
                return true;
            }
        }
        return false;
    }

    //endregion
}
