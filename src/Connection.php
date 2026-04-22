<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use Itools\SmartArray\SmartArrayHtml;
use Itools\SmartString\SmartString;
use RuntimeException;
use Throwable;
use mysqli;
use mysqli_sql_exception;
use WeakMap;

/**
 * Connection class for ZenDB - manages a single database connection with its own settings.
 *
 * Usage:
 *     // Create default connection (use DB::connect)
 *     DB::connect(['hostname' => 'localhost', 'database' => 'my_db', ...]);
 *
 *     // Create standalone connection
 *     $remote = new Connection(['hostname' => 'remote.example.com', 'database' => 'legacy']);
 *
 *     // Clone with different settings
 *     $db = DB::clone(['useSmartJoins' => false]);
 */
class Connection
{
    use ConnectionInternals;

    //region Public Properties

    /**
     * The raw mysqli connection instance. You can use this for direct access to mysqli methods if needed.
     */
    public ?MysqliWrapper $mysqli = null;

    /**
     * Table prefix prepended to table names (e.g., 'cms_')
     */
    public string $tablePrefix = '';

    /**
     * Add table-prefixed keys to JOIN results for column disambiguation
     */
    public bool $useSmartJoins = true;

    /**
     * Return values as SmartString objects with auto HTML-encoding
     */
    public bool $useSmartStrings = true;

    //endregion
    //region Connection

    /**
     * Create a new database connection.
     *
     *     DB::connect($config)      // Create and set as default connection
     *     new Connection($config)   // Create standalone connection
     *
     * @param array{
     *     hostname:              string,    // Database server hostname
     *     username:              string,    // Database username
     *     password:              string,    // Database password (use '' for none)
     *     database:              string,    // Database name
     *     tablePrefix?:          string,    // Prefix for table names (default: '')
     *     useSmartJoins?:        bool,      // Add table.column keys to JOIN results (default: true)
     *     useSmartStrings?:      bool,      // Return SmartString values (default: true)
     *     usePhpTimezone?:       bool,      // Sync MySQL timezone with PHP (default: true)
     *     loadHandler?: callable, // Custom result loading handler
     *     versionRequired?:      string,    // Minimum MySQL version (default: '5.7.32')
     *     requireSSL?:           bool,      // Require SSL connection (default: false)
     *     databaseAutoCreate?:   bool,      // Create database if missing (default: false)
     *     connectTimeout?:       int,       // Connection timeout in seconds (default: 3)
     *     readTimeout?:          int,       // Read timeout in seconds (default: 60)
     *     queryLogger?:          callable,  // fn(string $query, float $secs, ?Throwable $exception)
     *     sqlMode?:              string,    // MySQL SQL mode
     *     encryptionKey?:        string,    // AES encryption key, sets MySQL @ek session variable on first use
     * } $config
     *
     * @noinspection PhpFullyQualifiedNameUsageInspection - FQN required until PHP 8.2 minimum (can't import)
     */
    public function __construct(#[\SensitiveParameter] array $config = [])
    {
        // Seal credentials into vault (removes credential keys from $config)
        $this->sealSecrets(config: $config);

        // Apply remaining config to properties
        foreach ($config as $key => $value) {
            if (!property_exists($this, $key)) {
                throw new InvalidArgumentException("Unknown configuration key: '$key'");
            }
            $this->$key = $value;
        }
        unset($config);

        // Connect
        $this->connect();
    }

    /**
     * Connect to the database using the configured settings.
     *
     * @throws RuntimeException If connection fails or version requirements not met
     */
    public function connect(): void
    {
        // Throw if already connected
        if ($this->isConnected()) {
            throw new RuntimeException("Already connected. To reconnect, call disconnect() first.");
        }

        // Create a new mysqli instance
        $this->mysqli = new MysqliWrapper(queryLogger: $this->queryLogger);
        $this->mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $this->connectTimeout);
        $this->mysqli->options(MYSQLI_OPT_READ_TIMEOUT, $this->readTimeout);
        $this->mysqli->options(MYSQLI_OPT_LOCAL_INFILE, 0); // disable "LOAD DATA LOCAL INFILE" for security

        // Return native PHP types (int/float) instead of strings
        if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
            $this->mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        }

        // Pass encryption key callback to MysqliWrapper for automatic @ek session variable setup
        if ($this->secret('encryptionKey') !== '' && $this->secret('encryptionKey') !== null) {
            $this->mysqli->setEncryptionKeyCallback(fn() => $this->secret('encryptionKey'));
        }

        $flags = $this->requireSSL ? MYSQLI_CLIENT_SSL : 0;

        // Attempt to connect
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);  // ensure exceptions are thrown (default for PHP 8.1+)

            try {
                $this->mysqli->real_connect($this->secret('hostname'), $this->secret('username'), $this->secret('password'), $this->secret('database'), null, null, $flags);
            } catch (mysqli_sql_exception $e) {
                // if database doesn't exist and auto-create enabled, try again and create database
                $database = $this->secret('database');
                if ($this->databaseAutoCreate && $e->getCode() === 1049) {
                    if (!preg_match('/^[\w-]+$/', $database)) {
                        throw new InvalidArgumentException("Invalid database name '$database', allowed characters: a-z, A-Z, 0-9, _, -");
                    }
                    $dbCreateQuery = "CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                    $this->mysqli->real_connect($this->secret('hostname'), $this->secret('username'), $this->secret('password'), null, null, null, $flags);
                    try {
                        $this->mysqli->query($dbCreateQuery);
                        $this->mysqli->select_db($database);
                    } catch (mysqli_sql_exception $createErr) {
                        throw new RuntimeException("Couldn't create/select database '$database': {$createErr->getMessage()}", $createErr->getCode(), $createErr);
                    }
                } else {
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            $errorCode    = $this->mysqli->connect_errno;
            $baseErrorMsg = "MySQL Error($errorCode)";
            $errorDetail  = $e->getMessage() ?: $this->mysqli->connect_error;

            // Detect WSL + Unix socket failure
            $isWslSocketError = isset($_SERVER['WSL_DISTRO_NAME']) && $errorCode === 2002 && preg_match('/No such file/i', $errorDetail) && preg_match('/^localhost$/i', (string) $this->secret('hostname'));

            $errorMsg = match (true) {
                $isWslSocketError                        => "'localhost' uses Unix sockets. To connect to Windows MySQL from WSL, use '127.0.0.1' or 'localhost:3306' with WSL mirrored networking.\n$baseErrorMsg: $errorDetail",
                $errorCode === 2002                      => "Couldn't connect to server, check database server is running and connection settings are correct.\n$baseErrorMsg: $errorDetail",
                $errorCode === 2006 && $this->requireSSL => "Try disabling 'requireSSL' in database configuration.\n$baseErrorMsg: $errorDetail",
                default                                  => "$baseErrorMsg: $errorDetail",
            };
            throw new RuntimeException($errorMsg);
        }

        // Set charset - DO THIS FIRST
        if ($this->mysqli->character_set_name() !== 'utf8mb4') {
            $this->mysqli->set_charset('utf8mb4');
        }

        // Check mysql version
        if ($this->versionRequired) {
            $currentVersion = preg_replace("/[^0-9.]/", '', $this->mysqli->server_info);
            if (version_compare($this->versionRequired, $currentVersion, '>')) {
                $error  = "This program requires MySQL v$this->versionRequired or newer. This server has v$currentVersion installed.\n";
                $error .= "Please ask your server administrator to install MySQL v$this->versionRequired or newer.\n";
                throw new RuntimeException($error);
            }
        }

        // Set MySQL server variables
        $sets = $this->usePhpTimezone ? "time_zone = '" . date('P') . "', " : '';
        $sets .= $this->sqlMode ? "sql_mode = '$this->sqlMode', " : '';
        if ($sets = rtrim($sets, ', ')) {
            $this->mysqli->real_query("SET $sets");
        }
    }

    /**
     * Check if database connection was made and optionally check if it's still active.
     *
     * @param bool $ping Whether to ping the server to check for active connection
     * @return bool True if connected (and responsive if $ping is true)
     */
    public function isConnected(bool $ping = false): bool
    {
        if (!$this->mysqli) {
            return false;
        }
        if (!$ping) {
            return true;
        }

        try {
            return $this->mysqli->stat() !== false;
        } catch (mysqli_sql_exception) {
            return false;
        }
    }

    /**
     * Disconnect from the database.
     */
    public function disconnect(): void
    {
        if ($this->mysqli instanceof mysqli) {
            $this->mysqli->close();
            $this->mysqli = null;
        }
    }

    /**
     * Clone this connection with optional config overrides.
     * The clone shares the mysqli connection but has its own settings.
     *
     *     $db->clone()                             // Clone with same settings
     *     $db->clone(['useSmartStrings' => false]) // Clone with overrides
     *
     * @param array{
     *     tablePrefix?:     string,    // Prefix for table names
     *     useSmartJoins?:   bool,      // Add `table.column` keys to JOIN results, first-wins on duplicate columns
     *     useSmartStrings?: bool,      // Wrap values in SmartString objects
     * } $config Configuration overrides
     * @return self New Connection instance sharing this connection
     */
    public function clone(array $config = []): self
    {
        // Only these settings are meaningful on a clone (other settings are connect-time only)
        $allowedKeys = ['tablePrefix', 'useSmartJoins', 'useSmartStrings'];
        $invalidKeys = array_diff(array_keys($config), $allowedKeys);
        if ($invalidKeys) {
            throw new InvalidArgumentException("clone() only supports: " . implode(', ', $allowedKeys) . ". Got: " . implode(', ', $invalidKeys));
        }

        $clone = clone $this;
        $clone->sealSecrets(source: $this);

        foreach ($config as $key => $value) {
            $clone->$key = $value;
        }

        return $clone;
    }

    //endregion
    //region Query Methods

    /**
     * Execute a raw SQL query and return results.
     * Encrypted columns (MEDIUMBLOB) are automatically decrypted on read. For INSERT/UPDATE
     * queries, use DB::encryptValue() to encrypt values manually (DB::insert/update do this
     * automatically).
     *
     * @param string $sqlTemplate
     * @param mixed  ...$params Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws InvalidArgumentException
     */
    public function query(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        $this->mysqli->lastQuery = $sqlTemplate;

        // Validate
        $this->assertSafeTemplate($sqlTemplate);

        // Bind params and build SQL
        $this->paramValues = $this->parseParams($params);
        $sql               = $this->replacePlaceholders($sqlTemplate);

        // Execute
        $result = $this->mysqli->query($sql);
        $rows   = $this->fetchMappedRows($result);

        return $this->toSmartArray($rows, $sql);
    }

    /**
     * Execute a raw SQL query and return the first row.
     * Encrypted columns (MEDIUMBLOB) are automatically decrypted on read. For INSERT/UPDATE
     * queries, use DB::encryptValue() to encrypt values manually (DB::insert/update do this
     * automatically).
     *
     *     $row = DB::queryOne("SELECT name, email FROM ::users WHERE num = ?", 5);
     *     echo "$row->name - $row->email";
     *
     *     $row = DB::queryOne("SELECT MAX(price) AS max_price FROM ::products");
     *     echo $row->max_price;
     *
     * @param string $sqlTemplate SQL statement with placeholders
     * @param mixed  ...$params   Parameters to bind
     * @return SmartArrayHtml First row, or empty SmartArrayHtml if no rows
     * @throws InvalidArgumentException
     */
    public function queryOne(string $sqlTemplate, ...$params): SmartArrayHtml
    {
        $this->mysqli->lastQuery = $sqlTemplate;  // set for reject-* errors; query() overwrites with the LIMIT-appended template

        $this->rejectLimitAndOffset($sqlTemplate);
        $this->rejectPostLimitClauses($sqlTemplate);

        $supportsLimit = preg_match('/^\s*(SELECT|WITH)\b/i', $sqlTemplate);
        $sqlTemplate   .= $supportsLimit ? ' LIMIT 1' : '';
        $resultSet     = $this->query($sqlTemplate, ...$params);

        return $resultSet->first()->asHtml(); // asHtml() ensures SmartNull from empty results becomes SmartArrayHtml
    }

    /**
     * Select rows from a table.
     * Encrypted columns (MEDIUMBLOB) are automatically decrypted when `encryptionKey` is set.
     *
     * @param string           $baseTable Table name (without prefix)
     * @param int|array|string $whereEtc  WHERE and other clauses (ORDER BY, LIMIT, etc.)
     * @param mixed            ...$params Parameters to bind
     * @return SmartArrayHtml Result set
     * @throws InvalidArgumentException
     */
    public function select(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        $fullTable               = $this->tablePrefix . $baseTable;
        $this->mysqli->lastQuery = "SELECT * FROM `$fullTable` [WHERE ...]";

        // Validate
        $this->assertValidTable($baseTable);
        $this->logDeprecatedNumericWhere($whereEtc);

        // Bind params and build SQL
        $this->paramValues = $this->parseParams($params);
        $sql               = "SELECT * FROM `$fullTable` {$this->whereFromArgs($whereEtc)}";

        // Execute
        $result = $this->mysqli->query($sql);
        $rows   = $this->fetchMappedRows($result);

        return $this->toSmartArray($rows, $sql, $baseTable);
    }

    /**
     * Select a single row from a table. Always sends LIMIT 1 to MySQL.
     * Encrypted columns (MEDIUMBLOB) are automatically decrypted when `encryptionKey` is set.
     *
     *     $user = DB::selectOne('users', ['num' => 5]);
     *     echo "$user->name - $user->email";
     *
     *     $user = DB::selectOne('users', "status = ?", 'Active');
     *
     * @param string           $baseTable Table name (without prefix)
     * @param int|array|string $whereEtc  WHERE and other clauses
     * @param mixed            ...$params Parameters to bind
     * @return SmartArrayHtml Single row or empty SmartArrayHtml
     * @throws InvalidArgumentException
     */
    public function selectOne(string $baseTable, int|array|string $whereEtc = [], ...$params): SmartArrayHtml
    {
        $fullTable               = $this->tablePrefix . $baseTable;
        $this->mysqli->lastQuery = "SELECT * FROM `$fullTable` [WHERE ...] LIMIT 1";

        $this->assertValidTable($baseTable);
        $this->logDeprecatedNumericWhere($whereEtc);
        $this->rejectLimitAndOffset($whereEtc);
        $this->rejectPostLimitClauses($whereEtc);

        $this->paramValues = $this->parseParams($params);
        $sql               = "SELECT * FROM `$fullTable` {$this->whereFromArgs($whereEtc)} LIMIT 1";

        $result    = $this->mysqli->query($sql);
        $rows      = $this->fetchMappedRows($result);
        $resultSet = $this->toSmartArray($rows, $sql, $baseTable);

        return $resultSet->first()->asHtml(); // asHtml() ensures SmartNull from empty results becomes SmartArrayHtml
    }

    /**
     * Insert a row into a table.
     * Encrypted columns (MEDIUMBLOB) are automatically encrypted when `encryptionKey` is set.
     *
     * @param string $baseTable Table name (without prefix)
     * @param array  $values    Column => value pairs
     * @return int Insert ID
     * @throws InvalidArgumentException
     */
    public function insert(string $baseTable, array $values): int
    {
        $fullTable               = $this->tablePrefix . $baseTable;
        $this->mysqli->lastQuery = "INSERT INTO `$fullTable` [SET ...]";

        // Validate
        $this->assertValidTable($baseTable);

        // Build SQL
        $this->encryptRow($fullTable, $values);
        $setClause = $this->buildSetClause($values);
        $sql       = "INSERT INTO `$fullTable` $setClause";

        // Execute
        $this->mysqli->query($sql);

        return $this->mysqli->insert_id;
    }

    /**
     * Update rows in a table.
     * Encrypted columns (MEDIUMBLOB) are automatically encrypted when `encryptionKey` is set.
     *
     * @param string           $baseTable Table name (without prefix)
     * @param array            $values    Column => value pairs to update
     * @param int|array|string $whereEtc  WHERE condition (required), may include ORDER BY, LIMIT
     * @param mixed            ...$params Parameters to bind
     * @return int Number of affected rows
     * @throws InvalidArgumentException
     */
    public function update(string $baseTable, array $values, int|array|string $whereEtc, ...$params): int
    {
        $fullTable               = $this->tablePrefix . $baseTable;
        $this->mysqli->lastQuery = "UPDATE `$fullTable` [SET ...] [WHERE ...]";

        $this->assertValidTable($baseTable);
        $this->logDeprecatedNumericWhere($whereEtc);
        $this->rejectEmptyWhere($whereEtc, 'UPDATE');

        // Detect likely reversed arguments: SET ['num' => 5] is almost always a mistake
        if (count($values) === 1 && in_array(array_key_first($values), ['num', 'id', 'ID'], true)) {
            throw new InvalidArgumentException("Suspicious SET clause: only updating '" . array_key_first($values) . "'. Did you reverse the arguments? Signature is: update(\$table, \$values, \$whereEtc)");
        }

        $this->encryptRow($fullTable, $values);

        $setClause               = $this->buildSetClause($values);
        $this->mysqli->lastQuery = str_replace('[SET ...]', $setClause, $this->mysqli->lastQuery);  // Replace [SET ...] in lastQuery with the resolved SET so parseParams / whereFromArgs errors below report real context
        $this->paramValues       = $this->parseParams($params);
        $sql                     = "UPDATE `$fullTable` $setClause {$this->whereFromArgs($whereEtc)}";

        $this->mysqli->query($sql);

        return $this->mysqli->affected_rows;
    }

    /**
     * Delete rows from a table.
     *
     * @param string           $baseTable Table name (without prefix)
     * @param int|array|string $whereEtc  WHERE condition (required), may include ORDER BY, LIMIT
     * @param mixed            ...$params Parameters to bind
     * @return int Number of affected rows
     * @throws InvalidArgumentException
     */
    public function delete(string $baseTable, int|array|string $whereEtc, ...$params): int
    {
        $fullTable               = $this->tablePrefix . $baseTable;
        $this->mysqli->lastQuery = "DELETE FROM `$fullTable` [WHERE ...]";

        $this->assertValidTable($baseTable);
        $this->logDeprecatedNumericWhere($whereEtc);
        $this->rejectEmptyWhere($whereEtc, 'DELETE');

        $this->paramValues = $this->parseParams($params);
        $sql               = "DELETE FROM `$fullTable` {$this->whereFromArgs($whereEtc)}";

        $this->mysqli->query($sql);

        return $this->mysqli->affected_rows;
    }

    /**
     * Count rows in a table.
     *
     *     $total = DB::count('users');
     *     $total = DB::count('users', ['status' => 'active']);
     *
     *     // Existence check (0 is falsy, any count is truthy)
     *     if (DB::count('users', ['email' => $email])) { ... }
     *
     *     // For large unindexed result sets, queryOne() is faster because
     *     // COUNT(*) scans all matching rows while this stops at the first:
     *     if (DB::queryOne("SELECT 1 FROM ::users WHERE status = ?", 'active')->isNotEmpty()) { ... }
     *
     * @param string           $baseTable Table name (without prefix)
     * @param int|array|string $whereEtc  WHERE and other clauses (but not LIMIT/OFFSET)
     * @param mixed            ...$params Parameters to bind
     * @return int Row count
     * @throws InvalidArgumentException
     */
    public function count(string $baseTable, int|array|string $whereEtc = [], ...$params): int
    {
        $fullTable               = $this->tablePrefix . $baseTable;
        $this->mysqli->lastQuery = "SELECT COUNT(*) FROM `$fullTable` [WHERE ...]";

        $this->assertValidTable($baseTable);
        $this->logDeprecatedNumericWhere($whereEtc);
        $this->rejectLimitAndOffset($whereEtc);

        $this->paramValues = $this->parseParams($params);
        $sql               = "SELECT COUNT(*) FROM `$fullTable` {$this->whereFromArgs($whereEtc)}";

        $result = $this->mysqli->query($sql);
        $row    = $result->fetch_row();
        $result->free();

        return (int)$row[0];
    }

    /**
     * Run a group of queries together, guaranteeing all changes are applied, or none are.
     * Exceptions trigger an explicit ROLLBACK. For die/exit/timeout, MySQL automatically
     * rolls back uncommitted transactions when the connection closes.
     * This ensures you never end up with partial data.
     *
     *     // Group related writes so they all succeed or all fail
     *     $orderId = DB::transaction(function() {
     *         DB::insert('orders', ['customer_id' => 42]);
     *         $orderId = DB::$mysqli->insert_id;
     *         DB::insert('order_items', ['order_id' => $orderId, 'product_id' => 7]);
     *         return $orderId;
     *     });
     *
     * ***IMPORTANT:*** In addition to preventing partial writes, you must also *lock rows*
     * to prevent race conditions where other connections change your data between your read
     * and write.
     *
     * SELECT ... FOR UPDATE, INSERT, UPDATE, and DELETE lock affected rows from the moment
     * they run until the transaction commits or rolls back. Locked rows can still be read by
     * plain SELECTs, but any locking operation from other connections (SELECT ... FOR UPDATE,
     * INSERT, UPDATE, DELETE) will wait until your transaction completes.
     *
     * Use SELECT ... FOR UPDATE to lock rows you don't want changed while your transaction
     * is running.
     *
     *     // WRONG - two requests can both read qty=1, both sell the last item
     *     DB::transaction(function() use ($productId, $customerId) {
     *         $qty = DB::queryOne("SELECT qty FROM ::products WHERE id = ?", $productId)->qty->value();
     *         if ($qty < 1) { throw new RuntimeException("Out of stock"); }
     *         DB::update('products', ['qty' => $qty - 1], ['id' => $productId]);
     *         DB::insert('orders', ['customer_id' => $customerId, 'product_id' => $productId]);
     *     });
     *
     *     // RIGHT - FOR UPDATE locks the row, second request waits and reads updated qty
     *     DB::transaction(function() use ($productId, $customerId) {
     *         $qty = DB::query("SELECT qty FROM ::products WHERE id = ? FOR UPDATE", $productId)->first()->qty->value();
     *         if ($qty < 1) { throw new RuntimeException("Out of stock"); }
     *         DB::update('products', ['qty' => $qty - 1], ['id' => $productId]);
     *         DB::insert('orders', ['customer_id' => $customerId, 'product_id' => $productId]);
     *     });
     *
     *     // TIP - single queries are already atomic, no transaction needed
     *     DB::query("UPDATE ::counters SET views = views + 1 WHERE id = ?", $pageId);
     *
     * ***WARNING:*** Only use DML (SELECT, INSERT, UPDATE, DELETE) inside transactions.
     * When MySQL encounters a DDL statement (CREATE, ALTER, DROP, TRUNCATE, etc.),
     * it commits all pending work, executes the DDL, and ends the transaction.
     * Everything after runs in autocommit mode - each query committed on its own,
     * with no ROLLBACK possible.
     *
     * TRUNCATE is the common trap - it looks like DELETE, but it's DDL:
     *
     *     // WRONG - TRUNCATE is DDL, ends the transaction
     *     DB::transaction(function() {
     *         DB::insert('cache', [...]);                // queued in the transaction
     *         DB::query("TRUNCATE TABLE ::cache");       // commits the INSERT, truncates, transaction is OVER
     *         DB::insert('cache', [...]);                // auto-committed immediately, on its own
     *         DB::insert('cache', [...]);                // if this fails, everything above is permanent
     *     });
     *
     *     // RIGHT - DELETE is DML, fully transactional
     *     DB::transaction(function() {
     *         DB::query("DELETE FROM ::cache");           // can be rolled back
     *         DB::insert('cache', [...]);
     *         DB::insert('cache', [...]);
     *     });
     *
     * @param callable $fn A function with the operations to execute within the transaction
     * @return mixed The return value of the callable
     * @throws RuntimeException If called while already in a transaction
     * @throws Throwable Re-throws any exception after rolling back
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->mysqli->inTransaction) {
            throw new RuntimeException("transaction() cannot be nested - already in a transaction");
        }

        $this->mysqli->query("START TRANSACTION");
        $this->mysqli->inTransaction = true;
        try {
            $result = $fn();
            $this->mysqli->query("COMMIT");
            return $result;
        } catch (Throwable $e) {
            $this->mysqli->query("ROLLBACK");
            throw $e;
        } finally {
            $this->mysqli->inTransaction = false;
        }
    }

    //endregion
    //region Table Helpers

    /**
     * Extracts base table name by removing table prefix.
     *
     * Without checkDb, blindly strips the prefix if present:
     *
     *     // prefix = "test_"
     *     DB::getBaseTable('test_users');         // 'users'
     *     DB::getBaseTable('users');              // 'users' (no prefix found)
     *     DB::getBaseTable('test_cities');        // 'cities'
     *
     * With checkDb, checks the database when the input starts with the prefix.
     * This handles base names that themselves start with the prefix string.
     * For example, if the base name IS "test_cities", its full name would be
     * "test_test_cities". So checkDb checks: does test_test_cities exist?
     *
     *     // prefix = "test_", table "test_test_cities" exists in database
     *     DB::getBaseTable('test_cities', checkDb: true);      // 'test_cities' (test_test_cities exists, so it's a base name)
     *     DB::getBaseTable('test_users', checkDb: true);       // 'users' (test_test_users doesn't exist, so it was prefixed)
     *     DB::getBaseTable('test_nonexistent', checkDb: true); // 'nonexistent' (not found, strips prefix)
     *
     * @param string $table   Table name with or without prefix
     * @param bool   $checkDb When input starts with the prefix, query the database to check
     *                        if prefixing it AGAIN yields a real table; if so, keep the input as-is
     * @return string Base table name without prefix
     */
    public function getBaseTable(string $table, bool $checkDb = false): string
    {
        if (str_starts_with($table, $this->tablePrefix)) {
            /* If hasTable($table) finds a match, the input is actually a base name
               (hasTable prepends the prefix, so it's checking for "test_test_cities") */
            if ($checkDb && $this->hasTable($table)) {
                return $table;
            }
            return substr($table, strlen($this->tablePrefix));
        }

        return $table;
    }

    /**
     * Returns the full table name with the current table prefix.
     *
     * Without checkDb, assumes any input starting with the prefix is already full:
     *
     *     // prefix = "test_"
     *     DB::getFullTable('users');              // 'test_users'
     *     DB::getFullTable('test_users');         // 'test_users' (already starts with prefix)
     *     DB::getFullTable('test_cities');        // 'test_cities' (assumed already prefixed)
     *
     * With checkDb, checks the database when the input starts with the prefix.
     * This handles base names that themselves start with the prefix string.
     * For example, "test_cities" could be a full name (table exists) or a base
     * name that needs prefixing to "test_test_cities".
     *
     *     // prefix = "test_", table "test_verify_full" exists in database
     *     DB::getFullTable('test_verify_full', checkDb: true);  // 'test_verify_full' (exists in DB, already full)
     *     DB::getFullTable('test_nonexistent', checkDb: true);  // 'test_test_nonexistent' (not found, must be base name)
     *     DB::getFullTable('users', checkDb: true);             // 'test_users' (no ambiguity, just prefixes)
     *
     * @param string $table   Table name (base or full)
     * @param bool   $checkDb When input starts with the prefix, query the database to check
     *                        if it exists as-is; if not, treat it as a base name and add prefix
     * @return string Full table name with prefix
     */
    public function getFullTable(string $table, bool $checkDb = false): string
    {
        if (!str_starts_with($table, $this->tablePrefix)) {
            return $this->tablePrefix . $table;
        }

        /* If hasTable($table, isPrefixed: true) finds no match, the input is a base name
           that happens to start with the prefix string - prefix it */
        if ($checkDb && !$this->hasTable($table, isPrefixed: true)) {
            return $this->tablePrefix . $table;
        }

        return $table;
    }

    /**
     * Retrieve table names matching the configured prefix.
     *
     * Returns base tables only, not views or temporary tables.
     * Uses INFORMATION_SCHEMA instead of SHOW TABLES LIKE to avoid
     * MariaDB MDEV-32973 (LIKE pattern ignored when temp tables exist).
     *
     * @param bool $withPrefix If true, return names with prefix
     * @return string[] Array of table names
     * @throws InvalidArgumentException
     * @see https://jira.mariadb.org/browse/MDEV-32973
     * @noinspection SpellCheckingInspection for MDEV
     */
    public function getTableNames(bool $withPrefix = false): array
    {
        $prefixLength     = strlen($this->tablePrefix);
        $escapedPrefix    = $this->mysqli->real_escape_string($this->tablePrefix);
        $query            = <<<__SQL__
            SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND LEFT(TABLE_NAME, $prefixLength) = '$escapedPrefix'
              AND TABLE_TYPE = 'BASE TABLE'
            __SQL__;
        $result           = $this->mysqli->query($query);
        $tableNames       = array_column($result->fetch_all(), 0);
        $result->free();

        // Sort _tables to the bottom
        usort($tableNames, fn($a, $b) => (($a[$prefixLength] ?? '') === '_') <=> (($b[$prefixLength] ?? '') === '_') ?: ($a <=> $b));

        // Remove prefix
        if (!$withPrefix) {
            $tableNames = array_map(fn($name) => substr($name, $prefixLength), $tableNames);
        }

        return $tableNames;
    }

    /**
     * Get column definitions from a table.
     *
     * @param string $baseTable The base table name without prefix
     * @return array<string,string> Array of column name => column definition pairs
     */
    public function getColumnDefinitions(string $baseTable): array
    {
        $columnDefinitions = [];

        try {
            $createTableSQL = $this->query('SHOW CREATE TABLE `::?`', $baseTable)->first()->nth(1)->value();
            $lines          = explode("\n", $createTableSQL);
        } catch (mysqli_sql_exception) {
            $lines = [];
        }

        // Extract charset/collation from last line (table defaults)
        $defaults = [];
        if (preg_match('/\bDEFAULT CHARSET=(\S+) COLLATE=(\S+)\b/', (string) array_pop($lines), $m)) {
            $defaults = [" CHARACTER SET $m[1]", " COLLATE $m[2]"];
        }

        // Get column definitions
        $intTypesRx = 'tinyint|smallint|mediumint|int|bigint';
        foreach ($lines as $line) {
            if (preg_match('/^ {2}`([^`]+)` (.*?),?$/', $line, $matches)) {
                [, $columnName, $definition] = $matches;
                $definition                     = str_replace($defaults, '', $definition);
                $definition                     = preg_replace("/\b($intTypesRx)\(\d+\)/i", '$1', $definition);
                $columnDefinitions[$columnName] = $definition;
            }
        }

        return $columnDefinitions;
    }

    /**
     * Check if a table, view, or temporary table exists in the database.
     *
     * Returns true/false, never throws. Invalid table names return false.
     *
     * @param string $table      Table name
     * @param bool   $isPrefixed If true, table name already includes the prefix
     * @return bool
     */
    public function hasTable(string $table, bool $isPrefixed = false): bool
    {
        $fullTable = $isPrefixed ? $table : $this->tablePrefix . $table;
        try {
            $this->assertValidTable($fullTable);
            $result = $this->mysqli->query("SELECT 1 FROM `$fullTable` LIMIT 0");
            $result->free();
            return true;
        } catch (InvalidArgumentException|mysqli_sql_exception) {
            return false;
        }
    }

    //endregion
    //region SQL Generation

    /**
     * Creates a MySQL LIKE pattern for "column contains value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%value%'
     */
    public function likeContains(string|int|float|null|SmartString $input): RawSql
    {
        return new RawSql("'%" . $this->escape($input, true) . "%'");
    }

    /**
     * Creates a MySQL LIKE pattern for matching values in tab-delimited columns.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%\tValue\t%'
     */
    public function likeContainsTSV(string|int|float|null|SmartString $input): RawSql
    {
        return new RawSql("'%\\t" . $this->escape($input, true) . "\\t%'");
    }

    /**
     * Creates a MySQL LIKE pattern for "column starts with value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern 'value%'
     */
    public function likeStartsWith(string|int|float|null|SmartString $input): RawSql
    {
        return new RawSql("'" . $this->escape($input, true) . "%'");
    }

    /**
     * Creates a MySQL LIKE pattern for "column ends with value" searches.
     *
     * @param string|int|float|null|SmartString $input Value to search for
     * @return RawSql Escaped LIKE pattern '%value'
     */
    public function likeEndsWith(string|int|float|null|SmartString $input): RawSql
    {
        return new RawSql("'%" . $this->escape($input, true) . "'");
    }

    //endregion
    //region Encryption

    /**
     * Encrypt a value in PHP, producing the same ciphertext that DB::insert() and
     * DB::update() auto-generate for MEDIUMBLOB columns.
     *
     * You normally don't need this - insert/update handle encryption for you. Reach
     * for it when the value isn't going through those methods:
     *
     *     // Exact match on an encrypted column (AES-128-ECB is deterministic)
     *     DB::select('users', ['token' => DB::encryptValue($searchToken)]);
     *
     *     // Raw SQL insert/update via DB::query()
     *     DB::query("UPDATE ::users SET token = ? WHERE id = ?", DB::encryptValue($token), $id);
     *
     * Requires `encryptionKey` on the connection; aesKey() throws RuntimeException without one.
     *
     * @param string|int|float|null|SmartString $value Plaintext value to encrypt
     * @return string|null Encrypted binary string, or null if value is null
     */
    public function encryptValue(string|int|float|null|SmartString $value): string|null
    {
        if ($value instanceof SmartString) {
            $value = $value->value();
        }
        if (is_null($value)) {
            return null;
        }

        return openssl_encrypt((string) $value, 'aes-128-ecb', $this->aesKey(), OPENSSL_RAW_DATA);
    }

    /**
     * Decrypt MEDIUMBLOB-column values in fetched rows, in place. Called automatically
     * by DB::select(), DB::query(), and other read methods.
     *
     * A MEDIUMBLOB column stores either encrypted values or raw binary. Not both. We
     * can't support mixing because on read there's no reliable way to tell them apart:
     * openssl_decrypt returns false when decryption fails, but random binary data whose
     * length is a multiple of 16 bytes passes the padding check about 1 in 255 times and
     * silently decrypts to garbage. If you're enabling encryption on existing MEDIUMBLOB
     * data, re-encrypt those rows first.
     *
     * Which mode applies is decided by `encryptionKey`. With it set, every MEDIUMBLOB is
     * decrypted on read. Without it, this is a no-op and MEDIUMBLOB rows pass through as
     * raw bytes.
     *
     * You rarely need to call this directly. Reach for it when you've bypassed the query
     * methods and want PHP-side decryption on raw mysqli rows:
     *
     *     $rows          = $result->fetch_all(MYSQLI_ASSOC);
     *     $mysqliNumRows = $result->fetch_all(MYSQLI_NUM);
     *     DB::decryptRows($rows, $result->fetch_fields());         // detect encrypted cols from field metadata
     *     DB::decryptRows($rows, ['token', 'secret']);             // assoc rows: pass column names
     *     DB::decryptRows($mysqliNumRows, [0, 3]);                 // numeric rows: pass field indexes
     *
     * To decrypt in MySQL instead of PHP, use AES_DECRYPT with @ek:
     *
     *     SELECT AES_DECRYPT(`column`, @ek) FROM prefix_users
     *     SELECT {{column}} FROM ::users                           // ZenDB shorthand for the above
     *
     * @param array $rows             Fetched rows (modified in place)
     * @param array $keysOrFetchFields Either a list of row keys (column names for assoc rows, indexes for numeric rows),
     *                                 or field objects from fetch_fields() (auto-detects encrypted cols)
     */
    public function decryptRows(array &$rows, array $keysOrFetchFields): void
    {
        if (!$rows || !$keysOrFetchFields || !$this->secret('encryptionKey')) {
            return;
        }

        // Detect input shape: fetch_fields() returns stdClass objects; otherwise treat as a list of row keys.
        // The fetch_fields() path is actively used by external callers decrypting raw mysqli results - do not remove.
        $isFetchFields = is_object(reset($keysOrFetchFields));
        $keysToDecrypt = $isFetchFields ? DB::getEncryptedColumns($keysOrFetchFields) : $keysOrFetchFields;
        if (!$keysToDecrypt) {
            return;
        }

        // Decrypt at each requested key (column name for assoc rows, field index for numeric rows)
        $aesKey = $this->aesKey();
        foreach ($rows as &$row) {
            foreach ($keysToDecrypt as $key) {
                if ($row[$key] === null) {
                    continue;
                }
                $decrypted = openssl_decrypt($row[$key], 'aes-128-ecb', $aesKey, OPENSSL_RAW_DATA);
                if ($decrypted !== false) {
                    $row[$key] = $decrypted;
                }
            }
        }
        unset($row);
    }

    /**
     * Encrypt MEDIUMBLOB-column values in an insert/update values array, in place. Called
     * automatically by DB::insert() and DB::update() before values go to the database.
     *
     * A MEDIUMBLOB column stores either encrypted values or raw binary. Not both. We
     * can't support mixing because on read there's no reliable way to tell them apart:
     * openssl_decrypt returns false when decryption fails, but random binary data whose
     * length is a multiple of 16 bytes passes the padding check about 1 in 255 times and
     * silently decrypts to garbage.
     *
     * Which mode applies is decided by `encryptionKey`. With it set, every MEDIUMBLOB is
     * encrypted on write and decrypted on read. Without it, every MEDIUMBLOB is raw bytes.
     *
     * @param string $fullTable Full table name (with prefix)
     * @param array  $values    Column => value pairs (modified in place)
     */
    private function encryptRow(string $fullTable, array &$values): void
    {
        if (!$values || !$this->secret('encryptionKey')) {
            return;
        }

        // Cache the encrypted column list per connection per table (one LIMIT 0 query per table, per request)
        static $tableCache = new WeakMap();
        if (!isset($tableCache[$this][$fullTable])) {
            $tableCache[$this] ??= [];
            $result = $this->mysqli->query("SELECT * FROM `$fullTable` LIMIT 0");
            $tableCache[$this][$fullTable] = DB::getEncryptedColumns($result->fetch_fields());
            $result->free();
        }

        /** @noinspection PhpIllegalArrayKeyTypeInspection */
        $encryptedCols = $tableCache[$this][$fullTable];
        if (!$encryptedCols) {
            return;
        }

        // Encrypt each targeted value (intersect $values with encrypted columns, skip null / non-scalar)
        $toEncrypt = array_intersect_key($values, array_flip($encryptedCols));
        $aesKey    = $this->aesKey();
        foreach ($toEncrypt as $col => $value) {
            if ($value === null) {
                continue;
            }
            if ($value instanceof SmartString) {
                $value = $value->value();
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                continue; // skip RawSql, arrays, and other non-scalar types
            }
            $values[$col] = openssl_encrypt((string) $value, 'aes-128-ecb', $aesKey, OPENSSL_RAW_DATA);
        }
    }

    /**
     * Derive and cache the AES-128 key matching MySQL's AES_ENCRYPT() key handling.
     * MySQL XOR-folds the SHA-512 hash into a 16-byte key for AES-128-ECB.
     */
    private function aesKey(): string
    {
        static $cache = new WeakMap();
        if (!isset($cache[$this])) {
            $encryptionKey = $this->secret('encryptionKey') ?: throw new RuntimeException("aesKey() requires 'encryptionKey' in connection config.");
            $keyBytes      = hash('sha512', $encryptionKey, true);
            $cache[$this]  = substr($keyBytes, 0, 16);
            $cache[$this] ^= substr($keyBytes, 16, 16);
            $cache[$this] ^= substr($keyBytes, 32, 16);
            $cache[$this] ^= substr($keyBytes, 48, 16);
        }

        return $cache[$this];
    }

    //endregion
}
