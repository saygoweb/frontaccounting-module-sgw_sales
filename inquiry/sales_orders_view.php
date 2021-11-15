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

use SGW_Sales\controller\SalesOrderList;

$path_to_root = "../../..";

include_once($path_to_root . "/admin/db/fiscalyears_db.inc");
// include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/ui/db_pager_view.inc");
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/sales/includes/sales_ui.inc");
include_once($path_to_root . "/reporting/includes/reporting.inc");

$page_security = 'SA_SALESTRANSVIEW';

set_page_security( @$_POST['order_view_mode'],
	array(	'OutstandingOnly' => 'SA_SALESDELIVERY',
			'InvoiceTemplates' => 'SA_SALESINVOICE',
			'DeliveryTemplates' => 'SA_SALESDELIVERY',
			'PrepaidOrders' => 'SA_SALESINVOICE'),
	array(	'OutstandingOnly' => 'SA_SALESDELIVERY',
			'InvoiceTemplates' => 'SA_SALESINVOICE',
			'DeliveryTemplates' => 'SA_SALESDELIVERY',
			'PrepaidOrders' => 'SA_SALESINVOICE')
);

class SalesOrderListView {

    /** @var SalesOrderList */
    public $controller;

    function view_link($dummy, $order_no)
    {
        return  get_customer_trans_view_str($this->controller->trans_type, $order_no);
    }

    function prt_link($row)
    {
        return print_document_link($row['order_no'], _("Print"), true, $this->controller->trans_type, ICON_PRINT);
    }

    function edit_link($row) 
    {
        global $page_nested;

        if ($page_nested) {
            return '';
        }
        $url = pager_link(
            _('Edit'), 
            sprintf('/modules/sgw_sales/sales_order_entry.php?ModifyOrderNumber=%d', $row['order_no']),
            ICON_EDIT
        );
        return $url;
    }

    function dispatch_link($row)
    {
        if ($row['ord_payments'] + $row['inv_payments'] < $row['prep_amount'])
            return '';

        if ($this->controller->trans_type == ST_SALESORDER)
                return pager_link( _("Dispatch"),
                "/sales/customer_delivery.php?OrderNumber=" .$row['order_no'], ICON_DOC);
        else
                return pager_link( _("Sales Order"),
                "/sales/sales_order_entry.php?OrderNumber=" .$row['order_no'], ICON_DOC);
    }

    function invoice_link($row)
    {
        if ($this->controller->trans_type == ST_SALESORDER)
            return pager_link( _("Invoice"),
                "/sales/sales_order_entry.php?NewInvoice=" .$row["order_no"], ICON_DOC);
        else
            return '';
    }

    function delivery_link($row)
    {
        return pager_link( _("Delivery"),
        "/sales/sales_order_entry.php?NewDelivery=" .$row['order_no'], ICON_DOC);
    }

    function order_link($row)
    {
        return pager_link( _("Sales Order"),
        "/sales/sales_order_entry.php?NewQuoteToSalesOrder=" .$row['order_no'], ICON_DOC);
    }

    function tmpl_checkbox($row)
    {
        global $page_nested;

        if ($this->controller->trans_type == ST_SALESQUOTE || !check_sales_order_type($row['order_no']))
            return '';

        if ($page_nested)
            return '';
        $name = "chgtpl" .$row['order_no'];
        $value = $row['type'] ? 1:0;

    // save also in hidden field for testing during 'Update'

        return checkbox(
            null, $name, $value, true,
            _('Set this order as a template for direct deliveries/invoices')
        ) . hidden('last['.$row['order_no'].']', $value, false);
    }

    function invoice_prep_link($row)
    {
        // invoicing should be available only for partially allocated orders
        return 
            $row['inv_payments'] < $row['total'] ?
            pager_link($row['ord_payments']  ? _("Prepayment Invoice") : _("Final Invoice"),
            "/sales/customer_invoice.php?InvoicePrepayments=" .$row['order_no'], ICON_DOC) : '';
    }

    public function renderView($show_dates, $table)
    {
        global $page_nested;

        start_form();
        
        start_table(TABLESTYLE_NOBORDER);
        start_row();
        ref_cells(_("#:"), 'OrderNumber', '',null, '', true);
        ref_cells(_("Ref"), 'OrderReference', '',null, '', true);
        if ($show_dates)
        {
            date_cells(_("From:"), 'OrdersAfterDate', '', null, -user_transaction_days());
            date_cells(_("To:"), 'OrdersToDate', '', null, 1);
        }
        // locations_list_cells(_("Location:"), 'StockLocation', null, true, true);
        if (!$page_nested)
            customer_list_cells(_("Customer: "), 'customer_id', null, true, true);
        
        // if($show_dates) {
        // 	end_row();
        // 	end_table();
        
        // 	start_table(TABLESTYLE_NOBORDER);
        // 	start_row();
        // }
        // stock_items_list_cells(_("Item:"), 'SelectStockFromList', null, true, true);
        
        if ($this->controller->trans_type == ST_SALESQUOTE)
            check_cells(_("Show All:"), 'show_all');
        
        submit_cells('SearchOrders', _("Search"),'',_('Select documents'), 'default');
        hidden('order_view_mode', $_POST['order_view_mode']);
        hidden('type', $this->controller->trans_type);
        
        end_row();
        end_table(1);

        display_db_pager($table);
        submit_center('Update', _("Update"), true, '', null);
        
        end_form();
    }

};

$view = new SalesOrderListView();
$controller = new SalesOrderList($view);
$view->controller = $controller;

$js = "";
if ($SysPrefs->use_popup_windows)
	$js .= get_js_open_window(900, 600);
if (user_use_date_picker())
	$js .= get_js_date_picker();

page($_SESSION['page_title'], false, false, "", $js);

$controller->run();

end_page();
