<?php

namespace SGW_Sales\service;

use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\db\SalesRecurringModel;

/**
 * Recurring invoices: which orders are due, and raising the invoice for one.
 *
 * No view and no $_POST in its interface, so the Generate Recurring Invoices
 * page and an API can both call it. It does need FrontAccounting booted and a
 * user logged in: the invoice is written by FrontAccounting's own Cart, which
 * is what posts to the ledger, and Anorm must be connected (hooks.php does that).
 */
class RecurringInvoiceService
{
    /**
     * Recurrences that have not ended, soonest first.
     * @param bool $all true to include those not yet due
     * @return \Generator<GenerateRecurringModel>
     */
    public function due(bool $all = false)
    {
        return GenerateRecurringModel::find($all);
    }

    /**
     * Raise the next invoice for a recurring sales order and move the recurrence
     * on to its next date.
     *
     * An order that is not yet due is invoiced all the same - the page lets a
     * user pick one from "Show All" - but one whose recurrence has ended is not.
     *
     * The recurrence is moved on before the invoice is emailed: once the invoice
     * exists, a failure to send it must not leave the order due again.
     *
     * @param int $orderNo The sales order's number
     * @param bool $email Email the invoice to the customer's contacts
     * @param \DateTime|null $today The date to generate as of; now by default
     * @throws RecurrenceNotFound if the order has no recurrence
     * @throws RecurrenceEnded if the recurrence's end date has passed
     */
    public function generate(int $orderNo, bool $email = true, ?\DateTime $today = null): GeneratedInvoice
    {
        $today = $today ?: new \DateTime();

        $recurrence = SalesRecurringModel::findByTransNo($orderNo);
        if (!$recurrence) {
            throw new RecurrenceNotFound("Sales order $orderNo has no recurrence");
        }
        // As GenerateRecurringModel::find(): over once dt_end is no longer in the future.
        if ($recurrence->dtEnd && $recurrence->dtEnd <= $today->format('Y-m-d')) {
            throw new RecurrenceEnded("The recurrence of sales order $orderNo ended on " . $recurrence->dtEnd);
        }

        $comment = RecurrenceSchedule::comment($recurrence, $today);
        $invoiceNo = (int) $this->generateInvoice($orderNo, $comment);

        $recurrence->dtNext = RecurrenceSchedule::nextDateAfter($recurrence, $today)->format('Y-m-d');
        $recurrence->write();

        if ($email) {
            $this->emailInvoice($invoiceNo);
        }

        return new GeneratedInvoice($orderNo, $invoiceNo, $comment, $recurrence->dtNext, $email);
    }

    /**
     * Generate the delivery and the invoice for the given $orderNo through
     * FrontAccounting's Cart. The given $comment is applied to the invoice.
     * @param int $orderNo
     * @param string $comment
     * @return int The invoice's transaction number
     */
    public function generateInvoice($orderNo, $comment)
    {
        // Everything Cart needs, asked for here because a caller that is not a
        // FrontAccounting page has not: FA's sales code takes ui.inc for granted
        // (count_array() in sales_db.inc, for one), and every FA page includes it.
        // The included files include others by $path_to_root, from the scope
        // they land in.
        global $path_to_root;
        include_once($path_to_root . '/includes/ui.inc');
        include_once($path_to_root . '/sales/includes/cart_class.inc');
        include_once($path_to_root . '/sales/includes/sales_db.inc');

        // Prepare the delivery (child of Sales Order)
        $delivery = new \Cart(ST_SALESORDER, array($orderNo), true);

        $delivery->reference = 'auto';
        foreach ($delivery->line_items as $item) {
            $item->qty_done = 0;
            $item->qty_dispatched = $item->quantity;
            $new_price = get_price(
                $item->stock_id,
                $delivery->customer_currency,
                $delivery->sales_type,
                $delivery->price_factor,
                $delivery->document_date
            );
            if ($new_price != 0) { // use template price if no price is currently set for the item.
                $item->price = $new_price;
            }
        }
        $delivery->Comments = 'Auto generated recurring delivery.';

        $trans_no = $delivery->write(1);

        // Prepare the invoice (child of Delivery)
        $invoice = new \Cart(ST_CUSTDELIVERY, array($trans_no), true);
        foreach ($invoice->line_items as $item) {
            $item->qty_done = 0;
            $new_price = get_price(
                $item->stock_id,
                $invoice->customer_currency,
                $invoice->sales_type,
                $invoice->price_factor,
                $invoice->document_date
            );
            if ($new_price != 0) { // use template price if no price is currently set for the item.
                $item->price = $new_price;
            }
        }
        // no way to register cash payment with recurrent invoice at once
        $invoice->payment_terms['cash_sale'] = false;
        $invoice->Comments = $comment;

        return $invoice->write(1);
    }

    /**
     * Email the given $invoiceNo (transaction number) through FrontAccounting's
     * invoice report, which takes its parameters from $_POST and nowhere else.
     * What the caller had there is put back afterwards.
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
}
