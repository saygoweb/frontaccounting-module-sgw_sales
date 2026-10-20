<?php

// With the GraphQL module beside this one (the CI image and dev environments),
// its autoloader last, as FrontAccounting loads them when graphql is registered
// after sgw_sales: Composer prepends each autoloader it loads, so graphql's Anorm
// is the one every model gets (see tools/ci.sh). Its Bootstrap also loads
// FrontAccounting for the integration suite. The unit suite needs only ours.
require_once __DIR__ . '/../vendor/autoload.php';
$graphql = dirname(__DIR__, 2) . '/graphql/vendor/autoload.php';
if (is_file($graphql)) {
    require_once $graphql;
}

// The comment on a generated invoice is translated.
if (!function_exists('_')) {
    function _($text)
    {
        return $text;
    }
}
