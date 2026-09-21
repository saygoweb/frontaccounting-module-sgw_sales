<?php

namespace SGW_Sales\service;

/**
 * What RecurringInvoiceService::generate() did.
 */
class GeneratedInvoice
{
    /** @var int The sales order the invoice was raised from */
    public $orderNo;

    /** @var int The invoice's transaction number */
    public $invoiceNo;

    /** @var string The comment on the invoice: the period it covers */
    public $comment;

    /** @var string The recurrence's new dt_next, Y-m-d */
    public $dtNext;

    /** @var bool Whether the invoice was handed to FrontAccounting to email */
    public $emailed;

    public function __construct(int $orderNo, int $invoiceNo, string $comment, string $dtNext, bool $emailed)
    {
        $this->orderNo = $orderNo;
        $this->invoiceNo = $invoiceNo;
        $this->comment = $comment;
        $this->dtNext = $dtNext;
        $this->emailed = $emailed;
    }
}
