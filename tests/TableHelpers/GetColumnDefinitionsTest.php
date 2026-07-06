<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\TableHelpers;

use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for DB::getColumnDefinitions() method
 *
 * @covers \Itools\ZenDB\Connection::getColumnDefinitions
 */
class GetColumnDefinitionsTest extends BaseTestCase
{
    //region Setup

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //endregion
    //region Tests

    public function testReturnsColumnDefinitions(): void
    {
        $columns = DB::getColumnDefinitions('users');

        $this->assertIsArray($columns);
        $this->assertArrayHasKey('num', $columns);
        $this->assertArrayHasKey('name', $columns);
        $this->assertArrayHasKey('status', $columns);
    }

    public function testIncludesAllColumns(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // Check for expected columns
        $expectedColumns = ['num', 'name', 'isAdmin', 'status', 'city', 'dob', 'age'];
        foreach ($expectedColumns as $col) {
            $this->assertArrayHasKey($col, $columns, "Missing column: $col");
        }
    }

    public function testStripsIntDisplayWidth(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // INT should not have display width like int(11)
        // Note: MySQL 8.0.17+ deprecated display width for int types
        $numDef = $columns['num'] ?? '';
        $this->assertStringNotContainsString('int(', strtolower($numDef));
    }

    public function testColumnTypesAreCorrect(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // num should be INT
        $this->assertStringContainsStringIgnoringCase('int', $columns['num']);

        // name should be VARCHAR
        $this->assertStringContainsStringIgnoringCase('varchar', $columns['name']);

        // status should be ENUM
        $this->assertStringContainsStringIgnoringCase('enum', $columns['status']);

        // dob should be DATE
        $this->assertStringContainsStringIgnoringCase('date', $columns['dob']);
    }

    public function testStripsDefaultCharset(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // Default charset/collation should be stripped
        foreach ($columns as $definition) {
            // Common default charset patterns that should be removed
            $this->assertStringNotContainsString('CHARACTER SET utf8mb4', $definition);
            $this->assertStringNotContainsString('COLLATE utf8mb4_unicode_ci', $definition);
        }
    }

    public function testNonExistingTableReturnsEmpty(): void
    {
        $columns = DB::getColumnDefinitions('nonexistent_table');

        $this->assertIsArray($columns);
        $this->assertEmpty($columns);
    }

    public function testOrdersTableColumns(): void
    {
        $columns = DB::getColumnDefinitions('orders');

        $this->assertArrayHasKey('order_id', $columns);
        $this->assertArrayHasKey('user_id', $columns);
        $this->assertArrayHasKey('order_date', $columns);
        $this->assertArrayHasKey('total_amount', $columns);
    }

    public function testDecimalTypePreserved(): void
    {
        $columns = DB::getColumnDefinitions('orders');

        // total_amount is DECIMAL(10,2)
        $amountDef = $columns['total_amount'] ?? '';
        $this->assertStringContainsStringIgnoringCase('decimal', $amountDef);
    }

    public function testNullableInfo(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // isAdmin is TINYINT(1) NULL
        $isAdminDef = $columns['isAdmin'] ?? '';
        $this->assertStringContainsString('NULL', $isAdminDef);
    }

    public function testAutoIncrementInfo(): void
    {
        $columns = DB::getColumnDefinitions('users');

        // num should have AUTO_INCREMENT
        $numDef = $columns['num'] ?? '';
        $this->assertStringContainsStringIgnoringCase('auto_increment', $numDef);
    }

    public function testPrimaryKeyColumn(): void
    {
        // Primary key info might be in the column definition
        $columns = DB::getColumnDefinitions('users');
        $numDef = $columns['num'] ?? '';

        // Either has PRIMARY KEY in definition or auto_increment (which implies PK for InnoDB)
        $hasPrimaryInfo = stripos($numDef, 'auto_increment') !== false;
        $this->assertTrue($hasPrimaryInfo);
    }

    //endregion
    //region Normalization

    public function testKeepsTinyint1DisplayWidth(): void
    {
        // isAdmin is TINYINT(1) - the conventional boolean marker, kept as-is
        $columns = DB::getColumnDefinitions('users');
        $this->assertStringStartsWith('tinyint(1)', $columns['isAdmin']);
    }

    public function testStripsOtherDisplayWidths(): void
    {
        // DDL runs through mysqli directly - query templates reject standalone numbers
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_schema_norm");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_schema_norm (
            flag         TINYINT(1),
            flagUnsigned TINYINT(1) UNSIGNED,
            level        TINYINT(4),
            qty          SMALLINT(6),
            pos          MEDIUMINT(9),
            views        BIGINT(20),
            yr           YEAR
        )");
        $columns = DB::getColumnDefinitions('schema_norm');

        // MySQL 8.0.19+ keeps the width only for plain signed tinyint(1) (MySQL bugs #100309/#105667),
        // and drops year(4) along with the int widths
        $this->assertStringStartsWith('tinyint(1)',       $columns['flag']);
        $this->assertStringStartsWith('tinyint unsigned', $columns['flagUnsigned']);
        $this->assertMatchesRegularExpression('/^tinyint(?: |$)/',   $columns['level']);
        $this->assertMatchesRegularExpression('/^smallint(?: |$)/',  $columns['qty']);
        $this->assertMatchesRegularExpression('/^mediumint(?: |$)/', $columns['pos']);
        $this->assertMatchesRegularExpression('/^bigint(?: |$)/',    $columns['views']);
        $this->assertMatchesRegularExpression('/^year(?: |$)/',      $columns['yr']);
    }

    public function testKeepsZerofillDisplayWidth(): void
    {
        // Zerofill widths set the zero-padding amount, and MySQL 8 keeps emitting them
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_schema_zerofill");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_schema_zerofill (
            padded INT(5) UNSIGNED ZEROFILL
        )");
        $columns = DB::getColumnDefinitions('schema_zerofill');

        $this->assertStringStartsWith('int(5) unsigned zerofill', $columns['padded']);
    }

    public function testCropsLeadingTypeOnlyNeverQuotedText(): void
    {
        // Widths, lengths, and precision that are not int display widths stay untouched,
        // including 'int(11)' appearing as text inside a quoted default
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_schema_leading");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_schema_leading (
            initial CHAR(1) NOT NULL,
            price   DECIMAL(10,2) NOT NULL,
            label   VARCHAR(50) NOT NULL DEFAULT 'int(11)'
        )");
        $columns = DB::getColumnDefinitions('schema_leading');

        $this->assertStringStartsWith('char(1)',       $columns['initial']);
        $this->assertStringStartsWith('decimal(10,2)', $columns['price']);
        $this->assertStringContainsString("DEFAULT 'int(11)'", $columns['label']);
    }

    public function testStripsColumnCharsetMatchingTableDefault(): void
    {
        // A column-level COLLATE override makes SHOW CREATE TABLE emit both
        // CHARACTER SET and COLLATE for that column. The charset matches the
        // table default so it should be stripped; the collation difference is
        // the meaningful part and should be kept. A column with a different
        // charset keeps its full CHARACTER SET / COLLATE clause. The enum
        // column proves the quotes inside the type don't block the strip.
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_schema_charset");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_schema_charset (
            title  VARCHAR(50) COLLATE utf8mb4_bin,
            status ENUM('yes','no') COLLATE utf8mb4_bin,
            code   VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin
        ) DEFAULT CHARSET=utf8mb4");
        $columns = DB::getColumnDefinitions('schema_charset');

        $this->assertStringNotContainsString('CHARACTER SET', $columns['title']);
        $this->assertStringContainsString('COLLATE utf8mb4_bin', $columns['title']);
        $this->assertStringStartsWith("enum('yes','no') COLLATE utf8mb4_bin", $columns['status']);
        $this->assertStringContainsString('CHARACTER SET ascii COLLATE ascii_bin', $columns['code']);
    }

    public function testNormalizationIgnoresCommentText(): void
    {
        // Comment text is split off before normalizing and reattached verbatim, so words
        // like 'zerofill' or 'CHARACTER SET utf8mb4' in a comment can't affect the result.
        // SHOW CREATE TABLE doubles single quotes inside comments: 'it''s a flag'
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_schema_comment");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_schema_comment (
            bits INT(11) NOT NULL COMMENT 'bitmask, not zerofill',
            note VARCHAR(100) COMMENT 'stored with CHARACTER SET utf8mb4 for emoji',
            flag TINYINT(1) COMMENT 'it''s a flag'
        ) DEFAULT CHARSET=utf8mb4");
        $columns = DB::getColumnDefinitions('schema_comment');

        $this->assertSame("int NOT NULL COMMENT 'bitmask, not zerofill'", $columns['bits']);
        $this->assertStringContainsString("COMMENT 'stored with CHARACTER SET utf8mb4 for emoji'", $columns['note']);
        $this->assertStringContainsString("COMMENT 'it''s a flag'", $columns['flag']);
    }

    public function testNormalizesCurrentTimestampSpelling(): void
    {
        // MariaDB 10.2+ emits DEFAULT current_timestamp(), MySQL emits DEFAULT CURRENT_TIMESTAMP
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_schema_ts");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_schema_ts (
            created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $columns = DB::getColumnDefinitions('schema_ts');

        $this->assertSame('datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $columns['created']);
    }

    //endregion
    //region Partitioned Tables

    // Partitioned tables append PARTITION clauses after the table-options line in SHOW CREATE
    // TABLE output, and they can't be TEMPORARY, so these tests create real tables. The leading
    // DROP cleans up leftovers from any earlier failed run; the DROP after the fetch runs before
    // assertions so a failure doesn't leave the table behind.

    public function testFindsTableDefaultsOnHashPartitionedTable(): void
    {
        // HASH emits a short tail: " PARTITION BY HASH (`id`)" then "PARTITIONS 2"
        DB::$mysqli->query("DROP TABLE IF EXISTS test_schema_part_hash");
        DB::$mysqli->query("CREATE TABLE test_schema_part_hash (
            id    INT NOT NULL PRIMARY KEY,
            title VARCHAR(50) COLLATE utf8mb4_bin
        ) DEFAULT CHARSET=utf8mb4 PARTITION BY HASH (id) PARTITIONS 2");
        $columns = DB::getColumnDefinitions('schema_part_hash');
        DB::$mysqli->query("DROP TABLE IF EXISTS test_schema_part_hash");

        // PARTITION lines must not hide the table-defaults line or parse as columns
        $this->assertSame(['id', 'title'], array_keys($columns));
        $this->assertSame('int NOT NULL', $columns['id']);
        $this->assertStringNotContainsString('CHARACTER SET', $columns['title']);
        $this->assertStringContainsString('COLLATE utf8mb4_bin', $columns['title']);
    }

    public function testFindsTableDefaultsOnRangePartitionedTable(): void
    {
        // RANGE emits one line per partition, wrapped in /*!50100 ... */ on MySQL
        DB::$mysqli->query("DROP TABLE IF EXISTS test_schema_part_range");
        DB::$mysqli->query("CREATE TABLE test_schema_part_range (
            id    INT NOT NULL PRIMARY KEY,
            title VARCHAR(50) COLLATE utf8mb4_bin
        ) DEFAULT CHARSET=utf8mb4
          PARTITION BY RANGE (id) (
            PARTITION p0   VALUES LESS THAN (100),
            PARTITION pmax VALUES LESS THAN MAXVALUE
          )");
        $columns = DB::getColumnDefinitions('schema_part_range');
        DB::$mysqli->query("DROP TABLE IF EXISTS test_schema_part_range");

        $this->assertSame(['id', 'title'], array_keys($columns));
        $this->assertSame('int NOT NULL', $columns['id']);
        $this->assertStringNotContainsString('CHARACTER SET', $columns['title']);
        $this->assertStringContainsString('COLLATE utf8mb4_bin', $columns['title']);
    }

    //endregion
    //region Data Provider

    /**
     * @dataProvider provideTableColumnScenarios
     */
    public function testTableColumnScenarios(string $table, string $column, string $expectedType): void
    {
        $columns = DB::getColumnDefinitions($table);

        $this->assertArrayHasKey($column, $columns);
        $this->assertStringContainsStringIgnoringCase($expectedType, $columns[$column]);
    }

    public static function provideTableColumnScenarios(): array
    {
        return [
            ['users', 'num', 'int'],
            ['users', 'name', 'varchar'],
            ['users', 'status', 'enum'],
            ['users', 'dob', 'date'],
            ['users', 'age', 'int'],
            ['orders', 'order_id', 'int'],
            ['orders', 'total_amount', 'decimal'],
            ['products', 'price', 'decimal'],
            ['products', 'product_name', 'varchar'],
        ];
    }

    //endregion
}
