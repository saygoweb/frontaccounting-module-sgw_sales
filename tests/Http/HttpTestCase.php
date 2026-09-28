<?php

namespace SGW_Sales\Tests\Http;

use Anorm\Anorm;
use PHPUnit\Framework\TestCase;
use SGW_Sales\db\DB;
use SGW_Sales\db\SalesRecurringModel;

/**
 * A logged-in FrontAccounting session over HTTP, at FA_URL.
 */
abstract class HttpTestCase extends TestCase
{
    /** @var string */
    private static $base = '';

    /**
     * The session cookie, carried by hand. FrontAccounting marks it `secure`
     * whatever the scheme (SECURE_ONLY in includes/session.inc) and lets plain
     * http through for localhost only; a cookie jar would not send it back.
     * @var array<string, string>
     */
    private static $cookies = [];

    /** customer_ref of an order copied by copyOrder(): never picked by unrecurredOrder(). */
    protected const COPY_MARK = 'sgw_sales test copy';

    /** Served from the module's root: Apache denies tests/. */
    private const PROBE = __DIR__ . '/../../test-service-probe.php';

    /** @var int[] orders copied by this test */
    private $copies = [];

    /** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> rows to put back */
    private $restore = [];

    /** @var bool */
    private $probeInstalled = false;

    protected function setUp(): void
    {
        self::$base = rtrim((string) getenv('FA_URL'), '/');
        if (!self::$base) {
            $this->markTestSkipped('FA_URL is not set - run the suite through tools/ci.sh (docker/ci/plugin-test.sh)');
        }
        if (!self::$cookies) {
            $this->login();
        }
    }

    protected function tearDown(): void
    {
        if ($this->probeInstalled) {
            @unlink(self::PROBE);
        }
        foreach (array_reverse($this->restore) as [$table, $where, $values]) {
            $set = implode(', ', array_map(function ($column) {
                return "`$column`=:$column";
            }, array_keys($values)));
            Anorm::pdo()->prepare('UPDATE ' . DB::prefix($table) . " SET $set WHERE $where")->execute($values);
        }
        $this->restore = [];
        foreach ($this->copies as $orderNo) {
            // A copy that has documents stays, as every test's documents do.
            if ($this->invoiceCount($orderNo) + $this->deliveryCount($orderNo) === 0) {
                foreach (['sales_order_details', 'sales_orders'] as $table) {
                    Anorm::pdo()->exec(
                        'DELETE FROM ' . DB::prefix($table)
                        . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
                    );
                }
                Anorm::pdo()->exec(
                    'DELETE FROM ' . DB::prefix('refs') . ' WHERE type=' . ST_SALESORDER . " AND id=$orderNo"
                );
            }
        }
        $this->copies = [];
    }

    private function login(): void
    {
        [, $html] = $this->request('/index.php');
        $this->request('/index.php', [
            'user_name_entry_field' => getenv('FA_USER') ?: 'admin',
            'password' => getenv('FA_PASSWORD') ?: 'password',
            'company_login_name' => '0',
            'ui_mode' => '',
            'SubmitUser' => 'Login',
            '_token' => $this->token($html),
        ]);
    }

    /** The CSRF token FrontAccounting puts in every form and checks on every POST. */
    protected function token(string $html): string
    {
        if (!preg_match('/name="_token" value="([^"]*)"/', $html, $m)) {
            $this->fail('no form token in the page');
        }
        return $m[1];
    }

    /** @return array{0: int, 1: string} status and body */
    protected function request(string $path, ?array $post = null): array
    {
        $c = curl_init(self::$base . $path);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            // Not optional: FrontAccounting's SessionManager::preventHijacking()
            // discards any session that was started without a user agent.
            CURLOPT_USERAGENT => 'sgw_sales-tests',
            CURLOPT_COOKIE => http_build_query(self::$cookies, '', '; '),
            CURLOPT_HEADERFUNCTION => function ($c, $header) {
                if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)/i', $header, $m)) {
                    self::$cookies[$m[1]] = urldecode($m[2]);
                }
                return strlen($header);
            },
        ]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return [$status, (string) $body];
    }

    /** Anorm, connected the way hooks.php connects it. */
    protected function connectDb(): void
    {
        if (!getenv('FA_DB_HOST')) {
            $this->markTestSkipped('FA_DB_HOST is not set - run the suite through tools/ci.sh (docker/ci/plugin-test.sh)');
        }
        Anorm::connect(
            Anorm::DEFAULT,
            'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
            getenv('FA_DB_USER'),
            getenv('FA_DB_PASSWORD')
        );
        DB::init(getenv('FA_DB_PREFIX') !== false ? getenv('FA_DB_PREFIX') : '0_');
    }

    /**
     * A sales order with no recurrence yet, from whatever dataset is loaded. One that
     * holds no stock (service lines only) where there is one: every generation
     * delivers the order again, these documents are not rolled back, and the
     * service refuses a delivery the stock cannot cover.
     */
    protected function unrecurredOrder(): int
    {
        $orderNo = Anorm::pdo()->query(
            'SELECT so.order_no FROM ' . DB::prefix('sales_orders') . ' AS so'
            . ' WHERE so.trans_type=' . ST_SALESORDER
            . ' AND so.order_no NOT IN (SELECT trans_no FROM ' . DB::prefix('sales_recurring') . ')'
            . " AND so.customer_ref <> '" . self::COPY_MARK . "'"
            . ' ORDER BY EXISTS (SELECT 1 FROM ' . DB::prefix('sales_order_details') . ' AS line'
            . ' JOIN ' . DB::prefix('stock_master') . ' AS item ON item.stock_id=line.stk_code'
            . " WHERE line.order_no=so.order_no AND line.trans_type=so.trans_type AND item.mb_flag IN ('B', 'M')),"
            . ' so.order_no DESC LIMIT 1'
        )->fetchColumn();
        if (!$orderNo) {
            $this->markTestSkipped('the loaded dataset has no sales order to hang a recurrence on');
        }
        return (int) $orderNo;
    }

    /** A recurrence on $orderNo, monthly on the 1st and never yet invoiced: due now. */
    protected function dueMonthly(int $orderNo): SalesRecurringModel
    {
        $recurrence = new SalesRecurringModel();
        $recurrence->transNo = $orderNo;
        $recurrence->dtStart = '2016-07-01';
        $recurrence->auto = 0;
        $recurrence->repeats = SalesRecurringModel::REPEAT_MONTHLY;
        $recurrence->every = 1;
        $recurrence->occur = '1';
        $recurrence->write();
        return $recurrence;
    }

    protected function invoiceCount(int $orderNo): int
    {
        $statement = Anorm::pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB::prefix('debtor_trans') . ' WHERE type=' . ST_SALESINVOICE . ' AND order_=:order'
        );
        $statement->execute([':order' => $orderNo]);
        return (int) $statement->fetchColumn();
    }

    protected function deliveryCount(int $orderNo): int
    {
        $statement = Anorm::pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB::prefix('debtor_trans')
            . ' WHERE type=' . ST_CUSTDELIVERY . ' AND order_=:order'
        );
        $statement->execute([':order' => $orderNo]);
        return (int) $statement->fetchColumn();
    }

    /** The GL of a delivery and an invoice sums to zero, as every FrontAccounting posting must. */
    protected function assertGlBalanced(int $deliveryNo, int $invoiceNo): void
    {
        $statement = Anorm::pdo()->prepare(
            'SELECT ROUND(SUM(amount), 2) FROM ' . DB::prefix('gl_trans')
            . ' WHERE (type=' . ST_CUSTDELIVERY . ' AND type_no=:d) OR (type=' . ST_SALESINVOICE . ' AND type_no=:i)'
        );
        $statement->execute([':d' => $deliveryNo, ':i' => $invoiceNo]);
        $this->assertEquals(0.0, (float) $statement->fetchColumn(), 'GL not balanced');
    }

    /** A monthly recurrence on the 1st whose next date is $dtNext (null: never generated, started 2016). */
    protected function monthlyOnThe1st(int $orderNo, ?string $dtNext, ?string $dtEnd = null): SalesRecurringModel
    {
        $recurrence = $this->dueMonthly($orderNo);
        $recurrence->dtNext = $dtNext;
        $recurrence->dtEnd = $dtEnd;
        $recurrence->write();
        return $recurrence;
    }

    protected function assertRendered(int $status, string $html): void
    {
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('name="user_name_entry_field"', $html, 'got the login form: the login did not take');
        $this->assertDoesNotMatchRegularExpression('/Fatal error|Uncaught|DATABASE ERROR|class=.err_msg/i', $html);
    }

    /**
     * A new sales order copied from $orderNo, nothing yet delivered on it, for a test
     * that changes the order itself (closes it, empties it). Removed in tearDown
     * unless documents were written for it.
     */
    protected function copyOrder(int $orderNo): int
    {
        $pdo = Anorm::pdo();
        $new = 1 + (int) $pdo->query(
            'SELECT MAX(order_no) FROM ' . DB::prefix('sales_orders') . ' WHERE trans_type=' . ST_SALESORDER
        )->fetchColumn();
        $header = $pdo->query(
            'SELECT * FROM ' . DB::prefix('sales_orders')
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo"
        )->fetch(\PDO::FETCH_ASSOC);
        $header['order_no'] = $new;
        $header['customer_ref'] = self::COPY_MARK;
        // A reference of its own that the order page accepts ({001}/{YYYY}), not 'auto'.
        $header['reference'] = sprintf('9%04d/%s', $new, substr((string) $header['ord_date'], 0, 4));
        $header['version'] = 0;
        $this->insert('sales_orders', $header);
        $this->copies[] = $new;
        $lines = $pdo->query(
            'SELECT * FROM ' . DB::prefix('sales_order_details') . ' WHERE trans_type=' . ST_SALESORDER
            . " AND order_no=$orderNo ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($lines as $line) {
            unset($line['id']);
            $line['order_no'] = $new;
            $line['qty_sent'] = 0;
            $line['invoiced'] = 0;
            $this->insert('sales_order_details', $line);
        }
        return $new;
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        Anorm::pdo()->prepare(
            'INSERT INTO ' . DB::prefix($table) . ' (`' . implode('`, `', $columns) . '`)'
            . ' VALUES (:' . implode(', :', $columns) . ')'
        )->execute($row);
    }

    /**
     * Change one row's $values for the length of the test: put back in tearDown.
     * @param array<string, mixed> $values
     */
    protected function changeRow(string $table, string $where, array $values): void
    {
        $old = Anorm::pdo()->query(
            'SELECT `' . implode('`, `', array_keys($values)) . '` FROM ' . DB::prefix($table) . " WHERE $where"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($old, "no $table row where $where");
        $this->restore[] = [$table, $where, $old];
        $set = implode(', ', array_map(function ($column) {
            return "`$column`=:$column";
        }, array_keys($values)));
        Anorm::pdo()->prepare('UPDATE ' . DB::prefix($table) . " SET $set WHERE $where")->execute($values);
    }

    /** The order's lines' quantities, by stock id. @return array<string, float> */
    protected function quantities(int $orderNo): array
    {
        $rows = Anorm::pdo()->query(
            'SELECT stk_code, quantity FROM ' . DB::prefix('sales_order_details')
            . ' WHERE trans_type=' . ST_SALESORDER . " AND order_no=$orderNo ORDER BY id"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map('floatval', $rows);
    }

    /**
     * RecurringInvoiceService::generate() in a request with no page behind it
     * (tests/Http/fixtures/service-probe.php), answered as JSON.
     * @return array<string, mixed>
     */
    protected function probe(int $orderNo, string $date, array $more = []): array
    {
        if (!$this->probeInstalled) {
            if (!@copy(__DIR__ . '/fixtures/service-probe.php', self::PROBE)) {
                $this->markTestSkipped('cannot write the probe into the module directory');
            }
            $this->probeInstalled = true;
        }
        $query = http_build_query(['order' => $orderNo, 'date' => $date] + $more);
        [$status, $body] = $this->request('/modules/sgw_sales/test-service-probe.php?' . $query);
        $this->assertSame(200, $status);
        if (!preg_match('/<<<JSON(.*)JSON>>>/s', $body, $m)) {
            $this->fail('the probe did not answer: ' . substr(strip_tags($body), 0, 500));
        }
        return json_decode($m[1], true);
    }

    protected static function today(): string
    {
        return (new \DateTime('today'))->format('Y-m-d');
    }
}
