<?php
/**
 * Personal data exporters and erasers for orders and customer links.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Privacy;

use InvoiceNinja\Commerce\Orders;
use InvoiceNinja\Plugin;

/**
 * Tools > Export / Erase Personal Data. Orders are anonymised rather than deleted, so
 * order numbers and totals stay for the books (the invoices live on in Invoice Ninja,
 * which has its own privacy tools).
 */
final class Privacy {

	public const ERASE_BATCH     = 200;
	public const ERASE_MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( array $exporters ): array {
				$exporters['invoiceninja-orders'] = array(
					'exporter_friendly_name' => __( 'Invoice Ninja orders', 'invoiceninja' ),
					'callback'               => array( $this, 'export' ),
				);
				return $exporters;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( array $erasers ): array {
				$erasers['invoiceninja-orders'] = array(
					'eraser_friendly_name' => __( 'Invoice Ninja orders', 'invoiceninja' ),
					'callback'             => array( $this, 'erase' ),
				);
				return $erasers;
			}
		);
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	/**
	 * Export orders and the account link for an email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$items = array();
		foreach ( $this->orders( $email, $page ) as $order ) {
			$items[] = array(
				'group_id'    => 'invoiceninja-orders',
				'group_label' => __( 'Orders', 'invoiceninja' ),
				'item_id'     => 'invoiceninja-order-' . $order['id'],
				'data'        => array(
					array(
						'name'  => __( 'Order', 'invoiceninja' ),
						'value' => (string) $order['number'],
					),
					array(
						'name'  => __( 'Placed', 'invoiceninja' ),
						'value' => (string) $order['created_at'],
					),
					array(
						'name'  => __( 'Email', 'invoiceninja' ),
						'value' => (string) $order['email'],
					),
					array(
						'name'  => __( 'Name', 'invoiceninja' ),
						'value' => trim( (string) ( $order['customer']['first_name'] ?? '' ) . ' ' . (string) ( $order['customer']['last_name'] ?? '' ) ),
					),
					array(
						'name'  => __( 'Phone', 'invoiceninja' ),
						'value' => (string) ( $order['customer']['phone'] ?? '' ),
					),
					array(
						'name'  => __( 'Items', 'invoiceninja' ),
						'value' => implode( ', ', array_map( static fn( array $i ): string => $i['quantity'] . ' × ' . $i['title'], (array) $order['items'] ) ),
					),
					array(
						'name'  => __( 'Total', 'invoiceninja' ),
						'value' => (string) $order['total'],
					),
					array(
						'name'  => __( 'Status', 'invoiceninja' ),
						'value' => (string) $order['status'],
					),
				),
			);
		}
		$user = get_user_by( 'email', $email );
		if ( $user && 1 === $page ) {
			$items[] = array(
				'group_id'    => 'invoiceninja-account',
				'group_label' => __( 'Invoice Ninja account', 'invoiceninja' ),
				'item_id'     => 'invoiceninja-account-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Client linked', 'invoiceninja' ),
						'value' => '' !== (string) get_user_meta( $user->ID, \InvoiceNinja\Portal\Links::CLIENT, true ) ? __( 'Yes', 'invoiceninja' ) : __( 'No', 'invoiceninja' ),
					),
					array(
						'name'  => __( 'Email verified on', 'invoiceninja' ),
						'value' => (string) get_user_meta( $user->ID, '_invoiceninja_verified_at', true ),
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => count( $this->orders( $email, $page + 1 ) ) === 0,
		);
	}

	/**
	 * Anonymise orders and remove the account link for an email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$removed = false;
		// Erased orders no longer match, so every page starts from the top.
		$batch = $this->orders( $email, 1, self::ERASE_BATCH );
		foreach ( $batch as $order ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
			$wpdb->update(
				Orders::table(),
				array(
					'email'    => 'deleted-' . $order['id'] . '@example.invalid',
					'customer' => '{}',
					'user_id'  => 0,
					'pay_link' => '', // An invitation link opens the invoice for whoever has it.
				),
				array( 'id' => (int) $order['id'] )
			);
			$removed = true;
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			foreach ( array( \InvoiceNinja\Portal\Links::CLIENT, \InvoiceNinja\Portal\Links::CONTACT, \InvoiceNinja\Portal\Links::EMAIL, '_invoiceninja_verified_at', '_invoiceninja_verified_email_hash', '_invoiceninja_verified_by' ) as $key ) {
				$removed = delete_user_meta( $user->ID, $key ) || $removed;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $removed,
			'messages'       => $removed ? array( __( 'Order numbers and totals were kept for your records; names and emails were removed. The invoices in Invoice Ninja are not changed.', 'invoiceninja' ) ) : array(),
			// A full batch means there may be more; stop after many pages in case a row won't update.
			'done'           => count( $batch ) < self::ERASE_BATCH || $page >= self::ERASE_MAX_PAGES,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Invoice Ninja', 'invoiceninja' ),
			'<p>' . esc_html__( 'When you place an order or request a quote, we send your name, email, phone and the items to our billing system (Invoice Ninja) to create your invoice or quote. We email you a 6-digit code to confirm your email address. If you tick "Remember this device", a cookie remembers that for 30 days. Cookies also keep your cart and your latest order on this browser.', 'invoiceninja' ) . '</p>'
		);
	}

	/**
	 * Orders for an email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @param int    $per   Per page.
	 * @return list<array<string, mixed>>
	 */
	private function orders( string $email, int $page, int $per = 50 ): array {
		global $wpdb;
		$table = Orders::table();
		// Also the account's own orders placed under an earlier email.
		$user    = get_user_by( 'email', $email );
		$user_id = $user ? (int) $user->ID : 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s OR ( user_id > 0 AND user_id = %d ) ORDER BY id LIMIT %d OFFSET %d", strtolower( trim( $email ) ), $user_id, $per, max( 0, $page - 1 ) * $per ) );
		return array_values( array_filter( array_map( fn( $id ) => $this->plugin->orders->find( (int) $id ), (array) $ids ) ) );
	}
}
