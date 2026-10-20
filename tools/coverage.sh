#!/bin/sh
# Code coverage for the README's badge. Runs inside the FrontAccounting CI
# image like tools/ci.sh, with graphql activated first and its dev dependencies
# installed, as for tools/ci-graphql.sh: phpunit.xml's in-process suites (unit,
# db, integration; the http suite's requests run in Apache, where nothing is
# measured), then the GraphQL-extension suite. Writes build/coverage.xml and
# build/coverage-graphql.xml (Clover) for Codecov, which merges them, and a
# text summary of each.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci/plugin-test.sh)}"
export FA_DB_PREFIX="${FA_DB_PREFIX:-0_}"
export FA_GRAPHQL_URL="${FA_URL%/}/modules/graphql/"
here="$(pwd)"

# The image has xdebug installed but not enabled; it is loaded for phpunit
# alone, never for composer. An extra ini scan directory, rather than php -d,
# reaches the tests phpunit runs in separate processes. develop mode too,
# because FrontAccounting's db_profile() calls xdebug_call_file() whenever
# xdebug is loaded, which without it warns on every query: enough stderr to
# block an isolated test.
ini="$(mktemp -d)"
trap 'rm -rf "$ini"' EXIT
echo 'zend_extension=xdebug' > "$ini/xdebug.ini"
covered() {
    PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$ini" XDEBUG_MODE=coverage,develop php "$@"
}

echo "==> phpunit.xml with coverage"
covered vendor/bin/phpunit -c phpunit.xml --testsuite unit,db,integration \
    --coverage-clover build/coverage.xml --coverage-text

sh tools/graphql-init.sh

echo "==> phpunit-graphql.xml with coverage"
cd ../graphql
covered vendor/bin/phpunit -c "$here/phpunit-graphql.xml" \
    --coverage-clover "$here/build/coverage-graphql.xml" --coverage-text
