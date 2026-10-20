<?php

namespace SGW_Sales\Tests\Support;

/**
 * FrontAccounting's transaction types (includes/types.inc), for the db and http
 * suites, which run without FrontAccounting loaded: the models name them in their
 * SQL. Not defined in tests/bootstrap.php, because the integration suite loads
 * FrontAccounting, whose types.inc defines them unguarded.
 */
final class FaTypes
{
    public static function define(): void
    {
        defined('ST_SALESINVOICE') || define('ST_SALESINVOICE', 10);
        defined('ST_CUSTDELIVERY') || define('ST_CUSTDELIVERY', 13);
        defined('ST_SALESORDER') || define('ST_SALESORDER', 30);
    }
}
