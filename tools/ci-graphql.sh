#!/bin/sh
# sgw_sales' GraphQL-extension suite (phpunit-graphql.xml), run with graphql's
# PHPUnit from ../graphql, as `docker/fa-graphql test-extension sgw_sales`
# did. Inside the FrontAccounting CI image, with graphql activated first and
# its dev dependencies installed by the workflow's setup (tools/graphql-init.sh
# checks). Arguments go to phpunit.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci/plugin-test.sh)}"
export FA_DB_PREFIX="${FA_DB_PREFIX:-0_}"
export FA_GRAPHQL_URL="${FA_URL%/}/modules/graphql/"

sh tools/graphql-init.sh

echo "==> phpunit-graphql.xml"
cd ../graphql
php vendor/bin/phpunit -c ../sgw_sales/phpunit-graphql.xml --fail-on-skipped "$@"
