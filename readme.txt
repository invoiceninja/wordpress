=== Invoice Ninja ===
Contributors: hillel369
Tags: invoice, billing, payments, quotes, subscriptions
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell your Invoice Ninja products, give customers an account for their invoices, take quote requests and subscriptions, or invoice WooCommerce orders.

== Description ==

Invoice Ninja for WordPress connects your site to [Invoice Ninja](https://invoiceninja.com), the open-source invoicing app, either hosted at invoicing.co (Pro or Enterprise plan) or self-hosted (any plan, free).

Your products, prices and stock come from Invoice Ninja. Every order becomes an invoice there, and customers pay on Invoice Ninja's secure payment page with the gateway you already use.

= Features =

* **Store:** Products, Product, Add to Cart, Mini Cart, Checkout and Order Status blocks, each with a matching shortcode. They follow your theme's styles.
* **Guest checkout:** shoppers confirm their email with a 6-digit code (no account needed), with "Remember this device".
* **Reliable orders:** never a duplicate invoice, even when Invoice Ninja is slow; orders placed while it's down are saved and sent once it's back.
* **Customer account:** invoices, quotes, payments, credits and subscriptions on your own site, with Pay and PDF links. The billing portal only opens for customers who confirmed their email.
* **Quote Request Form:** requests become draft quotes with tags. Requests are queued while Invoice Ninja is down, and spam protection is built in.
* **Subscriptions:** Pricing Table and Subscribe Button blocks, lifecycle hooks, and a signed provisioning relay for hosting or memberships.
* **WooCommerce invoicing:** WooCommerce stays the store and Invoice Ninja becomes the ledger. Orders become invoices and payments, refunds are mirrored, and older orders can be backfilled.
* **Built for real sites:** cache-friendly, with nothing loaded on pages without our blocks. HPOS and multisite ready, with status checks in Site Health and WP-CLI commands.
* **Private by design:** encrypted tokens, a staging pause, portal links never printed into pages, and personal-data exporters and erasers.

The full user guide, with screenshots, is at [github.com/invoiceninja/wordpress](https://github.com/invoiceninja/wordpress#readme).

= Upgrading from 1.x =

Your shop looks and works the same after the update: the 1.x display is kept until you choose Switch to blocks on the Welcome screen. Nothing from 1.x is deleted. Carts in progress at the moment of the update are emptied.

== External services ==

This plugin connects to the Invoice Ninja API of the Invoice Ninja server you choose: your own self-hosted server, or the hosted service at invoicing.co.

* When:
    * when you connect and run the checks;
    * hourly, to sync products;
    * when a shopper places an order, asks for a quote, opens their account, or uses a payment or PDF link;
    * when Invoice Ninja sends webhooks to your site.
* What is sent:
    * your API token (an encrypted copy is kept on your site);
    * order details: the buyer's name, email, phone and the items;
    * quote request details;
    * WooCommerce orders, when that module is on;
    * WordPress users, when you choose to export them.
* Hosted service: [Terms of Service](https://invoiceninja.com/terms/) and [Privacy Policy](https://invoiceninja.com/privacy-policy/).

Emails (codes, order and payment links) are sent by your own WordPress site.

== Installation ==

1. Install and activate the plugin.
2. Open Invoice Ninja in the admin menu and follow Setup: your Invoice Ninja address, an admin's API token, and the features you want.
3. In Settings → Store, click Create missing pages to make the Shop, Checkout and Order Status pages, or add the blocks to your own pages.

== Frequently Asked Questions ==

= Do I need a paid Invoice Ninja plan? =

Hosted Invoice Ninja (invoicing.co) needs a Pro or Enterprise plan to connect. Self-hosted Invoice Ninja works on any plan.

= Do shoppers need an account? =

No. Guests confirm their email with a 6-digit code. You can require a login in Settings → Store.

= Where do customers pay? =

On Invoice Ninja's payment page, with the gateways you set up there. The plugin never sees card details.

= Does it work with page caching? =

Yes. The cart, checkout and account load after the page renders, and the cart cookie doesn't start with "wp-", so caches keep serving pages.

= Can I keep the 1.x look? =

Yes. Settings → Store → Display → Legacy 1.x keeps the 1.x products page and cart exactly as they were.

= Is there a staging mode? =

A copy of your site at a new address pauses orders until you allow them, so staging never creates real invoices.

== Screenshots ==

1. A shop built with the Invoice Ninja blocks, in the theme's own style.
2. The checkout: cart, details, a 6-digit email code, then pay on Invoice Ninja's secure page.
3. Order Status, opened from the link in the order email.
4. The customer Account: invoices, quotes, payments, credits and subscriptions.
5. A Pricing Table for Invoice Ninja subscriptions.
6. The Quote Request Form.
7. Home: orders this month, latest orders and features.
8. The Orders screen with statuses and actions.
9. Status checks with plain-language fixes.
10. The setup wizard tests the connection step by step.

== Changelog ==

= 2.0.2 =
* Fix: when Invoice Ninja calculates the tax, the order total and emails match the invoice, and the cart says tax is added on the payment page. US buyers enter a ZIP code so they're charged their own state's sales tax.
* Fix: orders whose invoice was cancelled or deleted in Invoice Ninja are closed, and cancelling such an order no longer fails.
* Fix: "Change order" gives tracked stock back in Invoice Ninja.
* Fix: product sync no longer pauses because of stock changes; admins get a notice and an email when it does pause. Large catalogs sync in steps in the background.
* Fix: staging copies stay paused after an update; more staging addresses are recognised.
* Fix: checkout, Mini Cart, Order Status and Account show "couldn't reach the site" with Try again instead of getting stuck, and a cached Checkout page no longer keeps saying ordering is unavailable.
* Fix: with login required, checkout shows and uses the account's email.
* Fix: adding more than is in stock says "Only N available"; Buy Now on a sold-out product no longer opens an empty checkout.
* Fix: product names with "&" display correctly; the order banner no longer lands in the site header.
* Fix: the Quote Request Form hides after sending, shows field errors first, and autofill can no longer drop a request as spam.
* Fix: the Account page works when the browser sends no referrer; tabs and Resend behave when clicked quickly.
* Fix (1.x display): on classic themes Buy Now and Checkout reach the payment page, refreshing after Add to cart doesn't add it again, the company details (like inventory tracking) refresh daily, and hand-placed 1.x buttons work in the blocks display.
* Fix: a non-admin token is reported as "Not an admin", and a private address for subscriptions gets a clear message.
* Fix: order numbers and limits work with read-replica databases; multisite sites keep their own customer links; network deactivation keeps queued jobs; no "connection problem" email for a single failed check; fewer queries on every page; no PHP error on links with unexpected parameters.
* Accessibility: keyboard focus stays on Add to cart and the cart's +, − and Remove buttons; the Mini Cart has a correct label.

= 2.0.1 =
* Fix: sites updated from 1.x with the address left blank connect to hosted Invoice Ninja and keep syncing products.
* Fix: sites updated from 1.x connect themselves in the background, without running setup.
* Fix: a self-hosted Invoice Ninja on your own network keeps working after the update. Addresses with underscores are accepted.
* Fix: a failed update no longer stops the site. It's logged, shown to admins and tried again.
* Fix: live shops whose domain contains a word like "test" or "dev" are no longer paused as staging copies. Paused sites show a notice.
* Fix: going back to 1.0.16 and updating again no longer duplicates products.
* Fix: customers confirm their email on the site before buying, even without an Account page.
* Fix: the first page load after the update keeps the 1.x shop display.
* Fix: when Invoice Ninja calculates tax, checkout asks only for the country (the postcode is optional), so buyers from countries without postcodes can order. The fields appear even on cached pages.
* Fix: orders held up on the shop's side no longer count toward a buyer's unpaid-orders limit, and the limit is now 5.
* Fix: browser autofill and password managers no longer cause checkout or Pay Invoice requests to be dropped as spam.
* Fix: a CAPTCHA set up for the Quote Request Form no longer blocks checkout or Pay Invoice (they have their own filters now).
* Fix: an add-on registering a REST route that isn't allowed no longer breaks the REST API and the block editor.

= 2.0.0 =
* Rebuilt from the ground up, with automatic migration from 1.x.
* New:
    * store blocks with guest checkout and email codes, Order Status and Find my order;
    * the customer Account and Pay Invoice blocks;
    * the Quote Request Form;
    * subscriptions with a provisioning relay;
    * webhooks;
    * WooCommerce invoicing.
* Product sync updates products in place and never deletes them because of an outage.
* Status checks, Site Health, an activity log, WP-CLI, encrypted tokens and a staging pause.

= 1.0.16 =
* Fix: the product import no longer deletes products when Invoice Ninja can't be reached.

== Upgrade Notice ==

= 2.0.2 =
Fixes for checkout totals when Invoice Ninja calculates tax, product sync, staging copies and the 1.x display. Recommended for all 2.0 sites.

= 2.0.1 =
Fixes for sites updating from 1.x. Existing customers confirm their email once, with a code, before their next purchase.

= 2.0.0 =
A rebuilt plugin. It requires PHP 8.1 and WordPress 6.7. Your shop keeps its 1.x display until you switch to blocks, and carts in progress are emptied. Shoppers now confirm their email with a code at checkout.
