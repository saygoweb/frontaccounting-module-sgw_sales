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

    private static function where(QueryBuilder $q, $orderNo, $ref, $dtFrom, $dtTo, $customer)
    {
        if ($orderNo) {
            return $q->where('so.order_no=:orderNo', [':orderNo' => $orderNo]);
        }
        if ($ref) {
            return $q->where('so.reference=:reference', [':reference' => $ref]);
        }
        if ($customer) {
            return $q->where('so.debtor_no=:customer', [':customer' => $customer]);
        }
        return $q->where('so.ord_date>=:dtFrom AND so.ord_date<=:dtTo', [':dtFrom' => date2sql($dtFrom), ':dtTo' => date2sql($dtTo)]);
    }

    /** @return int */
    public static function countByFilter($orderNo, $ref, $dtFrom, $dtTo, $customer) {
        /** @var QueryBuilder */
        $result = DataMapper::find(CountModel::class, Anorm::pdo())
            ->select("COUNT(so.order_no) AS count")
            ->from(DB::prefix("sales_orders") . " AS so");
        /** @var CountModel */
        $result = self::where($result, $orderNo, $ref, $dtFrom, $dtTo, $customer)
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
            ->join("LEFT JOIN " . DB::prefix("sales_order_details") .  " AS sd ON so.order_no=sd.order_no AND sd.stk_code='HDOM'")
            ->join("JOIN " . DB::prefix("debtors_master") . " AS debtor ON so.debtor_no=debtor.debtor_no");

        $result = self::where($result, $orderNo, $ref, $dtFrom, $dtTo, $customer)
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
