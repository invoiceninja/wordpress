<?php
/**
 * Wires the plugin together.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja;

use InvoiceNinja\Admin\Admin;
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
			}
		);
		$this->queue->handle( 'invoiceninja_daily', array( $this, 'daily' ) );

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
