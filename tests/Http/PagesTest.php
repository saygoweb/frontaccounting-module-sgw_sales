<?php

namespace SGW_Sales\Tests\Http;

/**
 * The module's pages, served by a FrontAccounting that has loaded hooks.php:
 * each of them reads through Anorm before it renders.
 */
class PagesTest extends HttpTestCase
{
    public function pages(): array
    {
        return [
            'order entry' => ['/modules/sgw_sales/sales_order_entry.php?NewOrder=Yes', 'New Sales Order Entry', "name='sale_recurring'"],
            'generate recurring' => ['/modules/sgw_sales/generate_recurring_invoices.php', 'Create and Print Recurrent Invoices', 'GenerateInvoices'],
            'order inquiry' => ['/modules/sgw_sales/inquiry/sales_orders_view.php?type=30', null, 'orders_tbl'],
        ];
    }

    /** @dataProvider pages */
    public function testPageRenders(string $path, ?string $title, string $marker): void
    {
        [$status, $html] = $this->request($path);

        $this->assertRendered($status, $html);
        if ($title !== null) {
            $this->assertStringContainsString('<title>' . $title, $html);
        }
        $this->assertStringContainsString($marker, $html);
    }

    public function testMenuOffersTheModule(): void
    {
        // Needs the role to hold the module's security areas; `fa-sgw-sales db load` grants them.
        [$status, $html] = $this->request('/index.php?application=orders');

        $this->assertRendered($status, $html);
        $this->assertStringContainsString('modules/sgw_sales/generate_recurring_invoices.php', $html);
    }
}
