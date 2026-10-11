# Business examples

Nine integrations, two product types and forty-odd settings do not tell you what to build.
This chapter does: real businesses, the exact product each one needs, and which integrations
have to be connected before it works.

Every example gives you the field values to type. Fields not mentioned should be left alone —
their defaults are right for that business.

Read [USER-GUIDE.md](USER-GUIDE.md) first if you have not set up a subscription product
before, and [INTEGRATIONS.md](INTEGRATIONS.md) for what each integration does in detail.

---

## Contents

1. [Pick your business in one table](#pick-your-business-in-one-table)
2. [Physical products delivered regularly](#1-physical-products-delivered-regularly)
3. [Digital products and memberships](#2-digital-products-and-memberships)
4. [Software and online services](#3-software-and-online-services)
5. [Memberships and recurring services](#4-memberships-and-recurring-services)
6. [Two complex builds](#two-complex-builds)
7. [Which integration for which product](#which-integration-for-which-product)
8. [Before you take real money](#before-you-take-real-money)

---

## Pick your business in one table

| You sell | Product type | Integrations you need | Tier |
|---|---|---|---|
| A one-size box, one price | Subscription | None | Free |
| Boxes in three sizes | Variable subscription | None | **Pro** |
| Ship weekly, bill monthly | Subscription | None — **Delivery schedule** field | Pro |
| An online course | Subscription | LearnDash / Tutor LMS / LearnPress | Pro |
| Members-only articles | Subscription | None — **Grant role while active** | Pro |
| A download library | Subscription | None — WooCommerce downloads | Pro |
| SaaS with a licence key | Subscription | License Manager for WooCommerce | Pro |
| Monthly / yearly pricing | Variable subscription | None | **Pro** |
| A 6-month coaching programme | Subscription | **Payment type** = split payments | Pro |
| A course paid off in 3 goes | Subscription | **Instalment plan** | Pro |
| Anything with a mailing list | either | MailPoet / FluentCRM | Pro |
| Anything with a CRM behind it | either | WP Fusion | Pro |
| Anything with custom automation | either | AutomatorWP | Pro |

Nothing in the **Free** rows needs Pro. A coffee box at one price, billed monthly, taken by
Stripe, is entirely free — including the customer's cancel and pay-a-failed-renewal pages.

> **Variable subscriptions are a Pro feature**, and the type only appears in the Product data
> dropdown while Pro is active. A variation carries none of its parent's meta, so without
> Pro's resolver nothing gives it a billing schedule and it would sell as a one-off purchase.
> If you need per-variation pricing or a monthly/yearly choice, you need Pro. An existing
> variable subscription keeps its type if Pro is switched off, and warns you on the edit
> screen rather than silently becoming something else.

---

## 1. Physical products delivered regularly

*Coffee and tea boxes · skincare · pet food · vitamins and household essentials*

What makes these different from every other kind of subscription: **something leaves a
warehouse**. Billing is the easy half. The half that goes wrong is shipping — cadence,
stock, and knowing what to pack this week.

### Example A — a coffee box, three sizes

**The business.** A roastery ships coffee monthly. Customers choose 250g, 500g or 1kg, and
between ground and whole bean.

**The product.** Product data → **Variable subscription** *(needs Pro)*. Attributes: Size (250g / 500g /
1kg) and Grind (Ground / Whole bean). Generate variations from both.

| Field | Value | Where |
|---|---|---|
| Bill every | `1` `Month` | Parent, General |
| Free trial (days) | `0` | Parent |
| Sign-up fee | *(empty)* | Parent |
| Different billing schedule | **unticked** | Every variation |
| Regular price | 15 / 25 / 40 | Per variation |
| Virtual / Downloadable | **unticked** | Every variation |

Six variations share one monthly schedule and differ only in price. The shop page reads
*From £15.00 every month*.

**Settings that matter.**

- **Settings → General → Renewals → Missed renewals**: *Charge once and move the schedule
  forward*. If your scheduler stalls for a fortnight you ship one box, so you charge for one
  box.
- **Grace period**: `7`. A declined card gets a week of retries while the customer keeps
  their place in the next run.
- **Settings → General → Access → Buying without an account**: *Create an account for them
  automatically*. Physical-goods buyers abandon registration forms.

**Integrations.** None required. Add **MailPoet** or **FluentCRM** with a *Coffee subscribers*
list if you want to mail them about the month's roast — subscribing on activation and
removing on cancel is exactly what the integration does, so the list is never stale.

**What happens.** They order today and are charged today. Every month Stripe is charged again
and a renewal order appears in WooCommerce for you to fulfil. Cancel, and no further order is
created — but the box they have already paid for still ships, because the current period runs
to its end.

### Example B — pet food, shipped weekly, billed monthly

**The business.** Fresh dog food. A week's food at a time, because it will not keep, but
customers hate being charged four times a month.

**The product.** Product data → **Subscription**.

| Field | Value |
|---|---|
| Regular price | `80` |
| Bill every | `1` `Month` |
| **Delivery schedule** *(Pro)* | **Enabled** |
| **Deliver every** | `1` `Week` |
| **Deliver on** | `Monday` |

One charge a month, four deliveries. **Submex → Deliveries** prints the manifest for a given
day: who is due, what, and where.

**Settings that matter.**

- **Missed renewals**: this is the one business where *Charge for every missed period* can be
  right — if you shipped food every week regardless of whether billing ran, you are owed for
  every week. Only choose it if that is literally true.
- **Purchase limit** *(Pro, on the product)*: *One live subscription per customer*. Two food
  subscriptions for one dog is a support ticket, not a sale.

**Integrations.** **AutomatorWP** earns its place here. Its *Renewal failed* trigger can hold
the next delivery and text the kitchen, which matters when the alternative is cooking food
for somebody who has stopped paying.

### Example C — vitamins, prepaid three months at a time

**The business.** A 3-month vitamin course, £120, paid in three monthly instalments. It ends
by itself.

**The product.** Product data → **Subscription**.

| Field | Value |
|---|---|
| Regular price | `120` |
| Bill every | `1` `Month` |
| **Instalment plan** *(Pro)* | **Enabled** |
| **Number of payments** | `3` |

£40 a month, three times, then the subscription completes on its own. Nobody is charged for a
fourth month and nobody has to remember to cancel.

> **Instalment plan** divides a total. **Payment type / split payments** repeats a price.
> £120 over 3 instalments is £40 each; £120 × 3 split payments is £360. A product cannot use
> both — Submex refuses to save that.

---

## 2. Digital products and memberships

*Online courses · ebooks and resources · members-only articles · download libraries*

Nothing ships, so billing is simple. The work is **access**: granting it the moment they pay,
and taking it back the moment they stop — without stripping access they still pay for through
some other subscription.

### Example A — a course library on LearnDash

**The business.** Twelve courses. £39 a month for all of them, or £390 a year.

**The product.** Product data → **Variable subscription** *(needs Pro)*. Attribute: Plan (Monthly /
Yearly).

| Field | Monthly variation | Yearly variation |
|---|---|---|
| Different billing schedule | **ticked** | **ticked** |
| Bill every | `1` `Month` | `1` `Year` |
| Regular price | `39` | `390` |
| Virtual | **ticked** | **ticked** |

This is the single most common reason to reach for a variable subscription: one product, two
billing schedules, the yearly one cheaper per month.

**Integrations — required.** **LearnDash**, and on the product, the **courses** this
subscription grants. Pick all twelve.

Activate → enrolled in all twelve. Cancel or expire → un-enrolled, *unless another live
subscription of theirs includes the same course*. Somebody on both the library plan and a
single-course plan keeps that one course when the library lapses. That protection is
automatic and is the thing that stops the angriest support tickets.

> LearnDash is sold commercially, so **Submex → Integrations** links out to buy it rather than
> offering an install button. Tutor LMS and LearnPress install in place — but their enrolment
> calls are written to the documented API and have **never been run against a live install**.
> Check the first enrolment by hand.

**Integrations — worth adding.** **FluentCRM**, with tag `student-active`. Your onboarding
sequence triggers on the tag, not on Submex, so marketing can change the emails without
touching the shop.

### Example B — members-only articles, no membership plugin

**The business.** A publication. £8 a month unlocks the archive. No LMS, no membership
plugin, just WordPress.

**The product.** Product data → **Subscription**. Price `8`, Bill every `1` `Month`, Virtual
ticked.

**The mechanism is roles, and it is a setting, not an integration.**

**Settings → General → Access:**

| Setting | Value |
|---|---|
| Role while subscribed | `Subscriber` — or a custom `member` role |
| Role once it ends | `Customer` |

Then gate your content on that role, however your theme already does it.

Two safeguards you get free: an **administrator is never changed**, so you cannot demote
yourself by buying your own product; and a customer is only dropped when **no other live
subscription** of theirs still grants the role.

If you sell tiers, use **Grant role while active** on each product instead — it overrides the
store-wide setting per product, so Bronze and Gold can grant different roles.

**Settings that matter.**

- **Let customers turn off renewal**: **on**. For content, this is the single most valuable
  setting in the plugin. Someone who would have cancelled in irritation instead switches
  renewal off, keeps reading to the end of the month, and a fair few switch it back on.

**Integrations.** None needed. **WP Fusion** if your CRM should know: a tag per status, so a
win-back sequence fires on the Cancelled tag and a dunning nudge on On hold.

### Example C — a downloadable resource library

**The business.** £15 a month for a library of templates and ebooks.

**The product.** Product data → **Subscription**, **Virtual** and **Downloadable** both
ticked, files attached exactly as on any WooCommerce product.

Submex ties the downloads to the subscription: active, they can download; stopped, they
cannot. No integration, no extra plugin.

**Pro field worth knowing.** **Access ends** / **Access duration**. Set it to *Keep access for good* and
they keep what they downloaded even after cancelling — which is the honest way to sell a
library people build a workflow around, and it converts better than the alternative.

---

## 3. Software and online services

*SaaS plans · website maintenance · premium support · monthly business tools*

The distinguishing feature is a **licence key**. A key that keeps working after somebody
stops paying is lost revenue; a key that is replaced with a fresh one at every renewal is a
support ticket every month.

### Example A — a WordPress plugin sold as SaaS

**The business.** A plugin, £49/site/year, sold with a licence key that the customer's site
checks.

**The product.** Product data → **Variable subscription** *(needs Pro)*. Attribute: Sites (1 / 5 /
Unlimited).

| Field | Value |
|---|---|
| Bill every | `1` `Year` (parent) |
| Different billing schedule | **unticked** on all variations |
| Regular price | 49 / 149 / 399 |
| Virtual | **ticked** |
| **Automatic renewals only** *(Pro)* | **ticked** |

*Automatic renewals only* hides payment methods that cannot renew by themselves. For software
that must not lapse silently, that is the right trade.

**Integrations — required.** **License Manager for WooCommerce**.

Nothing to configure on the product. The keys the original order generated are found
automatically, and then:

| Event | What happens to the key |
|---|---|
| Subscription active | Activated; expiry set to the next payment date |
| Renewal succeeds | Expiry pushed forward to the new next payment date |
| Cancelled, expired, payment failed | Deactivated |
| A renewal order is created | Licence generation **suppressed** |

That last row is the one that matters. Without it a customer accumulates a fresh licence key
every year and nobody knows which one their site is using.

> **WP Software License** does the same job for that plugin, but the call that changes licence
> state is an **assumption** — it is guarded so it does nothing rather than failing loudly,
> and it fires `submex_integration_unsupported`. Verify it against a real install before
> relying on it.

**Settings that matter.**

- **Grace period**: `14` for annual software. A card that expired eleven months ago needs
  longer to notice than a card used last month.
- **Health digest** *(Pro)*: **Weekly**. For annual billing, a weekly digest of what is at
  risk is how you catch a failing renewal before the key deactivates.

### Example B — website maintenance retainer

**The business.** £150 a month for maintenance. Clients come and go, some pay by invoice.

**The product.** Product data → **Subscription**, price `150`, Bill every `1` `Month`,
Virtual ticked.

**Taking payment without a gateway.** You do not need one. Enable WooCommerce's **Direct bank
transfer** and every renewal becomes an invoice the client pays by hand. Submex still tracks
the schedule, still marks it overdue, still shows it in Health.

Leave **Automatic renewals only** *unticked* here — it is precisely the opposite of what this
business needs.

**Integrations.** **AutomatorWP**, on *Renewal paid*, to open a ticket or file a report
automatically each month.

### Example C — premium support, capped at twelve months

**The business.** A support contract. Twelve months, then it must be renegotiated rather than
rolling into a second year.

**The product.** Product data → **Subscription**, price `99`, Bill every `1` `Month`, plus:

| Field | Value |
|---|---|
| **Maximum payments** *(Pro)* | `12` |

After the twelfth charge the subscription ends, whatever else is configured. Contracts that
auto-renew into a year the client did not agree to are how chargebacks start.

---

## 4. Memberships and recurring services

*Gyms · coaching · communities · recurring cleaning and maintenance*

A person, a place, and a limited number of slots. The recurring theme here is **limits** —
one membership per person, a fixed number of sessions, a programme that ends.

### Example A — a gym membership, three tiers

**The business.** Off-peak £25, Standard £40, Premium £60. Monthly. Front desk needs to know
who is current.

**The product.** Product data → **Variable subscription** *(needs Pro)*. Attribute: Membership (Off-peak /
Standard / Premium), one price each, all monthly.

| Field | Value |
|---|---|
| Bill every | `1` `Month` (parent) |
| Regular price | 25 / 40 / 60 |
| **Purchase limit** *(Pro)* | *One live subscription per customer* |
| **Grant role while active** *(Pro)* | a role per tier — `gym-offpeak`, `gym-standard`, `gym-premium` |

*One live subscription per customer* stops the double-membership ticket before it happens. Per-tier roles
give the front desk something to read, and the member-only pages something to check.

**Settings that matter.**

- **Let customers turn off renewal**: **on**. A gym member who cannot stop their own payments
  disputes the charge with their bank, and a chargeback costs more than the month did.
- **Grace period**: `7`.

**Integrations.** **WP Fusion**, tags per status: `member-active`, `member-on-hold`,
`member-cancelled`. Reception reads them, and the win-back campaign fires on the cancelled
tag without anybody exporting a CSV.

**Pro features that earn their keep.** **Pause and resume** — remaining time is preserved, so
a member going travelling for two months pauses instead of cancelling, and the gym keeps them.

### Example B — a 12-week coaching programme

**The business.** Twelve weeks of coaching, £600, paid £200 a month for three months. It ends
when it ends.

**The product.** Product data → **Subscription**.

| Field | Value |
|---|---|
| Regular price | `200` |
| Bill every | `1` `Month` |
| **Payment type** *(Pro)* | *Split payment plan* |
| **Number of payments** | `3` |
| **Access ends** *(Pro)* | *Until the period the last payment covers ends* |
| **Purchase limit** *(Pro)* | *One subscription per customer, ever* |

£200 × 3 = £600, then it stops. *One subscription per customer, ever* is right for a programme somebody does once.

Set **Access ends** to *Keep access for good* instead if the recordings should stay available — that one
choice is often worth more than the price difference between two tiers.

**Integrations.** **LearnDash / Tutor LMS / LearnPress** if the programme has a course
attached, plus **FluentCRM** tagged `coaching-cohort-active` to drive the weekly emails.

### Example C — a community with a free trial

**The business.** A paid community. £12 a month, fourteen days free first.

**The product.** Product data → **Subscription**.

| Field | Value |
|---|---|
| Regular price | `12` |
| Bill every | `1` `Month` |
| **Free trial (days)** | `14` |
| **Purchase limit** *(Pro)* | *One subscription per customer, ever* — so the trial cannot be farmed |

The customer pays nothing today and is charged in a fortnight. The product page says so,
plainly, in its own line.

**Integrations.** **AutomatorWP** on *Trial ended* and *Subscription activated* to post the
welcome and hand out the forum role; **MailPoet** or **FluentCRM** for the trial nurture
sequence.

> Check MailPoet's own settings before launch — it may send its own confirmation email when
> somebody is added to a list, and a trial signup does not want two welcomes.

---

## Two complex builds

Most businesses need one integration or none. These two need several at once, and are worth
walking through because the order they fire in matters.

### A certification programme — course, licence, CRM and automation

**The business.** A £1,200 professional certification. Paid over six months. Students get
course access, exam-software licence keys, a private community, and a certificate at the end.
Access to the course materials is for life; the exam licence is not.

**Product.** Subscription, price `1200`, Bill every `1` `Month`, plus:

| Field | Value |
|---|---|
| **Instalment plan** | Enabled, **Number of payments** `6` |
| **Access ends** | *Keep access for good* |
| **Grant role while active** | `certification-student` |
| **Purchase limit** | *One subscription per customer, ever* |

£200 a month for six months, then it stops by itself.

**Four integrations, all active at once:**

| Integration | Configured with | Does |
|---|---|---|
| **LearnDash** | the 6 module courses | Enrols on activation |
| **License Manager for WooCommerce** | nothing — keys come from the order | Activates exam keys, expiry tracks the next payment date, suppresses key generation on renewals |
| **FluentCRM** | list *Certification 2026*, tag `cert-active` | Drives the weekly module emails |
| **AutomatorWP** | triggers | *Renewal paid* → progress email; *Renewal failed* → hold exam access and alert an admin; *Subscription activated* → issue community access |

**What happens when a student stops paying in month four.** The charge is declined; the grace
period begins and they keep everything for seven days while retries run. AutomatorWP's
*Renewal failed* trigger fires immediately, so exam access is held and an admin is alerted on
day one rather than day eight. If the grace period runs out: LearnDash un-enrols them,
License Manager deactivates the keys, FluentCRM swaps the tag and the win-back sequence
starts, and the `certification-student` role drops — **except** for anything another live
subscription of theirs still grants.

**The conflict to watch.** *Access ends: Keep access for good* and LearnDash un-enrolment are pulling in
opposite directions. Lifetime access is Submex's own downloadable-content rule; LearnDash
enrolment is LearnDash's. If the courses must survive non-payment, do not list them in the
LearnDash field — grant them at enrolment and let Submex's role and downloads carry the
lifetime part.

### A meal-kit service — delivery, roles, CRM and per-status automation

**The business.** Meal kits. Customers pick 2, 3 or 5 meals a week, billed monthly, delivered
Tuesdays. There is a recipe archive for subscribers, a pause button for holidays, and a
kitchen that needs Monday's numbers.

**Product.** Variable subscription. Attribute: Meals (2 / 3 / 5).

| Field | Value |
|---|---|
| Bill every | `1` `Month` (parent) |
| Regular price | 60 / 85 / 130 (per variation) |
| **Delivery schedule** | Enabled, **Deliver every** `1` `Week`, **Deliver on** `Tuesday` |
| **Purchase limit** | *One live subscription per customer* |
| **Grant role while active** | `kitchen-member` — unlocks the recipe archive |

**Three integrations:**

| Integration | Does |
|---|---|
| **MailPoet** | List *This week's menu*. Subscribed on activation, removed on cancel — so the menu never goes to somebody who left. |
| **WP Fusion** | A tag per status with *Remove stale tags* on. Paused members stop getting delivery reminders without being unsubscribed from anything. |
| **AutomatorWP** | *Status changed to On hold* → pull them from the Monday pick list. *Recovered* → put them back. |

**Why three and not one.** MailPoet sends the menu, WP Fusion tells the CRM the truth about
each member's state, and AutomatorWP acts on the kitchen's behalf. Each answers a different
question. If you only want one, take WP Fusion — its per-status tags can drive the other two
from inside your CRM.

**Running it day to day.** **Submex → Deliveries** on Monday prints Tuesday's manifest. Health
shows whose payment failed before their box is packed. A member going on holiday uses
**Pause**, their remaining time is preserved, and the kitchen stops seeing them.

---

## Which integration for which product

The reverse lookup. Find what you sell; connect what is in the second column.

| What the customer gets | Connect | Notes |
|---|---|---|
| A course | **LearnDash** | Commercial — buy it, no install button |
| A course | **Tutor LMS** | Installs from the Integrations screen; enrolment call unverified live |
| A course | **LearnPress** | Installs from the Integrations screen; enrolment call unverified live |
| A licence key | **License Manager for WooCommerce** | The one to pick; suppresses duplicate keys on renewal |
| A licence key | **WP Software License** | State change is an assumption — verify first |
| A mailing list | **MailPoet** | Watch its own confirmation emails |
| A CRM contact, lists and tags | **FluentCRM** | Lists, tags, or both |
| CRM state that mirrors the subscription | **WP Fusion** | A tag per status, all eight; the most flexible |
| Anything with custom follow-up | **AutomatorWP** | 13 triggers; fires once per product |
| Members-only content | **nothing** — Role settings | Store-wide, or **Grant role while active** per product |
| Downloadable files | **nothing** — WooCommerce downloads | Follows the subscription; **Access ends** decides if they outlive it |
| A physical delivery | **nothing** — **Delivery schedule** | Separate cadence from billing, with a manifest |

**Three rules that apply to every one of them:**

1. **Nothing happens until you configure the product.** Installing LearnDash does not enrol
   anybody. Fill in the integration's fields on the subscription product.
2. **Access follows the subscription's status, not the order.** Granted when it goes active,
   released when it stops. A successful renewal changes nothing, because nothing was lost.
3. **A customer's other subscriptions protect what they still pay for.** Two subscriptions
   granting the same course, one cancelled: the course stays.

An integration whose plugin is not active registers no hooks and takes no action. If one
seems not to fire, **Submex → Integrations** is the first place to look — it will say *Plugin
not active*.

---

## Before you take real money

Whatever you are building, in this order:

1. **Every gateway starts in Test/Sandbox.** Buy your own product with a test card and watch
   the subscription appear under **Submex → All subscriptions**.
2. **Force one renewal** before going live. `tools/` has the scripts; see
   [TESTING.md](TESTING.md).
3. **Check Settings → General → Health.** Renewal queue green, no unresolved charges,
   double-charge protection in place. A red renewal queue means renewals are not happening at
   all — on a quiet site, ask your host for a server cron.
4. **Cancel one, yourself, as a customer.** Confirm the access you expected to be removed is
   removed, and the access you expected to survive survives.
5. **If you use PayPal, paste the Webhook ID back.** Without it Submex rejects every webhook —
   PayPal takes money and nothing updates.
6. **If you use an integration, verify the first one by hand.** Especially Tutor LMS,
   LearnPress and WP Software License — see
   [What has not been proven](INTEGRATIONS.md#what-has-not-been-proven).
