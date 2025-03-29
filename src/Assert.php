<?php
/** @noinspection PhpIllegalPsrClassPathInspection */
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

    /**
     * Asserts that a given value matches one of the specified types.
     *
     * Usage: assertType($value, ['string', 'integer', 'double', 'boolean', 'array', 'object', 'NULL'], "\$value");
     *
     * @param mixed $value The value to be checked.
     * @param array $allowedTypes An array of allowable types as strings.  eg: ['string', 'integer', 'double', 'boolean', 'array', 'object', 'NULL'];
     * @param string|null $name Optional name to report in the exception.
     *
     * @return void
     * @throws InvalidArgumentException When the type of value is not in the list of allowed types.
     */
    public static function type(mixed $value, array $allowedTypes, ?string $name = null): void {
        $actualType  = gettype($value);
        $isValidType = in_array($actualType, $allowedTypes);
        if (!$isValidType) {
            $allowedTypesCSV = implode(', ', $allowedTypes);
            $varName = $name ? " for $name" : '';
            throw new InvalidArgumentException("Invalid type '$actualType'$varName, expected one of: $allowedTypesCSV");
        }
    }
    /**
     * Assert that the given value's type is NOT within the disallowed types.
     *
     * @param mixed $value The value to check.
     * @param array $disallowedTypes An array of disallowed types. eg: ['string', 'integer', 'double', 'boolean', 'array', 'object', 'NULL'];
     * @param string|null $name Optional name to include in the exception message.
     *
     * @throws InvalidArgumentException When the type of the value is disallowed.
     */
    public static function notType(mixed $value, array $disallowedTypes, ?string $name = null): void {
        $actualType = gettype($value);
        $isInvalidType = in_array($actualType, $disallowedTypes);
        if ($isInvalidType) {
            $disallowedTypesCSV = implode(', ', $disallowedTypes);
            $varName = $name ? " for $name" : '';
            throw new InvalidArgumentException("Invalid type '$actualType'$varName, should not be one of: $disallowedTypesCSV");
        }
    }

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
     * @throws InvalidArgumentException on unsafe characters.
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

    public static function validConfig(string $key, mixed $value): void {

        // key name, preConnectOnly, value types,
        static $keyRules = [
            'hostname'               => [true, ['NULL', 'string']],
            'username'               => [true, ['NULL', 'string']],
            'password'               => [true, ['NULL', 'string']],
            'database'               => [true, ['string']],
            'tablePrefix'            => [false, ['string']], // allow changing post-connect after?
            'primaryKey'             => [true, ['string']], // allow changing post-connect after?
            'usePhpTimezone'         => [true, ['boolean']],
            'set_sql_mode'           => [true, ['string']],
            'set_innodb_strict_mode' => [true, ['NULL','boolean']],
            'versionRequired'        => [true, ['string']],
            'requireSSL'             => [true, ['boolean']],
            'databaseAutoCreate'     => [true, ['boolean']],
            'connectTimeout'         => [true, ['integer']],
            'readTimeout'            => [true, ['integer']],
            'enableLogging'          => [false, ['boolean']],
            'logFile'                => [false, ['NULL', 'string']],
            'useSmartJoins'          => [false, ['boolean']],
        ];

        // key: is valid name?
        if (!array_key_exists($key, $keyRules)) {
            $validKeys = implode(', ', array_keys($keyRules));
            throw new InvalidArgumentException("Invalid key '$key'. Available keys: $validKeys");
        }

        // get rules
        [$preConnectOnly, $valueTypes] = $keyRules[$key];

        // key can be set before connecting
        if ($preConnectOnly && DB::isConnected()) {
            throw new InvalidArgumentException("Invalid key '$key': This key can only be set before connecting to the database");
        }

        // value: is expected type?
        $valueType = gettype($value);
        if (!in_array($valueType, $valueTypes)) {
            $validTypes = implode(', ', $valueTypes);
            throw new InvalidArgumentException("Invalid value type of '$valueType' for key '$key'.  Expected one of: $validTypes");
        }

        // value: check for unsafe characters
        if (is_string($value)) {
            self::sqlSafeString($value, "key '$key'", true);
        }
    }

    /**
     * Asserts that the input is of an allowed type for htmlEncode, urlEncode, jsEncode
     *
     * Usage: Assert::encodableType($input); // throws exception for invalid types
     *
     * @param mixed $input The input to validate.
     *
     * @throws InvalidArgumentException If the input is of an invalid type.
     */
    public static function encodableType(mixed $input): void {
        $allowedTypes = ['string', 'boolean', 'integer', 'double', 'NULL'];
        $actualType   = gettype($input);

        if (!in_array($actualType, $allowedTypes, true)) {
            throw new InvalidArgumentException("Invalid input of type: ".$actualType);
        }
    }


    /**
     * Checks if the MySQL version meets the required version.
     *
     * Usage: Assert::mysqlVersion($mysqli, '5.7.28');
     *
     * @param mysqli $mysqli          The mysqli object representing the connection to the MySQL server.
     * @param string $requiredVersion The required MySQL version in the format 'x.x.x'.
     *
     * @return void
     * @throws RuntimeException When the MySQL version is older than the required version.
     */
    public static function mysqlVersion(mysqli $mysqli, string $requiredVersion): void {

        $currentVersion = preg_replace("/[^0-9.]/", '', $mysqli->server_info);
        if (version_compare($requiredVersion, $currentVersion, '>')) {
            $error  = "This program requires MySQL v$requiredVersion or newer. This server has v$currentVersion installed.\n";
            $error .= "Please ask your server administrator to install MySQL v$requiredVersion or newer.\n";
            throw new RuntimeException($error);
        }
    }

}

