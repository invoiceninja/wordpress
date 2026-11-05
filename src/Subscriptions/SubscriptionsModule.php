<?php
/**
 * Subscriptions: Invoice Ninja subscriptions sold from WordPress.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Core\Capabilities;
use InvoiceNinja\Module;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;
use InvoiceNinja\Webhooks\Events;

/**
 * Pricing Table and Subscribe Button (blocks and shortcodes), the purchase callback and
 * lifecycle events, the ProvisioningRelay, the return page and the Account tab's rows.
 * The features run while Settings → Features → Subscriptions (modules.subs) is on; the
 * safety parts (the callback endpoint, putting subscriptions back on disconnect,
 * deactivation or turning the feature off) always run.
 */
final class SubscriptionsModule implements Module {

	public const RECONNECT = 'invoiceninja_subscription_reconnect';

	public const RESTORE = 'invoiceninja_subscriptions_restore';

	public const OVERDUE = 'invoiceninja_subscriptions_overdue';

	public readonly SubscriptionSync $sync;

	public readonly Configurator $configurator;

	public readonly Callback $callback;

	public readonly State $state;

	public readonly ProvisioningRelay $relay;

	public readonly Lifecycle $lifecycle;

	public readonly Redirects $redirects;

	public readonly Blocks $blocks;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
		$this->sync         = new SubscriptionSync( $plugin );
		$this->configurator = new Configurator( $plugin, $this->sync );
		$this->callback     = new Callback( $plugin, $this->configurator );
		$this->state        = new State( $plugin->options );
		$this->relay        = new ProvisioningRelay( $plugin, $this->configurator );
		$this->lifecycle    = new Lifecycle( $plugin, $this->sync, $this->state, $this->relay );
		$this->redirects    = new Redirects( $plugin, $this->sync, $this->callback, $this->state );
		$this->blocks       = new Blocks( $plugin, $this->sync );
	}

	/**
	 * Whether the feature is on.
	 */
	public function enabled(): bool {
		return (bool) $this->plugin->options->setting( 'modules.subs' );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		$p = $this->plugin;
		add_filter(
			'invoiceninja_setting_defaults',
			static fn( array $d ): array => $d + array(
				'subscriptions' => array(
					'suspend_days' => 7,
				),
			)
		);
		add_action( 'invoiceninja_rest_routes', array( $this, 'routes' ) );

		// Always: the queue handlers and putting Invoice Ninja back as it was.
		$p->queue->handle( Callback::JOB, array( $this->lifecycle, 'handle' ) );
		$p->queue->handle( Callback::FORWARD, array( $this->callback, 'forward' ) );
		$p->queue->handle( ProvisioningRelay::JOB, array( $this->relay, 'deliver' ) );
		$p->queue->handle(
			SubscriptionSync::HOOK,
			function (): void {
				$this->verify( $this->sync->refresh() );
			}
		);
		$p->queue->handle(
			self::RECONNECT,
			function ( array $job ): void {
				$id = Events::cleanId( $job['id'] ?? '' );
				if ( '' !== $id && $this->configurator->get( $id )['connected'] ) {
					$this->configurator->connect( $id );
				}
			}
		);
		$p->queue->handle(
			self::RESTORE,
			function (): void {
				$this->configurator->restoreAll( 30.0 );
			}
		);
		$p->queue->handle( self::OVERDUE, array( $this->lifecycle, 'pollOverdue' ) );

		$restore = function (): void {
			$this->configurator->restoreAll( 8.0 );
		};
		add_action( 'invoiceninja_before_disconnect', $restore );
		add_action( 'deactivate_' . plugin_basename( $p->file ), $restore );
		add_action( 'update_option_invoiceninja_settings', array( $this, 'settingsChanged' ), 10, 2 );
		add_action( 'invoiceninja_company_switched', array( $this, 'forgetCompany' ) );
		add_filter( 'invoiceninja_diagnostics_checks', array( $this->configurator, 'check' ) );
		add_action( 'admin_notices', array( $this, 'restoreNotice' ) );
		add_filter(
			'invoiceninja_repair',
			function ( array $done ): array {
				if ( ! array_filter( $this->configurator->all(), static fn( array $row ): bool => '' !== $row['restore_error'] ) ) {
					return $done;
				}
				$result = $this->configurator->restoreAll( 20.0 );
				$done[] = $result['failed']
					? __( "Some subscriptions' Invoice Ninja settings still couldn't be put back", 'invoiceninja' )
					: __( "Put the subscriptions' original Invoice Ninja settings back", 'invoiceninja' );
				return $done;
			}
		);

		if ( ! $this->enabled() ) {
			add_action(
				'action_scheduler_init',
				function (): void {
					$this->plugin->queue->stopRecurring( SubscriptionSync::HOOK );
				}
			);
			return;
		}
		$p->store->add( 'pricing-table', 'invoiceninja_pricing_table', array( $this->blocks, 'pricingTable' ) );
		$p->store->add( 'subscribe-button', 'invoiceninja_subscribe', array( $this->blocks, 'subscribeButton' ) );
		add_action( 'init', array( $this->blocks, 'registerAssets' ) );
		add_action( 'template_redirect', array( $this->redirects, 'route' ), 5 );
		add_filter( 'invoiceninja_account_subscriptions', array( $this->redirects, 'accountRows' ), 10, 3 );
		add_action(
			'action_scheduler_init',
			function (): void {
				if ( 'none' !== $this->plugin->credentials->source() ) {
					$this->plugin->queue->recurring( SubscriptionSync::HOOK, HOUR_IN_SECONDS );
				}
			}
		);
		add_action(
			'invoiceninja_connected',
			function (): void {
				$this->plugin->queue->push( SubscriptionSync::HOOK, array(), 0, true );
			}
		);
		add_action(
			'invoiceninja_daily_tasks',
			function (): void {
				$this->plugin->queue->push( self::OVERDUE, array(), 0, true );
				$this->relay->kick();
			}
		);
		add_action( 'invoiceninja_webhook_payment_created', array( $this->lifecycle, 'onPayment' ) );
		add_action( 'invoiceninja_webhook_invoice_late', array( $this->lifecycle, 'onLate' ) );
		add_action( 'invoiceninja_admin_assets', array( $this, 'adminAssets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editorAssets' ) );
	}

	/**
	 * REST routes.
	 *
	 * @param Routes $routes Routes.
	 */
	public function routes( Routes $routes ): void {
		// Every method, so a misconfigured method never makes Invoice Ninja block a purchase.
		$routes->add( '/subscription-callback', \WP_REST_Server::ALLMETHODS, array( $this->callback, 'handle' ), 'public' );
		$routes->add( '/subscriptions/return', 'GET', array( $this->redirects, 'returnStatus' ), 'public' );
		$routes->add( '/admin/subscriptions', 'GET', array( $this, 'adminList' ), 'view' );
		$routes->add( '/admin/subscriptions/sync', 'POST', array( $this, 'adminSync' ), 'manage' );
		$routes->add( '/admin/subscriptions/restore', 'POST', array( $this, 'adminRestore' ), 'manage' );
		$routes->add( '/admin/subscriptions/(?P<id>[A-Za-z0-9]+)', 'POST', array( $this, 'adminUpdate' ), 'manage' );
	}

	/**
	 * After a sync: repair stale secrets.
	 *
	 * @param array<string, array<string, mixed>> $items Subscriptions.
	 */
	private function verify( array $items ): void {
		$this->configurator->verify( $items );
	}

	/**
	 * Turning the feature off puts every subscription back.
	 *
	 * @param mixed $before Old settings.
	 * @param mixed $after  New settings.
	 */
	public function settingsChanged( mixed $before, mixed $after ): void {
		$was = is_array( $before ) && ! empty( $before['modules']['subs'] );
		$now = is_array( $after ) && ! empty( $after['modules']['subs'] );
		if ( $was && ! $now ) {
			$this->plugin->queue->push( self::RESTORE, array(), 0, true );
		}
	}

	/**
	 * After a company switch the old subscriptions can't be reached: forget them.
	 */
	public function forgetCompany(): void {
		$left = array();
		foreach ( $this->configurator->all() as $id => $row ) {
			if ( $row['connected'] ) {
				$left[] = '' !== $row['name'] ? $row['name'] : $id;
			}
		}
		if ( $left ) {
			$this->plugin->logger->warning( 'subscriptions', sprintf( 'Switched company: the previous company\'s subscriptions %s still send purchases to this site. Clear their "Webhook URL" in that company.', implode( ', ', $left ) ) );
		}
		// The old company's callbacks are now ignored (still answered "Success", so its buyers aren't blocked).
		$this->plugin->options->delete( 'callback_secret' );
		$this->relay->clear();
		foreach ( array( SubscriptionSync::OPTION, Configurator::OPTION, State::OPTION, Callback::RECENT ) as $option ) {
			$this->plugin->options->delete( $option );
		}
	}

	/**
	 * A loud notice when a subscription couldn't be put back.
	 */
	public function restoreNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$failed = array();
		foreach ( $this->configurator->all() as $id => $row ) {
			if ( '' !== $row['restore_error'] ) {
				$failed[] = '' !== $row['name'] ? $row['name'] : $id;
			}
		}
		if ( ! $failed ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Invoice Ninja: subscription settings not restored.', 'invoiceninja' ),
			/* translators: %s: subscription names */
			esc_html( sprintf( __( 'Purchases of %s may still be sent to this site. Try again, or clear "Webhook URL" on the subscription in Invoice Ninja.', 'invoiceninja' ), implode( ', ', $failed ) ) ),
			esc_url( admin_url( 'admin.php?page=invoiceninja-settings&tab=subscriptions' ) ),
			esc_html__( 'Review', 'invoiceninja' )
		);
	}

	/**
	 * GET /admin/subscriptions.
	 */
	public function adminList(): \WP_REST_Response {
		$cache  = $this->sync->cache();
		$config = $this->configurator->all();
		$items  = array();
		foreach ( $cache['items'] as $id => $sub ) {
			if ( $sub['archived'] && empty( $config[ $id ]['connected'] ) ) {
				continue;
			}
			$row     = $config[ $id ] ?? $this->configurator->get( (string) $id );
			$url     = (string) ( $sub['webhook_configuration']['post_purchase_url'] ?? '' );
			$items[] = array(
				'id'                 => (string) $id,
				'name'               => (string) $sub['name'],
				'price'              => $this->plugin->statics->money( (float) $sub['price'] ),
				'per'                => $sub['recurring'] ? Frequency::per( (int) $sub['frequency_id'] ) : '',
				'frequency_id'       => (int) $sub['frequency_id'],
				'trial_days'         => $sub['trial_enabled'] ? (int) $sub['trial_days'] : 0,
				'allow_cancellation' => (bool) $sub['allow_cancellation'],
				'allow_plan_changes' => (bool) $sub['allow_plan_changes'],
				'connected'          => $row['connected'],
				'points_here'        => Configurator::pointsHere( (array) $sub['webhook_configuration'], $row['callback'] ),
				'callback'           => '' === $url ? 'none' : ( Configurator::pointsHere( (array) $sub['webhook_configuration'], $row['callback'] ) ? 'here' : ( Configurator::isWordPress( $url ) ? 'other_wordpress' : 'other' ) ),
				'callback_host'      => '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '',
				'relay_url'          => $row['relay_url'],
				'restore_error'      => $row['restore_error'],
				'archived'           => (bool) $sub['archived'],
				'shortcode'          => '[invoiceninja_subscribe id="' . $id . '"]',
			);
		}
		return new \WP_REST_Response(
			array(
				'items'          => $items,
				'synced_at'      => $cache['at'],
				'error'          => $cache['error'],
				'connected'      => 'none' !== $this->plugin->credentials->source(),
				'writes_allowed' => $this->plugin->environment->writesAllowed(),
				'enabled'        => $this->enabled(),
				'callback_url'   => Configurator::callbackUrl(),
				'return_url'     => Configurator::returnUrl(),
				'relay_secret'   => current_user_can( 'manage_options' ) ? $this->plugin->secrets->get( 'relay_secret' ) : '',
				'events'         => array_slice( array_reverse( $this->lifecycle->events() ), 0, 10 ),
			)
		);
	}

	/**
	 * POST /admin/subscriptions/sync.
	 */
	public function adminSync(): \WP_REST_Response {
		try {
			$this->verify( $this->sync->refresh() );
		} catch ( ApiException $e ) {
			return Routes::fail( $e->kind->value, $e->kind->title() . '. ' . $e->kind->fix(), 502 );
		}
		return $this->adminList();
	}

	/**
	 * POST /admin/subscriptions/restore: try failed restores again.
	 */
	public function adminRestore(): \WP_REST_Response {
		$result = $this->configurator->restoreAll( 20.0 );
		$list   = $this->adminList();
		$data   = (array) $list->get_data();
		return new \WP_REST_Response( $data + array( 'restore' => $result ) );
	}

	/**
	 * POST /admin/subscriptions/<id> {connected?, relay_url?}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function adminUpdate( \WP_REST_Request $request ): \WP_REST_Response {
		$id = Events::cleanId( $request->get_param( 'id' ) );
		if ( null === $this->sync->find( $id ) && ! $this->configurator->get( $id )['connected'] ) {
			return Routes::fail( 'unknown_subscription', __( 'Unknown subscription. Sync and try again.', 'invoiceninja' ), 404 );
		}
		$message = '';
		if ( null !== $request->get_param( 'relay_url' ) ) {
			$result = $this->configurator->setRelay( $id, (string) $request->get_param( 'relay_url' ) );
			if ( ! $result['ok'] ) {
				return Routes::fail( $result['code'], $result['message'], 400 );
			}
			$message = $result['message'];
		}
		if ( null !== $request->get_param( 'connected' ) ) {
			$on = (bool) filter_var( $request->get_param( 'connected' ), FILTER_VALIDATE_BOOLEAN );
			if ( $on ) {
				$result = $this->configurator->connect( $id );
				if ( ! $result['ok'] ) {
					return Routes::fail( $result['code'], $result['message'], 'other_wordpress' === $result['code'] || 'paused' === $result['code'] ? 409 : 502 );
				}
				$message = $result['message'];
			} elseif ( ! $this->configurator->disconnect( $id ) ) {
				return Routes::fail( 'restore_failed', __( "Couldn't put the subscription's original settings back in Invoice Ninja. Try again.", 'invoiceninja' ), 502 );
			} else {
				$message = __( "The subscription's original settings are back in Invoice Ninja.", 'invoiceninja' );
			}
		}
		$data            = (array) $this->adminList()->get_data();
		$data['message'] = $message;
		return new \WP_REST_Response( $data );
	}

	/**
	 * The Settings → Subscriptions tab.
	 *
	 * @param string $screen Screen.
	 */
	public function adminAssets( string $screen ): void {
		if ( 'settings' !== $screen ) {
			return;
		}
		wp_enqueue_script( 'invoiceninja-subscriptions-admin', $this->plugin->url() . 'assets/subscriptions/admin.js', array( 'invoiceninja-admin' ), INVOICENINJA_VERSION, true );
		wp_set_script_translations( 'invoiceninja-subscriptions-admin', 'invoiceninja' );
	}

	/**
	 * Block editor settings for the two blocks.
	 */
	public function editorAssets(): void {
		wp_enqueue_script( 'invoiceninja-subscriptions-editor', $this->plugin->url() . 'assets/subscriptions/editor.js', array( 'invoiceninja-blocks' ), INVOICENINJA_VERSION, true );
		wp_set_script_translations( 'invoiceninja-subscriptions-editor', 'invoiceninja' );
		wp_add_inline_script( 'invoiceninja-subscriptions-editor', 'window.invoiceNinjaSubscriptionsEditor = ' . wp_json_encode( array( 'canList' => current_user_can( Capabilities::VIEW ) ) ) . ';', 'before' );
	}

	/**
	 * Uninstall: put every connected subscription back (called from uninstall.php).
	 */
	public static function uninstall(): void {
		Configurator::uninstall();
	}
}
