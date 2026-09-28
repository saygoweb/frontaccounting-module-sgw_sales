<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A whole recurring schedule; on an update it replaces the one there. To end one,
 * give it an end. Moved from the FrontAccounting GraphQL module unchanged.
 */
final class RecurrenceInputType extends InputObjectType
{
    public function __construct(RecurrenceRepeatsType $repeats)
    {
        parent::__construct([
            'name' => 'RecurrenceInput',
            'fields' => [
                'start' => ['type' => Type::nonNull(DateType::instance())],
                'end' => ['type' => DateType::instance()],
                'repeats' => ['type' => Type::nonNull($repeats)],
                'every' => ['type' => Type::nonNull(Type::int())],
                'day' => ['type' => Type::int(), 'description' => 'Monthly: 1 to 31.'],
                'monthDay' => ['type' => Type::string(), 'description' => 'Yearly: MM-DD.'],
                'auto' => ['type' => Type::boolean(), 'defaultValue' => true],
            ],
        ]);
    }
}
