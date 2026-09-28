<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use SGW_Sales\Tests\GraphQL\RecurringGenerationTestCase;

/**
 * recurringDueList (Release 4 spec §4.2).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurringDueListTest extends RecurringGenerationTestCase
{
    private const LIST = 'query ($asOf: Date) { recurringDueList(asOf: $asOf) {'
        . ' orderId customerId branchId reference customerRef next repeats every day monthDay end } }';

    /** @return array<int, array<string, mixed>> keyed by order id */
    private function due(?string $asOf = null): array
    {
        $result = $this->graphql(self::LIST, $asOf === null ? [] : ['asOf' => $asOf]);
        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result['errors'] ?? null));
        $byId = [];
        foreach ($result['data']['recurringDueList'] as $row) {
            $byId[(int) $row['orderId']] = $row;
        }
        return $byId;
    }

    public function testAStartedScheduleNeverGeneratedIsDueToday(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);

        $due = $this->due();

        $this->assertArrayHasKey($orderNo, $due);
        $this->assertSame(
            [
                'orderId' => (string) $orderNo, 'customerId' => '1', 'branchId' => '1',
                'next' => null, 'repeats' => 'MONTH', 'every' => 1, 'day' => 1, 'monthDay' => null, 'end' => null,
            ],
            array_intersect_key($due[$orderNo], array_flip(
                ['orderId', 'customerId', 'branchId', 'next', 'repeats', 'every', 'day', 'monthDay', 'end']
            ))
        );
    }

    public function testAScheduleStartingLaterIsDueOnlyFromItsStart(): void
    {
        $start = (new \DateTime('first day of next month'))->format('Y-m-d');
        $orderNo = $this->recurringOrder($start, 1);

        $this->assertArrayNotHasKey($orderNo, $this->due());
        $this->assertArrayHasKey($orderNo, $this->due($start));
    }

    public function testAnEndedScheduleIsNotDue(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1, date('Y-m-d'));

        $this->assertArrayNotHasKey($orderNo, $this->due());
    }

    public function testAGeneratedOrderIsNoLongerDue(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);
        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result['errors'] ?? null));
        $this->assertNull($result['data']['recurringGenerate'][0]['error']);

        $this->assertArrayNotHasKey($orderNo, $this->due());
    }

    public function testListingNeedsSalesTransactionView(): void
    {
        // noapi's role lacks SA_GRAPHQL; sgwpanel holds 3073. apiorders' "GraphQL Orders"
        // role (seed: areas '3073;3075;91236') has SA_GRAPHQL: taken without 3073 here.
        $areas = function (): string {
            return (string) $this->pdo()->query("SELECT areas FROM 0_security_roles WHERE role = 'GraphQL Orders'")
                ->fetchColumn();
        };
        $before = $areas();
        $this->assertStringStartsWith('3073;', $before);
        $this->pdo()->exec(
            "UPDATE 0_security_roles SET areas = SUBSTRING(areas, 6) WHERE role = 'GraphQL Orders'"
            . " AND areas LIKE '3073;%'"
        );
        try {
            $this->enterAs('apiorders');
            $result = $this->graphql(self::LIST);
            $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'] ?? null);
            $this->assertNull($result['data'] ?? null);
        } finally {
            $this->pdo()->exec(
                "UPDATE 0_security_roles SET areas = CONCAT('3073;', areas) WHERE role = 'GraphQL Orders'"
                . " AND areas NOT LIKE '3073;%'"
            );
        }
        $this->assertSame($before, $areas(), 'the role is back as it was');
    }
}
