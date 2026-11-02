# Selling hosting (or any service) with Invoice Ninja subscriptions

This recipe turns WordPress + Invoice Ninja into a WHMCS-style billing front end: customers pick a plan
on your site, pay through Invoice Ninja, and your provisioning system (a control panel, a licence server,
a script on your server) is told when to create, change, suspend, resume or remove the service.

It covers GitHub issue #16.

## How it fits together

1. **Plans live in Invoice Ninja.** Create one subscription per plan (Settings → Subscriptions in Invoice
   Ninja), with its recurring product, price, frequency, optional trial, and whether customers may change
   plans or cancel.
2. **WordPress shows and sells them.** In WordPress, turn on **Subscriptions** under Features, then add the
   **Pricing Table** block (or `[invoiceninja_pricing_table ids="…"]`) or a **Subscribe Button**
   (`[invoiceninja_subscribe id="…"]`). Pair a monthly plan with its yearly version to get a toggle.
3. **Purchases report back to WordPress.** In *Invoice Ninja → Settings → Subscriptions* on the WordPress
   side, click **Send purchases to this site** for each plan. The plugin sets the subscription's webhook
   URL, method, secret header and return page in Invoice Ninja, and remembers what was there before (it
   puts it back when you turn this off, disconnect, deactivate or uninstall the plugin).
4. **WordPress tells your provisioning system.** Enter a **Provisioning URL** for the plan. Every lifecycle
   event is POSTed there as signed JSON, retried for about eight hours if your system is down, and logged
   under *Status → Activity*.
5. **Customers manage their plan** on the Account page's Subscriptions tab (plan, status, next bill, and
   links to change plan, update the payment method, or cancel).

```
Customer ──▶ WordPress Pricing Table ──▶ Invoice Ninja purchase page ──▶ pays
                                                │
                     purchase callback ◀────────┘ (secret header)
                            │
                  WordPress Lifecycle ── re-fetches client / recurring invoice / invoice by id
                            │
         do_action('invoiceninja_subscription_<event>')   +   signed POST ──▶ your provisioning system
```

## Lifecycle events

| Event | When |
|---|---|
| `purchased` | A subscription was bought (`single_purchase` or `recurring_purchase` in Invoice Ninja). Create the service. |
| `trial` | A free trial started. Create the service; the first invoice comes when the trial ends. |
| `plan_changed` | The customer moved to another plan. The payload's `subscription` is the **new** plan. Resize the service. |
| `paid` | A renewal invoice was paid. Extend the service's paid-until date. |
| `cancelled` | The customer cancelled. Remove (or schedule removal of) the service. |
| `suspended` | A subscription invoice has been unpaid for *N* days (Settings → Subscriptions → "Suspend after", default 7). Suspend the service. |
| `resumed` | The overdue invoice of a suspended subscription was paid. Unsuspend the service. |

Invoice Ninja has no "suspended" state of its own. The plugin decides it from: Invoice Ninja's nightly
`plan_expired` callback, the late-invoice webhook (event 22) as an early hint, and its own daily check of
overdue invoices. Each event fires at most once (repeats are recognised by the event `id`).

WordPress developers can listen to the same events without a relay:

```php
add_action( 'invoiceninja_subscription_suspended', function ( array $payload, array $raw ) {
    // $payload is the version 1 payload below; $raw has the client, recurring_invoice and
    // invoice exactly as Invoice Ninja returned them.
}, 10, 2 );
```

To refuse a purchase (for example, one plan per customer), answer the eligibility check, which runs inside
Invoice Ninja's purchase page:

```php
add_filter( 'invoiceninja_subscription_eligible', function ( $eligible, string $subscription_id, array $buyer ) {
    // $buyer: email, client_id, contact_id, account_key. Return true, false, or a sentence to show.
    return $eligible;
}, 10, 3 );
```

If the filter throws or the plugin can't answer within a second, the purchase goes ahead.

## Payload (version 1)

Every delivery is a JSON object. Fields are only ever added within version 1; a change that removes or
renames a field becomes `version: 2`.

```json
{
  "version": 1,
  "id": "evt_6b1f0c0e4d2a4f4e9a2c7d3b8e5f1a20",
  "event": "purchased",
  "context": "recurring_purchase",
  "occurred_at": "2026-11-02T10:15:00Z",
  "sent_at": "2026-11-02T10:15:02Z",
  "subscription": { "id": "Wpmbk5ezJn", "name": "VPS Small", "price": 10.0, "frequency_id": 5 },
  "client": {
    "id": "7LDdwRb1YK",
    "number": "0042",
    "name": "Acme Ltd",
    "email": "ops@acme.example",
    "account_key": "",
    "custom_value1": "", "custom_value2": "", "custom_value3": "", "custom_value4": "",
    "contacts": [
      { "id": "VolejRejNm", "first_name": "Ann", "last_name": "Ops", "email": "ops@acme.example", "phone": "", "is_primary": true }
    ]
  },
  "recurring_invoice": {
    "id": "Opnel5aKBz",
    "number": "R0007",
    "status_id": 2,
    "frequency_id": 5,
    "next_send_date": "2026-12-02",
    "remaining_cycles": -1,
    "amount": 10.0,
    "line_items": [ { "product_key": "vps-small", "notes": "1 vCPU, 2 GB", "quantity": 1.0, "cost": 10.0 } ]
  },
  "invoice": {
    "id": "xYRdG7dDzO",
    "number": "0091",
    "status_id": 4,
    "amount": 10.0,
    "balance": 0.0,
    "paid_to_date": 10.0,
    "due_date": "2026-11-02",
    "line_items": [ { "product_key": "vps-small", "notes": "1 vCPU, 2 GB", "quantity": 1.0, "cost": 10.0 } ]
  },
  "wordpress_user_id": 12
}
```

| Field | Notes |
|---|---|
| `id` | Stable per event: the same event delivered twice (a retry) has the same `id`. Store it and ignore repeats. |
| `event` | One of the seven events above. |
| `context` | What triggered it: an Invoice Ninja callback context (`single_purchase`, `recurring_purchase`, `trial`, `change_plan`, `plan_paid`, `cancellation`, `plan_expired`), or `overdue_poll`, `invoice_late`, `payment`. |
| `subscription.frequency_id` | Invoice Ninja's frequency: 1 daily, 2 weekly, 3 every 2 weeks, 4 every 4 weeks, 5 monthly, 6 every 2 months, 7 quarterly, 8 every 4 months, 9 every 6 months, 10 yearly, 11 every 2 years, 12 every 3 years. |
| `client.account_key` | Invoice Ninja's `custom_value2`, which its own callbacks call the account key. Put your panel's account id in a client custom field to link the two. |
| `recurring_invoice` | `null` for one-off purchases. `status_id`: -1 pending, 1 draft, 2 active, 3 paused, 4 completed. |
| `invoice` | The invoice the event is about, or `null`. `status_id`: 1 draft, 2 sent, 3 partial, 4 paid, 5 cancelled, 6 reversed. |
| `wordpress_user_id` | The WordPress user linked to the client, or 0. |

Everything in the payload was fetched from Invoice Ninja by id just before sending; nothing comes from the
callback body. The payload never contains portal or login links.

### Headers

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Invoiceninja-Signature` | `sha256=` + hex HMAC-SHA256 of `"<timestamp>.<raw body>"`, keyed with your relay secret |
| `X-Invoiceninja-Timestamp` | Unix time the request was signed |
| `X-Invoiceninja-Event` | The event name |
| `X-Invoiceninja-Id` | The event `id` |
| `X-Invoiceninja-Source` | The WordPress site's address |

The relay secret is shown under *Settings → Subscriptions → Provisioning signature* (administrators only).

Your endpoint should answer **2xx** once it has accepted the event (queue the real work if it's slow). Any
other answer, or no answer within 10 seconds, is retried after 1, 2, 5, 15 and 30 minutes, then 1, 2 and 4
hours. Redirects are not followed.

The provisioning URL must be on the public internet, like any address WordPress calls on someone's
behalf. For a panel on your own network, allow it explicitly:

```php
add_filter( 'invoiceninja_relay_allow_private', function ( bool $allow, string $url ) {
    return str_starts_with( $url, 'https://panel.internal.example/' );
}, 10, 2 );
```

## Verifying the signature

Always verify against the **raw** request body, before parsing it, and refuse timestamps more than five
minutes old.

### PHP

```php
<?php
function invoiceninja_relay_verified( string $body, string $signature, string $timestamp, string $secret ): bool {
    if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
    return hash_equals( $expected, $signature );
}
```

### Node.js

```js
const crypto = require( 'crypto' );

function relayVerified( rawBody, signature, timestamp, secret ) {
	if ( ! /^\d+$/.test( timestamp || '' ) || Math.abs( Date.now() / 1000 - Number( timestamp ) ) > 300 ) {
		return false;
	}
	const expected = 'sha256=' + crypto.createHmac( 'sha256', secret ).update( timestamp + '.' + rawBody ).digest( 'hex' );
	const a = Buffer.from( expected );
	const b = Buffer.from( signature || '' );
	return a.length === b.length && crypto.timingSafeEqual( a, b );
}
```

## A sample receiver

A minimal PHP endpoint (for example `https://panel.example.com/hooks/invoiceninja.php`). Replace the
`provision_*` functions with calls to your control panel's API.

```php
<?php
const RELAY_SECRET = 'paste the relay secret here';

$body      = file_get_contents( 'php://input' );
$signature = $_SERVER['HTTP_X_INVOICENINJA_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_INVOICENINJA_TIMESTAMP'] ?? '';

if ( ! invoiceninja_relay_verified( $body, $signature, $timestamp, RELAY_SECRET ) ) {
    http_response_code( 401 );
    exit;
}

$event = json_decode( $body, true );
if ( ! is_array( $event ) || 1 !== ( $event['version'] ?? 0 ) ) {
    http_response_code( 400 ); // Unknown version: retried later, after you've upgraded.
    exit;
}

// Ignore repeats (a retry after a timeout carries the same id).
$seen = __DIR__ . '/seen/' . preg_replace( '/[^a-z0-9_]/', '', $event['id'] );
if ( file_exists( $seen ) ) {
    http_response_code( 200 );
    exit;
}

$account = $event['client']['account_key'] ?: $event['client']['id'];
$plan    = $event['recurring_invoice']['line_items'][0]['product_key'] ?? $event['subscription']['name'];

switch ( $event['event'] ) {
    case 'purchased':
    case 'trial':
        provision_create( $account, $plan, $event['client']['email'] );
        break;
    case 'plan_changed':
        provision_resize( $account, $plan );
        break;
    case 'paid':
    case 'resumed':
        provision_unsuspend( $account );
        break;
    case 'suspended':
        provision_suspend( $account );
        break;
    case 'cancelled':
        provision_terminate( $account );
        break;
}

touch( $seen );
http_response_code( 200 );
echo '{"ok":true}';
```

The same in Node.js with Express:

```js
const express = require( 'express' );
const app = express();
const seen = new Set(); // Use your database in production.

app.post( '/hooks/invoiceninja', express.raw( { type: 'application/json' } ), ( req, res ) => {
	const raw = req.body.toString( 'utf8' );
	if ( ! relayVerified( raw, req.get( 'X-Invoiceninja-Signature' ), req.get( 'X-Invoiceninja-Timestamp' ), process.env.RELAY_SECRET ) ) {
		return res.sendStatus( 401 );
	}
	const event = JSON.parse( raw );
	if ( event.version !== 1 ) {
		return res.sendStatus( 400 );
	}
	if ( ! seen.has( event.id ) ) {
		seen.add( event.id );
		queueProvisioning( event ); // Do the slow work after answering.
	}
	res.json( { ok: true } );
} );

app.listen( 3000 );
```

## Good to know

- **Use an admin token** in WordPress, and on hosted Invoice Ninja a Pro or Enterprise plan (the API and
  webhooks need it).
- **Eligibility rules only run on the standard purchase page.** The Pricing Table and Subscribe Button
  always link there. Invoice Ninja's newer "v3" purchase links (`…/purchase/v3`) skip the eligibility
  check and don't accept a prefilled email.
- **Logged-in customers with a confirmed email** arrive on the purchase page already signed in; everyone
  else confirms their email on Invoice Ninja's page. If your company requires a client portal password,
  customers are asked for it.
- **A subscription that already sends its purchases to another URL** keeps working: the plugin passes
  every callback on to it, and asks it during the eligibility check. A subscription that already reports
  to *another WordPress site* (for example your live site, seen from a staging copy) is refused.
- **Staging copies** never change your subscriptions in Invoice Ninja until you allow orders from that
  site.
- The Subscriptions tab on the Account page shows each plan's status: active, trial, overdue, suspended or
  cancelled.

## Out of scope

The plugin delivers signed, retried lifecycle events; it doesn't talk to control panels itself. There are
**no built-in modules for cPanel, Plesk, DirectAdmin, Virtualizor, Proxmox or similar**: connect them with a
small receiver like the one above (or an automation tool that can verify an HMAC signature).
