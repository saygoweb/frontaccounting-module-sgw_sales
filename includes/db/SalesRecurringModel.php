<?php
namespace SGW_Sales\db;

use SGW\common\DataMapper;

class SalesRecurringModel {

	const REPEAT_YEARLY  = 'year';
	const REPEAT_MONTHLY = 'month';
	const REPEAT_WEEKLY  = 'week';
	
	public $id;
	public $transNo;
	
	public $dtFrom;
	public $dtTo;
	public $dtLast;
	public $repeats;
	public $every;
	public $occur;
	
// 	public function write() {
// 		$this->mapper->write($this);
// 	}

}

// $m = new RecurringModel();
// $d = DataMapper::createByClass('0_', $m);
// $d->write($m);