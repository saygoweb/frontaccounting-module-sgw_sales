<?php

namespace SGW_Sales\Tests\Integration;

use Anorm\Anorm;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\FaSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SGW_Sales\db\DB;
use SGW_Sales\db\SalesRecurringModel;
use SGW_Sales\Tests\Support\FaRows;

/**
 * Tests that load FrontAccounting in-process, by the GraphQL module's Bootstrap
 * (sgw_sales is deployed beside it), into company 0 of the demo dataset, with
 * Anorm connected and DB initialised as hooks_sgw_sales does for a signed-in
 * user. Every row a test writes is removed in tearDown. Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 *
 * because FrontAccounting defines its functions, constants and globals once.
 */
abstract class FaTestCase extends TestCase
{
    private ?\PDO $pdo = null;

    private ?FaRows $rows = null;

    protected function setUp(): void
    {
        if (!class_exists(Bootstrap::class)) {
            $this->markTestSkipped('No GraphQL module beside this one: run in the FrontAccounting CI image.');
        }
        $root = Bootstrap::defaultRoot();
        if (!is_file($root . '/config_db.php')) {
            $this->markTestSkipped('No FrontAccounting install at ' . $root . ': run in the FA CI image.');
        }
        (new FaSession(Config::fromArray(['secret' => str_repeat('x', 32)])))->openCompany(0);
        // The user FrontAccounting's audit trail records.
        $_SESSION['wa_current_user']->user = 1;
        $_SESSION['wa_current_user']->loginname = 'admin';
        // Its point of sale: a cash sale takes its location from it.
        $_SESSION['wa_current_user']->pos = 1;
        // Every area, as the CI image's test user has: FrontAccounting checks
        // access as it writes (a sales cart's dates, say).
        $_SESSION['wa_current_user']->role_set = array_map(function (array $area) {
            return $area[0];
        }, array_values($GLOBALS['security_areas']));
        // What sgw_sales' pages include: ui.inc brings the sales db layer in
        // through includes/ui/ui_lists.inc.
        foreach (['sales/includes/cart_class.inc', 'includes/ui.inc', 'includes/ui/db_pager_view.inc'] as $file) {
            Bootstrap::includeFa($file);
        }

        $c = $GLOBALS['db_connections'][0];
        Anorm::connect(Anorm::DEFAULT, "mysql:host={$c['host']};dbname={$c['dbname']}", $c['dbuser'], $c['dbpassword']);
        DB::init($c['tbpref']);

        $this->rows = FaRows::mark($this->pdo(), $c['tbpref']);
    }

    protected function tearDown(): void
    {
        if ($this->rows !== null) {
            $this->rows->purge();
        }
        $_POST = [];
        $_GET = [];
    }

    /**
     * A view for a controller: the page's view class lives in the page, which
     * renders as it is included, so a mock with just the methods the controller
     * calls.
     *
     * @param string[] $methods
     * @return MockObject&\stdClass
     */
    protected function view(array $methods): MockObject
    {
        return $this->getMockBuilder(\stdClass::class)->addMethods($methods)->getMock();
    }

    protected function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $c = $GLOBALS['db_connections'][0];
            $this->pdo = new \PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['dbuser'], $c['dbpassword']);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    /**
     * A demo sales order with no recurrence, holding no stock (service lines
     * only) where there is one: generating delivers it again, and the service
     * refuses a delivery the stock cannot cover.
     */
    protected function unrecurredOrder(): int
    {
        $orderNo = $this->pdo()->query(
            'SELECT so.order_no FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' WHERE so.trans_type=' . ST_SALESORDER
            . ' AND so.order_no NOT IN (SELECT trans_no FROM ' . DB::prefix('sales_recurring') . ')'
            . ' ORDER BY EXISTS (SELECT 1 FROM ' . DB::prefix('sales_order_details') . ' AS line'
            . ' JOIN ' . DB::prefix('stock_master') . ' AS item ON item.stock_id=line.stk_code'
            . " WHERE line.order_no=so.order_no AND line.trans_type=so.trans_type AND item.mb_flag IN ('B', 'M')),"
            . ' so.order_no DESC LIMIT 1'
        )->fetchColumn();
        if (!$orderNo) {
            $this->fail('the demo dataset has no sales order to hang a recurrence on');
        }

        return (int) $orderNo;
    }

    /**
     * A monthly recurrence on $orderNo on the 1st, started in 2016, next due on
     * $dtNext (null: never generated, so due now).
     */
    protected function monthly(int $orderNo, ?string $dtNext = null, ?string $dtEnd = null): SalesRecurringModel
    {
        $recurrence = new SalesRecurringModel();
        $recurrence->transNo = $orderNo;
        $recurrence->dtStart = '2016-07-01';
        $recurrence->dtEnd = $dtEnd;
        $recurrence->dtNext = $dtNext;
        $recurrence->auto = 0;
        $recurrence->repeats = SalesRecurringModel::REPEAT_MONTHLY;
        $recurrence->every = 1;
        $recurrence->occur = '1';
        $recurrence->write();

        return $recurrence;
    }

    /**
     * @return array<string, mixed>|false the order's recurrence row, as stored
     */
    protected function recurrenceRow(int $orderNo)
    {
        $statement = $this->pdo()->prepare('SELECT * FROM ' . DB::prefix('sales_recurring') . ' WHERE trans_no=?');
        $statement->execute([$orderNo]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    protected function documentCount(int $type, int $orderNo): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB::prefix('debtor_trans') . ' WHERE type=? AND order_=?'
        );
        $statement->execute([$type, $orderNo]);

        return (int) $statement->fetchColumn();
    }

    protected static function today(): string
    {
        return (new \DateTime('today'))->format('Y-m-d');
    }
}
