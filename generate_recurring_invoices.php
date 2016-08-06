<?php
/**********************************************************************
	Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL, 
	as published by the Free Software Foundation, either version 3 
	of the License, or (at your option) any later version.
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
	See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/

use SGW_Sales\db\GenerateRecurringModel;

$page_security = 'SA_SALESINVOICE';

include_once(__DIR__ . '/vendor/autoload.php');

$path_to_root = "../..";
$path_to_module = __DIR__;

include_once($path_to_root . "/sales/includes/cart_class.inc");
include_once($path_to_root . "/includes/session.inc");
//include_once($path_to_root . "/sales/includes/ui/sales_order_ui.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/reporting/includes/reporting.inc");

$js = "";
if ($SysPrefs->use_popup_windows)
	$js .= get_js_open_window(900, 600);

if (user_use_date_picker())
	$js .= get_js_date_picker();

page(_($help_context = "Create and Print Recurrent Invoices"), false, false, "", $js);

// ---

// ---

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

start_form();
start_table(TABLESTYLE, "width=70%");
table_header(array(
	"",
	_("#"), 
	_("Ref"), 
	_("Customer"),
	_("Branch"), 
	_("Start"), 
	_("End"), 
	_("Repeats"),
	_("Every"),
	_("On"),
	_("Next invoice"),
	""
));

$k = 0;
$due = false;

$model = new GenerateRecurringModel();
$result = $model->_mapper->query($sql);
while ($model->_mapper->readRow($model, $result))
{
// 	if ($myrow['overdue'])
// 	{
// 		start_row("class='overduebg'");
// 		$due = true;
// 	}
// 	else
		alt_table_row_color($k);

	check_cells('', 's_' . $model->orderNo, 0);
	label_cell($model->orderNo);
	label_cell($model->reference);
	label_cell($model->name);
	label_cell($model->brName);
	label_cell(sql2date($model->dtStart), "align='center'");
	label_cell(sql2date($model->dtEnd), "align='center'");
	label_cell($model->repeats);
	label_cell($model->every);
	label_cell($model->occur);
	label_cell("");
	label_cell("");

//  	if ($myrow['overdue'])
//  	{
// 		$count = recurrent_invoice_count($myrow['id']);
//  		if ($count)
//  		{
// 			button_cell("create".$myrow["id"], sprintf(_("Create %s Invoice(s)"), $count), "", ICON_DOC, 'process');
// 		} else {
// 			label_cell('');
// 		}
// 	}
//  	else
//  		label_cell("");
	end_row();
}
end_table();
end_form();
if ($due)
	display_note(_("Marked items are due."), 1, 0, "class='overduefg'");
else
	display_note(_("No recurrent invoices are due."), 1, 0);

br();

end_page();
