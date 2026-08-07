<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlIdentifier */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Docs;

use InvalidArgumentException;
use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use Itools\ZenDB\Connection;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use mysqli_sql_exception;
use RuntimeException;

/**
 * Every docs/ example with a claimed output, executed and asserted exactly.
 * Docs are the spec: when one of these fails, either the code broke or the
 * docs went stale - both are findings.
 *
 * Examples run against the test fixtures, so table and column names are the
 * fixture ones and the connection has a 'test_' tablePrefix. Generated SQL is
 * read back with mysqli('query'), which is why the pinned SQL shows prefixed
 * table names where the docs show bare ones.
 *
 * Examples without a pinnable output (loops over unspecified data, `$_GET`
 * reads, sample tables that don't exist here) are covered by the unit files.
 *
 * One region per page, in docs/ reading order.
 */
class DocsExamplesTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::createDefaultConnection();
    }

    protected function setUp(): void
    {
        // Every test starts from the same rows and auto-increment values, so
        // examples that insert or delete can pin exact IDs and row counts.
        self::resetTestTables();
    }

    //region docs/getting-started.md

    public function testGettingStartedConnectAndFetchYourFirstRows(): void
    {
        $users = DB::select('users', ['status' => 'Active']);

        $this->assertSame("SELECT * FROM `test_users` WHERE `status` = 'Active'", $users->mysqli('query'));
        $this->assertSame(10, count($users));

        $lines = [];
        foreach ($users as $user) {
            $lines[] = "<li>$user->name from $user->city</li>";
        }
        $this->assertSame('<li>John Doe from Vancouver</li>', $lines[0]);
        $this->assertSame('<li>Alice Smith from Montreal</li>', $lines[1]);
    }

    public function testGettingStartedMissingConfigKeyThrows(): void
    {
        foreach (['hostname', 'username', 'password', 'database'] as $key) {
            $config = self::$configDefaults;
            unset($config[$key]);

            try {
                new Connection($config);
                $this->fail("Missing '$key' should throw");
            } catch (RuntimeException $e) {
                $this->assertSame("Missing required config: '$key'", $e->getMessage());
            }
        }
    }

    public function testGettingStartedUnknownConfigKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown configuration key: 'bogusKey'");

        new Connection(array_merge(self::$configDefaults, ['bogusKey' => 1]));
    }

    public function testGettingStartedFetchingOneRow(): void
    {
        $user = DB::selectOne('users', ['num' => 1]);

        $this->assertSame('SELECT * FROM `test_users` WHERE `num` = 1 LIMIT 1', $user->mysqli('query'));
        $this->assertFalse($user->isEmpty());
        $this->assertSame('Name: John Doe', "Name: $user->name");
        $this->assertSame('City: Vancouver', "City: $user->city");
    }

    public function testGettingStartedSelectOneReturnsEmptyRowWhenNoMatch(): void
    {
        $user = DB::selectOne('users', ['num' => 9999]);

        $this->assertTrue($user->isEmpty());
        $this->assertSame('', (string)$user->name);
    }

    public function testGettingStartedSqlConditionWithPlaceholders(): void
    {
        $admins = DB::select('users', "isAdmin = ? AND city = ?", 1, 'Vancouver');

        $this->assertSame("SELECT * FROM `test_users` WHERE isAdmin = 1 AND city = 'Vancouver'", $admins->mysqli('query'));
        $this->assertSame(['John Doe'], $admins->pluck('name')->toArray());
    }

    public function testGettingStartedInsertingRows(): void
    {
        $newId = DB::insert('users', [
            'name'    => 'Alice',
            'isAdmin' => 0,
            'status'  => 'Active',
            'city'    => 'Toronto',
        ]);

        $this->assertSame(21, $newId);
        $this->assertSame('Created user #21', "Created user #$newId");
        $this->assertSame(
            ['num' => 21, 'name' => 'Alice', 'isAdmin' => 0, 'status' => 'Active', 'city' => 'Toronto', 'dob' => null, 'age' => null],
            DB::selectOne('users', ['num' => $newId])->toArray()
        );
    }

    public function testGettingStartedUpdatingRows(): void
    {
        $affected = DB::update('users',
            ['city' => 'Montreal'],   // columns to set
            ['num'  => 1],            // WHERE condition
        );

        $this->assertSame(1, $affected);
        $this->assertSame('Montreal', DB::selectOne('users', ['num' => 1])->city->value());

        // SQL conditions with placeholders work here too; users 1 and 3 are now both admins in Montreal
        $this->assertSame(2, DB::update('users', ['status' => 'Inactive'], "city = ? AND isAdmin = ?", 'Montreal', 1));
        $this->assertSame('Inactive', DB::selectOne('users', ['num' => 1])->status->value());
        $this->assertSame('Inactive', DB::selectOne('users', ['num' => 3])->status->value());
    }

    public function testGettingStartedUpdateRequiresWhereCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UPDATE requires a WHERE condition to prevent accidental bulk UPDATE');

        DB::update('users', ['city' => 'Montreal'], []);
    }

    public function testGettingStartedDeletingRows(): void
    {
        $this->assertSame(1, DB::delete('users', ['num' => 1]));
        $this->assertSame(0, DB::count('users', ['num' => 1]));

        $this->assertSame(3, DB::delete('users', "status = ? AND isAdmin = ?", 'Inactive', 0));
        $this->assertSame(16, DB::count('users'));
    }

    public function testGettingStartedDeleteRequiresWhereCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DELETE requires a WHERE condition to prevent accidental bulk DELETE');

        DB::delete('users', []);
    }

    public function testGettingStartedGettingRawValues(): void
    {
        // One field's raw value
        $user = DB::selectOne('users', ['num' => 8]);
        $name = $user->name->value();

        $this->assertSame('Frank <b>Miller</b>', $name);
        $this->assertSame('Frank &lt;b&gt;Miller&lt;/b&gt;', (string)$user->name);

        // A whole result as a plain PHP array
        $users = DB::select('users', ['status' => 'Active'])->toArray();

        $this->assertCount(10, $users);
        $this->assertSame('John Doe', $users[0]['name']);
    }

    public function testGettingStartedCatchingErrors(): void
    {
        $message = null;
        try {
            DB::query("SELECT * FROM ::no_such_table");
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        $this->assertSame("Table 'phpunit_test_db.test_no_such_table' doesn't exist", $message);
    }

    //endregion
    //region docs/querying-data.md

    public function testQueryingSelectAllRows(): void
    {
        $users = DB::select('users');

        $this->assertSame('SELECT * FROM `test_users` ', $users->mysqli('query'));
        $this->assertSame(20, count($users));
    }

    public function testQueryingWhereArray(): void
    {
        $users = DB::select('users', ['status' => 'Active', 'city' => 'Vancouver']);

        $this->assertSame("SELECT * FROM `test_users` WHERE `status` = 'Active' AND `city` = 'Vancouver'", $users->mysqli('query'));
        $this->assertSame(['John Doe'], $users->pluck('name')->toArray());
    }

    public function testQueryingPositionalPlaceholders(): void
    {
        $users = DB::select('users', "status = ? AND age > ?", 'Active', 25);

        $this->assertSame("SELECT * FROM `test_users` WHERE status = 'Active' AND age > 25", $users->mysqli('query'));
        $this->assertSame(8, count($users));
    }

    public function testQueryingNamedPlaceholders(): void
    {
        $users = DB::select('users', "status = :status AND age > :age", [
            ':status' => 'Active',
            ':age'    => 25,
        ]);

        $this->assertSame("SELECT * FROM `test_users` WHERE status = 'Active' AND age > 25", $users->mysqli('query'));
        $this->assertSame(8, count($users));
    }

    public function testQueryingWhereKeywordIsOptional(): void
    {
        $withKeyword    = DB::select('users', "WHERE status = ?", 'Active');
        $withoutKeyword = DB::select('users', "status = ?", 'Active');

        $this->assertSame("SELECT * FROM `test_users` WHERE status = 'Active'", $withKeyword->mysqli('query'));
        $this->assertSame($withKeyword->mysqli('query'), $withoutKeyword->mysqli('query'));
    }

    public function testQueryingValuesEncodeThemselvesInLoops(): void
    {
        $lines = [];
        foreach (DB::select('users', "num IN (:nums)", [':nums' => [8, 18]]) as $user) {
            $lines[] = "<li>$user->name - $user->city</li>";
        }

        $this->assertSame([
            '<li>Frank &lt;b&gt;Miller&lt;/b&gt; - Winnipeg</li>',
            '<li>Paula Hall - St. John&apos;s</li>',
        ], $lines);
    }

    public function testQueryingSelectOneAddsLimitOne(): void
    {
        $user = DB::selectOne('users', ['num' => 123]);

        $this->assertSame('SELECT * FROM `test_users` WHERE `num` = 123 LIMIT 1', $user->mysqli('query'));
        $this->assertTrue($user->isEmpty());
    }

    public function testQueryingCountRows(): void
    {
        $this->assertSame(20, DB::count('users'));
        $this->assertSame(10, DB::count('users', ['status' => 'Active']));
        $this->assertSame(4, DB::count('orders', "order_date > ?", '2023-12-01'));
    }

    public function testQueryingCountRejectsLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("This method doesn't support LIMIT or OFFSET");

        DB::count('users', "status = ? LIMIT 5", 'Active');
    }

    public function testQueryingArrayConditionBecomesInList(): void
    {
        $users = DB::select('users', ['status' => ['Active', 'Suspended']]);

        $this->assertSame("SELECT * FROM `test_users` WHERE `status` IN ('Active','Suspended')", $users->mysqli('query'));
        $this->assertSame(15, count($users));
    }

    public function testQueryingNullConditionBecomesIsNull(): void
    {
        $users = DB::select('users', ['isAdmin' => null]);

        $this->assertSame('SELECT * FROM `test_users` WHERE `isAdmin` IS NULL', $users->mysqli('query'));
        $this->assertSame(4, count($users));
    }

    public function testQueryingRawSqlConditionIsInsertedAsIs(): void
    {
        $users = DB::select('users', ['dob' => DB::rawSql('CURDATE()')]);

        $this->assertSame('SELECT * FROM `test_users` WHERE `dob` = CURDATE()', $users->mysqli('query'));
        $this->assertSame(0, count($users));
    }

    public function testQueryingNamedPlaceholdersWithSeveralValues(): void
    {
        $orders = DB::select('orders', "user_id = :userId AND total_amount >= :minTotal AND order_date > :since", [
            ':userId'   => 7,
            ':minTotal' => 100,
            ':since'    => '2023-01-01',
        ]);

        $this->assertSame(
            "SELECT * FROM `test_orders` WHERE user_id = 7 AND total_amount >= 100 AND order_date > '2023-01-01'",
            $orders->mysqli('query')
        );
        $this->assertSame(1, count($orders));
    }

    public function testQueryingOrderByAlone(): void
    {
        $users = DB::select('users', "ORDER BY name");

        $this->assertSame('SELECT * FROM `test_users` ORDER BY name', $users->mysqli('query'));
        $this->assertSame('Alice Smith', $users->first()->name->value());
    }

    public function testQueryingOrderByWithLimitPlaceholder(): void
    {
        $users = DB::select('users', "status = ? ORDER BY name DESC LIMIT ?", 'Active', 10);

        $this->assertSame("SELECT * FROM `test_users` WHERE status = 'Active' ORDER BY name DESC LIMIT 10", $users->mysqli('query'));
        $this->assertSame('Quentin Adams', $users->first()->name->value());
    }

    public function testQueryingLiteralTrailingLimitIsAllowed(): void
    {
        $users = DB::select('users', "ORDER BY name LIMIT 10");

        $this->assertSame('SELECT * FROM `test_users` ORDER BY name LIMIT 10', $users->mysqli('query'));
        $this->assertSame(10, count($users));
    }

    public function testQueryingLimitValueMustBeAnInt(): void
    {
        $this->assertSame('SELECT * FROM `test_users` LIMIT 10', DB::select('users', "LIMIT ?", 10)->mysqli('query'));

        $this->expectException(mysqli_sql_exception::class);
        $this->expectExceptionMessage("'10'");

        DB::select('users', "LIMIT ?", "10");
    }

    public function testQueryingPagingSql(): void
    {
        $users = DB::select('users', "ORDER BY name :pagingSQL", [
            ':pagingSQL' => DB::pagingSql(1, 10),
        ]);

        $this->assertSame('SELECT * FROM `test_users` ORDER BY name LIMIT 10 OFFSET 0', $users->mysqli('query'));
        $this->assertSame(10, count($users));
    }

    public function testQueryingPagingSqlSanitizesItsInputs(): void
    {
        $this->assertSame('LIMIT 10 OFFSET 0', (string)DB::pagingSql(1, 10));
        $this->assertSame('LIMIT 10 OFFSET 10', (string)DB::pagingSql(2, 10));
        $this->assertSame('LIMIT 10 OFFSET 0', (string)DB::pagingSql('abc'));
        $this->assertSame('LIMIT 10 OFFSET 0', (string)DB::pagingSql(''));
        $this->assertSame('LIMIT 10 OFFSET 0', (string)DB::pagingSql(0, 0));
    }

    public function testQueryingCustomSqlWithQuery(): void
    {
        $rows = DB::query("
    SELECT u.name, COUNT(o.order_id) AS orderCount
      FROM ::users u
 LEFT JOIN ::orders o ON o.user_id = u.num
     WHERE u.status = :status
  GROUP BY u.num, u.name
  ORDER BY orderCount DESC, u.num",
            [':status' => 'Active'],
        );

        $this->assertStringContainsString('FROM test_users u', $rows->mysqli('query'));
        $this->assertStringContainsString('LEFT JOIN test_orders o', $rows->mysqli('query'));
        $this->assertSame(10, count($rows));
        $this->assertSame(['name' => 'Erin Davis', 'orderCount' => 1], $rows->first()->toArray());
    }

    public function testQueryingCustomSqlWithQueryOne(): void
    {
        $row = DB::queryOne("SELECT MAX(price) AS maxPrice FROM ::products");

        $this->assertSame('SELECT MAX(price) AS maxPrice FROM test_products LIMIT 1', $row->mysqli('query'));
        $this->assertSame('25.75', (string)$row->maxPrice);
    }

    //endregion
    //region docs/working-with-results.md

    public function testResultsHierarchy(): void
    {
        $users = DB::select('users');

        $names = [];
        foreach ($users as $user) {
            $names[] = (string)$user->name;
        }

        $this->assertSame(20, count($names));
        $this->assertSame('Frank &lt;b&gt;Miller&lt;/b&gt;', $names[7]);
    }

    public function testResultsOutputEncoding(): void
    {
        $row = DB::selectOne('special_chars', ['id' => 2]);

        $this->assertSame('&lt;b&gt;Bold&lt;/b&gt; &amp; &quot;quoted&quot;', "$row->html_content");
        $this->assertSame('<b>Bold</b> & "quoted"', $row->html_content->value());
        $this->assertSame('<b>Bold</b> & "quoted"', $row->html_content->rawHtml());
        $this->assertSame('%3Cb%3EBold%3C%2Fb%3E+%26+%22quoted%22', (string)$row->html_content->urlEncode());
        $this->assertSame('"\\u003Cb\\u003EBold\\u003C/b\\u003E \\u0026 \\u0022quoted\\u0022"', (string)$row->html_content->jsonEncode());
    }

    public function testResultsLogicUsesTheRawValue(): void
    {
        $admin    = DB::selectOne('users', ['num' => 1]);
        $nonAdmin = DB::selectOne('users', ['num' => 8]);

        $this->assertSame(1, $admin->isAdmin->value());
        $this->assertSame(0, $nonAdmin->isAdmin->value());
        $this->assertTrue((bool)$admin->isAdmin->value());
        $this->assertFalse((bool)$nonAdmin->isAdmin->value());
    }

    public function testResultsOrFallbackForEmptyColumns(): void
    {
        $user = DB::selectOne('users', ['num' => 2]);

        $this->assertSame('Jane Janey Doe', (string)$user->name);
        $this->assertSame('Anonymous', (string)$user->isAdmin->or('Anonymous'));
    }

    public function testResultsGettingRawData(): void
    {
        $user = DB::selectOne('users', ['num' => 18]);

        $this->assertSame("St. John's", $user->city->value());
        $this->assertSame(
            ['num' => 18, 'name' => 'Paula Hall', 'isAdmin' => 0, 'status' => 'Inactive', 'city' => "St. John's", 'dob' => '1982-10-15', 'age' => 41],
            $user->toArray()
        );

        $rows = DB::select('users')->toArray();
        $this->assertCount(20, $rows);
        $this->assertSame('John Doe', $rows[0]['name']);
    }

    public function testResultsChainingValueMethods(): void
    {
        $row     = DB::selectOne('special_chars', ['id' => 2]);
        $product = DB::selectOne('products', ['product_id' => 3]);
        $user    = DB::selectOne('users', ['num' => 8]);

        // Strip HTML tags, then shorten with an ellipsis
        $this->assertSame('Bold ...', (string)$row->html_content->textOnly()->maxChars(10));

        // Format a number and prepend a currency symbol
        $this->assertSame('$25.75', (string)$product->price->numberFormat(2)->prepend('$'));

        // Format a date; null falls through to the or()
        $this->assertSame('Jul 22, 1992', (string)$user->dob->dateFormat('M j, Y')->or('Never'));
        $this->assertSame('Never', (string)DB::selectOne('users', ['num' => 21])->dob->dateFormat('M j, Y')->or('Never'));
    }

    public function testResultsPrintR(): void
    {
        $users = DB::select('users', ['num' => 1]);

        $this->assertStringContainsString('[name] => John Doe', print_r($users, true));
        $this->assertStringContainsString('[name] => John Doe', print_r($users->first(), true));
        $this->assertStringContainsString('[value] => John Doe', print_r($users->first()->name, true));
    }

    public function testResultsDebugShowsTheExecutedSql(): void
    {
        $users = DB::select('users', ['status' => 'Active']);

        ob_start();
        $users->debug();
        $debug = ob_get_clean();

        $this->assertStringContainsString('MySQL Query:', $debug);
        $this->assertStringContainsString("SELECT * FROM `test_users` WHERE `status` = 'Active'", $debug);
        $this->assertStringContainsString('John Doe', $debug);
    }

    public function testResultsQueryMetadata(): void
    {
        $result = DB::query("INSERT INTO ::users SET name = ?", 'Alice');

        $this->assertSame(21, $result->mysqli('insert_id'));
        $this->assertSame(1, $result->mysqli('affected_rows'));
        $this->assertSame("INSERT INTO test_users SET name = 'Alice'", $result->mysqli('query'));
        $this->assertSame(['query', 'baseTable', 'affected_rows', 'insert_id'], array_keys($result->mysqli()));
    }

    public function testResultsCollectionMethods(): void
    {
        $users = DB::select('users', ['status' => 'Active']);

        $this->assertSame(10, count($users));
        $this->assertSame(10, $users->count());

        // One column
        $names = $users->column('name');
        $this->assertSame('John Doe', $names->first()->value());
        $this->assertSame(10, count($names));

        // Lookup by primary key
        $byNum = $users->indexBy('num');
        $this->assertSame('George Wilson', (string)$byNum->{'9'}->name);

        // Group rows by a column value
        $byCity  = DB::select('users', ['status' => 'Suspended'])->groupBy('city');
        $headings = [];
        foreach ($byCity as $city => $cityUsers) {
            $headings[] = "<h2>$city (" . count($cityUsers) . ")</h2>";
        }
        $this->assertSame([
            '<h2>Calgary (1)</h2>',
            '<h2>Winnipeg (1)</h2>',
            '<h2>Hamilton (1)</h2>',
            '<h2>Toronto (1)</h2>',
            '<h2>Yellowknife (1)</h2>',
        ], $headings);

        $this->assertSame(
            ['Calgary', 'Hamilton', 'Toronto', 'Winnipeg', 'Yellowknife'],
            DB::select('users', ['status' => 'Suspended'])->sortBy('city')->pluck('city')->toArray()
        );
        $this->assertSame(
            ['Jane Janey Doe', 'Nancy Allen'],
            DB::select('users')->where('city', 'Toronto')->pluck('name')->toArray()
        );
    }

    public function testResultsFirstOnEmptyResultKeepsChainingWorking(): void
    {
        $empty = DB::select('users', ['num' => 9999]);

        $this->assertInstanceOf(SmartNull::class, $empty->first());
        $this->assertSame('none', (string)$empty->first()->name->or('none'));
    }

    public function testResultsRowMethods(): void
    {
        $row = DB::selectOne('users', ['num' => 8]);

        $this->assertSame('Frank &lt;b&gt;Miller&lt;/b&gt;', (string)$row->name);
        $this->assertSame(['num', 'name', 'isAdmin', 'status', 'city', 'dob', 'age'], $row->keys()->toArray());
        $this->assertSame([8, 'Frank <b>Miller</b>', 0, 'Suspended', 'Winnipeg', '1992-07-22', 31], $row->values()->toArray());
        $this->assertFalse($row->isEmpty());
        $this->assertTrue(DB::selectOne('users', ['num' => 9999])->isEmpty());

        // Smart Join keys can't be typed with plain property syntax, so ->{'...'} reads them
        $joined = DB::query("SELECT * FROM ::users u JOIN ::orders o ON o.user_id = u.num ORDER BY o.order_id LIMIT 1")->first();
        $this->assertSame('Dave Williams', (string)$joined->{'users.name'});
    }

    public function testResultsValueMethodsText(): void
    {
        $user      = DB::selectOne('users', ['num' => 8]);
        $multiline = DB::selectOne('special_chars', ['id' => 3]);

        $this->assertSame('Frank Miller', (string)$user->name->textOnly());
        $this->assertSame('Frank...', (string)$user->name->textOnly()->maxChars(10));
        $this->assertSame('Jane Janey...', (string)DB::selectOne('users', ['num' => 2])->name->maxWords(2));
        $this->assertSame("Line1<br>\nLine2", (string)$multiline->content->nl2br());
        $this->assertSame('John Doe', (string)DB::selectOne('users', ['num' => 1])->name->trim());
    }

    public function testResultsValueMethodsFormatting(): void
    {
        $product = DB::selectOne('products', ['product_id' => 1]);

        $this->assertSame('Apr 10, 1985', (string)DB::selectOne('users', ['num' => 1])->dob->dateFormat('M j, Y'));
        $this->assertSame('10.99', (string)$product->price->numberFormat(2));
        $this->assertSame(10, $product->price->int());
        $this->assertSame(10.99, $product->price->float());
    }

    public function testResultsValueMethodsConditionalFallbacks(): void
    {
        $admin    = DB::selectOne('users', ['num' => 1]);   // isAdmin = 1
        $nonAdmin = DB::selectOne('users', ['num' => 8]);   // isAdmin = 0
        $nullish  = DB::selectOne('users', ['num' => 2]);   // isAdmin = null

        $this->assertSame('N/A', (string)$nullish->isAdmin->or('N/A'));
        $this->assertSame('0', (string)$nonAdmin->isAdmin->or('N/A'));   // zero stays
        $this->assertSame('N/A', (string)$nullish->isAdmin->ifNull('N/A'));
        $this->assertSame('Free', (string)$nonAdmin->isAdmin->ifZero('Free'));
        $this->assertSame('1 items', (string)$admin->isAdmin->append(' items'));
        $this->assertSame('$0', (string)$nonAdmin->isAdmin->prepend('$'));
        $this->assertSame('', (string)$nullish->isAdmin->prepend('$'));
    }

    //endregion
    //region docs/modifying-data.md

    public function testModifyingInsertingRows(): void
    {
        $newId = DB::insert('users', [
            'name'    => 'Bob Smith',
            'isAdmin' => 0,
            'status'  => 'Active',
            'city'    => 'Vancouver',
        ]);

        $this->assertSame(21, $newId);
        $this->assertSame('Bob Smith', DB::selectOne('users', ['num' => $newId])->name->value());
    }

    public function testModifyingUpdatingRows(): void
    {
        $affected = DB::update('users',
            ['city' => 'Toronto'],   // values to set
            ['num'  => 1],           // WHERE condition
        );
        $this->assertSame(1, $affected);

        // The same call with named variables
        $newValues = ['city' => 'Vancouver'];
        $where     = ['num'  => 1];
        $this->assertSame(1, DB::update('users', $newValues, $where));

        // SQL WHERE with placeholders
        $this->assertSame(1, DB::update('users', ['status' => 'Inactive'], "dob < ? AND num = ?", '1990-01-01', 1));
        $this->assertSame('Inactive', DB::selectOne('users', ['num' => 1])->status->value());
    }

    public function testModifyingUpdateCountsChangedRowsNotMatchedRows(): void
    {
        $this->assertSame(1, DB::update('users', ['city' => 'Toronto'], ['num' => 1]));
        $this->assertSame(0, DB::update('users', ['city' => 'Toronto'], ['num' => 1]));
    }

    public function testModifyingUpdateEveryRowNeedsAnAlwaysTrueCondition(): void
    {
        $this->assertSame(20, DB::update('users', ['age' => 99], "TRUE"));
        $this->assertSame(20, DB::count('users', ['age' => 99]));
    }

    public function testModifyingUpdateCatchesReversedArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Suspicious SET clause: only updating 'num'. Did you reverse the arguments? Signature is: update(\$table, \$values, \$whereEtc)");

        DB::update('users', ['num' => 1], ['city' => 'Toronto']);
    }

    public function testModifyingWhereCanCarryOrderByAndLimit(): void
    {
        $this->assertSame(2, DB::delete('users', "status = ? ORDER BY num LIMIT 2", 'Suspended'));
        $this->assertSame(3, DB::count('users', ['status' => 'Suspended']));
    }

    public function testModifyingDeletingRows(): void
    {
        $deleted = DB::delete('users', ['num' => 1]);
        $this->assertSame(1, $deleted);

        $this->assertSame(5, DB::delete('users', "status = ?", 'Suspended'));
        $this->assertSame(0, DB::count('users', ['status' => 'Suspended']));
    }

    public function testModifyingColumnValuesAndTypes(): void
    {
        $newId = DB::insert('users', [
            'name'    => 'Coffee Mug',              // string, quoted and escaped
            'age'     => 30,                        // int
            'isAdmin' => true,                      // bool, becomes TRUE
            'city'    => null,                      // null, becomes NULL
            'dob'     => DB::rawSql('CURDATE()'),   // RawSql, inserted as-is
        ]);

        $this->assertSame(
            ['num' => 21, 'name' => 'Coffee Mug', 'isAdmin' => 1, 'status' => null, 'city' => null, 'dob' => date('Y-m-d'), 'age' => 30],
            DB::selectOne('users', ['num' => $newId])->toArray()
        );

        // float
        $productId = DB::insert('products', ['product_name' => 'Coffee Mug', 'price' => 9.5]);
        $this->assertSame('9.50', DB::selectOne('products', ['product_id' => $productId])->price->value());
    }

    public function testModifyingArrayColumnValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported type for column 'name': array");

        DB::insert('users', ['name' => ['a', 'b']]);
    }

    public function testModifyingSqlExpressionsWithRawSql(): void
    {
        $orderId = DB::insert('orders', [
            'user_id'      => 1,
            'order_date'   => DB::rawSql('CURDATE() + INTERVAL 30 DAY'),
            'total_amount' => 10,
        ]);
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), DB::selectOne('orders', ['order_id' => $orderId])->order_date->value());

        // Increment a counter in place
        $newValues = ['total_amount' => DB::rawSql('total_amount + 1')];
        DB::update('orders', $newValues, ['order_id' => $orderId]);
        $this->assertSame('11.00', DB::selectOne('orders', ['order_id' => $orderId])->total_amount->value());
    }

    public function testModifyingTransactionCommitsAndReturnsTheClosureValue(): void
    {
        $orderId = DB::transaction(function() {
            $orderId = DB::insert('orders', ['user_id' => 42, 'total_amount' => 59.90]);
            DB::insert('order_details', ['order_id' => $orderId, 'product_id' => 7, 'quantity' => 1]);
            DB::insert('order_details', ['order_id' => $orderId, 'product_id' => 12, 'quantity' => 1]);
            return $orderId;
        });

        $this->assertSame(11, $orderId);
        $this->assertSame(1, DB::count('orders', ['order_id' => $orderId]));
        $this->assertSame(2, DB::count('order_details', ['order_id' => $orderId, 'product_id' => [7, 12]]));
    }

    public function testModifyingTransactionRollsBackAndRethrows(): void
    {
        // A real InnoDB table, because temporary tables don't roll back
        DB::$mysqli->query("CREATE TABLE IF NOT EXISTS test_docs_orders (order_id INT PRIMARY KEY AUTO_INCREMENT, customer_id INT) ENGINE=InnoDB");
        DB::$mysqli->query("DELETE FROM test_docs_orders");

        $message = null;
        try {
            DB::transaction(function() {
                DB::insert('docs_orders', ['customer_id' => 42]);
                throw new RuntimeException("Out of stock");
            });
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('Out of stock', $message);
        $this->assertSame(0, DB::count('docs_orders'));

        DB::$mysqli->query("DROP TABLE IF EXISTS test_docs_orders");
    }

    public function testModifyingTransactionsCannotNest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transaction() cannot be nested - already in a transaction');

        DB::transaction(function() {
            DB::transaction(fn() => null);
        });
    }

    public function testModifyingQueryOneRejectsForUpdate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("This method doesn't support FOR UPDATE. Use query(...)->first() instead.");

        DB::queryOne("SELECT quantity FROM ::order_details WHERE order_detail_id = ? FOR UPDATE", 1);
    }

    //endregion
    //region docs/placeholders.md

    public function testPlaceholdersQuickReference(): void
    {
        $this->assertSame(
            'SELECT * FROM `test_users` WHERE age > 25',
            DB::select('users', "age > ?", 25)->mysqli('query')
        );
        $this->assertSame(
            "SELECT * FROM `test_users` WHERE city = 'St. John\\'s'",
            DB::select('users', "city = :city", [':city' => "St. John's"])->mysqli('query')
        );
        $this->assertSame(
            'SELECT * FROM test_users LIMIT 1',
            DB::query("SELECT * FROM ::users LIMIT 1")->mysqli('query')
        );
    }

    public function testPlaceholdersPositional(): void
    {
        $this->assertSame(
            "SELECT * FROM `test_users` WHERE status = 'Active'",
            DB::select('users', "status = ?", 'Active')->mysqli('query')
        );
        $this->assertSame(
            "SELECT * FROM `test_users` WHERE status = 'Active' AND city = 'Vancouver'",
            DB::select('users', "status = ? AND city = ?", 'Active', 'Vancouver')->mysqli('query')
        );
    }

    public function testPlaceholdersNamed(): void
    {
        $users = DB::select('users', "city = :city AND status = :status", [
            ':city'   => 'Vancouver',
            ':status' => 'Active',
        ]);

        $this->assertSame("SELECT * FROM `test_users` WHERE city = 'Vancouver' AND status = 'Active'", $users->mysqli('query'));
        $this->assertSame(['John Doe'], $users->pluck('name')->toArray());
    }

    public function testPlaceholdersNamedCanRepeat(): void
    {
        $users = DB::query("SELECT * FROM ::users WHERE city = :city OR name = :city", [
            ':city' => 'Vancouver',
        ]);

        $this->assertSame("SELECT * FROM test_users WHERE city = 'Vancouver' OR name = 'Vancouver'", $users->mysqli('query'));
        $this->assertSame(1, count($users));
    }

    public function testPlaceholdersMixingStylesThrows(): void
    {
        try {
            DB::select('users', "num = ? AND city = :city", [':city' => 'Vancouver']);
            $this->fail('Mixing ? and :name in one template should throw');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Missing value for ? parameter at position 1', $e->getMessage());
        }

        try {
            DB::select('users', "name = ? AND city = :city", 'John Doe', [':city' => 'Vancouver']);
            $this->fail('Mixing direct values and a params array should throw');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Param args must be either a single array or multiple non-array values', $e->getMessage());
        }
    }

    public function testPlaceholdersPositionalTakeAtMostThreeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Max 3 positional arguments allowed. For more, use named placeholders: [':name' => \$value]");

        DB::select('users', "num = ? AND city = ? AND status = ? AND age = ?", 1, 'Vancouver', 'Active', 38);
    }

    public function testPlaceholdersTablePrefix(): void
    {
        $rows = DB::query("SELECT * FROM ::users JOIN ::orders ON ::orders.user_id = ::users.num LIMIT 1");

        $this->assertSame(
            'SELECT * FROM test_users JOIN test_orders ON test_orders.user_id = test_users.num LIMIT 1',
            $rows->mysqli('query')
        );
    }

    public function testPlaceholdersIdentifier(): void
    {
        $sort  = 'name';
        $users = DB::select('users', "ORDER BY `:sort`", [':sort' => $sort]);

        $this->assertSame('SELECT * FROM `test_users` ORDER BY `name`', $users->mysqli('query'));
        $this->assertSame('Alice Smith', $users->first()->name->value());
    }

    public function testPlaceholdersIdentifierRejectsInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid backtick identifier: 'name; DROP TABLE users'. Only word characters (a-z, 0-9, _, -) allowed.");

        DB::select('users', "ORDER BY `:sort`", [':sort' => 'name; DROP TABLE users']);
    }

    public function testPlaceholdersPrefixedIdentifier(): void
    {
        $rows = DB::query("SELECT * FROM `:::table` WHERE num = :id", [':table' => 'users', ':id' => 1]);

        $this->assertSame('SELECT * FROM `test_users` WHERE num = 1', $rows->mysqli('query'));
        $this->assertSame('John Doe', $rows->first()->name->value());
    }

    public function testPlaceholdersPrefixedValue(): void
    {
        $tables = DB::query("SHOW TABLES LIKE ::?", 'user%');

        $this->assertSame("SHOW TABLES LIKE 'test_user%'", $tables->mysqli('query'));
    }

    public function testPlaceholdersLikeStartsWithEscapesWildcards(): void
    {
        $this->assertSame("'test\\_user%'", (string)DB::likeStartsWith(DB::$tablePrefix . 'user'));

        $tables = DB::query("SHOW TABLES LIKE ?", DB::likeStartsWith(DB::$tablePrefix . 'user'));
        $this->assertSame("SHOW TABLES LIKE 'test\\_user%'", $tables->mysqli('query'));
    }

    public function testPlaceholdersTypeHandling(): void
    {
        $this->assertSame(
            "SELECT * FROM `test_users` WHERE name = 'O\\'Brien'",
            DB::select('users', "name = ?", "O'Brien")->mysqli('query')
        );
        $this->assertSame('SELECT * FROM `test_users` WHERE age = 42', DB::select('users', "age = ?", 42)->mysqli('query'));
        $this->assertSame('SELECT * FROM `test_products` WHERE price = 9.5', DB::select('products', "price = ?", 9.5)->mysqli('query'));
        $this->assertSame('SELECT * FROM `test_users` WHERE isAdmin = TRUE', DB::select('users', "isAdmin = ?", true)->mysqli('query'));
        $this->assertSame('SELECT * FROM `test_users` WHERE isAdmin <=> NULL', DB::select('users', "isAdmin <=> ?", null)->mysqli('query'));
        $this->assertSame(
            'SELECT * FROM `test_users` WHERE num IN (1,2,3)',
            DB::select('users', "num IN (:nums)", [':nums' => [1, 2, 3]])->mysqli('query')
        );
        $this->assertSame(
            'SELECT * FROM `test_users` WHERE dob = CURDATE()',
            DB::select('users', "dob = ?", DB::rawSql('CURDATE()'))->mysqli('query')
        );
    }

    public function testPlaceholdersSmartStringParamsUnwrap(): void
    {
        $row = DB::selectOne('users', ['num' => 1]);

        $this->assertSame(
            "SELECT * FROM `test_users` WHERE name = 'John Doe'",
            DB::select('users', "name = ?", $row->name)->mysqli('query')
        );
    }

    public function testPlaceholdersArraysSkipNullsAndDuplicates(): void
    {
        $users = DB::select('users', "num IN (:ids)", [':ids' => [1, null, 1, 2]]);

        $this->assertSame('SELECT * FROM `test_users` WHERE num IN (1,2)', $users->mysqli('query'));
        $this->assertSame(2, count($users));
    }

    public function testPlaceholdersEmptyArrayInListMatchesNothing(): void
    {
        $users = DB::select('users', "num IN (:ids)", [':ids' => []]);

        $this->assertSame('SELECT * FROM `test_users` WHERE num IN (NULL)', $users->mysqli('query'));
        $this->assertSame(0, count($users));
    }

    public function testPlaceholdersMissingValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing value for ? parameter at position 2');

        DB::select('users', "name = ? AND city = ?", 'John Doe');
    }

    public function testPlaceholdersQuotesInTemplateThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Quotes not allowed in template. Replace 'John' with :paramName and add: [ ':paramName' => 'John' ]");

        DB::select('users', "name = 'John'");
    }

    public function testPlaceholdersStandaloneNumberInTemplateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Standalone number in template. Replace 18 with :n18 and add: [ \':n18\' => 18 ]');

        DB::select('users', "age > 18");
    }

    public function testPlaceholdersDeprecatedPositionalArrayRunsAsFirstValue(): void
    {
        $deprecations = [];
        set_error_handler(static function(int $errno, string $errstr) use (&$deprecations): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecations[] = $errstr;
                return true;
            }
            return false;
        });

        try {
            $users = DB::select('users', "num IN (?)", [1, 2, 3]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('SELECT * FROM `test_users` WHERE num IN (1)', $users->mysqli('query'));
        $this->assertSame(1, count($users));
        $this->assertCount(1, $deprecations);
        $this->assertStringContainsString('Positional values in an array are deprecated', $deprecations[0]);
    }

    public function testPlaceholdersDeprecatedExtraPositionalValue(): void
    {
        $deprecations = [];
        set_error_handler(static function(int $errno, string $errstr) use (&$deprecations): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecations[] = $errstr;
                return true;
            }
            return false;
        });

        try {
            $users = DB::select('users', "num = ?", 1, 999);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('SELECT * FROM `test_users` WHERE num = 1', $users->mysqli('query'));
        $this->assertCount(1, $deprecations);
        $this->assertStringContainsString('Unused positional values are deprecated', $deprecations[0]);
    }

    public function testPlaceholdersEmptyStringLiteralIsAllowed(): void
    {
        $users = DB::select('users', "name != ''");

        $this->assertSame("SELECT * FROM `test_users` WHERE name != ''", $users->mysqli('query'));
        $this->assertSame(20, count($users));
    }

    public function testPlaceholdersUnusedNamedParamsAreAllowed(): void
    {
        $users = DB::select('users', "num = :num", [':num' => 1, ':unused' => 'ignored']);

        $this->assertSame('SELECT * FROM `test_users` WHERE num = 1', $users->mysqli('query'));
        $this->assertSame(1, count($users));
    }

    //endregion
    //region docs/joins-and-custom-sql.md

    public function testJoinsCustomSqlQueryAndQueryOne(): void
    {
        $rows = DB::query("
            SELECT * FROM ::users
              JOIN ::orders ON ::orders.user_id = ::users.num
             WHERE ::orders.total_amount > ?", 100);

        $names = $rows->pluck('name')->toArray();
        sort($names);

        $this->assertCount(3, $rows);
        $this->assertSame(['Erin Davis', 'George Wilson', 'Jill Taylor'], $names);

        $row = DB::queryOne("SELECT MAX(price) AS maxPrice FROM ::products");

        $this->assertSame('25.75', $row->maxPrice->value());
        $this->assertSame('SELECT MAX(price) AS maxPrice FROM test_products LIMIT 1', DB::$mysqli->lastQuery);
    }

    public function testJoinsTablePrefixesInRawSql(): void
    {
        DB::query("
            SELECT * FROM ::orders
         LEFT JOIN ::users ON ::orders.user_id = ::users.num
             WHERE ::orders.total_amount > ?", 100);

        $sql = preg_replace('/\s+/', ' ', trim(DB::$mysqli->lastQuery));

        $this->assertSame(
            'SELECT * FROM test_orders LEFT JOIN test_users ON test_orders.user_id = test_users.num WHERE test_orders.total_amount > 100',
            $sql
        );
    }

    public function testJoinsTableAliasesKeepTheirOwnNames(): void
    {
        DB::query("SELECT u.name, o.total_amount FROM ::users u JOIN ::orders o ON o.user_id = u.num");

        $this->assertSame(
            'SELECT u.name, o.total_amount FROM test_users u JOIN test_orders o ON o.user_id = u.num',
            DB::$mysqli->lastQuery
        );
    }

    public function testJoinsSmartJoinKeys(): void
    {
        $row = DB::queryOne("
            SELECT *, YEAR(o.order_date) AS orderYear
              FROM ::users  u
              JOIN ::orders o ON o.user_id = u.num
             WHERE u.num = ?", 6);

        $this->assertSame([
            'num',
            'name',
            'isAdmin',
            'status',
            'city',
            'dob',
            'age',
            'order_id',
            'user_id',
            'order_date',
            'total_amount',
            'orderYear',
            'users.num',
            'users.name',
            'users.isAdmin',
            'users.status',
            'users.city',
            'users.dob',
            'users.age',
            'orders.order_id',
            'orders.user_id',
            'orders.order_date',
            'orders.total_amount',
        ], array_keys($row->toArray()));

        $this->assertSame('Dave Williams', $row->name->value());
        $this->assertSame('Dave Williams', $row->{'users.name'}->value());
        $this->assertSame(1, $row->{'orders.order_id'}->value());
        $this->assertSame('2023-06-25', $row->{'orders.order_date'}->value());
        $this->assertSame(2023, $row->orderYear->value());
    }

    public function testJoinsDuplicateKeysFirstWins(): void
    {
        $row = DB::queryOne("SELECT * FROM ::users JOIN ::employees ON ::employees.id = ::users.num WHERE ::users.num = ?", 1);

        $this->assertSame('John Doe', $row->name->value());
        $this->assertSame('John Doe', $row->{'users.name'}->value());
        $this->assertSame('CEO', $row->{'employees.name'}->value());
    }

    public function testJoinsSelfJoinAliasKeys(): void
    {
        $rows = DB::query("SELECT * FROM ::employees a JOIN ::employees b ON a.manager_id = b.id ORDER BY a.id");
        $row  = $rows->first();

        $this->assertCount(5, $rows);
        $this->assertSame('VP Engineering', $row->{'a.name'}->value());
        $this->assertSame('CEO', $row->{'b.name'}->value());
        $this->assertSame('VP Engineering', $row->{'employees.name'}->value());
    }

    public function testJoinsTurningSmartJoinsOff(): void
    {
        $db   = DB::clone(['useSmartJoins' => false]);
        $rows = $db->query("SELECT u.name, o.order_date FROM ::users u JOIN ::orders o ON o.user_id = u.num");

        $this->assertSame(['name', 'order_date'], array_keys($rows->first()->toArray()));

        $aliased = $db->query("
            SELECT u.num AS userId, o.order_id AS orderId, u.name, o.total_amount
              FROM ::users u JOIN ::orders o ON o.user_id = u.num");

        $this->assertSame(['userId', 'orderId', 'name', 'total_amount'], array_keys($aliased->first()->toArray()));
    }

    public function testJoinsRawSqlAsValue(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_articles");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_articles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), createdAt DATETIME, views INT DEFAULT 0)");

        $id = DB::insert('articles', [
            'name'      => 'Alice',
            'createdAt' => DB::rawSql('NOW()'),
        ]);

        $this->assertSame(
            "INSERT INTO `test_articles` SET `name` = 'Alice', `createdAt` = NOW()",
            DB::$mysqli->lastQuery
        );

        DB::update('articles',
            ['views' => DB::rawSql('views + 1')],
            ['id'    => $id],
        );

        $this->assertSame(
            'UPDATE `test_articles` SET `views` = views + 1 WHERE `id` = 1',
            DB::$mysqli->lastQuery
        );
        $this->assertSame(1, DB::selectOne('articles', ['id' => $id])->views->value());
    }

    public function testJoinsSubqueriesNeedNoRawSql(): void
    {
        $pricey = DB::select('products', "price > (SELECT AVG(price) FROM ::products)");

        $this->assertSame(
            'SELECT * FROM `test_products` WHERE price > (SELECT AVG(price) FROM test_products)',
            DB::$mysqli->lastQuery
        );
        $this->assertSame(['Product C', 'Product E'], $pricey->pluck('product_name')->toArray());
    }

    public function testJoinsPuttingItTogether(): void
    {
        $pageNum = 1;
        $rows    = DB::query("
            SELECT u.name, o.order_date, p.product_name, od.quantity, (od.quantity * p.price) AS total
              FROM ::users         u
              JOIN ::orders        o  ON o.user_id    = u.num
              JOIN ::order_details od ON od.order_id  = o.order_id
              JOIN ::products      p  ON p.product_id = od.product_id
             WHERE u.city = :city
          ORDER BY o.order_date DESC
          :pagingSQL", [
            ':city'      => 'Ottawa',
            ':pagingSQL' => DB::pagingSql($pageNum, 25),
        ]);

        $sql = preg_replace('/\s+/', ' ', trim(DB::$mysqli->lastQuery));

        $this->assertSame(
            'SELECT u.name, o.order_date, p.product_name, od.quantity, (od.quantity * p.price) AS total '
            . 'FROM test_users u JOIN test_orders o ON o.user_id = u.num '
            . 'JOIN test_order_details od ON od.order_id = o.order_id '
            . 'JOIN test_products p ON p.product_id = od.product_id '
            . "WHERE u.city = 'Ottawa' ORDER BY o.order_date DESC LIMIT 25 OFFSET 0",
            $sql
        );

        $this->assertSame([
            'name',
            'order_date',
            'product_name',
            'quantity',
            'total',
            'users.name',
            'orders.order_date',
            'products.product_name',
            'order_details.quantity',
        ], array_keys($rows->first()->toArray()));

        $row = $rows->first();
        $this->assertSame('Dave Williams', $row->name->value());
        $this->assertSame('Product A', $row->product_name->value());
        $this->assertSame(2, $row->quantity->value());
        $this->assertSame('21.98', $row->total->value());
    }

    //endregion
    //region docs/common-patterns.md

    public function testCommonPatternsRecordDetailOr404(): void
    {
        $id   = 1;
        $user = DB::selectOne('users', ['num' => $id])->or404();

        $this->assertSame('SELECT * FROM `test_users` WHERE `num` = 1 LIMIT 1', DB::$mysqli->lastQuery);
        $this->assertSame('John Doe', $user->name->value());
        $this->assertSame('Member since Apr 10, 1985', "Member since {$user->dob->dateFormat('M j, Y')}");
    }

    public function testCommonPatternsOrThrowRowAndValue(): void
    {
        $email   = 'nobody@example.com';
        $message = null;

        try {
            DB::selectOne('users', ['name' => 'Nobody Here'])->orThrow("No user found for $email");
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('No user found for nobody@example.com', $message);

        $message = null;
        try {
            DB::selectOne('users', ['num' => 2])
                ->orThrow("No user found for $email")
                ->isAdmin
                ->orThrow("User $email has no admin flag")
                ->int();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('User nobody@example.com has no admin flag', $message);

        $age = DB::selectOne('users', ['num' => 1])
            ->orThrow("No user found for $email")
            ->age
            ->orThrow("User $email has no age")
            ->int();
        $this->assertSame(38, $age);
    }

    public function testCommonPatternsCheckingARowExists(): void
    {
        $emailInUse = DB::count('users', ['city' => 'Vancouver']);

        $this->assertSame("SELECT COUNT(*) FROM `test_users` WHERE `city` = 'Vancouver'", DB::$mysqli->lastQuery);
        $this->assertSame(1, $emailInUse);
        $this->assertSame(0, DB::count('users', ['city' => 'Nowhere']));
    }

    public function testCommonPatternsOneValueFromQuery(): void
    {
        $newest = DB::queryOne("SELECT MAX(dob) AS newest FROM ::users")->newest;

        $this->assertSame('SELECT MAX(dob) AS newest FROM test_users LIMIT 1', DB::$mysqli->lastQuery);
        $this->assertSame('Newest signup: Jan 1, 2000', "Newest signup: {$newest->dateFormat('M j, Y')}");
    }

    public function testCommonPatternsReadingAColumnByPosition(): void
    {
        $row       = DB::queryOne("SHOW CREATE TABLE ::users");
        $createSql = $row->at(1)->value();

        $this->assertSame(['Table', 'Create Table'], array_keys($row->toArray()));
        $this->assertStringStartsWith('CREATE TEMPORARY TABLE `test_users`', $createSql);
        $this->assertSame($createSql, $row->{'Create Table'}->value());
    }

    public function testCommonPatternsColumnAt(): void
    {
        $columns = DB::query("SHOW COLUMNS FROM ::users")->columnAt(0)->toArray();

        $this->assertSame(['num', 'name', 'isAdmin', 'status', 'city', 'dob', 'age'], $columns);

        $tables = DB::query("SHOW TABLES");

        $this->assertSame(['Tables_in_phpunit_test_db'], array_keys($tables->first()->toArray()));
        $this->assertSame($tables->column('Tables_in_phpunit_test_db')->toArray(), $tables->columnAt(0)->toArray());
    }

    public function testCommonPatternsInsertThenLoadTheNewRow(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_newusers");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_newusers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), email VARCHAR(255), createdAt DATETIME)");

        $newId = DB::insert('newusers', [
            'name'      => 'Bob Smith',
            'email'     => 'bob@example.com',
            'createdAt' => DB::rawSql('NOW()'),
        ]);

        $this->assertSame(
            "INSERT INTO `test_newusers` SET `name` = 'Bob Smith', `email` = 'bob@example.com', `createdAt` = NOW()",
            DB::$mysqli->lastQuery
        );
        $this->assertSame(1, $newId);

        $user = DB::selectOne('newusers', ['id' => $newId]);

        $this->assertSame('SELECT * FROM `test_newusers` WHERE `id` = 1 LIMIT 1', DB::$mysqli->lastQuery);
        $this->assertSame('Bob Smith', $user->name->value());
    }

    public function testCommonPatternsSearchSortAndPaginate(): void
    {
        $search  = 'john';
        $page    = 1;
        $perPage = 25;

        $total = DB::count('users', "name LIKE ?", DB::likeContains($search));

        $this->assertSame("SELECT COUNT(*) FROM `test_users` WHERE name LIKE '%john%'", DB::$mysqli->lastQuery);
        $this->assertSame(2, $total);

        $totalPages = max(1, ceil($total / $perPage));
        $this->assertSame(1, $totalPages);

        $users = DB::select('users', "name LIKE :search ORDER BY name :paging", [
            ':search' => DB::likeContains($search),
            ':paging' => DB::pagingSql($page, $perPage),
        ]);

        $this->assertSame(
            "SELECT * FROM `test_users` WHERE name LIKE '%john%' ORDER BY name LIMIT 25 OFFSET 0",
            DB::$mysqli->lastQuery
        );
        $this->assertSame(['Bob Johnson', 'John Doe'], $users->pluck('name')->toArray());

        $html = '';
        foreach ($users as $user) {
            $html .= "<div>$user->name - $user->city</div>";
        }
        $this->assertSame('<div>Bob Johnson - Calgary</div><div>John Doe - Vancouver</div>', $html);
    }

    public function testCommonPatternsEmptySearchMatchesEveryRow(): void
    {
        $users = DB::select('users', "name LIKE :search ORDER BY name :paging", [
            ':search' => DB::likeContains(''),
            ':paging' => DB::pagingSql(1, 25),
        ]);

        $this->assertSame(
            "SELECT * FROM `test_users` WHERE name LIKE '%%' ORDER BY name LIMIT 25 OFFSET 0",
            DB::$mysqli->lastQuery
        );
        $this->assertCount(20, $users);
    }

    public function testCommonPatternsHtmlTableFromQueryResults(): void
    {
        $users = DB::select('users', ['status' => 'Suspended']);

        $this->assertSame("SELECT * FROM `test_users` WHERE `status` = 'Suspended'", DB::$mysqli->lastQuery);
        $this->assertFalse($users->isEmpty());

        $html = '';
        foreach ($users as $user) {
            $html .= "<tr><td>$user->name</td><td>$user->city</td><td>{$user->dob->dateFormat('M j, Y')}</td></tr>";
        }

        $this->assertStringContainsString(
            '<tr><td>Frank &lt;b&gt;Miller&lt;/b&gt;</td><td>Winnipeg</td><td>Jul 22, 1992</td></tr>',
            $html
        );
        $this->assertTrue(DB::select('users', ['city' => 'Nowhere'])->isEmpty());
    }

    public function testCommonPatternsHtmlTableFromAnyQuery(): void
    {
        $rows = DB::select('users', "num = ? LIMIT 1", 8);

        $thead = '';
        foreach ($rows->first()->keys() as $name) {
            $thead .= "<th>$name</th>";
        }
        $this->assertSame(
            "<th>num</th><th>name</th><th>isAdmin</th><th>status</th><th>city</th><th>dob</th><th>age</th>",
            $thead
        );

        $tbody = '';
        foreach ($rows->first() as $value) {
            $tbody .= "<td>$value</td>";
        }
        $this->assertSame(
            "<td>8</td><td>Frank &lt;b&gt;Miller&lt;/b&gt;</td><td>0</td><td>Suspended</td><td>Winnipeg</td><td>1992-07-22</td><td>31</td>",
            $tbody
        );
    }

    public function testCommonPatternsSelectDropdown(): void
    {
        $products = DB::select('products', "ORDER BY product_name");

        $html = '';
        foreach ($products as $product) {
            $html .= "<option value=\"$product->product_id\">$product->product_name</option>";
        }

        $this->assertStringStartsWith('<option value="1">Product A</option>', $html);
        $this->assertStringEndsWith('<option value="5">Product E</option>', $html);
    }

    public function testCommonPatternsGroupedDisplay(): void
    {
        $users     = DB::select('users', "ORDER BY status, name");
        $byStatus  = $users->groupBy('status');
        $keys      = array_keys($byStatus->toArray());

        $this->assertSame(['Active', 'Inactive', 'Suspended'], $keys);
        $this->assertSame('string', get_debug_type($keys[0]));
        $this->assertCount(10, $byStatus->Active);
        $this->assertCount(5, $byStatus->Inactive);
        $this->assertCount(5, $byStatus->Suspended);

        $html = '';
        foreach ($byStatus->Inactive as $item) {
            $html .= "<li>$item->name - {$item->age->numberFormat(2)->prepend('$')}</li>";
        }
        $this->assertStringStartsWith('<li>Dave Williams - $48.00</li>', $html);
    }

    public function testCommonPatternsLookupMaps(): void
    {
        $productNames = DB::select('products')->column('product_name', 'product_id');

        $this->assertSame([
            1 => 'Product A',
            2 => 'Product B',
            3 => 'Product C',
            4 => 'Product D',
            5 => 'Product E',
        ], $productNames->toArray());

        $productsById = DB::select('products')->indexBy('product_id');
        $this->assertSame('Product C', $productsById->{'3'}->product_name->value());
    }

    public function testCommonPatternsCheckingAColumnForAValue(): void
    {
        $admins = DB::select('users', ['isAdmin' => 1]);

        $this->assertTrue($admins->column('name')->contains('Alice Smith'));
        $this->assertFalse($admins->column('name')->contains('Bob Johnson'));

        $hasNumIndex = DB::query("SHOW INDEX FROM ::users")->column('Column_name')->contains('num');
        $this->assertTrue($hasNumIndex);
    }

    public function testCommonPatternsDefaultMissingNumbersToZero(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_prices");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_prices (id INT PRIMARY KEY, price DECIMAL(10,2) NULL, total DECIMAL(10,2))");
        DB::$mysqli->query("INSERT INTO test_prices VALUES (1, NULL, 1234.56), (2, 19.99, 0.00)");

        $product = DB::selectOne('prices', ['id' => 1]);

        $this->assertSame('', (string) $product->price->numberFormat(2));
        $this->assertSame('0.00', (string) $product->price->or(0)->numberFormat(2));
        $this->assertSame('n/a', (string) $product->price->numberFormat(2)->or('n/a'));

        $this->assertSame('1,234.56', (string) $product->total->numberFormat(2)->ifZero(''));
        $this->assertSame('', (string) DB::selectOne('prices', ['id' => 2])->total->numberFormat(2)->ifZero(''));
    }

    public function testCommonPatternsCalculationsInTemplates(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_stats");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_stats (id INT PRIMARY KEY, inquiries INT, days INT, leads INT, visitors INT, sales INT, completionRate DECIMAL(5,3))");
        DB::$mysqli->query("INSERT INTO test_stats VALUES (1, 250, 30, 45, 1234, 120, 0.254), (2, 250, 0, 0, 0, 100, 0.000)");

        $stats    = DB::selectOne('stats', ['id' => 1]);
        $noTraffic = DB::selectOne('stats', ['id' => 2]);

        $this->assertSame('8.3', (string) $stats->inquiries->divide($stats->days)->numberFormat(1)->or('-'));
        $this->assertSame('-', (string) $noTraffic->inquiries->divide($noTraffic->days)->numberFormat(1)->or('-'));
        $this->assertSame('3.6%', (string) $stats->leads->percentOf($stats->visitors, 1)->or('-'));

        $withSign = fn(string $v) => str_starts_with($v, '-') ? $v : "+$v";

        $this->assertSame('+20%', (string) $stats->sales
            ->subtract($noTraffic->sales)
            ->percentOf($noTraffic->sales)
            ->ifNull('-')
            ->map($withSign));
        $this->assertSame('-17%', (string) $noTraffic->sales
            ->subtract($stats->sales)
            ->percentOf($stats->sales)
            ->ifNull('-')
            ->map($withSign));

        $this->assertSame('25.4%', (string) $stats->completionRate->percent(1));
        $this->assertSame('-', (string) $noTraffic->completionRate->percent(0, '-'));
    }

    public function testCommonPatternsAddressLinesSkipEmptyFields(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_addresses");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_addresses (id INT PRIMARY KEY, city VARCHAR(255), region VARCHAR(255) NULL, postalCode VARCHAR(20), balance DECIMAL(10,2) NULL)");
        DB::$mysqli->query("INSERT INTO test_addresses VALUES (1,'Vancouver','BC','V6B 1A1',0), (2,'Vancouver',NULL,'V6B 1A1',NULL)");

        $withRegion = DB::selectOne('addresses', ['id' => 1]);
        $noRegion   = DB::selectOne('addresses', ['id' => 2]);

        $this->assertSame(
            'Vancouver, BC V6B 1A1',
            $withRegion->city->append(', ') . $withRegion->region->append(' ') . $withRegion->postalCode
        );
        $this->assertSame(
            'Vancouver, V6B 1A1',
            $noRegion->city->append(', ') . $noRegion->region->append(' ') . $noRegion->postalCode
        );

        $this->assertSame('$0.00', (string) $withRegion->balance->prepend('$'));
        $this->assertSame('', (string) $noRegion->balance->prepend('$'));
    }

    public function testCommonPatternsValuesInUrlsAndJavascript(): void
    {
        $user = DB::selectOne('users', ['num' => 8]);

        $this->assertSame(
            "<a href='/users?name=Frank+%3Cb%3EMiller%3C%2Fb%3E'>Frank &lt;b&gt;Miller&lt;/b&gt;</a>",
            "<a href='/users?name={$user->name->urlEncode()}'>$user->name</a>"
        );
        $this->assertSame(
            '<script>let userName = "Frank \u003Cb\u003EMiller\u003C/b\u003E";</script>',
            "<script>let userName = {$user->name->jsonEncode()};</script>"
        );
    }

    public function testCommonPatternsClickToCallPhoneLinks(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_contacts");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_contacts (id INT PRIMARY KEY, phone VARCHAR(30))");
        DB::$mysqli->query("INSERT INTO test_contacts VALUES (1, '(604) 555-1234')");

        $user = DB::selectOne('contacts', ['id' => 1]);

        $this->assertSame(
            '<a href="tel:6045551234">(604) 555-1234</a>',
            "<a href=\"tel:{$user->phone->pregReplace('/\D/', '')}\">$user->phone</a>"
        );
    }

    public function testCommonPatternsDisplayingTrustedHtml(): void
    {
        $article = DB::selectOne('special_chars', ['id' => 1]);

        $this->assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', (string) $article->html_content);
        $this->assertSame('<script>alert("xss")</script>', $article->html_content->rawHtml());
    }

    //endregion
    //region docs/helpers-and-utilities.md

    public function testHelpersRawSqlInValuePositions(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_accounts");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_accounts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), createdAt DATETIME, views INT DEFAULT 0)");

        DB::insert('accounts', ['name' => 'Alice', 'createdAt' => DB::rawSql('NOW()')]);
        $this->assertSame(
            "INSERT INTO `test_accounts` SET `name` = 'Alice', `createdAt` = NOW()",
            DB::$mysqli->lastQuery
        );

        DB::update('accounts', ['views' => DB::rawSql('views + 1')], ['id' => 1]);
        $this->assertSame(
            'UPDATE `test_accounts` SET `views` = views + 1 WHERE `id` = 1',
            DB::$mysqli->lastQuery
        );

        DB::select('users', ['dob' => DB::rawSql('CURDATE()')]);
        $this->assertSame('SELECT * FROM `test_users` WHERE `dob` = CURDATE()', DB::$mysqli->lastQuery);

        DB::select('users', "dob > ?", DB::rawSql('CURDATE()'));
        $this->assertSame('SELECT * FROM `test_users` WHERE dob > CURDATE()', DB::$mysqli->lastQuery);
    }

    public function testHelpersRawSqlConvertsScalarsAndNull(): void
    {
        $this->assertSame('NOW()', (string) DB::rawSql('NOW()'));
        $this->assertSame('42', (string) DB::rawSql(42));
        $this->assertSame('3.5', (string) DB::rawSql(3.5));
        $this->assertSame('NULL', (string) DB::rawSql(null));
    }

    public function testHelpersIdentifierPlaceholderInsteadOfRawSql(): void
    {
        $sort = 'city';
        DB::select('users', "ORDER BY `:sort`", [':sort' => $sort]);

        $this->assertSame('SELECT * FROM `test_users` ORDER BY `city`', DB::$mysqli->lastQuery);
    }

    public function testHelpersPagingSql(): void
    {
        $this->assertSame('LIMIT 10 OFFSET 0', (string) DB::pagingSql(1));
        $this->assertSame('LIMIT 25 OFFSET 50', (string) DB::pagingSql(3, 25));
        $this->assertSame('LIMIT 10 OFFSET 0', (string) DB::pagingSql(0));
        $this->assertSame('LIMIT 10 OFFSET 0', (string) DB::pagingSql('abc'));
        $this->assertSame('LIMIT 25 OFFSET 50', (string) DB::pagingSql(-3, 25));

        DB::select('users', "ORDER BY name :paging", [':paging' => DB::pagingSql(3, 25)]);
        $this->assertSame('SELECT * FROM `test_users` ORDER BY name LIMIT 25 OFFSET 50', DB::$mysqli->lastQuery);
    }

    public function testHelpersPagingSqlWithTotalPages(): void
    {
        $total      = DB::count('users');
        $totalPages = max(1, ceil($total / 25));

        $this->assertSame(20, $total);
        $this->assertSame(1, $totalPages);
        $this->assertSame(12.0, max(1, ceil(300 / 25)));
    }

    public function testHelpersLikePatterns(): void
    {
        $this->assertSame("'%John%'", (string) DB::likeContains('John'));
        $this->assertSame("'John%'", (string) DB::likeStartsWith('John'));
        $this->assertSame("'%son'", (string) DB::likeEndsWith('son'));
        $this->assertSame("'%\\tnews\\t%'", (string) DB::likeContainsTSV('news'));

        $users = DB::select('users', "name LIKE ?", DB::likeContains('John'));
        $this->assertSame("SELECT * FROM `test_users` WHERE name LIKE '%John%'", DB::$mysqli->lastQuery);
        $this->assertSame(['John Doe', 'Bob Johnson'], $users->pluck('name')->toArray());
    }

    public function testHelpersLikeEscapesWildcards(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_promos");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_promos (id INT PRIMARY KEY, name VARCHAR(50))");
        DB::$mysqli->query("INSERT INTO test_promos VALUES (1,'50% off'),(2,'505 sale'),(3,'50 percent')");

        $matches = DB::select('promos', "name LIKE ?", DB::likeContains('50%'));

        $this->assertSame("SELECT * FROM `test_promos` WHERE name LIKE '%50\\%%'", DB::$mysqli->lastQuery);
        $this->assertSame(['50% off'], $matches->pluck('name')->toArray());

        $startsWith = DB::select('promos', "name LIKE ?", DB::likeStartsWith('50'));
        $this->assertSame(['50% off', '505 sale', '50 percent'], $startsWith->pluck('name')->toArray());

        $endsWith = DB::select('promos', "name LIKE ?", DB::likeEndsWith('sale'));
        $this->assertSame(['505 sale'], $endsWith->pluck('name')->toArray());
    }

    public function testHelpersLikeContainsTsv(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_tagged");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_tagged (id INT PRIMARY KEY, product_name VARCHAR(50), tags VARCHAR(100))");
        DB::$mysqli->query("INSERT INTO test_tagged VALUES (1,'A','\tfeatured\tnew\t'),(2,'B','\tunfeatured\t'),(3,'C','\tnew\t')");

        $featured = DB::select('tagged', "tags LIKE ?", DB::likeContainsTSV('featured'));

        $this->assertSame("SELECT * FROM `test_tagged` WHERE tags LIKE '%\\tfeatured\\t%'", DB::$mysqli->lastQuery);
        $this->assertSame(['A'], $featured->pluck('product_name')->toArray());
    }

    public function testHelpersLikeAcceptsSmartStringsAndNumbers(): void
    {
        $this->assertSame("'%42%'", (string) DB::likeContains(42));
        $this->assertSame("'%3.5%'", (string) DB::likeContains(3.5));
        $this->assertSame("'%%'", (string) DB::likeContains(null));
        $this->assertSame("'%John%'", (string) DB::likeContains(new SmartString('John')));
    }

    public function testHelpersTablePrefixConversion(): void
    {
        $this->assertSame('test_users', DB::getFullTable('users'));
        $this->assertSame('test_users', DB::getFullTable('test_users'));

        $this->assertSame('users', DB::getBaseTable('test_users'));
        $this->assertSame('users', DB::getBaseTable('users'));
    }

    public function testHelpersTablePrefixConversionWithCheckDb(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_test_archive");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_test_archive (id INT PRIMARY KEY)");

        $this->assertSame('test_test_archive', DB::getFullTable('test_archive', checkDb: true));
        $this->assertSame('test_archive', DB::getBaseTable('test_archive', checkDb: true));

        $this->assertSame('test_users', DB::getFullTable('test_users', checkDb: true));
        $this->assertSame('users', DB::getBaseTable('test_users', checkDb: true));
    }

    public function testHelpersDateAndTimeFormatConstants(): void
    {
        $this->assertSame('Y-m-d H:i:s', DB::DATETIME);
        $this->assertSame('Y-m-d', DB::DATE);
        $this->assertSame('H:i:s', DB::TIME);

        $this->assertSame('2026-07-08 14:30:00', date(DB::DATETIME, strtotime('2026-07-08 14:30:00')));
        $this->assertSame('2026-07-08', date(DB::DATE, strtotime('2026-07-08 14:30:00')));
        $this->assertSame('14:30:00', date(DB::TIME, strtotime('2026-07-08 14:30:00')));

        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_coupons");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_coupons (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20), expiresAt DATE, lastLogin DATETIME)");

        $expiry = date(DB::DATE, strtotime('2026-07-08 14:30:00 +30 days'));
        DB::insert('coupons', ['code' => 'SUMMER26', 'expiresAt' => $expiry]);
        $this->assertSame(
            "INSERT INTO `test_coupons` SET `code` = 'SUMMER26', `expiresAt` = '2026-08-07'",
            DB::$mysqli->lastQuery
        );

        DB::update('coupons', ['lastLogin' => date(DB::DATETIME, strtotime('2026-07-08 14:30:00'))], ['id' => 1]);
        $this->assertSame(
            "UPDATE `test_coupons` SET `lastLogin` = '2026-07-08 14:30:00' WHERE `id` = 1",
            DB::$mysqli->lastQuery
        );
    }

    //endregion
    //region docs/multiple-connections.md

    public function testMultipleConnectionsCloneWithDifferentTablePrefix(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS legacy_users");
        DB::$mysqli->query("CREATE TEMPORARY TABLE legacy_users (num INT PRIMARY KEY, name VARCHAR(255))");
        DB::$mysqli->query("INSERT INTO legacy_users VALUES (1, 'Old Person')");

        $legacy = DB::clone(['tablePrefix' => 'legacy_']);

        $users = DB::select('users');
        $this->assertSame('SELECT * FROM `test_users` ', DB::$mysqli->lastQuery);
        $this->assertCount(20, $users);

        $oldUsers = $legacy->select('users');
        $this->assertSame('SELECT * FROM `legacy_users` ', DB::$mysqli->lastQuery);
        $this->assertSame(['Old Person'], $oldUsers->pluck('name')->toArray());
    }

    public function testMultipleConnectionsCloneWithoutSmartStrings(): void
    {
        $raw = DB::clone(['useSmartStrings' => false]);

        $users = DB::select('users', ['status' => 'Active']);
        $rows  = $raw->select('users', ['status' => 'Active']);

        $this->assertInstanceOf(SmartString::class, $users->first()->name);
        $this->assertSame('John Doe', $rows->first()->name);
    }

    public function testMultipleConnectionsCloneWithoutSmartJoins(): void
    {
        $plain = DB::clone(['useSmartJoins' => false]);
        $rows  = $plain->query("SELECT u.name, o.total_amount FROM ::users u JOIN ::orders o ON o.user_id = u.num");

        $this->assertSame(['name', 'total_amount'], array_keys($rows->first()->toArray()));
    }

    public function testMultipleConnectionsCloneRejectsOtherConfigKeys(): void
    {
        $message = null;

        try {
            DB::clone(['database' => 'analytics']);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            'clone() only supports: tablePrefix, useSmartJoins, useSmartStrings. Got: database',
            $message
        );
    }

    public function testMultipleConnectionsCloneSharesTransactionState(): void
    {
        $clone   = DB::clone(['tablePrefix' => 'test_']);
        $message = null;

        try {
            DB::transaction(fn() => $clone->transaction(fn() => null));
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('transaction() cannot be nested - already in a transaction', $message);
    }

    public function testMultipleConnectionsSecondDatabase(): void
    {
        $reports = new Connection(self::$configDefaults);

        try {
            $reports->mysqli->query("CREATE TEMPORARY TABLE test_daily_signups (id INT AUTO_INCREMENT PRIMARY KEY, signupDate DATE, total INT)");
            $reports->mysqli->query("INSERT INTO test_daily_signups (signupDate, total) VALUES ('2026-06-30', 5), ('2026-07-01', 9), ('2026-07-02', 11)");

            $signups = $reports->select('daily_signups', "signupDate >= ?", '2026-07-01');

            $this->assertSame(
                "SELECT * FROM `test_daily_signups` WHERE signupDate >= '2026-07-01'",
                $reports->mysqli->lastQuery
            );
            $this->assertCount(2, $signups);

            $rowCount = $reports->count('daily_signups');
            $this->assertSame(3, $rowCount);

            $reports->insert('daily_signups', ['signupDate' => '2026-07-03', 'total' => 2]);
            $this->assertSame(4, $reports->count('daily_signups'));

            // the default connection is untouched: its own tables, its own state
            $this->assertSame(20, DB::count('users'));
        } finally {
            $reports->disconnect();
        }
    }

    //endregion
    //region docs/encryption.md

    public function testEncryptionWritesAndReadsWithoutQueryChanges(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_secrets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), apiToken MEDIUMBLOB, ssn MEDIUMBLOB)");

        $id = $db->insert('secrets', ['name' => 'Alice', 'apiToken' => 'secret-token-value']);
        $this->assertStringStartsWith("INSERT INTO `test_secrets` SET `name` = 'Alice', `apiToken` = '", $db->mysqli->lastQuery);
        $this->assertStringNotContainsString('secret-token-value', $db->mysqli->lastQuery);

        $db->update('secrets', ['apiToken' => 'new-token'], ['id' => $id]);
        $this->assertStringNotContainsString('new-token', $db->mysqli->lastQuery);

        $user = $db->selectOne('secrets', ['id' => $id]);
        $this->assertSame('new-token', $user->apiToken->value());

        $stored = $db->mysqli->query("SELECT apiToken FROM test_secrets WHERE id = 1")->fetch_assoc();
        $this->assertNotSame('new-token', $stored['apiToken']);
    }

    public function testEncryptionNullPassesThroughUnencrypted(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_secrets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), apiToken MEDIUMBLOB)");

        $id = $db->insert('secrets', ['name' => 'No Token', 'apiToken' => null]);

        $stored = $db->mysqli->query("SELECT apiToken FROM test_secrets WHERE id = $id")->fetch_assoc();
        $this->assertNull($stored['apiToken']);
        $this->assertNull($db->selectOne('secrets', ['id' => $id])->apiToken->value());
        $this->assertNull($db->encryptValue(null));
    }

    public function testEncryptionExactMatchSearchWithEncryptValue(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_secrets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), apiToken MEDIUMBLOB)");

        $db->insert('secrets', ['name' => 'Alice', 'apiToken' => 'secret-token-value']);
        $db->insert('secrets', ['name' => 'Bob', 'apiToken' => 'other-token']);

        $user = $db->selectOne('secrets', ['apiToken' => $db->encryptValue('secret-token-value')]);

        $this->assertSame('Alice', $user->name->value());
        $this->assertStringStartsWith('SELECT * FROM `test_secrets` WHERE `apiToken` = ', $db->mysqli->lastQuery);
        $this->assertStringNotContainsString('secret-token-value', $db->mysqli->lastQuery);
        $this->assertSame($db->encryptValue('secret-token-value'), $db->encryptValue(new SmartString('secret-token-value')));
    }

    public function testEncryptionEncryptValueInRawSql(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_secrets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), apiToken MEDIUMBLOB)");

        $id = $db->insert('secrets', ['name' => 'Alice', 'apiToken' => 'first-token']);
        $db->query("UPDATE ::secrets SET apiToken = ? WHERE id = ?", $db->encryptValue('new-token'), $id);

        $this->assertStringStartsWith('UPDATE test_secrets SET apiToken = ', $db->mysqli->lastQuery);
        $this->assertSame('new-token', $db->selectOne('secrets', ['id' => $id])->apiToken->value());
    }

    public function testEncryptionDoubleBraceDecryptsInMysql(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_secrets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), apiToken MEDIUMBLOB)");

        $db->insert('secrets', ['name' => 'Alice', 'apiToken' => 'secret-token-value']);
        $db->insert('secrets', ['name' => 'Bob', 'apiToken' => 'no-match-here']);

        $users = $db->select('secrets', "{{apiToken}} LIKE ?", '%token%');

        $this->assertSame(
            "SELECT * FROM `test_secrets` WHERE AES_DECRYPT(`apiToken`, @ek) LIKE '%token%'",
            $db->mysqli->lastQuery
        );
        $this->assertSame(['Alice'], $users->pluck('name')->toArray());
    }

    public function testEncryptionDecryptExpr(): void
    {
        $this->assertSame('AES_DECRYPT(`apiToken`, @ek)', DB::decryptExpr('apiToken'));
        $this->assertSame('AES_DECRYPT(`blog`.`title`, @ek)', DB::decryptExpr('blog.title'));
    }

    public function testEncryptionDoubleBraceWithTableAndAlias(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_secrets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), apiToken MEDIUMBLOB)");
        $db->insert('secrets', ['name' => 'Alice', 'apiToken' => 'secret-token-value']);

        $users = $db->select('secrets', "{{::secrets.apiToken}} LIKE ?", '%token%');
        $this->assertSame(
            "SELECT * FROM `test_secrets` WHERE AES_DECRYPT(`test_secrets`.`apiToken`, @ek) LIKE '%token%'",
            $db->mysqli->lastQuery
        );
        $this->assertSame(['Alice'], $users->pluck('name')->toArray());

        $rows = $db->query("SELECT * FROM ::secrets u WHERE {{u.apiToken}} LIKE ?", '%token%');
        $this->assertSame(
            "SELECT * FROM test_secrets u WHERE AES_DECRYPT(`u`.`apiToken`, @ek) LIKE '%token%'",
            $db->mysqli->lastQuery
        );
        $this->assertSame(['Alice'], $rows->pluck('name')->toArray());
    }

    public function testEncryptionDecryptRawMysqliRows(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_secrets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), apiToken MEDIUMBLOB, ssn MEDIUMBLOB)");
        $db->insert('secrets', ['name' => 'Alice', 'apiToken' => 'secret-token-value', 'ssn' => '123-45-6789']);

        $result = $db->mysqli->query("SELECT * FROM test_secrets");
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $fields = $result->fetch_fields();

        $this->assertNotSame('secret-token-value', $rows[0]['apiToken']);
        $this->assertSame([2 => 'apiToken', 3 => 'ssn'], DB::getEncryptedColumns($fields));

        $db->decryptRows($rows, $fields);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('secret-token-value', $rows[0]['apiToken']);
        $this->assertSame('123-45-6789', $rows[0]['ssn']);

        $again = $db->mysqli->query("SELECT * FROM test_secrets")->fetch_all(MYSQLI_ASSOC);
        $db->decryptRows($again, ['apiToken', 'ssn']);
        $this->assertSame('secret-token-value', $again[0]['apiToken']);
    }

    public function testEncryptionCoversMediumBlobOnly(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_blob_types (id INT PRIMARY KEY, tiny TINYBLOB, regular BLOB, medium MEDIUMBLOB, big LONGBLOB)");

        $result = $db->mysqli->query("SELECT * FROM test_blob_types LIMIT 0");
        $columns = DB::getEncryptedColumns($result->fetch_fields());

        $this->assertSame([3 => 'medium'], $columns);
    }

    public function testEncryptionWarnsOncePerConnectionWhenDecryptFails(): void
    {
        $db = new Connection(array_merge(self::$configDefaults, ['encryptionKey' => 'docs-example-key']));
        $db->mysqli->query("CREATE TEMPORARY TABLE test_secrets (id INT PRIMARY KEY, apiToken MEDIUMBLOB)");
        $db->mysqli->query("INSERT INTO test_secrets VALUES (1, 'raw-bytes'), (2, 'more-raw-bytes')");

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return $errno === E_USER_WARNING;
        }, E_USER_WARNING);

        try {
            $rows = $db->select('secrets');
            $db->select('secrets');
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $warnings);
        $this->assertSame(
            "ZenDB: can't decrypt MEDIUMBLOB column 'apiToken', returning raw bytes. Wrong encryptionKey, or the column holds unencrypted data.",
            $warnings[0]
        );
        $this->assertSame('raw-bytes', $rows->first()->apiToken->value());
    }

    public function testEncryptionEncryptValueWithoutKeyThrows(): void
    {
        $plain   = new Connection(self::$configDefaults);
        $message = null;

        try {
            $plain->encryptValue('test');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        } finally {
            $plain->disconnect();
        }

        $this->assertSame("aesKey() requires 'encryptionKey' in connection config.", $message);
    }

    //endregion
    //region docs/security-gotchas.md

    public function testSecurityGotchasRawMysqliSkipsEverything(): void
    {
        $name = 'Frank <b>Miller</b>';

        $raw = DB::$mysqli->query("SELECT name FROM test_users WHERE num = 8")->fetch_assoc();
        $this->assertSame('Frank <b>Miller</b>', $raw['name']);

        $rows = DB::query("SELECT * FROM ::users WHERE name = ?", $name);
        $this->assertSame("SELECT * FROM test_users WHERE name = 'Frank <b>Miller</b>'", DB::$mysqli->lastQuery);
        $this->assertSame('Frank &lt;b&gt;Miller&lt;/b&gt;', (string) $rows->first()->name);
    }

    public function testSecurityGotchasRawHtmlSkipsEncoding(): void
    {
        $row = DB::selectOne('special_chars', ['id' => 2]);

        $this->assertSame('&lt;b&gt;Bold&lt;/b&gt; &amp; &quot;quoted&quot;', (string) $row->html_content);
        $this->assertSame('<b>Bold</b> & "quoted"', $row->html_content->rawHtml());
        $this->assertSame($row->html_content->value(), $row->html_content->rawHtml());
    }

    public function testSecurityGotchasQuotesInTemplateThrow(): void
    {
        $name    = 'Vancouver';
        $message = null;

        try {
            DB::query("SELECT * FROM ::users WHERE name = '$name'");
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            "Quotes not allowed in template. Replace 'Vancouver' with :paramName and add: [ ':paramName' => 'Vancouver' ]",
            $message
        );

        $rows = DB::query("SELECT * FROM ::users WHERE name = ?", $name);
        $this->assertCount(0, $rows);
        $this->assertSame("SELECT * FROM test_users WHERE name = 'Vancouver'", DB::$mysqli->lastQuery);
    }

    public function testSecurityGotchasEmptyQuotesGap(): void
    {
        $name = "' OR name=name #'";
        $rows = DB::query("SELECT * FROM ::users WHERE name = '$name'");

        $this->assertSame("SELECT * FROM test_users WHERE name = '' OR name=name #''", DB::$mysqli->lastQuery);
        $this->assertCount(20, $rows);

        $safe = DB::query("SELECT * FROM ::users WHERE name != :empty", [':empty' => '']);
        $this->assertSame("SELECT * FROM test_users WHERE name != ''", DB::$mysqli->lastQuery);
        $this->assertCount(20, $safe);
    }

    public function testSecurityGotchasDynamicIdentifiers(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_members");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_members (num INT PRIMARY KEY, name VARCHAR(255))");
        DB::$mysqli->query("INSERT INTO test_members VALUES (1,'Carol'),(2,'Alice'),(3,'Bob')");

        $sort = 'name';

        $byConstant = DB::query("SELECT * FROM ::members ORDER BY ?", $sort);
        $this->assertSame("SELECT * FROM test_members ORDER BY 'name'", DB::$mysqli->lastQuery);
        $this->assertSame([1, 2, 3], $byConstant->pluck('num')->toArray());

        $byColumn = DB::query("SELECT * FROM ::members ORDER BY `:sort`", [':sort' => $sort]);
        $this->assertSame('SELECT * FROM test_members ORDER BY `name`', DB::$mysqli->lastQuery);
        $this->assertSame(['Alice', 'Bob', 'Carol'], $byColumn->pluck('name')->toArray());

        $message = null;
        try {
            DB::query("SELECT * FROM ::members ORDER BY `:sort`", [':sort' => 'name; DROP TABLE x']);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame(
            "Invalid backtick identifier: 'name; DROP TABLE x'. Only word characters (a-z, 0-9, _, -) allowed.",
            $message
        );
    }

    public function testSecurityGotchasMappedSortColumnAndDirection(): void
    {
        $sortIndex = 0;
        $dir       = 'desc';
        $column    = ['name', 'city', 'age'][$sortIndex] ?? 'name';
        $direction = $dir === 'desc' ? 'DESC' : 'ASC';

        $rows = DB::query("SELECT * FROM ::users ORDER BY `:col` " . $direction, [':col' => $column]);

        $this->assertSame('SELECT * FROM test_users ORDER BY `name` DESC', DB::$mysqli->lastQuery);
        $this->assertSame('Rachel Carter', $rows->first()->name->value());
    }

    //endregion
    //region docs/troubleshooting.md

    public function testTroubleshootingQuotesNotAllowedInTemplate(): void
    {
        $message = null;

        try {
            DB::select('users', "name = 'John'");
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            "Quotes not allowed in template. Replace 'John' with :paramName and add: [ ':paramName' => 'John' ]",
            $message
        );

        DB::select('users', "name = ?", 'John Doe');
        $this->assertSame("SELECT * FROM `test_users` WHERE name = 'John Doe'", DB::$mysqli->lastQuery);
    }

    public function testTroubleshootingStandaloneNumberInTemplate(): void
    {
        $message = null;

        try {
            DB::select('users', "age > 21");
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            "Standalone number in template. Replace 21 with :n21 and add: [ ':n21' => 21 ]",
            $message
        );

        $olderThan21 = DB::select('users', "age > ?", 21);
        $this->assertSame('SELECT * FROM `test_users` WHERE age > 21', DB::$mysqli->lastQuery);
        $this->assertCount(20, $olderThan21);

        $limited = DB::select('users', "age > ? LIMIT 3", 21);
        $this->assertSame('SELECT * FROM `test_users` WHERE age > 21 LIMIT 3', DB::$mysqli->lastQuery);
        $this->assertCount(3, $limited);
    }

    public function testTroubleshootingMaxThreePositionalArguments(): void
    {
        $message = null;

        try {
            DB::select('users', "num = ? AND name = ? AND city = ? AND age = ?", 1, 'John Doe', 'Vancouver', 38);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            "Max 3 positional arguments allowed. For more, use named placeholders: [':name' => \$value]",
            $message
        );

        $rows = DB::select('users', "num = :num AND name = :name AND city = :city AND age = :age", [
            ':num'  => 1,
            ':name' => 'John Doe',
            ':city' => 'Vancouver',
            ':age'  => 38,
        ]);
        $this->assertCount(1, $rows);
    }

    public function testTroubleshootingCannotMixPlaceholderStyles(): void
    {
        $message = null;

        try {
            DB::select('users', "status = ? AND city = :city", ['Active', ':city' => 'Vancouver']);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            "Can't mix positional (?) and named (:param) placeholders. Use one style consistently.",
            $message
        );

        $rows = DB::select('users', "status = :status AND city = :city", [
            ':status' => 'Active',
            ':city'   => 'Vancouver',
        ]);
        $this->assertSame(['John Doe'], $rows->pluck('name')->toArray());
    }

    public function testTroubleshootingMissingPositionalValue(): void
    {
        $message = null;

        try {
            DB::select('users', "name = ? AND city = ?", 'Alice Smith');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('Missing value for ? parameter at position 2', $message);

        $rows = DB::select('users', "name = ? AND city = ?", 'Alice Smith', 'Montreal');
        $this->assertCount(1, $rows);
    }

    public function testTroubleshootingMissingNamedValue(): void
    {
        $message = null;

        try {
            DB::select('users', "name = :name AND city = :city", [':name' => 'Alice Smith']);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame("Missing value for ':city' parameter", $message);

        $rows = DB::select('users', "name = :name AND city = :city", [
            ':name' => 'Alice Smith',
            ':city' => 'Montreal',
        ]);
        $this->assertCount(1, $rows);
    }

    public function testTroubleshootingArraysNotAllowedWithPositionalPlaceholders(): void
    {
        $message = null;

        try {
            DB::select('users', "num IN (?)", [[1, 2, 3]]);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            "Arrays not allowed with positional ? placeholders (ambiguous). Use named placeholder instead: ':paramName' => [...]",
            $message
        );

        $rows = DB::select('users', "num IN (:ids)", [':ids' => [1, 2, 3]]);
        $this->assertSame('SELECT * FROM `test_users` WHERE num IN (1,2,3)', DB::$mysqli->lastQuery);
        $this->assertCount(3, $rows);
    }

    public function testTroubleshootingNoLimitOrOffsetOnSingleRowMethods(): void
    {
        $message = null;

        try {
            DB::selectOne('users', "status = ? LIMIT 5", 'Active');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame("This method doesn't support LIMIT or OFFSET", $message);

        $rows = DB::select('users', "status = ? LIMIT 5", 'Active');
        $this->assertSame("SELECT * FROM `test_users` WHERE status = 'Active' LIMIT 5", DB::$mysqli->lastQuery);
        $this->assertCount(5, $rows);

        $row = DB::query("SELECT * FROM ::users WHERE status = ? ORDER BY num DESC LIMIT 5", 'Active')->first();
        $this->assertSame('Quentin Adams', $row->name->value());
    }

    public function testTroubleshootingQueryOneRejectsTrailingCommentsAndSemicolons(): void
    {
        $commentMessage = null;
        try {
            DB::queryOne("SELECT * FROM ::users -- comment");
        } catch (InvalidArgumentException $e) {
            $commentMessage = $e->getMessage();
        }
        $this->assertSame(
            "This method appends LIMIT 1 automatically; a trailing '--' or '#' comment would swallow it and cause a full-table scan. Remove the comment or use query(...)->first() instead.",
            $commentMessage
        );

        $semicolonMessage = null;
        try {
            DB::queryOne("SELECT * FROM ::users;");
        } catch (InvalidArgumentException $e) {
            $semicolonMessage = $e->getMessage();
        }
        $this->assertSame(
            "This method appends LIMIT 1 automatically; a trailing ';' would produce '; LIMIT 1' and fail parsing. Remove the semicolon or use query(...)->first() instead.",
            $semicolonMessage
        );
    }

    public function testTroubleshootingUpdateAndDeleteRequireWhere(): void
    {
        $updateMessage = null;
        try {
            DB::update('users', ['status' => 'Inactive'], []);
        } catch (InvalidArgumentException $e) {
            $updateMessage = $e->getMessage();
        }
        $this->assertSame('UPDATE requires a WHERE condition to prevent accidental bulk UPDATE', $updateMessage);

        $deleteMessage = null;
        try {
            DB::delete('users', []);
        } catch (InvalidArgumentException $e) {
            $deleteMessage = $e->getMessage();
        }
        $this->assertSame('DELETE requires a WHERE condition to prevent accidental bulk DELETE', $deleteMessage);

        DB::update('users', ['status' => 'Inactive'], ['num' => 1]);
        $this->assertSame("UPDATE `test_users` SET `status` = 'Inactive' WHERE `num` = 1", DB::$mysqli->lastQuery);

        DB::update('users', ['status' => 'Inactive'], "TRUE");
        $this->assertSame("UPDATE `test_users` SET `status` = 'Inactive' WHERE TRUE", DB::$mysqli->lastQuery);
        $this->assertSame(20, DB::count('users', ['status' => 'Inactive']));
    }

    public function testTroubleshootingSuspiciousSetClause(): void
    {
        $message = null;

        try {
            DB::update('users', ['num' => 5], ['status' => 'Active']);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            "Suspicious SET clause: only updating 'num'. Did you reverse the arguments? Signature is: update(\$table, \$values, \$whereEtc)",
            $message
        );

        DB::update('users', ['status' => 'Active'], ['num' => 5]);
        $this->assertSame("UPDATE `test_users` SET `status` = 'Active' WHERE `num` = 5", DB::$mysqli->lastQuery);
    }

    public function testTroubleshootingPositionalValuesInArrayAreDeprecated(): void
    {
        $deprecations = [];
        set_error_handler(function (int $errno, string $errstr) use (&$deprecations): bool {
            $deprecations[] = $errstr;
            return $errno === E_USER_DEPRECATED;
        }, E_USER_DEPRECATED);

        try {
            $rows = DB::select('users', "name = ? AND city = ?", ['John Doe', 'Vancouver']);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $rows);
        $this->assertCount(1, $deprecations);
        $this->assertStringStartsWith(
            "Positional values in an array are deprecated. Pass up to 3 values directly for ? placeholders, or use named placeholders: [':name' => \$value]",
            $deprecations[0]
        );

        $direct = DB::select('users', "name = ? AND city = ?", 'John Doe', 'Vancouver');
        $this->assertCount(1, $direct);
    }

    public function testTroubleshootingUnusedPositionalValuesAreDeprecated(): void
    {
        $deprecations = [];
        set_error_handler(function (int $errno, string $errstr) use (&$deprecations): bool {
            $deprecations[] = $errstr;
            return $errno === E_USER_DEPRECATED;
        }, E_USER_DEPRECATED);

        try {
            $rows = DB::select('users', "num IN (?)", 1, 2, 3);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('SELECT * FROM `test_users` WHERE num IN (1)', DB::$mysqli->lastQuery);
        $this->assertCount(1, $rows);
        $this->assertCount(1, $deprecations);
        $this->assertStringStartsWith(
            "Query has 1 positional (?) placeholder(s) but 3 values were passed. Unused positional values are deprecated and will throw in a future version. For IN() lists use a named placeholder: ':ids' => [...]",
            $deprecations[0]
        );

        $named = DB::select('users', "num IN (:ids)", [':ids' => [1, 2, 3]]);
        $this->assertSame('SELECT * FROM `test_users` WHERE num IN (1,2,3)', DB::$mysqli->lastQuery);
        $this->assertCount(3, $named);
    }

    public function testTroubleshootingVersionRequired(): void
    {
        $message = null;

        try {
            new Connection(array_merge(self::$configDefaults, ['versionRequired' => '99.0.0']));
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringStartsWith('This program requires MySQL v99.0.0+ or compatible. This server has ', $message);
        $this->assertStringContainsString(' installed.', $message);
    }

    public function testTroubleshootingNullComparisons(): void
    {
        $isNull = DB::select('users', ['isAdmin' => null]);
        $this->assertSame('SELECT * FROM `test_users` WHERE `isAdmin` IS NULL', DB::$mysqli->lastQuery);
        $this->assertCount(4, $isNull);

        $equalsNull = DB::select('users', "isAdmin = ?", null);
        $this->assertSame('SELECT * FROM `test_users` WHERE isAdmin = NULL', DB::$mysqli->lastQuery);
        $this->assertCount(0, $equalsNull);
    }

    public function testTroubleshootingNullAndEmptyArraysInInLists(): void
    {
        $withNull = DB::select('users', "num IN (:ids)", [':ids' => [1, null, 3]]);
        $this->assertSame('SELECT * FROM `test_users` WHERE num IN (1,3)', DB::$mysqli->lastQuery);
        $this->assertCount(2, $withNull);

        $wantedIds = [];
        $wanted    = DB::select('users', "num IN (:ids)", [':ids' => $wantedIds]);
        $this->assertSame('SELECT * FROM `test_users` WHERE num IN (NULL)', DB::$mysqli->lastQuery);
        $this->assertCount(0, $wanted);

        $excludeIds = [];
        $trap       = DB::select('users', "num NOT IN (:ids)", [':ids' => $excludeIds]);
        $this->assertSame('SELECT * FROM `test_users` WHERE num NOT IN (NULL)', DB::$mysqli->lastQuery);
        $this->assertCount(0, $trap);

        $fixed = DB::select('users', "num NOT IN (:ids)", [':ids' => $excludeIds ?: [-1]]);
        $this->assertSame('SELECT * FROM `test_users` WHERE num NOT IN (-1)', DB::$mysqli->lastQuery);
        $this->assertCount(20, $fixed);
    }

    public function testTroubleshootingBooleansConvertToTrueAndFalse(): void
    {
        DB::$mysqli->query("DROP TEMPORARY TABLE IF EXISTS test_flags");
        DB::$mysqli->query("CREATE TEMPORARY TABLE test_flags (id INT AUTO_INCREMENT PRIMARY KEY, isAdmin TINYINT(1))");

        $id = DB::insert('flags', ['isAdmin' => true]);
        $this->assertSame('INSERT INTO `test_flags` SET `isAdmin` = TRUE', DB::$mysqli->lastQuery);
        $this->assertSame(1, DB::selectOne('flags', ['id' => $id])->isAdmin->value());

        $id = DB::insert('flags', ['isAdmin' => false]);
        $this->assertSame('INSERT INTO `test_flags` SET `isAdmin` = FALSE', DB::$mysqli->lastQuery);
        $this->assertSame(0, DB::selectOne('flags', ['id' => $id])->isAdmin->value());
    }

    public function testTroubleshootingSeeingTheSqlThatJustRan(): void
    {
        DB::select('users', ['status' => 'Active', 'city' => 'Vancouver']);

        $this->assertSame(
            "SELECT * FROM `test_users` WHERE `status` = 'Active' AND `city` = 'Vancouver'",
            DB::$mysqli->lastQuery
        );
    }

    public function testTroubleshootingInspectingResultsWithPrintR(): void
    {
        $users  = DB::select('users', "num = ? LIMIT 1", 1);
        $output = print_r($users, true);

        $this->assertStringContainsString('[num] => 1', $output);
        $this->assertStringContainsString('[name] => John Doe', $output);
        $this->assertStringContainsString('[city] => Vancouver', $output);
    }

    //endregion
}
