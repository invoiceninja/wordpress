<?php
/**
 * The "Invoices & billing" tab in WooCommerce My Account.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Plugin;

/**
 * Renders the Account (Portal module) inside WooCommerce's My Account, so customers
 * have one place for orders, invoices and billing. The Account itself decides what a
 * visitor may see (verification, portal links through its own redirect endpoint).
 */
final class AccountTab {

	public const ENDPOINT = 'invoices-billing';

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
		$endpoint = self::endpoint();
		add_action( 'init', static fn() => add_rewrite_endpoint( $endpoint, EP_ROOT | EP_PAGES ), 5 );
		add_action( 'init', array( $this, 'flushOnce' ), 99 );
		add_filter(
			'woocommerce_get_query_vars',
			static function ( $vars ) use ( $endpoint ): array {
				$vars              = is_array( $vars ) ? $vars : array();
				$vars[ $endpoint ] = $endpoint;
				return $vars;
			}
		);
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ), 20 );
		add_filter( 'woocommerce_endpoint_' . $endpoint . '_title', static fn() => __( 'Invoices & billing', 'invoiceninja' ) );
		add_action( 'woocommerce_account_' . $endpoint . '_endpoint', array( $this, 'render' ) );
	}

	/**
	 * The endpoint slug.
	 */
	public static function endpoint(): string {
		/**
		 * The My Account endpoint slug for Invoices & billing.
		 *
		 * @since 2.0.0
		 * @param string $endpoint Slug.
		 */
		$slug = sanitize_title( (string) apply_filters( 'invoiceninja_woo_account_endpoint', self::ENDPOINT ) );
		return '' !== $slug ? $slug : self::ENDPOINT;
	}

	/**
	 * Flush rewrite rules once after the endpoint appears or changes.
	 */
	public function flushOnce(): void {
		if ( $this->plugin->options->get( 'woo_endpoint' ) !== self::endpoint() ) {
			flush_rewrite_rules( false );
			$this->plugin->options->set( 'woo_endpoint', self::endpoint() );
		}
	}

	/**
	 * Add the tab before Log out.
	 *
	 * @param mixed $items Menu items.
	 * @return array<string, string>
	 */
	public function menu( $items ): array {
		$items = is_array( $items ) ? $items : array();
		$out   = array();
		$added = false;
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key && ! $added ) {
				$out[ self::endpoint() ] = __( 'Invoices & billing', 'invoiceninja' );
				$added                   = true;
			}
			$out[ (string) $key ] = (string) $label;
		}
		if ( ! $added ) {
			$out[ self::endpoint() ] = __( 'Invoices & billing', 'invoiceninja' );
		}
		return $out;
	}

	/**
	 * The tab's content: the Account.
	 */
	public function render(): void {
		if ( shortcode_exists( 'invoiceninja_account' ) ) {
			echo do_shortcode( '[invoiceninja_account]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the Account renders (and escapes) its own HTML.
			return;
		}
		echo '<p>' . esc_html__( 'Your invoices and billing details will appear here soon.', 'invoiceninja' ) . '</p>';
	}
}
