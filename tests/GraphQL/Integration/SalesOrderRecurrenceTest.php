<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use SGW_Sales\GraphQL\RecurrenceParticipant;
use SGW_Sales\Tests\GraphQL\ExtensionTestCase;

/**
 * Release 2 spec section 4.5. The docker stack has sgw_sales active and upgraded to
 * 1.4 (Foundation spec section 9); without it, only the last test can run.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderRecurrenceTest extends ExtensionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireExtensionServesRecurrence();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function monthly(array $overrides = []): array
    {
        return array_merge([
            'start' => new \DateTimeImmutable($this->today()),
            'repeats' => 'month',
            'every' => 1,
            'day' => 15,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduleRow(int $orderNo): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM 0_sales_recurring WHERE trans_no = ?');
        $statement->execute([$orderNo]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function update(int $orderNo, array $patch): void
    {
        $input = array_merge(['id' => $orderNo, 'version' => (int) $this->orderRow($orderNo)['version']], $patch);
        ServiceCall::run(function () use ($input): void {
            $this->service()->update($input);
        });
    }

    public function testAnOrderCreatedWithARecurrenceHasItsSchedule(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);

        $row = $this->scheduleRow($orderNo);
        $this->assertSame($this->today(), $row['dt_start']);
        $this->assertNull($row['dt_end']);
        $this->assertNull($row['dt_next'], 'sgw_sales computes it');
        $this->assertSame('month', $row['repeats']);
        $this->assertSame('15', $row['occur']);
        $this->assertSame('1', (string) $row['every']);
        $this->assertSame('1', (string) $row['auto']);

        $read = $this->participant()->read($orderNo);
        $this->assertSame('month', $read['repeats']);
        $this->assertSame(15, $read['day']);
    }

    public function testAnOrderWithoutOneReadsNull(): void
    {
        $orderNo = $this->createOrder();

        $this->assertNull($this->participant()->read($orderNo));
    }

    public function testAnInvalidScheduleIsRefusedBeforeTheOrderIsWritten(): void
    {
        $marker = 'recurring-' . uniqid();
        try {
            $this->createOrder(['customerRef' => $marker, 'recurring' => $this->monthly(['day' => null])]);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame('recurring.day', $e->field());
        }
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_sales_orders WHERE customer_ref = ?');
        $count->execute([$marker]);
        $this->assertSame(0, (int) $count->fetchColumn());
    }

    public function testAScheduleRollsBackWithItsBatch(): void
    {
        $marker = 'recurring-' . uniqid();
        $schedules = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_sales_recurring')->fetchColumn();
        try {
            $this->container->get(SalesOrderType::class)->resolveCreate(null, ['input' => [
                $this->orderInput(['customerRef' => $marker, 'recurring' => $this->monthly()]),
                $this->orderInput(['customerRef' => $marker, 'lines' => []]),
            ]], $this->container);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame(1, $e->index());
        }

        $after = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_sales_recurring')->fetchColumn();
        $this->assertSame($schedules, $after);
    }

    public function testAnUpdateSetsKeepsAndResetsTheSchedule(): void
    {
        $orderNo = $this->createOrder();
        $yearly = [
            'start' => new \DateTimeImmutable($this->today()),
            'repeats' => 'year',
            'every' => 1,
            'monthDay' => '03-01',
        ];

        $this->update($orderNo, ['recurring' => $yearly]);
        $this->assertSame('03-01', $this->scheduleRow($orderNo)['occur']);

        // sgw_sales has computed a next date; an end date leaves it alone...
        $this->pdo()->prepare("UPDATE 0_sales_recurring SET dt_next = '2030-03-01' WHERE trans_no = ?")
            ->execute([$orderNo]);
        $this->update($orderNo, ['recurring' => array_merge($yearly, ['end' => new \DateTimeImmutable('+5 years')])]);
        $this->assertSame('2030-03-01', $this->scheduleRow($orderNo)['dt_next']);
        $this->assertNotNull($this->scheduleRow($orderNo)['dt_end']);

        // ...a new rhythm makes sgw_sales compute it again.
        $this->update($orderNo, ['recurring' => array_merge($yearly, ['every' => 2])]);
        $this->assertNull($this->scheduleRow($orderNo)['dt_next']);
        $this->assertSame('2', (string) $this->scheduleRow($orderNo)['every']);
    }

    public function testDeletingAnOrderDeletesItsSchedule(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);

        $outcome = ServiceCall::run(function () use ($orderNo): string {
            return $this->service()->delete($orderNo);
        });

        $this->assertSame(SalesOrderService::DELETED, $outcome);
        $this->assertNull($this->scheduleRow($orderNo));
    }

    public function testClosingAnOrderEndsItsScheduleToday(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1.0]);

        $outcome = ServiceCall::run(function () use ($orderNo): string {
            return $this->service()->delete($orderNo);
        });

        $this->assertSame(SalesOrderService::CLOSED, $outcome);
        $this->assertSame($this->today(), $this->scheduleRow($orderNo)['dt_end']);
    }

    public function testAClosedScheduleThatEndsEarlierKeepsItsEnd(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        $this->pdo()->prepare("UPDATE 0_sales_recurring SET dt_end = '2000-01-01' WHERE trans_no = ?")
            ->execute([$orderNo]);
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1.0]);

        ServiceCall::run(function () use ($orderNo): string {
            return $this->service()->delete($orderNo);
        });

        $this->assertSame('2000-01-01', $this->scheduleRow($orderNo)['dt_end']);
    }

    /**
     * sgw_sales: a recurring order's header stays editable once delivered
     * (sales_order_entry.php :827), and its quantities may drop below what was
     * delivered — every generated invoice raises qty_sent (:616-617).
     */
    public function testARecurringOrderKeepsItsHeaderEditableAndHasNoDeliveredFloor(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        [$line] = $this->lineIds($orderNo);
        $this->deliver($orderNo, [$line => 2.0]);

        $this->update($orderNo, ['salesTypeId' => 2, 'lines' => [['id' => $line, 'quantity' => 1.0]]]);

        $this->assertSame('2', $this->orderRow($orderNo)['order_type']);
        $this->assertEquals(1, $this->lineRows($orderNo)[0]['quantity']);
    }

    public function testTheTypeReadsTheScheduleAndSnapshotsItOnDelete(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        $type = $this->container->get(SalesOrderType::class);

        $rows = $type->resolveDelete(null, ['id' => [(string) $orderNo]], $this->container);

        $this->assertSame(15, $rows[0]['recurring']['day'], 'as it was before the delete');
    }

    /**
     * Release 4 spec §3.3: with sgw_sales not active for the company, `recurring`
     * is not in that company's schema — not BAD_INPUT and null as in Release 2.
     * Simulated in-process: install_hooks() puts an extension in $Hooks only when it
     * is active; a fresh container asks the hooks again.
     */
    public function testWithoutSgwSalesTheSchemaHasNoRecurring(): void
    {
        unset($GLOBALS['Hooks']['sgw_sales']);
        $factory = require dirname(__DIR__, 4) . '/graphql/container.php';
        $container = $factory(
            \FA\GraphQL\Config::fromArray([
                'secret' => '0123456789abcdef0123456789abcdef',
                'fa_root' => \FA\GraphQL\Fa\Bootstrap::defaultRoot(),
            ]),
            new \FA\GraphQL\RequestInfo(false, 'phpunit 127.0.0.1')
        );

        $this->assertNotContains(
            'sgw_sales',
            $container->get(\FA\GraphQL\Extension\Extensions::class)->loaded()->names()
        );
        $schema = $container->get(\GraphQL\Type\Schema::class);
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderType')->getFields());
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderCreateInput')->getFields());
        $this->assertNull($schema->getType('Recurrence'));
    }

    /**
     * Release 2 spec section 3.3: sgw_sales active but its sales_recurring not at
     * update_1.4.sql's shape is the installation's problem, FA_REJECTED naming the
     * script, and the order goes with it. The shape check reads information_schema
     * under the company's table prefix; pointing the company at a prefix with no
     * sales_recurring gives the missing-table case without DDL (which would commit).
     * FrontAccounting's own queries still use TB_PREF, so the order itself is written.
     */
    public function testAScheduleTableNotAtThe14ShapeIsRejectedNamingTheScript(): void
    {
        $connection = ['tbpref' => 'gqlt_none_'] + $GLOBALS['db_connections'][0];
        CompanyContext::set(0, $connection);
        $schedule = new RecurrenceParticipant();
        $this->container->set(RecurrenceParticipant::class, $schedule);
        $orders = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_sales_orders')->fetchColumn();

        try {
            $this->createOrder(['recurring' => $this->monthly()]);
            $this->fail('accepted');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('gqlt_none_sales_recurring', $e->getMessage());
            $this->assertStringContainsString('update_1.4.sql', $e->getMessage());
            $this->assertSame([$e->getMessage()], $e->getExtensions()['messages'] ?? null);
        }
        $this->assertSame(
            $orders,
            (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_sales_orders')->fetchColumn(),
            'the order rolls back with its schedule'
        );
        $this->assertFalse($schedule->isAvailable(), 'and the schedule reads as none');
    }
}
