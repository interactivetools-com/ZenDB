<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException, RuntimeException;
use mysqli;

/**
 * Class Assert
 *
 * Error checking assertions
 */
class Assert
{

    public static function validDatabaseName(string $identifier): void {
        // Updated to allow leading numbers to support hosting providers that require numeric table prefixes, e.g., 123456789_articles).
        if (!preg_match('/^[\w-]+$/', $identifier)) {
            throw new InvalidArgumentException("Invalid database name '$identifier', can only contain (a-z, A-Z, 0-9, _, -)");
        }
    }

    public static function validTableName(string $identifier): void {
        if (!preg_match('/^[a-zA-Z_][\w-]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid table name '$identifier', must start with (a-z, A-Z, _) followed by (a-z, A-Z, 0-9, _, -) ");
        }
    }

    public static function validColumnName(string $identifier): void {
        if (!preg_match('/^[a-zA-Z_][\w-]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid column name '$identifier', must start with (a-z, A-Z, _) followed by (a-z, A-Z, 0-9, _, -)");
        }
    }

    /**
     * Asserts that a string contains only MySQL safe characters.
     *
     * @param string      $string String to scan for unsafe characters.
     * @param string|null $inputName
     *
     * @return void True if the string is safe, false if it's not.
     */
    /**
     * Asserts that a SQL string is safe to use in a query.
     *
     * @param string $string The string to check.
     * @param string|null $inputName A description of the input source.
     *
     * @throws InvalidArgumentException|DBException on unsafe characters.
     */
    public static function sqlSafeString(string $string, ?string $inputName = null, $allowNumbers = false): void {
        $inputName       ??= "sql template";

        // Standalone numbers
        // Prevent devs from insert raw numbers into queries as they could be replaced with SQL injection.
        // eg: $db->query("SELECT * FROM table WHERE id = {$_REQUEST['id']}");
        // Could be replaced with the following to inject SQL: $_REQUEST['id'] = "1 OR 1=1";
        // So we force them to use placeholders instead.
        if (!$allowNumbers && preg_match('/\b(\d+)\b/', $string, $matches)) {
            $n = $matches[1];
            throw new DBException("Disallowed standalone number in $inputName. Replace $n with :n$n and add a named parameter: [ ':n$n' => $n ]");
        }

        // Quote characters
        // Prevent devs from inserting quotes, so they are forced to use placeholders instead and can't accidentally create exploitable queries.
        if (preg_match('/[\'"]/', $string, $matches)) {
            $quote        = $matches[0];                                                          // first quote char, either ' or "
            $quotedText   = preg_match('/(([\'"]).*?\2)/', $string, $matches) ? $matches[1] : ''; // first quoted string
            $quoteContext = substr($string, max(0, strpos($string, $quote) - 15), 30);            // context around first quote char

            $error = $quotedText ? "Quotes are not allowed in $inputName, replace $quotedText with a :paramName and add: [ ':paramName' => $quotedText ]"
                : "Quotes are not allowed in $inputName, found $quote in: ...$quoteContext...";
            throw new DBException($error);
        }

        // Other error checking
        $error = match (true) {
            str_contains($string, "\\")    => "Backslashes (\\) are not allowed in $inputName.",
            str_contains($string, "\x00")  => "Disallowed NULL character in $inputName.",
            str_contains($string, "\x1a")  => "Disallowed CTRL-Z character in $inputName.",
            default                        => null,
        };

        if (isset($error)) {
            throw new DBException($error);
        }
    }

}

