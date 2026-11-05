<?php
/**
 * Webhooks: instant updates from Invoice Ninja.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Webhooks;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Module;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * Registers this site's webhooks when the plugin connects (and checks them nightly),
 * receives them on /webhook, and dispatches each through the queue. Falls back to polling
 * every 15 minutes when Invoice Ninja won't send webhooks to this site.
 */
final class WebhooksModule implements Module {

	public const REGISTER = 'invoiceninja_webhooks_register';

	public readonly Registrar $registrar;

	public readonly Dispatcher $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
		$this->registrar  = new Registrar( $plugin );
		$this->dispatcher = new Dispatcher( $plugin );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		$p        = $this->plugin;
		$receiver = new Receiver( $p );
		$poller   = new Poller( $p, $this->dispatcher );

		add_action(
			'invoiceninja_rest_routes',
			function ( Routes $routes ) use ( $receiver ): void {
				// Every method, so Invoice Ninja never sees a 404 or 405 (both delete the webhook).
				$routes->add( '/webhook', \WP_REST_Server::ALLMETHODS, array( $receiver, 'handle' ), 'public' );
				$routes->add( '/webhooks', 'GET', array( $this, 'status' ), 'view' );
				$routes->add( '/webhooks', 'POST', array( $this, 'registerNow' ), 'manage' );
			}
		);

		$p->queue->handle( Receiver::JOB, array( $this->dispatcher, 'handle' ) );
		$p->queue->handle(
			self::REGISTER,
			function (): void {
				$this->registrar->sync();
			}
		);
		$p->queue->handle( Poller::HOOK, array( $poller, 'run' ) );

		$queue_register = static function () use ( $p ): void {
			$p->queue->push( self::REGISTER, array(), 0, true );
		};
		add_action( 'invoiceninja_connected', $queue_register );
		add_action( 'invoiceninja_daily_tasks', $queue_register ); // The nightly existence check.
		add_action( 'invoiceninja_company_switched', array( $this->registrar, 'forgetCompany' ) );
		add_action(
			'invoiceninja_before_disconnect',
			function (): void {
				$this->registrar->deleteAll( 6.0 );
			}
		);
		add_action(
			'deactivate_' . plugin_basename( $p->file ),
			function (): void {
				$this->registrar->deleteAll( 5.0 );
			}
		);
		add_action( 'activate_' . plugin_basename( $p->file ), $queue_register );
		// Orders allowed again on this site: set up what was waiting.
		$env_changed = function () use ( $queue_register ): void {
			$state = $this->registrar->state();
			if ( 'paused' === $state['reason'] && $this->plugin->environment->writesAllowed() ) {
				$queue_register();
			}
		};
		add_action( 'update_option_invoiceninja_environment', $env_changed );
		add_action( 'add_option_invoiceninja_environment', $env_changed );

		add_action( 'action_scheduler_init', array( $this, 'schedule' ) );
		add_filter( 'invoiceninja_diagnostics_checks', array( $this, 'check' ) );
		add_filter( 'invoiceninja_repair', array( $this, 'repair' ) );

		add_action(
			'cli_init',
			function (): void {
				\WP_CLI::add_command( 'invoiceninja webhooks', new WebhooksCommand( $this ) );
			}
		);
	}

	/**
	 * Poll every 15 minutes only while webhooks aren't working.
	 */
	public function schedule(): void {
		$polling = 'none' !== $this->plugin->credentials->source() && 'instant' !== $this->registrar->state()['mode'];
		if ( $polling ) {
			$this->plugin->queue->recurring( Poller::HOOK, Poller::INTERVAL );
		} else {
			$this->plugin->queue->stopRecurring( Poller::HOOK );
		}
	}

	/**
	 * GET /webhooks.
	 */
	public function status(): \WP_REST_Response {
		return new \WP_REST_Response( $this->summary() );
	}

	/**
	 * POST /webhooks: create or repair them now.
	 */
	public function registerNow(): \WP_REST_Response {
		try {
			$this->registrar->sync();
		} catch ( ApiException $e ) {
			$this->plugin->queue->push( self::REGISTER, array(), 60, true );
		}
		return new \WP_REST_Response( $this->summary() );
	}

	/**
	 * State for the admin screens and CLI.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		$state = $this->registrar->state();
		return array(
			'mode'       => $state['mode'],
			'reason'     => $state['reason'],
			'message'    => $this->reasonText( $state['reason'] ),
			'registered' => count( $state['hooks'] ),
			'expected'   => count( Events::ids() ),
			'complete'   => $this->registrar->complete(),
			'checked_at' => $state['checked_at'],
		);
	}

	/**
	 * A plain sentence for a reason code.
	 *
	 * @param string $reason Reason.
	 */
	private function reasonText( string $reason ): string {
		return match ( $reason ) {
			''              => __( 'Changes in Invoice Ninja reach this site within seconds.', 'invoiceninja' ),
			'not_connected' => __( 'Not connected to Invoice Ninja.', 'invoiceninja' ),
			'paused'        => __( 'Waiting until orders are allowed from this site (it looks like a copy).', 'invoiceninja' ),
			'not_allowed'   => __( "Invoice Ninja doesn't let this token create webhooks (it needs an admin token, and a Pro or Enterprise plan on hosted Invoice Ninja). Changes are checked every 15 minutes instead.", 'invoiceninja' ),
			'unreachable'   => __( "Invoice Ninja can't reach this site's address (it must be public), so changes are checked every 15 minutes instead.", 'invoiceninja' ),
			default         => __( "Webhooks couldn't be set up yet; the plugin tries again. Changes are checked every 15 minutes meanwhile.", 'invoiceninja' ),
		};
	}

	/**
	 * Diagnostics: "Webhooks registered".
	 *
	 * @param array<int, array<string, string>> $checks Checks.
	 * @return array<int, array<string, string>>
	 */
	public function check( array $checks ): array {
		$state = $this->registrar->state();
		$label = __( 'Webhooks registered', 'invoiceninja' );
		if ( 'none' === $this->plugin->credentials->source() ) {
			$status = 'skip';
			$detail = __( 'Skipped until Invoice Ninja is connected.', 'invoiceninja' );
			$action = '';
		} elseif ( $this->registrar->complete() ) {
			$status = 'pass';
			/* translators: %d: number of events */
			$detail = sprintf( __( '%d events reach this site within seconds', 'invoiceninja' ), count( $state['hooks'] ) );
			$action = '';
		} elseif ( 'paused' === $state['reason'] ) {
			$status = 'info';
			$detail = $this->reasonText( 'paused' );
			$action = '';
		} else {
			$status = 'warn';
			$detail = $this->reasonText( 'instant' === $state['mode'] ? 'error' : $state['reason'] );
			$action = 'repair';
		}
		$checks[] = array(
			'id'     => 'webhooks_registered',
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'action' => $action,
		);
		return $checks;
	}

	/**
	 * Repair: create missing webhooks now.
	 *
	 * @param list<string> $done What was done.
	 * @return list<string>
	 */
	public function repair( array $done ): array {
		if ( 'none' === $this->plugin->credentials->source() ) {
			return $done;
		}
		try {
			$state = $this->registrar->sync();
		} catch ( ApiException $e ) {
			$this->plugin->queue->push( self::REGISTER, array(), 60, true );
			$done[] = __( "Couldn't reach Invoice Ninja to check the webhooks; trying again shortly", 'invoiceninja' );
			return $done;
		}
		$done[] = 'instant' === $state['mode'] ? __( 'Checked the webhooks', 'invoiceninja' ) : $this->reasonText( $state['reason'] );
		return $done;
	}
}
