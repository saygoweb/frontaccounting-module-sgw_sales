<?php

namespace SGW_Sales\Tests\Db;

use SGW_Sales\db\SalesRecurringModel;

class SalesRecurringModelTest extends DbTestCase
{
    private const TRANS_NO = 999999;

    private function newModel(): SalesRecurringModel
    {
        $m = new SalesRecurringModel();
        $m->transNo = self::TRANS_NO;
        $m->dtStart = '2016-07-03';
        $m->auto = 0;
        $m->repeats = SalesRecurringModel::REPEAT_YEARLY;
        $m->every = 1;
        $m->occur = '07-03';
        return $m;
    }

    public function testReadByTransNoThrowsWhenThereIsNone(): void
    {
        $this->expectException(\Exception::class);
        SalesRecurringModel::readByTransNo(self::TRANS_NO);
    }

    public function testCreateReadUpdateDelete(): void
    {
        // Create. dt_end and dt_next are left null, which update_1.4.sql allows.
        $created = $this->newModel();
        $id = $created->write();
        $this->assertGreaterThan(0, (int) $id);
        $this->assertEquals($id, $created->id);

        // Read
        $read = SalesRecurringModel::readByTransNo(self::TRANS_NO);
        $this->assertInstanceOf(SalesRecurringModel::class, $read);
        $this->assertEquals($id, $read->id);
        $this->assertSame('2016-07-03', $read->dtStart);
        $this->assertNull($read->dtEnd);
        $this->assertNull($read->dtNext);
        $this->assertSame('year', $read->repeats);
        $this->assertSame('07-03', $read->occur);

        // Update, as GenerateRecurring::run() does after an invoice
        $read->dtNext = '2017-07-03';
        $this->assertEquals($id, $read->write());

        $updated = new SalesRecurringModel();
        $this->assertTrue($updated->read((int) $id));
        $this->assertSame('2017-07-03', $updated->dtNext);
        $this->assertEquals(self::TRANS_NO, $updated->transNo);

        // Delete
        $this->assertTrue((bool) $updated->delete());
        $this->assertFalse((new SalesRecurringModel())->read((int) $id));
    }

    public function testFormPostIsReadIntoTheModel(): void
    {
        // sales_order_entry.php fills the model straight from $_POST.
        $m = new SalesRecurringModel();
        $post = ['trans_no' => '12', 'repeats' => 'month', 'every' => '2', 'dt_start' => '01/02/2016', 'unrelated' => 'x'];
        $m->mapper()->readArray($m, $post, ['dtLast', 'dtStart', 'dtEnd']);
        $this->assertSame('12', $m->transNo);
        $this->assertSame('month', $m->repeats);
        $this->assertSame('2', $m->every);
        $this->assertNull($m->dtStart);
    }
}
