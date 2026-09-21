<?php

use Anorm\Anorm;
use SGW_Sales\db\DB;

define ('SS_SGW_SALES', 121<<8);

include_once(__DIR__ . '/vendor/autoload.php');

class hooks_sgw_sales extends hooks {

	function __construct() {
		global $db_connections;
		$this->module_name = 'sgw_sales';

		$company = user_company();
		$dbCredentials = $db_connections[$company];
		$host = $dbCredentials['host'];
		$database = $dbCredentials['dbname'];
		if ($_SESSION["wa_current_user"]->logged_in()) {
			Anorm::connect(Anorm::DEFAULT, "mysql:host=$host;dbname=$database" . self::dsnCharset(), $dbCredentials['dbuser'], $dbCredentials['dbpassword']);
		}

		DB::init($dbCredentials['tbpref']);
	}

	/**
	 * The charset FrontAccounting reads its own connection in, as a DSN suffix.
	 *
	 * FA sets its connection to the encoding of the user's language
	 * (db_set_encoding() in includes/db/connect_db_mysqli.inc) and sends its pages
	 * in the same. What Anorm reads - customer names, line descriptions - is
	 * printed into those pages, so it has to arrive in that encoding too. Anorm 3
	 * asks for utf8mb4 when the DSN names no charset, which is only right for a
	 * UTF-8 language.
	 * @return string
	 */
	private static function dsnCharset() {
		if (!isset($_SESSION['language']) || !function_exists('get_mysql_encoding_name')) {
			return '';
		}
		$charset = get_mysql_encoding_name(strtoupper($_SESSION['language']->encoding));
		return $charset ? ";charset=$charset" : '';
	}

	/*
		Install additonal menu options provided by module
		*/
	function install_options($app) {
		global $path_to_root;

		switch($app->id) {
			case 'orders': // id tag for sales
				$app->enabled = true;
//				var_dump($app);
				$app->modules[0]->lappfunctions[1] = new app_function(
					_("Sales &Order Entry"),
					"modules/sgw_sales/sales_order_entry.php?NewOrder=Yes",
					'SA_SGW_SALES_ORDER',
					MENU_TRANSACTION
				);
				$app->modules[0]->rappfunctions[2] = new app_function(
					_("&Generate Recurring Invoices"),
					"modules/sgw_sales/generate_recurring_invoices.php",
					'SA_SGW_GENERATE_RECURRING_INVOICES',
					MENU_TRANSACTION
				);
				$app->modules[1]->lappfunctions[1] = new app_function(
					_("Sales Order &Inquiry"),
					"modules/sgw_sales/inquiry/sales_orders_view.php?type=30",
					'SA_SGW_SALES_INQUIRY',
					MENU_INQUIRY
				);
				$this->remove_menu_item($app->modules[2]->lappfunctions, 3);
				
				break;
			case 'GL':
				break;
//				$app->add_rapp_function(2, _('Import &Transactions'),
//				$path_to_root.'/modules/import_transactions/import_transactions.php', 'SA_CSVTRANSACTIONS');
		}
	}

	function install_access()
	{
		$security_sections[SS_SGW_SALES] =	_("SayGo Sales");

		$security_areas['SA_SGW_SALES_ORDER'] = array(
			SS_SGW_SALES|1, _("Sales Order Entry")
		);
		$security_areas['SA_SGW_GENERATE_RECURRING_INVOICES'] = array(
			SS_SGW_SALES|2, _("Generate Recurring Invoices")
		);
		$security_areas['SA_SGW_SALES_INQUIRY'] = array(
			SS_SGW_SALES|21, _("Sales Order Inquiry")
		);
		
		return array($security_areas, $security_sections);
	}
	
	/* This method is called on extension activation for company.   */
	function activate_extension($company, $check_only = true)
	{
		global $db_connections;
	
		$updates = array(
			'update_1.0.sql' => array('sales_recurring')
		);
	
		return $this->update_databases($company, $updates, $check_only);
	}
	
	private function remove_menu_item(&$items, $offset) {
		array_splice($items, $offset, 1);
	}
}
