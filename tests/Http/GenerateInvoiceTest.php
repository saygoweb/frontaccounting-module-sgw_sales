<?php

namespace SGW_Sales\Tests\Http;

use SGW_Sales\db\SalesRecurringModel;

/**
 * The whole of what the module is for: an order that is due is listed, the page
 * generates its delivery and invoice through FrontAccounting's Cart, and the
 * recurrence moves on to its next date.
 *
 * Not rolled back - FrontAccounting writes on its own connection - so every run
 * leaves one more invoice in the stack's database.
 */
class GenerateInvoiceTest extends HttpTestCase
{
    private const PAGE = '/modules/sgw_sales/generate_recurring_invoices.php';

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
    }

    public function testDueOrderIsInvoicedAndMovesOn(): void
    {
        $orderNo = $this->unrecurredOrder();
        $before = $this->invoiceCount($orderNo);
        $this->recurrence = $this->dueMonthly($orderNo);

        [$status, $html] = $this->request(self::PAGE);
        $this->assertRendered($status, $html);
        $this->assertStringContainsString("name='s_$orderNo'", $html, 'the due order is not in the list');

        [$status, $html] = $this->request(self::PAGE, [
            's_' . $orderNo => '1',
            'GenerateInvoices' => 'Generate',
            '_token' => $this->token($html),
        ]);
        $this->assertRendered($status, $html);
        $this->assertStringContainsString('Generated invoice for order ' . $orderNo, $html);

        $this->assertSame($before + 1, $this->invoiceCount($orderNo));

        // The 1st of next month, whatever today is.
        $expected = (new \DateTime('first day of next month'))->format('Y-m-d');
        $this->assertSame($expected, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }
}
