<?php
/**
 * Points an Invoice Ninja subscription's purchase callbacks at this site, and back.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Connection\ConnectionState;
use InvoiceNinja\Connection\Credentials;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Plugin;
use InvoiceNinja\Webhooks\Events;

/**
 * Opting a subscription in sets its webhook_configuration: post_purchase_url (our callback),
 * post_purchase_rest_method "post" (Invoice Ninja calls $client->{method}, so it must be
 * set), post_purchase_headers (our secret) and return_url (our return page). The values it
 * had are stored and put back on opt-out, disconnect, deactivation and uninstall. If the
 * subscription already reports to another WordPress site, opting in is refused, so a
 * staging copy can never take over the live site's subscriptions.
 */
final class Configurator {

	public const OPTION = 'subscriptions_config';

	public const HEADER = 'X-Invoiceninja-Secret';

	public const DEFAULT_STEPS = 'cart,auth.login-or-register';

	/**
	 * Constructor.
	 *
	 * @param Plugin           $plugin Plugin.
	 * @param SubscriptionSync $sync   Subscriptions.
	 */
	public function __construct( private Plugin $plugin, private SubscriptionSync $sync ) {}

	/**
	 * Where Invoice Ninja sends purchase callbacks.
	 */
	public static function callbackUrl(): string {
		return rest_url( 'invoiceninja/v1/subscription-callback' );
	}

	/**
	 * Where buyers come back to after a purchase.
	 */
	public static function returnUrl(): string {
		return add_query_arg( 'invoiceninja', 'subscribed', home_url( '/' ) );
	}

	/**
	 * Per-subscription settings: connected, relay_url, original, at, restore_error, name, and
	 * callback (the URL this site set on it, so it's still recognised after an address change).
	 *
	 * @return array<string, array{connected: bool, relay_url: string, original: array<string, mixed>, at: int, restore_error: string, name: string, callback: string}>
	 */
	public function all(): array {
		$saved = $this->plugin->options->get( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $id => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'connected'     => ! empty( $row['connected'] ),
				'relay_url'     => (string) ( $row['relay_url'] ?? '' ),
				'original'      => is_array( $row['original'] ?? null ) ? $row['original'] : array(),
				'at'            => (int) ( $row['at'] ?? 0 ),
				'restore_error' => (string) ( $row['restore_error'] ?? '' ),
				'name'          => (string) ( $row['name'] ?? '' ),
				'callback'      => (string) ( $row['callback'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * One subscription's settings.
	 *
	 * @param string $id Subscription id.
	 * @return array{connected: bool, relay_url: string, original: array<string, mixed>, at: int, restore_error: string, name: string, callback: string}
	 */
	public function get( string $id ): array {
		return $this->all()[ $id ] ?? array(
			'connected'     => false,
			'relay_url'     => '',
			'original'      => array(),
			'at'            => 0,
			'restore_error' => '',
			'name'          => '',
			'callback'      => '',
		);
	}

	/**
	 * Save one subscription's settings (an empty, disconnected row is removed).
	 *
	 * @param string               $id  Subscription id.
	 * @param array<string, mixed> $row Fields to change.
	 */
	private function put( string $id, array $row ): void {
		$all        = $this->all();
		$all[ $id ] = array_merge( $this->get( $id ), $row );
		if ( ! $all[ $id ]['connected'] && '' === $all[ $id ]['relay_url'] && '' === $all[ $id ]['restore_error'] ) {
			unset( $all[ $id ] );
		}
		$this->plugin->options->set( self::OPTION, $all );
	}

	/**
	 * Whether a URL is a WordPress REST or AJAX endpoint.
	 *
	 * @param string $url URL.
	 */
	public static function isWordPress( string $url ): bool {
		return str_contains( $url, '/wp-json/' ) || str_contains( $url, 'rest_route=' ) || str_contains( $url, '/wp-admin/admin-ajax.php' );
	}

	/**
	 * Work out the new webhook_configuration (pure, for tests).
	 *
	 * @param array<string, mixed> $current  The subscription's webhook_configuration now.
	 * @param string               $callback Our callback URL.
	 * @param string               $back     Our return page.
	 * @param string               $secret   Callback secret.
	 * @return array{ok: bool, error: string, host: string, config: array<string, mixed>, original: array<string, mixed>|null}
	 */
	public static function plan( array $current, string $callback, string $back, #[\SensitiveParameter] string $secret, string $stored = '' ): array {
		$url    = trim( (string) ( $current['post_purchase_url'] ?? '' ) );
		$ours   = self::ours( $url, $callback, $stored );
		$result = array(
			'ok'       => false,
			'error'    => '',
			'host'     => '',
			'config'   => array(),
			'original' => null,
		);
		if ( '' !== $url && ! $ours && self::isWordPress( $url ) ) {
			$result['error'] = 'other_wordpress';
			$result['host']  = (string) wp_parse_url( $url, PHP_URL_HOST );
			return $result;
		}
		$result['ok']       = true;
		$result['original'] = $ours ? null : $current; // Already ours (maybe at an older address): keep the stored original.
		$result['config']   = array_merge(
			$current,
			array(
				'post_purchase_url'         => $callback,
				'post_purchase_rest_method' => 'post',
				'post_purchase_headers'     => array( self::HEADER => $secret ),
				'return_url'                => $back,
			)
		);
		return $result;
	}

	/**
	 * Whether a subscription's configuration in Invoice Ninja points at this site.
	 *
	 * @param array<string, mixed> $config webhook_configuration.
	 */
	public static function pointsHere( array $config, string $stored = '' ): bool {
		return self::ours( (string) ( $config['post_purchase_url'] ?? '' ), self::callbackUrl(), $stored );
	}

	/**
	 * Whether a callback URL is this site's (pure, for tests): our callback now, the same
	 * address over http/https or with/without "www.", or the URL this site set on the
	 * subscription before (say, before a permalink change) while it's under this site's
	 * address. A copy of the site elsewhere never counts, so staging can't take over live.
	 *
	 * @param string $url      The subscription's post_purchase_url.
	 * @param string $callback Our callback URL now.
	 * @param string $stored   The URL this site last set on it, if any.
	 */
	public static function ours( string $url, string $callback, string $stored = '' ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}
		if ( $url === $callback || self::same( $url, $callback ) ) {
			return true;
		}
		$site = rtrim( \InvoiceNinja\Core\Environment::identity( (string) home_url() ), '/' );
		$here = \InvoiceNinja\Core\Environment::identity( $url );
		return '' !== $stored && self::same( $url, $stored ) && '' !== $site && ( $here === $site || str_starts_with( $here, $site . '/' ) );
	}

	/**
	 * Whether two URLs differ only in http/https and "www.".
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 */
	private static function same( string $a, string $b ): bool {
		$query = static fn( string $u ): string => (string) wp_parse_url( $u, PHP_URL_QUERY );
		return \InvoiceNinja\Core\Environment::identity( $a ) === \InvoiceNinja\Core\Environment::identity( $b ) && $query( $a ) === $query( $b );
	}

	/**
	 * Opt a subscription in.
	 *
	 * @param string $id Subscription id.
	 * @return array{ok: bool, code: string, message: string}
	 */
	public function connect( string $id ): array {
		$p = $this->plugin;
		if ( ! $p->environment->writesAllowed() ) {
			return self::result( false, 'paused', __( 'This site looks like a copy, so it may not change Invoice Ninja. Allow orders from this site first (Status → Tools).', 'invoiceninja' ) );
		}
		try {
			$client  = $p->api();
			$current = $client->get( 'subscriptions/' . rawurlencode( $id ), array(), 8.0 )->data();
			$plan    = self::plan( is_array( $current['webhook_configuration'] ?? null ) ? $current['webhook_configuration'] : array(), self::callbackUrl(), self::returnUrl(), $p->secrets->get( 'callback_secret' ), $this->get( $id )['callback'] );
			if ( ! $plan['ok'] ) {
				return self::result(
					false,
					'other_wordpress',
					/* translators: %s: host name */
					sprintf( __( 'This subscription already sends its purchases to another WordPress site (%s). Clear "Webhook URL" on the subscription in Invoice Ninja first if this site should take over.', 'invoiceninja' ), $plan['host'] )
				);
			}
			$this->write( $client, $id, $current, $plan['config'] );
		} catch ( ApiException $e ) {
			if ( self::urlRefused( $e ) ) {
				// Invoice Ninja checks the callback address: a private or local one is "Invalid URL".
				return self::result( false, 'unreachable', __( "Invoice Ninja can't reach this site's address, so it can't report purchases here. The site needs a public address that Invoice Ninja can reach.", 'invoiceninja' ) );
			}
			return self::result( false, $e->kind->value, $e->kind->title() . '. ' . $e->kind->fix() );
		}
		$saved = $this->get( $id );
		$this->put(
			$id,
			array(
				'connected'     => true,
				'original'      => null === $plan['original'] ? $saved['original'] : $plan['original'],
				'at'            => time(),
				'restore_error' => '',
				'name'          => sanitize_text_field( (string) ( $current['name'] ?? '' ) ),
				'callback'      => self::callbackUrl(),
			)
		);
		$p->logger->info( 'subscriptions', sprintf( 'Subscription "%s" now reports purchases to this site', sanitize_text_field( (string) ( $current['name'] ?? $id ) ) ) );
		try {
			$this->sync->refresh();
		} catch ( ApiException $ignored ) {
			unset( $ignored ); // The hourly refresh catches up.
		}
		return self::result( true, 'connected', __( 'Purchases of this subscription now reach this site.', 'invoiceninja' ) );
	}

	/**
	 * Put the original configuration back.
	 *
	 * @param string $id     Subscription id.
	 * @param float  $budget Seconds.
	 * @return bool Whether it's back (or there was nothing to do).
	 */
	public function disconnect( string $id, float $budget = 8.0 ): bool {
		$row = $this->get( $id );
		if ( ! $row['connected'] && '' === $row['restore_error'] ) {
			return true;
		}
		try {
			$restored = self::restoreWith( $this->plugin->api(), $id, $row['original'], $budget, $row['callback'] );
		} catch ( ApiException $e ) {
			if ( ErrorKind::NotFound === $e->kind ) {
				$this->put(
					$id,
					array(
						'connected'     => false,
						'restore_error' => '',
					)
				); // Deleted in Invoice Ninja: nothing to put back.
				return true;
			}
			$this->put(
				$id,
				array(
					'connected'     => false,
					'restore_error' => $e->kind->title(),
				)
			);
			$this->plugin->logger->error( 'subscriptions', sprintf( 'Could not restore the Invoice Ninja settings of subscription "%s": %s', $row['name'] ? $row['name'] : $id, $e->kind->title() ), array( 'technical' => $e->technical ) );
			return false;
		}
		$this->put(
			$id,
			array(
				'connected'     => false,
				'restore_error' => '',
				'original'      => array(),
				'callback'      => '',
			)
		);
		if ( $restored ) {
			$this->plugin->logger->info( 'subscriptions', sprintf( 'Restored the original settings of subscription "%s" in Invoice Ninja', $row['name'] ? $row['name'] : $id ) );
		} else {
			// Pointed elsewhere by an admin in Invoice Ninja (not at an old address of this site): that change wins.
			$this->plugin->logger->warning( 'subscriptions', sprintf( 'Subscription "%s" had been pointed elsewhere in Invoice Ninja; left as it is', $row['name'] ? $row['name'] : $id ) );
		}
		return true;
	}

	/**
	 * Restore every connected subscription (disconnect, deactivation, module off).
	 *
	 * @param float $budget Seconds for all of them.
	 * @return array{restored: int, failed: list<string>}
	 */
	public function restoreAll( float $budget = 10.0 ): array {
		$deadline = microtime( true ) + $budget;
		$result   = array(
			'restored' => 0,
			'failed'   => array(),
		);
		foreach ( $this->all() as $id => $row ) {
			if ( ! $row['connected'] && '' === $row['restore_error'] ) {
				continue;
			}
			$left = $deadline - microtime( true );
			if ( $left < 1.5 || 'none' === $this->plugin->credentials->source() || ! $this->plugin->credentials->hasToken() ) {
				$this->put(
					(string) $id,
					array(
						'connected'     => false,
						'restore_error' => __( 'Not attempted in time', 'invoiceninja' ),
					)
				);
				$result['failed'][] = (string) $id;
				continue;
			}
			if ( $this->disconnect( (string) $id, min( 6.0, $left ) ) ) {
				++$result['restored'];
			} else {
				$result['failed'][] = (string) $id;
			}
		}
		return $result;
	}

	/**
	 * PUT the configuration. Invoice Ninja's update requires `steps`, so the current value goes too.
	 *
	 * @param HttpClient           $client  Client.
	 * @param string               $id      Subscription id.
	 * @param array<string, mixed> $current Subscription now.
	 * @param array<string, mixed> $config  webhook_configuration.
	 * @param float                $budget  Seconds.
	 * @throws ApiException On failure.
	 */
	private function write( HttpClient $client, string $id, array $current, array $config, float $budget = 8.0 ): void {
		self::putConfig( $client, $id, $current, $config, $budget );
	}

	/**
	 * Whether Invoice Ninja refused the callback or return address (e.g. a private one:
	 * 422 webhook_configuration.post_purchase_url "Invalid URL").
	 *
	 * @param ApiException $e Error.
	 */
	public static function urlRefused( ApiException $e ): bool {
		if ( ErrorKind::Validation !== $e->kind ) {
			return false;
		}
		foreach ( array_keys( $e->errors ) as $field ) {
			if ( str_starts_with( (string) $field, 'webhook_configuration' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * PUT a subscription's webhook_configuration.
	 *
	 * @param HttpClient           $client  Client.
	 * @param string               $id      Subscription id.
	 * @param array<string, mixed> $current Subscription now.
	 * @param array<string, mixed> $config  webhook_configuration.
	 * @param float                $budget  Seconds.
	 * @throws ApiException On failure.
	 */
	private static function putConfig( HttpClient $client, string $id, array $current, array $config, float $budget ): void {
		$steps = trim( (string) ( $current['steps'] ?? '' ) );
		$client->put(
			'subscriptions/' . rawurlencode( $id ),
			array(
				'webhook_configuration' => (object) $config,
				'steps'                 => '' !== $steps ? $steps : self::DEFAULT_STEPS,
			),
			array(),
			$budget
		);
	}

	/**
	 * Put the original back, but only if the subscription still points at this site (an
	 * admin may have changed it in Invoice Ninja since; that change wins).
	 *
	 * @param HttpClient           $client   Client.
	 * @param string               $id       Subscription id.
	 * @param array<string, mixed> $original Original webhook_configuration.
	 * @param float                $budget   Seconds.
	 * @param string               $stored   The callback URL this site set (it still counts after an http → https change).
	 * @return bool True when restored, false when it no longer pointed here.
	 * @throws ApiException On failure.
	 */
	private static function restoreWith( HttpClient $client, string $id, array $original, float $budget, string $stored = '' ): bool {
		$current = $client->get( 'subscriptions/' . rawurlencode( $id ), array(), $budget / 2 )->data();
		$config  = is_array( $current['webhook_configuration'] ?? null ) ? $current['webhook_configuration'] : array();
		if ( ! self::pointsHere( $config, $stored ) ) {
			return false;
		}
		$original += array(
			'post_purchase_url'         => '',
			'post_purchase_rest_method' => 'post',
			'post_purchase_headers'     => array(),
			'return_url'                => '',
		);
		self::putConfig( $client, $id, $current, $original, $budget / 2 );
		return true;
	}

	/**
	 * Set or clear the provisioning relay URL.
	 *
	 * @param string $id  Subscription id.
	 * @param string $url URL (https), or empty.
	 * @return array{ok: bool, code: string, message: string}
	 */
	public function setRelay( string $id, string $url ): array {
		$url = trim( $url );
		if ( '' !== $url && ! Redirects::webUrl( $url ) ) {
			return self::result( false, 'invalid_url', __( 'Enter a full web address, like https://panel.example.com/hooks/invoiceninja.', 'invoiceninja' ) );
		}
		$this->put( $id, array( 'relay_url' => esc_url_raw( $url ) ) );
		return self::result( true, 'saved', '' !== $url ? __( 'Provisioning updates will be sent to this address.', 'invoiceninja' ) : __( 'Provisioning updates turned off.', 'invoiceninja' ) );
	}

	/**
	 * Mark subscriptions whose configuration changed in Invoice Ninja, and repair a stale
	 * secret, after each sync.
	 *
	 * @param array<string, array<string, mixed>> $items Subscriptions by id.
	 */
	public function verify( array $items ): void {
		$secret = $this->plugin->secrets->get( 'callback_secret' );
		foreach ( $this->all() as $id => $row ) {
			if ( ! $row['connected'] || ! isset( $items[ $id ] ) ) {
				continue;
			}
			$config = (array) $items[ $id ]['webhook_configuration'];
			if ( ! self::pointsHere( $config, $row['callback'] ) ) {
				continue; // Shown in Diagnostics; the admin decides.
			}
			$sent = (string) ( ( is_array( $config['post_purchase_headers'] ?? null ) ? $config['post_purchase_headers'] : array() )[ self::HEADER ] ?? '' );
			// An old address of this site (http → https, a permalink change) is moved to the new one too.
			if ( self::callbackUrl() !== ( $config['post_purchase_url'] ?? '' ) || ! hash_equals( $secret, $sent ) || 'post' !== ( $config['post_purchase_rest_method'] ?? '' ) || self::returnUrl() !== ( $config['return_url'] ?? '' ) ) {
				$this->plugin->queue->push( 'invoiceninja_subscription_reconnect', array( 'id' => (string) $id ), 0, true );
			}
		}
	}

	/**
	 * Diagnostics check.
	 *
	 * @param array<int, array<string, string>> $checks Checks.
	 * @return array<int, array<string, string>>
	 */
	public function check( array $checks ): array {
		$rows      = $this->all();
		$connected = array_filter( $rows, static fn( array $r ): bool => $r['connected'] );
		$failed    = array_filter( $rows, static fn( array $r ): bool => '' !== $r['restore_error'] );
		$items     = $this->sync->all();
		$drifted   = array();
		foreach ( $connected as $id => $row ) {
			if ( isset( $items[ $id ] ) && ! self::pointsHere( (array) $items[ $id ]['webhook_configuration'], $row['callback'] ) ) {
				$drifted[] = $row['name'] ? $row['name'] : $id;
			}
		}
		$label = __( 'Subscription callbacks', 'invoiceninja' );
		if ( $failed ) {
			$status = 'fail';
			/* translators: %s: subscription names */
			$detail = sprintf( __( "Couldn't put back the Invoice Ninja settings of: %s. Purchases of these may still be sent to this site.", 'invoiceninja' ), implode( ', ', array_map( static fn( array $r, string $id ): string => $r['name'] ? $r['name'] : $id, $failed, array_keys( $failed ) ) ) );
		} elseif ( $drifted ) {
			$status = 'warn';
			/* translators: %s: subscription names */
			$detail = sprintf( __( 'These subscriptions were changed in Invoice Ninja and no longer report to this site: %s.', 'invoiceninja' ), implode( ', ', $drifted ) );
		} elseif ( $connected ) {
			$status = 'pass';
			/* translators: %d: number of subscriptions */
			$detail = sprintf( _n( '%d subscription reports purchases to this site', '%d subscriptions report purchases to this site', count( $connected ), 'invoiceninja' ), count( $connected ) );
		} else {
			$status = 'info';
			$detail = __( 'No subscription reports purchases to this site yet (Settings → Subscriptions).', 'invoiceninja' );
		}
		$checks[] = array(
			'id'     => 'subscriptions',
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'action' => $failed ? 'repair' : '',
		);
		return $checks;
	}

	/**
	 * A result array.
	 *
	 * @param bool   $ok      Ok.
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array{ok: bool, code: string, message: string}
	 */
	private static function result( bool $ok, string $code, string $message ): array {
		return array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Uninstall: restore every connected subscription without booting the plugin.
	 */
	public static function uninstall(): void {
		$options = new Options();
		$saved   = $options->get( self::OPTION, array() );
		if ( ! is_array( $saved ) || ! $saved ) {
			return;
		}
		try {
			$credentials = new Credentials( $options, new Secrets( $options ) );
			if ( 'none' === $credentials->source() || null === $credentials->token() ) {
				return;
			}
			$client   = $credentials->client( ( new ConnectionState( $options ) )->allowsPrivate( $credentials->url() ) );
			$deadline = microtime( true ) + 15.0;
			foreach ( $saved as $id => $row ) {
				if ( ! is_array( $row ) || ( empty( $row['connected'] ) && empty( $row['restore_error'] ) ) || microtime( true ) > $deadline - 2 ) {
					continue;
				}
				try {
					self::restoreWith( $client, Events::cleanId( $id ), is_array( $row['original'] ?? null ) ? $row['original'] : array(), 4.0, (string) ( $row['callback'] ?? '' ) );
				} catch ( ApiException $ignored ) {
					unset( $ignored ); // Best effort while uninstalling.
				}
			}
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
