<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\Service\ServiceCall;
use SGW_Sales\GraphQL\RecurrenceParticipant;
use SGW_Sales\Tests\GraphQL\ExtensionTestCase;

/**
 * The participant on its own, against FrontAccounting in-process: what the module's
 * SalesOrderService will call inside an order's transaction (Release 4 spec §2.4).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurrenceParticipantTest extends ExtensionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->participant()->isAvailable()) {
            $this->markTestSkipped('sales_recurring is not at its update_1.4.sql shape in this stack.');
        }
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

    public function testTheContainerGivesOneParticipantPerRequest(): void
    {
        $this->assertSame(
            $this->container->get(RecurrenceParticipant::class),
            $this->container->get(RecurrenceParticipant::class)
        );
    }

    public function testAfterCreateWritesTheScheduleAndReadGivesItBack(): void
    {
        $orderNo = $this->createOrder();
        ServiceCall::run(function () use ($orderNo): void {
            $this->participant()->afterCreate($orderNo, ['recurring' => $this->monthly()]);
        });

        $row = $this->scheduleRow($orderNo);
        $this->assertSame($this->today(), $row['dt_start']);
        $this->assertNull($row['dt_next'], 'the generation service computes it');
        $this->assertSame('15', $row['occur']);
        $read = $this->participant()->read($orderNo);
        $this->assertSame('month', $read['repeats']);
        $this->assertSame(15, $read['day']);
    }

    public function testNoRecurringInTheInputWritesNothing(): void
    {
        $orderNo = $this->createOrder();
        ServiceCall::run(function () use ($orderNo): void {
            $this->participant()->afterCreate($orderNo, ['recurring' => null]);
            $this->participant()->afterUpdate($orderNo, []);
        });

        $this->assertNull($this->scheduleRow($orderNo));
        $this->assertFalse($this->participant()->isRelaxed($orderNo, []));
        $this->assertTrue($this->participant()->isRelaxed($orderNo, ['recurring' => $this->monthly()]));
    }

    public function testValidateRefusesABadScheduleNamingItsField(): void
    {
        try {
            $this->participant()->validate(['recurring' => $this->monthly(['day' => null])]);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame('recurring.day', $e->field());
        }
        $this->participant()->validate(['recurring' => null]);
        $this->addToAssertionCount(1);
    }

    public function testAnExistingScheduleRelaxesTheOrder(): void
    {
        $orderNo = $this->createOrder();
        ServiceCall::run(function () use ($orderNo): void {
            $this->participant()->afterCreate($orderNo, ['recurring' => $this->monthly()]);
        });

        $this->assertTrue($this->container->get(RecurrenceParticipant::class)->isRelaxed($orderNo, []));
    }

    public function testDeleteRemovesTheRowButTheSnapshotKeepsItForThisRequest(): void
    {
        $orderNo = $this->createOrder();
        ServiceCall::run(function () use ($orderNo): void {
            $this->participant()->afterCreate($orderNo, ['recurring' => $this->monthly()]);
            $this->participant()->afterDelete($orderNo);
        });

        $this->assertNull($this->scheduleRow($orderNo));
        $this->assertNull($this->participant()->read($orderNo));
        $this->assertSame(15, $this->participant()->snapshot($orderNo)['day'], 'as it was before the delete');
    }

    public function testCloseEndsTheScheduleTodayUnlessItEndsEarlier(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();
        ServiceCall::run(function () use ($first, $second): void {
            $this->participant()->afterCreate($first, ['recurring' => $this->monthly()]);
            $this->participant()->afterCreate($second, ['recurring' => $this->monthly()]);
        });
        $this->pdo()->prepare("UPDATE 0_sales_recurring SET dt_end = '2000-01-01' WHERE trans_no = ?")
            ->execute([$second]);

        ServiceCall::run(function () use ($first, $second): void {
            $this->participant()->afterClose($first);
            $this->participant()->afterClose($second);
        });

        $this->assertSame($this->today(), $this->scheduleRow($first)['dt_end']);
        $this->assertSame('2000-01-01', $this->scheduleRow($second)['dt_end']);
        $this->assertNull($this->participant()->snapshot($first)['end'], 'the snapshot is before the close');
    }

    public function testATransactionThatFailsTakesTheScheduleWithIt(): void
    {
        $orderNo = $this->createOrder();
        try {
            ServiceCall::run(function () use ($orderNo): void {
                $this->participant()->afterCreate($orderNo, ['recurring' => $this->monthly()]);
                throw new BadInput('the order after it was refused', 'lines');
            });
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame('lines', $e->field());
        }

        $this->assertNull($this->scheduleRow($orderNo), 'rolled back with the transaction');
    }

    /**
     * The table-shape check reads information_schema under the company's table
     * prefix; pointing the company at a prefix with no sales_recurring gives the
     * missing-table case without DDL (which would commit).
     */
    public function testATableNotAtThe14ShapeIsRejectedNamingTheScript(): void
    {
        $connection = ['tbpref' => 'gqlt_none_'] + $GLOBALS['db_connections'][0];
        CompanyContext::set(0, $connection);
        $participant = new RecurrenceParticipant();

        $this->assertFalse($participant->isAvailable());
        try {
            $participant->validate(['recurring' => $this->monthly()]);
            $this->fail('accepted');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('gqlt_none_sales_recurring', $e->getMessage());
            $this->assertStringContainsString('update_1.4.sql', $e->getMessage());
            $this->assertSame([$e->getMessage()], $e->getExtensions()['messages'] ?? null);
        }
        $this->assertNull($participant->read(1), 'and a schedule reads as none');
    }
}
