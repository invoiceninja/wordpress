<?php
/**
 * Admin notices for problems that need someone.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Connection;

use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Plugin;

/**
 * Shown to people who can manage the connection, on the Dashboard, Plugins and
 * Invoice Ninja screens only, so other admin pages stay clean.
 */
final class Notices {

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
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render notices.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( ! in_array( $id, array( 'dashboard', 'plugins' ), true ) && ! str_contains( $id, 'invoiceninja' ) ) {
			return;
		}
		$creds = $this->plugin->credentials;
		$state = $this->plugin->state->get();
		$link  = admin_url( 'admin.php?page=invoiceninja-settings' );

		if ( $creds->hasToken() && null === $creds->token() ) {
			$this->notice( 'error', ErrorKind::TokenUnreadable->title(), ErrorKind::TokenUnreadable->fix(), $link, __( 'Paste the token again', 'invoiceninja' ) );
			return;
		}
		if ( 'error' === $state['status'] && null !== $state['error'] ) {
			$kind = ErrorKind::tryFrom( $state['error']['kind'] ) ?? ErrorKind::Transport;
			$this->notice( 'error', $kind->title(), $kind->fix(), admin_url( 'admin.php?page=invoiceninja-status' ), __( 'Open Status', 'invoiceninja' ) );
			return;
		}
		if ( 'connected' === $state['status'] && $this->plugin->environment->changed() ) {
			$this->notice(
				'warning',
				__( 'Invoice Ninja orders are paused on this site', 'invoiceninja' ),
				__( 'The site address or type changed, which usually means this is a copy. Allow orders when this is your live site.', 'invoiceninja' ),
				admin_url( 'admin.php?page=invoiceninja-status&tab=tools' ),
				__( 'Review', 'invoiceninja' )
			);
		}
	}

	/**
	 * Print one notice.
	 *
	 * @param string $type  error, warning or info.
	 * @param string $title Title.
	 * @param string $body  Body.
	 * @param string $url   Action URL.
	 * @param string $label Action label.
	 */
	private function notice( string $type, string $title, string $body, string $url, string $label ): void {
		printf(
			'<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s <a href="%4$s">%5$s</a></p></div>',
			esc_attr( $type ),
			esc_html( 'Invoice Ninja: ' . $title . '.' ),
			esc_html( $body ),
			esc_url( $url ),
			esc_html( $label )
		);
	}
}
