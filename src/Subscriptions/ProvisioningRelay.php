<?php
/**
 * Sends lifecycle events to a provisioning system (a hosting control panel, a licence server).
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\RetryPolicy;
use InvoiceNinja\Plugin;

/**
 * Optional, per subscription. Each event is POSTed as JSON (Payload, version 1) and signed:
 * X-Invoiceninja-Signature = sha256=HMAC-SHA256("<timestamp>.<body>", relay secret) and
 * X-Invoiceninja-Timestamp. Anything but a 2xx is retried (from one minute up to four hours,
 * eight attempts), and every delivery is logged.
 *
 * Events wait in an outbox: one option row per event, named by client ("lane") and sequence,
 * so the queue job only carries the lane (Action Scheduler refuses arguments over 8,000
 * characters) and nothing is lost if a job can't be scheduled. Each client's events are sent
 * one at a time, oldest first: a later event waits while an earlier one is being retried, so a
 * receiver never gets "suspended" after the "resumed" that followed it.
 */
final class ProvisioningRelay {

	public const JOB = 'invoiceninja_subscription_relay';

	/** Option key prefix of outbox rows: subscriptions_relay_<lane>_<sequence>. */
	public const ROW = 'subscriptions_relay_';

	/** The last sequence number handed out. */
	public const SEQUENCE = 'subscriptions_relay_seq';

	private const LOCK = 'subscriptions_relaylock_';

	/** Deliveries per job run before it hands over to a new run. */
	private const PER_RUN = 25;

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin       Plugin.
	 * @param Configurator $configurator Configurator.
	 */
	public function __construct( private Plugin $plugin, private Configurator $configurator ) {}

	/**
	 * The next event sequence number (increases with every event on this site).
	 */
	public function nextSequence(): int {
		return $this->plugin->options->increment( self::SEQUENCE );
	}

	/**
	 * The lane of an event: one per client, so a client's events go out in order.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	public static function lane( array $payload ): string {
		$client = is_array( $payload['client'] ?? null ) ? (string) ( $payload['client']['id'] ?? '' ) : '';
		return substr( md5( 'client|' . $client ), 0, 12 );
	}

	/**
	 * The outbox row key of an event.
	 *
	 * @param string $lane     Lane.
	 * @param int    $sequence Sequence.
	 */
	public static function rowKey( string $lane, int $sequence ): string {
		return self::ROW . $lane . '_' . str_pad( (string) max( 0, $sequence ), 12, '0', STR_PAD_LEFT );
	}

	/**
	 * Put an event in the outbox when the subscription has a relay URL, and wake its lane.
	 *
	 * @param string               $subscription Subscription id.
	 * @param array<string, mixed> $payload      Payload.
	 */
	public function queue( string $subscription, array $payload ): void {
		if ( '' === $this->configurator->get( $subscription )['relay_url'] ) {
			return;
		}
		$sequence = (int) ( $payload['sequence'] ?? 0 );
		$sequence = $sequence > 0 ? $sequence : $this->nextSequence();
		$lane     = self::lane( $payload );
		$this->plugin->options->set(
			self::rowKey( $lane, $sequence ),
			array(
				'subscription' => $subscription,
				'payload'      => $payload,
				'attempts'     => 0,
				'next_at'      => 0,
			)
		);
		$this->wake( $lane );
	}

	/**
	 * Schedule a lane's delivery job (once; a waiting job already covers it).
	 *
	 * @param string $lane  Lane.
	 * @param int    $delay Seconds.
	 */
	private function wake( string $lane, int $delay = 0 ): void {
		try {
			$this->plugin->queue->push( self::JOB, array( 'lane' => $lane ), max( 0, $delay ), true );
		} catch ( \Throwable $e ) {
			// The event is in the outbox; the daily check schedules it again.
			$this->plugin->logger->error( 'subscriptions', 'Could not schedule a provisioning delivery: ' . $e->getMessage() );
		}
	}

	/**
	 * Daily: wake every lane with events waiting (in case a job was lost).
	 */
	public function kick(): void {
		$lanes = array();
		foreach ( $this->rows( '' ) as $name ) {
			if ( preg_match( '/^' . preg_quote( self::ROW, '/' ) . '([0-9a-f]{12})_\d{12}$/', $name, $m ) ) {
				$lanes[ $m[1] ] = true;
			}
		}
		foreach ( array_keys( $lanes ) as $lane ) {
			$this->wake( (string) $lane );
		}
	}

	/**
	 * Drop every waiting event (company switch).
	 */
	public function clear(): void {
		foreach ( $this->rows( '' ) as $name ) {
			$this->plugin->options->delete( $name );
		}
	}

	/**
	 * The body and headers of one delivery (for tests and the docs).
	 *
	 * @param array<string, mixed> $payload   Payload.
	 * @param string               $secret    Relay secret.
	 * @param int                  $timestamp Unix time.
	 * @return array{body: string, headers: array<string, string>}
	 */
	public static function request( array $payload, #[\SensitiveParameter] string $secret, int $timestamp ): array {
		$body = (string) wp_json_encode( $payload + array( 'sent_at' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) ), JSON_UNESCAPED_SLASHES );
		return array(
			'body'    => $body,
			'headers' => array(
				'Content-Type'          => 'application/json',
				Signature::HEADER       => Signature::sign( $timestamp, $body, $secret ),
				Signature::TIMESTAMP    => (string) $timestamp,
				'X-Invoiceninja-Event'  => (string) ( $payload['event'] ?? '' ),
				'X-Invoiceninja-Id'     => (string) ( $payload['id'] ?? '' ),
				'X-Invoiceninja-Source' => (string) home_url( '/' ),
			),
		);
	}

	/**
	 * What to do after a delivery attempt (pure, for tests): "done", "retry" with a delay, or
	 * "give_up" when it was the last attempt.
	 *
	 * @param bool $delivered Whether the receiver answered 2xx.
	 * @param int  $attempts  Attempts so far, this one included.
	 * @return array{0: string, 1: int}
	 */
	public static function next( bool $delivered, int $attempts ): array {
		if ( $delivered ) {
			return array( 'done', 0 );
		}
		$delay = RetryPolicy::delay( $attempts );
		return null === $delay ? array( 'give_up', 0 ) : array( 'retry', $delay );
	}

	/**
	 * Queue handler: deliver a lane's waiting events, oldest first, stopping at the first one
	 * that has to wait for a retry.
	 *
	 * @param array<string, mixed> $job     lane (or, from older versions, subscription + payload).
	 * @param int                  $attempt Attempt (unused: each event counts its own).
	 */
	public function deliver( array $job, int $attempt = 1 ): void {
		unset( $attempt );
		if ( ! isset( $job['lane'] ) && is_array( $job['payload'] ?? null ) ) {
			$this->queue( (string) ( $job['subscription'] ?? '' ), $job['payload'] ); // A job queued before the outbox existed.
			return;
		}
		$lane = (string) ( $job['lane'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f]{12}$/', $lane ) ) {
			return;
		}
		if ( ! $this->plugin->environment->writesAllowed() ) {
			// A staging copy has the live relay URL and secret: its events wait in the outbox
			// (the daily check wakes them) until orders from this site are allowed.
			return;
		}
		if ( ! $this->lock( $lane ) ) {
			$this->wake( $lane, 30 ); // Another run is sending this lane; look again shortly.
			return;
		}
		try {
			$this->drain( $lane );
		} finally {
			$this->plugin->options->delete( self::LOCK . $lane );
		}
	}

	/**
	 * Send a lane's events in order.
	 *
	 * @param string $lane Lane.
	 */
	private function drain( string $lane ): void {
		$deadline = microtime( true ) + 60;
		for ( $sent = 0; $sent < self::PER_RUN; $sent++ ) {
			$names = $this->rows( $lane, 1 );
			if ( ! $names ) {
				return;
			}
			$key   = $names[0];
			$entry = $this->plugin->options->get( $key );
			if ( ! is_array( $entry ) || ! is_array( $entry['payload'] ?? null ) ) {
				$this->plugin->options->delete( $key );
				continue;
			}
			$wait = (int) ( $entry['next_at'] ?? 0 ) - time();
			if ( $wait > 0 ) {
				$this->wake( $lane, $wait );
				return;
			}
			if ( microtime( true ) > $deadline ) {
				break;
			}
			$subscription = (string) ( $entry['subscription'] ?? '' );
			$url          = $this->configurator->get( $subscription )['relay_url'];
			if ( '' === $url ) {
				$this->plugin->options->delete( $key ); // The relay was turned off for this plan.
				continue;
			}
			$attempts         = (int) ( $entry['attempts'] ?? 0 ) + 1;
			$error            = $this->send( $url, $entry['payload'], $subscription, $attempts );
			[ $what, $delay ] = self::next( null === $error, $attempts );
			if ( 'done' === $what ) {
				$this->plugin->options->delete( $key );
				continue;
			}
			if ( 'give_up' === $what ) {
				$this->plugin->options->delete( $key );
				$this->plugin->logger->log( LogLevel::Error, 'subscriptions', sprintf( 'Gave up sending "%s" to the provisioning system after %d attempts: %s', (string) ( $entry['payload']['event'] ?? '' ), $attempts, (string) $error ), array( 'id' => (string) ( $entry['payload']['id'] ?? '' ) ), 'subscription', $subscription );
				/** This action is documented in src/Core/Queue.php. */
				do_action( 'invoiceninja_job_failed', self::JOB, $entry['payload'], new ApiException( ErrorKind::Server, (string) $error ) );
				continue;
			}
			$entry['attempts'] = $attempts;
			$entry['next_at']  = time() + $delay;
			$this->plugin->options->set( $key, $entry );
			$this->plugin->logger->log( LogLevel::Warning, 'subscriptions', sprintf( 'Sending "%s" to the provisioning system failed (attempt %d); later events for this customer wait for it: %s', (string) ( $entry['payload']['event'] ?? '' ), $attempts, (string) $error ), array(), 'subscription', $subscription );
			$this->wake( $lane, $delay );
			return;
		}
		$this->wake( $lane ); // More waiting: carry on in a new run.
	}

	/**
	 * POST one event; null when the receiver accepted it, otherwise what went wrong.
	 *
	 * @param string               $url          Relay URL.
	 * @param array<string, mixed> $payload      Payload.
	 * @param string               $subscription Subscription id.
	 * @param int                  $attempt      Attempt.
	 */
	private function send( string $url, array $payload, string $subscription, int $attempt ): ?string {
		$request = self::request( $payload, $this->plugin->secrets->get( 'relay_secret' ), time() );
		$args    = array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => $request['headers'],
			'body'        => $request['body'],
		);
		/**
		 * Allow a provisioning URL on a private network (a control panel on your LAN). Off by
		 * default, like every request WordPress makes to an address someone typed in.
		 *
		 * @since 2.0.0
		 * @param bool   $allow Allow.
		 * @param string $url   Provisioning URL.
		 */
		$response = apply_filters( 'invoiceninja_relay_allow_private', false, $url )
			? wp_remote_post( $url, $args )
			: wp_safe_remote_post( $url, $args );
		$event    = (string) ( $payload['event'] ?? '' );
		$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( is_wp_error( $response ) ) {
			return sprintf( '%s: %s', $host, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return sprintf( '%s: HTTP %d (attempt %d)', $host, $code, $attempt );
		}
		$this->plugin->logger->log( LogLevel::Info, 'subscriptions', sprintf( 'Sent "%s" to the provisioning system (HTTP %d)', $event, $code ), array( 'id' => (string) ( $payload['id'] ?? '' ) ), 'subscription', $subscription );
		return null;
	}

	/**
	 * Take a lane's lock (a stale one, from a run that died, is taken over after five minutes).
	 *
	 * @param string $lane Lane.
	 */
	private function lock( string $lane ): bool {
		$options = $this->plugin->options;
		if ( $options->addAtomic( self::LOCK . $lane, (string) time() ) ) {
			return true;
		}
		if ( time() - (int) $options->get( self::LOCK . $lane, 0 ) < 5 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$options->delete( self::LOCK . $lane );
		return $options->addAtomic( self::LOCK . $lane, (string) time() );
	}

	/**
	 * Outbox row keys (without the option prefix), oldest first; for one lane or all.
	 *
	 * @param string $lane  Lane, or '' for every lane.
	 * @param int    $limit Rows.
	 * @return list<string>
	 */
	private function rows( string $lane, int $limit = 5000 ): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}
		$prefix = Options::PREFIX . self::ROW . ( '' !== $lane ? $lane . '_' : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own option rows, ordered by name.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d", $wpdb->esc_like( $prefix ) . '%', $limit ) );
		$out   = array();
		foreach ( (array) $names as $name ) {
			$key = substr( (string) $name, strlen( Options::PREFIX ) );
			if ( preg_match( '/^' . preg_quote( self::ROW, '/' ) . '[0-9a-f]{12}_\d{12}$/', $key ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}
}
