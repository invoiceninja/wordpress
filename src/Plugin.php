<?php
/**
 * Wires the plugin together.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja;

use InvoiceNinja\Admin\Admin;
use InvoiceNinja\Catalog\ImageSideloader;
use InvoiceNinja\Catalog\ProductSync;
use InvoiceNinja\Commerce\AbandonedOrders;
use InvoiceNinja\Commerce\Cart;
use InvoiceNinja\Commerce\Checkout;
use InvoiceNinja\Commerce\OrderNumbers;
use InvoiceNinja\Commerce\Orders;
use InvoiceNinja\Commerce\OrderService;
use InvoiceNinja\Commerce\StockCheck;
use InvoiceNinja\Verification\DeviceRemember;
use InvoiceNinja\Verification\EmailCode;
use InvoiceNinja\CLI\Command;
use InvoiceNinja\Connection\ConnectionService;
use InvoiceNinja\Connection\ConnectionState;
use InvoiceNinja\Connection\Credentials;
use InvoiceNinja\Connection\Diagnostics;
use InvoiceNinja\Connection\Notices;
use InvoiceNinja\Connection\SiteHealth;
use InvoiceNinja\Core\Environment;
use InvoiceNinja\Core\Installer;
use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Mailer;
use InvoiceNinja\Core\Notifications;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Rest\Routes;
use InvoiceNinja\Sync\Mapping;

/**
 * One instance per request. Services are plain objects created up front; they are cheap
 * and do nothing until called. Front-end requests only register hooks.
 */
final class Plugin {

	/**
	 * Background job queued after any version change.
	 */
	public const AFTER_UPGRADE = 'invoiceninja_after_upgrade';

	/**
	 * The instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	public readonly Options $options;
	public readonly Secrets $secrets;
	public readonly Logger $logger;
	public readonly Queue $queue;
	public readonly Environment $environment;
	public readonly Credentials $credentials;
	public readonly ConnectionState $state;
	public readonly ConnectionService $connection;
	public readonly Installer $installer;
	public readonly Diagnostics $diagnostics;
	public readonly Mailer $mailer;
	public readonly Notifications $notifications;
	public readonly Mapping $mapping;
	public readonly ProductSync $products;
	public readonly Orders $orders;
	public readonly Cart $cart;
	public readonly OrderService $orderService;
	public readonly Checkout $checkout;

	public readonly AbandonedOrders $abandoned;

	public readonly \InvoiceNinja\Catalog\Statics $statics;

	public readonly \InvoiceNinja\Frontend\Store $store;

	public readonly \InvoiceNinja\Customers\ClientResolver $clients;

	public readonly EmailCode $codes;

	/**
	 * Set while Settings > Store and the 1.x purchase option are being synced.
	 */
	private bool $syncingStore = false;

	/**
	 * Constructor.
	 *
	 * @param string $file Main plugin file.
	 */
	private function __construct( public readonly string $file ) {
		$this->options       = new Options();
		$this->secrets       = new Secrets( $this->options );
		$this->logger        = new Logger( $this->options );
		$this->queue         = new Queue( $this->logger );
		$this->environment   = new Environment( $this->options );
		$this->credentials   = new Credentials( $this->options, $this->secrets );
		$this->state         = new ConnectionState( $this->options );
		$this->connection    = new ConnectionService( $this->credentials, $this->state, $this->logger );
		$this->installer     = new Installer( $this->options, $this->secrets, $this->credentials, $this->logger );
		$this->mailer        = new Mailer( $this->options );
		$this->notifications = new Notifications( $this->options, $this->mailer, $this->logger );
		$this->mapping       = new Mapping();
		$this->products      = new ProductSync( $this, $this->mapping );
		$this->orders        = new Orders();
		$this->cart          = new Cart( $this->secrets, $this->mapping );
		$this->orderService  = new OrderService( $this, $this->orders );
		$this->codes         = new EmailCode( $this->secrets, $this->mailer, $this->logger, $this->options );
		$this->checkout      = new Checkout(
			$this,
			$this->cart,
			$this->orders,
			$this->orderService,
			new OrderNumbers( $this->options, $this->secrets ),
			$this->codes,
			new DeviceRemember( $this->secrets ),
			new StockCheck( $this, $this->cart, $this->mapping )
		);
		$this->abandoned     = new AbandonedOrders( $this, $this->orders, $this->orderService );
		$this->clients       = new \InvoiceNinja\Customers\ClientResolver( $this );
		$this->statics       = new \InvoiceNinja\Catalog\Statics( $this );
		$this->store         = new \InvoiceNinja\Frontend\Store( $this );
		$this->diagnostics   = new Diagnostics( $this );
	}

	/**
	 * Feature modules, created when their class exists.
	 */
	public const MODULES = array(
		'portal'        => 'InvoiceNinja\\Portal\\PortalModule',
		'forms'         => 'InvoiceNinja\\Forms\\FormsModule',
		'webhooks'      => 'InvoiceNinja\\Webhooks\\WebhooksModule',
		'subscriptions' => 'InvoiceNinja\\Subscriptions\\SubscriptionsModule',
		'woocommerce'   => 'InvoiceNinja\\WooCommerce\\WooCommerceModule',
	);

	/**
	 * Created modules.
	 *
	 * @var array<string, Module>
	 */
	private array $modules = array();

	/**
	 * A module, if it exists.
	 *
	 * @param string $name Key in MODULES.
	 */
	public function module( string $name ): ?Module {
		return $this->modules[ $name ] ?? null;
	}

	/**
	 * The API client for the saved connection (private hosts only when allowed for it).
	 */
	public function api(): \InvoiceNinja\Api\HttpClient {
		return $this->credentials->client( $this->state->allowsPrivate( $this->credentials->url() ) );
	}

	/**
	 * The shopper's IP for rate limits.
	 */
	public static function clientIp(): string {
		/**
		 * The shopper's IP for rate limits (filter it when a trusted proxy is in front).
		 *
		 * @since 2.0.0
		 * @param string $ip IP.
		 */
		return (string) apply_filters( 'invoiceninja_client_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
	}

	/**
	 * Create the instance and register hooks.
	 *
	 * @param string $file Main plugin file.
	 */
	public static function boot( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
			self::$instance->hooks();
		}
		return self::$instance;
	}

	/**
	 * Whether boot() ran.
	 */
	public static function booted(): bool {
		return null !== self::$instance;
	}

	/**
	 * The instance.
	 *
	 * @throws \LogicException Before boot().
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			throw new \LogicException( 'Invoice Ninja is not booted' );
		}
		return self::$instance;
	}

	/**
	 * Plugin directory with a trailing slash.
	 */
	public function dir(): string {
		return plugin_dir_path( $this->file );
	}

	/**
	 * Plugin URL with a trailing slash.
	 */
	public function url(): string {
		return plugin_dir_url( $this->file );
	}

	/**
	 * Register hooks.
	 */
	private function hooks(): void {
		add_action( 'plugins_loaded', array( $this->installer, 'maybeUpgrade' ), 5 );
		$this->store->register();
		( new \InvoiceNinja\Privacy\Privacy( $this ) )->register();
		( new \InvoiceNinja\Admin\Dashboard( $this ) )->register();
		foreach ( self::MODULES as $name => $class ) {
			// @phpstan-ignore-next-line function.impossibleType, function.alreadyNarrowedType -- a module exists only when its files ship.
			if ( class_exists( $class ) && is_subclass_of( $class, Module::class ) ) {
				$this->modules[ $name ] = new $class( $this );
				$this->modules[ $name ]->register();
			}
		}
		add_action( 'invoiceninja_connected', array( $this->statics, 'refresh' ) );

		add_action(
			'action_scheduler_init',
			function (): void {
				$this->queue->recurring( 'invoiceninja_daily', DAY_IN_SECONDS );
				if ( 'none' !== $this->credentials->source() && $this->products->enabled() ) {
					$this->queue->recurring( 'invoiceninja_sync_products', HOUR_IN_SECONDS );
				}
			}
		);
		$this->queue->handle( 'invoiceninja_daily', array( $this, 'daily' ) );
		// After any version change, catch up in the background: connect a site that has a
		// token but never connected through 2.0, refresh the company details and relink products.
		add_action(
			'invoiceninja_upgraded',
			function (): void {
				$push = function (): void {
					$this->queue->push( self::AFTER_UPGRADE );
				};
				if ( did_action( 'action_scheduler_init' ) ) {
					$push();
				} else {
					add_action( 'action_scheduler_init', $push, 20 );
				}
			}
		);
		$this->queue->handle( self::AFTER_UPGRADE, fn() => $this->afterUpgrade() );
		$this->queue->handle(
			'invoiceninja_sync_products',
			function ( array $payload ): void {
				if ( ! $this->products->enabled() ) {
					// Turned off since it was scheduled.
					if ( function_exists( 'as_unschedule_all_actions' ) ) {
						as_unschedule_all_actions( 'invoiceninja_sync_products', array(), Queue::GROUP );
					}
					return;
				}
				$this->products->run( ! empty( $payload['full'] ), ! empty( $payload['force'] ), ! empty( $payload['continue'] ) );
			}
		);
		add_action( 'invoiceninja_company_switched', array( $this->products, 'forgetCompany' ) );
		$this->queue->handle( 'invoiceninja_place_order', array( $this->orderService, 'retry' ) );
		$this->queue->handle( AbandonedOrders::HOOK, fn() => $this->abandoned->run() );
		// Settings > Store and the 1.x purchase option stay in step (the 1.x buttons still read
		// theirs), once migration 7 has copied the 1.x value over.
		$to_legacy   = function ( ...$args ): void {
			$value = $args[1] ?? null; // The new value is the second argument of both hooks.
			$modes = array(
				'off'     => 'disabled',
				'buy_now' => 'single',
				'cart'    => 'multiple',
			);
			$mode  = is_array( $value ) ? (string) ( $value['store']['mode'] ?? '' ) : '';
			if ( $this->syncingStore || ! isset( $modes[ $mode ] ) || (int) get_option( Options::PREFIX . 'schema_version', 0 ) < 7 || get_option( 'invoiceninja_online_purchases' ) === $modes[ $mode ] ) {
				return;
			}
			$this->syncingStore = true;
			update_option( 'invoiceninja_online_purchases', $modes[ $mode ] );
			$this->syncingStore = false;
		};
		$from_legacy = function ( ...$args ): void {
			$value = is_string( $args[1] ?? null ) ? $args[1] : '';
			$modes = array(
				'disabled' => 'off',
				'single'   => 'buy_now',
				'multiple' => 'cart',
			);
			if ( $this->syncingStore || ! isset( $modes[ $value ] ) || (int) get_option( Options::PREFIX . 'schema_version', 0 ) < 7 || $this->options->setting( 'store.mode' ) === $modes[ $value ] ) {
				return;
			}
			$this->syncingStore = true;
			$this->options->saveSettings( array( 'store' => array( 'mode' => $modes[ $value ] ) ) );
			$this->syncingStore = false;
		};
		add_action( 'update_option_' . Options::PREFIX . 'settings', $to_legacy, 10, 3 );
		add_action( 'add_option_' . Options::PREFIX . 'settings', $to_legacy, 10, 2 );
		add_action( 'update_option_invoiceninja_online_purchases', $from_legacy, 10, 3 );
		add_action( 'add_option_invoiceninja_online_purchases', $from_legacy, 10, 2 );
		add_action( 'invoiceninja_order_placed', array( $this->checkout, 'emailBuyer' ) );
		add_action( 'invoiceninja_order_needs_attention', array( $this->notifications, 'orderNeedsAttention' ) );
		$this->queue->handle( 'invoiceninja_sideload_image', array( new ImageSideloader( $this ), 'handle' ) );
		add_action(
			'invoiceninja_connected',
			function (): void {
				if ( $this->products->enabled() ) {
					$this->queue->push( 'invoiceninja_sync_products', array( 'full' => true ) );
				}
			}
		);

		add_action( 'rest_api_init', array( new Routes( $this ), 'register' ) );
		add_filter( 'site_status_tests', array( new SiteHealth( $this ), 'tests' ) );
		$this->notifications->register();

		if ( is_admin() ) {
			( new Admin( $this ) )->register();
			( new Notices( $this ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'invoiceninja', new Command( $this ) );
		}
	}

	/**
	 * Background work after the plugin was updated.
	 */
	public function afterUpgrade(): void {
		// 1.x (also after a downgrade and update again) re-creates product posts: link them.
		$linked = $this->products->linkLegacyPosts();
		if ( $linked > 0 ) {
			$this->logger->info( 'upgrade', sprintf( 'Linked %d products from 1.x', $linked ) );
		}
		// Connects a site that never connected through 2.0 (which also syncs products and
		// refreshes the company lists), or refreshes the company record for new fields.
		if ( 'refreshed' === $this->connection->resume() ) {
			$this->statics->refresh();
		}
	}

	/**
	 * Daily housekeeping: prune the log and re-check the connection.
	 */
	public function daily(): void {
		$this->logger->prune();
		$this->options->pruneCounters();
		if ( $this->credentials->hasToken() ) {
			$was = $this->state->get()['status'];
			$ok  = in_array( $this->connection->resume(), array( 'connected', 'refreshed' ), true );
			if ( ! $ok && 'connected' === $was ) {
				$this->notifications->connectionLost( $this->state->get() );
			} elseif ( $ok ) {
				$this->notifications->connectionRecovered();
			}
		}
		$this->statics->refresh();
		// Orders whose placing request died: send them again.
		foreach ( $this->orders->staleClaims() as $order_id ) {
			$this->queue->push( 'invoiceninja_place_order', array( 'order_id' => $order_id ), 0, true );
		}
		if ( $this->abandoned->enabled() ) {
			$this->queue->push( AbandonedOrders::HOOK, array(), 0, true );
		}
		/**
		 * Daily work for modules (runs in the background).
		 *
		 * @since 2.0.0
		 */
		do_action( 'invoiceninja_daily_tasks' );
		$this->options->set( 'heartbeat', time() );
	}
}
