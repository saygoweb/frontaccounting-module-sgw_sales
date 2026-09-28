<?php

namespace SGW_Sales\Tests\Http;

use Anorm\Anorm;
use SGW_Sales\db\DB;
use SGW_Sales\db\SalesRecurringModel;

/**
 * RecurringInvoiceService called the way an API calls it: FrontAccounting
 * booted, a user logged in, and none of what the Generate Recurring Invoices
 * page includes. Whatever the service needs of FrontAccounting it has to ask
 * for itself - Cart wants count_array() from ui.inc, which every FA page has
 * loaded and nothing else has.
 *
 * Not rolled back, as GenerateInvoiceTest is not: the documents stay in this
 * stack's database, and the recurrence is deleted afterwards.
 */
class ServiceWithoutAPageTest extends HttpTestCase
{
    /** Served from the module's root: Apache denies tests/. */
    private const PROBE = __DIR__ . '/../../test-service-probe.php';

    /** @var SalesRecurringModel|null */
    private $recurrence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectDb();
        if (!@copy(__DIR__ . '/fixtures/service-probe.php', self::PROBE)) {
            $this->markTestSkipped('cannot write the probe into the module directory');
        }
    }

    protected function tearDown(): void
    {
        @unlink(self::PROBE);
        if ($this->recurrence && $this->recurrence->id) {
            $this->recurrence->delete();
        }
    }

    private function probe(int $orderNo, string $date, array $more = []): array
    {
        $query = http_build_query(['order' => $orderNo, 'date' => $date] + $more);
        [$status, $body] = $this->request('/modules/sgw_sales/test-service-probe.php?' . $query);
        $this->assertSame(200, $status);
        if (!preg_match('/<<<JSON(.*)JSON>>>/s', $body, $m)) {
            $this->fail('the probe did not answer: ' . substr(strip_tags($body), 0, 500));
        }
        return json_decode($m[1], true);
    }

    private function today(): string
    {
        return (new \DateTime('today'))->format('Y-m-d');
    }

    public function testADueOrderIsDeliveredAndInvoicedInOneGoWithNoPageBehindIt(): void
    {
        $orderNo = $this->unrecurredOrder();
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);
        $this->recurrence = $this->dueMonthly($orderNo);

        $out = $this->probe($orderNo, $this->today(), ['email' => 1]);

        $this->assertArrayNotHasKey('error', $out, $out['error'] ?? '');
        $this->assertFalse($out['view_loaded']);
        $generated = $out['generated'];
        $this->assertSame($orderNo, $generated['orderNo']);
        $this->assertGreaterThan(0, $generated['deliveryNo']);
        $this->assertGreaterThan(0, $generated['invoiceNo']);
        $this->assertStringStartsWith('Invoice for period 1 ', $generated['comment']);
        $this->assertTrue($out['emailed']);
        $this->assertSame(0, $out['transaction_level'], 'the transaction is closed when generate() returns');

        $expected = (new \DateTime('first day of next month'))->format('Y-m-d');
        $this->assertSame($expected, $generated['dtNext']);
        $this->assertSame($expected, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
        $this->assertSame($invoices + 1, $this->invoiceCount($orderNo));
        $this->assertSame($deliveries + 1, $this->deliveryCount($orderNo));
        $this->assertGlBalanced($generated['deliveryNo'], $generated['invoiceNo']);

        // Emailing borrows $_POST for FrontAccounting's report; the caller gets its own back.
        $this->assertSame(['PARAM_0' => 'the caller had this here'], $out['post']);
    }

    public function testTheInvoiceIsDatedAsAskedNotToday(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->dueMonthly($orderNo);
        $date = (new \DateTime('first day of this month'))->format('Y-m-d');

        $out = $this->probe($orderNo, $date);

        $this->assertArrayNotHasKey('error', $out, $out['error'] ?? '');
        $statement = Anorm::pdo()->prepare(
            'SELECT tran_date FROM ' . DB::prefix('debtor_trans') . ' WHERE type=10 AND trans_no=:no'
        );
        $statement->execute([':no' => $out['generated']['invoiceNo']]);
        $this->assertSame($date, $statement->fetchColumn());
    }

    public function testARetryOnTheSameDateBillsNothingTwice(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->dueMonthly($orderNo);
        $first = $this->probe($orderNo, $this->today());
        $this->assertArrayNotHasKey('error', $first, $first['error'] ?? '');
        $invoices = $this->invoiceCount($orderNo);

        $again = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\RecurrenceNotDue', $again['errorClass'] ?? null, $again['error'] ?? '');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
    }

    public function testAnOrderNotYetDueIsRefusedUnlessAskedForEarly(): void
    {
        $orderNo = $this->unrecurredOrder();
        $tomorrow = (new \DateTime('tomorrow'))->format('Y-m-d');
        $this->recurrence = $this->monthlyOnThe1st($orderNo, $tomorrow);
        $invoices = $this->invoiceCount($orderNo);

        $refused = $this->probe($orderNo, $this->today());
        $notDue = 'SGW_Sales\service\RecurrenceNotDue';
        $this->assertSame($notDue, $refused['errorClass'] ?? null, $refused['error'] ?? '');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));

        $early = $this->probe($orderNo, $this->today(), ['early' => 1]);
        $this->assertArrayNotHasKey('error', $early, $early['error'] ?? '');
        $this->assertSame($invoices + 1, $this->invoiceCount($orderNo));
    }

    public function testAnEndedScheduleIsRefused(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->monthlyOnThe1st($orderNo, null, $this->today());

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\RecurrenceEnded', $out['errorClass'] ?? null, $out['error'] ?? '');
    }

    public function testADateOutsideTheFiscalYearIsRefusedAndNothingIsWritten(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->monthlyOnThe1st($orderNo, '1999-12-01');
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $out = $this->probe($orderNo, '2000-01-01');

        $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertSame('date', $out['field']);
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($deliveries, $this->deliveryCount($orderNo));
        $this->assertSame('1999-12-01', SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    public function testAFailureAfterTheDeliveryRollsEverythingBack(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->dueMonthly($orderNo);
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $out = $this->probe($orderNo, $this->today(), ['fail' => 'invoice']);

        $this->assertStringStartsWith('RuntimeException: the invoice failed after delivery', $out['error'] ?? '');
        $this->assertSame(0, $out['transaction_level']);
        $this->assertSame($deliveries, $this->deliveryCount($orderNo), 'the delivery rolled back with the invoice');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertNull(SalesRecurringModel::readByTransNo($orderNo)->dtNext, 'the schedule did not move on');
    }

    public function testAnOrderTheStockCannotCoverIsRefusedAndNothingIsWritten(): void
    {
        // FrontAccounting's own check (Cart::check_qoh) where negative stock is not
        // allowed: a stock-held line asking for more than its location holds.
        $orderNo = (int) Anorm::pdo()->query(
            'SELECT so.order_no FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' JOIN ' . DB::prefix('sales_order_details') . ' AS line'
            . ' ON line.order_no=so.order_no AND line.trans_type=so.trans_type'
            . ' JOIN ' . DB::prefix('stock_master') . ' AS item ON item.stock_id=line.stk_code'
            . " AND item.mb_flag IN ('B', 'M')"
            . ' WHERE so.trans_type=' . ST_SALESORDER
            . ' AND so.order_no NOT IN (SELECT trans_no FROM ' . DB::prefix('sales_recurring') . ')'
            . ' AND (SELECT value FROM ' . DB::prefix('sys_prefs') . " WHERE name='allow_negative_stock') = 0"
            . ' AND line.quantity > (SELECT COALESCE(SUM(qty), 0) FROM ' . DB::prefix('stock_moves') . ' AS m'
            . ' WHERE m.stock_id=line.stk_code AND m.loc_code=so.from_stk_loc)'
            . ' ORDER BY so.order_no LIMIT 1'
        )->fetchColumn();
        if (!$orderNo) {
            $this->markTestSkipped('no order in the loaded dataset asks for more stock than it holds');
        }
        $this->recurrence = $this->dueMonthly($orderNo);
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertStringContainsString('insufficient quantity', $out['error']);
        $this->assertSame($deliveries, $this->deliveryCount($orderNo));
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertNull(SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    public function testOrderWithoutARecurrenceIsRefusedAndNothingIsInvoiced(): void
    {
        $orderNo = $this->unrecurredOrder();
        $before = $this->invoiceCount($orderNo);

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\RecurrenceNotFound', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertSame($before, $this->invoiceCount($orderNo));
    }
}
