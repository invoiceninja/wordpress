<?php
/**
 * A fixed address where a logged-in customer confirms their email.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

use InvoiceNinja\Plugin;

/**
 * ?invoiceninja=verify&return=… sends the customer to the Account page to confirm their
 * email with a code, then back to where they were (a product they wanted to buy). Sites
 * without an Account page (most sites updated from 1.x) get the same Account block on a
 * plain page here, so no page is ever created for it.
 */
final class ConfirmEmail {

	/**
	 * Constructor.
	 *
	 * @param Plugin  $plugin  Plugin.
	 * @param Account $account Account block.
	 */
	public function __construct( private Plugin $plugin, private Account $account ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 5 );
	}

	/**
	 * The address, with where to go back to afterwards.
	 *
	 * @param string $back A page on this site.
	 */
	public static function url( string $back = '' ): string {
		$args = array( 'invoiceninja' => 'verify' );
		if ( '' !== $back ) {
			$args['return'] = rawurlencode( $back );
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * The page to go back to, if it's on this site.
	 *
	 * @param string $raw As passed in the link (esc_url_raw'd).
	 */
	public static function back( string $raw ): string {
		$raw = trim( $raw );
		return '' === $raw ? '' : (string) wp_validate_redirect( $raw, '' );
	}

	/**
	 * Handle ?invoiceninja=verify.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link; nothing changes until the customer enters a code.
		if ( ! isset( $_GET['invoiceninja'] ) || 'verify' !== sanitize_key( wp_unslash( $_GET['invoiceninja'] ) ) ) {
			return;
		}
		$back = self::back( isset( $_GET['return'] ) && is_string( $_GET['return'] ) ? esc_url_raw( wp_unslash( $_GET['return'] ) ) : '' );
		// phpcs:enable
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url( $back ) ) );
			exit;
		}
		$page = $this->plugin->store->pageUrl( 'account' );
		if ( '' !== $page ) {
			$args = array( 'invoiceninja_verify' => '1' );
			if ( '' !== $back ) {
				$args['invoiceninja_return'] = rawurlencode( $back );
			}
			wp_safe_redirect( add_query_arg( $args, $page ) );
			exit;
		}
		$this->plugin->store->standalone( $this->account->render( array( 'return' => $back ) ) );
		exit;
	}
}
