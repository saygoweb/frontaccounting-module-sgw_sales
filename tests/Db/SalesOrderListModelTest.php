<?php

namespace SGW_Sales\Tests\Db;

use SGW_Sales\db\SalesOrderListModel;

/**
 * The sales order inquiry: a count and a paged list built from one filter.
 */
class SalesOrderListModelTest extends DbTestCase
{
    public function testFilterByOrderNumber(): void
    {
        $order = $this->anySalesOrder();

        $count = SalesOrderListModel::countByFilter($order['order_no'], '', '', '', '', '');
        $this->assertEquals(1, $count);

        $query = SalesOrderListModel::queryByFilter($order['order_no'], '', '', '', '', '');
        $rows = iterator_to_array(SalesOrderListModel::findByQuery($query, 10, 0), false);

        $this->assertCount(1, $rows);
        $this->assertInstanceOf(SalesOrderListModel::class, $rows[0]);
        $this->assertEquals($order['order_no'], $rows[0]->orderNo);
        $this->assertSame($order['reference'], $rows[0]->reference);
        $this->assertSame($order['name'], $rows[0]->name);
    }

    public function testFilterByCustomerPages(): void
    {
        $order = $this->anySalesOrder();

        $all = iterator_to_array(SalesOrderListModel::findByQuery(
            SalesOrderListModel::queryByFilter('', '', '', '', '', $order['debtor_no']),
            1000,
            0
        ), false);
        $this->assertNotEmpty($all);

        $page = iterator_to_array(SalesOrderListModel::findByQuery(
            SalesOrderListModel::queryByFilter('', '', '', '', '', $order['debtor_no']),
            1,
            count($all) - 1
        ), false);
        $this->assertCount(1, $page);
        $this->assertEquals($all[count($all) - 1]->orderNo, $page[0]->orderNo);
    }

    public function testFilterByDomainMatchesLineDescriptions(): void
    {
        $nothing = SalesOrderListModel::countByFilter('', '', 'no-line-says-this.example', '', '', '');
        $this->assertEquals(0, $nothing);
    }
}
