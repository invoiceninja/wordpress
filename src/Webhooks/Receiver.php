<?php
/**
 * The public endpoint Invoice Ninja's webhooks call.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Webhooks;

use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * Replies only 202 (queued), 401 (wrong secret) or 429 with Retry-After (too many calls).
 * Never 404, 405 or 410, which make Invoice Ninja delete the webhook, and never a 5xx, which
 * makes it drop the call. The body is trusted for nothing but the entity id: the Dispatcher
 * fetches the entity again before acting.
 */
final class Receiver {

	public const JOB = 'invoiceninja_webhook_dispatch';

	/** Wrong-secret calls allowed per IP and hour before 429. */
	public const MAX_FAILURES = 30;

	/** Accepted calls per IP and hour (a bulk edit in Invoice Ninja sends one per record). */
	public const MAX_CALLS = 5000;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Any method on /webhook?event=<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			return $this->receive( $request );
		} catch ( \Throwable $e ) {
			// Never a 5xx (Invoice Ninja drops the call): 429 makes it try again later.
			$this->plugin->logger->error( 'webhooks', 'Receiving a webhook failed: ' . $e->getMessage() );
			return self::reply( 429, 'try_again', 60 );
		}
	}

	/**
	 * Check, count and queue one call.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$options = $this->plugin->options;
		$ip      = substr( hash( 'sha256', Routes::clientIp() ), 0, 12 );
		$hour    = (int) floor( time() / HOUR_IN_SECONDS );
		$retry   = HOUR_IN_SECONDS - ( time() % HOUR_IN_SECONDS );

		if ( (int) $options->get( 'ctr_webhook_bad_' . $ip . '_' . $hour, 0 ) >= self::MAX_FAILURES ) {
			return self::reply( 429, 'rate_limited', $retry );
		}
		$sent = (string) ( $request->get_header( 'x_invoiceninja_secret' ) ?? '' );
		if ( '' === $sent || ! hash_equals( $this->plugin->secrets->get( 'webhook_secret' ), $sent ) ) {
			$options->increment( 'ctr_webhook_bad_' . $ip . '_' . $hour );
			return self::reply( 401, 'bad_secret' );
		}
		if ( $options->increment( 'ctr_webhook_' . $ip . '_' . $hour ) > self::MAX_CALLS ) {
			return self::reply( 429, 'rate_limited', min( $retry, 600 ) );
		}

		$event = (int) $request->get_param( 'event' );
		$body  = $request->get_json_params();
		$id    = Events::cleanId( is_array( $body ) ? ( $body['id'] ?? '' ) : '' );
		if ( ! Events::known( $event ) || '' === $id ) {
			// Accepted and ignored: a 4xx here would make Invoice Ninja retry or delete the webhook.
			return self::reply( 202, 'ignored' );
		}
		// Same event and entity already waiting: one fetch covers both calls.
		$this->plugin->queue->push(
			self::JOB,
			array(
				'event' => $event,
				'id'    => $id,
			),
			0,
			true
		);
		return self::reply( 202, 'queued' );
	}

	/**
	 * A reply with only the allowed statuses.
	 *
	 * @param int    $status      202, 401 or 429.
	 * @param string $code        Short code.
	 * @param int    $retry_after Seconds, for 429.
	 */
	private static function reply( int $status, string $code, int $retry_after = 0 ): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'code' => $code ), in_array( $status, array( 202, 401, 429 ), true ) ? $status : 202 );
		if ( 429 === $status ) {
			$response->header( 'Retry-After', (string) max( 1, $retry_after ) );
		}
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
