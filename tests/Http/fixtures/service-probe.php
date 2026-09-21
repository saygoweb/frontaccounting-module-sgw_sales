<?php

/**
 * What a caller that is not a FrontAccounting page looks like: FrontAccounting
 * booted and a user logged in, and nothing else - no ui.inc, no reporting.inc,
 * no view. ServiceWithoutAPageTest copies this into the module's root for the
 * length of a test, because Apache will not serve anything under tests/.
 */

use SGW_Sales\service\RecurringInvoiceService;

$page_security = 'SA_SALESINVOICE';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

$service = new RecurringInvoiceService();
$out = array();
try {
    $_POST['PARAM_0'] = 'the caller had this here';
    $out['generated'] = $service->generate((int) $_GET['order'], (bool) $_GET['email']);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}
$out['post'] = $_POST;
$out['view_loaded'] = class_exists('GenerateRecurringView', false);

echo '<<<JSON' . json_encode($out) . 'JSON>>>';
