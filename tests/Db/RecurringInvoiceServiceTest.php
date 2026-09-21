<?php

namespace SGW_Sales\Tests\Db;

use SGW_Sales\db\SalesRecurringModel;
use SGW_Sales\service\GeneratedInvoice;
use SGW_Sales\service\RecurrenceEnded;
use SGW_Sales\service\RecurrenceNotFound;
use SGW_Sales\service\RecurringInvoiceService;

/**
 * The service with FrontAccounting cut away: the two methods that reach into it
 * (Cart, and the invoice report) are replaced, and what is left is what the
 * service decides - which recurrence, what comment, what next date, in what
 * order. tests/Http/GenerateInvoiceTest runs the same thing with them in place.
 */
class RecurringInvoiceServiceTest extends DbTestCase
{
    /** @var RecurringInvoiceService */
    private $service;

    /** @var array<int, array> what the service asked of FrontAccounting, in order */
    private $calls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
        $calls = &$this->calls;
        $this->service = new class ($calls) extends RecurringInvoiceService {
            private $calls;

            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function generateInvoice($orderNo, $comment)
            {
                $this->calls[] = ['invoice', $orderNo, $comment, SalesRecurringModel::findByTransNo($orderNo)->dtNext];
                return 4242;
            }

            public function emailInvoice($invoiceNo)
            {
                $this->calls[] = ['email', $invoiceNo, null, null];
            }
        };
    }

    private function recur(string $repeats, int $every, string $occur, ?string $dtNext, ?string $dtEnd = null): int
    {
        $order = $this->anySalesOrder();
        $m = new SalesRecurringModel();
        $m->transNo = $order['order_no'];
        $m->dtStart = '2016-04-01';
        $m->dtEnd = $dtEnd;
        $m->dtNext = $dtNext;
        $m->auto = 0;
        $m->repeats = $repeats;
        $m->every = $every;
        $m->occur = $occur;
        $m->write();
        return (int) $order['order_no'];
    }

    public function testGenerateInvoicesEmailsAndMovesTheRecurrenceOn(): void
    {
        $orderNo = $this->recur(SalesRecurringModel::REPEAT_YEARLY, 1, '04-06', '2017-04-06');

        $result = $this->service->generate($orderNo, true, new \DateTime('2017-04-06'));

        $this->assertInstanceOf(GeneratedInvoice::class, $result);
        $this->assertSame($orderNo, $result->orderNo);
        $this->assertSame(4242, $result->invoiceNo);
        $this->assertSame('Invoice for period 1 April 2017 to 31 March 2018', $result->comment);
        $this->assertSame('2018-04-06', $result->dtNext);
        $this->assertTrue($result->emailed);

        $this->assertSame('2018-04-06', SalesRecurringModel::findByTransNo($orderNo)->dtNext);
        $this->assertSame(
            [
                ['invoice', $orderNo, 'Invoice for period 1 April 2017 to 31 March 2018', '2017-04-06'],
                ['email', 4242, null, null],
            ],
            $this->calls
        );
    }

    public function testRecurrenceHasMovedOnBeforeTheInvoiceIsEmailed(): void
    {
        $orderNo = $this->recur(SalesRecurringModel::REPEAT_MONTHLY, 2, '21', null);
        $service = new class extends RecurringInvoiceService {
            public function generateInvoice($orderNo, $comment)
            {
                return 7;
            }

            public function emailInvoice($invoiceNo)
            {
                throw new \RuntimeException('the mail server is down');
            }
        };

        try {
            $service->generate($orderNo, true, new \DateTime('2017-09-21'));
            $this->fail('the mail failure should have surfaced');
        } catch (\RuntimeException $e) {
            $this->assertSame('the mail server is down', $e->getMessage());
        }

        // Invoiced, so no longer due - whatever became of the email.
        $this->assertSame('2017-11-21', SalesRecurringModel::findByTransNo($orderNo)->dtNext);
    }

    public function testGenerateWithoutEmail(): void
    {
        $orderNo = $this->recur(SalesRecurringModel::REPEAT_MONTHLY, 1, '21', '2017-09-21');

        $result = $this->service->generate($orderNo, false, new \DateTime('2017-09-21'));

        $this->assertFalse($result->emailed);
        $this->assertSame('2017-10-21', $result->dtNext);
        $this->assertSame(['invoice'], array_column($this->calls, 0));
    }

    public function testOrderNotYetDueIsStillInvoiced(): void
    {
        $orderNo = $this->recur(SalesRecurringModel::REPEAT_MONTHLY, 1, '21', '2999-01-21');

        $result = $this->service->generate($orderNo, false, new \DateTime('2017-09-21'));

        $this->assertSame(4242, $result->invoiceNo);
    }

    public function testOrderWithoutARecurrenceIsRefused(): void
    {
        $order = $this->anySalesOrder();

        try {
            $this->service->generate((int) $order['order_no']);
            $this->fail('expected RecurrenceNotFound');
        } catch (RecurrenceNotFound $e) {
            $this->assertSame([], $this->calls);
        }
    }

    public function testEndedRecurrenceIsRefusedAndNothingIsInvoiced(): void
    {
        $orderNo = $this->recur(SalesRecurringModel::REPEAT_MONTHLY, 1, '21', '2017-09-21', '2017-09-21');

        try {
            $this->service->generate($orderNo, true, new \DateTime('2017-09-21'));
            $this->fail('expected RecurrenceEnded');
        } catch (RecurrenceEnded $e) {
            $this->assertSame([], $this->calls);
            $this->assertSame('2017-09-21', SalesRecurringModel::findByTransNo($orderNo)->dtNext);
        }
    }

    public function testRecurrenceEndingTomorrowIsInvoiced(): void
    {
        $orderNo = $this->recur(SalesRecurringModel::REPEAT_MONTHLY, 1, '21', '2017-09-21', '2017-09-22');

        $result = $this->service->generate($orderNo, false, new \DateTime('2017-09-21'));

        $this->assertSame(4242, $result->invoiceNo);
    }

    public function testDueListsWhatIsDue(): void
    {
        $due = $this->recur(SalesRecurringModel::REPEAT_MONTHLY, 1, '21', '2016-09-21');
        $later = $this->recur(SalesRecurringModel::REPEAT_MONTHLY, 1, '21', '2999-01-21');

        $listed = [];
        foreach ($this->service->due() as $model) {
            $listed[] = (int) $model->orderNo;
        }
        $this->assertContains($due, $listed);
        $this->assertNotContains($later, $listed);

        $all = [];
        foreach ($this->service->due(true) as $model) {
            $all[] = (int) $model->orderNo;
        }
        $this->assertContains($later, $all);
    }
}
