<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

final class RecurringGenerateInputType extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'RecurringGenerateInput',
            'fields' => [
                'orderId' => ['type' => Type::nonNull(Type::id())],
                'date' => [
                    'type' => Type::nonNull(DateType::instance()),
                    'description' => 'The invoice and delivery date; the order must be due on it.',
                ],
                'email' => [
                    'type' => Type::boolean(),
                    'defaultValue' => false,
                    'description' => "Email the invoice through FrontAccounting's invoice report once it is written.",
                ],
            ],
        ]);
    }
}
