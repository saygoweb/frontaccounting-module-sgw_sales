<?php

namespace SGW_Sales\Tests\Db;

use SGW_Sales\db\SalesRecurringModel;
use SGW_Sales\service\RecurringInvoiceService;

/**
 * due(), against the database through Anorm, rolled back. generate() writes on
 * FrontAccounting's own connection inside its transaction, which this suite's
 * rolled-back PDO cannot see; tests/Http/ServiceWithoutAPageTest covers it.
 */
class RecurringInvoiceServiceTest extends DbTestCase
{
    private function recur(
        ?string $dtNext,
        ?string $dtEnd = null,
        string $dtStart = '2016-04-01',
        ?int $orderNo = null
    ): int {
        $orderNo = $orderNo ?? (int) $this->anySalesOrder()['order_no'];
        $m = new SalesRecurringModel();
        $m->transNo = $orderNo;
        $m->dtStart = $dtStart;
        $m->dtEnd = $dtEnd;
        $m->dtNext = $dtNext;
        $m->auto = 0;
        $m->repeats = SalesRecurringModel::REPEAT_MONTHLY;
        $m->every = 1;
        $m->occur = '21';
        $m->write();
        return $orderNo;
    }

    /** @return int[] */
    private function listed(\DateTimeInterface $asOf, bool $all = false): array
    {
        $numbers = [];
        foreach ((new RecurringInvoiceService())->due($asOf, $all, $this->pdo) as $model) {
            $numbers[] = (int) $model->orderNo;
        }
        return $numbers;
    }

    public function testDueListsWhatIsDueOnTheDateGiven(): void
    {
        $due = $this->recur('2017-09-21');

        $this->assertContains($due, $this->listed(new \DateTime('2017-09-21')));
        $this->assertNotContains($due, $this->listed(new \DateTime('2017-09-20')));
        $this->assertContains($due, $this->listed(new \DateTime('2017-09-20'), true), 'all lists what is not yet due');
    }

    public function testANeverGeneratedScheduleIsDueOnceItHasStarted(): void
    {
        $orderNo = $this->recur(null, null, '2017-09-21');

        $this->assertNotContains($orderNo, $this->listed(new \DateTime('2017-09-20')), 'not before its start');
        $this->assertContains($orderNo, $this->listed(new \DateTime('2017-09-21')));
    }

    public function testAnEndedScheduleIsNotListed(): void
    {
        $orderNo = $this->recur('2017-09-21', '2017-09-21');

        $this->assertNotContains($orderNo, $this->listed(new \DateTime('2017-09-21')));
        $this->assertNotContains($orderNo, $this->listed(new \DateTime('2017-09-21'), true));
    }

    public function testDueCarriesTheCustomerBranchAndCustomerReference(): void
    {
        $order = $this->anySalesOrder();
        $this->recur('2017-09-21', null, '2016-04-01', (int) $order['order_no']);
        $row = $this->pdo->query(
            'SELECT debtor_no, branch_code, customer_ref FROM ' . $this->prefix . 'sales_orders'
            . ' WHERE trans_type = 30 AND order_no = ' . (int) $order['order_no']
        )->fetch(\PDO::FETCH_ASSOC);

        $found = null;
        foreach ((new RecurringInvoiceService())->due(new \DateTime('2017-09-21'), false, $this->pdo) as $model) {
            if ((int) $model->orderNo === (int) $order['order_no']) {
                $found = $model;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame((string) $row['debtor_no'], (string) $found->debtorNo);
        $this->assertSame((string) $row['branch_code'], (string) $found->branchCode);
        $this->assertSame((string) $row['customer_ref'], (string) $found->customerRef);
    }

    public function testDueListsOnlySalesOrders(): void
    {
        // A schedule whose trans_no is a quotation's number and no sales order's:
        // the join on sales_orders alone would pick up the quotation.
        $quotation = $this->pdo->query(
            'SELECT q.order_no FROM ' . $this->prefix . 'sales_orders q WHERE q.trans_type = 32'
            . ' AND q.order_no NOT IN (SELECT order_no FROM ' . $this->prefix . 'sales_orders WHERE trans_type = 30)'
            . ' AND q.order_no NOT IN (SELECT trans_no FROM ' . $this->prefix . 'sales_recurring)'
            . ' ORDER BY q.order_no LIMIT 1'
        )->fetchColumn();
        if (!$quotation) {
            // None in the dataset: make one (rolled back), for a real customer, so
            // only its trans_type keeps it out of the list.
            $quotation = 1 + (int) $this->pdo->query(
                'SELECT MAX(order_no) FROM ' . $this->prefix . 'sales_orders'
            )->fetchColumn();
            $this->pdo->prepare(
                'INSERT INTO ' . $this->prefix . 'sales_orders'
                . ' (order_no, trans_type, debtor_no, reference, customer_ref, ord_date, delivery_address, deliver_to)'
                . " VALUES (:no, 32, :debtor, 'test quotation', '', '2016-04-01', '', '')"
            )->execute([':no' => $quotation, ':debtor' => $this->anySalesOrder()['debtor_no']]);
        }
        $this->recur('2017-09-21', null, '2016-04-01', (int) $quotation);

        $this->assertNotContains((int) $quotation, $this->listed(new \DateTime('2017-09-21'), true));
    }
}
