=== Submex Subscription Billing for WooCommerce ===
Contributors: omexer, amhossain
Tags: woocommerce, subscriptions, recurring payments, billing, memberships
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell subscriptions in WooCommerce: recurring billing, free trials, Stripe and PayPal, and customers who manage their own plan.

== Description ==

Submex turns WooCommerce products into subscriptions and runs the renewal billing for you — scheduling each charge, taking it, retrying it and recording every attempt — with a place in My Account where customers can see and cancel what they pay for.

= Selling subscriptions =

* A **Subscription** product type in the Product data panel, billed every day, week, month or year, or any multiple of them.
* Free trials in days, weeks, months or years, and sign-up fees.
* The price says how often it recurs wherever WooCommerce shows it, and the terms — the trial, the first payment, what follows, cancel anytime — are spelled out on the product page, in the cart and at checkout.
* Works with both the classic checkout and the block checkout.
* A guest can buy a subscription and have an account made at checkout, or you can require a login first.
* A checkout that costs nothing today — a free trial, or a coupon worth the whole first payment — still asks for a payment method whenever a later renewal will charge something, so the first renewal has a card to charge. Plans that renew for nothing are not asked.

= Taking payment =

* **Stripe** collects the card on Stripe's own checkout page, and Submex charges it again whenever a renewal falls due, with a free trial saving the card without charging it.
* **PayPal** runs the schedule itself, and Submex keeps in step through PayPal's webhooks.
* With no gateway at all, renewals become invoices the customer pays by hand.
* Payments that are confirmed days later — Direct Debit and bank payments — wait as pending: the customer keeps access, the renewal order waits on hold so it cannot be paid twice, and Submex never retries or re-charges it. The gateway settles it once the money is confirmed or refused.
* A failed or waiting renewal can be paid from its payment link. Paying it restarts the subscription exactly once, keeps the card it was paid with for later renewals, and is never charged again.
* A safeguard in the database means a renewal can never be charged twice, even when two processes try at the same moment.
* A charge whose result never arrived is checked with the gateway, not simply tried again.
* The next renewal is queued as soon as one is charged. Renewals missed while the site's scheduler was not running are picked up by an hourly check, which by default charges once for the gap rather than once per missed period.
* In stores that enter prices including tax, renewals charge exactly what the checkout charged. VAT-exempt customers stay exempt on every renewal.
* A copy of the site on staging refuses to bill anyone.

= For your customers =

* My Account lists their subscriptions, what they pay and when the next payment is.
* They can cancel at the end of the period they paid for, or straight away. You cannot switch cancelling off. A subscription cancelled at the end of the period ends when that period runs out: access stops and nothing more is charged.
* If you turn on **Ask before cancelling**, they are asked for their main reason first. The reason is optional, and Continue canceling is always one click.
* They can turn off automatic renewal and keep what they paid for until the period ends.
* They can pay a renewal that failed, or one waiting on them, from its payment link.
* If you allow it, they can pay the next period early without moving their renewal date.

= Running your store =

* **Overview** walks you through setting up — connect a payment method, create a product, run a test renewal that charges nobody — then shows recurring revenue (net of tax), what needs your attention and your latest subscriptions.
* **All subscriptions** lets you search, filter, sort and change subscriptions in bulk, and open any one to see its history, charge it now or move its dates.
* **Health** checks, under Settings: whether the renewal queue is running, charges whose outcome is still unknown, renewals waiting more than 10 days for their payment provider to confirm them, whether the double-charge safeguard is in place, and subscriptions whose renewals add tax twice, listed with a Repair action so nothing is ever changed silently.
* Ten emails for the moments that matter — seven to the customer (including a reminder before each renewal) and three to you — each with a preview under Email Notifications on WooCommerce 9.6 and later.
* Customer roles and downloadable files that follow the subscription.
* **FluentCRM:** choose tags and lists on each subscription product. The contact has them while the subscription is active or in a free trial and loses them when it stops, and only tags and lists Submex added are ever removed.
* Integrations and Help screens, with a system report that never contains your keys.
* A REST API for subscriptions, open to logged-in store managers.

= Submex Pro =

A separate, optional plugin extends Submex with additional payment gateways, advanced plans and pricing, customer retention tools, more integrations and reporting. Submex itself is complete and fully functional on its own.

= Source code =

The JavaScript for Submex's admin screens is bundled. Its unbundled source is in the plugin's `src/` directory, alongside the build configuration (`package.json`, `webpack.config.js`, `tailwind.config.js`).

To rebuild it, run these commands in the plugin folder:

`npm install`

`npm run build`

The build uses @wordpress/scripts with Tailwind CSS and PostCSS, and needs Node.js.

== Installation ==

1. Install and activate WooCommerce 8.0 or newer.
2. Upload the `submex` folder to `/wp-content/plugins/`, or upload the ZIP from **Plugins → Add New → Upload Plugin**.
3. Activate Submex.
4. Open **Submex → Overview** and follow the setup checklist: connect a payment method, create a subscription product, and run a test renewal.

To turn a product into a subscription yourself, edit it and choose **Subscription** in the **Product data** dropdown.

== Frequently Asked Questions ==

= Which payment methods can renew automatically? =

Stripe and PayPal, both included. Without a gateway, each renewal is an invoice the customer pays by hand. A separate Pro plugin adds further gateways.

= What happens to a Direct Debit renewal while it clears? =

The renewal is pending: the customer keeps access, the renewal order waits on hold, and nothing is charged again or retried. When the payment provider confirms the payment the renewal is marked paid and the next one is scheduled; if the payment fails, it follows the normal failed-payment path, once.

= Can a customer be charged twice for the same renewal? =

No. Each billing period can hold exactly one charge, enforced by a unique index in the database, so a second attempt is refused before it reaches the payment gateway — even if two processes try at the same moment.

= Can customers cancel on their own? =

Yes, from My Account, and that cannot be turned off. A customer who cannot cancel is a chargeback waiting to happen.

= Does it work with High-Performance Order Storage? =

Yes. Subscriptions are a native WooCommerce order type, stored in the HPOS tables.

== External services ==

Submex talks to a payment provider only when you have switched that provider on and entered its credentials. Nothing is sent anywhere by default, and Submex sends nothing to its own author or to any analytics service.

= PayPal =

Used to create and run the billing plan, when PayPal is enabled under **Submex → Settings → PayPal**.

Requests go to `https://api-m.paypal.com`, or to `https://api-m.sandbox.paypal.com` when the environment is set to Sandbox. They are made when a subscription product is bought, and whenever PayPal notifies the site of a payment so Submex can verify that the notification is genuine. What is sent: the product name, the price, currency and billing cycle of the plan including any free trial or sign-up fee, the order number, the customer's first name, last name and billing email, this site's name and the addresses PayPal returns the customer to, and — for verification — the notification PayPal sent back together with the signature headers that came with it.

PayPal's terms: https://www.paypal.com/legalhub/useragreement-full — PayPal's privacy statement: https://www.paypal.com/legalhub/privacy-full

= Stripe =

Used to take the card payment and charge each renewal, when Stripe is enabled under **Submex → Settings → Stripe**.

Requests go to `https://api.stripe.com`, with the test or live secret key depending on the environment chosen. They are made when a customer pays by card at checkout, when they come back from Stripe's payment page so Submex can confirm the payment, whenever a renewal is charged, and when Submex checks whether a renewal whose result never arrived was charged. What is sent: the customer's billing email, and their name if a card has to be linked to a Stripe customer; the order number and amount, the currency, and the addresses Stripe returns the customer to; and for a renewal, the amount, the currency, the subscription and order numbers and the Stripe customer and card saved at checkout. The card itself is entered on Stripe's page and never reaches this site.

Stripe's terms: https://stripe.com/legal/ssa — Stripe's privacy policy: https://stripe.com/privacy

= WordPress.org plugin directory =

Used only when an administrator clicks **Install** on the Integrations screen to add the FluentCRM plugin (`fluent-crm`). Nothing is requested or installed automatically.

When the button is clicked, the site asks the WordPress.org plugin API (`https://api.wordpress.org`) for that plugin's details and download, exactly as **Plugins → Add New** does. The only data sent is the plugin slug, plus the WordPress version and site address that WordPress includes with every plugin request.

WordPress.org privacy policy: https://wordpress.org/about/privacy/

== Screenshots ==

1. The subscriptions list: who is subscribed, what they pay, and how far off the next payment is.
2. One subscription in full — status, next payment, schedule, and the activity log grouped by day.
3. The Subscription panel on a product: billing cycle, free trial, sign-up fee and shipping.
4. The subscription terms as a customer sees them on the product page.

== Changelog ==

= 1.2.1 =
* Fixed: PayPal charged only the plan's price while the whole order was marked paid. A second unit, a one-off item, tax and shipping now reach PayPal, and an order that costs less than one renewal is not sent to PayPal.
* Fixed: a store set to show prices with no decimals sent Stripe a hundredth of the price, and a store in yen showing two decimals charged a hundred times it at checkout. Stripe is now sent each currency's own units.
* Fixed: PayPal's first payment was also recorded as a renewal, with a receipt minutes after signup. A cancellation or failed payment at PayPal now emails the customer and the store.
* Fixed: a renewal paid late was charged again at once; a payment made on a renewal's pay page could be charged a second time if the customer closed the tab before coming back; and a renewal that timed out could be marked paid with another period's payment.
* Fixed: a free trial gave access before its checkout was paid. Subscriptions now start when their order is paid, once, and the store's "New subscription" email waits for that too.
* Refunds from the order screen now reach Stripe. Turning Stripe off stops new sign-ups only; saved cards keep renewing. A renewal waiting for the customer to confirm counts as a failed payment after 7 days, and Submex warns when Stripe refuses its key.
* Paying early that is declined no longer puts a paid-up subscription on hold, and Reactivate explains when a renewal is waiting for the customer instead of billing nothing.
* A guest whose first payment failed can try again. Variable subscriptions can be given variations. Store emails go to WooCommerce's new-order recipient by default, with a recipient setting of their own.
* Deleting Submex or an older Submex Pro with "delete data" on no longer removes the other's settings or the Stripe keys.

= 1.2.0 =
* **Stripe is included.** Customers pay by card on Stripe's own checkout page, and Submex charges the saved card again whenever a renewal falls due. A free trial saves the card without charging it. Set it up under Submex → Settings → Stripe.
* A store that already used Stripe through Submex Pro keeps its keys, its settings and its subscriptions' saved cards, with nothing to set up again. Until Submex Pro is updated to 0.54.0, Pro's own Stripe keeps running.
* Submex now says when Stripe is switched on but has no secret key for the chosen mode.
* Deleting Submex with "delete data" on also removes the PayPal and Stripe checkout settings WooCommerce kept for it.

= 1.1.3 =
* Fixed: opening a saved subscription product hid its General tab, so the price, the billing fields and WooCommerce's Virtual box were missing and the product could not be made shipping-free. They now show on every subscription product.
* Submex's settings live only under Submex → Settings. The duplicate Subscriptions tab under WooCommerce → Settings is gone, and old links to it open Submex → Settings.
* Manage on PayPal under WooCommerce → Payments opens Submex's PayPal settings, with the credentials, instead of a title-only form.

= 1.1.2 =
* Fixed: PayPal turned on under Submex's settings was not offered at checkout until it was also turned on under WooCommerce → Settings → Payments. Turning either one on now turns on the other; turning PayPal off under Payments alone still keeps existing subscribers renewing.

= 1.1.1 =
* The cancel form in My Account names the right end date when an extension moves it.

= 1.1.0 =
* Ask before cancelling: an optional reasons screen before a customer cancels, off by default. Continue canceling is always one click.
* Edit, reorder, switch off and delete the cancellation reasons, and set the reasons screen's text, with a live preview.
* Confirmation messages: your own message after a customer cancels, or the plain notice.
* The Home menu item is now Overview.
* Fixed: on sites without pretty permalinks, subscription links in My Account landed on the account dashboard.
* Fixed: resubmitting the cancel form sent the cancellation emails again.
* Fixed: the Submex logo in the admin header was cut off.

= 1.0.1 =
* Integrations: installing an integration plugin no longer reloads core admin files WordPress has already loaded.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.1 =
Fixes PayPal and Stripe charging the wrong amount in some stores, and trials giving access before payment. Recommended for every store. If you use Submex Pro, update it as well.

= 1.2.0 =
Stripe card payments and automatic renewals are now part of the free plugin. If you use Submex Pro, update it as well.

= 1.1.3 =
Subscription products show their price and Virtual box again when edited, so a digital subscription no longer needs shipping. Settings are now only under Submex → Settings.

= 1.0.0 =
Initial release.
