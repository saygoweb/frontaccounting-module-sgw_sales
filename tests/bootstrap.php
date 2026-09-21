<?php

require_once __DIR__ . '/../vendor/autoload.php';

// FrontAccounting's transaction types (includes/types.inc). The models name them
// in their SQL, and the suite runs without FrontAccounting loaded.
defined('ST_SALESINVOICE') || define('ST_SALESINVOICE', 10);
defined('ST_SALESORDER') || define('ST_SALESORDER', 30);

// The comment on a generated invoice is translated.
if (!function_exists('_')) {
    function _($text)
    {
        return $text;
    }
}
