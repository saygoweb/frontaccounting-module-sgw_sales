<?php

namespace SGW_Sales\Tests\Http;

use Anorm\Anorm;
use SGW_Sales\db\DB;

/**
 * view/view_sales_order.php, the order the inquiry opens.
 *
 * A recurring order is delivered again on every generation, so its lines'
 * delivered quantities pass their ordered ones. The view once read the order as
 * a delivery to come (Cart's $prepare_child), which taxed the quantity still to
 * deliver, negative on such an order, and showed negative tax.
 */
class ViewOrderTest extends HttpTestCase
{
    private const PAGE = '/modules/sgw_sales/view/view_sales_order.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectDb();
    }

    public function testTaxIsOnTheOrderedQuantityWhenMoreHasBeenDelivered(): void
    {
        $orderNo = $this->copyOrder($this->unrecurredOrder());
        // On a price list that excludes tax, so that the tax is added to the total.
        $salesType = Anorm::pdo()->query(
            'SELECT id FROM ' . DB::prefix('sales_types') . ' WHERE tax_included=0 ORDER BY id LIMIT 1'
        )->fetchColumn();
        $this->assertNotFalse($salesType, 'the loaded dataset has no price list that excludes tax');
        $this->changeRow('sales_orders', 'trans_type=' . ST_SALESORDER . " AND order_no=$orderNo", [
            'order_type' => $salesType,
        ]);

        $undelivered = $this->totals($orderNo);
        $taxes = array_diff_key($undelivered, array_flip(['Sub Total', 'Shipping', 'Amount Total']));
        $this->assertNotEmpty(array_filter($taxes), 'the order is not taxed: nothing to check');

        // Delivered 5 more than ordered on every line, as generation leaves a recurring order.
        Anorm::pdo()->exec(
            'UPDATE ' . DB::prefix('sales_order_details') . ' SET qty_sent=quantity+5'
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        );
        $overDelivered = $this->totals($orderNo);

        $this->assertSame($undelivered, $overDelivered);
        foreach ($taxes as $label => $amount) {
            $this->assertGreaterThan(0, $amount, $label);
        }
        $this->assertEqualsWithDelta(
            $overDelivered['Sub Total'] + array_sum($taxes),
            $overDelivered['Amount Total'],
            0.001
        );
    }

    /**
     * The rows under the order's lines: Sub Total, Shipping, each tax, Amount Total.
     * @return array<string, float>
     */
    private function totals(int $orderNo): array
    {
        [$status, $html] = $this->request(self::PAGE . "?trans_no=$orderNo");
        $this->assertRendered($status, $html);
        $lines = (string) strstr($html, 'Line Details');
        $this->assertNotSame('', $lines, 'no line details shown');
        preg_match_all(
            '/<td [^>]*colspan=6[^>]*>([^<]+)<\/td>\s*<td[^>]*>(-?[\d,.]+)<\/td>/',
            $lines,
            $rows,
            PREG_SET_ORDER
        );
        $totals = [];
        foreach ($rows as [, $label, $amount]) {
            $totals[trim($label)] = (float) str_replace(',', '', $amount);
        }
        $this->assertArrayHasKey('Sub Total', $totals);
        $this->assertArrayHasKey('Amount Total', $totals);
        return $totals;
    }
}
