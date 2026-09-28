<?php

namespace SGW_Sales\Tests\Http;

use SGW_Sales\db\SalesRecurringModel;

/**
 * sgw_sales' Sales Order Entry, where a person edits a recurring order's schedule
 * and cancels (closes) an order, and what it means for generation.
 *
 * Each test works on a copy of an order (copyOrder()): closing an order changes its
 * quantities for good. A copy that was billed stays, as every test's documents do.
 */
class OrderEntryTest extends HttpTestCase
{
    private const PAGE = '/modules/sgw_sales/sales_order_entry.php';

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

    /**
     * The order open for editing, and the form's fields as the browser would post
     * them (text, hidden, ticked boxes, selected options).
     * @return array<string, string>
     */
    private function openOrder(int $orderNo): array
    {
        [$status, $html] = $this->request(self::PAGE . '?ModifyOrderNumber=' . $orderNo);
        $this->assertRendered($status, $html);
        $fields = [];
        preg_match_all('/<input\b[^>]*>/i', $html, $inputs);
        foreach ($inputs[0] as $input) {
            $name = self::attribute($input, 'name');
            $type = strtolower(self::attribute($input, 'type') ?? 'text');
            if ($name === null || in_array($type, ['submit', 'button', 'image'], true)) {
                continue;
            }
            if ($type === 'checkbox' || $type === 'radio') {
                if (preg_match('/\bchecked\b/i', $input)) {
                    $fields[$name] = self::attribute($input, 'value') ?? '1';
                }
                continue;
            }
            $fields[$name] = html_entity_decode(self::attribute($input, 'value') ?? '', ENT_QUOTES);
        }
        preg_match_all('/<select\b[^>]*>.*?<\/select>/is', $html, $selects);
        foreach ($selects[0] as $select) {
            $name = self::attribute($select, 'name');
            if ($name === null) {
                continue;
            }
            if (preg_match('/<option\b[^>]*\bselected\b[^>]*>/i', $select, $option)) {
                $fields[$name] = html_entity_decode(self::attribute($option[0], 'value') ?? '', ENT_QUOTES);
            }
        }
        $this->assertArrayHasKey('cart_id', $fields, 'the order form was not shown');
        return $fields;
    }

    private static function attribute(string $tag, string $name): ?string
    {
        // The first tag only: a select's options carry their own value attributes.
        $tag = (string) strstr($tag, '>', true) . '>';
        $name = preg_quote($name, '/');
        if (preg_match('/\s' . $name . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m)) {
            return $m[2];
        }
        if (preg_match('/\s' . $name . '\s*=\s*([^\s>"\']+)/i', $tag, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Checkpoint C C-1: the page checked 'Every' only with is_numeric(), so 0 could
     * be saved — a schedule that never moves on. The API takes 1 to 127 (tinyint).
     */
    public function testEveryMustBeFrom1To127(): void
    {
        $source = $this->unrecurredOrder();
        foreach (['0', '128', '1.5'] as $every) {
            // A fresh order each time: FrontAccounting's edit session is one per order.
            $orderNo = $this->copyOrder($source);
            $this->recurrence = $this->dueMonthly($orderNo);
            $fields = $this->openOrder($orderNo);
            $this->assertSame('1', $fields['every'] ?? null, 'the schedule is shown');

            [$status, $html] = $this->request(
                self::PAGE,
                ['every' => $every, 'ProcessOrder' => 'Commit Order Changes'] + $fields
            );

            $this->assertSame(200, $status);
            $this->assertStringContainsString(
                "Recurring Order 'Every' must be a whole number from 1 to 127.",
                $html,
                "every = $every"
            );
            $this->assertSame(1, (int) SalesRecurringModel::readByTransNo($orderNo)->every, "every = $every");
            $this->recurrence->delete();
        }
    }

    /**
     * Checkpoint C C-2: cancelling a delivered order on this page closes it
     * (FrontAccounting sets each line's quantity to what was sent: every period so
     * far) but left the schedule running, so the next billing run invoiced the
     * closed order at that inflated quantity. The close now ends the schedule today,
     * in the same transaction, as the API's close does.
     */
    public function testCancellingADeliveredOrderEndsItsScheduleAndNothingMoreIsBilled(): void
    {
        $orderNo = $this->copyOrder($this->unrecurredOrder());
        $ordered = $this->quantities($orderNo);
        $lastMonth = (new \DateTime('first day of last month'))->format('Y-m-d');
        $thisMonth = (new \DateTime('first day of this month'))->format('Y-m-d');
        $this->recurrence = $this->monthlyOnThe1st($orderNo, $lastMonth);
        foreach ([$lastMonth, $thisMonth] as $date) {
            $out = $this->probe($orderNo, $date);
            $this->assertArrayNotHasKey('error', $out, $out['error'] ?? '');
        }
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $fields = $this->openOrder($orderNo);
        [$status, $html] = $this->request(self::PAGE, [
            'cart_id' => $fields['cart_id'],
            '_token' => $fields['_token'],
            'CancelOrder' => 'Cancel Order',
        ]);

        $this->assertRendered($status, $html);
        $this->assertStringContainsString('Undelivered part of order has been cancelled', $html);
        $this->assertSame(
            array_map(function ($quantity) {
                return 2 * $quantity;
            }, $ordered),
            $this->quantities($orderNo),
            'closed: each line is now what was sent, two periods'
        );
        $schedule = SalesRecurringModel::readByTransNo($orderNo);
        $this->assertSame($this->today(), $schedule->dtEnd, 'the close ended the schedule today');

        $out = $this->probe($orderNo, (string) $schedule->dtNext);

        $this->assertSame('SGW_Sales\service\RecurrenceEnded', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($deliveries, $this->deliveryCount($orderNo));
    }
}
