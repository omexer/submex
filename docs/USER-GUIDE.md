# Submex — User Guide

**Submex 1.0.1 · Submex Pro 0.52.0**

This guide explains everything Submex does and every setting it has, in plain language. You
do not need to be technical to follow it.

Please read [What is not proven yet](#what-is-not-proven-yet) before taking real money.

---

## Contents

1. [What Submex does](#what-submex-does)
2. [Installing](#installing)
3. [Your first subscription in five minutes](#your-first-subscription-in-five-minutes)
4. [Making subscription products](#making-subscription-products)
5. [Every setting, explained](#every-setting-explained)
6. [Taking payment](#taking-payment)
7. [What your customers see](#what-your-customers-see)
8. [Running the shop day to day](#running-the-shop-day-to-day)
9. [Pro features](#pro-features)
10. [The REST API](#the-rest-api)
11. [When something looks wrong](#when-something-looks-wrong)
12. [What is not proven yet](#what-is-not-proven-yet)

Then, once you know what the settings do, [BUSINESS-EXAMPLES.md](BUSINESS-EXAMPLES.md) builds
twelve real businesses with it — a coffee box, a course library, a SaaS licence, a gym — with
the field values to type and the integrations each one needs.

---

## What Submex does

A normal WooCommerce product is bought once. A **subscription** product is bought once and
then charges again on a schedule — every month, every year, every two weeks, whatever you
choose.

Submex adds that. It creates a subscription record when somebody buys, charges them when the
next payment falls due, emails them when something needs their attention, and gives them a
place to see and cancel it themselves.

**What you need:** WordPress 6.5+, WooCommerce 8.0+, PHP 8.1+. Submex refuses to start and
tells you if any of these is missing, rather than half-working.

**Free or Pro?** The free plugin is complete on its own — products, billing, renewals,
emails, customer self-service, Stripe and PayPal. Pro adds instalments, plan switching,
pausing, failed-payment recovery, delivery schedules, reports, content access, three more
payment gateways and nine integrations.

---

## Installing

1. Install and activate **Submex Subscription Billing for WooCommerce**.
2. For Pro, install **Submex Subscriptions Pro** and activate it **after** the free plugin.

Pro does nothing on its own and says so if you activate it alone. It needs the free plugin
version 0.9.0 or newer.

> **The folder name does not matter.** Downloading from GitHub gives you a folder called
> `submex-main`. That is fine — Pro finds the free plugin either way.

Then go to **Submex** in the admin menu, just below WooCommerce.

---

## Your first subscription in five minutes

On **Submex → Home** there is a checklist. It walks you through four things, and
the fourth is the one that matters.

**1. Connect a payment method.** Stripe or PayPal — both are included free, and the checklist
names whichever you connect. You can also skip it entirely and test with Cash on delivery;
renewals then become invoices the customer pays by hand, which still works.

**2. Create a subscription product.** There is a short form right there: name, price, how
often, optional free trial. Fill it in, press **Create it**, and the product is published.

**3. Check renewals can run.** This tells you whether WordPress's scheduler is working. On a
quiet site it often is not, which is the single most common reason subscriptions stop
billing.

**4. Run a test renewal.** This is the important one. It creates a throwaway subscription,
renews it, checks the whole chain worked, and deletes everything. Nobody is charged. If this
passes, your billing works. If it fails, it tells you why.

---

## Making subscription products

In **Products → Add New**, the **Product data** dropdown now has two extra entries:
**Subscription** and **Variable subscription**. Pick one and the subscription settings appear
under General, in sections — see [The subscription panel](#the-subscription-panel).

### Which type should I choose?

The question to ask is simple: **does the customer have a choice to make before buying?**

| | Choose **Subscription** | Choose **Variable subscription** *(Pro)* |
|---|---|---|
| The customer picks | Nothing — one price, one schedule | A plan, a size, a tier |
| Price | One price | A different price per option |
| Product page | Add to cart | A dropdown, then Add to cart |
| Shop page shows | `£29.00 every month` | `From £10.00 every month` |

**Choose Subscription when there is one way to buy it:**

- A £29/month membership
- A weekly veg box, one size
- Software billed yearly at one price
- A £5/month supporter tier

**Choose Variable subscription when the same thing is sold several ways:**

- **Monthly £10 / Yearly £100** — the classic. Same product, two billing schedules, and the
  yearly one is cheaper per month. This is the most common reason to use it.
- **Small box £15 / Medium £25 / Large £40**, all monthly
- **Basic / Pro / Team**, each a different price
- **Ground coffee / Whole bean**, same price, both monthly — a choice that does not change the
  billing at all is still a variation

Each variation can have its **own** schedule, or share the parent's. On a variation you get
**Different billing schedule** — tick it to give that one its own period, interval, trial and
fee. That is how Monthly-£10 and Yearly-£100 live on one product.

Twelve worked examples, with the values to type, are in
[BUSINESS-EXAMPLES.md](BUSINESS-EXAMPLES.md).

**Still not sure?** Start with **Subscription**. If you later need a second price or plan, you
can change the type on the same product — WooCommerce keeps the product, its URL and its
reviews.

> **Variable subscription needs Pro**, and only appears in the dropdown while Pro is active.
> A variation holds none of its parent's meta, so without Pro's resolver nothing gives it a
> billing schedule and it would sell as a one-off purchase. If you already have a variable
> subscription and Pro is switched off, the product keeps its type and Submex says so on the
> edit screen — it does not quietly turn into a simple product.

**What about physical and digital?** Either type works for both. Tick **Virtual** for
something not shipped and **Downloadable** for a file, exactly as you would on a normal
product. **Shipping required** in the panel is the same setting as the Virtual box, so you can
set it from either place. Delivery schedules (Pro) apply only to products that are actually
shipped.

### The subscription panel

The General tab is laid out in sections. The free plugin fills **Pricing**, **Billing
settings** and **Shipping settings**; Pro adds rows to those, plus **Custom renewal pricing**
and **More settings**. A section with nothing in it is not shown, and every field has a **?**
beside it that explains it in one line.

With Pro, the first row of Billing settings is **Payment type**: **Recurring** or
**Installment**. The rows below change to match — a field that does not apply is hidden, and a
hidden field is never saved, so what you see is exactly what the product does.

**Pricing**

| Field | What it means |
|---|---|
| **Regular price** / **Sale price** | WooCommerce's own. The regular price is what recurs. |
| **Sign-up fee** | A one-off amount charged *today*, on top of the first payment. Leave empty for none. |

**Billing settings**

| Field | Shown for | What it means |
|---|---|---|
| **Payment type** *(Pro)* | Both | **Recurring** bills until the customer cancels. **Installment** charges the price a set number of times, then stops. |
| **Bill every** | Both | A number and a unit on one row. `1` `Month(s)` is monthly; `2` `Week(s)` is fortnightly. |
| **Number of payments** *(Pro)* | Installment | How many times the price is charged, counting checkout. |
| **Fixed expiry date** *(Pro)* | Recurring | No payment is taken on or after this date; the period already paid for runs to its end. The product cannot be bought once the date has passed. A season pass that ends on 31 May. |
| **Maximum payments** *(Pro)* | Recurring | Ends the subscription after this many charges, counting checkout. Empty bills until cancelled. |
| **Minimum billing period** *(Pro)* | Both | How many payments, counting checkout, before the customer can cancel from **My Account**. Until then the Cancel button is replaced by a note saying when they can. You can still cancel for them. |
| **Free trial** | Both | A number and a unit — `14` `Day(s)`, `2` `Week(s)`, `1` `Month(s)`. Nothing is charged until it ends, apart from any sign-up fee. Empty means no trial. |
| **Subscription limit** *(Pro)* | Both | No limit, one live subscription per customer, one ever, or a maximum number — which shows a **Maximum per customer** box. |
| **Access limit** *(Pro)* | Installment | What the customer keeps once it is paid off: **Lifetime access after completion**, **Until the last paid period ends**, or **A fixed time from the first payment** — which shows an **Access for** box. |

**Custom renewal pricing** *(Pro, Recurring only)*

| Field | What it means |
|---|---|
| **Enable custom renewal pricing** | Yes to charge a different price from a later payment on — an introductory rate, say. |
| **New renewal price** | The price from then on, entered the same way as the regular price. |
| **Apply after payment number** | Payments counted from checkout. `3` charges the regular price for payments 1–3 and the new price from payment 4. |

The customer sees both prices before buying, and the terms they bought on are kept: changing
the product later does not reprice anybody who already subscribed. It is not offered for
Installment, which is sold as a fixed total.

**Shipping settings**

| Field | Shown for | What it means |
|---|---|---|
| **Shipping required** | Both | The Virtual box, from the other side: **No** makes the product virtual. |
| **Ship every** + **Delivery day** *(Pro)* | Recurring | A shipping cadence of its own — pay monthly, ship weekly — optionally pinned to a weekday. Leave **Ship every** empty to ship once per payment. |
| **Shipping charge** *(Pro)* | Recurring | **Free shipping for renewals** — how Submex has always billed: shipping is paid at checkout only. **Charge shipping on every renewal** adds the product's shipping to each renewal, by the method the customer chose at checkout. |

**More settings** *(Pro)*

| Field | What it does | Use it for |
|---|---|---|
| **Divide the price** + **Number of parts** | Treats the price as a total and splits it into equal charges, then stops. £300 as 3 × £100. | Paying off a course or a product over time |
| **Payment methods** | Tick the only methods this product accepts. Tick none to offer them all. | A product you only sell by card |
| **Automatic renewals only** | Hides payment methods that cannot renew by themselves | Products you refuse to invoice manually |
| **Grant role while active** | A WordPress role for this product only, overriding the store-wide setting | A "premium member" role for one tier |
| **Suggest upgrades to** + **Upgrade pitch** | Plans shown as "Upgrade your plan" on an active subscription in My Account, with your one-line pitch | Monthly → yearly, basic → premium |
| **Instead of cancelling, offer** | Plans offered after a customer cancels, while they still have access. Switching to one withdraws the cancellation | A cheaper plan for someone leaving on price |
| **Community groups** + **Member type** *(BuddyPress/BuddyBoss)* | Groups joined, and a member (profile) type set, while the subscription is active or trialling | A members' community |

Integrations add their own fields here too — courses, lists, tags — see
[INTEGRATIONS.md](INTEGRATIONS.md). On a variable product, set them on the parent: variations
use the parent's role, integrations, instalment plan and delivery schedule.

Two of these are easy to confuse:

- **Divide the price** divides one total. £300 over 3 parts = £100 each.
- **Installment** repeats a price. £100 × 3 payments = £300 total.

They do the same arithmetic from opposite ends, and a product cannot use both — Submex
refuses to save that. An instalment plan charges the part, not the total, at checkout and on
every renewal; with an uneven total the first payment takes the odd cents. A product cannot
have both a choice of plans and an instalment plan.

With a free trial, instalment and split plans, **Maximum payments**, **Minimum billing period**
and **Apply after payment number** count paid payments, not the free checkout: the first
instalment is charged when the trial ends.

> **PayPal, Paddle and these terms.** PayPal bills from a fixed plan of its own, so it cannot
> honour a custom renewal price, a fixed expiry date or renewal shipping. A product that uses
> any of them does not offer PayPal at checkout. Neither PayPal nor Paddle is offered for an
> instalment product, or when any coupon discounts a subscription in the cart, because both
> bill their own plan price. Gateways Submex charges itself are unaffected. A minimum billing period works with PayPal, except that a PayPal customer
> can always cancel inside PayPal itself.

A refused setting — a price left empty, a date in the past — is not saved, and a red notice at
the top of the screen says why.

### What the customer sees

Submex writes the terms on the product page automatically — as separate plain facts, not one
long sentence. The first line is the product's own price, which now says how often it
recurs; the facts follow underneath:

```
$29.00 every month
14 days free
$20.00 sign-up fee today
First payment $29.00 on 1 September 2026
Then $29.00 every month
Cancel anytime
```

Pro terms add their own lines, in plain words — for example:

```
From payment 4: $19.00 every month
No payments on or after 31 May 2027
Shipping is charged with every renewal
Cancel anytime after 3 payments
```

The same terms follow the product into the cart and the checkout, and the sentence beside
**Place order** states them too.

The price says how often it recurs everywhere WooCommerce prints one — the shop, category
pages, related products — not only on the product page. A product on sale keeps its
struck-through old price, with the interval after it. An instalment or split plan shows the
plan instead (for example *3 payments of £100*), because the price plus "every month" would
state a total the customer is not going to pay.

> **One subscription per cart.** A customer cannot buy two subscriptions at once — that is
> deliberate. A subscription plus ordinary products is fine.

### Variable subscriptions

*(Pro.)* Choose **Variable subscription**, set up your variations as normal, and each variation can
have its own schedule. On each variation you will find **Different billing schedule** — tick
it to give that variation its own period, interval, trial and fee. Leave it unticked and the
variation follows the parent product.

The shop page shows "From £10.00 every month", using the cheapest variation, because until
somebody picks one there is no single price to state.

---

## Every setting, explained

All of these live under **Submex → Settings**, a screen of sections: pick one on the left,
change what you need, then **Save changes**. Each section saves on its own.

### System status

Not settings — a read-out, in the panel on the right of every section and under **General →
Health**. Five lines telling you whether billing is actually working:

| Line | What it means | If it is red |
|---|---|---|
| **Renewal queue** | WordPress's background task system is running | Renewals are not happening. On a quiet site, ask your host to set up a real server cron. |
| **Unresolved charges** | A charge whose outcome nobody ever learned | That subscription has **stopped billing** on purpose, because charging again might charge twice. Open it and check your payment provider. |
| **Payments awaiting confirmation** | Renewals submitted to a payment provider that confirms days later (Direct Debit, bank payments) | One has waited more than 10 days. Check that the provider's webhook reaches your site, and look the payment up in its dashboard. |
| **Double-charge protection** | The database safeguard is in place | Deactivate and reactivate Submex. |
| **Renewal tax** | No subscription renews with tax added twice | See below. |

**Subscriptions renewing with tax added twice.** Before 0.19.4, a store that enters prices
*including* tax stored the subscription's price as if it excluded tax, so every renewal added
tax on top: 12.00 including 20% renewed at 14.40. New subscriptions renew at exactly what the
checkout charged. Existing ones are never changed silently: **General → Health** lists them,
each with a **Repair** button that stores the price without tax. Customers already charged
extra may be owed a refund — check their past renewal orders. Stores that enter prices
without tax were never affected.

### General → Renewals

**Missed renewals** — what to do when a subscription is overdue by more than one period,
which almost always means your site had no traffic and the scheduler stalled.

| Choice | What happens |
|---|---|
| **Charge once and move the schedule forward** *(recommended)* | The customer is charged once and the next date moves on. |
| **Charge for every missed period** | The customer is charged for each missed period, all at once. |

Only use the second if you physically ship goods for every period regardless. Otherwise you
are billing a customer several times over for an outage that was not their fault.

**Grace period (days)** — default `7`. After a payment fails, how long to keep trying before
giving up. Set it to `0` to give up immediately; `14` gives someone a fortnight to notice their
card expired. The setting's help text says access continues during this window, but a
subscription goes **on hold** when a payment fails, and on hold removes the subscriber role,
downloads and integration access until a payment succeeds. Which of the two is intended is an
open product decision; plan around the code's behaviour for now.

**Remind before charging** — default `3` days. Emails the customer before a renewal is
charged. `0` turns it off. A renewal falling due sooner than this is not warned about.

**Free first payments** — on by default. When nothing is due today — a free trial with no
sign-up fee, or a coupon worth the whole first payment — WooCommerce normally skips the payment
step, so no card is saved and the first renewal fails. With this on, checkout still asks for a
payment method whenever a later payment will actually charge something. Plans that renew for
nothing, and (with Pro) coupons that make every renewal free, are not asked. Offline methods —
bank transfer, cheque, cash on delivery — store no card, so choosing one still leaves nothing
to charge later.

### General → Access

**Buying without an account** — a subscription has to belong to someone, so that they can
manage and cancel it.

| Choice | What happens |
|---|---|
| **Create an account for them automatically** *(default)* | A guest who buys a subscription gets an account made at checkout. Smoothest for the customer. |
| **Require them to log in first** | They must sign in or register before buying. |

If someone checks out with an email that already has an account, Submex stops and asks them
to log in. It will not attach the subscription to an account they have not proved is theirs.

> If you choose **Require them to log in** but WooCommerce is not showing a login on the
> checkout page, Submex warns you — otherwise every subscription customer hits a dead end.

**Let customers pay early** — off by default. Shows a button in My Account that charges the next
period now. The renewal date does not move: paying early settles the payment that was already
coming. Offered only for methods Submex charges itself, never PayPal.

**Let customers turn off renewal** — off by default. Turn it on to show a switch in My
Account.

This is **not** cancelling. The customer keeps everything they have paid for until the
current period ends, and is simply never charged again. Many people who would otherwise
cancel in frustration will use this instead, and some turn it back on.

**Role while subscribed** / **Role once it ends** — the WordPress user role a customer gets
while paying, and what they drop to when they stop. This is how you gate content without a
membership plugin.

Leave both as **Leave the role alone** if you do not use roles.

Two safeguards: an **administrator is never changed** (you cannot demote yourself by buying
your own product), and a customer is only dropped when **no other live subscription** of
theirs still grants the role.

**Delete data when the plugin is deleted** — off by default. Removes Submex's settings and own
tables on uninstall. Subscriptions and their orders are never deleted either way.

**Health digest** *(Pro)* — how often to email you a summary of subscriptions needing
attention: Daily, Weekly *(default)*, Monthly or Never.

### Cart & checkout

| Setting | Default | What it does |
|---|---|---|
| **Allow mixed checkout** | On | A subscription and one-time products can be bought in one order. Off, the cart refuses to mix them in either direction, and both checkouts refuse a cart that already mixes them. |
| **Enable one-click checkout** | Off | Adding a subscription to the cart goes straight to checkout, from the product page and from product lists. |
| **Subscribe button text** | *Subscribe* | The add to cart button on subscription products. Leave it empty for *Subscribe* in the shopper's language. |

A cart still holds one subscription at a time, whatever these say: most gateways keep one
payment agreement per order.

---

## Taking payment

Submex adds its own payment methods under **Submex → Settings**. All are off until you enter
credentials.

| Gateway | How renewals are paid | Tier |
|---|---|---|
| **Stripe** | Submex charges the saved card when a payment falls due. | Free |
| **PayPal** | PayPal bills on its own schedule and tells us by webhook. | Free |
| **Square**, **Braintree**, **Authorize.net**, **Mollie**, **Xendit**, **WooPayments** | Submex charges the payment method that gateway's own WooCommerce plugin saved at checkout | Pro |
| **Razorpay** (UPI Autopay), **GoCardless** (Direct Debit), **Adyen** | Submex's own checkout sets up the mandate; Submex charges renewals | Pro |
| **Paddle** | Paddle bills on its own schedule and collects the tax, as merchant of record | Pro |
| **bKash**, **SSLCommerz** | They cannot charge a customer again, so each renewal is emailed as a payment link | Pro |

Each Pro gateway — what it rides on, where its settings are, its webhook address, its limits
and how to check it in the provider's sandbox — is described in Submex Pro's
`docs/GATEWAYS.md`. Check yours in its sandbox before taking real payments.

**Payments confirmed days later.** Direct Debit (GoCardless), UPI Autopay (Razorpay), and some
Adyen and WooPayments payments (SEPA, ACH) are submitted on the renewal date and confirmed
days later. Meanwhile the renewal is *pending*: the customer keeps access, the renewal order
waits **On hold** with the provider's reference, the next payment date does not move, and
Submex never retries or re-charges it. When the provider confirms, the renewal is marked paid
and the next one scheduled; if it fails, the normal failed-payment path runs once. A
subscription cancelled at the end of its period waits for a pending renewal before it ends.

**Paying a renewal by link.** A renewal that failed, or is waiting on the customer (a bank
transfer, a 3-D Secure confirmation, a bKash or SSLCommerz link), can be paid from its payment
link. The payment page offers only the subscription's own payment method — never PayPal,
which would start a second agreement. Paying it restarts the subscription exactly once, keeps
the card it was paid with for later renewals, and is never charged again. Marking a renewal
order paid yourself does the same.

Every gateway has an **Environment** setting: **Test/Sandbox** or **Live**. Always start in
test.

### Stripe

Enter your **Test secret key** and **Live secret key** from your Stripe dashboard (they start
`sk_test_` and `sk_live_`). Switch Environment to Live when you are ready.

A renewal that needs 3-D Secure goes on hold and emails the customer a link that opens
Stripe's own confirmation page.

### PayPal

PayPal needs four things, and one of them catches everybody:

1. **Client ID** and **Secret** from your PayPal app.
2. **Webhook URL** — Submex shows it. Copy it into your PayPal app and subscribe it to the
   billing-subscription and payment-sale events.
3. **Webhook ID** — PayPal gives you this *after* you add the URL. Paste it back.

**Without the Webhook ID, Submex rejects every webhook** — because it cannot prove the
message really came from PayPal — and no renewal is ever recorded. If PayPal is taking money
and your subscriptions are not updating, this is why.

**Known issue — tax.** In a store that enters prices without tax, PayPal bills its plan price
with no tax added, so the order total Submex records and the money PayPal actually takes can
differ. Not fixed yet.

### Square, Braintree, Authorize.net, Mollie and Xendit (Pro)

These renew against the payment method that the store's **existing** plugin saved at
checkout. Keep that plugin installed and configured for the first payment, and make sure it
saves the card. Submex only handles the renewals.

**If a Xendit renewal's answer never arrives** — a timeout, a dropped connection — Submex asks
Xendit again for the same charge, which cannot bill the customer twice. It can only do that for
24 hours. After that it stops and marks the charge as unresolved, so you can check your Xendit
dashboard and settle it by hand, rather than risk charging again.

### Razorpay (Pro)

Subscriptions bought through the Razorpay for WooCommerce plugin cannot renew — it never sets
up a mandate — so they renew as emailed invoices. For automatic renewals, enable Submex Pro's
own **Razorpay Subscriptions (Submex)** payment method (shown to customers as "UPI Autopay (Razorpay)"): INR only, UPI only (card mandates are not built yet),
with a **mandate limit** per renewal (₹15,000 by default). The bank notifies the customer
before each debit and debits about a day and a half later; the renewal is pending meanwhile.

### Every gateway field

| Field | Appears on | What to put in it |
|---|---|---|
| **Enable PayPal** / **Enable Stripe** | Free gateways | Tick to offer it at checkout. Off until you do. |
| **Enable … renewals** | Pro gateways that ride another plugin | Tick to let Submex renew against that gateway's stored payment method. |
| **Environment** | All | **Test**/**Sandbox** while you are setting up, **Live** when real money should move. |
| **Test secret key** / **Live secret key** | Stripe | From Stripe → Developers → API keys. `sk_test_…` and `sk_live_…`. |
| **Client ID** / **Secret** | PayPal | From your PayPal app. |
| **Webhook URL** | PayPal | Submex shows it — copy it into PayPal. |
| **Webhook ID** | PayPal | PayPal gives you this after you add the URL. Paste it back. |
| **Test API key** / **Live API key** | Mollie, Xendit | From that gateway's dashboard. |

Pro's other gateways have their own fields; see Pro's `docs/GATEWAYS.md`.

Submex's gateways work on both checkouts — the classic one and the newer block checkout —
and on either they are offered only when the cart actually contains a subscription.

A gateway with no credentials is never offered at checkout, even if enabled — so a
half-configured gateway cannot be chosen by a customer and then fail. **Submex tells you
when this is happening**, and names the field that is empty: seeing *Active* on the
WooCommerce payments screen and *There are no payment methods available* at the checkout is
almost always a gateway switched on with an empty key, or keys typed into Test while the
environment is set to Live.

### No gateway at all

You can run without any of them. Enable **Cash on delivery** or **Direct bank transfer**, and
each renewal becomes an invoice the customer pays by hand.

---

## What your customers see

Under **My account → Subscriptions** they get a list and a detail page, where they can:

- See the status, next payment date and what they are paying
- **Cancel** — either at the end of the period they have paid for, or immediately. You cannot
  switch this off; being unable to cancel is what causes chargebacks.
- **Turn off automatic renewal**, if you enabled it
- **Pay** a renewal that failed, or one waiting on them, from its payment link
- **Pay early**, if you enabled it
- **Pause**, **Resume** and **Switch plan** (Pro, where the gateway supports it)
- **Update card** (Pro, Submex Stripe subscriptions) — saves a new card on Stripe's own page;
  a renewal waiting on a declined card is retried on it straight away
- **Upgrade your plan** (Pro) — the upgrades you chose on the product, with a confirmation
  page stating the new price and the date it starts. Nothing is charged at the switch; the
  new price starts when the current period ends
- After cancelling, a retention discount and **Rather switch than go?** alternatives (Pro,
  where you set them up)
- **WhatsApp updates** (Pro) — an opt-in at checkout and in My Account

### Emails

Nine emails in the free plugin, all editable under **WooCommerce → Settings → Emails**:

| Email | When |
|---|---|
| Subscription started | The first payment succeeds |
| Upcoming renewal | **Remind before charging** days before a renewal; a trial is told it is ending |
| Renewal receipt | A renewal is paid |
| Payment failed | A charge is declined — includes a link to pay |
| Confirm your payment | The bank wants the customer to authenticate |
| Subscription cancelled | It is cancelled |
| New subscription *(to you)* | Somebody subscribes |
| Subscription cancelled *(to you)* | A subscription is cancelled, with the reason given |
| Subscription ended *(to you)* | A subscription ends |

Pro adds: Payment retry scheduled, Subscription ended after failed payment, Update your payment
details, Card expiring, Win-back follow-up, Subscription anniversary, the bKash and SSLCommerz
payment-link and upcoming-renewal emails (which replace the free ones for those
subscriptions), and the health digest to you.

---

## Running the shop day to day

### Submex → Home

Where Submex opens. Until setup is finished, the checklist comes first. After that:

- **The numbers** — monthly recurring revenue, active subscriptions, how many are on a free
  trial, and how many are on hold after a failed payment.
- **Needs your attention** — subscriptions on hold, and ones about to end. Each links
  straight to that filtered list. With nothing to act on, it says so rather than showing an
  empty box.
- **Recent subscriptions** — the latest six, each opening its own screen.
- **Shortcuts** to Integrations, Settings and Help.

### Submex → All subscriptions

Everyone who pays you on a schedule. You can **search** by name, email or id, **sort** by id,
next payment or total, and **filter** by status.

Tick rows to **cancel**, **put on hold** or **reactivate** several at once. Each row also
offers **View**, **Renew now** and **Parent order**.

Bulk changes follow the same rules as everything else: a subscription that cannot legally
make that change is skipped, not forced, and you are told how many were left alone and why.

Searching, sorting, filtering and paging all happen without reloading the page. Sorting asks
the server for the order, so it sorts every subscription you have, not just the page you can
see.

### A single subscription

Everything about it, plus an **Activity log** — every charge attempt, every status change,
with the reason. When a customer asks "why was I charged?", the answer is here.

**Process renewal now** charges it immediately, naming the amount before you confirm.

### The statuses

| Status | Meaning |
|---|---|
| **Pending** | Created, not started |
| **Trialling** | In a free trial, not yet charged |
| **Active** | Billing normally. A renewal still clearing (pending) leaves it Active |
| **On hold** | A payment failed, it was paused, or a bKash/SSLCommerz renewal is waiting for its link to be paid. Not billing, and no access. |
| **Pending cancel** | Cancelled, running out the paid period |
| **Cancelled** | Ended |
| **Expired** | Ran its course |
| **Switched** | Replaced by a different plan |

---

## Pro features

| | |
|---|---|
| **Instalment plans** | A fixed total over N payments, then it stops. £300 as 3 × £100. |
| **Split payments** | A set price × N payments, with access that can outlive the plan |
| **Pause and resume** | Remaining time is preserved |
| **Plan switching** | Upgrade or downgrade. Nothing is charged at the switch; the new price starts on the date the old plan was paid up to |
| **Upgrade suggestions** | "Upgrade your plan" in My Account and, optionally, in the renewal reminder; alternatives offered after a cancellation |
| **Recurring coupons** | Discounts that apply to renewals, not only the first order |
| **Sign-up fee coupons** | "Sign-up fee percentage discount" and "Sign-up fee fixed discount" coupon types, which take money off the sign-up fee only |
| **Payment retries** | How many times a soft-declined renewal is retried (1–5), the wait before each, and whether the subscription is cancelled or expired when they run out; one email per attempt; recovery figures in Reports |
| **Card updates** | "Update card" in My Account, an "Update your payment details" email on permanent declines, and an expiring-card warning |
| **Win-back** | Up to three emails to customers whose subscription ended, each with an optional single-use discount and a "Come back" button |
| **Anniversaries** | A thank-you email on 12-month (or chosen) milestones, with an optional renewal discount or store coupon |
| **Members-only content** | Lock posts, pages or part of a post (`[submex_restricted]`) to subscribers of chosen products |
| **Webhooks** | Subscription events through WooCommerce's own webhooks (topics `submex_subscription.*`) |
| **WhatsApp** | Renewal, payment and cancellation messages through the Meta WhatsApp Cloud API, to customers who opt in |
| **Subscription limits** | One active, one ever, or N per customer; and a cap on total payments |
| **Delivery schedules** | Ship on a different cadence from billing, with a printable manifest |
| **Subscription health** | Everything at risk, why, and one-click fixes |
| **Reports** | Revenue over time, signups, status breakdown, churn, lifetime value |
| **Content access** | Roles and downloadable files follow the subscription |
| **Live QR** | A code for the packing slip linking to a private status page |
| **REST API** | Pause and resume over the API, plus reports and health. The subscriptions API itself is free — see [the REST API](#the-rest-api). |
| **Thirteen integrations** | See [INTEGRATIONS.md](INTEGRATIONS.md), and [BUSINESS-EXAMPLES.md](BUSINESS-EXAMPLES.md) for which to connect for what |
| **More gateways** | See [Taking payment](#taking-payment) |

Pro's settings are sections of **Submex → Settings** (the same sections appear under
**WooCommerce → Settings → Subscriptions**):

| Section | What is in it |
|---|---|
| **Payment retries** | **Retry attempts**, **Wait before retry N** (hours), **When retries run out**, **Retry emails** |
| **Payment methods** | **Warn before a card expires** (days ahead, 14 by default) |
| **Win-back** | **Enable win-back**, then per email: **Send after**, **Subject**, **Heading**, **Message**, **Discount**, **Discount amount**, **Valid for** |
| **Anniversaries** | **Enable anniversary emails**, **When**, **Milestones**, **Thank-you gift**, **Discount type**, **Discount amount**, **Coupon valid for**, and the email's wording |
| **Upgrades** | **Renewal reminder** — suggest the first upgrade in the upcoming renewal email. The upgrades themselves are set per product |
| **Members-only content** | **Message for non-members**, **Before the message**, **Teaser length** |
| **WhatsApp** | **Enable WhatsApp**, **Phone number ID**, **Access token**, **App secret**, **Webhook verify token**, a template per event, and **Recent messages** |
| **AffiliateWP** | **Commission on renewals**, **Renewal rate (%)**, **Renewals that earn** |
| **Live QR**, **Licence** | Below |
| One section per Pro gateway | See Pro's `docs/GATEWAYS.md` |

### Live QR settings

**Submex → Settings → Live QR.** Prints a QR code on the subscription so you can put it on a
packing slip; the customer scans it and sees where their subscription stands.

| Setting | Default | What it does |
|---|---|---|
| **Enable Live QR** | On | Show the code and serve its status page. |
| **Show on the status page: what the subscription is for** | On | The product name. |
| **…the billing schedule and recurring total** | On | What they pay and how often. |
| **…a timeline of what has happened so far** | Off | Status changes over time. |

Each code carries its own secret link. Nobody can reach a subscription they were not given the
code for, and regenerating a code retires every slip already printed with it.

**The page never shows a name, email address, phone number, postal address or payment
details** — whichever of the three sections you switch on.

### The Pro licence

**Submex → Settings → Licence.** Paste your key and activate.

The licence controls **updates and support only**. If it lapses or expires, or the licence
server is unreachable, **your Pro features keep working and your customers keep being
charged.** A licence problem will never stop you taking money.

---

## The REST API

Free. Everything below works without a licence. A logged-in store manager can always use it;
for an app, turn on **Submex → Settings → API Settings → Allow API keys**, then create
a key under **WooCommerce → Settings → Advanced → REST API** on an account that can manage
WooCommerce. It authenticates exactly as it does on WooCommerce's own API: a read key can only
read, and a write key can only make changes. API Settings lists every endpoint on your site.

All of it lives under `/wp-json/submex/v1/`.

| Route | Method | What it does |
|---|---|---|
| `/subscriptions` | GET | A page of subscriptions. `page`, `per_page`, `status`, `customer`, `search`. Totals come back in the `X-WP-Total` and `X-WP-TotalPages` headers. |
| `/subscriptions/<id>` | GET | One subscription. |
| `/subscriptions/<id>` | POST | Move `next_payment` or `end_date`. |
| `/subscriptions/<id>/actions` | POST | `cancel`, `expire`, `reactivate`, `change_status` — and `pause` / `resume` with Pro. |
| `/subscriptions/<id>/activity` | GET | What has happened to it, newest first. |
| `/subscriptions/statuses` | GET | The statuses and what they are called, so you do not hard-code either. |
| `/dashboard` | GET | Everything the Submex Home screen shows: setup steps, the numbers, what needs attention, recent subscriptions. |
| `/overview` | GET | Recurring revenue, live count and the status breakdown, on their own. |
| `/reports` | GET | *(Pro)* Every figure on the Reports screen. `days` sets the range. |
| `/health` | GET | *(Pro)* Subscriptions at risk, why, and what would fix them. |
| `/health/<id>/actions` | POST | *(Pro)* `retry_now`, `requeue`, `email_customer`, `dismiss`, `restore`. |

Three behaviours worth knowing before you build on it:

- **An action that repeats itself succeeds.** Cancelling a cancelled subscription returns
  200, not a conflict — a call retried after a dropped connection must not look like a
  failure.
- **An action the status forbids returns 409**, and says which status it was in. That is a
  refusal, not an error.
- **Asking for `pause` without Pro returns 400**, because the action is not in the schema on
  that site. It is never a 500.

---

## When something looks wrong

**Renewals are not happening.** Check **Settings → General → Health**. If the renewal queue is
red, WordPress's scheduler is not running — on a quiet site it needs real traffic or a server
cron. Ask your host for a "server cron" pointing at `wp-cron.php`.

Once the scheduler is running again you do not need to do anything about the renewals it
missed. An hourly check picks up every subscription whose renewal is overdue, the most overdue
first, however many subscriptions the store has.

**A subscription stopped billing and nothing explains it.** Check **Unresolved charges** in
that same panel. If a charge's outcome was never learned, Submex stops rather than risk
charging twice. Look the payment up at your provider, then act on the subscription.

**A renewal has been "awaiting confirmation" for days.** Direct Debit and UPI Autopay
renewals take days, but after 10 the **Payments awaiting confirmation** line turns red. The
provider's webhook probably is not reaching your site: check its address in the provider's
dashboard, and look the payment up there.

**Renewal tax is red.** Some subscriptions renew with tax added twice. Open **General →
Health**, repair each one, and check whether its customer is owed a refund.

**PayPal is charging but subscriptions are not updating.** The Webhook ID is missing or
wrong. See the PayPal section above.

**An integration is not doing anything.** **Submex → Integrations** shows whether its plugin
is active. An integration whose plugin is missing does nothing, silently.

**I need to ask for help.** **Submex → Help** has a system report — versions, settings, queue
health — to paste into your request. It contains no passwords and no customer data.

---

## How Submex is tested

What has been verified, and what to check on your own store before taking real money.

| | |
|---|---|
| **Payment gateways** | Every gateway in both plugins is tested against simulated responses in the automated suites. Run one subscription and one renewal through your gateway's sandbox before going live. |
| Automated tests | Both plugins have an integration suite that runs against a real WordPress and WooCommerce on every push, with payment gateways faked. |
| **Unattended renewals** | Renewals are scheduled with Action Scheduler and tested when triggered; make sure WP-Cron or a real cron runs on your site. |
| ~~Concurrency~~ | **Tested.** Eight processes released together on one subscription produce exactly one charge. |
| Integrations | Most host plugins are not installed on the development machine; AffiliateWP and AutomateWoo were tested against stand-ins, BuddyPress against a real copy. See [INTEGRATIONS.md](INTEGRATIONS.md). |
| Browser testing | Very little has been checked visually. |

If it involves money — a wrong amount, a charge that should not have happened, a renewal that
did not — please say so in the first line of your report. Those get looked at first.
