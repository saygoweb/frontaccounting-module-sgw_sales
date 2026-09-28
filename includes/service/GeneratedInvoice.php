<?php

namespace SGW_Sales\service;

/**
 * What RecurringInvoiceService::generate() did: one delivery and one invoice, and
 * the recurrence moved on. Emailing is the caller's (the page's rep107, or the
 * API's report child), after the transaction has committed.
 */
class GeneratedInvoice
{
    /** @var int The sales order the invoice was raised from */
    public $orderNo;

    /** @var int The delivery's transaction number */
    public $deliveryNo;

    /** @var int The invoice's transaction number */
    public $invoiceNo;

    /** @var string The comment on the invoice: the period it covers */
    public $comment;

    /** @var string The recurrence's new dt_next, Y-m-d */
    public $dtNext;

    public function __construct(int $orderNo, int $deliveryNo, int $invoiceNo, string $comment, string $dtNext)
    {
        $this->orderNo = $orderNo;
        $this->deliveryNo = $deliveryNo;
        $this->invoiceNo = $invoiceNo;
        $this->comment = $comment;
        $this->dtNext = $dtNext;
    }
}
