<?php

namespace SGW_Sales\Tests\Http;

use Anorm\Anorm;
use SGW_Sales\db\DB;
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
        if (!getenv('FA_DB_HOST')) {
            $this->markTestSkipped('FA_DB_HOST is not set - run the suite through docker/fa-sgw-sales test');
        }
        Anorm::connect(
            Anorm::DEFAULT,
            'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
            getenv('FA_DB_USER'),
            getenv('FA_DB_PASSWORD')
        );
        DB::init(getenv('FA_DB_PREFIX') !== false ? getenv('FA_DB_PREFIX') : '0_');
    }

    protected function tearDown(): void
    {
        if ($this->recurrence && $this->recurrence->id) {
            $this->recurrence->delete();
        }
    }

    private function invoiceCount(string $orderNo): int
    {
        $statement = Anorm::pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB::prefix('debtor_trans') . ' WHERE type=' . ST_SALESINVOICE . ' AND order_=:order'
        );
        $statement->execute([':order' => $orderNo]);
        return (int) $statement->fetchColumn();
    }

    public function testDueOrderIsInvoicedAndMovesOn(): void
    {
        $orderNo = Anorm::pdo()->query(
            'SELECT so.order_no FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' WHERE so.trans_type=' . ST_SALESORDER
            . ' AND so.order_no NOT IN (SELECT trans_no FROM ' . DB::prefix('sales_recurring') . ')'
            . ' ORDER BY so.order_no DESC LIMIT 1'
        )->fetchColumn();
        if (!$orderNo) {
            $this->markTestSkipped('the loaded dataset has no sales order to hang a recurrence on');
        }
        $before = $this->invoiceCount($orderNo);

        // Monthly on the 1st, never yet invoiced: due now.
        $this->recurrence = new SalesRecurringModel();
        $this->recurrence->transNo = $orderNo;
        $this->recurrence->dtStart = '2016-07-01';
        $this->recurrence->auto = 0;
        $this->recurrence->repeats = SalesRecurringModel::REPEAT_MONTHLY;
        $this->recurrence->every = 1;
        $this->recurrence->occur = '1';
        $this->recurrence->write();

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
        $after = SalesRecurringModel::readByTransNo($orderNo);
        $this->assertSame($expected, $after->dtNext);
    }
}
