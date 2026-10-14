# Submex — Feature Inventory

Everything either plugin does, what tier it is in, and **how well it is actually proven**. Measured against the committed code at Submex 0.23.0 and Submex Pro 0.45.0, not from memory.

Since 2026-09-24 both plugins have an integration suite (`tools/test.sh`, run in CI on PHP 8.1 and 8.4 — see [`TESTING.md`](TESTING.md)). **Run (suite)** below means a test in that suite drives the feature against a real WordPress and WooCommerce on every push. A gateway row stays **Mocked** even when the suite covers it: the gateway's HTTP is faked with `pre_http_request`.

Verification key:

| | |
|---|---|
| **Run** | Exercised against a live WordPress with real data, including the failure cases |
| **Mocked** | Exercised, but with the outside world simulated — no real gateway or third-party plugin was contacted |
| **Wired** | Code exists and loads; behaviour has not been driven end to end |
| **In flight** | Being written now; not committed, not reviewed |

---

## Core engine — Free

| Feature | Verification | Notes |
|---|---|---|
| Subscription as a native HPOS order type | Run | Not a custom post type; inherits line items, tax, refunds, notes |
| Status machine (8 states, illegal transitions refused) | Run | Terminal states cannot be reactivated |
| Billing schedule, UTC, anchored months | Run | Jan 31 → Feb 28 → Mar 31 → Apr 30 |
| Integer-minor-unit money with deterministic remainder split | Run | `300.01 / 3 = 100.01 + 100.00 + 100.00` |
| Charge-slot ledger, one charge per period | Run | `UNIQUE(subscription_id, period_index)` is the guard, not the lock |
| Idempotency key per attempt, rotating only on a definitive decline | Run | A timeout keeps its key |
| Reconciliation of unknown outcomes | Mocked | Every gateway implements it; no real timeout has ever occurred |
| Renewal pipeline | Run | Nothing contacts a gateway before the slot is claimed |
| Retry and scheduling via Action Scheduler | Wired | Never observed firing unattended |
| Next renewal queued as soon as one is charged | Run (suite) | Before 0.18.7 every renewal waited for the hourly sweep. A gateway that keeps timing out is retried at 2, 4, 8, 16 and 32 minutes, then left to the sweep |
| Missed-renewals policy ("Missed renewals" setting) | Run (suite) | Default charges once for the gap and resumes on the anchor day; applied since 0.18.1 |
| **Pending charge outcome** (Direct Debit, bank payments) | Run (suite) | Slot `pending`, renewal order on hold with the gateway reference, subscription keeps its status and access, next payment does not move; never retried, reconciled or dunned. Settled once by `Renewal_Processor::resolve_pending()`. Free ships no gateway that returns it — Pro's GoCardless, Razorpay, Adyen and WooPayments do |
| Pending renewals excluded from the hourly sweep | Run (suite) | So many clearing Direct Debits cannot starve the sweep's batch of 50 |
| Paying a failed or waiting renewal from its pay link settles it once | Run (suite) | Restarts the subscription exactly once, adopts the card it was paid with, never charged again. Also covers an order the store marks paid |
| Cancelled at period end ends on time | Run (suite) | Before 0.18.5 these stayed in Pending cancel for ever. An unknown charge is reconciled first; a pending renewal is waited for |
| Per-subscription lock is exclusive | Run (suite) | `INSERT IGNORE` of an `expiry\|uuid` value; release deletes only its own value. Before 0.19.2 two workers could both hold it on WP 6.9 |
| Renewal tax in tax-inclusive stores | Run (suite) | Line stored ex-tax, so renewal = checkout across prices, quantities, rates and rounding, on PHP 8.1 and 8.4. Before 0.19.4 tax was added twice |
| VAT exemption carried to renewals | Run (suite) | From the parent order to the subscription and each renewal order, with a fallback to the parent order for older subscriptions |
| Subscriptions read and saved through legacy (posts) order storage | Run (suite) | Broken before 0.18.6. End-to-end renewals on legacy storage are still not covered |
| Hourly sweeper for missed renewals | Run | Asks for due subscriptions directly, soonest first; proven with 53 subscriptions. On legacy order storage, checked for SQL errors only |
| Staging-clone protection | Run | A copied site refuses to bill |
| Activity log per subscription | Run | |
| Ledger and activity rows removed with the subscription | Run | On deletion only, never on trashing, so a restore stays safe |

## Products and checkout — Free

| Feature | Verification | Notes |
|---|---|---|
| Simple subscription products | Run | |
| Interval, period, free trial, sign-up fee | Run | Trials in days, weeks, months or years; a one-month trial from Jan 31 ends Feb 28, not Mar 3 |
| Sectioned product panel (Pricing, Billing, Shipping; rows follow the payment type) | Run | Rendered through WooCommerce's own meta box and clicked through in a browser harness — not a logged-in wp-admin. A hidden row is disabled, so it is never saved |
| Create the first product from the setup guide | Run | Invalid input refused, not coerced |
| **Admin screens in one visual language** (list, detail, settings, deliveries) | Run | Tokens shared by the PHP and React screens; clicked through in a logged-in wp-admin after every change |
| **Settings screen** (Submex → Settings) | Run | Sections, cards and a status rail. The fields are WooCommerce's own definitions, so Pro's sections appear untouched and Woo's handler saves them; driven in a logged-in wp-admin |
| Disclosure as independent facts, not prose | Run | Survives trial + fee + interval combined |
| Subscription end date enforced | Run | No renewal on or after it; the paid period runs out. Stored but never enforced before 0.14.0 |
| Classic checkout | Run | |
| Zero-total first payment still collects a payment method when renewals will charge | Run (suite) | Trials and coupons alike, classic and block checkout ("Free first payments"). Plans that renew for nothing are not asked; offline methods store nothing to charge |
| Block checkout via the Store API | Run | |
| One subscription per cart | Run | Enforced deliberately |
| Guest checkout with account creation | Run | A taken email is refused, never silently claimed |
| Require login instead | Run | Warns the merchant if Woo offers no login at checkout |

## Payments — Free

| Feature | Verification | Notes |
|---|---|---|
| Stripe checkout + off-session renewals | **Mocked** | Never called a real Stripe sandbox. Checkout creates a Stripe customer (0.18.3); older cards are attached to a new customer on their next charge. 3-D Secure renewals open Stripe's own page |
| PayPal Subscriptions + webhook mirroring | **Mocked** | Never called a real PayPal sandbox |
| Manual / invoice renewals | Run | |
| Test gateway (scripted outcomes) | Run | Refuses to run unless `WP_DEBUG` |
| Decline classification: soft / hard / transient / needs-action | Run | Only soft declines enter dunning |

## Customer self-service — Free

| Feature | Verification | Notes |
|---|---|---|
| My Account subscription list and detail | Run | |
| Cancel at period end or immediately | Run | Cannot be disabled by the merchant |
| Turn off automatic renewal | Run | Keeps access to the end of the paid period |
| Pay a failed renewal, or one waiting on bank transfer or 3-D Secure | Run (suite) | The pay page offers only the subscription's own method, never PayPal |
| Pay the next period early ("Let customers pay early") | Run (suite) | Off by default. A pending early payment is reported as on its way |
| Extension point for Pro actions | Run | Pause / Resume / Renew now render here |
| Ask before cancelling: optional reason, editable reasons, confirmation message | Run (suite) | Off by default. Plain forms; Continue canceling is one submit on every screen. Offers come from Pro |

## Admin — Free

| Feature | Verification | Notes |
|---|---|---|
| Subscriptions list and detail screen | Run | |
| List search, sorting, bulk and row actions | Run | Bulk changes respect the status rules; illegal ones are skipped and reported |
| Install an integration's plugin in place | Run | Allow list built from the screen; arbitrary slugs refused |
| MRR and live count on Overview | Run | Yearly, weekly, every-2-months all normalise correctly. Net of tax since 0.19.4 |
| Daily MRR snapshot, capped at 400 days | Run | History cannot be recomputed, so it is recorded |
| Setup checklist | Run | |
| One top-level Submex menu | Run | Overview, All subscriptions, Retention Flow, Integrations, Help, Settings, and Upgrade to Pro without Pro |
| Integrations screen | Run | Lists all thirteen Pro integrations and whether each host plugin is active |
| Help screen with a system report | Run | Proven to exclude Stripe, PayPal and licence credentials |
| Self-test renewal (creates, renews, deletes) | Run | |
| Health panel: queue, unresolved charges, payments awaiting confirmation, ledger index, renewal tax | Run | Unresolved flips on after an hour; "Payments awaiting confirmation" after 10 days pending; "Renewal tax" lists affected subscriptions with a Repair action (Settings → General → Health) |
| Renewal tax Repair action | Run (suite) | Nonce'd, capability-checked, idempotent; adds a note that the customer may be owed a refund. Lists only live subscriptions created before the fix, in tax-inclusive stores, with no tax lines, not repriced by Pro |
| Nine transactional emails (six customer, three merchant) | Run | Merchant mail goes to the merchant, not the customer. "Subscription started" may send twice |
| Privacy exporter and eraser | Wired | Refuses to erase an active subscription |

## Access — Free

| Feature | Verification | Notes |
|---|---|---|
| Role while subscribed / once ended | Run | Administrators never demoted; kept while another subscription is live |
| Downloadable file gating | Run | Active + cancelled pair keeps the files |

## API and admin UI — Free

| Feature | Verification | Notes |
|---|---|---|
| **REST API**: list, read, update, act on, and read the history of a subscription | Run | Proven with Pro on and off; 401 / 404 / 409 / 400 paths all covered |
| Overview endpoint | Run | Recurring revenue, live count and status breakdown |
| Dashboard endpoint | Run | Setup steps, numbers, what needs attention, recent subscriptions |
| React Overview screen | Run | Setup until done, then numbers, attention and recent subscriptions; a failed request leaves the server-rendered Overview showing |
| One frame for every Submex screen | Run | Header with breadcrumbs, page heading, footer; checked by screenshot, not only by test |
| React subscriptions list | Run | Tabs, search, server-side sorting, bulk actions, paging; 6 tests, bulk-ids assertion mutation-checked |
| React subscription detail | Run | Facts, actions, date editing, activity; a panel added by another plugin stays visible |

---

## Pro

| Feature | Verification | Notes |
|---|---|---|
| **Licensing** (activate, validate, updates, seat release) | Mocked | Gates updates and support only — **never billing**. Core now asks the updater about Pro (proven); the release it fetches is still mocked |
| **Variable subscriptions** | Run (suite) | Per-variation schedule, inheritance, "From" price. Since 0.39.0 variations read the parent's role, integrations, instalment plan and delivery schedule; a once-only backfill grants missing role/integration access to live variable subscriptions |
| **Instalment plans** | Run (suite) | Fixed total over N charges, then stops. Before 0.39.0 each charge was the full price; now checkout and renewals charge the part, odd cents on the first. PayPal and Paddle are not offered for them. Pre-fix live subscriptions are listed in Pro Health, not changed |
| **Pause and resume** | Run | Remaining time preserved |
| **Plan switching** | Run (suite) | Since 0.34.0 nothing is charged at the switch and the new price starts on the old plan's paid-up date (the old credit line was never copied to renewals). Refused for on-hold/overdue/pending, a minimum term still owed, instalment/split plans, and gateway-managed subscriptions whose gateway does not `supports('switch')` (PayPal) |
| **Upgrade suggestions and cancel-and-switch** | Run (suite) | Per product under More settings; My Account "Upgrade your plan" and "Rather switch than go?"; optional line in the renewal reminder; Reports block |
| **Recurring coupons** | Run (suite) | Discounts that survive into renewals; checked with tax in both modes (percent, fixed cart, fixed product) |
| **Sign-up fee coupons** | Run (suite) | Two discount types that touch only the sign-up fee. PayPal hidden while one applies; moved to draft when Pro is deactivated |
| **Failed payment recovery** (configurable retries, retry emails, recovery report) | Run (suite) | Payment retries: 1–5 attempts, wait per attempt in hours, cancel or expire at the end; default waits 1, 2, 3 days. Hard declines are never retried and there is no give-up timer for them yet |
| **Card updates and card emails** | Mocked | Stripe faked in the suite. "Update card" in My Account for Submex Stripe subscriptions; "Update your payment details" on hard declines; "Card expiring" scan (default 14 days ahead) |
| **Subscription health** (6 signals, digest email) | Run | Every signal has a positive *and* a negative case. Since 0.39.0 also lists live subscriptions that missed their instalment plan or delivery schedule (sold before the fix) — listed, never changed |
| Health row actions: retry now, ask the customer | Run | Retry runs the real pipeline; the slot still guards the charge |
| Row actions over AJAX | Run | Progressive enhancement: plain submits without JavaScript |
| **Reports charts** (area, bars, ring) | Run | Inline SVG; edge cases drawn, not crashed |
| **Delivery schedules** (cadence, manifest, print) | Run | Guards refuse rather than coerce |
| **Reports** (MRR, ARR, churn, LTV) | Run | |
| **Content access** (per-product roles, download capability) | Wired | |
| **Members-only content** | Run (suite) | Per-post panel and `[submex_restricted]` shortcode; kept out of excerpts, feeds, REST, search and the Latest Posts block. On hold does not unlock |
| **Win-back campaign** | Run (suite) | Up to three emails, single-use personal coupons, signed come-back link, confirmed unsubscribe |
| **Anniversary thank-yous** | Run (suite) | Daily scan, 7-day window so enabling it never floods; renewal discount or personal store coupon |
| **Retention offer on cancellation** | Run (suite) | Whether its coupon discounts renewals is unverified |
| **Subscription webhooks** | Run (suite) | WooCommerce's own webhooks, resource `submex_subscription`; see Pro's `docs/WEBHOOKS.md` |
| **WhatsApp notifications** | Mocked | Meta Cloud API faked; opt-in at checkout and My Account; exactly-once per event |
| **REST API**: pause and resume, reports, health | Run | 401 / 404 / 409 / 400 paths all proven. The subscriptions routes themselves are free. |
| **Mollie renewals** | Mocked | Idempotency-Key + payment metadata |
| **Square, Braintree, Authorize.net renewals** | Mocked | Ride the store's own WooCommerce gateway plugin; token stored at activation and backfilled (0.25.1) |
| **GoCardless Direct Debit** | Mocked | Own mandate checkout; renewals pending until the webhook confirms |
| **Adyen** | Mocked | Own hosted checkout, card only; Pending/Received renewals pending |
| **WooPayments renewals** | Mocked | Through WooPayments' own `process_payment_for_order()`; SEPA/ACH processing is pending |
| **Paddle Billing** | Mocked | Gateway-managed, merchant of record; non-shipping carts only; no cycle cap, so not offered for instalments |
| **bKash, SSLCommerz** (pay link) | Mocked | No off-session charging; each renewal is on hold until its link is paid; unpaid links are not chased |
| **Razorpay renewals** | Mocked | Rebuilt in 0.31.0: Submex's own UPI Autopay checkout registers the mandate (the Razorpay plugin never did, so earlier renewals always declined). Renewals are pending until the debit lands (~36h). Card mandates not built |
| **Xendit renewals** | Mocked | Refuses to replay past the 24h key window; reconciliation finds the renewal order from its attempt record |
| **Subscription limits** (one active / one ever / N per customer) | Run | Guests refused, not waved through |
| **Payment cap** (end after N charges) | Run | |
| **Gateway restriction** for subscription purchases | Run | Through Woo's own filter, never the renewal registry |
| **Split payments** (per-payment price x N) | Run | Totals reconcile exactly; distinct from instalments |
| **Retained access** after a completed split plan | Run | Lifetime or a dated window, honoured after expiry |
| **Custom renewal pricing** (a new price from payment N) | Run | Stamped at checkout, so editing the product never reprices an existing customer; applied before recurring coupons. Recurring only |
| **Fixed expiry date** | Run | Becomes the subscription's end date; the product cannot be bought after it |
| **Minimum billing period** | Run | The customer's own cancel is held back with a reason until N payments; the store can still cancel |
| **Renewal shipping charge** | Run | Copies the checkout shipping when the product is the order's only shippable item (run); otherwise quotes the product alone (Wired) |
| **PayPal withheld** for terms PayPal's plan cannot honour | Wired | Which products qualify is run; the checkout filter itself has not been driven through a cart |
| **Live QR status page** | Run | Unguessable token; enumeration proven to fail |
| **QR encoder** (ours, versions 1-10) | Run | Proven by decoding what it draws |
| LearnDash, TutorLMS, LearnPress | Wired | Host plugins not installed. Variable products read the parent's settings since 0.39.0 |
| BuddyBoss / BuddyPress groups and member type | Run | Run against BuddyPress 14.5.2 during development; the CI suite uses a stand-in where BuddyPress is not active. BuddyBoss Platform read from source only. Removes only what Submex added |
| AutomateWoo triggers, data type, actions | Mocked | Against a stand-in built from AutomateWoo 6.9.0's GPL source. Verify against a licensed copy before release |
| AffiliateWP recurring referrals | Mocked | Against a stand-in built from AffiliateWP's documented behaviour. Verify against a licensed copy before release |
| MailPoet, FluentCRM | Wired | Host plugins not installed |
| WP Fusion, AutomatorWP | Mocked | Proven inert without the host; active path against fakes |
| License Manager for WooCommerce | Mocked | Same |
| WP Software License | Mocked | `update_licence_status` is a **guess**; guarded, needs a real install |

---

## Deliberately not built

| | Why |
|---|---|
| Migration importer from another subscriptions plugin | Neither plugin we benchmarked has one either |
| Multiple subscriptions per cart | Blocked on purpose |
| Removing WordPress comment metaboxes | A custom-post-type problem we do not have |
| Vendored 11,000-line QR library | Wrote a focused encoder instead, verified by decoding its own output |
| A status page keyed on the subscription id | The reference does this with no ownership check; it leaks every customer's subscription |

---

## The gap that is not a feature

The rows below that are not struck through are the reason nothing above should go near a live store yet.

| | |
|---|---|
| ~~Automated PHP tests~~ | **Done.** Integration suites in both plugins (`tools/test.sh`), run in CI on PHP 8.1 and 8.4 against a disposable wp-env site. Still no unit tests. |
| ~~CI~~ | **Done.** Both plugins run PHPCS and PHPStan on every push and pull request. |
| ~~Static analysis~~ | **Done.** PHPStan level 5 and PHPCS clean on both plugins. See `docs/TESTING.md`. |
| **Real gateway calls** | Still zero, for every gateway in both plugins. `tools/sandbox-stripe.php` drives the reconciliation path against a real Stripe test account and needs a test key; the other gateways' sandbox steps are in Pro's `docs/GATEWAYS.md`. |
| **Unattended renewals** | Never observed firing on their own. |
| ~~Concurrency~~ | **Done.** `tools/concurrency-test.php` races eight real processes: one claim wins, seven are refused, one charge reaches the gateway. |
| **Browser testing** | The admin screens are screenshotted through `tools/preview`, without WordPress's own sidebar and admin bar. No test drives a real wp-admin, and the storefront and checkout have never been driven through a browser. |

What remains is a real gateway call and a renewal watched firing on its own. The concurrency guarantee - the one
that would have invalidated everything else - now holds under a real race.
