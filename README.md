# Invoice Ninja

Tags: invoice, billing, payments, quotes, subscriptions
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell your Invoice Ninja products, give customers an account with their invoices, take quote requests and subscriptions, or invoice WooCommerce orders.

## Description

Invoice Ninja for WordPress connects your site to [Invoice Ninja](https://invoiceninja.com), the open-source invoicing app, either hosted at [invoicing.co](https://invoicing.co) (Pro or Enterprise plan) or self-hosted (any plan, free).

Your products, prices and stock come from Invoice Ninja. Every order becomes an invoice there, and customers pay on Invoice Ninja's secure payment page, through whichever gateway you already use.

### Features

* **Store.** Products, Product, Add to Cart, Mini Cart, Checkout and Order Status blocks, each with a matching shortcode. They use your theme's styles.
* **Guest checkout.** Shoppers confirm their email with a 6-digit code, with no account needed, and can tick "Remember this device".
* **Reliable orders.** Orders never create duplicate invoices, even when Invoice Ninja is slow or down. An order placed during an outage is saved and sent once Invoice Ninja is back.
* **Customer account.** Invoices, quotes, payments, credits and subscriptions on your own site, with Pay and PDF links. The billing portal only opens for customers who have confirmed their email.
* **Quote Request Form.** Each request becomes a draft quote, with optional tags. Requests are queued while Invoice Ninja is down, and spam protection is built in.
* **Subscriptions.** Pricing Table and Subscribe Button blocks for your Invoice Ninja subscriptions. Lifecycle hooks and a signed provisioning relay let you automate hosting or memberships.
* **WooCommerce invoicing.** WooCommerce stays the store and Invoice Ninja becomes the ledger. Every order becomes an invoice and payment, totals are reconciled, refunds are mirrored, and older orders can be backfilled.
* **Built for real sites.** Pages stay cacheable, nothing loads on pages without our blocks, and the plugin works with HPOS and multisite. Status checks also show in Site Health, and WP-CLI commands are included.
* **Private by design.** Tokens are encrypted at rest, a staging copy pauses orders, and portal links are never printed into pages. Exporters and erasers for personal data are included.

### Upgrading from 1.x

Your shop looks and works the same after the update: the 1.x display is kept until you choose **Switch to blocks** on the Welcome screen. Nothing from 1.x is deleted. Carts in progress at the moment of the update are emptied.

## External services

This plugin connects to the **Invoice Ninja API** of the Invoice Ninja server you choose: your own self-hosted server, or the hosted service at invoicing.co.

* **When:**
  * when you connect and run the checks;
  * hourly, to sync products;
  * when a shopper places an order, asks for a quote, opens their account, or uses a payment or PDF link;
  * when Invoice Ninja sends webhooks to your site.
* **What is sent:**
  * your API token (an encrypted copy is kept on your site);
  * order details: the buyer's name, email, phone and the items;
  * quote request details;
  * WooCommerce orders, when that module is on;
  * WordPress users, when you choose to export them.
* **Hosted service terms:** [Terms of Service](https://invoiceninja.com/terms/) and [Privacy Policy](https://invoiceninja.com/privacy-policy/).

Emails (codes, order and payment links) are sent by your own WordPress site.

## Installation

1. Install and activate the plugin.
2. Open **Invoice Ninja** in the admin menu and follow **Setup**: your Invoice Ninja address, an admin's API token, and the features you want.
3. Add the blocks to your pages, or let **Settings → Store → Create missing pages** make the Shop, Checkout and Order Status pages.

## Frequently Asked Questions

### Do I need a paid Invoice Ninja plan?

Hosted Invoice Ninja (invoicing.co) needs a Pro or Enterprise plan to connect. Self-hosted Invoice Ninja works on any plan.

### Do shoppers need an account?

No. Guests confirm their email with a 6-digit code. You can require a login in **Settings → Store**.

### Why do customers confirm their email?

So invoices, payment links and the billing portal only reach the person who owns the address.

### Where do customers pay?

On Invoice Ninja's payment page, with the gateways you set up there. Orders arrive even before a gateway is set up, but customers can't pay online until there is one.

### Does it work with page caching?

Yes. Cart, checkout and account content is loaded after the page renders, and the cart cookie doesn't start with `wp-`, so caches keep serving pages.

### Can I keep the 1.x look?

Yes. **Settings → Store → Display → Legacy 1.x** keeps the 1.x products page and cart exactly as they were.

### Is there a staging mode?

A copy of your site at a new address pauses orders until you allow them, so staging never creates real invoices.

## Blocks and shortcodes

| Block | Shortcode |
|---|---|
| Products | `[invoiceninja_products columns="3" per_page="12"]` |
| Product (also Product Price and Product Stock) | `[invoiceninja_product product_id="" show="all"]` |
| Add to Cart Button | `[invoiceninja_add_to_cart product_id=""]` |
| Mini Cart | `[invoiceninja_mini_cart]` |
| Checkout | `[invoiceninja_checkout_form]` |
| Order Status | `[invoiceninja_order_status]` |
| Account (also Portal Link) | `[invoiceninja_account]`, `[invoiceninja_client_portal_url]` |
| Pay Invoice | `[invoiceninja_pay_invoice]` |
| Pricing Table | `[invoiceninja_pricing_table]` |
| Subscribe Button | `[invoiceninja_subscribe]` |
| Quote Request Form | `[invoiceninja_quote_form]` |

The 1.x shortcodes still work. `[invoiceninja_client_portal]` now opens the portal only for logged-in customers who have confirmed their email, and it accepts `class` and `style="link|button"`.

Developers: hooks, REST routes, WP-CLI and theming are documented in the [developer docs](https://github.com/invoiceninja/wordpress/tree/main/docs).

## Upgrade Notice

### 2.0.0
A rebuilt plugin. It requires PHP 8.1 and WordPress 6.7. Your shop keeps its 1.x display until you switch to blocks, and carts in progress are emptied. Shoppers now confirm their email with a code at checkout.

## Changelog

### 2.0.0
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

### 1.0.16
* Fix: the product import no longer deletes products when Invoice Ninja can't be reached.

## Credits
* [Hillel Coren](https://hillel.dev)
* [Oliver Flueckiger](https://www.oliver-flueckiger.ch)
