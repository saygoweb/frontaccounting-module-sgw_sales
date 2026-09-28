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
   `sql/update_1.4.sql`); an install activated before this release should be
   re-activated per company once. Grant the *SayGo Sales* areas to a role in Setup → Access Setup.

Anorm is loaded from this module's own `vendor/`, into the same PHP process as every other
extension. Another module that uses Anorm has to be on 3.x as well: only one `Anorm\` can be loaded.

## Generating recurring invoices ##

`SGW_Sales\service\RecurringInvoiceService` is the one place invoices are
generated, for the Generate Recurring Invoices page and for the GraphQL API
(`modules/graphql`'s extension, `recurringGenerate`):

- `due(DateTimeInterface $asOf, bool $all = false)` - the sales orders whose
  recurrence is due on `$asOf`: not ended, and either their next date reached, or
  never generated and already started.
- `generate(int $orderNo, DateTimeInterface $invoiceDate, bool $allowEarly = false)` - delivers every line
  of the order again and invoices it, dated `$invoiceDate`, and moves the
  recurrence on, in one FrontAccounting transaction. It refuses an order that is
  not due on that date, or a period already billed, so running it twice bills once;
  the next date must move strictly past the date billed (a schedule with `every`
  outside 1-127 is refused). It refuses a closed order whatever the date (closing
  an order - on its page or through the API - ends its schedule today and records
  the close in the audit trail), a schedule that has ended by that date (a late run
  may still bill a period that began before the end), an order with nothing to
  deliver, and a prepayment order. It checks the
  fiscal year, the exchange rate, a customer on hold and stock, and writes nothing
  if any fails.
  One case is not detected (FrontAccounting's limit, not this module's): an order
  closed on FrontAccounting's own sales order page after exactly **one** delivery
  looks like an open order that has delivered once, so it stays due and is billed
  again at the full quantity. Close a recurring order on this module's page or
  through the API (`salesOrderDelete` of a delivered order, or a delivery with `closeOrder`), which end its schedule and record the close.
  Closed on FrontAccounting's page after two or more deliveries, it is refused
  (`ENDED`) on every run but stays on the due list until its schedule is ended.
  It does not email: the page emails through FrontAccounting's invoice report
  afterwards; the API through its own report process.

For example:

    $service = new \SGW_Sales\service\RecurringInvoiceService();
    $today = new \DateTime();

    foreach ($service->due($today) as $order) {
        $result = $service->generate((int) $order->orderNo, $today);
        // $result->deliveryNo, ->invoiceNo, ->comment, ->dtNext
        $service->emailInvoice($result->invoiceNo);   // the page's way: rep107, through $_POST
    }

It needs FrontAccounting booted with a user logged in - the documents are written by
FrontAccounting's own `Cart`, which is what posts to the ledger - and this module's `hooks.php`
loaded, which connects Anorm. It asks for the FrontAccounting includes it needs itself.
`generate()` throws `RecurrenceNotFound` (no recurrence, or no such sales order),
`RecurrenceEnded`, `RecurrenceNotDue`, or `GenerationRefused` (a check failed; `field()` names the
input, `messages()` has FrontAccounting's reasons). The page's *Show All* lets a person pick an
order that is not yet due; it passes `generate($orderNo, $date, true)` to bill the next due
period early - from `dt_next`, or the schedule's start if it has never been generated - dated
`$date`, once: asked again, that period is already billed. It never bills a period that starts
on or after the schedule's end: that is refused as ended (`RecurrenceEnded`), as a run on the
period's own date would refuse it.

The date arithmetic is `SGW_Sales\service\RecurrenceSchedule`: static, and free of FrontAccounting
and the database.

## GraphQL extension ##

When the [FrontAccounting GraphQL module](https://github.com/saygoweb/frontaccounting-module-graphql)
is installed, this module extends its API through the module's extension contract
(`hooks_sgw_sales::graphql_extensions`, code in `includes/GraphQL/`). Without the
GraphQL module nothing here is loaded.

For companies where this module is active the API gains:

 - `recurring` on sales orders (read) and on the sales order create/update inputs
   (`start`, `end`, `repeats` `MONTH|YEAR`, `every`, `day` or `monthDay`, `auto`),
   written in the order's own transaction;
 - `recurringDueList(asOf)` — the recurring orders due on a date; `next` is a
   never-generated schedule's start date, else its next due date;
 - `recurringGenerate(input: [{orderId, date, email}])` — deliver, invoice and
   optionally email each due order in one FrontAccounting transaction; items are
   independent (one item's refusal does not stop the rest), and a retry never bills
   a period twice (`NOT_DUE`). A closed order, or a schedule ended on or before the
   date asked, is refused too (`ENDED`); other refusals are `NOT_FOUND`, `BAD_INPUT`,
   `FA_REJECTED` or `INTERNAL`. `email: true` sends the invoice through
   FrontAccounting's `rep107` after the item's transaction commits.
 - Areas: listing needs *Sales transactions view* (`SA_SALESTRANSVIEW`); generating
   needs *Sales deliveries edition* (`SA_SALESDELIVERY`) and *Sales invoices
   edition* (`SA_SALESINVOICE`).

The page and the API share one generation service (`RecurringInvoiceService`, see
above); the API never asks for early generation (`allowEarly` is always `false`).

Its GraphQL tests (`tests/GraphQL/`) run inside the GraphQL module's docker stack,
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

### Deploying Release 4 ###

This module's GraphQL extension works with the GraphQL module's Release 4 and later.

 1. Deploy this module first. It is inert on an older GraphQL module (no extension loader),
    which keeps serving `recurring` itself; keep the window short and avoid rhythm changes
    through the API during it (the old module's API clears `dt_next` on a rhythm change).
 2. Deploy the GraphQL module.
 3. Re-activate, for **each** company, this module (which applies `update_1.4.sql`; until then
    API writes to `recurring` are refused with `FA_REJECTED`) and the GraphQL module.

Before deploying, on each company where the API changed schedules, find schedules whose
`dt_next` Release 2's API cleared although they were billed already:

    SELECT sr.trans_no FROM 0_sales_recurring sr
    JOIN 0_debtor_trans dt ON dt.order_=sr.trans_no AND dt.type=10
    WHERE sr.dt_next IS NULL GROUP BY sr.trans_no;

(with the company's table prefix). Set `dt_next` by hand on any row it returns: otherwise
the schedule reads as never generated and is due again from its start.

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
