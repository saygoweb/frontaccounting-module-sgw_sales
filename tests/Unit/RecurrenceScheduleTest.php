<?php

namespace SGW_Sales\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\db\SalesRecurringModel;
use SGW_Sales\service\RecurrenceSchedule;

/**
 * The date arithmetic behind recurring invoices: when the next one falls due,
 * and the period its comment names.
 */
class RecurrenceScheduleTest extends TestCase
{
    private function model(string $repeats, int $every, string $occur, ?string $dtNext = null): GenerateRecurringModel
    {
        // A model only needs a connection to be constructed, not to hold dates.
        $m = new GenerateRecurringModel($this->createStub(\PDO::class));
        $m->dtStart = '2016-04-01';
        $m->dtNext = $dtNext;
        $m->repeats = $repeats;
        $m->every = $every;
        $m->occur = $occur;
        return $m;
    }

    public function testDateBeforeYears(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_YEARLY, 1, '04-06');
        $actual = RecurrenceSchedule::dateBefore($m, new \DateTime('2016-04-01'));
        $this->assertSame('2015-04-06', $actual->format('Y-m-d'));
    }

    public function testDateAfterYears(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_YEARLY, 1, '04-06');
        $actual = RecurrenceSchedule::dateAfter($m, new \DateTime('2016-04-01'));
        $this->assertSame('2016-04-06', $actual->format('Y-m-d'));
    }

    public function testDateBeforeMonths(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_MONTHLY, 1, '21');
        $actual = RecurrenceSchedule::dateBefore($m, new \DateTime('2017-09-06'));
        $this->assertSame('2017-08-21', $actual->format('Y-m-d'));
    }

    public function testDateAfterMonths(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_MONTHLY, 1, '21');
        $actual = RecurrenceSchedule::dateAfter($m, new \DateTime('2017-09-22'));
        $this->assertSame('2017-10-21', $actual->format('Y-m-d'));
    }

    public function testNextDateAfter2Years(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_YEARLY, 2, '04-06');
        $actual = RecurrenceSchedule::nextDateAfter($m, new \DateTime('2016-05-03'));
        $this->assertSame('2018-04-06', $actual->format('Y-m-d'));
    }

    public function testNextDateAfterSameDay1Year(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_YEARLY, 1, '04-06');
        $actual = RecurrenceSchedule::nextDateAfter($m, new \DateTime('2016-04-06'));
        $this->assertSame('2017-04-06', $actual->format('Y-m-d'));
    }

    public function testNextDateAfter3Months(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_MONTHLY, 3, '21');
        $actual = RecurrenceSchedule::nextDateAfter($m, new \DateTime('2017-11-21'));
        $this->assertSame('2018-02-21', $actual->format('Y-m-d'));
    }

    public function testNextDateNoNext2Years(): void
    {
        // Before the start date, not after it: c5e2441 made the default the
        // occurrence on or before dt_start, so a new order is due straight away.
        $m = $this->model(SalesRecurringModel::REPEAT_YEARLY, 2, '04-06');
        $actual = RecurrenceSchedule::nextDate($m);
        $this->assertSame('2015-04-06', $actual->format('Y-m-d'));
    }

    public function testNextDateWithNext2Years(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_YEARLY, 2, '04-06', '2016-04-06');
        $actual = RecurrenceSchedule::nextDate($m);
        $this->assertSame('2018-04-06', $actual->format('Y-m-d'));
    }

    public function testCommentWith1YearNotInStartYear(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_YEARLY, 1, '04-06', '2017-04-06');
        $actual = RecurrenceSchedule::comment($m, new \DateTime('2017-04-06'));
        $this->assertSame('Invoice for period 1 April 2017 to 31 March 2018', $actual);
    }

    public function testCommentWith1MonthNotInStartYear(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_MONTHLY, 1, '21', '2017-04-06');
        $actual = RecurrenceSchedule::comment($m, new \DateTime('2017-09-21'));
        $this->assertSame('Invoice for period 1 September 2017 to 30 September 2017', $actual);
    }

    public function testCommentWith2YearNotInStartYear(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_YEARLY, 2, '04-06', '2017-04-06');
        $actual = RecurrenceSchedule::comment($m, new \DateTime('2017-04-06'));
        $this->assertSame('Invoice for period 1 April 2017 to 31 March 2019', $actual);
    }

    public function testCommentRefusesAnUnknownRecurrence(): void
    {
        $m = $this->model('week', 1, '3');
        $this->expectException(\DomainException::class);
        RecurrenceSchedule::comment($m, new \DateTime('2017-09-21'));
    }

    public function testCommentWith2MonthNotInStartYear(): void
    {
        $m = $this->model(SalesRecurringModel::REPEAT_MONTHLY, 2, '21', '2017-04-06');
        $actual = RecurrenceSchedule::comment($m, new \DateTime('2017-09-21'));
        $this->assertSame('Invoice for period 1 September 2017 to 31 October 2017', $actual);
    }
}
