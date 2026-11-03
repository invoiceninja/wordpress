<?php
/**
 * WooCommerce customers as Invoice Ninja clients (replaces 1.x ClientController).
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Plugin;

/**
 * woo.customer_sync:
 * - off: clients are only made when an order is invoiced;
 * - orders (default, "on purchase only"): the buyer's client is made at checkout, even
 *   when invoicing is off;
 * - accounts: also when a customer registers or saves their address (customers in the
 *   chosen roles only).
 *
 * An existing client is only changed when woo.customer_update is on (the 1.x "update
 * when found"), and then only its address and the matching contact's name and phone.
 *
 * 1.x's ClientController still runs (legacy display keeps 1.x code). While this module
 * handles a hook, 1.x's own sync is switched off for that hook, so nobody is synced twice.
 */
final class WooCustomerSync {

	public const HOOK = 'invoiceninja_woo_customer';

	/**
	 * Constructor.
	 *
	 * @param Plugin         $plugin   Plugin.
	 * @param OrderToInvoice $invoices Invoices (client lookup from an order).
	 */
	public function __construct( private Plugin $plugin, private OrderToInvoice $invoices ) {}

	/**
	 * The sync mode.
	 */
	public function mode(): string {
		$mode = (string) $this->plugin->options->setting( 'woo.customer_sync' );
		return in_array( $mode, array( 'off', 'orders', 'accounts' ), true ) ? $mode : 'orders';
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		$this->gateLegacy();
		$this->plugin->queue->handle( self::HOOK, array( $this, 'handle' ) );
		$mode = $this->mode();
		if ( 'off' === $mode ) {
			return;
		}
		if ( ! $this->plugin->options->setting( 'woo.invoices' ) ) {
			// Invoicing makes the client anyway; without it, make it at checkout.
			add_action( 'woocommerce_checkout_order_processed', fn( $order_id ) => $this->queue( array( 'order_id' => (int) $order_id ) ), 20, 1 );
			add_action(
				'woocommerce_store_api_checkout_order_processed',
				function ( $order ): void {
					if ( $order instanceof \WC_Order ) {
						$this->queue( array( 'order_id' => $order->get_id() ) );
					}
				},
				20,
				1
			);
		}
		if ( 'accounts' === $mode ) {
			add_action( 'woocommerce_created_customer', fn( $user_id ) => $this->queue( array( 'user_id' => (int) $user_id ) ), 20, 1 );
			add_action( 'woocommerce_customer_save_address', fn( $user_id ) => $this->queue( array( 'user_id' => (int) $user_id ) ), 20, 1 );
		}
	}

	/**
	 * Switch 1.x's client export off while we handle the same hooks.
	 */
	private function gateLegacy(): void {
		$off   = static fn() => '0';
		$hooks = array( 'woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed' );
		if ( 'accounts' === $this->mode() ) {
			$hooks = array_merge( $hooks, array( 'woocommerce_customer_save_address', 'woocommerce_created_customer', 'user_register', 'profile_update' ) );
		}
		foreach ( $hooks as $hook ) {
			add_action(
				$hook,
				static function () use ( $off ): void {
					add_filter( 'pre_option_invoiceninja_sync_clients', $off );
				},
				1,
				0
			);
			add_action(
				$hook,
				static function () use ( $off ): void {
					remove_filter( 'pre_option_invoiceninja_sync_clients', $off );
				},
				999,
				0
			);
		}
	}

	/**
	 * Queue a sync.
	 *
	 * @param array<string, int> $payload {user_id} or {order_id}.
	 */
	private function queue( array $payload ): void {
		if ( (int) ( $payload['user_id'] ?? $payload['order_id'] ?? 0 ) > 0 ) {
			$this->plugin->queue->push( self::HOOK, $payload, 5 );
		}
	}

	/**
	 * Job.
	 *
	 * @param array<string, mixed> $payload {user_id} or {order_id}.
	 * @throws ApiException To let the queue retry.
	 */
	public function handle( array $payload ): void {
		if ( ! $this->plugin->environment->writesAllowed() ) {
			$this->plugin->queue->push( self::HOOK, $payload, 900 );
			return;
		}
		if ( ! empty( $payload['order_id'] ) ) {
			$order = wc_get_order( (int) $payload['order_id'] );
			if ( $order instanceof \WC_Order ) {
				$this->invoices->clientFor( $this->plugin->api(), $order );
			}
			return;
		}
		$this->syncUser( (int) ( $payload['user_id'] ?? 0 ) );
	}

	/**
	 * Sync one customer account.
	 *
	 * @param int $user_id User.
	 * @return string The client id, or '' when skipped.
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	public function syncUser( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! self::roleMatches( (array) $user->roles, $this->roles() ) ) {
			return '';
		}
		$customer = new \WC_Customer( $user_id );
		$email    = strtolower( trim( (string) ( $customer->get_billing_email() ?: $user->user_email ) ) );
		if ( '' === $email ) {
			return '';
		}
		$details = self::details( $customer, $user );
		if ( '' === $details['first_name'] . $details['last_name'] . $details['address1'] ) {
			return ''; // Registered at checkout before WooCommerce copied the billing details; the order path handles it.
		}
		$client = $this->plugin->api();
		$found  = $this->plugin->clients->findOrCreate( $client, $email, $details );
		if ( ! $found['created'] && $this->plugin->options->setting( 'woo.customer_update' ) ) {
			$client->put( 'clients/' . rawurlencode( $found['client_id'] ), self::updateBody( $found['client'], $email, $details ), array(), 10.0 );
			$this->plugin->logger->log( LogLevel::Info, 'customers', 'Updated an Invoice Ninja client from a WooCommerce customer', array(), 'user', (string) $user_id );
		}
		// Links the account when its billing email is the account's own email.
		( new \InvoiceNinja\Portal\Links( $this->plugin ) )->link( $user_id, $email, $found['client_id'], $found['contact_id'] );
		return $found['client_id'];
	}

	/**
	 * Roles whose accounts are synced (from the setting, or 1.x's included roles).
	 *
	 * @return list<string>
	 */
	public function roles(): array {
		$setting = trim( (string) $this->plugin->options->setting( 'woo.customer_roles' ) );
		if ( '' === $setting ) {
			$legacy = get_option( 'invoiceninja_included_roles', array() );
			$roles  = is_array( $legacy ) ? $legacy : explode( ',', (string) $legacy );
		} else {
			$roles = explode( ',', $setting );
		}
		$roles = array_values( array_filter( array_map( static fn( $r ): string => sanitize_key( trim( (string) $r ) ), $roles ) ) );
		return $roles ? $roles : array( 'customer' );
	}

	/**
	 * Whether any of the user's roles is included.
	 *
	 * @param array<mixed> $user_roles User roles.
	 * @param list<string> $included   Included roles.
	 */
	public static function roleMatches( array $user_roles, array $included ): bool {
		return (bool) array_intersect( array_map( 'strval', $user_roles ), $included );
	}

	/**
	 * Client details from a WooCommerce customer.
	 *
	 * @param \WC_Customer $customer Customer.
	 * @param \WP_User     $user     User.
	 * @return array<string, string>
	 */
	public static function details( \WC_Customer $customer, \WP_User $user ): array {
		return array(
			'first_name'  => (string) ( $customer->get_billing_first_name() ?: $user->first_name ),
			'last_name'   => (string) ( $customer->get_billing_last_name() ?: $user->last_name ),
			'company'     => (string) $customer->get_billing_company(),
			'phone'       => (string) $customer->get_billing_phone(),
			'address1'    => (string) $customer->get_billing_address_1(),
			'address2'    => (string) $customer->get_billing_address_2(),
			'city'        => (string) $customer->get_billing_city(),
			'state'       => (string) $customer->get_billing_state(),
			'postal_code' => (string) $customer->get_billing_postcode(),
		);
	}

	/**
	 * The PUT body for "update when found": the address, and the matching contact's name
	 * and phone. Other contacts are sent back unchanged (with their ids), so none is lost.
	 *
	 * @param array<string, mixed>  $existing Client from Invoice Ninja.
	 * @param string                $email    Customer email.
	 * @param array<string, string> $details  Details.
	 * @return array<string, mixed>
	 */
	public static function updateBody( array $existing, string $email, array $details ): array {
		$body = array();
		foreach ( array( 'address1', 'address2', 'city', 'state', 'postal_code' ) as $field ) {
			if ( '' !== ( $details[ $field ] ?? '' ) ) {
				$body[ $field ] = $details[ $field ];
			}
		}
		$contacts = array();
		foreach ( (array) ( $existing['contacts'] ?? array() ) as $contact ) {
			if ( ! is_array( $contact ) ) {
				continue;
			}
			$keep = array_intersect_key( $contact, array_flip( array( 'id', 'first_name', 'last_name', 'email', 'phone', 'send_email', 'is_primary' ) ) );
			if ( strtolower( (string) ( $contact['email'] ?? '' ) ) === $email ) {
				foreach ( array( 'first_name', 'last_name', 'phone' ) as $field ) {
					if ( '' !== ( $details[ $field ] ?? '' ) ) {
						$keep[ $field ] = $details[ $field ];
					}
				}
			}
			$contacts[] = $keep;
		}
		if ( $contacts ) {
			$body['contacts'] = $contacts;
		}
		return $body;
	}
}
