#!/bin/sh
# sgw_sales' CI. Runs inside the FrontAccounting CI image (docker/ci in
# cambell-prince/frontaccounting), from this module's directory, on the demo
# dataset, after the module has been activated.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci/plugin-test.sh)}"
export FA_DB_PREFIX="${FA_DB_PREFIX:-0_}"

# The Http tests sign in as the demo admin (role 2) and expect the module's
# menu: give role 2 this module's areas, as docker/fa-sgw-sales did.
fa-ci-grant --role 2 --module sgw_sales

echo "==> lint"
composer run lint
echo "==> phpcs (advisory, as before)"
composer run cs:check -- --report=summary || true

echo "==> analyze"
composer run analyze

echo "==> phpunit"
composer run test
