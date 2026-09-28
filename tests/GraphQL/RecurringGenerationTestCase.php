<?php

namespace SGW_Sales\Tests\GraphQL;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Support\AssertsGlBalanced;
use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Tests\Support\MailCatcher;
use FA\GraphQL\Tests\Support\ReportFiles;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;

/**
 * recurringDueList / recurringGenerate through the real schema, signed in as
 * apitest (SalesOrderTestCase), with the billing documents, the orders and any
 * mail or report PDF a test caused removed afterwards. Customers a test makes carry
 * $this->prefix in their reference and are swept by it.
 */
abstract class RecurringGenerationTestCase extends ExtensionTestCase
{
    use AssertsGlBalanced;

    private ?FaBillingRows $billingRows = null;

    /** @var string[] */
    protected array $mailBefore = [];

    /** @var string[] */
    private array $pdfBefore = [];

    protected string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireExtensionServesRecurrence();
        FaIncludes::billing();
        $this->billingRows = FaBillingRows::mark(FaTestRows::connect());
        $this->mailBefore = MailCatcher::available() ? MailCatcher::files() : [];
        $this->pdfBefore = ReportFiles::files(Bootstrap::defaultRoot());
        $this->prefix = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        if (MailCatcher::available()) {
            MailCatcher::delete(MailCatcher::newSince($this->mailBefore));
        }
        ReportFiles::deleteNew(Bootstrap::defaultRoot(), $this->pdfBefore);
        if ($this->billingRows !== null) {
            $this->billingRows->purge();
        }
        parent::tearDown();
        if ($this->prefix !== '') {
            FaTestRows::sweep($this->pdo(), $this->prefix);
        }
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the GraphQL result (data, errors)
     */
    protected function graphql(string $query, array $variables = []): array
    {
        return GraphQL::executeQuery(
            $this->container->get(Schema::class),
            $query,
            null,
            $this->container,
            $variables
        )->toArray();
    }

    /** A new session as another user of company 0, in this (separate) process. */
    protected function enterAs(string $login): void
    {
        $factory = require '/var/www/html/modules/graphql/container.php';
        $this->container = $factory(
            Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef', 'fa_root' => Bootstrap::defaultRoot()]),
            new RequestInfo(false, 'phpunit 127.0.0.1')
        );
        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, $login, 'recurring-test', new \DateTimeImmutable('+5 minutes')));
    }

    /**
     * A recurring sales order for demo customer 1 / branch 1 of the service item 202,
     * monthly on day $day, starting $start; tracked for cleanup.
     */
    protected function recurringOrder(
        string $start,
        int $day,
        ?string $end = null,
        int $customerId = 1,
        int $branchId = 1
    ): int {
        $recurring = ['start' => $start, 'repeats' => 'MONTH', 'every' => 1, 'day' => $day];
        if ($end !== null) {
            $recurring['end'] = $end;
        }
        $result = $this->graphql(
            'mutation ($input: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $input) { id } }',
            ['input' => [[
                'customerId' => (string) $customerId,
                'branchId' => (string) $branchId,
                'orderDate' => date('Y-m-d'),
                'paymentTermsId' => '3',
                'deliverTo' => 'Recurring test',
                'deliveryAddress' => '1 Test Street',
                'lines' => [['stockId' => '202', 'quantity' => 1.0, 'unitPrice' => 30.0]],
                'recurring' => $recurring,
            ]]]
        );
        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result['errors'] ?? null));
        $orderNo = (int) $result['data']['salesOrderCreate'][0]['id'];
        $this->track($orderNo);

        return $orderNo;
    }

    protected function invoicesFor(int $orderNo): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_debtor_trans WHERE type = 10 AND order_ = ?');
        $statement->execute([$orderNo]);
        return (int) $statement->fetchColumn();
    }

    protected function dtNext(int $orderNo): ?string
    {
        $statement = $this->pdo()->prepare('SELECT dt_next FROM 0_sales_recurring WHERE trans_no = ?');
        $statement->execute([$orderNo]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    protected function generate(array $items): array
    {
        return $this->graphql(
            'mutation ($input: [RecurringGenerateInput!]!) { recurringGenerate(input: $input) {'
            . ' orderId invoiceId deliveryId next'
            . ' email { id sent recipient messages }'
            . ' error { code message field } } }',
            ['input' => $items]
        );
    }
}
