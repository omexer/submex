# Testing Submex

Five kinds of check. **Static analysis** runs anywhere and catches type and standards problems before the code runs. **Integration tests** run the PHP against a real WordPress and WooCommerce. **JavaScript tests** cover the React admin screens. **Screenshots** of those screens catch what tests cannot see. **Sandbox tests** talk to a real payment gateway and are the only way to prove the parts that matter most.

---

## Integration tests

Both plugins have a suite in `tests/integration/`: one PHP file per behaviour (22 in free, 47 in
Pro as of 0.26.0 / 0.45.0), each run through `wp eval-file` inside a WordPress with WooCommerce
and the plugin active. Every file requires `tests/integration/bootstrap.php`, prints `PASS` /
`FAIL` per check and ends with `submex_test_done( $fail )`.

```bash
tools/test.sh              # every file
tools/test.sh renewal      # only files whose name contains "renewal"
```

`SUBMEX_WP` is the command that runs WP-CLI in the target site. It defaults to wp-env
(`npx wp-env run cli wp`, configured by `.wp-env.json`); point it at your own stack instead:

```bash
SUBMEX_WP="docker compose -f ~/wp-docker/docker-compose.yml exec -T wordpress wp --allow-root" tools/test.sh
```

Pro's suite needs the free plugin active beside it.

`.github/workflows/integration.yml` runs the suite on every push to `main` and every pull
request, on PHP 8.1 and 8.4 against the latest WordPress and WooCommerce, on a wp-env site
where the plugin was activated from WP-CLI and nobody ever opened wp-admin. The release
workflow calls it, so a failing suite blocks a tag.

Rules the suite depends on:

- **A file only passes if it says so.** `wp eval-file` can exit 0 having run nothing — a test
  file holding one very long string literal once did exactly that and counted as a pass with
  zero checks. `tools/test.sh` now fails any file that exits 0 without printing the
  bootstrap's `all checks passed` line. A test that cannot continue calls
  `submex_test_abort()`, never a bare `exit`, which would report success.
- **Pin the site settings a test depends on, and restore them.** A test that asserts a
  formatted amount, a role or a tax figure must set the option it relies on first and put the
  site's value back at the end: `woocommerce_currency_pos` (Pro's WhatsApp test pins `left`),
  free's **Role while subscribed** / **Role once it ends** (Pro's variable-integrations test),
  and `woocommerce_calc_taxes`, `woocommerce_prices_include_tax` and the tax rates the
  renewal-tax tests insert and delete. The bootstrap already pins the currency to USD. Two Pro tests failed on
  a site configured differently before they did this.
- **Gateways are tested with `pre_http_request` fakes.** No test contacts a real gateway: each
  gateway test answers the provider's API from a fake that matches requests by host (a site
  with WooCommerce usage tracking on can send other requests carrying the same query string).
  Braintree is faked at its SDK. A passing gateway test proves Submex handles the answers the
  provider documents — not that the provider answers that way. See Pro's `docs/GATEWAYS.md`
  for the sandbox steps that close that gap.
- **Stand-ins for premium plugins must be checked against licensed copies before release.**
  AffiliateWP and AutomateWoo cannot be installed in CI, so Pro's tests run against stand-ins
  (`tests/integration/automatewoo-stand-in.php`, built from AutomateWoo 6.9.0's GPL source; the
  AffiliateWP stand-in inside `test-affiliatewp.php`, built from its documentation). A stand-in
  only proves Submex calls what it expects to call. Run both integrations once against a
  licensed AffiliateWP and AutomateWoo on staging before each release that touches them.
  BuddyPress was run against BuddyPress 14.5.2 during development; where it is not active,
  as in CI, `tests/integration/stand-ins/buddypress.php` stands in for the functions Submex
  calls.

---

## Static analysis

Both plugins carry PHPStan and PHP_CodeSniffer. Run them from either plugin directory.

```bash
composer install
```

```bash
composer check
```

That runs both. Individually:

| Command | What it does |
|---|---|
| `composer lint` | PHP_CodeSniffer against the WordPress standard |
| `composer lint:fix` | Fixes what it can automatically |
| `composer analyse` | PHPStan at level 5 |

Both are expected to pass with **zero errors**. The only thing you should see is a handful of `slow_db_query` warnings on deliberate meta lookups.

`.github/workflows/static-analysis.yml` runs both on every push and pull request, so a regression fails the build rather than reaching a release. The same workflow lints every shipped PHP file under PHP 8.1, 8.2, 8.3 and 8.4 — failing on a deprecation as well as an error — checks that the version agrees everywhere it is written, and builds the plugin zip. How a release is cut is in [`RELEASING.md`](RELEASING.md).

### What is deliberately switched off, and why

Every exclusion is written in `phpcs.xml.dist` / `phpstan.neon.dist` with its reason. The short version:

- **Documentation sniffs.** This codebase's standard is that a comment earns its place by explaining a non-obvious *why*. A rule demanding a docblock on every file, class, property and parameter produces exactly the restatement it is meant to prevent.
- **File naming.** Class files are named for their class so the autoloader maps namespace to path. WordPress's `class-foo-bar.php` convention assumes a manual require list.
- **A sniff that predates enums.** `PHPCompatibility` reads an enum body as a plain function and reports every `$this` inside it.
- **Stub gaps.** `WC_Data::update_meta_data()` declares no type for its value at all, and `get_items()` is stubbed as returning the base item class. Both stubs are narrower than WooCommerce itself.

If you add an exclusion, write the reason next to it.

---

## JavaScript tests and the admin build

The admin screens are React, built with `@wordpress/scripts`. Run these from either plugin directory.

```bash
npm ci
```

| Command | What it does |
|---|---|
| `npm run test:unit` | Jest. Free: Home, the subscriptions list, a subscription's screen, and block-checkout registration. Pro: Reports and Health |
| `npm run lint:js` | ESLint with WordPress's rules |
| `npm run build` | Builds `build/` |

`build/` is committed, because a plugin installed from a zip has no build step. `.github/workflows/admin-ui.yml` runs lint, tests and a build on every push, then **fails if `build/` differs from what was committed** — the check for source changed and assets not rebuilt.

Pro's tests stand in for the free plugin's UI kit with `tools/submex-ui-stub.js`. At runtime that kit is a global the free plugin puts on the page, not a package Pro can import, so the stub is what makes Pro's screens testable on their own.

A few assertions are mutation-checked — broken on purpose to prove the test notices — noted in the commit that added them.

---

## Looking at the admin screens

A test proves a screen shows the right text. It cannot tell you the screen looks broken. In 0.13.0 five layout defects passed every test and were found only by screenshot: borders that drew nothing, the browser's own button styling showing through, a reset that outranked the utility classes, a library that silently stopped merging classes, and integration icons of 840 KB.

`tools/preview` builds every React screen as a plain page against fixture data, so it can be opened without WordPress. From the free plugin, inside a WordPress install:

```bash
tools/preview/build.sh
```

```bash
python3 -m http.server 8765 --directory .preview
```

Then open `http://localhost:8765/#dashboard`, and swap the hash for `#list`, `#detail`, `#reports` or `#health`. Add `?setup=done` before the hash to see Home once setup is finished. Pro's screens are included, so check out the Pro plugin beside the free one first.

The preview draws Submex's own frame but not WordPress's sidebar and admin bar. Look at the real admin once before a release.

---

## The concurrency test

The renewal engine promises a billing period can be charged **at most once**, even when
several workers reach it together. One process cannot race itself, so this starts eight
real ones, holds them on a wall-clock barrier until they are all spinning, and releases
them against the same subscription.

```bash
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/submex/tools/concurrency-test.php
```

Two rounds. The first asks the narrow question - eight workers call `claim_next()` on the
same period, and exactly one should get through. The second asks the one that matters -
eight workers run the whole renewal pipeline, and exactly one charge should reach the
gateway.

It asserts thirteen things, and the load-bearing one is **exactly one charge attempt
reached the gateway**. More than one means a customer was charged twice.

Run it more than once. A race that passes a single time has told you very little: one run
in the first twenty-one failed here, which is why the harness now prints every worker's
outcome and the ledger rows whenever an assertion fails. Eighteen consecutive runs passed
after that, and the cause of the single failure was never captured - treat an occasional
failure as something to read, not to dismiss.

It cleans up after itself: the subscription, its renewal orders and its ledger rows.

If you have run older builds, they left orphan rows behind — deleting a subscription used
to leave its ledger and activity rows in place forever. Clear them once with:

```bash
docker compose exec -T wordpress php -r 'require "/var/www/html/wp-load.php"; printf("removed %d\n", Submex\Data\Cleanup::purge_orphans());'
```

---

## Sandbox testing

Everything above proves the code is *well formed*. It cannot prove a renewal charges the right amount once, and only once. That takes a real gateway.

### Why this matters more than it sounds

The renewal engine's central promise is that a charge can happen **at most once per billing period**, even when the answer never arrives. Simulated HTTP cannot test that honestly, because the assertion that matters is not what our code believes — it is how many charges actually exist at the gateway.

### Stripe

You need a Stripe **test** key. Get it from **Stripe Dashboard → Developers → API keys** with the **Test mode** toggle on. It starts `sk_test_`.

Never use a live key. The harness refuses anything that is not `sk_test_`, because a live key would charge real cards.

From the `wp-docker` directory:

```bash
SUBMEX_STRIPE_TEST_KEY='sk_test_your_key_here' docker compose exec -T -e SUBMEX_STRIPE_TEST_KEY wordpress php /var/www/html/wp-content/plugins/submex/tools/sandbox-stripe.php
```

The key is read from the environment. It is never written to the database beyond the run, never printed, and never logged.

### What the harness does

1. Creates a real Stripe customer and attaches a test card
2. Builds a subscription that is due, wired to that customer
3. Charges the renewal — then **aborts the HTTP read after one second**. Stripe has taken the request; we never see the answer. That is a genuine unknown outcome, not a simulated one.
4. Asserts the charge slot is left `charging`, not `failed`
5. Asks Stripe directly whether a charge landed
6. Runs the pipeline again, so reconciliation resolves the slot
7. **Asks Stripe how many payment intents exist for that subscription.** Anything but exactly one is a double charge.
8. Runs a third time and confirms nothing moves

Step 7 is the whole point. It asks the gateway, not our own records.

Afterwards it deletes the subscription, its renewal orders, its ledger rows, the Stripe customer, and the key from the database.

### If it does not time out

On a fast connection the read can finish inside the second, and you will see an ordinary success instead of the timeout path. Re-run it. If it never times out, lower the timeout in the harness.

### The other gateways

No harness like this exists for any other gateway yet. The manual sandbox steps for every
gateway — free's Stripe and PayPal and all of Pro's — are in Submex Pro's `docs/GATEWAYS.md`.
PayPal and Paddle own their billing schedules, so there is no charge of ours to interrupt:
they need a sandbox subscription and a check that the webhook mirrors it, exactly once.

---

## Exercising the licence screens without a store

Pro has no store behind it yet, so every key comes back *"Licensing is not configured for
this build"* and the licence screens cannot be reached at all. To click through them, add
this to `wp-config.php`:

```php
define( 'SUBMEX_PRO_TEST_LICENCE', true );
```

Then **Submex → Settings → Licence** gains a **Use a test licence** button. No store is
contacted and any key is accepted.

| Key | What it shows |
|---|---|
| anything, or the button | Active, receiving updates |
| `TEST-EXPIRED` | Expired: billing continues, updates stopped |
| `TEST-REVOKED` | Refused, with the revoked message |

Two things it deliberately will not do. It never offers an update, because there is no
package and pointing WordPress at a fake one would break the site rather than test
anything. And an empty key is still refused, so the validation path stays honest.

**Remove the constant when you are done.** A site left in this state accepts any licence
key, so it warns on the licence screen and on every admin page until you do. The constant
lives in `wp-config.php` and so cannot travel inside the plugin.

---

## What is still not tested

Be clear-eyed about this list. It is short and it is the important part.

| | |
|---|---|
| ~~Automated PHP test suite~~ | **Done** as an integration suite (above). There are still no unit tests. |
| ~~Concurrency~~ | **Tested.** See below. |
| **Unattended renewals** | Scheduled renewals fire correctly when run directly. No renewal has been watched happening on its own overnight. |
| **Browser** | The admin screens are screenshotted through `tools/preview`, which renders them without WordPress's own sidebar and admin bar. No test drives a real wp-admin, and no test drives the storefront or checkout through a browser at all. |
| **Real gateway calls** | Until you run the harness above, every payment path in both plugins has only ever met a simulated response. |
| **Premium-plugin integrations** | AffiliateWP and AutomateWoo have only met stand-ins. |
