<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartString\SmartString;
use RuntimeException;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function date, htmlspecialchars, is_finite, preg_match, var_export;
use const ENT_DISALLOWED, ENT_HTML5, ENT_QUOTES, ENT_SUBSTITUTE, MYSQLI_TYPE_BLOB;

/**
 * Internal methods for DB.
 *
 * Handles:
 * - Default connection management
 * - Escape methods (escape, escapef, escapeCSV)
 */
trait DBInternals
{
    //region Properties

    /**
     * The default Connection instance
     */
    private static ?Connection $db = null;

    /**
     * Names that passed assertIdentifier(), precached with common ones. Call sites
     * check here first and skip the call for names already seen.
     *
     * TODO-PHP83: initializer can become array_flip(['num', 'id', ...]) - same speed
     *
     * @internal
     */
    public static array $safeIdentifiers = [
        // primary keys
        'num' => true,
        'id'  => true,
        // CMS Builder system fields, on every table
        'createdDate'      => true,
        'createdByUserNum' => true,
        'updatedDate'      => true,
        'updatedByUserNum' => true,
        'dragSortOrder'    => true,
        // common column names
        'name'     => true,
        'title'    => true,
        'content'  => true,
        'email'    => true,
        'username' => true,
        'status'   => true,
        'date'     => true,
        // tables touched on nearly every CMS Builder request
        'accounts'    => true,
        'uploads'     => true,
        '_sessions'   => true,
        '_accesslist' => true,
        '_permalinks' => true,
        // default CMS Builder table prefix
        'cmsb_' => true,
    ];

    //endregion
    //region Connection

    /**
     * Get the default Connection instance, e.g. to pass somewhere a Connection is expected.
     * Throws a RuntimeException when not connected.
     *
     *     DB::connection()->table->exists('users');  // same call Table::exists('users') makes
     *
     * @internal
     */
    public static function connection(): Connection
    {
        return self::$db ?? throw new RuntimeException(
            "No database connection. Call DB::connect() first.",
        );
    }

    //endregion
    //region Escaping

    /**
     * Wrapper for {@see Connection::escape()}
     *
     * @internal
     */
    public static function escape(string|int|float|null|SmartString $input, bool $escapeLikeWildcards = false): string
    {
        return self::connection()->escape($input, $escapeLikeWildcards);
    }

    /**
     * Wrapper for {@see Connection::escapef()}
     *
     * @internal
     */
    public static function escapef(string $format, mixed ...$values): string
    {
        return self::connection()->escapef($format, ...$values);
    }

    /**
     * Wrapper for {@see Connection::escapeCSV()}
     *
     * @internal
     */
    public static function escapeCSV(array $values): RawSql
    {
        return self::connection()->escapeCSV($values);
    }

    /**
     * Convert a finite float to the shortest SQL literal that parses back to the
     * identical double. Every float ZenDB writes into SQL goes through here, so one
     * value has one spelling on every path: placeholders, SET clauses, IN lists,
     * escape(), and the like* helpers.
     *
     *     0.1 + 0.2            →  0.30000000000000004    the value the variable actually holds
     *     2.0                  →  2.0
     *     12345678901234567.0  →  12345678901234568.0    ...567 is not representable; the variable holds ...568
     *
     * A plain (string) cast rounds to 14 digits and prints values PHP isn't
     * actually holding, so writes lose precision and WHERE equality misses
     * stored values (MySQL reads '0.3' as a number that won't equal the sum).
     *
     * The imprecision starts in PHP, not here: floats are binary, so 0.1 + 0.2
     * is already 0.30000000000000004 before ZenDB sees it. This function writes
     * exactly what PHP has. To store something else, convert before passing:
     *
     *     round(0.1 + 0.2, 2)          →  0.3                    rounded to a precision you chose
     *     (string)(0.1 + 0.2)          →  '0.3'                  strings pass through untouched...
     *     (string)12345678901234568.0  →  '1.2345678901235E+16'  ...but the cast E-notates large floats
     *
     * For exact values, use ints of the smallest unit (cents, not dollars) or a
     * DECIMAL column with string input.
     *
     * NAN and INF have no SQL literal, so they throw.
     *
     * @internal
     */
    public static function floatToSql(float $value, string $context = 'value'): string
    {
        return is_finite($value)
            ? var_export($value, true) // exact: php.ini serialize_precision, -1 (shortest round-trip) by default since PHP 7.1
            : throw new InvalidArgumentException("NAN and INF have no SQL literal, can't escape $context");
    }

    /**
     * HTML-encode a value for safe output, same name and flags as CMS Builder's h().
     * ENT_DISALLOWED substitutes code points HTML5 forbids (C1 controls, noncharacters)
     * with � so they can't hide in page source.
     *
     * Every error, warning, or exception message encodes the values it interpolates
     * (keys, identifiers, method names - anything that isn't safe by construction):
     * handlers often echo messages into pages. Assign it to a variable to encode
     * inline in interpolated strings:
     *
     *     $h = self::h(...);
     *     throw new RuntimeException("Unknown key '{$h($key)}', expected a column name");
     *
     * @internal
     */
    public static function h(string|int|float|null $input): string
    {
        return htmlspecialchars((string)$input, ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8');
    }

    //endregion
    //region Validation

    /**
     * Throw unless a string is a safe SQL identifier: letters, numbers, _ and - only.
     * Runs on every table and column name ZenDB puts between backticks - the check that
     * matters there, since real_escape_string() doesn't escape backticks, so escaping
     * alone can't make an identifier safe. $what names the value in the error message.
     *
     * Names that pass are added to `$safeIdentifiers`. Call sites skip already-validated
     * names by checking that list first - IdentifierValidationTest enforces this form at
     * every call site:
     *
     *     isset(DB::$safeIdentifiers[$column]) || DB::assertIdentifier($column, 'column name');
     *
     * A few places inline this regex (or a close variant) instead of calling it - grep
     * for [\w- before changing the charset so they all move together.
     *
     * @internal
     * @param string $identifier The string to check
     * @param string $what Noun for the error message, e.g. 'table name', 'column name'
     * @throws InvalidArgumentException
     */
    public static function assertIdentifier(string $identifier, string $what = 'identifier'): void
    {
        if (!preg_match('/^[\w-]+\z/', $identifier)) { // \z: $ would also match before a trailing newline
            $h = self::h(...); // SECURITY: identifier failed validation, encode before it can reach page output
            throw new InvalidArgumentException("Invalid $what '{$h($identifier)}', allowed characters: a-z, A-Z, 0-9, _, -");
        }
        self::$safeIdentifiers[$identifier] = true;
    }

    //endregion
    //region Encryption

    /**
     * @see Connection::decryptRows()
     * @noinspection PhpFullyQualifiedNameUsageInspection - FQN required until PHP 8.2 minimum (can't import)
     */
    public static function decryptRows(#[\SensitiveParameter] array &$rows, array $keysOrFetchFields): void
    {
        self::connection()->decryptRows($rows, $keysOrFetchFields);
    }

    /**
     * Detect encrypted columns from field metadata. Returns column names for MEDIUMBLOB fields,
     * which are the standard storage type for AES_ENCRYPT() data.
     *
     * Called automatically by query methods when an encryption key is configured.
     * You don't normally need to call this directly.
     *
     *     $encryptedCols = DB::getEncryptedColumns($result->fetch_fields());
     *
     * @param array $fetchFields Field objects from fetch_fields()
     * @return array<int, string> Detected encrypted columns, keyed by field index (e.g. [0 => 'token', 3 => 'ssn'])
     */
    public static function getEncryptedColumns(array $fetchFields): array
    {
        $encrypted = [];
        foreach ($fetchFields as $index => $field) {
            $isMediumBlob = $field->type === MYSQLI_TYPE_BLOB && $field->charsetnr === 63 && $field->length === 16_777_215;
            if ($isMediumBlob) {
                $encrypted[$index] = $field->name;
            }
        }
        return $encrypted;
    }

    //endregion
    //region Timezone

    /**
     * PHP's current timezone expressed as a value MySQL's SET time_zone accepts.
     * Used at connect when `usePhpTimezone` is set; call it yourself after changing
     * PHP's timezone mid-request to bring the database session back in step:
     *
     *     date_default_timezone_set('Pacific/Kiritimati');
     *     DB::query("SET time_zone = ?", DB::phpTimezoneForMysql());
     *
     * Returns PHP's UTC offset (e.g. "-08:00"), except offsets past +13:00 (Kiritimati
     * +14:00, Chatham +13:45 in DST), which MariaDB and MySQL before 8.0.19 reject with
     * error 1298 (bug #63685). Those return an IANA name instead, which needs the
     * mysql.time_zone tables: Linux servers ship them loaded, Windows installs ship them
     * empty and reject the name with "Unknown or incorrect time zone" until they're
     * loaded (import MySQL's downloadable timezone package into the mysql schema and
     * restart: https://dev.mysql.com/downloads/timezones.html).
     *
     * @internal
     * @return string A UTC offset like "+02:00", or an IANA zone name for offsets past +13:00
     */
    public static function phpTimezoneForMysql(): string
    {
        return match ($offset = date('P')) {
            '+14:00' => 'Etc/GMT-14',
            '+13:45' => 'Pacific/Chatham',
            default  => $offset,
        };
    }

    //endregion

}
