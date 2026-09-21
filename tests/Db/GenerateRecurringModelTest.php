<?php

namespace SGW_Sales\Tests\Db;

use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\db\SalesRecurringModel;

/**
 * The generation list: a four-table join read through Anorm's QueryBuilder.
 */
class GenerateRecurringModelTest extends DbTestCase
{
    private function recur(array $order, ?string $dtNext, ?string $dtEnd = null): SalesRecurringModel
    {
        $m = new SalesRecurringModel();
        $m->transNo = $order['order_no'];
        $m->dtStart = '2016-07-03';
        $m->dtEnd = $dtEnd;
        $m->dtNext = $dtNext;
        $m->auto = 1;
        $m->repeats = SalesRecurringModel::REPEAT_MONTHLY;
        $m->every = 2;
        $m->occur = '3';
        $m->write();
        return $m;
    }

    /** @return GenerateRecurringModel[] keyed by order number */
    private function find(bool $showAll): array
    {
        $found = [];
        foreach (GenerateRecurringModel::find($showAll) as $model) {
            $found[$model->orderNo] = $model;
        }
        return $found;
    }

    public function testDueOrderIsListedWithItsOrderAndCustomer(): void
    {
        $order = $this->anySalesOrder();
        $this->recur($order, '2016-09-03');

        $found = $this->find(false);

        $this->assertArrayHasKey($order['order_no'], $found);
        $model = $found[$order['order_no']];
        $this->assertInstanceOf(GenerateRecurringModel::class, $model);
        $this->assertSame($order['reference'], $model->reference);
        $this->assertSame($order['name'], $model->name);
        $this->assertSame('2016-07-03', $model->dtStart);
        $this->assertSame('2016-09-03', $model->dtNext);
        $this->assertSame('month', $model->repeats);
        $this->assertEquals(2, $model->every);
        $this->assertSame('3', $model->occur);
    }

    public function testEveryRowIsItsOwnModel(): void
    {
        // Anorm 1 yielded one instance, overwritten per row; Anorm 3 makes one each.
        $first = $this->anySalesOrder();
        $this->recur($first, null);
        $second = $this->anySalesOrder();
        $this->recur($second, null);

        $found = $this->find(false);

        $this->assertNotSame($found[$first['order_no']], $found[$second['order_no']]);
        $this->assertEquals($first['order_no'], $found[$first['order_no']]->orderNo);
        $this->assertEquals($second['order_no'], $found[$second['order_no']]->orderNo);
    }

    public function testOrderNotYetDueIsOnlyListedWhenShowingAll(): void
    {
        $order = $this->anySalesOrder();
        $this->recur($order, '2999-01-01');

        $this->assertArrayNotHasKey($order['order_no'], $this->find(false));
        $this->assertArrayHasKey($order['order_no'], $this->find(true));
    }

    public function testEndedOrderIsNeverListed(): void
    {
        $order = $this->anySalesOrder();
        $this->recur($order, '2016-09-03', '2016-12-31');

        $this->assertArrayNotHasKey($order['order_no'], $this->find(true));
    }
}
