<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/** A recurring sales order due on the date asked (recurringDueList). */
final class RecurringDueType extends ObjectType
{
    public function __construct(RecurrenceRepeatsType $repeats)
    {
        parent::__construct([
            'name' => 'RecurringDue',
            'description' => 'A recurring sales order that is due: its next date reached, '
                . 'or never generated and started.',
            'fields' => [
                'orderId' => ['type' => Type::nonNull(Type::id())],
                'customerId' => ['type' => Type::nonNull(Type::id())],
                'branchId' => ['type' => Type::nonNull(Type::id())],
                'reference' => ['type' => Type::string()],
                'customerRef' => ['type' => Type::string()],
                'next' => [
                    'type' => DateType::instance(),
                    'description' => 'The date it fell due: its next date, or its start when it has never '
                        . 'been generated.',
                ],
                'repeats' => ['type' => Type::nonNull($repeats)],
                'every' => ['type' => Type::nonNull(Type::int())],
                'day' => ['type' => Type::int(), 'description' => 'Monthly: the day of the month.'],
                'monthDay' => ['type' => Type::string(), 'description' => 'Yearly: MM-DD.'],
                'end' => ['type' => DateType::instance()],
            ],
        ]);
    }
}
