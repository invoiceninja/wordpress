<?php
/**
 * REST API under invoiceninja/v1.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Rest;

use InvoiceNinja\Api\BaseUrl;
use InvoiceNinja\Core\Capabilities;
use InvoiceNinja\Plugin;

/**
 * Every route needs a capability, except those in PUBLIC_ROUTES. The allowlist grows by
 * milestone and a test walks every registered route against it.
 */
final class Routes {

	public const NS = 'invoiceninja/v1';

	public const PUBLIC_ROUTES = array( '/ping', '/cart', '/cart/items', '/checkout/start', '/checkout/confirm', '/orders/(?P<number>[A-Za-z0-9-]+)' );

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register routes.
	 */
	public function register(): void {
		$manage_site = static fn(): bool => current_user_can( 'manage_options' );
		$manage      = static fn(): bool => current_user_can( Capabilities::MANAGE );
		$view        = static fn(): bool => current_user_can( Capabilities::VIEW );

		$this->route( '/ping', 'GET', array( $this, 'ping' ), '__return_true' );

		$same_site = array( $this, 'sameSite' );
		$this->route( '/cart', 'GET', array( $this, 'getCart' ), '__return_true' );
		$this->route( '/cart/items', 'POST', array( $this, 'setCartItem' ), $same_site );
		$this->route( '/checkout/start', 'POST', array( $this, 'checkoutStart' ), $same_site );
		$this->route( '/checkout/confirm', 'POST', array( $this, 'checkoutConfirm' ), $same_site );
		$this->route( '/orders/(?P<number>[A-Za-z0-9-]+)', 'GET', array( $this, 'orderStatus' ), '__return_true' );

		$this->route( '/connection', 'GET', array( $this, 'getConnection' ), $view );
		$this->route( '/connection', 'POST', array( $this, 'saveConnection' ), $manage_site );
		$this->route( '/connection', 'DELETE', array( $this, 'deleteConnection' ), $manage_site );
		$this->route( '/connection/test', 'POST', array( $this, 'testConnection' ), $manage_site );
		$this->route( '/connection/refresh', 'POST', array( $this, 'refreshConnection' ), $manage );

		$this->route( '/environment', 'POST', array( $this, 'environment' ), $manage_site );

		$this->route( '/settings', 'GET', array( $this, 'getSettings' ), $manage );
		$this->route( '/settings', 'POST', array( $this, 'saveSettings' ), $manage );

		$this->route( '/diagnostics', 'GET', array( $this, 'diagnostics' ), $view );
		$this->route( '/diagnostics/repair', 'POST', array( $this, 'repair' ), $manage );

		$this->route( '/logs', 'GET', array( $this, 'logs' ), $view );
		$this->route( '/logs', 'DELETE', array( $this, 'clearLogs' ), $manage );

		$this->route( '/admin/orders', 'GET', array( $this, 'adminOrders' ), $view );
		$this->route( '/admin/orders/(?P<id>\\d+)/(?P<action>resend|retry|cancel)', 'POST', array( $this, 'adminOrderAction' ), $manage );

		$this->route( '/sync/products', 'GET', array( $this, 'syncStatus' ), $view );
		$this->route( '/sync/products', 'POST', array( $this, 'syncProducts' ), $manage );

		$this->route( '/mail/test', 'POST', array( $this, 'mailTest' ), $manage );
		$this->route( '/mail/confirm', 'POST', array( $this, 'mailConfirm' ), $manage );
	}

	/**
	 * Register one route, refusing an open permission on anything not allowlisted.
	 *
	 * @param string   $path       Path.
	 * @param string   $method     Method.
	 * @param callable $callback   Handler.
	 * @param callable $permission Permission callback.
	 * @throws \LogicException When a non-public route has an open permission.
	 */
	private function route( string $path, string $method, callable $callback, callable $permission ): void {
		$public = '__return_true' === $permission || ( is_array( $permission ) && 'sameSite' === $permission[1] );
		if ( $public && ! in_array( $path, self::PUBLIC_ROUTES, true ) ) {
			throw new \LogicException( 'Route ' . esc_html( $path ) . ' is not in the public allowlist' );
		}
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => $callback,
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Public writes must come from this site's own pages (Origin or Referer), which protects
	 * the cookie-based cart without nonces that page caches would break.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function sameSite( \WP_REST_Request $request ): bool {
		$source = (string) ( $request->get_header( 'origin' ) ?? '' );
		if ( '' === $source ) {
			$source = (string) ( $request->get_header( 'referer' ) ?? '' );
		}
		$origin = static function ( string $url ): string {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return '';
			}
			$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
			$port   = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
			return strtolower( (string) $parts['host'] ) . ':' . $port;
		};
		$from   = $origin( $source );
		return '' !== $from && ( $origin( (string) home_url() ) === $from || $origin( (string) site_url() ) === $from );
	}

	/**
	 * GET /cart.
	 */
	public function getCart(): \WP_REST_Response {
		nocache_headers();
		$cart = $this->plugin->cart;
		return new \WP_REST_Response( $cart->summary( $cart->items( $cart->currentId() ) ) );
	}

	/**
	 * POST /cart/items {product, quantity}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function setCartItem( \WP_REST_Request $request ): \WP_REST_Response {
		$cart = $this->plugin->cart;
		try {
			$id = $cart->ensureId();
		} catch ( \RuntimeException $e ) {
			return $this->error( 'rate_limited', __( 'Too many requests. Please try again later.', 'invoiceninja' ), 429 );
		}
		$mode = (string) $this->plugin->options->setting( 'store.mode' );
		if ( 'off' === $mode ) {
			return $this->error( 'store_off', __( "Online ordering isn't available on this site.", 'invoiceninja' ), 403 );
		}
		// Buy Now: the cart only ever holds the product just chosen.
		$result = $cart->set( $id, (string) $request->get_param( 'product' ), (int) $request->get_param( 'quantity' ), 'buy_now' === $mode );
		return new \WP_REST_Response( $result + array( 'cart' => $cart->summary( $cart->items( $id ) ) ) );
	}

	/**
	 * POST /checkout/start.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function checkoutStart( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->plugin->checkout->start( $this->buyer( $request ), self::ip() );
		return new \WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * POST /checkout/confirm.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function checkoutConfirm( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->plugin->checkout->confirm( $this->buyer( $request ), (string) $request->get_param( 'code' ), (bool) $request->get_param( 'remember' ), self::ip() );
		return new \WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * GET /orders/{number}?key= — the same reply for a wrong number or a wrong key.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function orderStatus( \WP_REST_Request $request ): \WP_REST_Response {
		nocache_headers();
		$key   = (string) ( $request->get_header( 'x-order-key' ) ?? '' );
		$key   = '' !== $key ? $key : (string) $request->get_param( 'key' );
		$order = $this->plugin->checkout->status( (string) $request->get_param( 'number' ), $key );
		return null === $order
			? $this->error( 'not_found', __( "We couldn't find that order. Check the link in your email.", 'invoiceninja' ), 404 )
			: new \WP_REST_Response( $order );
	}

	/**
	 * Buyer details from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, string>
	 */
	private function buyer( \WP_REST_Request $request ): array {
		return array(
			'email'      => sanitize_email( (string) $request->get_param( 'email' ) ),
			'first_name' => sanitize_text_field( (string) $request->get_param( 'first_name' ) ),
			'last_name'  => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
			'phone'      => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
		);
	}

	/**
	 * The shopper's IP, for rate limits only.
	 */
	private static function ip(): string {
		/** This filter is documented in src/Commerce/Cart.php */
		return (string) apply_filters( 'invoiceninja_client_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
	}

	/**
	 * GET /ping — used by the loopback check.
	 */
	public function ping(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * GET /connection.
	 */
	public function getConnection(): \WP_REST_Response {
		return new \WP_REST_Response( $this->connectionPayload() );
	}

	/**
	 * POST /connection/test.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function testConnection( \WP_REST_Request $request ): \WP_REST_Response {
		[ $url, $token, $error ] = $this->credentialsFrom( $request );
		if ( $error ) {
			return $error;
		}
		return new \WP_REST_Response( $this->plugin->connection->test( $url, $token, $this->allowPrivate( $request ) ) );
	}

	/**
	 * POST /connection — test and save.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function saveConnection( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $this->plugin->credentials->locked() ) {
			return $this->error( 'locked', __( 'The connection is set in wp-config.php and can only be changed there.', 'invoiceninja' ), 409 );
		}
		[ $url, $token, $error ] = $this->credentialsFrom( $request );
		if ( $error ) {
			return $error;
		}
		$result               = $this->plugin->connection->connect( $url, $token, $this->allowPrivate( $request ), (bool) $request->get_param( 'confirm_switch' ) );
		$result['connection'] = $this->connectionPayload();
		return new \WP_REST_Response( $result );
	}

	/**
	 * DELETE /connection.
	 */
	public function deleteConnection(): \WP_REST_Response {
		$this->plugin->connection->disconnect();
		return new \WP_REST_Response( $this->connectionPayload() );
	}

	/**
	 * POST /connection/refresh.
	 */
	public function refreshConnection(): \WP_REST_Response {
		$this->plugin->connection->refresh();
		return new \WP_REST_Response( $this->connectionPayload() );
	}

	/**
	 * POST /environment — allow or pause orders from this site.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function environment( \WP_REST_Request $request ): \WP_REST_Response {
		$action = (string) $request->get_param( 'action' );
		if ( 'allow' === $action ) {
			$this->plugin->environment->confirm( get_current_user_id() );
			$this->plugin->logger->info( 'connection', 'Orders allowed from this site' );
		} elseif ( 'pause' === $action ) {
			$this->plugin->environment->pause();
			$this->plugin->logger->info( 'connection', 'Orders paused on this site' );
		} else {
			return $this->error( 'invalid_action', __( 'Unknown action.', 'invoiceninja' ), 400 );
		}
		return new \WP_REST_Response( $this->connectionPayload() );
	}

	/**
	 * GET /settings.
	 */
	public function getSettings(): \WP_REST_Response {
		return new \WP_REST_Response( $this->plugin->options->settings() );
	}

	/**
	 * POST /settings — partial update.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$changes = $request->get_json_params();
		if ( ! is_array( $changes ) ) {
			return $this->error( 'invalid_body', __( 'Send the settings as JSON.', 'invoiceninja' ), 400 );
		}
		if ( isset( $changes['log_level'] ) && ! in_array( $changes['log_level'], array( 'debug', 'info', 'warning', 'error' ), true ) ) {
			return $this->error( 'invalid_log_level', __( 'Unknown log level.', 'invoiceninja' ), 400 );
		}
		if ( isset( $changes['notifications']['leads'] ) && ! in_array( $changes['notifications']['leads'], array( 'instant', 'daily', 'off' ), true ) ) {
			return $this->error( 'invalid_leads', __( 'Unknown quote request email setting.', 'invoiceninja' ), 400 );
		}
		if ( isset( $changes['store']['mode'] ) && ! in_array( $changes['store']['mode'], array( 'off', 'buy_now', 'cart' ), true ) ) {
			return $this->error( 'invalid_mode', __( 'Unknown store mode.', 'invoiceninja' ), 400 );
		}
		if ( isset( $changes['store']['display'] ) && ! in_array( $changes['store']['display'], array( 'blocks', 'legacy' ), true ) ) {
			return $this->error( 'invalid_display', __( 'Unknown display.', 'invoiceninja' ), 400 );
		}
		if ( isset( $changes['store']['abandoned_action'] ) && ! in_array( $changes['store']['abandoned_action'], array( 'cancel', 'delete' ), true ) ) {
			return $this->error( 'invalid_action', __( 'Unknown action for unpaid orders.', 'invoiceninja' ), 400 );
		}
		if ( isset( $changes['store']['abandoned_days'] ) && ( (int) $changes['store']['abandoned_days'] < 1 || (int) $changes['store']['abandoned_days'] > 365 ) ) {
			return $this->error( 'invalid_days', __( 'Choose between 1 and 365 days.', 'invoiceninja' ), 400 );
		}
		if ( isset( $changes['notifications']['recipients'] ) ) {
			$emails = array_filter( array_map( 'trim', explode( ',', (string) $changes['notifications']['recipients'] ) ) );
			foreach ( $emails as $email ) {
				if ( ! is_email( $email ) ) {
					/* translators: %s: email address */
					return $this->error( 'invalid_email', sprintf( __( '%s is not a valid email address.', 'invoiceninja' ), $email ), 400 );
				}
			}
		}
		foreach ( array( 'from', 'reply_to' ) as $field ) {
			if ( ! empty( $changes['email'][ $field ] ) && ! is_email( (string) $changes['email'][ $field ] ) ) {
				/* translators: %s: email address */
				return $this->error( 'invalid_email', sprintf( __( '%s is not a valid email address.', 'invoiceninja' ), (string) $changes['email'][ $field ] ), 400 );
			}
		}
		if ( ! current_user_can( 'manage_options' ) && ( isset( $changes['delete_data_on_uninstall'] ) || isset( $changes['email'] ) || isset( $changes['notifications']['recipients'] ) ) ) {
			return $this->error( 'forbidden', __( 'Only site administrators can change email addresses or uninstall settings.', 'invoiceninja' ), 403 );
		}
		$saved = $this->plugin->options->saveSettings( $changes );
		if ( 'debug' === ( $changes['log_level'] ?? '' ) ) {
			$this->plugin->options->set( 'log_debug_until', time() + DAY_IN_SECONDS );
		}
		return new \WP_REST_Response( $saved );
	}

	/**
	 * GET /diagnostics.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function diagnostics( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $request->get_param( 'cached' ) ) {
			$last = $this->plugin->options->get( 'last_diagnostics' );
			if ( is_array( $last ) ) {
				return new \WP_REST_Response( $last );
			}
		}
		return new \WP_REST_Response( $this->plugin->diagnostics->run( true ) );
	}

	/**
	 * POST /diagnostics/repair.
	 */
	public function repair(): \WP_REST_Response {
		$done = $this->plugin->diagnostics->repair();
		return new \WP_REST_Response(
			array(
				'done'        => $done,
				'diagnostics' => $this->plugin->diagnostics->run( true ),
			)
		);
	}

	/**
	 * GET /logs.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function logs( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->plugin->logger->query(
			array(
				'level'    => (string) $request->get_param( 'level' ),
				'channel'  => (string) $request->get_param( 'channel' ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?? 50 ),
			)
		);
		foreach ( $result['items'] as &$item ) {
			$item['context'] = null !== $item['context'] ? json_decode( (string) $item['context'], true ) : null;
		}
		return new \WP_REST_Response( $result );
	}

	/**
	 * DELETE /logs.
	 */
	public function clearLogs(): \WP_REST_Response {
		$this->plugin->logger->clear();
		$this->plugin->logger->info( 'connection', 'Activity log cleared' );
		return new \WP_REST_Response( array( 'cleared' => true ) );
	}

	/**
	 * GET /admin/orders.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function adminOrders( \WP_REST_Request $request ): \WP_REST_Response {
		$result          = $this->plugin->orders->list(
			array(
				'status'   => (string) $request->get_param( 'status' ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => 20,
			)
		);
		$result['items'] = array_map( array( $this, 'adminOrder' ), $result['items'] );
		return new \WP_REST_Response( $result );
	}

	/**
	 * POST /admin/orders/{id}/{resend|retry|cancel}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function adminOrderAction( \WP_REST_Request $request ): \WP_REST_Response {
		$order = $this->plugin->orders->find( (int) $request->get_param( 'id' ) );
		if ( null === $order ) {
			return $this->error( 'not_found', __( 'Order not found.', 'invoiceninja' ), 404 );
		}
		try {
			switch ( (string) $request->get_param( 'action' ) ) {
				case 'resend':
					// Payments are only noticed when someone looks, so check before asking the buyer to pay.
					delete_transient( 'invoiceninja_order_check_' . $order['id'] );
					$order = $this->plugin->orderService->refreshStatus( $order );
					if ( 'awaiting_payment' !== $order['status'] ) {
						return $this->error( 'not_awaiting', 'paid' === $order['status'] ? __( 'This order is paid, so there is nothing to resend.', 'invoiceninja' ) : __( 'Only orders awaiting payment have a payment email.', 'invoiceninja' ), 409 );
					}
					if ( '' === (string) $order['pay_link'] ) {
						return $this->error( 'no_pay_link', __( 'This order has no payment link for the buyer. Send the invoice from Invoice Ninja.', 'invoiceninja' ), 409 );
					}
					$this->plugin->checkout->emailBuyer( $order );
					$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'email', sprintf( 'Payment email resent for order %s', $order['number'] ), array(), 'order', (string) $order['number'] );
					break;
				case 'retry':
					$order = $this->plugin->orderService->retryNow( $order );
					break;
				case 'cancel':
					$order = $this->plugin->orderService->cancel( $order );
					if ( $request->get_param( 'email' ) ) {
						// In the site's language, not the admin's.
						$switched = switch_to_locale( get_locale() );
						$this->plugin->mailer->send(
							(string) $order['email'],
							/* translators: %s: order number */
							sprintf( __( 'Order #%s was cancelled', 'invoiceninja' ), $order['number'] ),
							'<p>' . esc_html__( "Your order was cancelled and its payment link no longer works. You haven't been charged for it. If this is a surprise, reply to this email.", 'invoiceninja' ) . '</p>'
						);
						if ( $switched ) {
							restore_previous_locale();
						}
					}
					break;
			}
		} catch ( \InvoiceNinja\Commerce\OrderConflict $e ) {
			return $this->error( 'order_conflict', $e->getMessage(), 409 );
		} catch ( \InvoiceNinja\Api\ApiException $e ) {
			return $this->error( $e->kind->value, $e->kind->title() . '. ' . $e->kind->fix(), 502 );
		}
		return new \WP_REST_Response( $this->adminOrder( (array) $this->plugin->orders->find( (int) $order['id'] ) ) );
	}

	/**
	 * Builds links to records in Invoice Ninja's own app. 5.13.44+ has /app/<path>, which
	 * opens whichever web app the server runs; older servers use the React app's hash routes.
	 *
	 * @return (callable(string): string)|null Null when not connected.
	 */
	private function inAppUrl(): ?callable {
		$base = $this->plugin->credentials->url();
		if ( '' === $base ) {
			return null;
		}
		$state   = $this->plugin->state->get();
		$company = (string) ( $state['company']['id'] ?? '' );
		if ( \InvoiceNinja\Api\HttpClient::versionAtLeast( (string) ( $state['version'] ?? '' ), '5.13.44' ) ) {
			return static fn( string $path ): string => $base . '/app/' . $path . ( '' !== $company ? '?company=' . rawurlencode( $company ) : '' );
		}
		$web = \InvoiceNinja\Api\BaseUrl::isHosted( $base ) ? 'https://app.invoicing.co' : $base;
		return static fn( string $path ): string => $web . '/#/' . $path . ( str_starts_with( $path, 'invoices/' ) ? '/edit' : '' );
	}

	/**
	 * An order for the admin list (no payment link: it logs into the client's portal).
	 *
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed>
	 */
	public function adminOrder( array $order ): array {
		$app = $this->inAppUrl();
		return array(
			'id'             => (int) $order['id'],
			'currency'       => (string) $order['currency'],
			'number'         => (string) $order['number'],
			'status'         => (string) $order['status'],
			'email'          => (string) $order['email'],
			'name'           => trim( (string) ( $order['customer']['first_name'] ?? '' ) . ' ' . (string) ( $order['customer']['last_name'] ?? '' ) ),
			'total'          => (float) $order['total'],
			'invoice_number' => (string) $order['invoice_number'],
			'invoice_url'    => '' !== (string) $order['invoice_id'] && null !== $app ? $app( 'invoices/' . rawurlencode( (string) $order['invoice_id'] ) ) : '',
			'client_url'     => '' !== (string) $order['client_id'] && null !== $app ? $app( 'clients/' . rawurlencode( (string) $order['client_id'] ) ) : '',
			'note'           => \InvoiceNinja\Commerce\Orders::noteText( (string) $order['note'] ),
			'created_at'     => (string) $order['created_at'],
			'status_url'     => $this->plugin->checkout->statusUrl( $order ),
			'items'          => array_map(
				static fn( array $i ): array => array(
					'title'    => (string) $i['title'],
					'quantity' => (int) $i['quantity'],
				),
				(array) $order['items']
			),
		);
	}

	/**
	 * GET /sync/products — last result and, when paused, what would change.
	 */
	public function syncStatus(): \WP_REST_Response {
		return new \WP_REST_Response( $this->syncPayload() );
	}

	/**
	 * POST /sync/products — sync now; `force` applies a sync the circuit breaker stopped.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function syncProducts( \WP_REST_Request $request ): \WP_REST_Response {
		$force  = (bool) $request->get_param( 'force' );
		$result = $this->plugin->products->run( true, $force );
		if ( $force ) {
			$this->plugin->logger->info( 'sync', 'Product sync applied after review' );
		}
		return new \WP_REST_Response( array( 'result' => $result ) + $this->syncPayload() );
	}

	/**
	 * Sync state for the UI.
	 *
	 * @return array<string, mixed>
	 */
	private function syncPayload(): array {
		return array(
			'status'  => $this->plugin->options->get( 'sync_status' ),
			'paused'  => $this->plugin->options->get( 'sync_paused' ),
			'enabled' => $this->plugin->products->enabled(),
			'count'   => $this->plugin->mapping->count( 'product' ),
		);
	}

	/**
	 * POST /mail/test.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function mailTest( \WP_REST_Request $request ): \WP_REST_Response {
		$raw = trim( (string) $request->get_param( 'to' ) );
		$to  = '' === $raw ? (string) wp_get_current_user()->user_email : sanitize_email( $raw );
		if ( ! is_email( $to ) || ( '' !== $raw && $to !== $raw ) ) {
			return $this->error( 'invalid_email', __( 'Enter a valid email address.', 'invoiceninja' ), 400 );
		}
		return new \WP_REST_Response(
			array(
				'sent' => $this->plugin->mailer->sendTest( $to ),
				'to'   => $to,
			)
		);
	}

	/**
	 * POST /mail/confirm.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function mailConfirm( \WP_REST_Request $request ): \WP_REST_Response {
		$this->plugin->mailer->confirmTest( (bool) $request->get_param( 'arrived' ) );
		return new \WP_REST_Response( array( 'test' => $this->plugin->mailer->lastTest() ) );
	}

	/**
	 * Connection details for the UI. Never includes the token.
	 *
	 * @return array<string, mixed>
	 */
	public function connectionPayload(): array {
		$p     = $this->plugin;
		$state = $p->state->get();
		$url   = $p->credentials->url();
		$env   = $p->environment;
		return array(
			'state'       => $state,
			'url'         => $url,
			'hosted'      => '' !== $url && BaseUrl::isHosted( $url ),
			'source'      => $p->credentials->source(),
			'locked'      => $p->credentials->locked(),
			'token_hint'  => $p->credentials->tokenHint(),
			'token_ok'    => null !== $p->credentials->token(),
			'environment' => array(
				'allowed'    => $env->writesAllowed(),
				'changed'    => $env->changed(),
				'looks_live' => $env->looksLive(),
				'type'       => $env->type(),
				'state'      => $env->state(),
			),
			'mail_test'   => $p->mailer->lastTest(),
		);
	}

	/**
	 * Read url/token from a request, reusing the saved token when the address is unchanged.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{0: string, 1: string, 2: \WP_REST_Response|null}
	 */
	private function credentialsFrom( \WP_REST_Request $request ): array {
		$hosted = (bool) $request->get_param( 'hosted' );
		$url    = $hosted ? BaseUrl::HOSTED : (string) $request->get_param( 'url' );
		$token  = trim( (string) $request->get_param( 'token' ) );
		if ( '' === $token ) {
			$saved_url = $this->plugin->credentials->url();
			$saved     = $this->plugin->credentials->token();
			if ( '' === $saved_url || BaseUrl::normalize( $url ) !== $saved_url || ! is_string( $saved ) || '' === $saved ) {
				return array( '', '', $this->error( 'token_required', __( 'Paste your API token.', 'invoiceninja' ), 400 ) );
			}
			$token = $saved;
		}
		if ( null === BaseUrl::normalize( $url ) ) {
			return array( '', '', $this->error( 'invalid_url', __( "That doesn't look like a web address.", 'invoiceninja' ), 400 ) );
		}
		return array( $url, $token, null );
	}

	/**
	 * Whether this request may allow a private host. On multisite only network admins can,
	 * so a subsite admin can't aim the site at the internal network.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function allowPrivate( \WP_REST_Request $request ): bool {
		if ( ! $request->get_param( 'allow_private' ) ) {
			return false;
		}
		return ! is_multisite() || current_user_can( 'manage_network_options' );
	}

	/**
	 * Error response.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
