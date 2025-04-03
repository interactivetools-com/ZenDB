<?php
declare(strict_types=1);

use Itools\ZenDB\DBException;
use PHPUnit\Framework\TestCase;
use Itools\ZenDB\DB;
use Itools\ZenDB\Config;

/**
 * Checks if Mysqlnd driver is installed
 *
 * This test runs early (alphabetical sorting) to warn when Mysqlnd is missing.
 * Without Mysqlnd, mysqli::query() returns numbers as strings, causing test failures.
 * Prepared statements (mysqli_stmt) are not affected and work correctly either way.
 */
class MysqlndTest extends TestCase
{
    /**
     * @test
     */
    public function testMysqlndDriver(): void
    {
        if (!defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
            echo "\n";
            echo "=========================================================================\n";
            echo "WARNING: Mysqlnd driver not detected!\n";
            echo "- Escaped queries (mysqli::query) return numbers as strings\n";
            echo "- Param queries (mysqli_stmt) are not affected and return proper types\n";
            echo "- Test plans may fail with type mismatch errors\n";
            echo "=========================================================================\n";

            $this->assertTrue(true, 'Test continued despite Mysqlnd missing');
        } else {
            // If we're here, MYSQLI_OPT_INT_AND_FLOAT_NATIVE is defined, so Mysqlnd is available
            $this->assertTrue(true, 'Mysqlnd driver detected');
        }
    }

    /**
     * @throws DBException
     */
    public function testNumericTypeHandling(): void
    {
        // Skip test if not connected
        if (!DB::isConnected()) {
            try {
                DB::config([
                    'hostname' => 'localhost',
                    'username' => 'root',
                    'password' => '',
                    'database' => 'test',
                ]);
                DB::connect();
            } catch (Exception $e) {
                $this->markTestSkipped('Could not connect to database: ' . $e->getMessage());
                return;
            }
        }

        // Test param query (mysqli_stmt) - always returns proper types regardless of Mysqlnd
        $preparedResult = DB::query("SELECT :num AS num", [':num' => 123])->first();
        $preparedValue = $preparedResult->num->value();
        $preparedType = gettype($preparedValue);

        // This should always be integer because param queries handle types correctly
        $this->assertSame('integer', $preparedType, 'Param queries should return integers for numeric values');

        // Escaped query behavior (mysqli::query) - this is where Mysqlnd makes a difference
        // We use a SHOW command which uses mysqli::query internally
        $escapedResult = DB::query("SHOW VARIABLES LIKE :pattern", [':pattern' => 'version'])->first();

        // Output information about the environment for debugging
        $hasMysqlnd = defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE') ? 'Yes' : 'No';
        echo "\nEnvironment:\n";
        echo "- PHP Version: " . PHP_VERSION . "\n";
        echo "- Mysqlnd Available: " . $hasMysqlnd . "\n";
        echo "- MySQL Version: " . DB::$mysqli->server_info . "\n";

        if (!defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
            echo "\nNOTE: Without Mysqlnd, escaped queries (mysqli::query) return numbers as strings.\n";
            echo "This affects SHOW, DESCRIBE and other commands using mysqli::query.\n";
        }
    }
}
