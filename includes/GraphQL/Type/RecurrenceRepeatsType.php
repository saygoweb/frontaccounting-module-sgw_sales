<?php

namespace SGW_Sales\GraphQL\Type;

use GraphQL\Type\Definition\EnumType;

/**
 * How a recurring order repeats. The values are this module's own column values
 * (sales_recurring.repeats), so a parsed input needs no mapping. Moved from the
 * FrontAccounting GraphQL module unchanged (Release 4 spec §3.3).
 */
final class RecurrenceRepeatsType extends EnumType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'RecurrenceRepeats',
            'description' => 'How a recurring order repeats (sgw_sales).',
            'values' => [
                'MONTH' => ['value' => 'month'],
                'YEAR' => ['value' => 'year'],
            ],
        ]);
    }
}
