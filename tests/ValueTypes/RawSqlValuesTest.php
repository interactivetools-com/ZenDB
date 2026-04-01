<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\ValueTypes;

use Itools\ZenDB\DB;
use Itools\ZenDB\RawSql;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * Tests for RawSql value handling
 *
 * @covers \Itools\ZenDB\ConnectionInternals::getPlaceholderValue
 * @covers \Itools\ZenDB\DB::rawSql
 * @covers \Itools\ZenDB\DB::isRawSql
 */
class RawSqlValuesTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::resetTempTestTables();
    }

    //region RawSql Creation

    public function testRawSqlCreation(): void
    {
        $raw = DB::rawSql('NOW()');
        $this->assertInstanceOf(RawSql::class, $raw);
        $this->assertSame('NOW()', (string) $raw);
    }

    public function testRawSqlWithInteger(): void
    {
        $raw = DB::rawSql(123);
        $this->assertSame('123', (string) $raw);
    }

    public function testRawSqlWithFloat(): void
    {
        $raw = DB::rawSql(3.14);
        $this->assertSame('3.14', (string) $raw);
    }

    public function testRawSqlWithNull(): void
    {
        $raw = DB::rawSql(null);
        $this->assertSame('', (string) $raw);
    }

    //endregion
    //region isRawSql

    public function testIsRawSqlTrue(): void
    {
        $raw = DB::rawSql('NOW()');
        $this->assertTrue(DB::isRawSql($raw));
    }

    public function testIsRawSqlFalse(): void
    {
        $this->assertFalse(DB::isRawSql('NOW()'));
        $this->assertFalse(DB::isRawSql(123));
        $this->assertFalse(DB::isRawSql(null));
        $this->assertFalse(DB::isRawSql([]));
    }

    //endregion
    //region RawSql in INSERT

    public function testRawSqlInInsert(): void
    {
        // Use NOW() as a raw SQL value
        DB::query("DROP TEMPORARY TABLE IF EXISTS test_timestamps");
        DB::query("CREATE TEMPORARY TABLE test_timestamps (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME)");

        $insertId = DB::query("INSERT INTO test_timestamps SET created_at = ?", DB::rawSql('NOW()'));

        $row = DB::query("SELECT * FROM test_timestamps WHERE id = ?", DB::$mysqli->insert_id)->first();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row->get('created_at')->value());
    }

    public function testRawSqlInInsertViaSetClause(): void
    {
        DB::query("DROP TEMPORARY TABLE IF EXISTS test_raw_insert");
        DB::query("CREATE TEMPORARY TABLE test_raw_insert (id INT AUTO_INCREMENT PRIMARY KEY, value INT)");

        // Use expression as raw SQL
        DB::insert('raw_insert', ['value' => DB::rawSql('10 + 5')]);

        $row = DB::query("SELECT * FROM test_raw_insert LIMIT 1")->first();
        $this->assertSame(15, $row->get('value')->value());
    }

    //endregion
    //region RawSql in UPDATE

    public function testRawSqlInUpdate(): void
    {
        // Create test table
        DB::query("DROP TEMPORARY TABLE IF EXISTS test_counters");
        DB::query("CREATE TEMPORARY TABLE test_counters (id INT PRIMARY KEY, count INT)");
        DB::query("INSERT INTO test_counters VALUES (?, ?)", [1, 5]);

        // Increment using raw SQL expression
        DB::query("UPDATE test_counters SET count = ? WHERE id = ?", [DB::rawSql('count + 1'), 1]);

        $row = DB::query("SELECT * FROM test_counters WHERE id = ?", 1)->first();
        $this->assertSame(6, $row->get('count')->value());
    }

    public function testRawSqlInUpdateViaSetClause(): void
    {
        // Update using shorthand method
        DB::query("DROP TEMPORARY TABLE IF EXISTS test_counters2");
        DB::query("CREATE TEMPORARY TABLE test_counters2 (id INT PRIMARY KEY, count INT)");
        DB::query("INSERT INTO test_counters2 VALUES (?, ?)", [1, 10]);

        // Use update() with RawSql in SET clause
        DB::update('counters2', ['count' => DB::rawSql('count * 2')], ['id' => 1]);

        $row = DB::query("SELECT * FROM test_counters2 WHERE id = ?", 1)->first();
        $this->assertSame(20, $row->get('count')->value());
    }

    //endregion
    //region RawSql in WHERE

    public function testRawSqlInWhere(): void
    {
        // Use raw SQL expression in WHERE
        $result = DB::query("SELECT * FROM ::users WHERE age > ?", DB::rawSql('30'));
        $this->assertCount(14, $result);

        foreach ($result as $row) {
            $this->assertGreaterThan(30, $row->get('age')->value());
        }
    }

    public function testRawSqlExpressionInWhere(): void
    {
        // Complex expression
        $result = DB::query(
            "SELECT * FROM ::users WHERE age BETWEEN ? AND ?",
            [DB::rawSql('25'), DB::rawSql('35')]
        );

        foreach ($result as $row) {
            $age = $row->get('age')->value();
            $this->assertGreaterThanOrEqual(25, $age);
            $this->assertLessThanOrEqual(35, $age);
        }
    }

    //endregion
    //region RawSql Not Escaped

    public function testRawSqlNotEscaped(): void
    {
        // RawSql values should NOT be escaped
        $raw = DB::rawSql("CONCAT('Hello', ' ', 'World')");

        $result = DB::query("SELECT ? as greeting", $raw);
        $this->assertSame('Hello World', $result->first()->get('greeting')->value());
    }

    public function testRawSqlWithFunctions(): void
    {
        // Common SQL functions
        $now = DB::query("SELECT ? as now", DB::rawSql('NOW()'))->first();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now->get('now')->value());

        $upper = DB::query("SELECT ? as upper", DB::rawSql("UPPER('test')"))->first();
        $this->assertSame('TEST', $upper->get('upper')->value());
    }

    public function testRawSqlWithColumnReference(): void
    {
        // Reference another column
        $result = DB::query(
            "SELECT num, name, ? as doubled FROM ::users WHERE num = ?",
            [DB::rawSql('num * 2'), 5]
        );

        $row = $result->first();
        $this->assertSame(5, $row->get('num')->value());
        $this->assertSame(10, $row->get('doubled')->value());
    }

    //endregion
    //region Integration with Other Methods

    public function testRawSqlWithEscapeCSV(): void
    {
        // escapeCSV returns RawSql
        $csv = DB::escapeCSV([1, 2, 3]);
        $this->assertInstanceOf(RawSql::class, $csv);

        // Use placeholder to pass RawSql into query
        $result = DB::query("SELECT * FROM ::users WHERE num IN (?)", $csv);
        $this->assertCount(3, $result);
    }

    public function testRawSqlWithLikeHelpers(): void
    {
        // Like helpers return RawSql
        $pattern = DB::likeContains('Doe');
        $this->assertInstanceOf(RawSql::class, $pattern);

        // Use placeholder to pass RawSql into query
        $result = DB::query("SELECT * FROM ::users WHERE name LIKE ?", $pattern);
        $this->assertCount(2, $result);
    }

    //endregion
}
