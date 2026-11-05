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

		$failure = $this->plugin->installer->failure();
		if ( null !== $failure ) {
			$this->notice(
				'error',
				__( "The update didn't finish", 'invoiceninja' ),
				/* translators: %s: error message */
				sprintf( __( 'The site keeps working and the update is tried again in a few minutes. Error: %s', 'invoiceninja' ), $failure['message'] ),
				admin_url( 'admin.php?page=invoiceninja-status' ),
				__( 'Open Status', 'invoiceninja' )
			);
		}
		if ( $creds->hasToken() && null === $creds->token() ) {
			$this->notice( 'error', ErrorKind::TokenUnreadable->title(), ErrorKind::TokenUnreadable->fix(), $link, __( 'Paste the token again', 'invoiceninja' ) );
			return;
		}
		$configured = 'none' !== $creds->source();
		$error      = $state['error'];
		$kind       = null !== $error ? ( ErrorKind::tryFrom( $error['kind'] ) ?? ErrorKind::Transport ) : null;
		// A site with a saved token that never connected (e.g. updated from 1.x) shows why too.
		if ( null !== $kind && ( 'error' === $state['status'] || ( $configured && ( 'disconnected' === $state['status'] || ErrorKind::PrivateHost === $kind ) ) ) ) {
			$host = '' !== $creds->url() ? \InvoiceNinja\Api\BaseUrl::host( $creds->url() ) : '';
			if ( ErrorKind::PrivateHost === $kind ) {
				$this->notice( 'error', $kind->title( $host ), $kind->fix(), admin_url( 'admin.php?page=invoiceninja-setup' ), __( 'Review the connection', 'invoiceninja' ) );
			} else {
				$this->notice( 'error', $kind->title( $host ), $kind->fix(), admin_url( 'admin.php?page=invoiceninja-status' ), __( 'Open Status', 'invoiceninja' ) );
			}
			return;
		}
		if ( ! $configured ) {
			return;
		}
		$paused = $this->plugin->options->get( 'sync_paused' );
		if ( is_array( $paused ) ) {
			$this->notice(
				'warning',
				__( 'Product sync is paused', 'invoiceninja' ),
				/* translators: 1: products that would change, 2: all products */
				sprintf( __( '%1$d of %2$d products would change at once. Prices and products stay as they are until you review the changes.', 'invoiceninja' ), (int) ( $paused['changes'] ?? 0 ), (int) ( $paused['total'] ?? 0 ) ),
				admin_url( 'admin.php?page=invoiceninja-status' ),
				__( 'Review', 'invoiceninja' )
			);
		}
		$environment = $this->plugin->environment;
		$confirmed   = $environment->state();
		if ( null !== $confirmed && '' === $confirmed['fingerprint'] ) {
			$this->notice(
				'warning',
				__( 'Invoice Ninja orders are paused on this site', 'invoiceninja' ),
				__( 'An admin paused them. Allow orders again when you are ready.', 'invoiceninja' ),
				admin_url( 'admin.php?page=invoiceninja-status&tab=tools' ),
				__( 'Review', 'invoiceninja' )
			);
		} elseif ( $environment->changed() ) {
			$this->notice(
				'warning',
				__( 'Invoice Ninja orders are paused on this site', 'invoiceninja' ),
				__( 'The site address or type changed, which usually means this is a copy. Allow orders when this is your live site.', 'invoiceninja' ),
				admin_url( 'admin.php?page=invoiceninja-status&tab=tools' ),
				__( 'Review', 'invoiceninja' )
			);
		} elseif ( ! $environment->writesAllowed() ) {
			$this->notice(
				'warning',
				__( 'Invoice Ninja orders are paused on this site', 'invoiceninja' ),
				__( 'This site looks like a staging or local copy, so it does not create invoices or clients. Allow orders when this is your live site.', 'invoiceninja' ),
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
