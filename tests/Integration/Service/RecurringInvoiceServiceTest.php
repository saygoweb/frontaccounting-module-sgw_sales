<?php

namespace SGW_Sales\Tests\Integration\Service;

use FA\GraphQL\Fa\FaMessages;
use SGW_Sales\db\DB;
use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\service\GeneratedInvoice;
use SGW_Sales\service\GenerationRefused;
use SGW_Sales\service\RecurrenceEnded;
use SGW_Sales\service\RecurrenceNotDue;
use SGW_Sales\service\RecurrenceNotFound;
use SGW_Sales\service\RecurrenceSchedule;
use SGW_Sales\service\RecurringInvoiceService;
use SGW_Sales\Tests\Integration\FaTestCase;

/**
 * RecurringInvoiceService in-process, on the demo company: what
 * tests/Http/ServiceWithoutAPageTest drives through a probe page, and every check
 * generate() makes. A refusal must leave nothing behind: FrontAccounting's
 * transaction is cancelled. emailInvoice() is left to the HTTP tests: rep107.php
 * includes session.inc, which cannot load beside the GraphQL module's Bootstrap.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurringInvoiceServiceTest extends FaTestCase
{
    /** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> rows changeRow() put back */
    private array $restore = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->restore) as [$table, $where, $old]) {
            $set = implode(', ', array_map(function (string $column): string {
                return "`$column`=:$column";
            }, array_keys($old)));
            $this->pdo()->prepare('UPDATE ' . DB::prefix($table) . " SET $set WHERE $where")->execute($old);
        }
        $this->restore = [];
        parent::tearDown();
    }

    public function testADueOrderIsDeliveredAndInvoicedAndItsScheduleMovesOn(): void
    {
        $orderNo = $this->pricedServiceOrder();
        $recurrence = $this->monthly($orderNo);
        $invoices = $this->documentCount(ST_SALESINVOICE, $orderNo);
        $deliveries = $this->documentCount(ST_CUSTDELIVERY, $orderNo);
        $today = new \DateTime('today');

        $generated = (new RecurringInvoiceService())->generate($orderNo, $today);

        $this->assertInstanceOf(GeneratedInvoice::class, $generated);
        $this->assertSame($invoices + 1, $this->documentCount(ST_SALESINVOICE, $orderNo));
        $this->assertSame($deliveries + 1, $this->documentCount(ST_CUSTDELIVERY, $orderNo));
        $this->assertSame($orderNo, $generated->orderNo);
        $this->assertSame($orderNo, $this->orderOf(ST_CUSTDELIVERY, $generated->deliveryNo));
        $this->assertSame($orderNo, $this->orderOf(ST_SALESINVOICE, $generated->invoiceNo));
        $nextMonth = self::firstOf('next month');
        $this->assertSame($nextMonth, $generated->dtNext);
        $this->assertSame($nextMonth, $this->recurrenceRow($orderNo)['dt_next']);
        $this->assertGreaterThan(self::today(), $generated->dtNext);
        $comment = 'Invoice for period ' . (new \DateTime('first day of this month'))->format('j F Y')
            . ' to ' . (new \DateTime('last day of this month'))->format('j F Y');
        $this->assertSame($comment, $generated->comment);
        $this->assertSame(RecurrenceSchedule::comment($recurrence, $today), $generated->comment);
        $this->assertSame($comment, $this->invoiceComment($generated->invoiceNo));
        $this->assertGlBalanced($generated->deliveryNo, $generated->invoiceNo);
        $this->assertSame(0, $GLOBALS['transaction_level'] ?? 0, 'the transaction is closed');
        $this->assertSame([], FaMessages::errors());
    }

    public function testTheDocumentsAreDatedAsAskedNotToday(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);
        $date = self::firstOf('this month');

        $generated = (new RecurringInvoiceService())->generate($orderNo, new \DateTime($date));

        $this->assertSame($date, $this->dateOf(ST_SALESINVOICE, $generated->invoiceNo));
        $this->assertSame($date, $this->dateOf(ST_CUSTDELIVERY, $generated->deliveryNo));
    }

    public function testRunningTwiceOnTheSameDateBillsOnce(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);
        $service = new RecurringInvoiceService();
        $service->generate($orderNo, new \DateTime('today'));

        $this->assertRefused(RecurrenceNotDue::class, $orderNo, function () use ($service, $orderNo) {
            $service->generate($orderNo, new \DateTime('today'));
        });
    }

    public function testAnOrderNotYetDueIsRefusedUnlessAskedForEarly(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo, self::firstOf('next month'));
        $service = new RecurringInvoiceService();

        $refused = $this->assertRefused(RecurrenceNotDue::class, $orderNo, function () use ($service, $orderNo) {
            $service->generate($orderNo, new \DateTime('today'));
        });
        $this->assertStringContainsString('next due ' . self::firstOf('next month'), $refused->getMessage());

        $invoices = $this->documentCount(ST_SALESINVOICE, $orderNo);
        $service->generate($orderNo, new \DateTime('today'), true);
        $this->assertSame($invoices + 1, $this->documentCount(ST_SALESINVOICE, $orderNo));
    }

    public function testAFailureAfterTheDeliveryRollsEverythingBack(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);
        $service = new class extends RecurringInvoiceService {
            protected function writeInvoice(int $deliveryNo, string $faDate, string $comment): int
            {
                throw new \RuntimeException("the invoice failed after delivery $deliveryNo was written");
            }
        };

        $this->assertRefused(\RuntimeException::class, $orderNo, function () use ($service, $orderNo) {
            $service->generate($orderNo, new \DateTime('today'));
        });
    }

    public function testAnOrderWithoutARecurrenceIsNotFound(): void
    {
        $orderNo = $this->unrecurredOrder();

        $this->assertRefused(RecurrenceNotFound::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
    }

    public function testARecurrenceWhoseOrderIsGoneIsNotFound(): void
    {
        $orderNo = 1000 + (int) $this->pdo()->query(
            'SELECT MAX(order_no) FROM ' . DB::prefix('sales_orders')
        )->fetchColumn();
        $this->monthly($orderNo);

        $this->assertRefused(RecurrenceNotFound::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
    }

    /**
     * @dataProvider endsOnOrBeforeToday
     */
    public function testAScheduleEndedOnOrBeforeTheDateIsRefused(string $end): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo, null, (new \DateTime($end))->format('Y-m-d'));

        $ended = $this->assertRefused(RecurrenceEnded::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
        $this->assertStringContainsString('ended on', $ended->getMessage());
    }

    /** @return array<string, array{0: string}> */
    public function endsOnOrBeforeToday(): array
    {
        return ['on the date' => ['today'], 'before it' => ['yesterday']];
    }

    /**
     * A late run bills a period that began before the end (December's on 5 January),
     * once; a period beginning on or after the end is refused.
     */
    public function testALateRunWithinTheScheduleEndBillsOnceThenIsRefused(): void
    {
        $orderNo = $this->unrecurredOrder();
        $lastMonth = self::firstOf('last month');
        $this->monthly($orderNo, $lastMonth, (new \DateTime('last day of last month'))->format('Y-m-d'));
        $service = new RecurringInvoiceService();

        $late = $service->generate($orderNo, new \DateTime($lastMonth));

        $this->assertSame(self::firstOf('this month'), $late->dtNext);
        $this->assertRefused(RecurrenceNotDue::class, $orderNo, function () use ($service, $orderNo, $lastMonth) {
            $service->generate($orderNo, new \DateTime($lastMonth));
        });
        $this->assertRefused(RecurrenceEnded::class, $orderNo, function () use ($service, $orderNo) {
            $service->generate($orderNo, new \DateTime(self::firstOf('this month')));
        });
    }

    public function testAnOrderClosedThroughCloseOrderIsRefusedWhateverItsEnd(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);

        RecurringInvoiceService::closeOrder($orderNo, self::today());

        $this->assertSame(self::today(), $this->recurrenceRow($orderNo)['dt_end']);
        // Reopened schedule, say: the audit trail's record is reason enough.
        $this->pdo()->prepare('UPDATE ' . DB::prefix('sales_recurring') . ' SET dt_end=NULL WHERE trans_no=?')
            ->execute([$orderNo]);
        $statement = $this->pdo()->prepare(
            'SELECT description FROM ' . DB::prefix('audit_trail') . ' WHERE type=? AND trans_no=? ORDER BY id DESC'
        );
        $statement->execute([ST_SALESORDER, $orderNo]);
        $this->assertSame(RecurringInvoiceService::CLOSED, $statement->fetchColumn());
        $closed = $this->assertRefused(RecurrenceEnded::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'), true);
        });
        $this->assertStringContainsString('is closed', $closed->getMessage());
    }

    /**
     * FrontAccounting's own close (close_sales_order()) sets every line's quantity
     * to what was sent: after more than one delivery, that is a closed order. After
     * one, it is just an order delivered whole once.
     */
    public function testAnOrderClosedByFrontAccountingAfterTwoDeliveriesIsRefused(): void
    {
        $orderNo = $this->copyOrder($this->unrecurredOrder());
        $twoMonthsAgo = self::firstOf('-2 months');
        $lastMonth = self::firstOf('last month');
        $this->monthly($orderNo, $twoMonthsAgo);
        $service = new RecurringInvoiceService();
        $service->generate($orderNo, new \DateTime($twoMonthsAgo));
        $this->assertSame(0, $this->openLines($orderNo), 'one delivery sent every line in full');

        $service->generate($orderNo, new \DateTime($lastMonth));
        $this->pdo()->exec(
            'UPDATE ' . DB::prefix('sales_order_details') . ' SET quantity=qty_sent'
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        );

        $closed = $this->assertRefused(RecurrenceEnded::class, $orderNo, function () use ($service, $orderNo) {
            $service->generate($orderNo, new \DateTime(self::firstOf('this month')));
        });
        $this->assertStringContainsString('is closed', $closed->getMessage());
    }

    /**
     * every is a signed tinyint: 128 and up cannot be stored, so 0 and below are
     * what reach the service.
     *
     * @dataProvider everyOutOfRange
     */
    public function testAnEveryOutside1To127IsRefused(int $every): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);
        $this->pdo()->prepare('UPDATE ' . DB::prefix('sales_recurring') . ' SET every=? WHERE trans_no=?')
            ->execute([$every, $orderNo]);

        $refused = $this->assertRefused(GenerationRefused::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
        $this->assertStringContainsString("'Every' must be from 1 to 127", $refused->getMessage());
    }

    /** @return array<string, array{0: int}> */
    public function everyOutOfRange(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    /**
     * @dataProvider outsideTheFiscalYears
     */
    public function testADateOutsideAnOpenFiscalYearIsRefused(string $dtNext, string $date): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo, $dtNext);
        $this->assertFalse(is_date_in_fiscalyear(sql2date($date)), "$date is outside every open fiscal year");

        $refused = $this->assertRefused(GenerationRefused::class, $orderNo, function () use ($orderNo, $date) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime($date));
        });
        $this->assertSame('date', $refused->field());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public function outsideTheFiscalYears(): array
    {
        return [
            'before the first' => ['1999-12-01', '2000-01-01'],
            'in a closed one' => ['2021-06-01', '2021-06-01'],
            'after the last' => [self::firstOf('+5 years'), self::firstOf('+5 years')],
        ];
    }

    public function testAnOrderWithNothingToDeliverIsRefused(): void
    {
        $orderNo = $this->copyOrder($this->unrecurredOrder());
        $this->pdo()->exec(
            'UPDATE ' . DB::prefix('sales_order_details') . ' SET quantity=0'
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        );
        $this->monthly($orderNo);

        $refused = $this->assertRefused(GenerationRefused::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
        $this->assertStringContainsString('nothing to deliver', $refused->getMessage());
    }

    public function testAPrepaymentOrderIsRefused(): void
    {
        $orderNo = $this->copyOrder($this->unrecurredOrder());
        $prepaid = $this->pdo()->query(
            'SELECT terms_indicator FROM ' . DB::prefix('payment_terms') . ' WHERE days_before_due=-1 LIMIT 1'
        )->fetchColumn();
        $this->assertNotFalse($prepaid, 'the demo company has prepayment terms');
        $this->pdo()->exec(
            'UPDATE ' . DB::prefix('sales_orders') . ' SET payment_terms=' . (int) $prepaid . ', prep_amount=1'
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        );
        $this->monthly($orderNo);

        $refused = $this->assertRefused(GenerationRefused::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
        $this->assertStringContainsString('prepayment order', $refused->getMessage());
    }

    /** On hold: the customer's credit status disallows invoices (credit_status.dissallow_invoices). */
    public function testACustomerOnHoldIsRefused(): void
    {
        $orderNo = $this->unrecurredOrder();
        $held = $this->pdo()->query(
            'SELECT id FROM ' . DB::prefix('credit_status') . ' WHERE dissallow_invoices=1 LIMIT 1'
        )->fetchColumn();
        $this->assertNotFalse($held, 'the demo company has a credit status that disallows invoices');
        $this->changeRow('debtors_master', 'debtor_no=' . $this->customerOf($orderNo), ['credit_status' => $held]);
        $this->monthly($orderNo);

        $refused = $this->assertRefused(GenerationRefused::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
        $this->assertStringContainsString('on hold', $refused->getMessage());
    }

    /** FrontAccounting would write with a rate of 1.0 where none is set. */
    public function testACustomerCurrencyWithoutARateIsRefused(): void
    {
        $orderNo = $this->unrecurredOrder();
        $currency = $this->pdo()->query(
            'SELECT curr_abrev FROM ' . DB::prefix('currencies')
            . ' WHERE curr_abrev NOT IN (SELECT curr_code FROM ' . DB::prefix('exchange_rates') . ')'
            . ' AND curr_abrev <> (SELECT value FROM ' . DB::prefix('sys_prefs') . " WHERE name='curr_default')"
            . ' ORDER BY curr_abrev LIMIT 1'
        )->fetchColumn();
        $this->assertNotFalse($currency, 'the demo company has a currency without rates');
        $this->changeRow('debtors_master', 'debtor_no=' . $this->customerOf($orderNo), ['curr_code' => $currency]);
        $this->monthly($orderNo);

        $refused = $this->assertRefused(GenerationRefused::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
        $this->assertSame('date', $refused->field());
        $this->assertStringContainsString(
            "no exchange rate for $currency as of " . self::today(),
            $refused->getMessage()
        );
    }

    /** Cart::check_qoh(), as customer_delivery.php checks it where negative stock is not allowed. */
    public function testAnOrderTheStockCannotCoverIsRefused(): void
    {
        $negative = $this->pdo()->query(
            'SELECT value FROM ' . DB::prefix('sys_prefs') . " WHERE name='allow_negative_stock'"
        )->fetchColumn();
        $this->assertSame('0', (string) $negative, 'the demo company does not allow negative stock');
        $stocked = $this->pdo()->query(
            'SELECT line.order_no FROM ' . DB::prefix('sales_order_details') . ' AS line'
            . ' JOIN ' . DB::prefix('stock_master') . ' AS item ON item.stock_id=line.stk_code'
            . " WHERE line.trans_type=" . ST_SALESORDER . " AND item.mb_flag IN ('B', 'M')"
            . ' ORDER BY line.order_no LIMIT 1'
        )->fetchColumn();
        $this->assertNotFalse($stocked, 'the demo company has an order for a stock item');
        $orderNo = $this->copyOrder((int) $stocked);
        $this->pdo()->exec(
            'UPDATE ' . DB::prefix('sales_order_details') . ' AS line'
            . ' JOIN ' . DB::prefix('stock_master') . ' AS item ON item.stock_id=line.stk_code'
            . " SET line.quantity=1000000 WHERE item.mb_flag IN ('B', 'M')"
            . ' AND line.trans_type=' . ST_SALESORDER . " AND line.order_no=$orderNo"
        );
        $this->monthly($orderNo);

        $refused = $this->assertRefused(GenerationRefused::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
        $this->assertStringContainsString('insufficient quantity', $refused->getMessage());
        $this->assertGreaterThan(1, count($refused->messages()), 'the items short are listed');
    }

    /**
     * Not reachable from a valid schedule (every is checked first, and a period of
     * a month or a year always moves on): a repeats value the schedule does not know,
     * which MySQL stores as '' outside strict mode.
     */
    public function testAScheduleThatDoesNotMoveOnIsRefused(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);
        $mode = $this->pdo()->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->pdo()->exec("SET SESSION sql_mode=''");
        try {
            $this->pdo()->prepare('UPDATE ' . DB::prefix('sales_recurring') . " SET repeats='week' WHERE trans_no=?")
                ->execute([$orderNo]);
        } finally {
            $this->pdo()->prepare('SET SESSION sql_mode=?')->execute([$mode]);
        }
        $this->assertSame('', $this->recurrenceRow($orderNo)['repeats']);

        $refused = $this->assertRefused(GenerationRefused::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'));
        });
        $this->assertStringContainsString('does not move on', $refused->getMessage());
    }

    /**
     * Early generation bills the next due period - the one starting at dt_next -
     * once: asked again, a period starting after the date is billed already.
     */
    public function testEarlyGenerationBillsTheNextDuePeriodOnceOnly(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);
        $service = new RecurringInvoiceService();
        $due = $service->generate($orderNo, new \DateTime('today'), true);
        $this->assertSame(self::firstOf('next month'), $due->dtNext, 'a due order bills its own period');

        $early = $service->generate($orderNo, new \DateTime('today'), true);

        $nextMonth = new \DateTime('first day of next month');
        $this->assertSame(
            'Invoice for period ' . $nextMonth->format('j F Y') . ' to '
            . (new \DateTime('last day of next month'))->format('j F Y'),
            $early->comment
        );
        $this->assertSame(self::firstOf('+2 months'), $early->dtNext);
        $this->assertSame(self::today(), $this->dateOf(ST_SALESINVOICE, $early->invoiceNo), 'dated as asked');
        $again = $this->assertRefused(RecurrenceNotDue::class, $orderNo, function () use ($service, $orderNo) {
            $service->generate($orderNo, new \DateTime('today'), true);
        });
        $this->assertStringContainsString('already invoiced ahead', $again->getMessage());
    }

    public function testEarlyGenerationIsRefusedWhenAPeriodAfterTheDateIsBilledAlready(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo, self::firstOf('+2 months'));

        $refused = $this->assertRefused(RecurrenceNotDue::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'), true);
        });
        $this->assertStringContainsString(
            'already invoiced ahead, for the period from ' . self::firstOf('next month'),
            $refused->getMessage()
        );
    }

    /** Every two months, billed last month: early billing starts at dt_next, not at the date. */
    public function testEarlyGenerationEveryTwoMonthsBillsFromDtNextNotFromTheDate(): void
    {
        $orderNo = $this->unrecurredOrder();
        $lastMonth = self::firstOf('last month');
        $recurrence = $this->monthly($orderNo, $lastMonth);
        $recurrence->every = 2;
        $recurrence->write();
        $service = new RecurringInvoiceService();
        $billed = $service->generate($orderNo, new \DateTime($lastMonth));
        $this->assertSame(self::firstOf('next month'), $billed->dtNext);

        $early = $service->generate($orderNo, new \DateTime('today'), true);

        $this->assertSame(
            'Invoice for period ' . (new \DateTime('first day of next month'))->format('j F Y')
            . ' to ' . (new \DateTime('last day of +2 months'))->format('j F Y'),
            $early->comment
        );
        $this->assertSame(self::firstOf('+3 months'), $early->dtNext);
        $this->assertRefused(RecurrenceNotDue::class, $orderNo, function () use ($service, $orderNo) {
            $service->generate($orderNo, new \DateTime('today'), true);
        });
    }

    public function testEarlyGenerationOfANeverGeneratedScheduleBillsFromItsStart(): void
    {
        $orderNo = $this->unrecurredOrder();
        $recurrence = $this->monthly($orderNo);
        $recurrence->dtStart = self::firstOf('next month');
        $recurrence->write();

        $early = (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'), true);

        $this->assertStringStartsWith(
            'Invoice for period ' . (new \DateTime('first day of next month'))->format('j F Y') . ' to ',
            $early->comment
        );
        $this->assertSame(self::firstOf('+2 months'), $early->dtNext);
        $this->assertSame(self::firstOf('+2 months'), $this->recurrenceRow($orderNo)['dt_next']);
    }

    /**
     * The period billed early starts on or after the end: a run on its own date
     * would refuse it as ended, and so does the early one.
     *
     * @dataProvider periodsAtOrAfterTheEnd
     */
    public function testEarlyGenerationOfAPeriodAtOrAfterTheEndIsRefused(?string $dtNext, string $dtEnd): void
    {
        $orderNo = $this->unrecurredOrder();
        $recurrence = $this->monthly($orderNo, $dtNext, $dtEnd);
        if ($dtNext === null) {
            $recurrence->dtStart = $dtEnd;
            $recurrence->write();
        }

        $ended = $this->assertRefused(RecurrenceEnded::class, $orderNo, function () use ($orderNo) {
            (new RecurringInvoiceService())->generate($orderNo, new \DateTime('today'), true);
        });
        $this->assertStringContainsString('no period left to bill', $ended->getMessage());
    }

    /** @return array<string, array{0: string|null, 1: string}> */
    public function periodsAtOrAfterTheEnd(): array
    {
        return [
            'starting at the end' => [self::firstOf('next month'), self::firstOf('next month')],
            'starting after the end' => [self::firstOf('+2 months'), self::firstOf('next month')],
            'never generated, ending at its start' => [null, self::firstOf('next month')],
        ];
    }

    public function testEndScheduleOnlyEverMovesTheEndEarlier(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);
        $inAMonth = (new \DateTime('+1 month'))->format('Y-m-d');
        $inAYear = (new \DateTime('+1 year'))->format('Y-m-d');

        RecurringInvoiceService::endSchedule($orderNo, $inAYear);
        $this->assertSame($inAYear, $this->recurrenceRow($orderNo)['dt_end'], 'an open schedule gets an end');

        RecurringInvoiceService::endSchedule($orderNo, $inAMonth);
        $this->assertSame($inAMonth, $this->recurrenceRow($orderNo)['dt_end'], 'an earlier end replaces it');

        RecurringInvoiceService::endSchedule($orderNo, $inAYear);
        $this->assertSame($inAMonth, $this->recurrenceRow($orderNo)['dt_end'], 'a later one does not');
    }

    public function testDueListsWhatIsDueAndWithAllWhatHasNotEnded(): void
    {
        $due = $this->copyOrder($this->unrecurredOrder());
        $later = $this->copyOrder($this->unrecurredOrder());
        $ended = $this->copyOrder($this->unrecurredOrder());
        $this->monthly($due);
        $this->monthly($later, self::firstOf('next month'));
        $this->monthly($ended, null, self::today());
        $service = new RecurringInvoiceService();

        $listed = $this->orderNumbers($service->due(new \DateTime('today')));
        $this->assertContains($due, $listed);
        $this->assertNotContains($later, $listed);
        $this->assertNotContains($ended, $listed);

        $all = $this->orderNumbers($service->due(new \DateTime('today'), true, $this->pdo()));
        $this->assertContains($due, $all);
        $this->assertContains($later, $all);
        $this->assertNotContains($ended, $all);

        $this->assertSame([], $service->due(new \DateTime('2000-01-01')), 'nothing had started in 2000');
    }

    /**
     * Run $generate, expecting $class, and check that nothing was written: no
     * document, no GL, no stock move, the schedule where it was, the transaction
     * closed.
     *
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return T
     */
    private function assertRefused(string $class, int $orderNo, callable $generate): \Throwable
    {
        $before = $this->snapshot($orderNo);
        try {
            $generate();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, get_class($e) . ': ' . $e->getMessage());
            $this->assertSame($before, $this->snapshot($orderNo), 'a refused generation writes nothing');

            return $e;
        }
        $this->fail("expected $class");
    }

    /** @return array<string, mixed> */
    private function snapshot(int $orderNo): array
    {
        $counts = [];
        foreach (['debtor_trans', 'gl_trans', 'stock_moves', 'comments', 'sales_recurring'] as $table) {
            $counts[$table] = (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . DB::prefix($table))->fetchColumn();
        }
        $row = $this->recurrenceRow($orderNo);
        $counts['dt_next'] = $row ? $row['dt_next'] : null;
        $counts['transaction_level'] = $GLOBALS['transaction_level'] ?? 0;

        return $counts;
    }

    /** The GL of a delivery and its invoice sums to zero, as every FrontAccounting posting must. */
    private function assertGlBalanced(int $deliveryNo, int $invoiceNo): void
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*), ROUND(SUM(amount), 2) FROM ' . DB::prefix('gl_trans')
            . ' WHERE (type=' . ST_CUSTDELIVERY . ' AND type_no=?) OR (type=' . ST_SALESINVOICE . ' AND type_no=?)'
        );
        $statement->execute([$deliveryNo, $invoiceNo]);
        [$count, $sum] = $statement->fetch(\PDO::FETCH_NUM);
        $this->assertGreaterThan(0, (int) $count, 'the documents posted to the GL');
        $this->assertEquals(0.0, (float) $sum, 'GL not balanced');
    }

    /**
     * A new sales order copied from $orderNo, nothing delivered on it yet, for a
     * test that changes the order itself. FaRows puts sales_orders and its lines
     * back whole.
     */
    private function copyOrder(int $orderNo): int
    {
        $new = 1 + (int) $this->pdo()->query(
            'SELECT MAX(order_no) FROM ' . DB::prefix('sales_orders') . ' WHERE trans_type=' . ST_SALESORDER
        )->fetchColumn();
        $header = $this->pdo()->query(
            'SELECT * FROM ' . DB::prefix('sales_orders')
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        )->fetch(\PDO::FETCH_ASSOC);
        $header['order_no'] = $new;
        $header['reference'] = sprintf('9%04d/%s', $new, substr((string) $header['ord_date'], 0, 4));
        $header['version'] = 0;
        $this->insert('sales_orders', $header);
        $lines = $this->pdo()->query(
            'SELECT * FROM ' . DB::prefix('sales_order_details') . ' WHERE trans_type=' . ST_SALESORDER
            . " AND order_no=$orderNo ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($lines as $line) {
            unset($line['id']);
            $line['order_no'] = $new;
            $line['qty_sent'] = 0;
            $line['invoiced'] = 0;
            $this->insert('sales_order_details', $line);
        }

        return $new;
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->pdo()->prepare(
            'INSERT INTO ' . DB::prefix($table) . ' (`' . implode('`, `', $columns) . '`)'
            . ' VALUES (:' . implode(', :', $columns) . ')'
        )->execute($row);
    }

    /**
     * Change one existing row's $values for the length of the test: FaRows does
     * not put back updated rows outside its own few tables, tearDown does.
     *
     * @param array<string, mixed> $values
     */
    private function changeRow(string $table, string $where, array $values): void
    {
        $old = $this->pdo()->query(
            'SELECT `' . implode('`, `', array_keys($values)) . '` FROM ' . DB::prefix($table) . " WHERE $where"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($old, "no $table row where $where");
        $this->restore[] = [$table, $where, $old];
        $set = implode(', ', array_map(function (string $column): string {
            return "`$column`=:$column";
        }, array_keys($values)));
        $this->pdo()->prepare('UPDATE ' . DB::prefix($table) . " SET $set WHERE $where")->execute($values);
    }

    private function customerOf(int $orderNo): int
    {
        return (int) $this->pdo()->query(
            'SELECT debtor_no FROM ' . DB::prefix('sales_orders')
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        )->fetchColumn();
    }

    /** The sales order a document was raised from. */
    private function orderOf(int $type, int $transNo): int
    {
        return (int) $this->documentColumn('order_', $type, $transNo);
    }

    private function dateOf(int $type, int $transNo): string
    {
        return (string) $this->documentColumn('tran_date', $type, $transNo);
    }

    /** @return mixed */
    private function documentColumn(string $column, int $type, int $transNo)
    {
        $statement = $this->pdo()->prepare(
            "SELECT `$column` FROM " . DB::prefix('debtor_trans') . ' WHERE type=? AND trans_no=?'
        );
        $statement->execute([$type, $transNo]);
        $value = $statement->fetchColumn();
        $this->assertNotFalse($value, "no document of type $type numbered $transNo");

        return $value;
    }

    private function invoiceComment(int $invoiceNo): string
    {
        $statement = $this->pdo()->prepare(
            'SELECT memo_ FROM ' . DB::prefix('comments') . ' WHERE type=? AND id=?'
        );
        $statement->execute([ST_SALESINVOICE, $invoiceNo]);

        return (string) $statement->fetchColumn();
    }

    /** How many of the order's lines have a quantity other than what was sent. */
    private function openLines(int $orderNo): int
    {
        return (int) $this->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB::prefix('sales_order_details')
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo AND quantity <> qty_sent"
        )->fetchColumn();
    }

    /**
     * @param GenerateRecurringModel[] $models
     * @return int[]
     */
    private function orderNumbers(array $models): array
    {
        return array_map(function (GenerateRecurringModel $model): int {
            return (int) $model->orderNo;
        }, $models);
    }

    /**
     * A demo order with no recurrence, of service lines only, worth something: its
     * invoice posts to the GL (unrecurredOrder()'s may be priced at nothing).
     */
    private function pricedServiceOrder(): int
    {
        $orderNo = $this->pdo()->query(
            'SELECT so.order_no FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' JOIN ' . DB::prefix('sales_order_details') . ' AS line'
            . ' ON line.order_no=so.order_no AND line.trans_type=so.trans_type'
            . ' JOIN ' . DB::prefix('stock_master') . ' AS item ON item.stock_id=line.stk_code'
            . ' WHERE so.trans_type=' . ST_SALESORDER
            . ' AND so.order_no NOT IN (SELECT trans_no FROM ' . DB::prefix('sales_recurring') . ')'
            . " GROUP BY so.order_no HAVING SUM(item.mb_flag IN ('B', 'M'))=0"
            . ' AND SUM(line.quantity * line.unit_price) > 0 ORDER BY so.order_no DESC LIMIT 1'
        )->fetchColumn();
        $this->assertNotFalse($orderNo, 'the demo company has a priced order of services only');

        return (int) $orderNo;
    }

    /** The 1st of the month $relative names ('next month', '+2 months'), Y-m-d. */
    private static function firstOf(string $relative): string
    {
        return (new \DateTime("first day of $relative"))->format('Y-m-d');
    }
}
