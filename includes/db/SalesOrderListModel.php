<?php
namespace SGW_Sales\db;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\QueryBuilder;
use SGW_Sales\db\DB;

class SalesOrderListModel extends Model {

    public function __construct() {
        $pdo = Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this, DB::tablePrefix()));
    }

    /** @return int */
    public static function countByFilter($orderNo, $ref, $dtFrom, $dtTo, $customer) {
        /** @var CountModel */
        $result = DataMapper::find(CountModel::class, Anorm::pdo())
            ->select("COUNT(so.order_no) AS count")
            ->from(DB::prefix("sales_orders") . " AS so")
            // ->join("JOIN " . DB::prefix("debtors_master") . " AS debtor ON so.debtor_no=debtor.debtor_no")
            // TODO where
            ->where("so.ord_date>=:dtFrom AND so.ord_date<=:dtTo", [':dtFrom' => date2sql($dtFrom), ':dtTo' => date2sql($dtTo)])
            // ->where("(sr.dt_end>CURDATE() OR sr.dt_end='0000-00-00')" . ($showAll ? "" : " AND sr.dt_next<=CURDATE()"), [])
            // ->groupBy("so.order_no")
            ->one();
        return $result->count;
    }

        /** @return QueryBuilder */
    public static function queryByFilter($orderNo, $ref, $dtFrom, $dtTo, $customer) {
        $transactionType = ST_SALESORDER;
        $result = DataMapper::find(SalesOrderListModel::class, Anorm::pdo())
            ->select("so.order_no,so.reference,debtor.name,so.ord_date,so.total,so.trans_type," .
                "sd.description")
            ->from(DB::prefix("sales_orders") . " AS so")
            // ->join("LEFT JOIN " .
            // 	"(SELECT order_, max(tran_date) dt_last, sum(ov_amount) inv_total FROM ". DB::prefix("debtor_trans") . " WHERE type=" . ST_SALESINVOICE . " GROUP BY order_) " .
            // 	"inv ON so.trans_type=" . $transactionType . " AND inv.order_=so.order_no")
            // ->join("LEFT JOIN " .
            //     "(SELECT order_no, sum(unit_price*quantity*(1-discount_percent)) ord_total FROM " . DB::prefix("sales_order_details") . " WHERE trans_type=" . ST_SALESORDER . " GROUP BY order_no) " .
            //     "ord ON so.trans_type=" . $transactionType . " AND ord.order_no=so.order_no")
            // ->join("JOIN " . DB::prefix("sales_recurring") . " AS sr ON sr.trans_no=so.order_no")
            ->join("LEFT JOIN " . DB::prefix("sales_order_details") .  " AS sd ON so.order_no=sd.order_no AND sd.stk_code='HDOM'")
            ->join("JOIN " . DB::prefix("debtors_master") . " AS debtor ON so.debtor_no=debtor.debtor_no")
            // TODO where
            ->where("so.ord_date>=:dtFrom AND so.ord_date<=:dtTo", [':dtFrom' => date2sql($dtFrom), ':dtTo' => date2sql($dtTo)])
            // ->where("(sr.dt_end>CURDATE() OR sr.dt_end='0000-00-00')" . ($showAll ? "" : " AND sr.dt_next<=CURDATE()"), [])
            ->groupBy("so.order_no")
            ->orderBy("so.order_no");
        return $result;
    }
    
    /** @return Generator<SalesOrderListModel>|SalesOrderListModel[]|boolean */
    public static function findByQuery(QueryBuilder $queryBuilder, int $recordCount, int $offset) {
        return $queryBuilder
            ->limit($recordCount, $offset)
            ->some();
    }

    public $orderNo;
    public $reference;
    public $name;

    public $ordDate;
    public $total;

    public $description;
    
    
}
