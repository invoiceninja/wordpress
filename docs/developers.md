# Invoice Ninja for WordPress 2.0: developer guide

## Hooks

The hook API follows semantic versioning: names and payload shapes don't change within 2.x. A deprecated hook keeps working through `apply_filters_deprecated` / `do_action_deprecated` for at least one minor release. Where an action carries an Invoice Ninja entity, the raw entity is the last argument.

### Actions

| Action | Arguments | When |
|---|---|---|
| `invoiceninja_connected` | `array $company` | The site connected, or reconnected after a token change |
| `invoiceninja_disconnected` | | The connection was removed |
| `invoiceninja_company_switched` | `string $old_id, array $company` | The token now belongs to another company |
| `invoiceninja_products_synced` | `array $result` | A product sync finished |
| `invoiceninja_order_placed` | `array $order` | An order's invoice was created; the payment link is ready |
| `invoiceninja_order_needs_attention` | `array $order` | An order can't be placed without an admin, or the buyer has no payment link |
| `invoiceninja_code_verified` | `string $email, string $purpose` | An email code was typed correctly (`checkout` or `portal`) |
| `invoiceninja_email_verified` | `int $user_id, string $how` | A user's email became verified |
| `invoiceninja_job_failed` | `string $hook, array $payload, Throwable $error` | A background task gave up |
| `invoiceninja_upgraded` | `string $from, string $to` | The plugin finished upgrading |
| `invoiceninja_daily_tasks` | | Daily background work (runs from Action Scheduler) |
| `invoiceninja_rest_routes` | `Routes $routes` | Add REST routes with `$routes->add( $path, $method, $callback, $access )` |
| `invoiceninja_create_tables` | | Create or update tables (dbDelta is loaded) |
| `invoiceninja_admin_assets` | `string $screen` | Enqueue admin scripts that add Settings tabs or screens |

### Filters

| Filter | Value | Use |
|---|---|---|
| `invoiceninja_client_ip` | `string $ip` | The shopper's IP for rate limits. Return the forwarded address behind a trusted proxy |
| `invoiceninja_track_inventory` | `bool $track` | Whether stock limits apply |
| `invoiceninja_is_verified` | `bool $verified, int $user_id` | Whether a user's email is verified (used by every module) |
| `invoiceninja_user_email_verified` | `bool $verified, int $user_id` | Mark users verified by your own means, e.g. a membership plugin |
| `invoiceninja_account_subscriptions` | `array $rows, string $client_id, int $user_id` | Rows for the Account's Subscriptions tab |
| `invoiceninja_setting_defaults` | `array $defaults` | Add a settings group; saved values are cast to the default's type |
| `invoiceninja_diagnostics_checks` | `array $checks` | Add checks to Status and Site Health |
| `invoiceninja_admin_screens` | `array $screens` | Add admin pages that load the admin app |
| `invoiceninja_pre_resolve_host` | `array\|null $ips, string $host` | Override DNS for the private-network check (tests) |
| `invoiceninja_repair` | `array $done` | Add steps to Status → Fix problems |

### Module hooks

| Hook | Type | Arguments | Notes |
|---|---|---|---|
| `invoiceninja_webhook_<entity>_<event>` | action | `array $entity, int $event_id` | After the entity was fetched again from Invoice Ninja, e.g. `invoiceninja_webhook_payment_created`, `invoiceninja_webhook_quote_approved`, `invoiceninja_webhook_invoice_late` |
| `invoiceninja_webhook` | action | `string $entity, string $event, array $data` | Every webhook, after the fetch |
| `invoiceninja_polling` | action | | Webhooks aren't available (e.g. a site Invoice Ninja can't reach), so the plugin polls instead |
| `invoiceninja_subscription_purchased`, `_trial`, `_plan_changed`, `_paid`, `_cancelled`, `_suspended`, `_resumed` | action | `array $payload, array $raw` | Subscription lifecycle; the payload matches the relay's (see `docs/hosting.md`) |
| `invoiceninja_subscriptions_synced` | action | `array $subscriptions` | Subscriptions refreshed from Invoice Ninja |
| `invoiceninja_subscription_eligible` | filter | `bool\|string $eligible, string $subscription_id, array $buyer` | Answer Invoice Ninja's `is_eligible` callback; return true or a refusal message (fails open) |
| `invoiceninja_relay_allow_private` | filter | `bool $allow, string $url` | Let the provisioning relay post to a private address |
| `invoiceninja_lead_received` / `invoiceninja_lead_quoted` | action | `array $lead` / `array $lead, array $quote` | A quote request was saved, or became a quote |
| `invoiceninja_captcha` | filter | `bool $ok, array $input, string $form` | Add a CAPTCHA to the Quote Request Form |
| `invoiceninja_quote_request_spam` | filter | `bool $spam, array $fields` | Your own spam check |
| `invoiceninja_quote_form_min_seconds` | filter | `int $seconds` | Minimum time to fill the form |
| `invoiceninja_quote_tags` / `invoiceninja_quote_request_body` | filter | `array $names, array $lead` / `array $body, array $lead` | Change the quote's tags or body |
| `invoiceninja_woo_invoice_created` | action | `int $order_id, array $invoice` | A WooCommerce order's invoice was created |
| `invoiceninja_woo_invoice_body` | filter | `array $body, WC_Order $order` | Change the invoice sent for a WooCommerce order |
| `invoiceninja_woo_pay_on_delivery_methods` | filter | `list<string> $methods` | Payment methods only paid on completion (default: cod) |
| `invoiceninja_woo_account_endpoint` | filter | `string $slug` | The My Account "Invoices & billing" endpoint |

## REST API

Everything is under `/wp-json/invoiceninja/v1`.
- **Admin routes** need the `view_invoiceninja` or `manage_invoiceninja` capability (connection routes need `manage_options`) and the usual `X-WP-Nonce`.
- **Public store routes** are an allowlist: `/cart`, `/cart/items`, `/checkout/start`, `/checkout/confirm`, `/orders/{number}`, `/orders/find`, `/orders/last`, `/orders/last/change`, `/pay-invoice`, and the module callbacks.
  - Writes must come from your own site's pages, checked by Origin or Referer, instead of a nonce, so pages stay cacheable.
  - They're rate-limited per IP; IPv6 is limited per /64.

## WP-CLI

```
wp invoiceninja status
wp invoiceninja doctor [--repair]
wp invoiceninja connect <url> < token.txt
wp invoiceninja disconnect
wp invoiceninja environment confirm|pause
wp invoiceninja verify-users --role=<role> [--yes]
wp invoiceninja webhooks status|register|delete
wp invoiceninja woo backfill --from=<date> --to=<date> [--dry-run] [--status]
```

Subscriptions provisioning (signed relay, payload schema, sample receivers): see `hosting.md`.

## Theming

Blocks use your theme's typography, colours and `wp-element-button`. Layout values are CSS custom properties you can override:

```css
.invoiceninja-products, .invoiceninja-checkout, .invoiceninja-status {
	--invoiceninja-gap: 2rem;
	--invoiceninja-radius: 0;
	--invoiceninja-warning: #a15c00;
}
```

Or in `theme.json`:

```json
{
	"styles": {
		"blocks": {
			"invoiceninja/products": { "spacing": { "blockGap": "2rem" } },
			"invoiceninja/checkout": { "typography": { "fontSize": "1.1rem" } }
		}
	}
}
```

## Adding a module

A module is a class implementing `InvoiceNinja\Module`, listed in `Plugin::MODULES`. It registers everything through the hooks above, plus `$plugin->store->add()` for blocks and shortcodes. See `src/Portal/PortalModule.php` for a complete example.
