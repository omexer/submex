# Integrations

An integration hands a subscription to the plugin that grants what the customer is paying
for — a course, a mailing list, a licence key — and takes it back when they stop paying.

FluentCRM is part of Submex itself; the other twelve are **Submex Pro**. Find them under **Submex → Integrations**: a card for each,
grouped by what it connects to, saying what it does, which plugin it needs, whether that
plugin is active right now, and — for plugins on WordPress.org — an Install button.

For *which* integration a given business needs — and the two builds that need four at once —
see [BUSINESS-EXAMPLES.md](BUSINESS-EXAMPLES.md).

---

## How they all behave

Three rules apply to every integration, and they are worth knowing before the list.

**Nothing happens until you configure the product.** Each integration adds its own fields to
the subscription product — which courses, which lists, which tags. A product with none
configured does nothing, silently and on purpose. It is not a bug that installing LearnDash
did not suddenly enrol anybody.

**Access follows the subscription's status, not the order.** It is granted while the
subscription is Active or Trialling, and taken back on every other status — cancelled,
expired, and also **on hold after a failed payment**. A renewal that succeeds changes nothing,
because nothing was lost.

**On a variable product, configure the parent.** Variations use the parent product's
integration settings (Pro 0.39.0; before that they were silently skipped). A once-only
background job granted the missing access to live variable subscriptions. LearnDash, Tutor
LMS, LearnPress, MailPoet and WP Fusion do not record what Submex added, so when
such a subscription ends they remove everything the product configures — including access you
may have granted by hand.

**A customer's other subscriptions protect what they still pay for.** If two subscriptions
both grant the same course and one is cancelled, the course stays. Access is only released
when no live subscription of theirs still covers it. This is the rule that most integrations
elsewhere get wrong, and it is the one that generates angry support tickets when it is
missing.

**An integration whose plugin is not active does nothing at all.** It registers no hooks and
takes no action. If an integration seems not to fire, the Integrations screen is the first
place to look — it will say *Plugin not active*.

---

## Courses

### LearnDash

**Configure:** the courses on the subscription product.

**Active subscription** → the customer is given access to those courses.
**Subscription ends** → access is removed, unless another live subscription of theirs
includes the same course.

Not on WordPress.org, so the Integrations screen links out to buy it rather than offering an
install button.

### Tutor LMS

**Configure:** the courses on the subscription product.

**Active subscription** → the customer is enrolled.
**Subscription ends** → they are un-enrolled, subject to the same protection above.

### LearnPress

**Configure:** the courses on the subscription product.

**Active subscription** → enrolment is set.
**Subscription ends** → enrolment is removed.

> The LearnPress and Tutor LMS enrolment calls are written against those plugins' documented
> APIs but have never been run against a live install. Check the first enrolment by hand.

---

## Email and CRM

### MailPoet

**Configure:** the MailPoet lists on the subscription product.

**Active subscription** → the customer's email is subscribed to those lists.
**Subscription ends** → they are removed from them.

> MailPoet may send its own confirmation email when somebody is added to a list, depending on
> your MailPoet settings. Check that before a launch, so customers are not double-mailed.

### FluentCRM

**Configure:** lists and tags on the subscription product. Either, or both.

Included in Submex; it does not need Pro.

**Active subscription** → the contact gains those lists and tags.
**Subscription ends** → the ones Submex added are removed, unless another live
subscription still grants them. Tags and lists the contact already had stay. Subscriptions
created before Submex started recording this (when FluentCRM moved into the free
plugin) remove everything their product configures, as before.

### WP Fusion

**Configure:** a tag bucket per subscription status — one for Active, one for On hold, one
for Cancelled, and so on for all eight. Plus a *Remove stale tags* switch.

**Any status change** → the tags for the new status are applied. With *Remove stale tags* on,
the tags belonging to statuses the customer no longer holds are removed — but a tag stays if
another of their subscriptions is in that status.

This is the most flexible of the three, because your CRM automations key off the tags rather
than off Submex. A "win back" sequence triggers on the Cancelled tag; a dunning nudge on the
On hold one.

The Integrations screen installs WP Fusion Lite from WordPress.org.

---

## Community

### BuddyPress and BuddyBoss

**Configure:** **Community groups** (private and hidden ones included) and, optionally, a
**Member type** — **Profile type** on BuddyBoss — on the subscription product.

**Active or trialling** → the customer joins those groups and gets the type, added alongside
any type they already have.
**Any other status** → they leave the groups and lose the type.

Submex records what it added and removes only that. A group or type the customer already had,
joined on their own, or gets from another live subscription is left alone, as are group
admins, moderators and banned members (removing a banned member's row would lift the ban). If
someone else removes them or changes the type, Submex forgets its record.

> Run against BuddyPress 14.5.2. BuddyBoss Platform 3.5.0 was read from source only.

---

## Affiliates

### AffiliateWP

**Configure:** **Submex → Settings → AffiliateWP** — **Commission on renewals** (off by
default), **Renewal rate (%)** (blank uses AffiliateWP's own rate for that affiliate and
product), **Renewals that earn** (0 for every renewal).

**Renewal paid** → the affiliate who earned the first order's referral gets a referral for the
renewal, in AffiliateWP's WooCommerce context, so AffiliateWP's own refund and cancel handling
applies. It earns only when the first referral is unpaid or paid, the affiliate is active and
the product is not excluded.
**Renewal fails or is cancelled** → that renewal's unpaid Submex referral is rejected.
**Refund** → follows AffiliateWP's "reject on refund" setting.

A renewal is never credited twice, including alongside AffiliateWP's Recurring Referrals
add-on (which supports only WooCommerce Subscriptions). The setup guide's test subscription
never earns.

> Tested against a stand-in built from AffiliateWP's documented behaviour, not a licensed
> copy. Run it once against a real AffiliateWP on staging before turning it on.

---

## Messaging

### WhatsApp

Not a plugin: Submex Pro talks to the **Meta WhatsApp Cloud API** directly.

**Configure:** **Submex → Settings → WhatsApp** — **Enable WhatsApp**, **Phone number ID**,
**Access token**, **App secret**, **Webhook verify token**, then for each event an approved
**Template name**, **Template language** and **Body variables**. An event with no template
sends nothing.

**Events:** renewal reminder, renewal paid, payment failed (when it will not be retried),
payment retry scheduled, subscription cancelled, subscription expired, and each win-back
email.

Only customers who opt in — at checkout (classic or block) or in My Account — are messaged,
at the number they agreed to. Each message is sent once; **Recent messages** shows delivery
status from Meta's signed webhook.

> Meta's API was faked in tests; no message has been sent through a real WhatsApp Business
> account.

---

## Automation

### AutomatorWP

**Configure:** nothing on the product. Triggers are available as soon as AutomatorWP is
active.

Thirteen triggers are registered:

| Trigger | Fires when |
|---|---|
| Subscription activated | a subscription becomes active |
| Status changed to … | one trigger per status, all eight |
| Trial ended | a trial finishes |
| Renewal paid | a renewal charge succeeds |
| Renewal failed | a renewal charge is declined |
| Recovered | a failed subscription is brought back |

Each fires **once per product** on the subscription, so an automation can act on the
specific thing that was bought rather than on the subscription as a whole. Triggers can be
filtered by product and by status inside AutomatorWP.

### AutomateWoo

Needs AutomateWoo **6.2.3 or newer**, and Pro's subscription webhooks module (it rides the
same events). Sold commercially, so the screen links out.

**Configure:** nothing on the product. In AutomateWoo, Submex subscriptions get:

| | |
|---|---|
| Triggers | Subscription created, status changed (from/to), renewal payment complete, renewal payment failed, cancellation scheduled, cancelled, expired; daily **Before renewal** and **Before trial end** (days before) |
| Data type | `submex_subscription`, with variables: id, status, next payment date, total, products, view URL, payment method |
| Actions | **Change Status** (schedule or confirm a cancellation, or withdraw one — never reactivate) and **Add Note** |

Every run is a background job about a minute later, held back while a charge is in flight or
a renewal is due, so a payment retry's brief flip to active is never reported. A workflow
never runs twice for the same event.

> Tested against a stand-in built from AutomateWoo 6.9.0's GPL source, not a licensed copy.
> Run it once against a real AutomateWoo on staging before relying on it.

---

## Licence keys

### License Manager for WooCommerce

**Configure:** nothing on the product. The licence keys the original order generated are
found automatically.

**Active subscription** → the keys are activated, and their expiry is set to the
subscription's next payment date.
**Renewal succeeds** → the expiry is pushed forward to the new next payment date.
**Cancelled, expired, or a failed payment** → the keys are deactivated.
**A renewal order** → licence generation is suppressed, so a renewal extends the existing key
rather than minting a new one. That last point is what stops a customer accumulating a fresh
licence key every month.

### WP Software License

**Configure:** nothing. Keys are found from the related orders the same way.

**Active subscription** → licences are set active.
**Subscription ends or payment fails** → they are set inactive.

> The call that changes licence state on this plugin is an **assumption**. The reference
> implementation we compared against never changes state, so there was nothing to copy, and
> the plugin is not installed here to check against. It is guarded: if the method is not
> what we expect, the integration does nothing rather than failing loudly, and fires
> `submex_integration_unsupported`. **Verify this one against a real install before relying
> on it.**

---

## Installing them

Where the plugin is on WordPress.org, **Submex → Integrations** installs and activates it in
place — no round trip through the Plugins screen. Eight of the thirteen work this way: Tutor
LMS, LearnPress, MailPoet, FluentCRM, WP Fusion Lite, AutomatorWP, License Manager for
WooCommerce and BuddyPress.

LearnDash, AutomateWoo, AffiliateWP and WP Software License are sold commercially, so they
show a link to buy instead of a button that could only fail. WhatsApp links to Meta's Cloud
API setup guide.

---

## What has not been proven

Be clear about this before you rely on any of it.

| | |
|---|---|
| **Most host plugins are not installed on the development machine** | Every active path except BuddyPress was proved against a stand-in written to the signature our code calls. If a real signature differs, our code calls the stand-in correctly and the real plugin incorrectly. |
| **AffiliateWP and AutomateWoo stand-ins** | Built from AffiliateWP's documentation and AutomateWoo 6.9.0's source. Both must be run once against licensed copies before a release. |
| BuddyPress | Run against BuddyPress 14.5.2. BuddyBoss read from source only. |
| WhatsApp | Meta's API faked; never sent through a real account. |
| **What *is* proven, thoroughly** | That an integration with its plugin absent registers no hooks, writes no data and takes no action — verified by diffing every hook in WordPress across a registration, then firing every lifecycle event and all 64 status-change pairs. This is the case that happens on most stores. |
| `WOO_SL_functions::update_licence_status` | A guess. See the warning above. |
| LearnPress and Tutor LMS enrolment | Written to the documented API, never run live. |
| MailPoet confirmation emails | May fire on subscribe; unverified. |
