<?php
namespace SGW_Sales\db;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
// use hooks_sgw_sales;
use SGW_Sales\db\DB;

class GenerateRecurringModel extends Model {

	/**
	 * @param \PDO|null $pdo Anorm's QueryBuilder gives every model it makes the
	 *   connection it was made with; without one the default connection is used.
	 */
	public function __construct(?\PDO $pdo = null) {
		$pdo = $pdo ?: Anorm::pdo();
		parent::__construct($pdo, DataMapper::createByClass($pdo, $this, DB::tablePrefix()));
	}

	/**
	 * The recurrences due on $asOf (all that have not ended with $showAll), soonest
	 * first. Due: not ended (dt_end after $asOf), and either dt_next on or before
	 * $asOf, or never generated (dt_next NULL) and already started.
	 *
	 * @param bool $showAll include those not yet due
	 * @param \DateTimeInterface|null $asOf today by default
	 * @param \PDO|null $pdo the connection to read on; Anorm's default by default.
	 *   The GraphQL extension passes its request's connection (the token's company).
	 * @return \Generator<GenerateRecurringModel>|GenerateRecurringModel[]|boolean
	 */
	public static function find($showAll, ?\DateTimeInterface $asOf = null, ?\PDO $pdo = null) {
		$pdo = $pdo ?: Anorm::pdo();
		$date = ($asOf ?: new \DateTime())->format('Y-m-d');
		$transactionType = ST_SALESORDER;
		$where = "so.trans_type=" . $transactionType
			. " AND (sr.dt_end>:asOfEnd OR sr.dt_end IS NULL)";
		$params = [':asOfEnd' => $date];
		if (!$showAll) {
			$where .= " AND (sr.dt_next<=:asOfNext OR (sr.dt_next IS NULL AND sr.dt_start<=:asOfStart))";
			$params[':asOfNext'] = $date;
			$params[':asOfStart'] = $date;
		}
		$result = DataMapper::find(GenerateRecurringModel::class, $pdo)
			->select("so.order_no,so.reference,so.debtor_no,so.branch_code,so.customer_ref,debtor.name,so.ord_date," .
				"sr.id,sr.dt_start,sr.dt_end,sr.dt_next,sr.auto,sr.repeats,sr.every,sr.occur," .
				"inv.dt_last,inv.inv_total,so.total,so.trans_type")
			->from(DB::prefix("sales_orders") . " AS so")
			->join("LEFT JOIN " .
				"(SELECT order_, max(tran_date) dt_last, sum(ov_amount) inv_total FROM ". DB::prefix("debtor_trans") . " WHERE type=" . ST_SALESINVOICE . " GROUP BY order_) " .
				"inv ON inv.order_=so.order_no")
			->join("JOIN " . DB::prefix("sales_recurring") . " AS sr ON sr.trans_no=so.order_no")
			->join("JOIN " . DB::prefix("debtors_master") . " AS debtor ON so.debtor_no=debtor.debtor_no")
			->where($where, $params)
			->groupBy("so.order_no")
			->orderBy("sr.dt_next")
			->some();
		return $result;
	}

	public $orderNo;
	public $reference;
	public $debtorNo;
	public $branchCode;
	public $customerRef;
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
