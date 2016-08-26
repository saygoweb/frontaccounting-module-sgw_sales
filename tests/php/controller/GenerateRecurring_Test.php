<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

define ('TB_PREF', '&TB_PREF&');

use SGW_Sales\controller\GenerateRecurring;
use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\db\SalesRecurringModel;

class GenerateRecurringMockView {
	
}

class GenerateRecurringTest extends PHPUnit_Framework_TestCase
{
	function testDateBefore_Years() {
		$c = new GenerateRecurring(new GenerateRecurringMockView());
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$m->every = 1;
		$m->occur = '04-06';
		
		$date = new DateTime('2016-04-01');
		$actual = $c->dateBefore($m, $date);
		$this->assertEquals('2015-04-06', $actual->format('Y-m-d'));
	}
	
	function testDateAfter_Years() {
		$c = new GenerateRecurring(new GenerateRecurringMockView());
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$m->every = 1;
		$m->occur = '04-06';
		
		$date = new DateTime('2016-04-01');
		$actual = $c->dateAfter($m, $date);
		$this->assertEquals('2016-04-06', $actual->format('Y-m-d'));
	}
	
	function testNextDateAfter_2Years() {
		$c = new GenerateRecurring(new GenerateRecurringMockView());
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$m->every = 2;
		$m->occur = '04-06';
	
		$date = new DateTime('2016-05-03');
		$actual = $c->nextDateAfter($m, $date);
		$this->assertEquals('2018-04-06', $actual->format('Y-m-d'));
	}

	function testNextDateAfter_SameDay1Years() {
		$c = new GenerateRecurring(new GenerateRecurringMockView());
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$m->every = 1;
		$m->occur = '04-06';
	
		$date = new DateTime('2016-04-06');
		$actual = $c->nextDateAfter($m, $date);
		$this->assertEquals('2017-04-06', $actual->format('Y-m-d'));
	}
	
	function testNextDate_NoLast2Years() {
		$c = new GenerateRecurring(new GenerateRecurringMockView());
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$m->every = 2;
		$m->occur = '04-06';
	
		$actual = $c->nextDate($m);
		$this->assertEquals('2016-04-06', $actual->format('Y-m-d'));
	}
	
	function testNextDate_WithLast2Years() {
		$c = new GenerateRecurring(new GenerateRecurringMockView());
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->dtNext = '2016-04-06';
		$m->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$m->every = 2;
		$m->occur = '04-06';
	
		$actual = $c->nextDate($m);
		$this->assertEquals('2018-04-06', $actual->format('Y-m-d'));
	}
	
	function testComment_With1YearNotInStartYear() {
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->dtNext = '2017-04-06'; // Not relevant to this test
		$m->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$m->every = 1;
		$m->occur = '04-06';
		
		$today = new \DateTime('2017-04-06');
		$actual = GenerateRecurring::comment($m, $today);
		$this->assertEquals('Invoice for period 1 April 2017 to 31 March 2018', $actual);
		
	}
	
	function testComment_With1MonthNotInStartYear() {
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->dtNext = '2017-04-06'; // Not relevant to this test
		$m->repeats = SalesRecurringModel::REPEAT_MONTHLY;
		$m->every = 1;
		$m->occur = '21';
		
		$today = new \DateTime('2017-09-21');
		$actual = GenerateRecurring::comment($m, $today);
		$this->assertEquals('Invoice for period 1 September 2017 to 30 September 2017', $actual);
		
	}
	
	function testComment_With2YearNotInStartYear() {
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->dtNext = '2017-04-06'; // Not relevant to this test
		$m->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$m->every = 2;
		$m->occur = '04-06';
	
		$today = new \DateTime('2017-04-06');
		$actual = GenerateRecurring::comment($m, $today);
		$this->assertEquals('Invoice for period 1 April 2017 to 31 March 2019', $actual);
	
	}
	
	function testComment_With2MonthNotInStartYear() {
		$m = new GenerateRecurringModel();
		$m->dtStart = '2016-04-01';
		$m->dtNext = '2017-04-06'; // Not relevant to this test
		$m->repeats = SalesRecurringModel::REPEAT_MONTHLY;
		$m->every = 2;
		$m->occur = '21';
	
		$today = new \DateTime('2017-09-21');
		$actual = GenerateRecurring::comment($m, $today);
		$this->assertEquals('Invoice for period 1 September 2017 to 31 October 2017', $actual);
	
	}
}