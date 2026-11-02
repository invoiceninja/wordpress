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
```

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
