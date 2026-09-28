<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use FA\GraphQL\Tests\Support\MailCatcher;
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
}
