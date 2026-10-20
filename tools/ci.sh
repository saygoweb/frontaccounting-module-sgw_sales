#!/bin/sh
# sgw_sales' CI. Runs inside the FrontAccounting CI image (docker/ci in
# cambell-prince/frontaccounting), from this module's directory, on the demo
# dataset, after graphql (which it is deployed with) and then the module have
# been activated: lint, coding standard and analysis, the models under
# graphql's Anorm, then the unit, db, integration and http suites.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci/plugin-test.sh)}"
export FA_DB_PREFIX="${FA_DB_PREFIX:-0_}"

echo "==> quality"
composer run quality

# Every extension's hooks share one PHP process, so with graphql active all of
# them get its Anorm. Composer *prepends* each autoload.php it loads, so
# whichever loads last wins a class name both define. FrontAccounting includes
# hooks.php in registration order, so where graphql is registered after
# sgw_sales, graphql's Anorm wins for everybody, including sgw_sales' own
# models. The CI driver activates the plugin under test last, under which that
# case cannot appear. So load them in that order here by hand, as
# tests/bootstrap.php does for the suites, and check every sgw_sales model
# class still loads under graphql's Anorm.
echo "==> sgw_sales' models load with graphql's Anorm (graphql's autoloader last)"
php -r 'require $argv[1]; require $argv[2]; foreach (glob("includes/db/*.php") as $f) { $c = "SGW_Sales\\db\\" . basename($f, ".php"); if (!class_exists($c)) { fwrite(STDERR, "cannot load $c\n"); exit(1); } } echo "ok\n";' vendor/autoload.php ../graphql/vendor/autoload.php

# The Http tests sign in as the demo admin (role 2) and expect the module's
# menu: give role 2 this module's areas, as docker/fa-sgw-sales did.
fa-ci-grant --role 2 --module sgw_sales

# The order inquiry's pager (ModelPager) renders through the cp fork's
# ControlRenderer; upstream's display_db_pager() cannot render it, so the
# tests of its HTML (group fa-cp) run on cp alone.
exclude=''
[ -f "$FA_ROOT/includes/ui/ControlRenderer.inc" ] || exclude='--exclude-group fa-cp'

echo "==> phpunit"
# shellcheck disable=SC2086 # $exclude is two words or none
composer run test -- --fail-on-skipped $exclude
echo "==> all checks passed"
