<?php
namespace SGW_Sales\db;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

/**
 * sales_recurring, through Anorm on this module's own PDO connection: the page and
 * the generation service use it.
 *
 * It is not the only writer. The FrontAccounting GraphQL module's API writes the
 * table through SGW_Sales\GraphQL\RecurrenceParticipant with FrontAccounting's
 * db_query(), on FrontAccounting's connection, so a schedule commits and rolls back
 * with its order (a different connection could not share that transaction). Both
 * write the same columns with the same meanings; change them together.
 */
class SalesRecurringModel extends Model {

	/**
	 * @param \PDO|null $pdo Anorm's QueryBuilder gives every model it makes the
	 *   connection it was made with; without one the default connection is used.
	 */
	public function __construct(?\PDO $pdo = null) {
		$pdo = $pdo ?: Anorm::pdo();
		parent::__construct($pdo, DataMapper::createByClass($pdo, $this, DB::tablePrefix()));
	}

	/** @return SalesRecurringModel|null null when the order has no recurrence */
	public static function findByTransNo($transNo) {
		$model = DataMapper::find(SalesRecurringModel::class, Anorm::pdo())
			->where('trans_no=:transNo', [':transNo' => $transNo])
			->one();
		return $model ?: null;
	}

	/**
	 * @return SalesRecurringModel
	 * @throws \Exception when the order has no recurrence
	 */
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