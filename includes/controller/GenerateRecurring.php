<?php
namespace SGW_Sales\controller;

use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\db\SalesRecurringModel;

class GenerateRecurring {
	
	/**
	 * @var \GenerateRecurringView
	 */
	private $_view;
	
	public function __construct($view) {
		$this->_view = $view;
	}
	
	public function table() {
		$trans_type = ST_SALESORDER;
		$sql = "SELECT
				so.order_no,
				so.reference,
				debtor.name,
				branch.br_name,"
						//		. ($filter == 'InvoiceTemplates' || $filter == 'DeliveryTemplates' ? "so.comments, " : "so.customer_ref, ")
				."so.ord_date,
				so.delivery_date,
				so.deliver_to,
				Sum(line.unit_price*line.quantity*(1-line.discount_percent))+freight_cost AS OrderValue,
				so.type,
				sr.id,
				sr.dt_start,
				sr.dt_end,
				sr.dt_last,
				sr.auto,
				sr.repeats,
				sr.every,
				sr.occur,
				debtor.curr_code,
				Sum(line.qty_sent) AS TotDelivered,
				Sum(line.quantity) AS TotQuantity,
				Sum(line.invoiced) AS TotInvoiced,
				alloc,
				prep_amount,
				allocs.ord_payments,
				inv.inv_payments,
				so.total,
				so.trans_type
			FROM ".TB_PREF."sales_orders as so
			LEFT JOIN (SELECT trans_no_to, sum(amt) ord_payments FROM ".TB_PREF."cust_allocations WHERE trans_type_to=".ST_SALESORDER." GROUP BY trans_no_to)
				 allocs ON so.trans_type=".ST_SALESORDER." AND allocs.trans_no_to=so.order_no
			LEFT JOIN (SELECT order_, sum(prep_amount) inv_payments	FROM ".TB_PREF."debtor_trans WHERE type=".ST_SALESINVOICE." GROUP BY order_)
					 inv ON so.trans_type=".ST_SALESORDER." AND inv.order_=so.order_no
			JOIN " .TB_PREF. "sales_recurring AS sr ON sr.trans_no=so.order_no,"
						.TB_PREF."sales_order_details as line, "
							.TB_PREF."debtors_master as debtor, "
								.TB_PREF."cust_branch as branch
			WHERE (so.order_no = line.order_no
				AND so.trans_type = line.trans_type
				AND so.trans_type = ".db_escape($trans_type)."
				AND so.debtor_no = debtor.debtor_no
				AND so.branch_code = branch.branch_code
				AND debtor.debtor_no = branch.debtor_no
				AND so.ord_date>='2001-01-01'
				AND so.ord_date<='9999-01-01')
			GROUP BY
				so.order_no
		";
		$model = new GenerateRecurringModel();
		$result = $model->_mapper->query($sql);
		$k = 0;
		while ($model->_mapper->readRow($model, $result))
		{
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
	 * Returns the earliest occuring date after the given $date 
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
		if (!$model->dtLast || $model->dtLast == '0000-00-00') {
			return self::dateAfter($model, new \DateTime($model->dtStart));
		}
		return self::nextDateAfter($model, new \DateTime($model->dtLast));
	}
	
	
}