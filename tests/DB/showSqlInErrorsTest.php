<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests;

// Ensure bootstrap is loaded
require_once __DIR__ . '/../bootstrap.php';

use Itools\ZenDB\DB;
use Itools\ZenDB\DBException;

/**
 * Tests for the showSqlInErrors configuration option
 */
class ShowSqlInErrorsTest extends BaseTest
{
    private $originalShowSqlValue;

    protected function setUp(): void
    {
        parent::setUp();
        // Store original value
        $this->originalShowSqlValue = DB::config('showSqlInErrors');
    }

    protected function tearDown(): void
    {
        // Restore original value
        DB::config('showSqlInErrors', $this->originalShowSqlValue);
        parent::tearDown();
    }

    public function testSqlHiddenByDefault(): void
    {
        // Make sure SQL is hidden by default
        DB::config('showSqlInErrors', false);

        try {
            // Execute a query with a deliberate error
            DB::query("SELECT * FROM non_existent_table");
            $this->fail('Query should have thrown an exception');
        } catch (DBException $e) {
            $this->assertStringNotContainsString('Last SQL query:', $e->getMessage(),
                'SQL should not be visible in exception message when showSqlInErrors is false');
        }
    }

    public function testSqlVisibleWhenEnabled(): void
    {
        // Enable SQL in errors
        DB::config('showSqlInErrors', true);

        try {
            // Execute a query with a deliberate error
            DB::query("SELECT * FROM non_existent_table");
            $this->fail('Query should have thrown an exception');
        } catch (DBException $e) {
            $this->assertStringContainsString('Last SQL query:', $e->getMessage(),
                'SQL should be visible in exception message when showSqlInErrors is true');
            $this->assertStringContainsString('non_existent_table', $e->getMessage(),
                'Exception message should contain the actual SQL query');
        }
    }

    public function testSqlVisibilityControlledByCallback(): void
    {
        // Set a callback that returns false
        DB::config('showSqlInErrors', function() { return false; });

        try {
            DB::query("SELECT * FROM non_existent_table");
            $this->fail('Query should have thrown an exception');
        } catch (DBException $e) {
            $this->assertStringNotContainsString('Last SQL query:', $e->getMessage(),
                'SQL should not be visible when callback returns false');
        }

        // Set a callback that returns true
        DB::config('showSqlInErrors', function() { return true; });

        try {
            DB::query("SELECT * FROM non_existent_table");
            $this->fail('Query should have thrown an exception');
        } catch (DBException $e) {
            $this->assertStringContainsString('Last SQL query:', $e->getMessage(),
                'SQL should be visible when callback returns true');
        }
    }
}
