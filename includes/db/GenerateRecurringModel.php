<?php
namespace SGW_Sales\db;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
// use hooks_sgw_sales;
use SGW_Sales\db\DB;

class GenerateRecurringModel extends Model {

	public function __construct() {
		$pdo = Anorm::pdo();
		parent::__construct($pdo, DataMapper::createByClass($pdo, $this, DB::tablePrefix()));
	}

	/** @return Generator<GenerateRecurringModel>|GenerateRecurringModel[]|boolean */
	public static function find($showAll) {
		$transactionType = ST_SALESORDER;
		$result = DataMapper::find(GenerateRecurringModel::class, Anorm::pdo())
			->select("so.order_no,so.reference,debtor.name,so.ord_date," .
				"sr.id,sr.dt_start,sr.dt_end,sr.dt_next,sr.auto,sr.repeats,sr.every,sr.occur," .
				"inv.dt_last,inv.inv_total,so.total,so.trans_type")
			->from(DB::prefix("sales_orders") . " AS so")
			->join("LEFT JOIN " .
				"(SELECT order_, max(tran_date) dt_last, sum(ov_amount) inv_total FROM ". DB::prefix("debtor_trans") . " WHERE type=" . ST_SALESINVOICE . " GROUP BY order_) " .
				"inv ON so.trans_type=" . $transactionType . " AND inv.order_=so.order_no")
			->join("JOIN " . DB::prefix("sales_recurring") . " AS sr ON sr.trans_no=so.order_no")
			->join("JOIN " . DB::prefix("debtors_master") . " AS debtor ON so.debtor_no=debtor.debtor_no")
			->where("(sr.dt_end>CURDATE() OR sr.dt_end='0000-00-00')" . ($showAll ? "" : " AND sr.dt_next<=CURDATE()"), [])
			->groupBy("so.order_no")
			->orderBy("sr.dt_next")
			->some();
		return $result;
	}
	
	public $orderNo;
	public $reference;
	public $name;
	
	public $dtStart;
	public $dtEnd;
	public $dtLast;
	public $dtNext;
	public $auto;
	public $repeats;
	public $every;
	public $occur;
	
}
