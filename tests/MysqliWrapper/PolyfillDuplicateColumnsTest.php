<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\MysqliWrapper;

use Itools\ZenDB\Connection;
use Itools\ZenDB\MysqliStmtWrapper;
use Itools\ZenDB\MysqliWrapper;
use Itools\ZenDB\Tests\BaseTestCase;

/**
 * get_result() polyfill behavior with duplicate column names (PHP 8.1 without mysqlnd).
 *
 * fetch_array() binds by column position, so a JOIN that selects two same-named columns
 * (SELECT a.id, b.id -> both "id") returns both values; assoc keys are last-wins, matching
 * native mysqli. Writes through execute_query() return true, also matching native.
 *
 * The polyfill runs natively only on PHP 8.1 without mysqlnd; these tests force it on any
 * box via the same test-only flags MysqliResultPolyfillTest uses. bind_result()/fetch()
 * work identically with or without mysqlnd, so forcing it is a faithful test.
 *
 * @covers \Itools\ZenDB\MysqliResultPolyfill::fetch_array
 */
class PolyfillDuplicateColumnsTest extends BaseTestCase
{
    private static bool $originalForceExecuteQueryPolyfill;
    private Connection $conn;

    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
        self::$originalForceExecuteQueryPolyfill = MysqliWrapper::$forceExecuteQueryPolyfill;
    }

    public static function tearDownAfterClass(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = self::$originalForceExecuteQueryPolyfill;
        MysqliStmtWrapper::enableTestResultPolyfill(false);
    }

    protected function setUp(): void
    {
        $this->enablePolyfill();
        $this->conn = new Connection(self::$configDefaults);
    }

    protected function tearDown(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = self::$originalForceExecuteQueryPolyfill;
        MysqliStmtWrapper::enableTestResultPolyfill(false);
    }

    private function enablePolyfill(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = true;
        MysqliStmtWrapper::enableTestResultPolyfill(true);
    }

    private function disablePolyfill(): void
    {
        MysqliWrapper::$forceExecuteQueryPolyfill = false;
        MysqliStmtWrapper::enableTestResultPolyfill(false);
    }

    //region Duplicate column names

    public function testDuplicateNamesFetchArrayBothInterleaved(): void
    {
        $result = $this->conn->mysqli->execute_query("SELECT 1 AS id, 2 AS id");

        // Numeric slots keep both; the assoc "id" key is last-wins. Key order matches native
        // mysqli exactly: the "id" key is first seen at column 0, then updated in place at
        // column 1, so it sits between the two numeric slots.
        $this->assertSame([0 => 1, 'id' => 2, 1 => 2], $result->fetch_array(MYSQLI_BOTH));
    }

    public function testDuplicateNamesAssocIsLastWins(): void
    {
        // ASSOC keeps one "id" key (last wins), like native
        $result = $this->conn->mysqli->execute_query("SELECT 1 AS id, 2 AS id");
        $this->assertSame(['id' => 2], $result->fetch_assoc());
    }

    public function testSelfJoinDuplicateColumnsSurvive(): void
    {
        $this->conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS dupcol_test");
        $this->conn->mysqli->query("CREATE TEMPORARY TABLE dupcol_test (num INT, name VARCHAR(50))");
        $this->conn->mysqli->query("INSERT INTO dupcol_test VALUES (1, 'Alice'), (2, 'Bob')");

        // a.num and b.num both come back named "num"; a.num=1 pairs with b.num=2.
        $result = $this->conn->mysqli->execute_query(
            "SELECT a.num, b.num FROM dupcol_test a JOIN dupcol_test b ON b.num = a.num + 1"
        );
        $this->assertSame([1, 2], $result->fetch_row());
    }

    /**
     * Differential oracle - the strongest form. Same SQL through native mysqlnd get_result()
     * (the source of truth) and through the forced polyfill must return identical rows.
     */
    public function testPolyfillMatchesNativeForDuplicateColumns(): void
    {
        $sql = "SELECT 10 AS c, 20 AS c, 30 AS c";

        $this->disablePolyfill();
        $native = $this->conn->mysqli->execute_query($sql)->fetch_row();

        $this->enablePolyfill();
        $polyfill = $this->conn->mysqli->execute_query($sql)->fetch_row();

        $this->assertSame($native, $polyfill);
        $this->assertSame([10, 20, 30], $polyfill);
    }

    //endregion
    //region Write return type

    /**
     * A write through the polyfill must return boolean true, matching native execute_query.
     */
    public function testWriteReturnsTrueNotResultObject(): void
    {
        $this->conn->mysqli->query("DROP TEMPORARY TABLE IF EXISTS polyfill_write_test");
        $this->conn->mysqli->query("CREATE TEMPORARY TABLE polyfill_write_test (id INT)");

        $result = $this->conn->mysqli->execute_query("INSERT INTO polyfill_write_test VALUES (?)", [1]);
        $this->assertTrue($result);
    }

    //endregion
}
