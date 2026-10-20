<?php

namespace SGW_Sales\Tests\Integration\Controller;

use SGW\common\Pager\ModelPager;
use SGW_Sales\controller\SalesOrderList;
use SGW_Sales\db\DB;
use SGW_Sales\db\SalesOrderListModel;
use SGW_Sales\Tests\Integration\FaTestCase;

/**
 * The Sales Order Inquiry page's controller, its view a mock that keeps what
 * renderView() is given: the view mode's dates switch and the orders pager.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderListTest extends FaTestCase
{
    private const VIEW = [
        'renderView', 'view_link', 'edit_link', 'prt_link', 'dispatch_link',
        'delivery_link', 'invoice_prep_link', 'order_link',
    ];

    /** @var bool|null what the controller passed renderView() */
    private $showDates;

    /** @var ModelPager|null */
    private $table;

    protected function setUp(): void
    {
        parent::setUp();
        // The signed-in user's page length: the session's prefs are not read from the user row here.
        $_SESSION['wa_current_user']->prefs->query_size = 100;
    }

    /**
     * Runs the controller on what is in $_GET and $_POST.
     *
     * @param \PHPUnit\Framework\MockObject\MockObject|null $view
     */
    private function runList($view = null): SalesOrderList
    {
        if ($view === null) {
            $view = $this->view(self::VIEW);
        }
        $view->method('renderView')->willReturnCallback(function ($showDates, $table) {
            $this->showDates = $showDates;
            $this->table = $table;
        });
        $controller = new SalesOrderList($view);
        $controller->run();

        return $controller;
    }

    /**
     * The order numbers the pager lists, its first page.
     *
     * @return int[]
     */
    private function listed(): array
    {
        $this->table->select_records();
        $listed = [];
        foreach ($this->table->generator() as $model) {
            $listed[] = (int) $model->orderNo;
        }

        return $listed;
    }

    /**
     * Order numbers in the database, in order.
     *
     * @param mixed[] $parameters
     * @return int[]
     */
    private function orderNos(string $where, array $parameters = []): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT DISTINCT so.order_no FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' LEFT JOIN ' . DB::prefix('sales_order_details') . ' AS sd ON sd.order_no=so.order_no'
            . ' WHERE ' . $where . ' ORDER BY so.order_no'
        );
        $statement->execute($parameters);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Dates around every demo order, in the user's format.
     */
    private function postAllDates(): void
    {
        $range = $this->pdo()->query(
            'SELECT MIN(ord_date), MAX(ord_date) FROM ' . DB::prefix('sales_orders')
        )->fetch(\PDO::FETCH_NUM);
        $_POST['OrdersAfterDate'] = sql2date($range[0]);
        $_POST['OrdersToDate'] = sql2date($range[1]);
    }

    /**
     * @return string[] the heads of the pager's columns, in order ('' for none)
     */
    private function heads(): array
    {
        return array_map(function (array $column) {
            return isset($column['head']) ? $column['head'] : '';
        }, $this->table->columns);
    }

    /**
     * @return mixed[] the pager's last column's format function
     */
    private function lastFun(int $fromEnd = 1)
    {
        $columns = $this->table->columns;

        return $columns[count($columns) - $fromEnd]['fun'];
    }

    private function orderType(int $orderNo): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT type FROM ' . DB::prefix('sales_orders') . ' WHERE order_no=? AND trans_type=' . ST_SALESORDER
        );
        $statement->execute([$orderNo]);

        return (int) $statement->fetchColumn();
    }

    private function orderOfType(int $type): int
    {
        $orderNo = $this->pdo()->query(
            'SELECT order_no FROM ' . DB::prefix('sales_orders')
            . ' WHERE trans_type=' . ST_SALESORDER . ' AND type=' . $type . ' ORDER BY order_no LIMIT 1'
        )->fetchColumn();
        if (!$orderNo) {
            $this->fail("the demo dataset has no sales order of type $type");
        }

        return (int) $orderNo;
    }

    /**
     * @return \Ajax&\PHPUnit\Framework\MockObject\MockObject
     */
    private function ajax()
    {
        $ajax = $this->getMockBuilder(\Ajax::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['activate', 'addDisable'])
            ->getMock();
        $GLOBALS['Ajax'] = $ajax;

        return $ajax;
    }

    public function testCheckOverdueMarksNoOrder(): void
    {
        $controller = new SalesOrderList($this->view(self::VIEW));

        $this->assertFalse($controller->check_overdue(new SalesOrderListModel()));
    }

    public function testFormatDescriptionKeepsWhatFollowsTheFirstDash(): void
    {
        $controller = new SalesOrderList($this->view(self::VIEW));

        $this->assertSame('example.com', $controller->formatDescription(null, 'Hosting - example.com'));
        $this->assertSame('my-site.org', $controller->formatDescription(null, 'Domain-my-site.org'));
        $this->assertSame('iPad Air 2 16GB', $controller->formatDescription(null, 'iPad Air 2 16GB'));
    }

    public function testByDefaultItSearchesAllSalesOrdersInTheCurrentFiscalYear(): void
    {
        $controller = $this->runList();

        $year = $this->pdo()->query(
            'SELECT fy.begin, fy.end FROM ' . DB::prefix('fiscal_year') . ' AS fy'
            . ' JOIN ' . DB::prefix('sys_prefs') . " AS pref ON pref.name='f_year' AND pref.value=fy.id"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(ST_SALESORDER, $controller->trans_type);
        $this->assertFalse($_POST['order_view_mode']);
        $this->assertSame('Search All Sales Orders', $_SESSION['page_title']);
        $this->assertSame(sql2date($year['begin']), $_POST['OrdersAfterDate']);
        $this->assertSame(sql2date($year['end']), $_POST['OrdersToDate']);
        $this->assertTrue($this->showDates);
        $this->assertInstanceOf(ModelPager::class, $this->table);
        $this->assertSame('orders_tbl', $this->table->name);
        $this->assertSame('80%', $this->table->width);
        $this->assertSame([$controller, 'check_overdue'], $this->table->marker);
        $this->assertSame('Marked items are overdue.', $this->table->marker_txt);
        $expected = $this->orderNos('so.ord_date>=? AND so.ord_date<=?', [$year['begin'], $year['end']]);
        $this->assertSame(count($expected), (int) $this->table->rec_count);
        $this->assertSame($expected, $this->listed());
    }

    public function testTheDatesPostedNarrowTheOrdersListed(): void
    {
        $this->postAllDates();
        $this->runList();
        $all = $this->orderNos('1=1');
        $this->assertSame($all, $this->listed());

        $first = $this->pdo()->query('SELECT MIN(ord_date) FROM ' . DB::prefix('sales_orders'))->fetchColumn();
        $_POST['OrdersToDate'] = sql2date($first);
        $this->runList();

        $expected = $this->orderNos('so.ord_date<=?', [$first]);
        $this->assertNotEmpty($expected);
        $this->assertLessThan(count($all), count($expected));
        $this->assertSame($expected, $this->listed());
        $this->assertSame(count($expected), (int) $this->table->rec_count);
    }

    public function testAnOrderNumberListsJustThatOrder(): void
    {
        $orderNo = max($this->orderNos('1=1'));
        $_POST['OrderNumber'] = (string) $orderNo;

        $this->runList();

        $this->assertSame([$orderNo], $this->listed());
        $this->assertSame(1, (int) $this->table->rec_count);
    }

    public function testAReferenceListsTheOrdersWithIt(): void
    {
        $reference = $this->pdo()->query(
            'SELECT reference FROM ' . DB::prefix('sales_orders') . " WHERE reference<>'auto'"
            . ' GROUP BY reference HAVING COUNT(*)=1 ORDER BY reference LIMIT 1'
        )->fetchColumn();
        $_POST['OrderReference'] = $reference;

        $this->runList();

        $expected = $this->orderNos('so.reference=?', [$reference]);
        $this->assertCount(1, $expected);
        $this->assertSame($expected, $this->listed());
        $this->assertSame(1, (int) $this->table->rec_count);
    }

    public function testADomainListsTheOrdersWithALineDescribedByIt(): void
    {
        $description = $this->pdo()->query(
            'SELECT description FROM ' . DB::prefix('sales_order_details') . ' ORDER BY order_no DESC LIMIT 1'
        )->fetchColumn();
        $_POST['Domain'] = $description;

        $this->runList();

        $expected = $this->orderNos('sd.description LIKE ?', ['%' . $description . '%']);
        $this->assertNotEmpty($expected);
        $this->assertLessThan(count($this->orderNos('1=1')), count($expected));
        $this->assertSame($expected, $this->listed());
    }

    public function testACustomerListsTheirOrders(): void
    {
        $customer = (int) $this->pdo()->query(
            'SELECT debtor_no FROM ' . DB::prefix('sales_orders') . ' GROUP BY debtor_no ORDER BY COUNT(*) LIMIT 1'
        )->fetchColumn();
        $this->postAllDates();
        $_POST['customer_id'] = (string) $customer;

        $this->runList();

        $expected = $this->orderNos('so.debtor_no=?', [$customer]);
        $this->assertNotEmpty($expected);
        $this->assertLessThan(count($this->orderNos('1=1')), count($expected));
        $this->assertSame($expected, $this->listed());
        $this->assertSame(count($expected), (int) $this->table->rec_count);
    }

    public function testTheOrderColumnsEndWithEditAndPrintLinks(): void
    {
        $view = $this->view(self::VIEW);
        $controller = $this->runList($view);

        $this->assertSame(
            ['#', 'Ref', 'Customer', 'Order Date', 'Domain', 'Order Total', 'Currency', '', ''],
            $this->heads()
        );
        $this->assertSame([$view, 'view_link'], $this->table->columns[0]['fun']);
        $this->assertSame([$controller, 'formatDescription'], $this->table->columns[4]['fun']);
        $this->assertSame([$view, 'edit_link'], $this->lastFun(2));
        $this->assertSame([$view, 'prt_link'], $this->lastFun());
    }

    public function testOutstandingOnlyHidesTheDatesAndAddsDispatchAndEditLinks(): void
    {
        $_GET['OutstandingOnly'] = 1;
        $view = $this->view(self::VIEW);

        $this->runList($view);

        $this->assertSame('OutstandingOnly', $_POST['order_view_mode']);
        $this->assertSame('Search Outstanding Sales Orders', $_SESSION['page_title']);
        $this->assertFalse($this->showDates);
        $this->assertSame([$view, 'dispatch_link'], $this->lastFun(2));
        $this->assertSame([$view, 'edit_link'], $this->lastFun());
    }

    public function testInvoiceTemplatesHidesTheDatesAndDescribesTheOrders(): void
    {
        $_GET['InvoiceTemplates'] = 1;

        $this->runList();

        $this->assertSame('InvoiceTemplates', $_POST['order_view_mode']);
        $this->assertSame('Search Template for Invoicing', $_SESSION['page_title']);
        $this->assertFalse($this->showDates);
        $this->assertSame(
            ['#', 'Ref', 'Customer', 'Order Date', 'Description', 'Order Total', 'Currency'],
            $this->heads()
        );
    }

    public function testDeliveryTemplatesHidesTheDatesAndAddsADeliveryLink(): void
    {
        $_GET['DeliveryTemplates'] = 1;
        $view = $this->view(self::VIEW);

        $this->runList($view);

        $this->assertSame('DeliveryTemplates', $_POST['order_view_mode']);
        $this->assertSame('Select Template for Delivery', $_SESSION['page_title']);
        $this->assertFalse($this->showDates);
        $this->assertSame('Description', $this->heads()[4]);
        $this->assertSame([$view, 'delivery_link'], $this->lastFun());
    }

    public function testPrepaidOrdersKeepsTheDatesAndAddsAPrepaymentInvoiceLink(): void
    {
        $_GET['PrepaidOrders'] = 1;
        $view = $this->view(self::VIEW);

        $this->runList($view);

        $this->assertSame('PrepaidOrders', $_POST['order_view_mode']);
        $this->assertSame('Invoicing Prepayment Orders', $_SESSION['page_title']);
        $this->assertTrue($this->showDates);
        $this->assertSame([$view, 'invoice_prep_link'], $this->lastFun());
    }

    public function testAViewModeAlreadyPostedIsKept(): void
    {
        $_POST['order_view_mode'] = 'OutstandingOnly';

        $this->runList();

        $this->assertSame('OutstandingOnly', $_POST['order_view_mode']);
        $this->assertFalse($this->showDates);
        $this->assertNotSame('Search All Sales Orders', $_SESSION['page_title'] ?? null);
    }

    public function testTheQuoteTypeInTheUrlSearchesQuotations(): void
    {
        $_GET['type'] = ST_SALESQUOTE;
        $view = $this->view(self::VIEW);

        $controller = $this->runList($view);

        $this->assertSame(ST_SALESQUOTE, $controller->trans_type);
        $this->assertSame('Quotations', $_POST['order_view_mode']);
        $this->assertSame('Search All Sales Quotations', $_SESSION['page_title']);
        $this->assertTrue($this->showDates);
        $this->assertSame(
            ['Quote #', 'Ref', 'Customer', 'Branch', 'Cust Order Ref', 'Quote Date', 'Valid until',
                'Delivery To', 'Quote Total', '', 'Currency', '', '', ''],
            $this->heads()
        );
        $this->assertSame('skip', $this->table->columns[9]['type']);
        $this->assertSame([$view, 'edit_link'], $this->lastFun(3));
        $this->assertSame([$view, 'order_link'], $this->lastFun(2));
        $this->assertSame([$view, 'prt_link'], $this->lastFun());
    }

    public function testAPostedTypeOverridesTheUrl(): void
    {
        $_POST['type'] = (string) ST_SALESQUOTE;
        $controller = $this->runList();
        $this->assertEquals(ST_SALESQUOTE, $controller->trans_type);
        $this->assertSame('Quotations', $_POST['order_view_mode']);

        $_POST = ['type' => (string) ST_SALESORDER];
        $_GET['type'] = ST_SALESQUOTE;
        $controller = $this->runList();
        $this->assertEquals(ST_SALESORDER, $controller->trans_type);
        $this->assertFalse($_POST['order_view_mode']);
    }

    public function testTickingAnOrdersTemplateBoxMakesItATemplate(): void
    {
        $orderNo = $this->orderOfType(0);
        $this->ajax()->expects($this->atLeastOnce())->method('activate')->with('orders_tbl');
        $_POST['_chgtpl' . $orderNo . '_update'] = 1;
        $_POST['chgtpl' . $orderNo] = 1;

        $this->runList();

        $this->assertSame(1, $this->orderType($orderNo));
    }

    public function testClearingATemplatesBoxMakesItAnOrderAgain(): void
    {
        $orderNo = $this->orderOfType(1);
        $this->ajax();
        $_POST['_chgtpl' . $orderNo . '_update'] = 1;

        $this->runList();

        $this->assertSame(0, $this->orderType($orderNo));
    }

    public function testUpdateLeavesTheTemplatesAsTheyWereWhereTheBoxesAreUnchanged(): void
    {
        $order = $this->orderOfType(0);
        $template = $this->orderOfType(1);
        $_POST['Update'] = 'Update';
        $_POST['last'] = [$order => 0, $template => 1];
        $_POST['chgtpl' . $template] = 1;

        $this->runList();

        $this->assertSame(0, $this->orderType($order));
        $this->assertSame(1, $this->orderType($template));
    }

    public function testANumberTypedDisablesTheDatesAndRefreshesTheTable(): void
    {
        $ajax = $this->ajax();
        $ajax->expects($this->exactly(2))->method('addDisable')->withConsecutive(
            [true, 'OrdersAfterDate', true],
            [true, 'OrdersToDate', true]
        );
        $ajax->expects($this->once())->method('activate')->with('orders_tbl');
        $_POST['_OrderNumber_changed'] = 1;
        $_POST['OrderNumber'] = '1';

        $this->runList();
    }

    public function testClearingTheSearchBoxesEnablesTheDatesAgain(): void
    {
        $ajax = $this->ajax();
        $ajax->expects($this->exactly(2))->method('addDisable')->withConsecutive(
            [true, 'OrdersAfterDate', false],
            [true, 'OrdersToDate', false]
        );
        $_POST['_Domain_changed'] = 1;
        $_POST['OrderNumber'] = '';
        $_POST['OrderReference'] = '';
        $_POST['Domain'] = '';

        $this->runList();
    }

    public function testWithoutDatesShownTheirControlsAreLeftAlone(): void
    {
        $ajax = $this->ajax();
        $ajax->expects($this->never())->method('addDisable');
        $ajax->expects($this->once())->method('activate')->with('orders_tbl');
        $_GET['OutstandingOnly'] = 1;
        $_POST['_OrderReference_changed'] = 1;
        $_POST['OrderReference'] = 'x';

        $this->runList();
    }

    /**
     * Rendered by the cp fork's ControlRenderer. Upstream's display_db_pager() reads
     * $pager->data, which a ModelPager has not: tools/ci.sh leaves fa-cp out there.
     *
     * @group fa-cp
     */
    public function testThePagerRendersTheOrdersAsTheViewFormatsThem(): void
    {
        $view = $this->view(self::VIEW);
        $view->method('view_link')->willReturnCallback(function ($model, $orderNo) {
            return "[order $orderNo]";
        });
        $view->method('edit_link')->willReturn('[edit]');
        $view->method('prt_link')->willReturn('[print]');
        $html = '';
        $view->method('renderView')->willReturnCallback(function ($showDates, $table) use (&$html) {
            ob_start();
            display_db_pager($table);
            $html = ob_get_clean();
        });
        $this->postAllDates();
        $controller = new SalesOrderList($view);

        $controller->run();

        $orders = $this->pdo()->query(
            'SELECT so.order_no, so.reference, debtor.name FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' JOIN ' . DB::prefix('debtors_master') . ' AS debtor ON debtor.debtor_no=so.debtor_no'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($orders);
        foreach ($orders as $order) {
            $this->assertStringContainsString('[order ' . $order['order_no'] . ']', $html);
            $this->assertStringContainsString('>' . $order['reference'] . '<', $html);
            $this->assertStringContainsString(htmlspecialchars($order['name'], ENT_QUOTES), $html);
        }
        $this->assertSame(count($orders), substr_count($html, '[edit]'));
        $this->assertSame(count($orders), substr_count($html, '[print]'));
        $this->assertStringContainsString(sprintf('Records 1-%1$d of %1$d', count($orders)), $html);
        $this->assertStringContainsString('orders_tbl_sort_1', $html);
        $this->assertStringContainsString('Marked items are overdue.', $html);
        $this->assertStringNotContainsString('overduebg', $html);
    }
}
