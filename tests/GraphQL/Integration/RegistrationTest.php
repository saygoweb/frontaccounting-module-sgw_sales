<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Extension\ExtensionRegistry;
use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Type\Invoice\InvoiceEmailResultType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use SGW_Sales\GraphQL\RecurrenceParticipant;
use SGW_Sales\GraphQL\SgwSalesExtension;
use SGW_Sales\GraphQL\Type\RecurrenceInputType;
use SGW_Sales\GraphQL\Type\RecurrenceType;
use SGW_Sales\Tests\GraphQL\ExtensionTestCase;

/**
 * Release 4 spec §2.1, §3.1: the module finds this extension through
 * FrontAccounting's hooks, per company.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RegistrationTest extends ExtensionTestCase
{
    public function testTheHookRegistersTheExtension(): void
    {
        $registry = new ExtensionRegistry();
        hook_invoke_all(ExtensionRegistry::HOOK, $registry);

        $names = array_map(function ($extension): string {
            return $extension->name();
        }, $registry->all());
        $this->assertContains('sgw_sales', $names);
    }

    public function testNotActiveForTheCompanyItIsNotRegistered(): void
    {
        // install_hooks() puts an extension in $Hooks only when it is active for the
        // company; removing it is what an inactive company looks like.
        unset($GLOBALS['Hooks']['sgw_sales']);
        $registry = new ExtensionRegistry();
        hook_invoke_all(ExtensionRegistry::HOOK, $registry);

        $names = array_map(function ($extension): string {
            return $extension->name();
        }, $registry->all());
        $this->assertNotContains('sgw_sales', $names);
    }

    public function testItContributesRecurringAndItsParticipant(): void
    {
        $extension = new SgwSalesExtension();
        $context = new ExtensionContext($this->container);

        $this->assertSame('sgw_sales', $extension->name());
        $this->assertSame('1.0', $extension->contractVersion());
        $this->assertSame(['recurringDueList'], array_keys($extension->queryFields($context)));
        $this->assertSame(['recurringGenerate'], array_keys($extension->mutationFields($context)));

        $types = $extension->typeFields($context);
        $this->assertSame(['SalesOrderType'], array_keys($types));
        $this->assertSame(['recurring'], array_keys($types['SalesOrderType']));
        $this->assertSame($this->container->get(RecurrenceType::class), $types['SalesOrderType']['recurring']['type']);

        $inputs = $extension->inputFields($context);
        $this->assertSame(['SalesOrderCreateInput', 'SalesOrderUpdateInput'], array_keys($inputs));
        foreach ($inputs as $fields) {
            $this->assertSame(['recurring'], array_keys($fields));
            // Nullable (spec §2.5): the type is the input object itself, not NonNull.
            $this->assertSame($this->container->get(RecurrenceInputType::class), $fields['recurring']['type']);
        }

        $participants = $extension->participants($context);
        $this->assertCount(1, $participants);
        $this->assertSame($this->container->get(RecurrenceParticipant::class), $participants[0]);
    }

    /**
     * RecurringGenerateResult.email is the core's InvoiceEmailResult, the same
     * instance: the loader keeps the extension (the same type object is no clash) and
     * the schema holds one InvoiceEmailResult.
     */
    public function testTheGenerationResultReusesTheCoresInvoiceEmailResult(): void
    {
        $this->assertContains('sgw_sales', $this->container->get(Extensions::class)->loaded()->names());
        $schema = $this->container->get(Schema::class);
        $result = $schema->getType('RecurringGenerateResult');
        $this->assertInstanceOf(ObjectType::class, $result);

        $this->assertSame($this->container->get(InvoiceEmailResultType::class), $result->getField('email')->getType());
        $this->assertSame($this->container->get(InvoiceEmailResultType::class), $schema->getType('InvoiceEmailResult'));
        $schema->assertValid();
    }

    public function testTheRecurringFieldReadsThroughTheParticipantsSnapshot(): void
    {
        $extension = new SgwSalesExtension();
        $field = $extension->typeFields(new ExtensionContext($this->container))['SalesOrderType']['recurring'];
        $orderNo = $this->createOrder();

        $resolve = $field['resolve'];
        $this->assertNull($resolve(['id' => (string) $orderNo], [], $this->container));
        $this->assertSame(
            ['day' => 3],
            $resolve(['id' => (string) $orderNo, 'recurring' => ['day' => 3]], [], $this->container)
        );
    }
}
