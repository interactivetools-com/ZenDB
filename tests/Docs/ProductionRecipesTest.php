<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection SqlResolve */
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Docs;

use Itools\SmartArray\SmartNull;
use Itools\SmartString\SmartString;
use Itools\ZenDB\DB;
use Itools\ZenDB\Tests\BaseTestCase;
use RuntimeException;

/**
 * The chains a real template or plugin runs: list pages, detail pages, search,
 * paging, form saves, joins, dashboard totals, transactions - each pinned end
 * to end with its exact output. If these chains still produce the same output
 * after a refactor, real sites survive it.
 *
 * Every test starts from the fixture data in resetTestTables(), so the
 * pinned ids and totals hold no matter what order the tests run in.
 */
class ProductionRecipesTest extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        DB::disconnect();
        DB::connect(self::$configDefaults);
        self::resetTestTables();
    }

    protected function setUp(): void
    {
        self::resetTestTables();
    }

    //region Template Output

    /**
     * The listing loop every site has. User 8's name contains real HTML, and
     * it comes out encoded without the template asking for it.
     */
    public function testListPageEncodesEachRow(): void
    {
        $html = '';
        foreach (DB::select('users', "status = ? ORDER BY name LIMIT ?", 'Suspended', 3) as $user) {
            $html .= "<li>$user->name ($user->city)</li>\n";
        }

        $this->assertSame(
            "<li>Bob Johnson (Calgary)</li>\n"
            . "<li>Frank &lt;b&gt;Miller&lt;/b&gt; (Winnipeg)</li>\n"
            . "<li>Jill Taylor (Hamilton)</li>\n",
            $html
        );
    }

    public function testDetailPageInterpolatesRecordFields(): void
    {
        $user = DB::selectOne('users', ['num' => 8]);

        $html  = "<h1>$user->name</h1>\n";
        $html .= "<p>{$user->city} - born {$user->dob->dateFormat('M j, Y')}, age $user->age</p>";

        $this->assertSame(
            "<h1>Frank &lt;b&gt;Miller&lt;/b&gt;</h1>\n"
            . "<p>Winnipeg - born Jul 22, 1992, age 31</p>",
            $html
        );
    }

    /**
     * No match returns an empty result object, never null, so a template can
     * keep chaining. Columns read as blank and isEmpty() reports the miss.
     */
    public function testMissingRecordRendersBlankAndReportsEmpty(): void
    {
        $user = DB::selectOne('users', ['num' => 9999]);

        $this->assertTrue($user->isEmpty());
        $this->assertSame(0, count($user));
        $this->assertSame([], $user->toArray());
        $this->assertSame('<h1></h1>', "<h1>$user->name</h1>");

        // what the template actually writes
        $html = $user->isEmpty() ? '<p>Record not found</p>' : "<h1>$user->name</h1>";
        $this->assertSame('<p>Record not found</p>', $html);
    }

    public function testTableRowsAlternateWithLoopPosition(): void
    {
        $html = '';
        foreach (DB::select('users', "status = ? ORDER BY num LIMIT ?", 'Inactive', 3) as $user) {
            $class = $user->position() % 2 === 1 ? 'odd' : 'even';
            if ($user->isLast()) {
                $class .= ' last';
            }
            $html .= "<tr class=\"$class\"><td>$user->name</td></tr>\n";
        }

        $this->assertSame(
            "<tr class=\"odd\"><td>Jane Janey Doe</td></tr>\n"
            . "<tr class=\"even\"><td>Dave Williams</td></tr>\n"
            . "<tr class=\"odd last\"><td>Helen Clark</td></tr>\n",
            $html
        );
    }

    public function testSelectDropdownMarksTheSavedValue(): void
    {
        $savedId = 3;

        $html = '';
        foreach (DB::select('products', "ORDER BY product_name") as $product) {
            $selected = $product->product_id->int() === $savedId ? ' selected' : '';
            $html    .= "<option value=\"$product->product_id\"$selected>$product->product_name</option>\n";
        }

        $this->assertSame(
            "<option value=\"1\">Product A</option>\n"
            . "<option value=\"2\">Product B</option>\n"
            . "<option value=\"3\" selected>Product C</option>\n"
            . "<option value=\"4\">Product D</option>\n"
            . "<option value=\"5\">Product E</option>\n",
            $html
        );
    }

    /**
     * Stored markup: encoded by default, raw only where the template asks for
     * it, and textOnly() for a preview that keeps encoding what it strips down to.
     */
    public function testStoredHtmlIsEncodedUnlessAskedForRaw(): void
    {
        $row = DB::selectOne('special_chars', ['id' => 1]);

        $this->assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', "$row->html_content");
        $this->assertSame('<script>alert("xss")</script>', $row->html_content->rawHtml());
        $this->assertSame('alert(&quot;xss&quot;)', (string)$row->html_content->textOnly());
    }

    public function testValuesForUrlsAndJavaScript(): void
    {
        $user = DB::selectOne('users', ['num' => 8]);

        $this->assertSame(
            '<a href="/users?name=Frank+%3Cb%3EMiller%3C%2Fb%3E">Frank &lt;b&gt;Miller&lt;/b&gt;</a>',
            "<a href=\"/users?name={$user->name->urlEncode()}\">$user->name</a>"
        );

        // jsonEncode() adds the quotes and escapes < and > so the value cannot close the script tag
        $this->assertSame(
            '<script>let userName = "Frank \u003Cb\u003EMiller\u003C/b\u003E";</script>',
            "<script>let userName = {$user->name->jsonEncode()};</script>"
        );
    }

    /**
     * Admin and debug pages that render whatever columns a query returns:
     * headers from the row's keys, cells from its values, no column named.
     */
    public function testGenericTableFromAnyQuery(): void
    {
        $row = DB::selectOne('products', ['product_id' => 1]);

        $this->assertSame('<th>product_id</th><th>product_name</th><th>price</th>', $row->keys()->sprintf('<th>{value}</th>')->implode(''));
        $this->assertSame('<td>1</td><td>Product A</td><td>10.99</td>', $row->sprintf('<td>{value}</td>')->implode(''));
    }

    //endregion
    //region Search and Paging

    public function testSearchByNameFragment(): void
    {
        $searchTerm = 'do'; // as typed into the search box

        $matches = DB::select('users', "name LIKE ? ORDER BY num", DB::likeContains($searchTerm));

        $this->assertSame(2, count($matches));
        $this->assertSame(['John Doe', 'Jane Janey Doe'], $matches->pluck('name')->toArray());
    }

    /**
     * A search for "100%" finds the text "100%", not every row: likeContains()
     * escapes the LIKE wildcards % and _ before they reach MySQL.
     */
    public function testSearchTermWildcardsMatchLiterally(): void
    {
        $percentMatches = DB::select('special_chars', "content LIKE ? ORDER BY id", DB::likeContains('100%'));
        $this->assertSame(['100% discount'], $percentMatches->pluck('content')->toArray());

        // no fixture row contains a literal underscore, so an escaped _ matches nothing
        $this->assertSame(0, DB::count('special_chars', "content LIKE ?", DB::likeContains('_')));

        // the same pattern unescaped is a single-character wildcard and matches every row
        $this->assertSame(3, DB::count('special_chars', "content LIKE ?", DB::rawSql("'%_%'")));
    }

    public function testEmptySearchTermMatchesEveryRow(): void
    {
        $this->assertSame(20, DB::count('users', "name LIKE ?", DB::likeContains('')));
    }

    /**
     * The admin listing: count for the page links, then the page itself.
     */
    public function testPagedListingSecondPage(): void
    {
        $page    = 2;
        $perPage = 5;

        $total      = DB::count('users', ['status' => 'Active']);
        $totalPages = (int)ceil($total / $perPage);

        $users = DB::select('users', "status = :status ORDER BY num :paging", [
            ':status' => 'Active',
            ':paging' => DB::pagingSql($page, $perPage),
        ]);

        $this->assertSame(10, $total);
        $this->assertSame(2, $totalPages);
        $this->assertSame(['Ivan Scott', 'Kevin Lewis', 'Mike Nelson', 'Oliver Young', 'Quentin Adams'], $users->pluck('name')->toArray());
        $this->assertSame([11, 13, 15, 17, 19], $users->pluck('num')->toArray());
    }

    public function testPageBeyondTheLastIsEmpty(): void
    {
        $users = DB::select('users', "ORDER BY num :paging", [':paging' => DB::pagingSql(9, 5)]);

        $this->assertSame(0, count($users));
        $this->assertTrue($users->isEmpty());
    }

    //endregion
    //region Data Lifecycle

    /**
     * The whole form: save a new record, edit it, reload it, delete it.
     */
    public function testFormSaveInsertUpdateReloadDelete(): void
    {
        $newId = DB::insert('users', [
            'name'    => "Sam O'Neil",
            'isAdmin' => 0,
            'status'  => 'Active',
            'city'    => 'Nanaimo',
            'dob'     => '1990-01-15',
            'age'     => 36,
        ]);
        $this->assertSame(21, $newId); // fixtures end at 20

        $affected = DB::update('users', ['city' => 'Victoria', 'status' => 'Inactive'], ['num' => $newId]);
        $this->assertSame(1, $affected);

        $saved = DB::selectOne('users', ['num' => $newId]);
        $this->assertSame([
            'num'     => 21,
            'name'    => "Sam O'Neil",
            'isAdmin' => 0,
            'status'  => 'Inactive',
            'city'    => 'Victoria',
            'dob'     => '1990-01-15',
            'age'     => 36,
        ], $saved->toArray());

        $this->assertSame(1, DB::delete('users', ['num' => $newId]));
        $this->assertSame(0, DB::count('users', ['num' => $newId]));
        $this->assertSame(20, DB::count('users'));
    }

    /**
     * Resaving a form with nothing changed returns 0, not 1. Code that treats
     * the return value as "did the save work" reports a false failure.
     */
    public function testUpdateReportsZeroWhenNothingChanged(): void
    {
        $this->assertSame(0, DB::update('users', ['city' => 'Vancouver'], ['num' => 1])); // already Vancouver
        $this->assertSame(1, DB::update('users', ['city' => 'Burnaby'], ['num' => 1]));
    }

    public function testBulkUpdateReportsEveryRowChanged(): void
    {
        $this->assertSame(5, DB::update('users', ['status' => 'Inactive'], "status = ?", 'Suspended'));
        $this->assertSame(0, DB::count('users', ['status' => 'Suspended']));
        $this->assertSame(10, DB::count('users', ['status' => 'Inactive']));
    }

    /**
     * Checkbox fields: a PHP bool goes in, a tinyint 1/0 comes back, and an
     * unset flag stays null so the template can tell it apart from "No".
     */
    public function testCheckboxValuesRoundTripAsTinyint(): void
    {
        $newId = DB::insert('users', [
            'name'    => 'Checkbox Bob',
            'isAdmin' => true,
            'status'  => 'Active',
            'city'    => 'Surrey',
            'dob'     => '1990-01-01',
            'age'     => 36,
        ]);

        $saved = DB::selectOne('users', ['num' => $newId]);
        $this->assertSame(1, $saved->isAdmin->value());
        $this->assertSame(1, $saved->isAdmin->int());
        $this->assertSame('Yes', $saved->isAdmin->int() === 1 ? 'Yes' : 'No');

        // user 2 never had the flag set
        $noFlag = DB::selectOne('users', ['num' => 2]);
        $this->assertNull($noFlag->isAdmin->value());
        $this->assertSame('not set', (string)$noFlag->isAdmin->or('not set'));

        // null and 0 are different rows: the array WHERE form writes IS NULL for null
        $this->assertSame(['Jane Janey Doe', 'Erin Davis', 'Linda Harris', 'Quentin Adams'], DB::select('users', ['isAdmin' => null])->pluck('name')->toArray());
        $this->assertSame(8, DB::count('users', ['isAdmin' => 0]));
    }

    public function testApostropheSurvivesTheRoundTrip(): void
    {
        $newId = DB::insert('special_chars', ['content' => "Tim O'Reilly said \"hi\"", 'html_content' => '']);
        $saved = DB::selectOne('special_chars', ['id' => $newId]);

        $this->assertSame("Tim O'Reilly said \"hi\"", $saved->content->value());       // stored unchanged
        $this->assertSame('Tim O&apos;Reilly said &quot;hi&quot;', "$saved->content"); // encoded on output

        // and the fixture row written the same way reads back the same way
        $this->assertSame("O'Reilly", DB::selectOne('special_chars', ['id' => 1])->content->value());
    }

    /**
     * Counters and totals that read the current value in SQL: rawSql() is
     * inserted verbatim, so the column name is not treated as a value.
     */
    public function testCounterIncrementWithRawSql(): void
    {
        $affected = DB::update('orders', ['total_amount' => DB::rawSql('total_amount + 10')], ['order_id' => 1]);

        $this->assertSame(1, $affected);
        $this->assertSame('90.00', DB::selectOne('orders', ['order_id' => 1])->total_amount->value());
    }

    //endregion
    //region Joins and Master Detail

    /**
     * Order plus customer in one query. Smart joins add table.column keys, so
     * a column that exists in both tables is never ambiguous.
     */
    public function testOrderWithCustomerFromSmartJoinKeys(): void
    {
        $order = DB::queryOne("SELECT * FROM ::orders o JOIN ::users u ON u.num = o.user_id WHERE o.order_id = ?", 3);

        $this->assertSame(3, $order->get('orders.order_id')->int());
        $this->assertSame(8, $order->get('users.num')->int());

        $line = sprintf(
            '%s spent %s on %s',
            $order->get('users.name'),
            $order->get('orders.total_amount')->numberFormat(2)->andPrefix('$'),
            $order->get('orders.order_date')->dateFormat('M j, Y')
        );
        $this->assertSame('Frank &lt;b&gt;Miller&lt;/b&gt; spent $45.75 on Aug 15, 2023', $line);
    }

    public function testRecentOrdersTable(): void
    {
        $orders = DB::query("SELECT o.order_id, o.order_date, o.total_amount, u.name FROM ::orders o JOIN ::users u ON u.num = o.user_id ORDER BY o.order_date DESC LIMIT ?", 3);

        $html = '';
        foreach ($orders as $order) {
            $html .= "<tr><td>$order->order_id</td><td>$order->name</td>"
                . "<td>{$order->order_date->dateFormat('M j, Y')}</td>"
                . "<td>{$order->total_amount->numberFormat(2)->andPrefix('$')}</td></tr>\n";
        }

        $this->assertSame(
            "<tr><td>10</td><td>Mike Nelson</td><td>Mar 8, 2024</td><td>$95.75</td></tr>\n"
            . "<tr><td>9</td><td>Linda Harris</td><td>Feb 12, 2024</td><td>$30.25</td></tr>\n"
            . "<tr><td>8</td><td>Kevin Lewis</td><td>Jan 22, 2024</td><td>$70.50</td></tr>\n",
            $html
        );
    }

    public function testCustomersWithNoOrders(): void
    {
        $users = DB::query("SELECT u.name FROM ::users u LEFT JOIN ::orders o ON o.user_id = u.num WHERE o.order_id IS NULL ORDER BY u.num LIMIT ?", 5);

        $this->assertSame(['John Doe', 'Jane Janey Doe', 'Alice Smith', 'Bob Johnson', 'Charlie Brown'], $users->pluck('name')->toArray());
    }

    /**
     * Org chart from one table joined to itself. The manager column is NULL
     * for the top of the tree, and or() supplies the display text.
     */
    public function testOrgChartSelfJoin(): void
    {
        $employees = DB::query("SELECT e.name, m.name AS manager FROM ::employees e LEFT JOIN ::employees m ON m.id = e.manager_id ORDER BY e.id");

        $lines = [];
        foreach ($employees as $employee) {
            $lines[] = "$employee->name reports to {$employee->manager->or('nobody')}";
        }

        $this->assertSame([
            'CEO reports to nobody',
            'VP Engineering reports to CEO',
            'VP Sales reports to CEO',
            'Developer 1 reports to VP Engineering',
            'Developer 2 reports to VP Engineering',
            'Sales Rep 1 reports to VP Sales',
        ], $lines);
    }

    //endregion
    //region Dashboards and Aggregates

    public function testDashboardCounts(): void
    {
        $this->assertSame(20, DB::count('users'));
        $this->assertSame(10, DB::count('users', ['status' => 'Active']));
        $this->assertSame(5, DB::count('users', ['status' => 'Suspended']));
        $this->assertSame(15, DB::count('users', ['status' => ['Active', 'Inactive']])); // IN list
        $this->assertSame(8, DB::count('users', ['isAdmin' => 1]));
        $this->assertSame(14, DB::count('users', "name LIKE ?", DB::likeContains('a')));

        // share of total for a progress bar
        $activeShare = SmartString::new(DB::count('users', ['status' => 'Active']))->percentOf(DB::count('users'));
        $this->assertSame('width: 50%', "width: $activeShare");
    }

    public function testOrderTotalsSummary(): void
    {
        $summary = DB::queryOne("SELECT COUNT(*) AS orders, SUM(total_amount) AS revenue, AVG(total_amount) AS average, MAX(total_amount) AS biggest FROM ::orders");

        $this->assertSame(10, $summary->orders->value());
        $this->assertSame('878.00', $summary->revenue->value());
        $this->assertSame('$878.00', (string)$summary->revenue->numberFormat(2)->andPrefix('$'));
        $this->assertSame('87.80', (string)$summary->average->numberFormat(2));
        $this->assertSame('175.25', (string)$summary->biggest->numberFormat(2));
    }

    public function testTopCustomersByRevenue(): void
    {
        $customers = DB::query(
            "SELECT u.name AS name, SUM(o.total_amount) AS spent
               FROM ::users u
               JOIN ::orders o ON o.user_id = u.num
              GROUP BY u.num, u.name
              ORDER BY spent DESC
              LIMIT ?",
            3
        );

        $lines = [];
        foreach ($customers as $customer) {
            $lines[] = "$customer->name: {$customer->spent->numberFormat(2)->andPrefix('$')}";
        }

        $this->assertSame([
            'George Wilson: $175.25',
            'Erin Davis: $120.50',
            'Jill Taylor: $110.00',
        ], $lines);
    }

    public function testProductSalesReport(): void
    {
        $report = DB::query(
            "SELECT p.product_name AS product, SUM(d.quantity) AS unitsSold, SUM(d.quantity * p.price) AS revenue
               FROM ::order_details d
               JOIN ::products p ON p.product_id = d.product_id
              GROUP BY p.product_id, p.product_name
              ORDER BY revenue DESC"
        );

        $lines = [];
        foreach ($report as $product) {
            $lines[] = "$product->product: $product->unitsSold units, {$product->revenue->numberFormat(2)->andPrefix('$')}";
        }

        $this->assertSame([
            'Product C: 14 units, $360.50',
            'Product E: 16 units, $255.84',
            'Product A: 14 units, $153.86',
            'Product D: 13 units, $107.25',
            'Product B: 14 units, $77.00',
        ], $lines);
    }

    /**
     * One query for the lookup table instead of one query per row.
     */
    public function testLookupMapAvoidsRepeatQueries(): void
    {
        $namesByNum = DB::select('users')->pluck('name', 'num');
        $this->assertSame(20, count($namesByNum));
        $this->assertSame('Dave Williams', $namesByNum->get(6)->value());

        $lines = [];
        foreach (DB::select('orders', "order_id <= ? ORDER BY order_id", 3) as $order) {
            $lines[] = "Order $order->order_id: {$namesByNum->get($order->user_id->int())->or('unknown customer')}";
        }
        $this->assertSame([
            'Order 1: Dave Williams',
            'Order 2: Erin Davis',
            'Order 3: Frank &lt;b&gt;Miller&lt;/b&gt;', // looked-up values encode on output too
        ], $lines);

        // an id with no matching row falls back instead of erroring
        $this->assertSame('unknown customer', (string)$namesByNum->get(9999)->or('unknown customer'));

        // whole rows instead of one column
        $usersByNum = DB::select('users', "num <= ? ORDER BY num", 3)->indexBy('num');
        $this->assertSame('Montreal', (string)$usersByNum->get(3)->city);
    }

    //endregion
    //region Transactions

    public function testTransactionCommitsWhenTheCallbackReturns(): void
    {
        self::createSignupsTable();
        try {
            $newId = DB::transaction(function () {
                $id = DB::insert('recipe_signups', ['email' => 'sam@example.com', 'plan' => 'pro']);
                DB::update('recipe_signups', ['plan' => 'pro-annual'], ['id' => $id]);
                return $id;
            });

            $this->assertSame(1, $newId);
            $this->assertSame(1, DB::count('recipe_signups'));
            $this->assertSame('pro-annual', DB::selectOne('recipe_signups', ['id' => $newId])->plan->value());
        } finally {
            self::dropSignupsTable();
        }
    }

    /**
     * Checkout that fails partway: the exception reaches the caller and the
     * half-written row is gone.
     */
    public function testTransactionRollsBackWhenTheCallbackThrows(): void
    {
        self::createSignupsTable();
        try {
            $message = '';
            try {
                DB::transaction(function () {
                    DB::insert('recipe_signups', ['email' => 'ghost@example.com', 'plan' => 'free']);
                    throw new RuntimeException('payment declined');
                });
                $this->fail('Expected RuntimeException was not thrown');
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
            }

            $this->assertSame('payment declined', $message);
            $this->assertSame(0, DB::count('recipe_signups', ['email' => 'ghost@example.com']));
            $this->assertSame(0, DB::count('recipe_signups'));
        } finally {
            self::dropSignupsTable();
        }
    }

    /**
     * Transactions need InnoDB, so the engine is pinned rather than left to
     * the server's default.
     */
    private static function createSignupsTable(): void
    {
        DB::$mysqli->query("CREATE TABLE IF NOT EXISTS test_recipe_signups (id INT PRIMARY KEY AUTO_INCREMENT, email VARCHAR(255), plan VARCHAR(50)) ENGINE=InnoDB");
        DB::$mysqli->query("TRUNCATE TABLE test_recipe_signups");
    }

    private static function dropSignupsTable(): void
    {
        DB::$mysqli->query("DROP TABLE IF EXISTS test_recipe_signups");
    }

    //endregion
    //region Guards and Empty Results

    /**
     * "No such record" and "record found but the field is empty" are two
     * different messages, and one chain reports both.
     */
    public function testTwoStageOrThrowSeparatesMissingRowFromMissingValue(): void
    {
        // row exists, column is null
        try {
            DB::selectOne('users', ['num' => 2])->orThrow('No user 2')->isAdmin->orThrow('User 2 has no admin flag');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('User 2 has no admin flag', $e->getMessage());
        }

        // no row at all
        try {
            DB::selectOne('users', ['num' => 9999])->orThrow('No user 9999')->isAdmin->orThrow('never reached');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('No user 9999', $e->getMessage());
        }

        // both present: the value comes through
        $this->assertSame(1, DB::selectOne('users', ['num' => 1])->orThrow('No user 1')->isAdmin->orThrow('No admin flag')->int());
    }

    public function testEmptyResultSetHelpers(): void
    {
        $users = DB::select('users', ['status' => 'Active', 'city' => 'Nowhere']);

        $this->assertSame(0, count($users));
        $this->assertTrue($users->isEmpty());
        $this->assertInstanceOf(SmartNull::class, $users->first()); // chaining still works on an empty set
        $this->assertSame('', "{$users->first()->name}");
        $this->assertSame([], $users->pluck('name')->toArray());

        try {
            $users->orThrow('No users matched');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('No users matched', $e->getMessage());
        }
    }

    /**
     * Checkbox lists post an array of ids, and an empty list matches nothing
     * instead of everything.
     */
    public function testEmptyIdListMatchesNothing(): void
    {
        $selected = DB::select('users', "num IN (:ids) ORDER BY num", [':ids' => [3, 8, 15]]);
        $this->assertSame(['Alice Smith', 'Frank <b>Miller</b>', 'Mike Nelson'], $selected->pluck('name')->toArray());

        $nothingChecked = DB::select('users', "num IN (:ids) ORDER BY num", [':ids' => []]);
        $this->assertSame(0, count($nothingChecked));
    }

    //endregion
}
