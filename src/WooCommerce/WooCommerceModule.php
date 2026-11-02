<?php
/**
 * WooCommerce invoicing: Woo is the store, Invoice Ninja is the ledger.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Core\Capabilities;
use InvoiceNinja\Module;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * Runs when WooCommerce (at least MIN_WOO) is active and Settings → WooCommerce is on
 * (modules.woo). WooCommerce loads after this plugin, so everything is wired on
 * plugins_loaded. The settings tab shows whenever WooCommerce is active, so the module
 * can be switched on there.
 */
final class WooCommerceModule implements Module {

	public const MIN_WOO = '8.2';

	public ?OrderToInvoice $invoices = null;

	public ?Payments $payments = null;

	public ?Backfill $backfill = null;

	public ?CatalogBridge $catalog = null;

	public ?OrderMetaBox $metaBox = null;

	public ?WooCustomerSync $customers = null;

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
		add_filter( 'invoiceninja_setting_defaults', array( self::class, 'defaults' ) );
		add_action(
			'before_woocommerce_init',
			static function (): void {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && defined( 'INVOICENINJA_FILE' ) ) {
					// Orders are only read and written through the CRUD (HPOS), and nothing touches the checkout UI.
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', INVOICENINJA_FILE, true );
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', INVOICENINJA_FILE, true );
				}
			}
		);
		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	/**
	 * Settings defaults.
	 *
	 * @param mixed $defaults Defaults.
	 * @return array<string, mixed>
	 */
	public static function defaults( $defaults ): array {
		$defaults = is_array( $defaults ) ? $defaults : array();
		return $defaults + array(
			'woo' => array(
				'invoices'        => true,
				'email_link'      => true,
				'account_tab'     => true,
				'customer_sync'   => 'orders',
				'customer_roles'  => '',
				'customer_update' => false,
				'catalog_push'    => false,
				'catalog_update'  => false,
			),
		);
	}

	/**
	 * Whether WooCommerce is active.
	 */
	public static function wooActive(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * The active WooCommerce version, or ''.
	 */
	public static function wooVersion(): string {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) constant( 'WC_VERSION' );
		}
		return function_exists( 'WC' ) ? (string) WC()->version : '';
	}

	/**
	 * Whether the WooCommerce version is supported.
	 */
	public static function wooSupported(): bool {
		$version = self::wooVersion();
		return '' !== $version && version_compare( $version, self::MIN_WOO, '>=' );
	}

	/**
	 * Whether the module runs: WooCommerce active and supported, and switched on.
	 */
	public function active(): bool {
		return self::wooActive() && self::wooSupported() && (bool) $this->plugin->options->setting( 'modules.woo' );
	}

	/**
	 * Wire everything once WooCommerce has loaded.
	 */
	public function boot(): void {
		if ( ! self::wooActive() ) {
			return;
		}
		if ( ! self::wooSupported() ) {
			add_action( 'admin_notices', array( $this, 'versionNotice' ) );
			return;
		}
		add_action( 'invoiceninja_admin_assets', array( $this, 'assets' ) );
		add_action( 'invoiceninja_rest_routes', array( $this, 'routes' ) );
		add_filter( 'invoiceninja_diagnostics_checks', array( $this, 'checks' ) );
		if ( ! $this->active() ) {
			return;
		}
		$ids             = new IdMaps( $this->plugin );
		$this->invoices  = new OrderToInvoice( $this->plugin, $ids );
		$this->payments  = new Payments( $this->plugin, $this->invoices );
		$this->backfill  = new Backfill( $this->plugin, $this->invoices, $this->payments );
		$this->catalog   = new CatalogBridge( $this->plugin );
		$this->metaBox   = new OrderMetaBox( $this->plugin, $this->invoices, $this->payments );
		$this->customers = new WooCustomerSync( $this->plugin, $this->invoices );
		$this->invoices->register();
		$this->payments->register();
		$this->backfill->register();
		$this->catalog->register();
		$this->customers->register();
		( new Emails( $this->plugin ) )->register();
		if ( $this->plugin->options->setting( 'woo.account_tab' ) ) {
			( new AccountTab( $this->plugin ) )->register();
		}
		if ( is_admin() ) {
			$this->metaBox->register();
		}
		// The plugin's own cart and checkout step aside: WooCommerce runs the store.
		add_filter( 'invoiceninja_store_enabled', '__return_false' );
		$cli = function (): void {
			if ( class_exists( '\WP_CLI' ) ) {
				\WP_CLI::add_command( 'invoiceninja woo', new WooCommand( $this ) );
			}
		};
		if ( did_action( 'cli_init' ) ) {
			$cli();
		} else {
			add_action( 'cli_init', $cli );
		}
	}

	/**
	 * Below the minimum WooCommerce version.
	 */
	public function versionNotice(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Invoice Ninja:', 'invoiceninja' ) . '</strong> ' . esc_html(
			sprintf(
				/* translators: 1: minimum WooCommerce version, 2: installed version */
				__( 'Invoicing WooCommerce orders needs WooCommerce %1$s or newer. This site runs %2$s, so orders are not sent to Invoice Ninja until WooCommerce is updated.', 'invoiceninja' ),
				self::MIN_WOO,
				self::wooVersion()
			)
		) . '</p></div>';
	}

	/**
	 * The settings tab script.
	 *
	 * @param string $screen Admin screen.
	 */
	public function assets( $screen ): void {
		if ( 'settings' !== $screen ) {
			return;
		}
		wp_enqueue_script( 'invoiceninja-woocommerce-admin', $this->plugin->url() . 'assets/admin/woocommerce.js', array( 'invoiceninja-admin' ), INVOICENINJA_VERSION, true );
		wp_set_script_translations( 'invoiceninja-woocommerce-admin', 'invoiceninja' );
		wp_add_inline_script( 'invoiceninja-woocommerce-admin', 'window.invoiceNinjaWoo = ' . wp_json_encode( $this->statusPayload() ) . ';', 'before' );
	}

	/**
	 * REST routes.
	 *
	 * @param Routes $routes Routes.
	 */
	public function routes( Routes $routes ): void {
		$routes->add( '/woo/status', 'GET', fn() => new \WP_REST_Response( $this->statusPayload() ), 'view' );
		$routes->add( '/woo/backfill', 'POST', array( $this, 'restBackfill' ), 'manage' );
		$routes->add( '/woo/backfill/cancel', 'POST', array( $this, 'restBackfillCancel' ), 'manage' );
		$routes->add( '/woo/catalog', 'POST', array( $this, 'restCatalog' ), 'manage' );
		$routes->add( '/woo/orders/(?P<id>\d+)/retry', 'POST', array( $this, 'restRetry' ), 'manage' );
	}

	/**
	 * What the settings tab shows.
	 *
	 * @return array<string, mixed>
	 */
	public function statusPayload(): array {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$push = $this->plugin->options->get( 'woo_catalog_push' );
		return array(
			'active'     => $this->active(),
			'version'    => self::wooVersion(),
			'minVersion' => self::MIN_WOO,
			'hpos'       => $hpos,
			'backfill'   => null !== $this->backfill ? $this->backfill->status() : array( 'status' => 'none' ),
			'catalog'    => is_array( $push ) ? $push : null,
			'roles'      => function_exists( 'wp_roles' ) ? array_map( 'translate_user_role', wp_roles()->get_names() ) : array(),
			'endpoint'   => AccountTab::endpoint(),
		);
	}

	/**
	 * POST /woo/backfill {from, to, dry_run}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function restBackfill( \WP_REST_Request $request ): \WP_REST_Response {
		if ( null === $this->backfill ) {
			return Routes::fail( 'woo_off', __( 'Turn on WooCommerce invoicing first.', 'invoiceninja' ), 409 );
		}
		try {
			$result = $this->backfill->start(
				sanitize_text_field( (string) $request->get_param( 'from' ) ),
				sanitize_text_field( (string) $request->get_param( 'to' ) ),
				(bool) $request->get_param( 'dry_run' )
			);
		} catch ( \InvalidArgumentException $e ) {
			return Routes::fail( 'invalid_dates', $e->getMessage(), 400 );
		}
		return new \WP_REST_Response( $result );
	}

	/**
	 * POST /woo/backfill/cancel.
	 */
	public function restBackfillCancel(): \WP_REST_Response {
		if ( null === $this->backfill ) {
			return Routes::fail( 'woo_off', __( 'Turn on WooCommerce invoicing first.', 'invoiceninja' ), 409 );
		}
		return new \WP_REST_Response( $this->backfill->cancel() );
	}

	/**
	 * POST /woo/catalog {dry_run}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function restCatalog( \WP_REST_Request $request ): \WP_REST_Response {
		if ( null === $this->catalog ) {
			return Routes::fail( 'woo_off', __( 'Turn on WooCommerce invoicing first.', 'invoiceninja' ), 409 );
		}
		return new \WP_REST_Response( $this->catalog->push( false !== $request->get_param( 'dry_run' ) && 'false' !== $request->get_param( 'dry_run' ) ) );
	}

	/**
	 * POST /woo/orders/{id}/retry.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function restRetry( \WP_REST_Request $request ): \WP_REST_Response {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( null === $this->metaBox || ! $order instanceof \WC_Order ) {
			return Routes::fail( 'not_found', __( 'Order not found.', 'invoiceninja' ), 404 );
		}
		$result = $this->metaBox->run( $order );
		$fresh  = wc_get_order( $order->get_id() );
		return new \WP_REST_Response(
			array(
				'result' => $result,
				'state'  => $fresh instanceof \WC_Order ? OrderMetaBox::state( $fresh ) : null,
			)
		);
	}

	/**
	 * Diagnostics checks.
	 *
	 * @param mixed $checks Checks.
	 * @return array<int, array<string, string>>
	 */
	public function checks( $checks ): array {
		$checks = is_array( $checks ) ? $checks : array();
		if ( ! $this->active() ) {
			return $checks;
		}
		$company  = (array) ( $this->plugin->state->get()['company'] ?? array() );
		$checks[] = array(
			'id'     => 'woo-module',
			'label'  => __( 'WooCommerce invoicing', 'invoiceninja' ),
			'status' => 'pass',
			/* translators: 1: WooCommerce version, 2: order storage */
			'detail' => sprintf( __( 'WooCommerce %1$s, %2$s. Orders become invoices in Invoice Ninja.', 'invoiceninja' ), self::wooVersion(), $this->statusPayload()['hpos'] ? __( 'order tables (HPOS)', 'invoiceninja' ) : __( 'orders stored as posts', 'invoiceninja' ) ),
			'action' => '',
		);
		if ( ! empty( $company['calculate_taxes'] ) ) {
			$checks[] = array(
				'id'     => 'woo-taxes',
				'label'  => __( 'Taxes on WooCommerce invoices', 'invoiceninja' ),
				'status' => 'warn',
				'detail' => __( 'Invoice Ninja calculates taxes itself, so it replaces the taxes WooCommerce charged and invoice totals can differ from orders. Turn off automatic taxes in Invoice Ninja to keep WooCommerce’s taxes.', 'invoiceninja' ),
				'action' => '',
			);
		}
		if ( ! empty( $company['track_inventory'] ) ) {
			$checks[] = array(
				'id'     => 'woo-inventory',
				'label'  => __( 'Stock with WooCommerce', 'invoiceninja' ),
				'status' => 'warn',
				'detail' => __( 'Invoice Ninja tracks stock too, so invoices for WooCommerce orders also reduce stock there. WooCommerce owns stock; turn off stock tracking in Invoice Ninja to avoid counting it twice.', 'invoiceninja' ),
				'action' => '',
			);
		}
		return $checks;
	}
}
