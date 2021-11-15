<?php
namespace SGW_Sales\controller;

use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\db\SalesRecurringModel;
use ActiveRecord\DateTime;

class GenerateRecurring {
	
	/**
	 * @var \GenerateRecurringView
	 */
	private $_view;
	
	public function __construct($view) {
		$this->_view = $view;
		$this->_force = self::FORCE_NO;
	}
	
	const FORCE_NO    = 'no';
	const FORCE_CHECK = '1';
	const FORCE_CLEAR = '0';
	
	private $_force;

	private $_showAll;
	
	public function run() {
		global $Ajax;
		if (get_post('GenerateInvoices')) {
			$Ajax->activate('_page_body');
			foreach ($_POST as $key => $value) {
				if (strpos($key, 's_') === false) {
					continue;
				}
				if ($value) {
					$parts = explode('_', $key);
					$orderNo = $parts[1];
					$model = SalesRecurringModel::readByTransNo($orderNo);
					$invoiceNo = $this->generateInvoice($orderNo, self::comment($model, new \DateTime()));
					$this->emailInvoice($invoiceNo);
					$next = self::nextDateAfter($model, new \DateTime());
					$model->dtNext = $next->format('Y-m-d');
					$model->write();
				}
			}
			return;
		}
		if (list_updated('select_all')) {
			$Ajax->activate('_page_body');
			$this->_force = check_value('select_all') ? self::FORCE_CHECK : self::FORCE_CLEAR; 
		}
		if (list_updated('show_all')) {
			$Ajax->activate('_page_body');
			$this->_showAll = check_value('show_all');
		}
		$this->_view->viewList();
	}
	
	/**
	 * Email the given $invoiceNo (transaction number)
	 * @param int $invoiceNo
	 */
	public function emailInvoice($invoiceNo) {
		/* Note that in order to ensure that invoices don't print when require_once is
		 * called, we unset the PARAM_X values which ensure that the printing exits early.
		 * See rep107.php for details.
		 */
		unset($_POST['PARAM_0']); // from
		unset($_POST['PARAM_1']); // to
		unset($_POST['PARAM_2']); // currency
		unset($_POST['PARAM_3']); // email
		unset($_POST['PARAM_4']); // pay_service
		unset($_POST['PARAM_5']); // comments
		unset($_POST['PARAM_6']); // customer
		unset($_POST['PARAM_7']); // orientation
		for ($i = 0; $i < 8; $i++) {
			$_POST['PARAM_' . $i] = false;
		}
		require_once(__DIR__ . '/../../../../reporting/rep107.php');
		
		$_POST['PARAM_0'] = $invoiceNo;
		$_POST['PARAM_1'] = $invoiceNo;
		$_POST['PARAM_2'] = ALL_TEXT; // Empty string
		$_POST['PARAM_3'] = 1;
		$_POST['REP_ID'] = '107';
		print_invoices();
	}
	
	/**
	 * Generate the next invoice for the given $orderNo (transaction number)
	 * The given $comment is applied to the invoice. 
	 * @param int $orderNo
	 * @param string $comment
	 */
	public function generateInvoice($orderNo, $comment) {
		global $Refs;

		// Prepare the delivery (child of Sales Order)
		$delivery = new \Cart(ST_SALESORDER, array($orderNo), true);
		
		$delivery->reference = 'auto';
		foreach ($delivery->line_items as $line_no=>$item) {
			$item->qty_done = 0;
			$item->qty_dispatched = $item->quantity;
			$new_price = get_price($item->stock_id, $delivery->customer_currency,
				$delivery->sales_type, $delivery->price_factor, $delivery->document_date);
			if ($new_price != 0)	// use template price if no price is currently set for the item.
				$item->price = $new_price;
		}
		$delivery->Comments = 'Auto generated recurring delivery.';
		
		$trans_no = $delivery->write(1);
		
		// Prepare the invoice (child of Delivery)
		$invoice = new \Cart(ST_CUSTDELIVERY, array($trans_no), true);
		foreach ($invoice->line_items as $line_no=>$item) {
			$item->qty_done = 0;
			$new_price = get_price(
				$item->stock_id, $invoice->customer_currency,
				$invoice->sales_type, $invoice->price_factor, $invoice->document_date
			);
			if ($new_price != 0)	// use template price if no price is currently set for the item.
				$item->price = $new_price;
		}
		$invoice->payment_terms['cash_sale'] = false; // no way to register cash payment with recurrent invoice at once
		$invoice->Comments = $comment;
		$trans_no = $invoice->write(1);
		
		$this->_view->generatedInvoice($orderNo);
		
		return $trans_no;
		
	}
	
	public function table() {
		$k = 0;
		$result = GenerateRecurringModel::find($this->_showAll);
		foreach ($result as $model) {
			if ($this->_force != self::FORCE_NO) {
				$key = 's_' . $model->orderNo;
				$_POST[$key] = $this->_force;
			}
			$this->_view->tableRow($model, $k);
		}
	}
	
	/**
	 * Returns the latest occuring date before the given $date 
	 * @param GenerateRecurringModel $model
	 * @param DateTime
	 */
	public static function dateBefore($model, $date) {
		$result = clone $date;
		switch ($model->repeats) {
			case SalesRecurringModel::REPEAT_YEARLY:
				$occurParts = explode('-', $model->occur);
				$result->setDate($date->format('Y'), $occurParts[0], $occurParts[1]);
				while ($result > $date) {
					$result->sub(new \DateInterval("P1Y"));
				}
				break;
			case SalesRecurringModel::REPEAT_MONTHLY:
				$result->setDate($date->format('Y'), $date->format('m'), $model->occur);
				while ($result > $date) {
					$result->sub(new \DateInterval("P1M"));
				}
				break;
		}
		return $result;
	}
	
	/**
	 * Returns the earliest anniversary date after the given $date
	 * without using 'every'. 
	 * @param GenerateRecurringModel $model
	 * @param DateTime
	 */
	public static function dateAfter($model, $date) {
		$result = clone $date;
		switch ($model->repeats) {
			case SalesRecurringModel::REPEAT_YEARLY:
				$occurParts = explode('-', $model->occur);
				$result->setDate($date->format('Y'), $occurParts[0], $occurParts[1]);
				while ($result < $date) {
					$result->add(new \DateInterval("P1Y"));
				}
				break;
			case SalesRecurringModel::REPEAT_MONTHLY:
				$result->setDate($date->format('Y'), $date->format('m'), $model->occur);
				while ($result < $date) {
					$result->add(new \DateInterval("P1M"));
				}
				break;
		}
		return $result;
	}
	
	/**
	 * Returns the next occuring date (including every) after the given $date
	 * @param GenerateRecurringModel $model
	 * @param DateTime
	 */
	public static function nextDateAfter($model, $date) {
		$result = self::dateBefore($model, $date);
		switch ($model->repeats) {
			case SalesRecurringModel::REPEAT_YEARLY:
				$result->add(new \DateInterval("P" . $model->every . "Y"));
				break;
			case SalesRecurringModel::REPEAT_MONTHLY:
				$result->add(new \DateInterval("P" . $model->every . "M"));
				break;
		}
		return $result;
	}
	
	/**
	 * @param GenerateRecurringModel $model
	 */
	public static function nextDate($model) {
		if (!$model->dtNext || $model->dtNext == '0000-00-00') {
			return self::dateBefore($model, new \DateTime($model->dtStart));
		}
		return self::nextDateAfter($model, new \DateTime($model->dtNext));
	}
	
	/**
	 * @param GenerateRecurringModel $model
	 * @param DateTime $today
	 */
	public static function comment($model, $today) {
		$startDate = new \DateTime();
		switch ($model->repeats) {
			case SalesRecurringModel::REPEAT_YEARLY:
				$parts = explode('-', $model->dtStart);
				$startDate->setDate($today->format('Y'), $parts[1], $parts[2]);
				while ($startDate > $today) {
					$startDate->sub(new \DateInterval("P1Y"));
				}
				$endDate = clone $startDate;
				$endDate->add(new \DateInterval("P" . $model->every . "Y"));
				$endDate->sub(new \DateInterval("P1D"));
				break;
			case SalesRecurringModel::REPEAT_MONTHLY:
				$parts = explode('-', $model->dtStart);
				$startDate->setDate($today->format('Y'), $today->format('m'), $parts[2]);
				while ($startDate > $today) {
					$startDate->sub(new \DateInterval("P1M"));
				}
				$endDate = clone $startDate;
				$endDate->add(new \DateInterval("P" . $model->every . "M"));
				$endDate->sub(new \DateInterval("P1D"));
				break;
		}
		$dateFormat = 'j F Y';
		$comment = _("Invoice for period ") . $startDate->format($dateFormat) . _(' to ') . $endDate->format($dateFormat);
		return $comment;
		
	}
	
	
}