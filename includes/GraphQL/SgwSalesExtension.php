<?php

namespace SGW_Sales\GraphQL;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Extension\AbstractExtension;
use FA\GraphQL\Extension\ExtensionContext;
use SGW_Sales\GraphQL\Type\RecurrenceInputType;
use SGW_Sales\GraphQL\Type\RecurrenceType;

/**
 * This module's part of the FrontAccounting GraphQL API (Release 4 spec §3): an
 * order's recurring schedule, as the `recurring` field on sales orders and their
 * inputs, kept in step with the order by RecurrenceParticipant. Registered by
 * hooks_sgw_sales::graphql_extensions() — only where the GraphQL module is
 * installed, and only for a company where this module is active.
 *
 * Types and the participant come from the request's container: one of each per
 * request, so the schema holds one Recurrence, RecurrenceInput and
 * RecurrenceRepeats, and the field reads the participant that wrote.
 */
final class SgwSalesExtension extends AbstractExtension
{
    public function name(): string
    {
        return 'sgw_sales';
    }

    public function contractVersion(): string
    {
        return '1.0';
    }

    public function typeFields(ExtensionContext $c): array
    {
        $container = $c->container();

        return [
            'SalesOrderType' => [
                'recurring' => FieldBuilder::create('recurring', $container->get(RecurrenceType::class))
                    ->setDescription('The recurring schedule, when sgw_sales is active and the order has one.')
                    ->setResolver(function (array $row, $args, $context): ?array {
                        // A row that already carries its schedule (a snapshot) wins.
                        return array_key_exists('recurring', $row)
                            ? $row['recurring']
                            : $context->get(RecurrenceParticipant::class)->snapshot((int) $row['id']);
                    })
                    ->build(),
            ],
        ];
    }

    public function inputFields(ExtensionContext $c): array
    {
        $input = $c->container()->get(RecurrenceInputType::class);

        return [
            'SalesOrderCreateInput' => [
                'recurring' => FieldBuilder::create('recurring', $input)
                    ->setDescription('A recurring schedule. Needs the sgw_sales extension, active for the company.')
                    ->build(),
            ],
            'SalesOrderUpdateInput' => [
                'recurring' => FieldBuilder::create('recurring', $input)
                    ->setDescription('Set or replace the recurring schedule; to end it, give an end. Needs sgw_sales.')
                    ->build(),
            ],
        ];
    }

    public function participants(ExtensionContext $c): array
    {
        return [$c->container()->get(RecurrenceParticipant::class)];
    }
}
