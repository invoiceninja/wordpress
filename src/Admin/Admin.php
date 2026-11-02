<?php
/**
 * Admin menu and screens.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Admin;

use InvoiceNinja\Core\Capabilities;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * Screens are rendered by assets/admin/admin.js (plain JS on WordPress's own React,
 * no build step) from data printed here. Assets load only on these screens.
 */
final class Admin {

	public const SCREENS = array(
		'invoiceninja'          => 'home',
		'invoiceninja-setup'    => 'setup',
		'invoiceninja-orders'   => 'orders',
		'invoiceninja-settings' => 'settings',
		'invoiceninja-status'   => 'status',
	);

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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'parent_file', array( $this, 'parentFile' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin->file ), array( $this, 'actionLinks' ) );
	}

	/**
	 * Build the menu.
	 */
	public function menu(): void {
		$connected = 'none' !== $this->plugin->credentials->source();
		$attention = $this->attentionCount();
		$title     = __( 'Invoice Ninja', 'invoiceninja' );
		if ( $attention > 0 ) {
			$title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>',
				$attention,
				/* translators: %d: number of problems */
				esc_html( sprintf( _n( '%d needs attention', '%d need attention', $attention, 'invoiceninja' ), $attention ) )
			);
		}

		add_menu_page( __( 'Invoice Ninja', 'invoiceninja' ), $title, Capabilities::VIEW, 'invoiceninja', array( $this, 'render' ), $this->icon(), 56 );
		add_submenu_page( 'invoiceninja', __( 'Invoice Ninja', 'invoiceninja' ), __( 'Home', 'invoiceninja' ), Capabilities::VIEW, 'invoiceninja', array( $this, 'render' ) );
		// Setup leaves the menu once connected but stays reachable (hidden pages hang off options.php).
		add_submenu_page( $connected ? 'options.php' : 'invoiceninja', __( 'Set up Invoice Ninja', 'invoiceninja' ), __( 'Setup', 'invoiceninja' ), 'manage_options', 'invoiceninja-setup', array( $this, 'render' ) );
		add_submenu_page( 'invoiceninja', __( 'Orders', 'invoiceninja' ), __( 'Orders', 'invoiceninja' ), Capabilities::VIEW, 'invoiceninja-orders', array( $this, 'render' ) );
		add_submenu_page( 'invoiceninja', __( 'Settings', 'invoiceninja' ), __( 'Settings', 'invoiceninja' ), Capabilities::MANAGE, 'invoiceninja-settings', array( $this, 'render' ) );
		add_submenu_page( 'invoiceninja', __( 'Status', 'invoiceninja' ), __( 'Status', 'invoiceninja' ), Capabilities::VIEW, 'invoiceninja-status', array( $this, 'render' ) );
	}

	/**
	 * Render a screen's root element.
	 */
	public function render(): void {
		$screen = $this->currentScreen();
		echo '<div class="wrap invoiceninja-wrap">';
		echo '<div id="invoiceninja-app" data-screen="' . esc_attr( $screen ) . '"><p class="invoiceninja-loading">' . esc_html__( 'Loading…', 'invoiceninja' ) . '</p></div>';
		echo '<noscript><div class="notice notice-error inline"><p>' . esc_html__( 'These screens need JavaScript.', 'invoiceninja' ) . '</p></div></noscript>';
		echo '</div>';
	}

	/**
	 * Enqueue assets on our screens only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( string $hook ): void {
		unset( $hook );
		$screen = $this->currentScreen();
		if ( '' === $screen ) {
			return;
		}
		$version = INVOICENINJA_VERSION;
		wp_enqueue_style( 'invoiceninja-admin', $this->plugin->url() . 'assets/admin/admin.css', array(), $version );
		wp_enqueue_script(
			'invoiceninja-admin',
			$this->plugin->url() . 'assets/admin/admin.js',
			array( 'wp-element', 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ),
			$version,
			true
		);
		wp_set_script_translations( 'invoiceninja-admin', 'invoiceninja' );
		wp_add_inline_script( 'invoiceninja-admin', 'window.invoiceNinjaAdmin = ' . wp_json_encode( $this->data( $screen ) ) . ';', 'before' );
	}

	/**
	 * Keep our menu open on the hidden Setup page.
	 *
	 * @param string $parent_file Parent file.
	 */
	public function parentFile( string $parent_file ): string {
		return 'setup' === $this->currentScreen() ? 'invoiceninja' : $parent_file;
	}

	/**
	 * Add Settings to the plugin row.
	 *
	 * @param array<string, string> $links Links.
	 * @return array<string, string>
	 */
	public function actionLinks( array $links ): array {
		$url = 'none' === $this->plugin->credentials->source() ? admin_url( 'admin.php?page=invoiceninja-setup' ) : admin_url( 'admin.php?page=invoiceninja' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html( 'none' === $this->plugin->credentials->source() ? __( 'Set up', 'invoiceninja' ) : __( 'Home', 'invoiceninja' ) ) . '</a>' );
		return $links;
	}

	/**
	 * Which of our screens is open, or ''.
	 */
	private function currentScreen(): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a screen.
		return self::SCREENS[ $page ] ?? '';
	}

	/**
	 * Problems from the last diagnostics run, for the menu bubble.
	 */
	private function attentionCount(): int {
		$last = $this->plugin->options->get( 'last_diagnostics' );
		$fail = is_array( $last ) ? (int) ( $last['counts']['fail'] ?? 0 ) : 0;
		return 'error' === $this->plugin->state->get()['status'] ? max( 1, $fail ) : $fail;
	}

	/**
	 * Data for the screens.
	 *
	 * @param string $screen Screen.
	 * @return array<string, mixed>
	 */
	private function data( string $screen ): array {
		$user = wp_get_current_user();
		return array(
			'screen'       => $screen,
			'version'      => INVOICENINJA_VERSION,
			'connection'   => ( new Routes( $this->plugin ) )->connectionPayload(),
			'settings'     => current_user_can( Capabilities::MANAGE ) ? $this->plugin->options->settings() : null,
			'diagnostics'  => $this->plugin->options->get( 'last_diagnostics' ),
			'wooActive'    => class_exists( 'WooCommerce' ),
			'userEmail'    => (string) $user->user_email,
			'siteHost'     => (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ),
			'upgradedFrom' => (string) $this->plugin->options->get( 'upgraded_from', '' ),
			'can'          => array(
				'manageSite' => current_user_can( 'manage_options' ),
				'manage'     => current_user_can( Capabilities::MANAGE ),
			),
			'urls'         => array(
				'home'      => admin_url( 'admin.php?page=invoiceninja' ),
				'setup'     => admin_url( 'admin.php?page=invoiceninja-setup' ),
				'settings'  => admin_url( 'admin.php?page=invoiceninja-settings' ),
				'status'    => admin_url( 'admin.php?page=invoiceninja-status' ),
				'orders'    => admin_url( 'admin.php?page=invoiceninja-orders' ),
				'legacy'    => admin_url( 'admin.php?page=invoiceninja-legacy' ),
				'scheduler' => admin_url( 'tools.php?page=action-scheduler&s=invoiceninja' ),
				'docs'      => 'https://invoiceninja.github.io/en/wordpress/',
			),
		);
	}

	/**
	 * Menu icon as a data URI.
	 */
	private function icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}
}
