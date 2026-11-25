# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Submex is a WordPress plugin (free tier, slug `submex`) that adds subscriptions to WooCommerce. PHP 8.1+, WP 6.5+, WC 8.0+. A separate plugin, **Submex Pro**, lives in its own repo, boots from this plugin's `submex_loaded` action, and consumes this plugin's JS UI kit and shell as globals — so public hooks, REST shapes and the `window.submex.*` exports are an API Pro depends on.

## Commands

PHP static analysis (needs `composer install`):

```bash
composer check        # phpcs + phpstan; both must report zero errors
composer lint         # phpcs (WordPress standard), composer lint:fix to autofix
composer analyse      # phpstan level 5
```

JS admin UI (needs `npm ci`):

```bash
npm run build         # writes build/ — COMMIT the result
npm run start         # watch mode
npm run lint:js
npm run test:unit                                   # all Jest tests
npm run test:unit -- src/subscriptions/test/list.test.js   # one file
```

PHP integration tests run inside a real WordPress + WooCommerce via `wp eval-file` (default target is wp-env from `.wp-env.json`: `npx wp-env start`):

```bash
tools/test.sh               # every tests/integration/test-*.php
tools/test.sh renewal       # only files whose name contains "renewal"
SUBMEX_WP="docker compose -f ~/wp-docker/docker-compose.yml exec -T wordpress wp --allow-root" tools/test.sh
```

Other tools: `tools/preview/build.sh` (render React screens without WordPress, see `tools/preview/README.md`), `tools/concurrency-test.php` (8 real processes racing one renewal; must show exactly one gateway charge), `tools/build.sh` (release zip from committed `HEAD`), `tools/release.sh X.Y.Z [--dry-run]`.

## Rules that CI enforces

- **`build/` is committed** and CI fails if a rebuild differs. Any change under `src/` needs `npm run build` and the updated `build/` in the same commit.
- **Never edit version numbers by hand.** They live in `submex.php` (header + `SUBMEX_VERSION`), `tools/phpstan-bootstrap.php` and `readme.txt` `Stable tag`; use `tools/bump-version.sh`. `tools/check-version.sh` fails on disagreement. The release changelog entry goes at the top of `readme.txt` as `= X.Y.Z =` (`CHANGELOG.md` is kept in step too).
- **Shipped PHP must lint clean on PHP 8.1–8.4**, including deprecations.
- **New top-level files** must be added to `.distignore` or `tools/build.sh`'s allow list, or the zip build fails.
- `submex.php` must stay parseable on old PHP (no typed properties, enums, `match`) so the version guard can show a notice instead of a fatal.
- phpcs/phpstan exclusions each carry a written reason in `phpcs.xml.dist` / `phpstan.neon.dist`; add one if you add an exclusion. Documentation sniffs are off on purpose: comments should explain a non-obvious *why*, not restate code.

## Integration test conventions

Each `tests/integration/test-*.php` requires `bootstrap.php`, prints PASS/FAIL per check and ends with `submex_test_done( $fail )`. The runner treats a file as passed only if it printed `all checks passed`; abort with `submex_test_abort()`, never bare `exit`. Pin any site option a test depends on (currency position, roles, `woocommerce_calc_taxes`, tax rates…) and restore it — `submex_test_site_snapshot()` / `submex_test_site_restore()` help. Gateways are never contacted: fake their HTTP with `pre_http_request`, matching by host. Third-party plugins that cannot be installed in CI use files in `tests/integration/stand-ins/`. Each test deletes the orders it created.

## Architecture

**Bootstrap.** `submex.php` checks requirements on `plugins_loaded` (offering a WooCommerce installer if missing), then loads `includes/autoload.php` (PSR-4, `Submex\Foo\Bar` → `includes/Foo/Bar.php`; no Composer at runtime) and calls `Submex\Plugin::instance()->boot()`. `Plugin.php` is a plain service registry: it constructs every service, wires dependencies by hand, calls `register()` to attach hooks, and finally fires `submex_loaded`. Services are reachable via `Plugin::instance()->get( 'id' )`. Start here to see how anything is wired.

**Subscriptions are WooCommerce orders.** `Domain\Subscription` extends `WC_Order` with order type `submex_sub` (registered in `Data\Order_Type`, HPOS and legacy-posts storage both supported). Schedule props (`billing_period`, `next_payment`, `period_index`, `payment_token_id`, …) are `extra_data` mapped to `_submex_*` meta. Statuses are the `Domain\Subscription_Status` enum, which refuses illegal transitions. Renewals are ordinary WC orders created by `Billing\Renewal_Order_Factory`. Money is integer minor units (`Domain\Money`); dates are UTC with anchored months (`Domain\Billing_Schedule`).

**The renewal pipeline (`Billing\Renewal_Processor`) is the core; treat changes there with great care.** The invariant is *at most one charge per billing period*, even with concurrent workers or a gateway that times out:
- `Data\Charge_Slot_Repository` is a ledger table (`{prefix}submex_charge_slot`) with `UNIQUE(subscription_id, period_index)`. Winning the INSERT grants the right to call the gateway once; losing means abort. It is a source of truth — never truncate it in a migration, never cache it.
- Nothing contacts a gateway before the slot is claimed. `Billing\Lock` (per-subscription, `INSERT IGNORE` of `expiry|uuid`) is released in a `finally`.
- Each attempt passes an idempotency key the gateway must forward unchanged; it rotates only on a definitive decline. An unknown outcome leaves the slot `charging` for reconciliation, not `failed`. Slot states: claimed, charging, pending (bank/Direct Debit), paid, failed, abandoned.
- Scheduling and retries go through Action Scheduler (`Billing\Renewal_Scheduler`), plus an hourly overdue sweep.

**Gateways.** `Gateways\Recurring_Gateway` is the contract; its `Gateway_Model` says who owns the schedule — `Tokenized` (Stripe: Submex decides when to charge via `charge_renewal`) vs `GatewayManaged` (PayPal: the provider bills and Submex mirrors via webhooks in `PayPal\Webhook_Controller`) vs `Manual`. Don't conflate them. Each provider has two classes: a WooCommerce checkout gateway (`*_Checkout_Gateway`, registered on `woocommerce_payment_gateways`) and a recurring gateway added to `Gateway_Registry` on `submex_register_gateways` (Pro adds its gateways there too). `Gateway_Switch` mirrors the Submex setting and the WC gateway's enabled flag. `Plugin::owns_stripe()` yields Stripe to older Pro versions.

**Other areas** (by `includes/` folder): `Checkout` (cart rules, Store API/blocks, creating the subscription from the paid parent order, trials, renewal pay-links), `Product` (simple/variable subscription product types and their meta fields), `Lifecycle` (auto-renew, cancellation policy/survey, retention offers, role switching), `Emails` (WC emails, templates in `templates/emails/`), `Frontend` (product disclosure, My Account endpoint with templates in `templates/myaccount/`), `Data` (migrations, activity log, stats, cleanup), `Rest` (controllers backing the admin app), `Admin` (menus, settings, notices, setup guide), `Integrations` (FluentCRM; Pro adds others via `submex_admin_integrations`).

**Admin UI.** React built with `@wordpress/scripts` (`webpack.config.js`). `src/ui` (Tailwind + shadcn-style components) and `src/shell` (app frame, router, notices) are published as `window.submex.ui` / `window.submex.shell` and imported elsewhere as the externals `@submex/ui` / `@submex/shell` — this is how Pro shares them without a build-time dependency. Route bundles (`dashboard`, `subscriptions`, `retention`, `integrations`, `help`, `settings`) register with the shell; `settings` is also exported as `window.submex.settings`. `blocks` registers the payment methods for block checkout. PHP side: `Admin\App_Host` / `Admin\Assets` / `Admin\Page_Shell` host the app and data comes from `includes/Rest/*`. Jest maps the externals back to source via `jest.config.js`.

## Docs

`docs/TESTING.md` (all test types, sandbox Stripe procedure, what is still untested), `docs/RELEASING.md` (release flow and what CI checks), `docs/FEATURES.md` (feature inventory with verification level per feature — update it when you change what is proven), `docs/INTEGRATIONS.md`, `docs/USER-GUIDE.md`. Docblocks cite a "Developer Guide" section by number; that guide is not in this repo.

## Workflows

Repeatable procedures live in `.claude/skills/`: `verify` (every CI check; run before calling anything done), `move-feature` (free ↔ Pro), `release` (user-invoked only). `.claude/agents/gateway-reviewer.md` independently reviews gateway and billing changes. New Pro gateways and Pro features are done from the `submex-pro` repo, whose skills cover them. This session can also edit Pro at `../submex-pro`. Free code must stay fully usable on its own (wordpress.org guidelines: no locked or trial features).
