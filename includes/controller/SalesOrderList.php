<?php
namespace SGW_Sales\controller;

use SGW\common\SqlPager;

class SalesOrderList {


    /** @var \SalesOrderListView */
    private $_view;

    public $trans_type;

    public function __construct($view) {
        $this->_view = $view;
    }

    //---------------------------------------------------------------------------------------------
    //	Query format functions
    //
    function check_overdue($row)
    {
        if ($this->trans_type == ST_SALESQUOTE)
            return (date1_greater_date2(Today(), sql2date($row['delivery_date'])));
        else
            return ($row['type'] == 0
                && date1_greater_date2(Today(), sql2date($row['delivery_date']))
                && ($row['TotDelivered'] < $row['TotQuantity']));
    }

    public function run() {
        global $Ajax;

        if (get_post('type'))
            $this->trans_type = $_POST['type'];
        elseif (isset($_GET['type']) && $_GET['type'] == ST_SALESQUOTE)
            $this->trans_type = ST_SALESQUOTE;
        else
            $this->trans_type = ST_SALESORDER;
        
        if ($this->trans_type == ST_SALESORDER)
        {
            if (isset($_GET['OutstandingOnly']) && ($_GET['OutstandingOnly'] == true))
            {
                $_POST['order_view_mode'] = 'OutstandingOnly';
                $_SESSION['page_title'] = _($help_context = "Search Outstanding Sales Orders");
            }
            elseif (isset($_GET['InvoiceTemplates']) && ($_GET['InvoiceTemplates'] == true))
            {
                $_POST['order_view_mode'] = 'InvoiceTemplates';
                $_SESSION['page_title'] = _($help_context = "Search Template for Invoicing");
            }
            elseif (isset($_GET['DeliveryTemplates']) && ($_GET['DeliveryTemplates'] == true))
            {
                $_POST['order_view_mode'] = 'DeliveryTemplates';
                $_SESSION['page_title'] = _($help_context = "Select Template for Delivery");
            }
            elseif (isset($_GET['PrepaidOrders']) && ($_GET['PrepaidOrders'] == true))
            {
                $_POST['order_view_mode'] = 'PrepaidOrders';
                $_SESSION['page_title'] = _($help_context = "Invoicing Prepayment Orders");
            }
            elseif (!isset($_POST['order_view_mode']))
            {
                $_POST['order_view_mode'] = false;
                $_SESSION['page_title'] = _($help_context = "Search All Sales Orders");
            }
        }
        else
        {
            $_POST['order_view_mode'] = "Quotations";
            $_SESSION['page_title'] = _($help_context = "Search All Sales Quotations");
        }

        $id = find_submit('_chgtpl');
        if ($id != -1)
        {
            sales_order_set_template($id, check_value('chgtpl'.$id));
            $Ajax->activate('orders_tbl');
        }
        
        if (isset($_POST['Update']) && isset($_POST['last'])) {
            foreach($_POST['last'] as $id => $value)
                if ($value != check_value('chgtpl'.$id))
                    sales_order_set_template($id, !check_value('chgtpl'.$id));
        }
        
        $show_dates = !in_array($_POST['order_view_mode'], array('OutstandingOnly', 'InvoiceTemplates', 'DeliveryTemplates'));
        
        //---------------------------------------------------------------------------------------------
        //	Order Search Form
        //
        if (get_post('_OrderNumber_changed') || get_post('_OrderReference_changed')) // enable/disable selection controls
        {
            $disable = get_post('OrderNumber') !== '' || get_post('OrderReference') !== '';
        
              if ($show_dates) {
                $Ajax->addDisable(true, 'OrdersAfterDate', $disable);
                $Ajax->addDisable(true, 'OrdersToDate', $disable);
            }
        
            $Ajax->activate('orders_tbl');
        }

        if ((!isset($_POST['OrdersAfterDate']) || $_POST['OrdersAfterDate'] == "") ||
            (!isset($_POST['OrdersToDate']) || $_POST['OrdersToDate'] == "")
        ) {
            $currentFiscalYear = get_current_fiscalyear();
            $_POST['OrdersAfterDate'] = sql2date($currentFiscalYear['begin']);
            $_POST['OrdersToDate'] = sql2date($currentFiscalYear['end']);
        }	

        $this->_view->renderSeachBar($show_dates);
       
        //---------------------------------------------------------------------------------------------
        //	Orders inquiry table
        //
        $sql = get_sql_for_sales_orders_view($this->trans_type, get_post('OrderNumber'), get_post('order_view_mode'),
            get_post('SelectStockFromList'), get_post('OrdersAfterDate'), get_post('OrdersToDate'), get_post('OrderReference'), get_post('StockLocation'),
            get_post('customer_id'));
        
        if ($this->trans_type == ST_SALESORDER)
            $cols = array(
                _("#") => array('fun'=> [$this->_view, 'view_link']),
                _("Ref") => array('type' => 'sorder.reference', 'ord' => '') ,
                _("Customer") => array('type' => 'debtor.name' , 'ord' => '') ,
                // _("Branch") =>array('type' => 'branch'),
                'Type0' => 'skip',
                // _("Cust Order Ref"),
                'Type1' => 'skip',
                _("Order Date") => array('type' =>  'date', 'ord' => ''),
                //	_("Required By") =>array('type'=>'date', 'ord'=>''),
                'Type2' => 'skip',
                //	_("Delivery To"), 
                'Type3' => 'skip',
                _("Order Total") => array('type'=>'amount', 'ord'=>''),
                'Type4' => 'skip',
                _("Currency") => array('align'=>'center')
            );
        else
            $cols = array(
                _("Quote #") => array('fun'=> [$this->_view, 'view_link']),
                _("Ref"),
                _("Customer"),
                _("Branch"), 
                _("Cust Order Ref"),
                _("Quote Date") => 'date',
                _("Valid until") =>array('type'=>'date', 'ord'=>''),
                _("Delivery To"), 
                _("Quote Total") => array('type'=>'amount', 'ord'=>''),
                'Type' => 'skip',
                _("Currency") => array('align'=>'center')
            );
        if ($_POST['order_view_mode'] == 'OutstandingOnly') {
            array_append($cols, array(
                array('insert'=>true, 'fun'=>[$this->_view, 'dispatch_link']),
                array('insert'=>true, 'fun'=>[$this->_view, 'edit_link'])));
        
        } elseif ($_POST['order_view_mode'] == 'InvoiceTemplates') {
            array_substitute($cols, 4, 1, _("Description"));
        
        } else if ($_POST['order_view_mode'] == 'DeliveryTemplates') {
            array_substitute($cols, 4, 1, _("Description"));
            array_append($cols, array(
                    array('insert'=>true, 'fun'=>[$this->_view, 'delivery_link']))
            );
        } else if ($_POST['order_view_mode'] == 'PrepaidOrders') {
            array_append($cols, array(
                    array('insert'=>true, 'fun'=>[$this->_view, 'invoice_prep_link']))
            );
        
        } elseif ($this->trans_type == ST_SALESQUOTE) {
            array_append($cols, array(
                array('insert'=>true, 'fun'=>[$this->_view, 'edit_link']),
                array('insert'=>true, 'fun'=>[$this->_view, 'order_link']),
                array('insert'=>true, 'fun'=>[$this->_view, 'prt_link'])
            ));
        } elseif ($this->trans_type == ST_SALESORDER) {
            array_append($cols, array(
                array('insert'=>true, 'fun'=>[$this->_view, 'edit_link']),
                array('insert'=>true, 'fun'=>[$this->_view, 'prt_link'])
            ));
        };
        
        $table =& SqlPager::new_db_pager('orders_tbl', $sql, $cols);
        $table->set_marker([$this, 'check_overdue'], _("Marked items are overdue."));
        
        $table->width = "80%";
       
        $this->_view->renderPager($table);
    
    }
}
