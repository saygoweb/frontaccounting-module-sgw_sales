<?php

namespace SGW_Sales\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SGW_Sales\controller\GenerateRecurring;

class GenerateRecurringTest extends TestCase
{
    public function testSelectedOrdersAreTheTickedCheckboxes(): void
    {
        $post = [
            's_12' => '1',
            's_13' => '0',
            's_14' => '',
            's_15' => '1',
            'GenerateInvoices' => 'Generate Invoices',
            'select_all' => '1',
            'show_all' => '1',
            '_token' => 'abc',
        ];
        $this->assertSame([12, 15], GenerateRecurring::selectedOrders($post));
    }

    public function testSelectedOrdersIgnoresKeysThatOnlyLookLikeOne(): void
    {
        // Each of these holds "s_"; none is a checkbox from the list.
        $post = ['sales_type' => '1', 's_' => '1', 's_12x' => '1', 'xs_12' => '1', 's_1;DROP' => '1'];
        $this->assertSame([], GenerateRecurring::selectedOrders($post));
    }
}
