<?php

namespace SGW_Sales\GraphQL\Type;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

final class GenerateErrorType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'RecurringGenerateError',
            'description' => 'Why one item was not generated: '
                . 'NOT_FOUND, NOT_DUE, ENDED, BAD_INPUT, FA_REJECTED or INTERNAL.',
            'fields' => [
                'code' => ['type' => Type::nonNull(Type::string())],
                'message' => ['type' => Type::nonNull(Type::string())],
                'field' => ['type' => Type::string(), 'description' => 'The input it is about, for BAD_INPUT.'],
            ],
        ]);
    }
}
