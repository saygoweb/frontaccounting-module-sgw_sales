# Docker test stack

A throwaway FrontAccounting install with this module plugged into it — Apache +
mod_php + MariaDB — for developing and testing sgw_sales without a
FrontAccounting checkout, a PHP, or a database on your own machine.

    docker/fa-sgw-sales init      # pick host ports that are free here
    docker/fa-sgw-sales up        # build, boot, seed, install composer deps
    docker/fa-sgw-sales test      # run the PHPUnit suite
    docker/fa-sgw-sales lint      # php -l, then phpcs PSR-12 (advisory)
    docker/fa-sgw-sales analyze   # PHPStan

`up` prints the URLs. `docker/fa-sgw-sales help` lists every command.

The design, and most of the driver script, comes from the sibling stack in
`modules/api/docker`; its README explains the reasoning at more length (the Debian
trixie + sury base, why xdebug is unloaded rather than idle, why `up` rather than
`restart` after changing `XDEBUG_MODE`). What follows is what is particular to
this one.

## How it fits together

| | |
| --- | --- |
| FrontAccounting | cloned into the image at build time from `FA_REPO` / `FA_REF` — the `cambell-prince/frontaccounting` fork at `master-cp` by default, which is what production runs and what `sales_order_entry.php` was patched from |
| this checkout | bind-mounted at `/var/www/html/modules/sgw_sales`, so an edit is live on the next request |
| `config.php`, `config_db.php`, `lang/installed_languages.inc` | written by the entrypoint, into the image's FA tree |
| the two `installed_extensions.php` | written by the entrypoint on every start, with this module **registered and active for company 0** — so `hooks.php` is loaded, as it would be after Setup → Install/Activate Extensions |
| `vendor/` | installed by `up`, into your checkout, owned by you |

Nothing is written into your checkout except `vendor/` and `composer.lock`.

Apache listens on **8000** inside the container, as in the api stack. `tests/Http`
reaches it there through `FA_URL`, and `tests/Db` reaches MariaDB through
`FA_DB_*`; compose sets both. On the host the web server is `HTTP_PORT`, 8110 by
default.

## What loading a dataset also does

Marking an extension active in a file is not everything FrontAccounting's
extension screens do, so `db load` (and so `up` and `db reset`) finishes the job
after the dataset is in:

- **The module's schema.** `sql/update_1.0.sql`, then `sql/update_1.4.sql` up to
  its `# Upgrade helpers` marker. `activate_extension()` only knows about 1.0,
  but 1.4 is what makes `dt_end` and `dt_next` nullable, and the code writes
  NULL to both.
- **Access.** The Sales menu entries are guarded by the module's own security
  areas, which no role holds until they are ticked in Setup → Access Setup. They
  are granted to role 2, System Administrator — who `admin` logs in as.
- **Fiscal years.** The installer datasets end with fiscal 2022 and
  FrontAccounting will not post outside a fiscal year, so as shipped no invoice
  can be generated today. Years are added until today is inside one.

## Ports

Defaults are clear of the other stacks on a machine that runs them all:

| stack | http | db | phpMyAdmin |
| --- | --- | --- | --- |
| FrontAccounting (`docker/fa`) | 8080 | 3307 | 8081 |
| `modules/api` | 8090 | 3309 | 8091 |
| Anorm, anorm-graphql | — | 3316–3319 | 8096–8099 |
| `modules/graphql` | 8100 | 3320 | 8101 |
| **this one** | **8110** | **3330** | **8111** |

## Datasets

`docker/fa-sgw-sales db load <what>` and `db reset <what>`:

| what | source | login |
| --- | --- | --- |
| `demo` (default) | FrontAccounting's `sql/en_US-demo.sql`, from the image | admin / password |
| `new` | FrontAccounting's `sql/en_US-new.sql`, from the image | admin / password |
| `test` | `tests/data/fa_test.sql.gz` — a 2017 dump, older than the schema `FA_REF` expects | — |
| `test-demo` | `tests/data/fa_demo.sql.gz` — a 2016 dump, likewise | — |
| a path | any `.sql` or `.sql.gz` on the host | — |

`demo` is the default because it has customers, items and sales orders to hang a
recurrence on, which is what the `db` and `http` test suites look for.
`docker/fa-sgw-sales db dump` writes a gzipped dump back out.

## Tests

| suite | needs | what it covers |
| --- | --- | --- |
| `unit` | nothing | the recurrence date arithmetic; what Anorm derives from the models |
| `db` | `FA_DB_*` | the models against MariaDB through Anorm, each test rolled back |
| `http` | `FA_URL`, `FA_DB_*` | the pages under a logged-in FrontAccounting, and generating an invoice end to end |

    docker/fa-sgw-sales test --testsuite unit
    docker/fa-sgw-sales test --filter GenerateInvoiceTest

`GenerateInvoiceTest` is not rolled back — FrontAccounting writes on its own
connection — so each run leaves one more invoice behind. `db reset` starts over.

## Packaging

    docker/fa-sgw-sales make package

builds the release zip and tarball with a production `vendor/` (phpmake,
`makefile.json`), then puts the development `vendor/` back.

## A second PHP version

The environment wins over `docker/.env`, so this gives a second stack beside the
7.4 one rather than replacing it:

    PHP_VERSION=8.3 COMPOSE_PROJECT_NAME=fa-sgw-sales-83 HTTP_PORT=8115 DB_PORT=3335 \
        docker/fa-sgw-sales up --build

## Debugging

    docker/fa-sgw-sales logs app        # Apache, PHP errors
    docker/fa-sgw-sales logs errors     # FrontAccounting's tmp/errors.log

`docker/fa-sgw-sales` has to be executable in git: `git update-index --chmod=+x
docker/fa-sgw-sales docker/docker-entrypoint.sh` if `core.fileMode` is false.
