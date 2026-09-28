<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Tests\Support\MailCatcher;
use SGW_Sales\GraphQL\RecurringGeneration;
use SGW_Sales\service\GeneratedInvoice;
use SGW_Sales\service\RecurringInvoiceService;
use SGW_Sales\Tests\GraphQL\RecurringGenerationTestCase;

/**
 * recurringGenerate (Release 4 spec §4.2): items independent, retry-safe, emailed
 * after each item commits.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurringGenerateTest extends RecurringGenerationTestCase
{
    public function testADueOrderIsDeliveredInvoicedAndMovedOn(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result['errors'] ?? null));
        $item = $result['data']['recurringGenerate'][0];
        $this->assertNull($item['error']);
        $this->assertNull($item['email']);
        $this->assertSame((string) $orderNo, $item['orderId']);
        $this->assertNotNull($item['invoiceId']);
        $this->assertNotNull($item['deliveryId']);
        $next = (new \DateTime('first day of next month'))->format('Y-m-d');
        $this->assertSame($next, $item['next']);
        $this->assertSame($next, $this->dtNext($orderNo));
        $this->assertSame(1, $this->invoicesFor($orderNo));
        $this->assertGlBalanced(13, (int) $item['deliveryId']);
        $this->assertGlBalanced(10, (int) $item['invoiceId']);

        $read = $this->graphql(
            'query ($s: String) { salesOrderList(query: {selector: $s}) { id recurring { next } } }',
            ['s' => json_encode(['id' => (string) $orderNo])]
        );
        $this->assertArrayNotHasKey('errors', $read, (string) json_encode($read['errors'] ?? null));
        $this->assertSame($next, $read['data']['salesOrderList'][0]['recurring']['next']);
    }

    public function testARetryBillsNothingTwice(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $first = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);
        $this->assertNull($first['data']['recurringGenerate'][0]['error']);

        $again = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $this->assertSame('NOT_DUE', $again['data']['recurringGenerate'][0]['error']['code']);
        $this->assertNull($again['data']['recurringGenerate'][0]['invoiceId']);
        $this->assertSame(1, $this->invoicesFor($orderNo));
    }

    public function testItemsAreIndependent(): void
    {
        $due = $this->recurringOrder(date('Y-m-01'), 1);
        $notYet = $this->recurringOrder((new \DateTime('first day of next month'))->format('Y-m-d'), 1);
        $ended = $this->recurringOrder(date('Y-m-01'), 1, date('Y-m-d'));

        $result = $this->generate([
            ['orderId' => (string) $due, 'date' => date('Y-m-d')],
            ['orderId' => '999999', 'date' => date('Y-m-d')],
            ['orderId' => (string) $notYet, 'date' => date('Y-m-d')],
            ['orderId' => (string) $ended, 'date' => date('Y-m-d')],
            ['orderId' => 'x1', 'date' => date('Y-m-d')],
        ]);

        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result['errors'] ?? null));
        $items = $result['data']['recurringGenerate'];
        $this->assertCount(5, $items);
        $this->assertNull($items[0]['error']);
        $this->assertSame('NOT_FOUND', $items[1]['error']['code']);
        $this->assertSame('NOT_DUE', $items[2]['error']['code']);
        $this->assertSame('ENDED', $items[3]['error']['code']);
        $this->assertSame(['BAD_INPUT', 'orderId'], [$items[4]['error']['code'], $items[4]['error']['field']]);
        $this->assertSame(1, $this->invoicesFor($due), 'the first item committed whatever came after');
        $this->assertSame(0, $this->invoicesFor($notYet));
        $this->assertSame(0, $this->invoicesFor($ended));
    }

    public function testADateOutsideTheFiscalYearWritesNothing(): void
    {
        $orderNo = $this->recurringOrder('1999-12-01', 1);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => '2000-01-01']]);

        $error = $result['data']['recurringGenerate'][0]['error'];
        $this->assertSame(['BAD_INPUT', 'date'], [$error['code'], $error['field']]);
        $this->assertSame(0, $this->invoicesFor($orderNo));
        $this->assertNull($this->dtNext($orderNo));
    }

    public function testEmailIsSentAfterTheItemCommits(): void
    {
        if (!MailCatcher::available()) {
            $this->markTestSkipped('The stack mail catcher is not installed (docker/fa-graphql up --build).');
        }
        $customer = $this->graphql(
            'mutation ($i: [CustomerCreateInput!]!) { customerCreate(input: $i) { id branches { id } } }',
            ['i' => [[
                'name' => 'Recurring Mail Customer',
                'ref' => $this->prefix . 'rm',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
                'branch' => [
                    'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                    'locationId' => 'DEF', 'shipperId' => '1',
                ],
                'contact' => ['email' => 'gqlt-recurring@example.com'],
            ]]]
        );
        $this->assertArrayNotHasKey('errors', $customer, (string) json_encode($customer['errors'] ?? null));
        $customerId = (int) $customer['data']['customerCreate'][0]['id'];
        $branchId = (int) $customer['data']['customerCreate'][0]['branches'][0]['id'];
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1, null, $customerId, $branchId);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d'), 'email' => true]]);

        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result['errors'] ?? null));
        $item = $result['data']['recurringGenerate'][0];
        $this->assertNull($item['error']);
        $this->assertTrue($item['email']['sent'], implode("\n", $item['email']['messages']));
        $this->assertSame('gqlt-recurring@example.com', $item['email']['recipient']);
        $this->assertSame($item['invoiceId'], $item['email']['id']);
        $new = MailCatcher::newSince($this->mailBefore);
        $this->assertCount(1, $new);
        $this->assertMatchesRegularExpression(
            '/^To: .*gqlt-recurring@example\.com/m',
            (string) file_get_contents($new[0])
        );
    }

    public function testGeneratingNeedsDeliveryAndInvoiceAreas(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $this->enterAs('sgwpanel'); // 3073, 3074, 3075: no 3076 or 3077

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'] ?? null);
        $this->assertSame(0, $this->invoicesFor($orderNo));
    }

    /**
     * Checkpoint C C-1, the review's reproduction: a schedule with every = 0 and one
     * call with two items for it. Both items billed the same period, and dt_next
     * moved backwards. Now neither bills, and dt_next does not move.
     */
    public function testAScheduleThatWouldNotMoveOnIsRefusedEvenTwiceInOneCall(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $this->pdo()->prepare('UPDATE 0_sales_recurring SET every = 0 WHERE trans_no = ?')->execute([$orderNo]);
        $item = ['orderId' => (string) $orderNo, 'date' => date('Y-m-d')];

        $result = $this->generate([$item, $item]);

        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result['errors'] ?? null));
        foreach ($result['data']['recurringGenerate'] as $i => $generated) {
            $this->assertSame('FA_REJECTED', $generated['error']['code'] ?? null, "item $i");
            $this->assertStringContainsString("'Every' must be from 1 to 127", $generated['error']['message']);
            $this->assertNull($generated['invoiceId']);
        }
        $this->assertSame(0, $this->invoicesFor($orderNo));
        $this->assertSame(0, $this->deliveriesFor($orderNo));
        $this->assertNull($this->dtNext($orderNo));
    }

    /**
     * Checkpoint C C-2: salesOrderDelete closes a delivered order (each line becomes
     * what was sent: two periods here) and ends its schedule today. A billing run
     * dated before today found the schedule not yet ended and billed the closed
     * order at the inflated quantity.
     */
    public function testAnOrderClosedThroughTheApiIsNotBilledAgainWhateverTheDate(): void
    {
        $twoMonthsAgo = (new \DateTime('first day of -2 months'))->format('Y-m-d');
        $lastMonth = (new \DateTime('first day of last month'))->format('Y-m-d');
        $thisMonth = date('Y-m-01');
        $orderNo = $this->recurringOrder($twoMonthsAgo, 1);
        foreach ([$twoMonthsAgo, $lastMonth] as $date) {
            $billed = $this->generate([['orderId' => (string) $orderNo, 'date' => $date]]);
            $this->assertNull($billed['data']['recurringGenerate'][0]['error'], (string) json_encode($billed));
        }
        $closed = $this->graphql('mutation ($ids: [ID!]!) { salesOrderDelete(id: $ids) { id } }', [
            'ids' => [(string) $orderNo],
        ]);
        $this->assertArrayNotHasKey('errors', $closed, (string) json_encode($closed['errors'] ?? null));
        $this->assertSame([2.0], $this->quantities($orderNo), 'closed: the line is now what was sent');
        $this->assertSame($thisMonth, $this->dtNext($orderNo), 'the current period is due');
        $invoices = $this->invoicesFor($orderNo);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => $thisMonth]]);

        $this->assertSame('ENDED', $result['data']['recurringGenerate'][0]['error']['code'] ?? null);
        $this->assertStringContainsString('is closed', $result['data']['recurringGenerate'][0]['error']['message']);
        $this->assertSame($invoices, $this->invoicesFor($orderNo));
        $this->assertSame($thisMonth, $this->dtNext($orderNo));
    }

    /** Checkpoint C M-4: DeliveryService's "nothing to deliver". */
    public function testAnOrderWithNothingToDeliverIsRefused(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $this->pdo()->prepare('UPDATE 0_sales_order_details SET quantity = 0 WHERE trans_type = 30 AND order_no = ?')
            ->execute([$orderNo]);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $error = $result['data']['recurringGenerate'][0]['error'];
        $this->assertSame('FA_REJECTED', $error['code'] ?? null, (string) json_encode($result));
        $this->assertStringContainsString('nothing to deliver', $error['message']);
        $this->assertSame(0, $this->deliveriesFor($orderNo));
        $this->assertNull($this->dtNext($orderNo));
    }

    /** Checkpoint C M-4: a prepayment order, as DeliveryService and InvoiceService refuse it. */
    public function testAPrepaymentOrderIsRefused(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $this->pdo()->prepare(
            'UPDATE 0_sales_orders SET prep_amount = 30, payment_terms = '
            . '(SELECT terms_indicator FROM 0_payment_terms WHERE days_before_due = -1 LIMIT 1)'
            . ' WHERE trans_type = 30 AND order_no = ?'
        )->execute([$orderNo]);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $error = $result['data']['recurringGenerate'][0]['error'];
        $this->assertSame('FA_REJECTED', $error['code'] ?? null, (string) json_encode($result));
        $this->assertStringContainsString('prepayment', $error['message']);
        $this->assertSame(0, $this->deliveriesFor($orderNo));
        $this->assertNull($this->dtNext($orderNo));
    }

    /** Checkpoint C M-3: a customer put on hold after the order was taken. */
    public function testACustomerOnHoldIsRefusedAndNothingIsWritten(): void
    {
        [$customerId, $branchId] = $this->newCustomer(null);
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1, null, $customerId, $branchId);
        $this->pdo()->prepare(
            'UPDATE 0_debtors_master SET credit_status = '
            . '(SELECT id FROM 0_credit_status WHERE dissallow_invoices = 1 LIMIT 1) WHERE debtor_no = ?'
        )->execute([$customerId]);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $error = $result['data']['recurringGenerate'][0]['error'];
        $this->assertSame('FA_REJECTED', $error['code'] ?? null, (string) json_encode($result));
        $this->assertStringContainsString('on hold', $error['message']);
        $this->assertSame(0, $this->deliveriesFor($orderNo));
        $this->assertNull($this->dtNext($orderNo));
    }

    /** Checkpoint C M-3: FrontAccounting would write at a rate of 1.0 where none is set. */
    public function testACurrencyWithoutARateOnTheDateIsRefusedAndNothingIsWritten(): void
    {
        $currency = $this->pdo()->query(
            'SELECT curr_abrev FROM 0_currencies WHERE curr_abrev NOT IN (SELECT curr_code FROM 0_exchange_rates)'
            . " AND curr_abrev <> (SELECT value FROM 0_sys_prefs WHERE name = 'curr_default')"
            . ' ORDER BY curr_abrev LIMIT 1'
        )->fetchColumn();
        if ($currency === false) {
            $this->markTestSkipped('every currency in this stack has a rate');
        }
        [$customerId, $branchId] = $this->newCustomer(null);
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1, null, $customerId, $branchId);
        // The customer's currency, as the order's Cart reads it (sales_order_db.inc).
        $this->pdo()->prepare('UPDATE 0_debtors_master SET curr_code = ? WHERE debtor_no = ?')
            ->execute([$currency, $customerId]);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $error = $result['data']['recurringGenerate'][0]['error'];
        $this->assertSame(['BAD_INPUT', 'date'], [$error['code'] ?? null, $error['field'] ?? null]);
        $this->assertStringContainsString("no exchange rate for $currency", $error['message']);
        $this->assertSame(0, $this->deliveriesFor($orderNo));
        $this->assertNull($this->dtNext($orderNo));
    }

    /**
     * Checkpoint C M-3: an email that fails is reported in `email`; the item, written
     * and committed, is not an error.
     */
    public function testAnEmailThatFailsIsReportedAndTheItemStandsWritten(): void
    {
        [$customerId, $branchId] = $this->newCustomer(null);
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1, null, $customerId, $branchId);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d'), 'email' => true]]);

        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result['errors'] ?? null));
        $item = $result['data']['recurringGenerate'][0];
        $this->assertNull($item['error']);
        $this->assertNotNull($item['invoiceId']);
        $this->assertSame($item['invoiceId'], $item['email']['id']);
        $this->assertFalse($item['email']['sent']);
        $this->assertNull($item['email']['recipient']);
        $this->assertStringContainsString('no email contact', implode("\n", $item['email']['messages']));
        $this->assertSame(1, $this->invoicesFor($orderNo));
        $this->assertSame((new \DateTime('first day of next month'))->format('Y-m-d'), $this->dtNext($orderNo));
        if (MailCatcher::available()) {
            $this->assertCount(0, MailCatcher::newSince($this->mailBefore));
        }
    }

    /**
     * Checkpoint C M-3: emailing needs SA_SALESTRANSVIEW (3073), as invoiceEmail does,
     * and is checked before any item: a role holding 3076 and 3077 but not 3073 bills
     * nothing when any item asks for an email, and bills without one.
     */
    public function testEmailingNeedsSalesTransactionViewBeforeAnyItemIsBilled(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $role = "role = 'GraphQL Orders'";
        $before = (string) $this->pdo()->query("SELECT areas FROM 0_security_roles WHERE $role")->fetchColumn();
        $this->assertStringContainsString('91236', $before, 'the role holds SA_GRAPHQL');
        try {
            $this->pdo()->prepare("UPDATE 0_security_roles SET areas = ? WHERE $role")
                ->execute(['3075;3076;3077;91236']);
            $this->enterAs('apiorders');

            $refused = $this->generate([
                ['orderId' => (string) $orderNo, 'date' => date('Y-m-d')],
                ['orderId' => (string) $orderNo, 'date' => date('Y-m-d'), 'email' => true],
            ]);

            $this->assertSame('FORBIDDEN', $refused['errors'][0]['extensions']['code'] ?? null);
            $this->assertNull($refused['data'] ?? null);
            $this->assertSame(0, $this->invoicesFor($orderNo), 'not even the first item, which asked for no email');

            $billed = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

            $this->assertArrayNotHasKey('errors', $billed, (string) json_encode($billed['errors'] ?? null));
            $this->assertNull($billed['data']['recurringGenerate'][0]['error']);
            $this->assertSame(1, $this->invoicesFor($orderNo));
        } finally {
            $this->pdo()->prepare("UPDATE 0_security_roles SET areas = ? WHERE $role")->execute([$before]);
        }
        $this->assertSame(
            $before,
            (string) $this->pdo()->query("SELECT areas FROM 0_security_roles WHERE $role")->fetchColumn()
        );
    }

    /**
     * Checkpoint C M-3: anything the service throws that is not one of its refusals
     * reaches the client as INTERNAL with a fixed message; the detail goes to the
     * server's log only.
     */
    public function testAnUnexpectedFailureIsMaskedAsInternal(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sgw-sales-log');
        ini_set('error_log', (string) $log);
        $failing = new class extends RecurringInvoiceService {
            public function generate(
                int $orderNo,
                \DateTimeInterface $invoiceDate,
                bool $allowEarly = false
            ): GeneratedInvoice {
                throw new \RuntimeException('secret detail: /var/www/html/config_db.php');
            }
        };
        $generation = new RecurringGeneration(new ExtensionContext($this->container), $failing);

        try {
            $results = $generation->generate(['input' => [
                ['orderId' => '1', 'date' => new \DateTimeImmutable('today')],
            ]]);
            $logged = (string) file_get_contents((string) $log);
        } finally {
            @unlink((string) $log);
        }

        $this->assertSame(
            ['code' => 'INTERNAL', 'message' => 'Internal server error', 'field' => null],
            $results[0]['error']
        );
        $this->assertNull($results[0]['invoiceId']);
        $this->assertStringContainsString('RuntimeException: secret detail', $logged);
    }
}
