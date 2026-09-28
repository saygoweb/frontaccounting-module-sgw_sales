<?php

namespace SGW_Sales\Tests\GraphQL;

use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use SGW_Sales\GraphQL\RecurrenceParticipant;

/**
 * The GraphQL module's sales-order test base (FrontAccounting in-process, signed
 * in as apitest, every order it makes purged in tearDown), plus what an extension
 * test needs to know: whether this extension is loaded for the company.
 *
 * Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class ExtensionTestCase extends SalesOrderTestCase
{
    protected function extensionLoaded(): bool
    {
        return in_array('sgw_sales', $this->container->get(Extensions::class)->loaded()->names(), true);
    }

    /**
     * Spec §6: while the GraphQL module still serves `recurring` itself, its loader
     * drops this extension (the field and the Recurrence* type names clash). The
     * module's Task 3 removes its own; from then on these tests must run, not skip.
     */
    protected function requireExtensionServesRecurrence(): void
    {
        if (!$this->extensionLoaded()) {
            $this->markTestSkipped(
                'The GraphQL module still serves recurring itself, so its loader drops the sgw_sales extension '
                . '(Release 4 plan, Task 3 removes it).'
            );
        }
        if (!$this->participant()->isAvailable()) {
            $this->markTestSkipped('sales_recurring is not at its update_1.4.sql shape in this stack.');
        }
    }

    protected function participant(): RecurrenceParticipant
    {
        return $this->container->get(RecurrenceParticipant::class);
    }
}
