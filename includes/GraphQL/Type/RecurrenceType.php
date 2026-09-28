<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * An order's recurring schedule, nested in the order (Release 2 spec §4.5;
 * Release 4 spec §3). Moved from the FrontAccounting GraphQL module unchanged.
 */
final class RecurrenceType extends ObjectType
{
    public function __construct(RecurrenceRepeatsType $repeats)
    {
        parent::__construct([
            'name' => 'Recurrence',
            'description' => 'An order\'s recurring schedule (sgw_sales).',
            'fields' => [
                'start' => ['type' => Type::nonNull(DateType::instance())],
                'end' => ['type' => DateType::instance(), 'description' => 'None: it does not end.'],
                'next' => [
                    'type' => DateType::instance(),
                    'description' => 'The next invoice date, as sgw_sales has computed it; none until it has.',
                ],
                'repeats' => ['type' => Type::nonNull($repeats)],
                'every' => ['type' => Type::nonNull(Type::int()), 'description' => 'Every this many months or years.'],
                'day' => ['type' => Type::int(), 'description' => 'Monthly: the day of the month.'],
                'monthDay' => ['type' => Type::string(), 'description' => 'Yearly: the date, MM-DD.'],
                'auto' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Generated automatically.'],
            ],
        ]);
    }
}
