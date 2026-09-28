<?php
namespace SGW_Sales\controller;

use SGW_Sales\service\RecurringInvoiceService;

/**
 * The Generate Recurring Invoices page: reads the form, and leaves the work to
 * RecurringInvoiceService.
 */
class GenerateRecurring {
	
	/**
	 * @var \GenerateRecurringView
	 */
	private $_view;

	/**
	 * @var RecurringInvoiceService
	 */
	private $_service;
	
	public function __construct($view, ?RecurringInvoiceService $service = null) {
		$this->_view = $view;
		$this->_service = $service ?: new RecurringInvoiceService();
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
			$today = new \DateTime();
			// "Show All" lists orders not yet due; a person picking one generates it early.
			$early = (bool) check_value('show_all');
			// Collected first: emailing an invoice goes through $_POST.
			foreach (self::selectedOrders($_POST) as $orderNo) {
				try {
					$generated = $this->_service->generate($orderNo, $today, $early);
				} catch (\Exception $e) {
					display_error(sprintf(_('Sales order %d was not invoiced: %s'), $orderNo, $e->getMessage()));
					continue;
				}
				// After the commit: a failure to send leaves the invoice written and the order moved on.
				$this->_service->emailInvoice($generated->invoiceNo);
				$this->_view->generatedInvoice($orderNo);
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
	 * The order numbers ticked in the list: its checkboxes are named s_<order no>.
	 * @param array $post
	 * @return int[]
	 */
	public static function selectedOrders($post) {
		$orders = array();
		foreach ($post as $key => $value) {
			if ($value && preg_match('/^s_(\d+)$/', $key, $matches)) {
				$orders[] = (int) $matches[1];
			}
		}
		return $orders;
	}
	
	public function table() {
		$k = 0;
		foreach ($this->_service->due(new \DateTime(), (bool) $this->_showAll) as $model) {
			if ($this->_force != self::FORCE_NO) {
				$key = 's_' . $model->orderNo;
				$_POST[$key] = $this->_force;
			}
			$this->_view->tableRow($model, $k);
		}
	}

}
