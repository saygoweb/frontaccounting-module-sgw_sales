<?php

namespace SGW_Sales\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SGW\common\Mapper;
use SGW_Sales\db\DB;
use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\db\SalesRecurringModel;

/**
 * What Anorm derives from the model classes: the table, with the company's
 * prefix, and the property => column map the order entry form is filled from.
 */
class ModelMappingTest extends TestCase
{
    protected function setUp(): void
    {
        DB::init('7_');
    }

    protected function tearDown(): void
    {
        DB::init('');
    }

    public function testTableTakesTheCompanyPrefix(): void
    {
        $m = new SalesRecurringModel($this->createStub(\PDO::class));
        $this->assertSame('7_sales_recurring', $m->mapper()->table);
    }

    public function testMapIsTheColumnsAndNothingOfAnorms(): void
    {
        $m = new SalesRecurringModel($this->createStub(\PDO::class));
        $this->assertSame(
            [
                'id' => 'id',
                'transNo' => 'trans_no',
                'dtStart' => 'dt_start',
                'dtEnd' => 'dt_end',
                'dtNext' => 'dt_next',
                'auto' => 'auto',
                'repeats' => 'repeats',
                'every' => 'every',
                'occur' => 'occur',
            ],
            $m->mapper()->map
        );
    }

    public function testModelKeepsTheConnectionItWasGiven(): void
    {
        // Anorm's QueryBuilder makes every row's model as `new $class($pdo)`.
        $pdo = $this->createStub(\PDO::class);
        $m = new GenerateRecurringModel($pdo);
        $this->assertSame($pdo, $m->getPdo());
        $this->assertSame($pdo, $m->mapper()->pdo);
    }

    public function testWriteArrayFillsTheFormByColumnName(): void
    {
        $m = new SalesRecurringModel($this->createStub(\PDO::class));
        $m->id = 5;
        $m->transNo = 12;
        $m->dtStart = '2016-04-01';
        $m->repeats = SalesRecurringModel::REPEAT_MONTHLY;
        $m->every = 3;

        $post = ['trans_no' => 'stale'];
        $this->assertTrue(Mapper::writeArray($m, $post, ['dtStart', 'dtEnd', 'dtNext', 'occur']));

        $this->assertSame(12, $post['trans_no']);
        $this->assertSame('month', $post['repeats']);
        $this->assertSame(3, $post['every']);
        $this->assertArrayNotHasKey('dt_start', $post);
        $this->assertArrayNotHasKey('_mapper', $post);
        $this->assertArrayNotHasKey('_last_snapshot', $post);
    }
}
