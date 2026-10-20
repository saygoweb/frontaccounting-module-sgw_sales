#!/bin/sh
# Prepares the FrontAccounting CI image for sgw_sales' GraphQL-extension suite
# (phpunit-graphql.xml), with graphql activated first (so graphql is extension
# 1) and graphql's dev dependencies installed by the workflow's setup: graphql's
# config, role 2's areas, and graphql's seed. Run by tools/ci-graphql.sh and
# tools/coverage.sh.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci/plugin-test.sh)}"

[ -f ../graphql/vendor/bin/phpunit ] || {
    echo "graphql's dev dependencies are missing: run composer install in ../graphql (the workflow's setup does)" >&2
    exit 1
}

# graphql is only a --with dependency here, so its own tools/ci.sh (which writes
# this) never runs; bin/fa-report (InvoiceMailer's subprocess, real email tests)
# needs it on disk. Mirrors tools/ci.sh in the graphql module.
if [ ! -f ../graphql/config_graphql.php ]; then
    secret="$(php -r 'echo bin2hex(random_bytes(24));')"
    printf "<?php\n\n/* Written by tools/graphql-init.sh for the CI image. Not for production:\n\tsee config_graphql.example.php. */\n\nreturn array(\n    'secret' => '%s',\n    'allow_insecure_login' => true,\n    'debug' => true,\n);\n" \
        "$secret" > ../graphql/config_graphql.php
fi

fa-ci-grant --role 2 --module sgw_sales

echo "==> graphql's seed"
if [ -f ../graphql/tests/data/seed.sh ]; then
    sh ../graphql/tests/data/seed.sh
else
    mariadb -h "$FA_DB_HOST" -u "$FA_DB_USER" -p"$FA_DB_PASSWORD" "$FA_DB_NAME" < ../graphql/tests/data/seed.sql
fi
