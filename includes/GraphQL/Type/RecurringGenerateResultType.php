<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use FA\GraphQL\Type\Invoice\InvoiceEmailResultType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

final class RecurringGenerateResultType extends ObjectType
{
    public function __construct(InvoiceEmailResultType $email, GenerateErrorType $error)
    {
        parent::__construct([
            'name' => 'RecurringGenerateResult',
            'description' => 'One item of recurringGenerate. '
                . 'Items are independent: each is written, or not, on its own.',
            'fields' => [
                'orderId' => ['type' => Type::nonNull(Type::id())],
                'invoiceId' => ['type' => Type::id()],
                'deliveryId' => ['type' => Type::id()],
                'next' => ['type' => DateType::instance(), 'description' => "The recurrence's new next date."],
                'email' => [
                    'type' => $email,
                    'description' => 'The email outcome when asked for and the invoice was written.',
                ],
                'error' => ['type' => $error, 'description' => 'Why nothing was written for this item.'],
            ],
        ]);
    }
}
