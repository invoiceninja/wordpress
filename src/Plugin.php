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
		$this->checkout      = new Checkout(
			$this,
			$this->cart,
			$this->orders,
			$this->orderService,
			new OrderNumbers( $this->options, $this->secrets ),
			new EmailCode( $this->secrets, $this->mailer, $this->logger, $this->options ),
			new DeviceRemember( $this->secrets ),
			new StockCheck( $this, $this->cart, $this->mapping )
		);
		$this->diagnostics   = new Diagnostics( $this );
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
				$this->products->run( ! empty( $payload['full'] ), ! empty( $payload['force'] ) );
			}
		);
		add_action( 'invoiceninja_company_switched', array( $this->products, 'forgetCompany' ) );
		$this->queue->handle( 'invoiceninja_place_order', array( $this->orderService, 'retry' ) );
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
	 * Daily housekeeping: prune the log and re-check the connection.
	 */
	public function daily(): void {
		$this->logger->prune();
		$this->options->pruneCounters();
		if ( $this->credentials->hasToken() ) {
			$was = $this->state->get()['status'];
			$ok  = $this->connection->refresh();
			if ( ! $ok && 'connected' === $was ) {
				$this->notifications->connectionLost( $this->state->get() );
			} elseif ( $ok ) {
				$this->notifications->connectionRecovered();
			}
		}
		$this->options->set( 'heartbeat', time() );
	}
}
