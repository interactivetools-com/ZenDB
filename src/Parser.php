<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArray;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use RuntimeException;

/**
 * Parser class for ZenDB
 *
 * Manages SQL parameters for database queries, supporting both named and positional parameters.
 */
class Parser
{
    public string $sqlTemplate;
    public string $paramQuery;
    public array  $bindValues;
    public Params $params;


    #region Constructor

    /**
     * Constructor for Parser
     */
    public function __construct()
    {
        $this->params = new Params();
    }

    /**
     * For when you need to set the sqlTemplate after the parser has been created
     *
     * @param string $sqlTemplate
     * @return void
     */
    public function setSqlTemplate(string $sqlTemplate): void
    {
        $this->sqlTemplate = rtrim($sqlTemplate); // trim blank space from "...query $whereEtc" with no where
    }

    #endregion
    #region SQL Clause Generation

    /**
     * Prepare and validate SQL conditions for clauses such as WHERE, ORDER BY, LIMIT, or OFFSET.
     *
     * This method can handle both a raw SQL string and an associative array of column-value pairs.
     * When a raw SQL string is provided, it should start with either 'WHERE', 'ORDER BY', 'LIMIT', or 'OFFSET'.
     * When an associative array is provided, the function dynamically constructs the SQL conditions and accompanying parameters.
     *
     * @param int|array|string $idArrayOrSql The raw SQL string or an associative array of column-value pairs.
     * @param bool $whereRequired Whether a WHERE clause is required.
     * @param string $primaryKey The primary key column name, used when $idArrayOrSql is an integer.
     *
     * @return string
     * @throws InvalidArgumentException|DBException
     */
    public function getWhereEtc(int|array|string $idArrayOrSql, bool $whereRequired = false, string $primaryKey = ''): string
    {
        // Get sql clauses from int|array|string
        if (is_int($idArrayOrSql)) {
            if (!$primaryKey) {
                throw new InvalidArgumentException("Primary key not defined in config");
            }
            $whereEtc = "WHERE `$primaryKey` = ?";
            $this->params->addPositionalParam($idArrayOrSql); // value is integer
        } elseif (is_array($idArrayOrSql)) {
            $whereEtc = $this->getWhereEtcForArray($idArrayOrSql);
        } elseif (is_string($idArrayOrSql)) { // Use provided sql clauses as-is
            if (preg_match("/^\s*\d+\s*$/", $idArrayOrSql)) {
                throw new InvalidArgumentException("Numeric string detected, convert to integer with (int) \$num to search by record number");
            }
            $whereEtc = $idArrayOrSql;
        } else {
            throw new InvalidArgumentException("Invalid type for \$idArrayOrSql: " . gettype($idArrayOrSql));
        }

        // Add WHERE if not already present
        $requiredLeadingKeyword = "/^\s*(WHERE|FOR|ORDER|LIMIT|OFFSET)\b/i";
        if (!preg_match($requiredLeadingKeyword, $whereEtc) &&
            !preg_match("/^\s*$/", $whereEtc)) { // don't add where if no content
            $whereEtc = "WHERE " . $whereEtc;
        }

        // Validate: Check SQL clauses start with a valid keyword and don't contain unsafe characters
        if ($whereEtc !== "") { // allow empty string
            if (!preg_match($requiredLeadingKeyword, $whereEtc)) {
                throw new InvalidArgumentException("SQL clauses must start with one of the following: WHERE, ORDER BY, LIMIT, OFFSET. Got: $whereEtc");
            }
            Assert::sqlSafeString($whereEtc);
        }

        // Require WHERE
        if ($whereRequired && !preg_match('/^\s*WHERE\b/i', $whereEtc)) {
            throw new InvalidArgumentException("No where condition, operations that can change multiple rows require a WHERE condition");
        }

        return $whereEtc;
    }

    /**
     * Initializes the SQL clauses for an array of conditions.
     *
     * @param array $idArrayOrSql An associative array of conditions.
     *
     * @return string
     *
     * @example $idArrayOrSql = ['column1' => $value1, 'column2' => $value2];
     *          $whereEtcInitForArray($idArrayOrSql);
     */
    private function getWhereEtcForArray(array $idArrayOrSql): string
    {
        $whereEtc   = "";
        $conditions = [];
        foreach ($idArrayOrSql as $column => $value) {
            Assert::validColumnName($column);
            if (is_null($value)) {
                $conditions[] = "`$column` IS NULL";
            } else {
                $conditions[] = "`$column` = ?";
                $this->params->addPositionalParam($value);
            }
        }
        if ($conditions) {
            $whereEtc = "WHERE " . implode(" AND ", $conditions);
        }
        return $whereEtc;
    }

    /**
     * Create set clause from colsToValues array for INSERT/UPDATE SET.  e.g., SET `column1` = :colVal1, `column2` = :colVal2
     * Usage: $this->buildSetClause($colsToValues);
     *
     * @param array $colsToValues
     *
     * @return string
     */
    public function getSetClause(array $colsToValues): string
    {
        // error checking
        if (!$colsToValues) {
            throw new InvalidArgumentException("No colsToValues, please specify some column values");
        }

        // build set clause and defined named parameters, e.g., SET `column1` = :colVal1, `column2` = :colVal2
        $setElements          = [];
        $tempPlaceholderCount = 0;
        foreach ($colsToValues as $column => $value) {
            Assert::validColumnName($column);

            // add to setClause
            $tempPlaceholderCount++;
            $tempPlaceholder = ":zdb_$tempPlaceholderCount"; // zdb_ is internal placeholder prefix
            $setElements[]   = "`$column` = $tempPlaceholder";

            // add values to paramMap
            $this->params->addInternalParam($tempPlaceholder, $value);
        }

        //
        return "SET " . implode(", ", $setElements);
    }

    #endregion
    #region Generate Queries

    /**
     * Return a SQL query with values escaped with mysqli_real_escape_string()
     * For queries that don't support prepared statements, e.g., SHOW, etc. (non-DML queries)
     *
     * @return string
     * @throws DBException
     */
    public function getEscapedQuery(): string
    {
        $this->finalizeQuery();         // prevent further changes to query

        // replace :named and positional ("?") parameters with mysql escaped values
        $positionalCount = 0;           // Index to keep track of positional parameters
        $escapedQuery    = preg_replace_callback(
            pattern: $this->getPlaceholderRegexp(),  // match `?`, ?, :name, `:name`
            callback: function ($matches) use (&$positionalCount) {
                $matchedString  = $matches[0];
                $value          = $this->getPlaceholderValue($matchedString, $positionalCount);
                $valueType      = get_debug_type($value);
                $isIntFloatBool = in_array($valueType, ['int', 'float', 'bool']); // safe value types
                return match (true) {
                    DB::isRawSql($value)              => (string)$value,                                         // DB::rawSql("...") values are passed unquoted
                    str_contains($matchedString, '`') => self::replaceParamInBackticks($value),                  // Return backtick-quoted values (already sanitized)
                    $isIntFloatBool                   => $value,                                                 // Return safe value types as is
                    $value === null                   => 'NULL',                                                 // NULL values are passed as is
                    is_string($value)                 => '"' . DB::$mysqli->real_escape_string($value) . '"',    // Quote and escape string values
                    default                           => throw new InvalidArgumentException("Invalid type for $matchedString: $value"),
                };
            },
            subject: $this->sqlTemplate,
        );

        // ltrim each line for a multiline string (for better formatting in logs and debug output)
        $escapedQuery = preg_replace('/^ +/m', '', $escapedQuery);
        return $escapedQuery;
    }

    /**
     * get parameterized query (positional only) and bind values from sqlTemplate and paramMap
     * converts named parameters to positional parameters and builds bindValues array
     * @throws DBException
     */
    public function getParamQuery(): string
    {
        // return cached values if already generated
        if (isset($this->paramQuery)) {
            return $this->paramQuery;
        }

        // replace :named parameters with positional ("?") parameters and build bindValues array
        $positionalCount = 0;           // Index to keep track of positional parameters
        $bindValues      = [];
        $preparedQuery   = preg_replace_callback(
            pattern: $this->getPlaceholderRegexp(),  // match `?`, ?, :name, `:name`
            callback: function ($matches) use (&$positionalCount, &$bindValues) {
                $matchedString    = $matches[0];
                $inBackticks      = str_starts_with($matchedString, '`') && str_ends_with($matchedString, '`');
                $replacementValue = $this->getPlaceholderValue($matchedString, $positionalCount);

                return match (true) {
                    $inBackticks => self::replaceParamInBackticks($replacementValue),
                    default      => self::replaceParam($replacementValue, $bindValues),
                };
            },
            subject: $this->sqlTemplate,
        );

        // store values
        $this->finalizeQuery();         // prevent further changes to query
        $this->paramQuery = $preparedQuery;
        $this->bindValues = $bindValues;

        // return prepared query
        return $this->paramQuery;
    }


    #endregion
    #region Generate Query Internals

    /**
     * @return string
     */
    private function getPlaceholderRegexp(): string
    {
        $placeholderRx = implode("|", [
            "\?",                 // match ?
            "`\?`",               // match `?`
            "`:_\?`",             // match `:_?`
            "`::\?`",             // match `::?`
            ":[a-zA-Z]\w*\b",     // match :name
            "`:[a-zA-Z]\w*\b`",   // match `:name`
            "`:_:[a-zA-Z]\w*\b`", // match `:_:name`
            "`:::[a-zA-Z]\w*\b`", // match `:::name`
            ":_",                 // match :_
            "::",                 // match ::
        ]);
        return "/$placeholderRx/";
    }

    /**
     * @param $matchedString
     * @param $positionalCount
     *
     * @return string|int|float|bool|RawSql|null
     * @throws DBException
     */
    private function getPlaceholderValue($matchedString, &$positionalCount): string|int|float|bool|null|RawSql
    {
        $addTablePrefix = str_starts_with($matchedString, "`:_") || str_starts_with($matchedString, "`::"); // only supported for backtick placeholders
        $placeholder    = trim($matchedString, '`');                                                        // unwrap backticks
        $placeholder    = preg_replace("/^(:_|::)/", "", $placeholder);                                     // remove table prefix placeholder
        $isPositional   = ($placeholder === '?');

        // handle special case for :_ and :: placeholders
        if ($matchedString === ':_' || $matchedString === '::') {
            return DB::rawSql(DB::$tablePrefix); // return table prefix as a RawSql object so it's not quoted
        }

        // get placeholder value
        $mapKey = $isPositional ? ':' . ++$positionalCount : $placeholder;                                  // e.g., :1 or :name
        if (!array_key_exists($mapKey, $this->params->paramMap)) {
            match (true) {
                $isPositional => throw new DBException("Missing value for ? parameter at position $positionalCount"),
                default       => throw new DBException("Missing value for '$mapKey' parameter"),
            };
        }
        $value = $this->params->paramMap[$mapKey];

        // params in backticks, add table prefix if needed
        if ($addTablePrefix) {
            $value = DB::$tablePrefix . $value;
        }

        // return value
        return $value;
    }

    /**
     * @param $value
     * @param $bindValues
     *
     * @return string
     */
    private static function replaceParam($value, &$bindValues): string
    {
        // Inject raw unquoted sql values.  e.g., DB::rawSql("NOW()") becomes NOW()
        if (DB::isRawSql($value)) {
            return (string)$value;
        }

        if ($value === null) {
            // null values are passed as is
            return 'NULL';
        }

        // Add value to bindValues
        $bindValues[] = $value;

        // return placeholder
        return "?";                                                      // replace all placeholders with positional placeholders
    }

    /**
     * `backtick_placeholders` Special case to allow inserting identifiers (table names, column names, etc.).
     * Instead of "quoting \& escaping" values or passing them as parameters, we check they're safe and allow them to be inserted
     *
     * PHP's MySQLi and PDO prepared statements are limited to DML queries (INSERT, UPDATE, DELETE, SELECT) and only support data value parameters.
     * They don't support placeholders for database objects like tables or columns due to the need for a pre-defined query structure.  For this
     * reason we need to escape and surround identifiers with backticks manually.
     *
     * @param $replacementValue
     *
     * @return string
     * @throws DBException
     */
    private static function replaceParamInBackticks($replacementValue): string
    {
        if (is_string($replacementValue) && !preg_match('/[^a-zA-Z0-9_-]/', $replacementValue)) {
            // we can assume $value is safe at this point as it only contains letters, numbers, and underscores
            return "`$replacementValue`";
        }

        // throw exception if $value contains invalid characters
        $replacementExport = is_scalar($replacementValue) ? "`$replacementValue`" : var_export($replacementValue, true);
        throw new DBException("Invalid identifier $replacementExport.  Placeholders in backticks can only contain letters, numbers, dashes and underscores.");
    }

    #endregion
    #region Utilities

    /**
     * Determines if this query is a DML (Data Manipulation Language) query
     * DML queries (SELECT, INSERT, UPDATE, DELETE) can use prepared statements
     * Other queries (SHOW, DESCRIBE, etc.) must use escaped queries
     *
     * @return bool True if this is a DML query
     */
    public function isDmlQuery(): bool
    {
        return (bool)preg_match("/^\s*(INSERT|UPDATE|DELETE|SELECT)\b/i", $this->sqlTemplate);
    }

    /**
     * Finalizes the SQL query by performing necessary preparation steps:
     * 1. Sets the template as the last query for debugging
     * 2. Handles trailing LIMIT clauses if present
     * 3. Validates the template for security and parameter constraints
     *
     * This method should be called before query execution to ensure
     * the SQL is properly prepared and validated.
     *
     * @return self Returns $this for method chaining
     * @throws InvalidArgumentException|DBException
     */
    public function finalizeQuery(): self
    {
        $this->params->finalizeQuery();

        MysqliWrapper::setLastQuery($this->sqlTemplate);                    // set template as last query for debugging
        $this->sqlTemplate = $this->allowTrailingLimit($this->sqlTemplate); // Handle trailing LIMIT clause if present
        $this->validateSqlTemplate($this->sqlTemplate);                     // Validate SQL template, throw exception if invalid

        return $this;
    }

    /**
     * Special case handling for allowing trailing "LIMIT #" clauses (common use case)
     * Detects and parameterizes queries ending in "LIMIT" followed by any number.
     *
     * This pattern is safe because:
     *   1. The regex requires the query to end with "LIMIT #" (where # is a number)
     *   2. To bypass our filters the injected code would also need to end in "LIMIT #" and multiple limits are invalid
     *   3. We don't support multi_query() semicolon-separated queries like "SELECT...; DELETE..." and check ; isn't present
     *   4. There is no possible valid MySql code that both starts with LIMIT and ends with another LIMIT #
     *
     * Example injection that fails (even if the user writes insecure code):
     *   Template: "SELECT * FROM users LIMIT {$_GET['limit']}"
     *   Attack: "(SELECT id FROM admins LIMIT 1)"         // fails, doesn't end in a number
     *   Attack: "1; SELECT * FROM users LIMIT 1"          // fails, multiple queries not supported
     *   Attack: "1 INTO OUTFILE '/var/www/html/temp.php'" // fails, doesn't end in a number
     *
     * @param string $sqlTemplate The SQL template to check for trailing LIMIT
     * @return string The modified SQL template
     * @throws \Exception
     */
    private function allowTrailingLimit(string $sqlTemplate): string
    {
        $limitRx = '/\bLIMIT\s+\d+\s*$/i';
        if (!str_contains($sqlTemplate, ';') && preg_match($limitRx, $sqlTemplate, $matches)) {
            $limitExpr   = $matches[0];
            $sqlTemplate = preg_replace($limitRx, ':zdb_limit', $sqlTemplate);
            $this->params->addInternalParam(':zdb_limit', DB::rawSql($limitExpr));
        }
        return $sqlTemplate;
    }

    /**
     * Validates the SQL template for safety and parameter constraints
     *
     * @param string $sqlTemplate The SQL template to validate
     * @return void
     * @throws InvalidArgumentException|DBException
     */
    private function validateSqlTemplate(string $sqlTemplate): void
    {
        // Check for SQL injection risks
        Assert::sqlSafeString($sqlTemplate);

        // Check for too many positional parameters
        $positionalCount = substr_count($sqlTemplate, '?');
        if ($positionalCount > 4) {
            throw new InvalidArgumentException("Too many ? parameters, max 4 allowed. Try using :named parameters instead");
        }
    }

    #endregion
}
