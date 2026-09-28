<?php

namespace SGW_Sales\service;

use SGW_Sales\db\GenerateRecurringModel;

/**
 * Recurring invoices: which orders are due, and raising the invoice for one.
 *
 * Used by the Generate Recurring Invoices page and by the GraphQL extension.
 * Needs FrontAccounting booted and a user logged in: the documents are written by
 * FrontAccounting's own Cart, which is what posts to the ledger.
 *
 * generate() writes the delivery, the invoice and the recurrence's next date in
 * one FrontAccounting transaction (on FrontAccounting's connection, not Anorm's),
 * so a failure leaves nothing behind and a retry after success finds the order
 * no longer due. It does not email: the caller does, after the commit.
 */
class RecurringInvoiceService
{
    /**
     * Recurrences due on $asOf (all that have not ended, with $all), soonest first.
     *
     * @param \PDO|null $pdo the connection to read on; Anorm's default by default
     * @return GenerateRecurringModel[]
     */
    public function due(\DateTimeInterface $asOf, bool $all = false, ?\PDO $pdo = null): array
    {
        $found = GenerateRecurringModel::find($all, $asOf, $pdo);
        if (!$found) {
            return [];
        }
        return is_array($found) ? $found : iterator_to_array($found, false);
    }

    /**
     * Deliver and invoice a recurring sales order dated $invoiceDate, and move its
     * recurrence on to the next date after it.
     *
     * @param bool $allowEarly invoice an order that is not yet due (the page's
     *   "Show All", where a person picks it); never from the API
     * @throws RecurrenceNotFound the order has no recurrence, or no longer exists
     * @throws RecurrenceEnded the recurrence has ended by $invoiceDate
     * @throws RecurrenceNotDue not due on $invoiceDate (and not $allowEarly)
     * @throws GenerationRefused a check refused it; nothing was written
     */
    public function generate(int $orderNo, \DateTimeInterface $invoiceDate, bool $allowEarly = false): GeneratedInvoice
    {
        self::includeFa();
        $date = \DateTime::createFromFormat('!Y-m-d', $invoiceDate->format('Y-m-d'));
        $ymd = $date->format('Y-m-d');

        begin_transaction();
        try {
            // Locked for the whole transaction: two generations of one order queue,
            // and the second finds it no longer due.
            $recurrence = self::lockRecurrence($orderNo);
            if ($recurrence === null || !self::salesOrderExists($orderNo)) {
                throw new RecurrenceNotFound("Sales order $orderNo has no recurrence");
            }
            if ($recurrence->dtEnd && $recurrence->dtEnd <= $ymd) {
                throw new RecurrenceEnded("The recurrence of sales order $orderNo ended on " . $recurrence->dtEnd);
            }
            if (!$allowEarly && !self::isDue($recurrence, $ymd)) {
                throw new RecurrenceNotDue(
                    "Sales order $orderNo is not due on $ymd: next due " . ($recurrence->dtNext ?: $recurrence->dtStart)
                );
            }
            $faDate = sql2date($ymd);
            if (!is_date_in_fiscalyear($faDate)) {
                throw new GenerationRefused("$ymd is out of the fiscal year or closed for further data entry.", 'date');
            }

            $comment = RecurrenceSchedule::comment($recurrence, $date);
            $deliveryNo = $this->writeDelivery($orderNo, $faDate);
            $invoiceNo = $this->writeInvoice($deliveryNo, $faDate, $comment);

            $next = RecurrenceSchedule::nextDateAfter($recurrence, $date)->format('Y-m-d');
            db_query(
                'UPDATE ' . TB_PREF . 'sales_recurring SET dt_next=' . db_escape($next)
                . ' WHERE trans_no=' . db_escape($orderNo),
                'The recurrence could not be moved on'
            );
            commit_transaction();
        } catch (\Throwable $e) {
            cancel_transaction();
            throw $e;
        }

        return new GeneratedInvoice($orderNo, $deliveryNo, $invoiceNo, $comment, $next);
    }

    /**
     * The delivery: every line's full quantity again (a recurring order is delivered
     * whole each period), repriced from the price list on the date.
     */
    protected function writeDelivery(int $orderNo, string $faDate): int
    {
        global $SysPrefs;

        $delivery = new \Cart(ST_SALESORDER, array($orderNo), true);
        // customer_delivery.php :408-415 shows an on-hold customer no form.
        $customer = get_customer_to_order($delivery->customer_id);
        if ($customer && (int) $customer['dissallow_invoices'] === 1) {
            throw new GenerationRefused(
                'The selected customer account is currently on hold.'
                . ' Please contact the credit control personnel to discuss.'
            );
        }
        $delivery->document_date = $faDate;
        $delivery->due_date = $faDate;
        $delivery->reference = 'auto';
        foreach ($delivery->line_items as $item) {
            $item->qty_done = 0;
            $item->qty_dispatched = $item->quantity;
            self::reprice($item, $delivery);
        }
        $delivery->Comments = 'Auto generated recurring delivery.';
        // FrontAccounting writes with 1.0 where a rate is missing (includes/banking.inc:30-45).
        if (!db_has_currency_rates($delivery->customer_currency, $faDate)) {
            throw new GenerationRefused(
                'There is no exchange rate for ' . $delivery->customer_currency . ' as of ' . date2sql($faDate) . '.',
                'date'
            );
        }
        // customer_delivery.php :205-209
        if (!$SysPrefs->allow_negative_stock() && ($low = $delivery->check_qoh())) {
            $message = 'This document cannot be processed because there is insufficient quantity for items marked.';
            throw new GenerationRefused($message, null, array_merge([$message], array_map('strval', $low)));
        }

        $no = $delivery->write(1);
        if (!$no || $no == -1) {
            throw new GenerationRefused('FrontAccounting did not write the delivery.');
        }
        return (int) $no;
    }

    /**
     * The invoice for that delivery, dated $faDate, due by the payment terms from that
     * date (not prepare_child()'s today), with its own reference for that date.
     */
    protected function writeInvoice(int $deliveryNo, string $faDate, string $comment): int
    {
        global $Refs;

        $invoice = new \Cart(ST_CUSTDELIVERY, array($deliveryNo), true);
        $invoice->document_date = $faDate;
        foreach ($invoice->line_items as $item) {
            $item->qty_done = 0;
            self::reprice($item, $invoice);
        }
        // No way to register a cash payment with a recurring invoice at once.
        $invoice->payment_terms['cash_sale'] = false;
        $invoice->due_date = get_invoice_duedate($invoice->payment, $invoice->document_date);
        $invoice->reference = $Refs->get_next(
            ST_SALESINVOICE,
            null,
            array('date' => $faDate, 'customer' => $invoice->customer_id, 'branch' => $invoice->Branch)
        );
        $invoice->Comments = $comment;

        $no = $invoice->write(1);
        if (!$no || $no == -1) {
            throw new GenerationRefused('FrontAccounting did not write the invoice.');
        }
        return (int) $no;
    }

    /**
     * Email the given $invoiceNo (transaction number) through FrontAccounting's
     * invoice report, which takes its parameters from $_POST and nowhere else.
     * What the caller had there is put back afterwards. The page's path: a
     * FrontAccounting page has session.inc loaded; the API emails through its own
     * report child instead.
     * @param int $invoiceNo
     */
    public function emailInvoice($invoiceNo)
    {
        $saved = array();
        $keys = array('REP_ID');
        for ($i = 0; $i < 8; $i++) {
            $keys[] = 'PARAM_' . $i;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $_POST)) {
                $saved[$key] = $_POST[$key];
            }
        }

        /* rep107.php prints as it is included, unless the PARAM_x values are
         * false, in which case it exits early. See rep107.php for details.
         * PARAM_0..7: from, to, currency, email, pay_service, comments,
         * customer, orientation.
         */
        for ($i = 0; $i < 8; $i++) {
            $_POST['PARAM_' . $i] = false;
        }
        require_once(__DIR__ . '/../../../../reporting/rep107.php');

        $_POST['PARAM_0'] = $invoiceNo;
        $_POST['PARAM_1'] = $invoiceNo;
        $_POST['PARAM_2'] = ALL_TEXT; // Empty string
        $_POST['PARAM_3'] = 1;
        $_POST['REP_ID'] = '107';
        try {
            print_invoices();
        } finally {
            foreach ($keys as $key) {
                unset($_POST[$key]);
            }
            foreach ($saved as $key => $value) {
                $_POST[$key] = $value;
            }
        }
    }

    /** Due on $ymd: next date reached, or never generated and already started. */
    private static function isDue(\stdClass $recurrence, string $ymd): bool
    {
        if ($recurrence->dtNext) {
            return $recurrence->dtNext <= $ymd;
        }
        return $recurrence->dtStart <= $ymd;
    }

    /**
     * The recurrence row on FrontAccounting's connection, locked (FOR UPDATE), as the
     * plain object RecurrenceSchedule reads. SalesRecurringModel is not used: it
     * reads on Anorm's own connection, outside this transaction.
     */
    private static function lockRecurrence(int $orderNo): ?\stdClass
    {
        $row = db_fetch_assoc(db_query(
            'SELECT * FROM ' . TB_PREF . 'sales_recurring WHERE trans_no=' . db_escape($orderNo) . ' FOR UPDATE',
            'The recurrence could not be read'
        ));
        if (!$row) {
            return null;
        }
        $recurrence = new \stdClass();
        $recurrence->transNo = (int) $row['trans_no'];
        $recurrence->dtStart = $row['dt_start'];
        $recurrence->dtEnd = self::sqlDate($row['dt_end'] ?? null);
        $recurrence->dtNext = self::sqlDate($row['dt_next'] ?? null);
        $recurrence->auto = (int) $row['auto'];
        $recurrence->repeats = $row['repeats'];
        $recurrence->every = (int) $row['every'];
        $recurrence->occur = (string) $row['occur'];
        return $recurrence;
    }

    private static function sqlDate(?string $value): ?string
    {
        return $value === null || $value === '' || $value === '0000-00-00' ? null : $value;
    }

    private static function salesOrderExists(int $orderNo): bool
    {
        return (bool) db_fetch(db_query(
            'SELECT 1 FROM ' . TB_PREF . 'sales_orders WHERE trans_type=' . ST_SALESORDER
            . ' AND order_no=' . db_escape($orderNo),
            'The sales order could not be read'
        ));
    }

    /** The template price where the price list has none (as before). */
    private static function reprice($item, \Cart $cart): void
    {
        $price = get_price(
            $item->stock_id,
            $cart->customer_currency,
            $cart->sales_type,
            $cart->price_factor,
            $cart->document_date
        );
        if ($price != 0) {
            $item->price = $price;
        }
    }

    /**
     * Everything Cart needs, asked for here because a caller that is not a
     * FrontAccounting page has not: FA's sales code takes ui.inc for granted
     * (count_array() in sales_db.inc, for one), and every FA page includes it.
     * The included files include others by $path_to_root, from the scope they land in.
     */
    private static function includeFa(): void
    {
        global $path_to_root;
        include_once($path_to_root . '/includes/ui.inc');
        include_once($path_to_root . '/sales/includes/cart_class.inc');
        include_once($path_to_root . '/sales/includes/sales_db.inc');
    }
}
