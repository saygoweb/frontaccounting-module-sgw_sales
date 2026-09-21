<?php

namespace SGW_Sales\Tests\Http;

use SGW_Sales\db\SalesRecurringModel;

/**
 * RecurringInvoiceService called the way an API calls it: FrontAccounting
 * booted, a user logged in, and none of what the Generate Recurring Invoices
 * page includes. Whatever the service needs of FrontAccounting it has to ask
 * for itself - Cart wants count_array() from ui.inc, which every FA page has
 * loaded and nothing else has.
 *
 * Not rolled back, as GenerateInvoiceTest is not.
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

    private function probe(int $orderNo, bool $email): array
    {
        [$status, $body] = $this->request('/modules/sgw_sales/test-service-probe.php?order=' . $orderNo . '&email=' . (int) $email);
        $this->assertSame(200, $status);
        if (!preg_match('/<<<JSON(.*)JSON>>>/s', $body, $m)) {
            $this->fail('the probe did not answer: ' . substr(strip_tags($body), 0, 500));
        }
        return json_decode($m[1], true);
    }

    public function testInvoiceIsGeneratedAndEmailedWithNoPageBehindIt(): void
    {
        $orderNo = $this->unrecurredOrder();
        $before = $this->invoiceCount($orderNo);
        $this->recurrence = $this->dueMonthly($orderNo);

        $out = $this->probe($orderNo, true);

        $this->assertArrayNotHasKey('error', $out, $out['error'] ?? '');
        $this->assertFalse($out['view_loaded']);
        $this->assertSame($orderNo, $out['generated']['orderNo']);
        $this->assertGreaterThan(0, $out['generated']['invoiceNo']);
        $this->assertTrue($out['generated']['emailed']);
        $this->assertStringStartsWith('Invoice for period 1 ', $out['generated']['comment']);

        $expected = (new \DateTime('first day of next month'))->format('Y-m-d');
        $this->assertSame($expected, $out['generated']['dtNext']);
        $this->assertSame($expected, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
        $this->assertSame($before + 1, $this->invoiceCount($orderNo));

        // Emailing borrows $_POST for FrontAccounting's report; the caller gets its own back.
        $this->assertSame(['PARAM_0' => 'the caller had this here'], $out['post']);
    }

    public function testOrderWithoutARecurrenceIsRefusedAndNothingIsInvoiced(): void
    {
        $orderNo = $this->unrecurredOrder();
        $before = $this->invoiceCount($orderNo);

        $out = $this->probe($orderNo, false);

        $this->assertStringStartsWith('SGW_Sales\service\RecurrenceNotFound:', $out['error'] ?? '');
        $this->assertSame($before, $this->invoiceCount($orderNo));
    }
}
