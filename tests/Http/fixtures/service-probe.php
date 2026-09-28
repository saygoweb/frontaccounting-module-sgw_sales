<?php

/**
 * What a caller that is not a FrontAccounting page looks like: FrontAccounting
 * booted and a user logged in, and nothing else - no ui.inc, no reporting.inc,
 * no view. ServiceWithoutAPageTest copies this into the module's root for the
 * length of a test, because Apache will not serve anything under tests/.
 *
 * ?order=N&date=Y-m-d[&early=1][&email=1][&fail=invoice]
 */

use SGW_Sales\service\GenerationRefused;
use SGW_Sales\service\RecurringInvoiceService;

$page_security = 'SA_SALESINVOICE';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

$service = ($_GET['fail'] ?? '') === 'invoice'
    ? new class extends RecurringInvoiceService {
        protected function writeInvoice(int $deliveryNo, string $faDate, string $comment): int
        {
            throw new \RuntimeException("the invoice failed after delivery $deliveryNo was written");
        }
    }
    : new RecurringInvoiceService();

$out = array();
try {
    $_POST['PARAM_0'] = 'the caller had this here';
    $date = new \DateTime($_GET['date'] ?? 'today');
    $generated = $service->generate((int) $_GET['order'], $date, !empty($_GET['early']));
    $out['generated'] = (array) $generated;
    if (!empty($_GET['email'])) {
        $service->emailInvoice($generated->invoiceNo);
        $out['emailed'] = true;
    }
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    $out['errorClass'] = get_class($e);
    $out['field'] = $e instanceof GenerationRefused ? $e->field() : null;
}
$out['post'] = $_POST;
$out['view_loaded'] = class_exists('GenerateRecurringView', false);
$out['transaction_level'] = $GLOBALS['transaction_level'] ?? null;

echo '<<<JSON' . json_encode($out) . 'JSON>>>';
