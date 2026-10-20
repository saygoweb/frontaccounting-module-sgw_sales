<?php

namespace SGW_Sales\Tests\Integration\Pager;

use SGW\common\Pager\ModelPager;
use SGW\common\Pager\PagerData;
use SGW_Sales\db\DB;
use SGW_Sales\db\SalesOrderListModel;
use SGW_Sales\Tests\Integration\FaTestCase;

/**
 * A row for the pager's header: the handler is called by name.
 *
 * @return array<int, array<int, string>>
 */
function pagerHeader(ModelPager $pager): array
{
    return [['[header of ' . $pager->rec_count . ']', "colspan='9'"]];
}

/**
 * A row for the pager's footer.
 *
 * @return array<int, array<int, string>>
 */
function pagerFooter(ModelPager $pager): array
{
    return [['[footer, page ' . $pager->curr_page . ']']];
}

/**
 * The pager over Anorm models that the Sales Order Inquiry lists its orders
 * with, on the demo company's sales orders, three to a page.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ModelPagerTest extends FaTestCase
{
    private const NAME = 'test_tbl';

    private const PAGE = 3;

    private const COLUMNS = [
        '#' => ['name' => 'orderNo'],
        'Ref' => ['name' => 'reference', 'ord' => ''],
        'Customer' => ['name' => 'name', 'ord' => ''],
        'Order Date' => ['name' => 'ordDate', 'type' => 'date'],
        'Total' => 'amount',
        'Hidden' => 'skip',
        'Plain',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['wa_current_user']->prefs->query_size = self::PAGE;
    }

    /**
     * A pager over every demo order, as a request makes it.
     *
     * @param mixed[] $columns
     */
    private function pager(array $columns = self::COLUMNS, ?int $count = null): ModelPager
    {
        $query = SalesOrderListModel::queryByFilter('', '', '', '01/01/2000', '12/31/2099', '');

        return new ModelPager(self::NAME, SalesOrderListModel::class, $query, $count ?? $this->orderCount(), $columns);
    }

    private function orderCount(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . DB::prefix('sales_orders'))->fetchColumn();
    }

    private function pages(): int
    {
        return (int) ceil($this->orderCount() / self::PAGE);
    }

    /**
     * The order numbers on a page, from the database.
     *
     * @return int[]
     */
    private function page(int $page): array
    {
        return array_map('intval', $this->pdo()->query(
            'SELECT order_no FROM ' . DB::prefix('sales_orders') . ' GROUP BY order_no ORDER BY order_no'
            . ' LIMIT ' . (($page - 1) * self::PAGE) . ', ' . self::PAGE
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The order numbers on the first page sorted by $orderBy, from the database.
     *
     * @return int[]
     */
    private function sortedPage(string $orderBy): array
    {
        return array_map('intval', $this->pdo()->query(
            'SELECT so.order_no FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' JOIN ' . DB::prefix('debtors_master') . ' AS debtor ON debtor.debtor_no=so.debtor_no'
            . " ORDER BY $orderBy, so.order_no LIMIT " . self::PAGE
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return int[]
     */
    private function listed(ModelPager $pager): array
    {
        $listed = [];
        foreach ($pager->generator() as $model) {
            $this->assertInstanceOf(SalesOrderListModel::class, $model);
            $listed[] = (int) $model->orderNo;
        }

        return $listed;
    }

    /**
     * The pager after a press of one of its buttons, in a new request.
     *
     * @param array<string, mixed> $post
     */
    private function press(array $post): ModelPager
    {
        $_POST = $post;
        $pager = $this->pager();
        $pager->select_records();

        return $pager;
    }

    public function testANewPagerStartsOnTheFirstPageAndKeepsItsPlaceInTheSession(): void
    {
        $this->assertGreaterThan(2, $this->pages(), 'the demo dataset has too few orders to page');

        $pager = $this->pager();

        $this->assertInstanceOf(PagerData::class, $_SESSION[self::NAME]);
        $this->assertSame($_SESSION[self::NAME], $pager->pagerData);
        $this->assertSame(self::PAGE, (int) $pager->page_len);
        $this->assertSame($this->orderCount(), $pager->rec_count);
        $this->assertEquals($this->pages(), $pager->max_page);
        $this->assertSame(1, $pager->curr_page);
        $this->assertSame(2, $pager->next_page);
        $this->assertEquals($this->pages(), $pager->last_page);
        $this->assertNull($pager->prev_page);
        $this->assertNull($pager->first_page);
        $this->assertSame('95%', $pager->width);
        $this->assertSame([], $pager->generator());
    }

    public function testItsColumnsTakeAHeadAndATypeFromTheirDefinitions(): void
    {
        $pager = $this->pager();

        $this->assertSame(
            [
                ['name' => 'orderNo', 'type' => 'text', 'head' => '#'],
                ['name' => 'reference', 'ord' => '', 'type' => 'text', 'head' => 'Ref'],
                ['name' => 'name', 'ord' => '', 'type' => 'text', 'head' => 'Customer'],
                ['name' => 'ordDate', 'type' => 'date', 'head' => 'Order Date'],
                ['type' => 'amount', 'head' => 'Total'],
                ['type' => 'skip'],
                ['type' => 'text', 'head' => 'Plain'],
            ],
            $pager->columns
        );
        $this->assertFalse($pager->inactive_ctrl);

        $pager = $this->pager([['insert' => true, 'fun' => 'strtoupper'], 'Active' => 'inactive']);

        $this->assertSame(
            [
                ['insert' => true, 'fun' => 'strtoupper', 'type' => 'text', 'head' => ''],
                ['type' => 'inactive', 'head' => 'Active'],
            ],
            $pager->columns
        );
        $this->assertTrue($pager->inactive_ctrl);
    }

    public function testSelectingRecordsWithNoButtonPressedQueriesTheCurrentPage(): void
    {
        $pager = $this->press([]);

        $this->assertSame($this->page(1), $this->listed($pager));
    }

    public function testTheNavigationButtonsMoveThroughThePages(): void
    {
        $pages = $this->pages();
        $this->pager();

        $pager = $this->press([self::NAME . '_page_next' => 'Next']);
        $this->assertSame(2, $pager->curr_page);
        $this->assertSame(1, $pager->prev_page);
        $this->assertSame(1, $pager->first_page);
        $this->assertSame($this->page(2), $this->listed($pager));

        // The page is kept in the session from one request to the next.
        $pager = $this->press([]);
        $this->assertSame(2, $pager->curr_page);
        $this->assertSame($this->page(2), $this->listed($pager));

        $pager = $this->press([self::NAME . '_page_last' => 'Last']);
        $this->assertEquals($pages, $pager->curr_page);
        $this->assertNull($pager->next_page);
        $this->assertNull($pager->last_page);
        $this->assertSame($this->page($pages), $this->listed($pager));

        $pager = $this->press([self::NAME . '_page_prev' => 'Prev']);
        $this->assertEquals($pages - 1, $pager->curr_page);
        $this->assertSame($this->page($pages - 1), $this->listed($pager));

        $pager = $this->press([self::NAME . '_page_first' => 'First']);
        $this->assertSame(1, $pager->curr_page);
        $this->assertSame($this->page(1), $this->listed($pager));
    }

    public function testPressingPastEitherEndStaysThereAndFocusesTheOtherWay(): void
    {
        $this->press([self::NAME . '_page_last' => 'Last']);

        $pager = $this->press([self::NAME . '_page_next' => 'Next']);
        $this->assertEquals($this->pages(), $pager->curr_page);
        $this->assertSame(self::NAME . '_page_prev', $_POST['_focus']);

        $this->press([self::NAME . '_page_first' => 'First']);
        $pager = $this->press([self::NAME . '_page_prev' => 'Prev']);
        $this->assertSame(1, $pager->curr_page);
        $this->assertSame(self::NAME . '_page_next', $_POST['_focus']);

        $pager = $this->press([self::NAME . '_page_first' => 'First']);
        $this->assertSame(self::NAME . '_page_next', $_POST['_focus']);
    }

    public function testSetPageTakesANumberAndKeepsItWithinThePages(): void
    {
        $pager = $this->pager();

        $pager->set_page(2);
        $this->assertSame(2, $pager->curr_page);

        $pager->set_page(99);
        $this->assertEquals($this->pages(), $pager->curr_page);

        // Not 0: on PHP 7 its switch takes 0 for 'next' (0 == 'next').
        $pager->set_page(-1);
        $this->assertSame(1, $pager->curr_page);

        $pager->set_page(2);
        $pager->set_page('nonsense');
        $this->assertSame(1, $pager->curr_page);
    }

    public function testChangePageQueriesThePageGiven(): void
    {
        $pager = $this->pager();

        $this->assertTrue($pager->change_page(2));

        $this->assertSame($this->page(2), $this->listed($pager));
    }

    public function testACountThatChangesTheNumberOfPagesStartsAgainOnTheFirst(): void
    {
        $this->press([self::NAME . '_page_next' => 'Next']);

        $pager = $this->pager(self::COLUMNS, 1);

        $this->assertEquals(1, $pager->max_page);
        $this->assertSame(1, $pager->curr_page);
        $this->assertNull($pager->next_page);
    }

    public function testNoRecordsQueriesNothing(): void
    {
        $pager = $this->pager(self::COLUMNS, 0);

        $this->assertTrue($pager->query());

        $this->assertSame([], $pager->generator());
    }

    public function testRefreshPagerForgetsThePlace(): void
    {
        $this->press([self::NAME . '_page_next' => 'Next']);
        $this->assertArrayHasKey(self::NAME, $_SESSION);

        ModelPager::refresh_pager(self::NAME);

        $this->assertArrayNotHasKey(self::NAME, $_SESSION);
        $this->assertSame(1, $this->pager()->curr_page);
        ModelPager::refresh_pager('no_such_pager');
    }

    public function testASortButtonCyclesItsColumnAscendingDescendingAndBackToFirstPage(): void
    {
        $this->press([self::NAME . '_page_next' => 'Next']);
        $pager = $this->pager();

        $pager = $this->press([self::NAME . '_sort_1' => 'Ref']);

        $this->assertSame('asc', $pager->columns[1]['ord']);
        $this->assertSame(1, $pager->columns[1]['ord_priority']);
        $this->assertSame(1, $pager->curr_page);
        $this->assertSame($this->sortedPage('so.reference'), $this->listed($pager));

        $pager->sort_table(2);
        $this->assertSame('asc', $pager->columns[2]['ord']);
        $this->assertSame(2, $pager->columns[2]['ord_priority']);

        $pager->sort_table(1);
        $this->assertSame('desc', $pager->columns[1]['ord']);
        $this->assertSame(3, $pager->columns[1]['ord_priority']);

        $this->assertTrue($pager->sort_table(1));
        $this->assertSame('', $pager->columns[1]['ord']);
    }

    public function testTheSortIsKeptFromOneRequestToTheNextAndOrdersTheRows(): void
    {
        $this->assertNotSame(
            $this->sortedPage('so.order_no'),
            $this->sortedPage('debtor.name DESC'),
            'the demo customers sort apart from the order numbers'
        );

        $this->press([self::NAME . '_sort_2' => 'Customer']);
        $pager = $this->press([]);
        $this->assertSame('asc', $pager->columns[2]['ord']);
        $this->assertSame($this->sortedPage('debtor.name'), $this->listed($pager));

        $pager = $this->press([self::NAME . '_sort_2' => 'Customer']);
        $this->assertSame('desc', $pager->columns[2]['ord']);
        $this->assertSame($this->sortedPage('debtor.name DESC'), $this->listed($pager));

        // The column sorted last comes first.
        $this->press([self::NAME . '_sort_1' => 'Ref']);
        $pager = $this->press([]);
        $this->assertSame($this->sortedPage('so.reference, debtor.name DESC'), $this->listed($pager));

        $this->press([self::NAME . '_sort_1' => 'Ref']);
        $this->press([self::NAME . '_sort_1' => 'Ref']);
        $this->press([self::NAME . '_sort_2' => 'Customer']);
        $pager = $this->press([]);
        $this->assertSame('', $pager->columns[1]['ord']);
        $this->assertSame('', $pager->columns[2]['ord']);
        $this->assertSame($this->page(1), $this->listed($pager));
    }

    public function testOnePagerListsEachPageItIsAskedFor(): void
    {
        $pager = $this->pager();

        $pager->change_page(1);
        $this->assertSame($this->page(1), $this->listed($pager));
        $pager->change_page(2);
        $this->assertSame($this->page(2), $this->listed($pager));
    }

    public function testInactiveControlCellShowsAModelsStatus(): void
    {
        $pager = $this->pager(['#' => ['name' => 'orderNo'], 'Inactive' => 'inactive']);
        $pager->set_inactive_ctrl('sales_orders', 'order_no');
        $this->assertSame('sales_orders', $pager->main_tbl);
        $this->assertSame('order_no', $pager->key);
        $_POST['show_inactive'] = 1;
        $model = new \stdClass();
        $model->orderNo = 7;
        $model->inactive = 1;
        $array = ['order_no' => 8, 'inactive' => 0];

        ob_start();
        $pager->inactive_control_cell($model);
        $pager->inactive_control_cell($array);
        $html = ob_get_clean();

        $this->assertMatchesRegularExpression("/<input checked [^>]*name=['\"]Inactive7['\"]/", $html);
        $this->assertMatchesRegularExpression("/<input type=['\"]checkbox['\"] name=['\"]Inactive8['\"]/", $html);
        $this->assertMatchesRegularExpression("/name=['\"]LInact\\[7\\]['\"][^>]*value=['\"]1['\"]/", $html);
        $this->assertMatchesRegularExpression("/name=['\"]LInact\\[8\\]['\"][^>]*value=['\"]0['\"]/", $html);
    }

    public function testTheMarkerHeaderAndFooterAreKept(): void
    {
        $pager = $this->pager();
        $marker = function () {
            return true;
        };

        $pager->set_marker($marker, 'Marked.');
        $pager->set_header(__NAMESPACE__ . '\pagerHeader');
        $pager->set_footer(__NAMESPACE__ . '\pagerFooter', 'footerbg');

        $this->assertSame($marker, $pager->marker);
        $this->assertSame('Marked.', $pager->marker_txt);
        $this->assertSame('overduebg', $pager->marker_class);
        $this->assertSame('overduefg', $pager->notice_class);
        $this->assertSame(__NAMESPACE__ . '\pagerHeader', $pager->header_fun);
        $this->assertSame('inquirybg', $pager->header_class);
        $this->assertSame(__NAMESPACE__ . '\pagerFooter', $pager->footer_fun);
        $this->assertSame('footerbg', $pager->footer_class);

        $pager->set_marker($marker, 'Marked.', 'mine', 'note');
        $this->assertSame('mine', $pager->marker_class);
        $this->assertSame('note', $pager->notice_class);
    }

    /**
     * Rendered by the cp fork's ControlRenderer. Upstream's display_db_pager() reads
     * $pager->data, which a ModelPager has not: tools/ci.sh leaves fa-cp out there.
     *
     * @group fa-cp
     */
    public function testDisplayDbPagerRendersAPageWithItsMarkerHeaderAndFooter(): void
    {
        $_POST = [self::NAME . '_page_next' => 'Next'];
        $pager = $this->pager();
        $page = $this->page(2);
        $marked = $page[0];
        $pager->set_marker(function (SalesOrderListModel $model) use ($marked) {
            return (int) $model->orderNo === $marked;
        }, 'Marked orders.');
        $pager->set_header(__NAMESPACE__ . '\pagerHeader');
        $pager->set_footer(__NAMESPACE__ . '\pagerFooter');

        ob_start();
        display_db_pager($pager);
        $html = ob_get_clean();

        $orders = $this->pdo()->query(
            'SELECT order_no, reference FROM ' . DB::prefix('sales_orders') . ' ORDER BY order_no'
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($orders as $orderNo => $reference) {
            $cell = '>' . $orderNo . '</td>';
            if (in_array($orderNo, $page)) {
                $this->assertStringContainsString($cell, $html);
            } else {
                $this->assertStringNotContainsString($cell, $html);
            }
        }
        $this->assertSame(1, substr_count($html, "class='overduebg'"));
        $this->assertStringContainsString('Marked orders.', $html);
        $this->assertStringContainsString('[header of ' . $this->orderCount() . ']', $html);
        $this->assertStringContainsString('[footer, page 2]', $html);
        $this->assertStringContainsString(
            sprintf('Records %d-%d of %d', self::PAGE + 1, self::PAGE * 2, $this->orderCount()),
            $html
        );
        $this->assertStringContainsString(self::NAME . '_sort_1', $html);
        $this->assertStringContainsString(self::NAME . '_page_first', $html);
        $this->assertStringNotContainsString('Hidden', $html);
    }

    /**
     * Rendered by the cp fork's ControlRenderer. Upstream's display_db_pager() reads
     * $pager->data, which a ModelPager has not: tools/ci.sh leaves fa-cp out there.
     *
     * @group fa-cp
     */
    public function testDisplayDbPagerSaysSoWhenThereAreNoRecords(): void
    {
        $pager = $this->pager(self::COLUMNS, 0);

        ob_start();
        display_db_pager($pager);
        $html = ob_get_clean();

        $this->assertStringContainsString('No records', $html);
    }

    public function testInactiveControlCellIsEmptyWithoutAnInactiveColumn(): void
    {
        $pager = $this->pager();
        $row = ['orderNo' => 1, 'inactive' => 0];

        $this->assertSame('', $pager->inactive_control_cell($row));

        $pager->set_inactive_ctrl('sales_orders', 'order_no');
        $this->assertSame(['table' => 'sales_orders', 'key' => 'order_no'], $pager->inactive_ctrl);
    }

    public function testTheGoDebugCheckWarnsOfAnOrderedColumnWithoutAName(): void
    {
        $GLOBALS['SysPrefs']->go_debug = 1;
        \FA\GraphQL\Fa\FaMessages::reset();

        $this->pager(['Sorted' => ['ord' => 'asc'], 'Named' => ['name' => 'orderNo', 'ord' => 'asc']]);

        $this->assertCount(1, \FA\GraphQL\Fa\FaMessages::warnings());
    }
}
