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
    /** @var SalesRecurringModel|null */
    private $recurrence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectDb();
    }

    protected function tearDown(): void
    {
        if ($this->recurrence && $this->recurrence->id) {
            $this->recurrence->delete();
        }
        parent::tearDown();
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

    /**
     * Checkpoint C C-1: a schedule with every = 0 (sgw_sales' order page let it be
     * saved) stayed due after billing, so a second call billed the period again, and
     * dt_next moved backwards. The next date must be strictly after the date billed.
     */
    public function testAScheduleThatWouldNotMoveOnIsRefusedAndNothingIsWritten(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->dueMonthly($orderNo);
        $this->recurrence->every = 0;
        $this->recurrence->write();
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $first = $this->probe($orderNo, $this->today());
        $second = $this->probe($orderNo, $this->today());

        foreach ([$first, $second] as $out) {
            $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
            $this->assertStringContainsString("'Every'", $out['error']);
        }
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($deliveries, $this->deliveryCount($orderNo));
        $this->assertNull(SalesRecurringModel::readByTransNo($orderNo)->dtNext);

        // Billed before, then saved with every = 0: dt_next stays, never earlier.
        $thisMonth = (new \DateTime('first day of this month'))->format('Y-m-d');
        $this->recurrence->dtNext = $thisMonth;
        $this->recurrence->write();

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertSame($thisMonth, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
    }

    /**
     * Checkpoint C M-5, re-review N-3: early generation (the page's Show All) bills
     * the next due period - the one starting at dt_next - early, once. Asked again,
     * a period starting after the date is already billed, so it is refused.
     */
    public function testEarlyGenerationBillsTheNextDuePeriodOnceOnly(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->dueMonthly($orderNo);
        $due = $this->probe($orderNo, $this->today(), ['early' => 1]);
        $this->assertArrayNotHasKey('error', $due, $due['error'] ?? '');
        $nextMonth = new \DateTime('first day of next month');
        $this->assertSame($nextMonth->format('Y-m-d'), $due['generated']['dtNext']);

        $early = $this->probe($orderNo, $this->today(), ['early' => 1]);

        $this->assertArrayNotHasKey('error', $early, $early['error'] ?? '');
        $this->assertStringStartsWith(
            'Invoice for period ' . $nextMonth->format('j F Y') . ' to ',
            $early['generated']['comment']
        );
        $inTwoMonths = (new \DateTime('first day of +2 months'))->format('Y-m-d');
        $this->assertSame($inTwoMonths, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
        $invoices = $this->invoiceCount($orderNo);

        $again = $this->probe($orderNo, $this->today(), ['early' => 1]);

        $this->assertSame('SGW_Sales\service\RecurrenceNotDue', $again['errorClass'] ?? null, $again['error'] ?? '');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($inTwoMonths, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    /**
     * Checkpoint C re-review N-3: every 2 months, billed last month (so dt_next is
     * next month, and this month is paid). Show All billed from this month - this
     * month twice - and moved the schedule onto the other months. It bills from
     * dt_next, once, and the rhythm stays.
     */
    public function testEarlyGenerationEveryTwoMonthsBillsFromDtNextNotFromTheDate(): void
    {
        $orderNo = $this->unrecurredOrder();
        $lastMonth = (new \DateTime('first day of last month'))->format('Y-m-d');
        $nextMonth = new \DateTime('first day of next month');
        $this->recurrence = $this->monthlyOnThe1st($orderNo, $lastMonth);
        $this->recurrence->every = 2;
        $this->recurrence->write();
        $billed = $this->probe($orderNo, $lastMonth);
        $this->assertArrayNotHasKey('error', $billed, $billed['error'] ?? '');
        $this->assertSame($nextMonth->format('Y-m-d'), $billed['generated']['dtNext']);

        $early = $this->probe($orderNo, $this->today(), ['early' => 1]);

        $this->assertArrayNotHasKey('error', $early, $early['error'] ?? '');
        $to = (new \DateTime('last day of +2 months'))->format('j F Y');
        $this->assertSame(
            'Invoice for period ' . $nextMonth->format('j F Y') . ' to ' . $to,
            $early['generated']['comment']
        );
        $inThreeMonths = (new \DateTime('first day of +3 months'))->format('Y-m-d');
        $this->assertSame($inThreeMonths, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
        $invoices = $this->invoiceCount($orderNo);

        $again = $this->probe($orderNo, $this->today(), ['early' => 1]);

        $this->assertSame('SGW_Sales\service\RecurrenceNotDue', $again['errorClass'] ?? null, $again['error'] ?? '');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($inThreeMonths, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    /** Checkpoint C re-review N-3: a never-generated schedule billed early bills from its start. */
    public function testEarlyGenerationOfANeverGeneratedScheduleBillsFromItsStart(): void
    {
        $orderNo = $this->unrecurredOrder();
        $nextMonth = new \DateTime('first day of next month');
        $this->recurrence = $this->monthlyOnThe1st($orderNo, null);
        $this->recurrence->dtStart = $nextMonth->format('Y-m-d');
        $this->recurrence->write();

        $early = $this->probe($orderNo, $this->today(), ['early' => 1]);

        $this->assertArrayNotHasKey('error', $early, $early['error'] ?? '');
        $this->assertStringStartsWith(
            'Invoice for period ' . $nextMonth->format('j F Y') . ' to ',
            $early['generated']['comment']
        );
        $this->assertSame(
            (new \DateTime('first day of +2 months'))->format('Y-m-d'),
            SalesRecurringModel::readByTransNo($orderNo)->dtNext
        );
    }

    /**
     * Coordinator's ruling on Checkpoint C: a late run bills a period that began
     * before the schedule's end, even when today is past the end (December's period
     * on 5 January, for a schedule ending 31 December) - once. A period that begins
     * on or after the end is refused.
     */
    public function testALateRunWithinTheScheduleEndIsAllowedOnceThenRefused(): void
    {
        $orderNo = $this->unrecurredOrder();
        $lastMonth = (new \DateTime('first day of last month'))->format('Y-m-d');
        $end = (new \DateTime('last day of last month'))->format('Y-m-d');
        $thisMonth = (new \DateTime('first day of this month'))->format('Y-m-d');
        $this->recurrence = $this->monthlyOnThe1st($orderNo, $lastMonth, $end);
        $invoices = $this->invoiceCount($orderNo);

        $late = $this->probe($orderNo, $lastMonth);

        $this->assertArrayNotHasKey('error', $late, $late['error'] ?? '');
        $this->assertSame($invoices + 1, $this->invoiceCount($orderNo));
        $this->assertSame($thisMonth, SalesRecurringModel::readByTransNo($orderNo)->dtNext);

        $again = $this->probe($orderNo, $lastMonth);
        $this->assertSame('SGW_Sales\service\RecurrenceNotDue', $again['errorClass'] ?? null, $again['error'] ?? '');
        $after = $this->probe($orderNo, $thisMonth);
        $this->assertSame('SGW_Sales\service\RecurrenceEnded', $after['errorClass'] ?? null, $after['error'] ?? '');
        $this->assertSame($invoices + 1, $this->invoiceCount($orderNo));
    }

    /**
     * Checkpoint C C-2: an order closed without its schedule ending (FrontAccounting's
     * own close, or sgw_sales' page before this release) holds every period sent so
     * far in its lines. It is refused as closed, whatever the date.
     */
    public function testAnOrderClosedWithoutItsScheduleEndingIsRefused(): void
    {
        $orderNo = $this->copyOrder($this->unrecurredOrder());
        $twoMonthsAgo = (new \DateTime('first day of -2 months'))->format('Y-m-d');
        $lastMonth = (new \DateTime('first day of last month'))->format('Y-m-d');
        $thisMonth = (new \DateTime('first day of this month'))->format('Y-m-d');
        $this->recurrence = $this->monthlyOnThe1st($orderNo, $twoMonthsAgo);
        foreach ([$twoMonthsAgo, $lastMonth] as $date) {
            $out = $this->probe($orderNo, $date);
            $this->assertArrayNotHasKey('error', $out, $out['error'] ?? '');
        }
        // close_sales_order() (sales_order_db.inc), and nothing else.
        Anorm::pdo()->exec(
            'UPDATE ' . DB::prefix('sales_order_details') . ' SET quantity=qty_sent'
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        );
        $invoices = $this->invoiceCount($orderNo);

        $out = $this->probe($orderNo, $thisMonth);

        $this->assertSame('SGW_Sales\service\RecurrenceEnded', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertStringContainsString('is closed', $out['error']);
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($thisMonth, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    /** Checkpoint C M-4: customer_delivery.php's "nothing to deliver", as DeliveryService refuses it. */
    public function testAnOrderWithNothingToDeliverIsRefused(): void
    {
        $orderNo = $this->copyOrder($this->unrecurredOrder());
        Anorm::pdo()->exec(
            'UPDATE ' . DB::prefix('sales_order_details') . ' SET quantity=0'
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        );
        $this->recurrence = $this->dueMonthly($orderNo);

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertStringContainsString('nothing to deliver', $out['error']);
        $this->assertSame(0, $this->deliveryCount($orderNo));
        $this->assertNull(SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    /** Checkpoint C M-4: a prepayment order (prepaid terms), as the module's billing refuses it. */
    public function testAPrepaymentOrderIsRefused(): void
    {
        $orderNo = $this->copyOrder($this->unrecurredOrder());
        $prepaid = Anorm::pdo()->query(
            'SELECT terms_indicator FROM ' . DB::prefix('payment_terms') . ' WHERE days_before_due=-1 LIMIT 1'
        )->fetchColumn();
        if ($prepaid === false) {
            $this->markTestSkipped('the loaded dataset has no prepayment terms');
        }
        Anorm::pdo()->exec(
            'UPDATE ' . DB::prefix('sales_orders') . ' SET payment_terms=' . (int) $prepaid . ', prep_amount=1'
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        );
        $this->recurrence = $this->dueMonthly($orderNo);

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertStringContainsString('prepayment', $out['error']);
        $this->assertSame(0, $this->deliveryCount($orderNo));
        $this->assertNull(SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    /** Checkpoint C M-3: customer_delivery.php shows an on-hold customer no form. */
    public function testACustomerOnHoldIsRefusedAndNothingIsWritten(): void
    {
        $orderNo = $this->unrecurredOrder();
        $held = Anorm::pdo()->query(
            'SELECT id FROM ' . DB::prefix('credit_status') . ' WHERE dissallow_invoices=1 LIMIT 1'
        )->fetchColumn();
        $this->assertNotFalse($held, 'the dataset has a credit status that disallows invoices');
        $this->changeRow('debtors_master', 'debtor_no=' . $this->customerOf($orderNo), ['credit_status' => $held]);
        $this->recurrence = $this->dueMonthly($orderNo);
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertStringContainsString('on hold', $out['error']);
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($deliveries, $this->deliveryCount($orderNo));
        $this->assertNull(SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    /** Checkpoint C M-3: FrontAccounting would write with a rate of 1.0 where none is set. */
    public function testACurrencyWithoutARateOnTheDateIsRefusedAndNothingIsWritten(): void
    {
        $orderNo = $this->unrecurredOrder();
        $currency = Anorm::pdo()->query(
            'SELECT curr_abrev FROM ' . DB::prefix('currencies')
            . ' WHERE curr_abrev NOT IN (SELECT curr_code FROM ' . DB::prefix('exchange_rates') . ')'
            . ' AND curr_abrev <> (SELECT value FROM ' . DB::prefix('sys_prefs') . " WHERE name='curr_default')"
            . ' ORDER BY curr_abrev LIMIT 1'
        )->fetchColumn();
        if ($currency === false) {
            $this->markTestSkipped('every currency in the loaded dataset has a rate');
        }
        $this->changeRow('debtors_master', 'debtor_no=' . $this->customerOf($orderNo), ['curr_code' => $currency]);
        $this->recurrence = $this->dueMonthly($orderNo);
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertSame('date', $out['field']);
        $this->assertStringContainsString("no exchange rate for $currency", $out['error']);
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($deliveries, $this->deliveryCount($orderNo));
        $this->assertNull(SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    private function customerOf(int $orderNo): int
    {
        return (int) Anorm::pdo()->query(
            'SELECT debtor_no FROM ' . DB::prefix('sales_orders')
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        )->fetchColumn();
    }
}
