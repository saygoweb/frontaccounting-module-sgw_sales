<?php

/**
 * The GraphQL extension's suites run inside the FrontAccounting GraphQL module's
 * stack, with the module's autoloader (its classes, its test bases, phpunit) and
 * this module's own. This module's autoload-dev is not relied on: the image
 * installs sgw_sales with --no-dev, so SGW_Sales\Tests\GraphQL\ is mapped here.
 */

$graphql = dirname(__DIR__, 3) . '/graphql/vendor/autoload.php';
if (!is_file($graphql)) {
    fwrite(STDERR, "The GraphQL module's vendor/ is not at $graphql. Run these suites from the module:\n"
        . "  docker/fa-graphql test-extension sgw_sales\n");
    exit(1);
}
require_once $graphql;
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'SGW_Sales\\Tests\\GraphQL\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
