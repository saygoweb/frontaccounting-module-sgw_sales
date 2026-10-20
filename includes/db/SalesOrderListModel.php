<?php
namespace SGW_Sales\db;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\QueryBuilder;
use SGW_Sales\db\DB;

class SalesOrderListModel extends Model {

    /** The columns the list sorts by, by the property the pager names them with. */
    private const SORTS = [
        'orderNo' => 'so.order_no',
        'reference' => 'so.reference',
        'name' => 'debtor.name',
        'ordDate' => 'so.ord_date',
        'total' => 'so.total',
    ];

    /**
     * @param \PDO|null $pdo Anorm's QueryBuilder gives every model it makes the
     *   connection it was made with; without one the default connection is used.
     */
    public function __construct(?\PDO $pdo = null) {
        $pdo = $pdo ?: Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this, DB::tablePrefix()));
    }

    /**
     * The documents of one type ($transType: ST_SALESORDER or ST_SALESQUOTE) that
     * the first filter given matches: number, reference, domain (a line's
     * description), customer, else the dates. Anorm's where() takes one condition.
     */
    private static function where(QueryBuilder $q, $orderNo, $ref, $domain, $dtFrom, $dtTo, $customer, $transType)
    {
        $sql = 'so.trans_type=:transType';
        $params = [':transType' => $transType];
        if ($orderNo) {
            $sql .= ' AND so.order_no=:orderNo';
            $params[':orderNo'] = $orderNo;
        } elseif ($ref) {
            $sql .= ' AND so.reference=:reference';
            $params[':reference'] = $ref;
        } elseif ($domain) {
            $sql .= ' AND sd.description LIKE :domain';
            $params[':domain'] = "%$domain%";
        } elseif ($customer) {
            $sql .= ' AND so.debtor_no=:customer';
            $params[':customer'] = $customer;
        } else {
            $sql .= ' AND so.ord_date>=:dtFrom AND so.ord_date<=:dtTo';
            $params[':dtFrom'] = date2sql($dtFrom);
            $params[':dtTo'] = date2sql($dtTo);
        }
        return $q->where($sql, $params);
    }

    /** The lines of the same document: an order and a quote can share a number. */
    private static function detailsJoin() {
        return "LEFT JOIN " . DB::prefix("sales_order_details")
            . " AS sd ON so.order_no=sd.order_no AND so.trans_type=sd.trans_type";
    }

    /** @return int the documents listed: each once, however many of its lines match */
    public static function countByFilter($orderNo, $ref, $domain, $dtFrom, $dtTo, $customer, $transType = ST_SALESORDER) {
        /** @var QueryBuilder */
        $result = DataMapper::find(CountModel::class, Anorm::pdo())
            ->select("COUNT(DISTINCT so.order_no) AS count")
            ->from(DB::prefix("sales_orders") . " AS so");
        if ($domain) {
            $result = $result->join(self::detailsJoin());
        }
        /** @var CountModel */
        $result = self::where($result, $orderNo, $ref, $domain, $dtFrom, $dtTo, $customer, $transType)
            ->one();
        return (int) $result->count;
    }

    /**
     * The documents listed; findByQuery() sorts and pages them.
     * @return QueryBuilder
     */
    public static function queryByFilter($orderNo, $ref, $domain, $dtFrom, $dtTo, $customer, $transType = ST_SALESORDER) {
        $result = DataMapper::find(SalesOrderListModel::class, Anorm::pdo())
            ->select("so.order_no,so.reference,debtor.name,so.ord_date,so.total,so.trans_type," .
                "sd.description")
            ->from(DB::prefix("sales_orders") . " AS so")
            ->join(self::detailsJoin())
            ->join("JOIN " . DB::prefix("debtors_master") . " AS debtor ON so.debtor_no=debtor.debtor_no");

        return self::where($result, $orderNo, $ref, $domain, $dtFrom, $dtTo, $customer, $transType)
            ->groupBy("so.order_no");
    }

    /**
     * One page of $queryBuilder's documents, sorted by $sort (property => 'asc' or
     * 'desc', the most significant first; a property not in SORTS is ignored), then
     * by number. $queryBuilder is left as it was, for the next page.
     * @param array<string, string> $sort
     * @return \Generator<SalesOrderListModel>|SalesOrderListModel[]|bool
     */
    public static function findByQuery(QueryBuilder $queryBuilder, int $recordCount, int $offset, array $sort = []) {
        $order = [];
        foreach ($sort as $property => $direction) {
            if (isset(self::SORTS[$property])) {
                $order[] = self::SORTS[$property] . ($direction === 'desc' ? ' DESC' : ' ASC');
            }
        }
        $order[] = 'so.order_no';
        return (clone $queryBuilder)
            ->orderBy(implode(', ', $order))
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
