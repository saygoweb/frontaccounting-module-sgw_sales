<?php

namespace SGW_Sales\Tests\Db;

use Anorm\Anorm;
use PHPUnit\Framework\TestCase;
use SGW_Sales\db\DB;

/**
 * Connects Anorm the way hooks.php does, from the environment the docker stack
 * provides, and rolls every test back.
 */
abstract class DbTestCase extends TestCase
{
    /** @var \PDO */
    protected $pdo;

    /** @var string */
    protected $prefix;

    protected function setUp(): void
    {
        $host = getenv('FA_DB_HOST');
        if (!$host) {
            $this->markTestSkipped('FA_DB_HOST is not set - run the suite through docker/fa-sgw-sales test');
        }
        $this->prefix = getenv('FA_DB_PREFIX') !== false ? getenv('FA_DB_PREFIX') : '0_';

        // connect() keeps the first connection made under a name, so this is one
        // connection for the whole run.
        Anorm::connect(
            Anorm::DEFAULT,
            'mysql:host=' . $host . ';dbname=' . getenv('FA_DB_NAME'),
            getenv('FA_DB_USER'),
            getenv('FA_DB_PASSWORD')
        );
        DB::init($this->prefix);

        $this->pdo = Anorm::pdo();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * A sales order from whatever dataset is loaded.
     * @return array{order_no: string, debtor_no: string, reference: string, name: string}
     */
    protected function anySalesOrder(): array
    {
        $row = $this->pdo->query(
            'SELECT so.order_no, so.debtor_no, so.reference, debtor.name'
            . ' FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' JOIN ' . DB::prefix('debtors_master') . ' AS debtor ON so.debtor_no=debtor.debtor_no'
            . ' WHERE so.trans_type=' . ST_SALESORDER
            . ' AND so.order_no NOT IN (SELECT trans_no FROM ' . DB::prefix('sales_recurring') . ')'
            . ' ORDER BY so.order_no LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('the loaded dataset has no sales order to hang a recurrence on');
        }
        return $row;
    }
}
