# FrontAccounting Module: sgw_sales

[![CI](https://github.com/saygoweb/frontaccounting-module-sgw_sales/actions/workflows/ci.yml/badge.svg)](https://github.com/saygoweb/frontaccounting-module-sgw_sales/actions/workflows/ci.yml)

A module for Front Accounting that provides recurring invoicing for sales orders.

## Order Entry ##

 - A modified Sales Order Entry screen that allows for orders to be marked as recurring.
 - Start and optional end date can be specified.
 - Yearly and Monthly recurring intervals.
 - Set the date in the year, or date in month on which the recurring invoice should be triggered.

![Order Entry](/docs/OrderEntry.png?raw=true "Sales Order Entry")

## Invoice Generation ##

 - Shows a list of orders that are due to be invoiced.
 - Some or all invoices can be generated.
 - Can email invoices when they are generated.
 - Delivery notes are automatically generated.
 - Invoices are recorded against the Sales Order.

![Invoice Generation](/docs/GenerateInvoices.png?raw=true "Invoice Generation")

## Requirements ##

 - PHP 7.4 or later, with `pdo_mysql`. The module reads and writes its own table through
   [Anorm](https://github.com/saygoweb/anorm) 3.2, which sets that floor.
 - FrontAccounting 2.4. It is developed against the
   [cambell-prince fork](https://github.com/cambell-prince/frontaccounting) at `master-cp`.
 - `composer install --no-dev` in the module directory; the release packages ship with `vendor/` in place.
 - Activating the extension creates and upgrades its table (`sql/update_1.0.sql`, then
   `sql/update_1.4.sql`). Grant the *SayGo Sales* areas to a role in Setup → Access Setup.

Anorm is loaded from this module's own `vendor/`, into the same PHP process as every other
extension. Another module that uses Anorm has to be on 3.x as well: only one `Anorm\` can be loaded.

## Calling it from other code ##

Generation is not tied to its page. `SGW_Sales\service\RecurringInvoiceService` is what the
*Generate Recurring Invoices* page calls, and what an API should call:

    $service = new \SGW_Sales\service\RecurringInvoiceService();

    foreach ($service->due() as $order) {          // due(true) includes those not yet due
        $result = $service->generate((int) $order->orderNo);   // generate($orderNo, $email = true)
        // $result->invoiceNo, ->comment, ->dtNext, ->emailed
    }

It needs FrontAccounting booted with a user logged in - the invoice is written by FrontAccounting's
own `Cart`, which is what posts to the ledger - and this module's `hooks.php` loaded, which connects
Anorm. It asks for the FrontAccounting includes it needs itself. `generate()` throws
`RecurrenceNotFound` for an order with no recurrence and `RecurrenceEnded` for one whose end date has
passed; an order that is merely not yet due is invoiced, as the page's *Show All* allows.

The date arithmetic is `SGW_Sales\service\RecurrenceSchedule`: static, and free of FrontAccounting
and the database.

## GraphQL API ##

When the FrontAccounting GraphQL module (`modules/graphql`) is installed, this
module adds to its API, for every company where it is active: the `recurring`
schedule on sales orders (`salesOrderList`, `salesOrderCreate`, `salesOrderUpdate`),
written in the order's own transaction. The code is `includes/GraphQL/`, registered
by `hooks_sgw_sales::graphql_extensions()`. Without that module nothing of it loads.

Its tests (`phpunit-graphql.xml`) run inside the GraphQL module's docker stack,
against this checkout mounted over the stack's clone, with no skips:

    cd ../graphql
    export SGW_SALES_PATH=../sgw_sales
    docker/fa-graphql up --recreate
    docker/fa-graphql test-extension sgw_sales --fail-on-skipped

(`docker/fa-graphql ci` runs them too, after the module's own suite.)

Activating this module now applies `sql/update_1.4.sql` as well as
`update_1.0.sql`. A company activated before must be re-activated (Setup →
Install/Activate Extensions) for the GraphQL API to write schedules; first check
it for duplicate schedules with `sql/helpers/update_1.4-duplicates.sql`.

## Development ##

There is a docker stack that supplies FrontAccounting, PHP and MariaDB, so nothing but docker is
needed on the host. See [docker/README.md](docker/README.md).

    docker/fa-sgw-sales init      # pick free host ports
    docker/fa-sgw-sales up        # build, boot, seed, composer install
    docker/fa-sgw-sales test      # PHPUnit: unit, db and http suites
    docker/fa-sgw-sales lint      # php -l, then phpcs (advisory)
    docker/fa-sgw-sales analyze   # PHPStan
    docker/fa-sgw-sales make package

GitHub Actions runs the same commands on PHP 7.4 and 8.3.
