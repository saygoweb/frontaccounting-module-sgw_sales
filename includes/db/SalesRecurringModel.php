<?php
namespace SGW_Sales\db;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class SalesRecurringModel extends Model {

	/**
	 * @param \PDO|null $pdo Anorm's QueryBuilder gives every model it makes the
	 *   connection it was made with; without one the default connection is used.
	 */
	public function __construct(?\PDO $pdo = null) {
		$pdo = $pdo ?: Anorm::pdo();
		parent::__construct($pdo, DataMapper::createByClass($pdo, $this, DB::tablePrefix()));
	}

	/** @return SalesRecurringModel */
	public static function readByTransNo($transNo) {
		return DataMapper::find(SalesRecurringModel::class, Anorm::pdo())
			->where('trans_no=:transNo', [':transNo' => $transNo])
			->oneOrThrow();
	}
	
	/**
	 * @var DataMapper
	 */
	public $_mapper;
	
	const REPEAT_YEARLY  = 'year';
	const REPEAT_MONTHLY = 'month';
	
	public $id;
	public $transNo;
	
	public $dtStart;
	public $dtEnd;
	public $dtNext;
	public $auto;
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