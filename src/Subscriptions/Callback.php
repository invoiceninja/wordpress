<?php
/**
 * The public endpoint Invoice Ninja calls during and after a subscription purchase.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;
use InvoiceNinja\Webhooks\Events;
use InvoiceNinja\Webhooks\Receiver;

/**
 * Invoice Ninja's v1/v2 purchase pages only go ahead when the reply's message is "Success",
 * and they wait for it inside the buyer's request with no timeout, so:
 * - is_eligible is answered inline: the invoiceninja_subscription_eligible filter, plus the
 *   subscription's original callback URL (if it had one) within one second. Anything that
 *   goes wrong answers "Success" (fail open), so a broken check never blocks a sale;
 * - every other context answers "Success" at once and its work is queued.
 * Every reply is HTTP 200 with message and status_code (see answer()). Invoice Ninja turns any
 * 4xx/5xx into status_code 500 and puts our message in front of the buyer, so a wrong secret
 * is answered "Success" too, with code "ignored", and nothing is acted on: a rotated secret
 * can't block buyers. The body only names ids: the Lifecycle fetches everything again.
 */
final class Callback {

	public const JOB = 'invoiceninja_subscription_callback';

	/** Callbacks with the right secret accepted per address and hour (beyond that: "Success", not acted on). */
	public const MAX_CALLS = 5000;

	public const FORWARD = 'invoiceninja_subscription_forward';

	public const CONTEXTS = array( 'is_eligible', 'single_purchase', 'recurring_purchase', 'trial', 'change_plan', 'plan_paid', 'cancellation', 'plan_expired' );

	public const RECENT = 'subscriptions_recent';

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin       Plugin.
	 * @param Configurator $configurator Configurator.
	 */
	public function __construct( private Plugin $plugin, private Configurator $configurator ) {}

	/**
	 * Any method on /subscription-callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			return $this->receive( $request );
		} catch ( \Throwable $e ) {
			$this->plugin->logger->error( 'subscriptions', 'Handling a subscription callback failed: ' . $e->getMessage() );
			return self::reply(); // Never block a purchase.
		}
	}

	/**
	 * Check and answer one callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$options = $this->plugin->options;
		$ip      = Receiver::bucket( Routes::clientIp() );
		$hour    = (int) floor( time() / HOUR_IN_SECONDS );
		// The secret comes first, so a genuine call is never refused because others sharing its
		// address sent wrong secrets. A wrong one is ignored but still answered as a success:
		// any 4xx makes Invoice Ninja report status 500, which blocks free purchases on its v1 page.
		$sent = (string) ( $request->get_header( 'x_invoiceninja_secret' ) ?? '' );
		if ( '' === $sent || ! hash_equals( $this->plugin->secrets->get( 'callback_secret' ), $sent ) ) {
			if ( 1 === $options->increment( 'ctr_subcb_bad_' . $ip . '_' . $hour ) ) {
				$this->plugin->logger->warning( 'subscriptions', 'Ignored a subscription callback with a wrong secret. If it came from Invoice Ninja, turn "Send purchases to this site" off and on again for that subscription.' );
			}
			return self::reply( 'ignored' );
		}
		if ( $options->increment( 'ctr_subcb_' . $ip . '_' . $hour ) > self::MAX_CALLS ) {
			return self::reply( 'busy' ); // Still "Success": never block a purchase.
		}
		$json = $request->get_json_params();
		$body = is_array( $json ) && is_array( $json['body'] ?? null ) ? $json['body'] : array();
		$ctx  = self::context( $body );
		if ( null === $ctx ) {
			return self::reply();
		}

		if ( 'is_eligible' === $ctx['context'] ) {
			$response = new \WP_REST_Response( self::answer( $this->eligible( $ctx, $body ) ), 200 );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		$this->remember( $ctx );
		$this->plugin->queue->push( self::JOB, $ctx, 0, true );
		if ( '' !== (string) ( $this->configurator->get( $ctx['subscription'] )['original']['post_purchase_url'] ?? '' ) ) {
			// The integration this subscription had before still hears about purchases.
			$this->plugin->queue->push(
				self::FORWARD,
				array(
					'subscription' => $ctx['subscription'],
					'body'         => self::forwardable( $body ),
				),
				0,
				false
			);
		}
		return self::reply();
	}

	/**
	 * The ids in a callback body, cleaned; null when it isn't one we handle.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array{context: string, subscription: string, client: string, invoice: string, recurring_invoice: string, contact: string}|null
	 */
	public static function context( array $body ): ?array {
		$context = (string) ( $body['context'] ?? '' );
		if ( ! in_array( $context, self::CONTEXTS, true ) ) {
			return null;
		}
		$ctx = array(
			'context'           => $context,
			'subscription'      => Events::cleanId( $body['subscription'] ?? '' ),
			'client'            => Events::cleanId( $body['client'] ?? '' ),
			'invoice'           => Events::cleanId( $body['invoice'] ?? '' ),
			'recurring_invoice' => Events::cleanId( $body['recurring_invoice'] ?? '' ),
			'contact'           => Events::cleanId( $body['contact'] ?? '' ),
		);
		return '' === $ctx['subscription'] || '' === $ctx['client'] ? null : $ctx;
	}

	/**
	 * The eligibility answer: "Success", or why not.
	 *
	 * @param array<string, string> $ctx  Context.
	 * @param array<string, mixed>  $body Raw body.
	 */
	public function eligible( array $ctx, array $body ): string {
		$success = 'Success';
		try {
			/**
			 * Whether a buyer may purchase a subscription. Return true, false, or a sentence to
			 * show the buyer. Runs inside Invoice Ninja's purchase page, so keep it fast.
			 * Anything that throws counts as eligible.
			 *
			 * @since 2.0.0
			 * @param bool|string $eligible     Eligible.
			 * @param string      $subscription Invoice Ninja subscription id.
			 * @param array       $buyer        email, client_id, contact_id, account_key (sent by Invoice Ninja).
			 */
			$result = apply_filters(
				'invoiceninja_subscription_eligible',
				true,
				$ctx['subscription'],
				array(
					'email'       => sanitize_email( (string) ( $body['contact_email'] ?? '' ) ),
					'client_id'   => $ctx['client'],
					'contact_id'  => $ctx['contact'],
					'account_key' => sanitize_text_field( (string) ( $body['account_key'] ?? '' ) ),
				)
			);
		} catch ( \Throwable $e ) {
			$this->plugin->logger->warning( 'subscriptions', 'The eligibility check failed, so the purchase was allowed: ' . $e->getMessage() );
			return $success;
		}
		if ( false === $result ) {
			return __( "Sorry, this plan isn't available for your account.", 'invoiceninja' );
		}
		if ( is_string( $result ) && '' !== trim( $result ) && 'Success' !== $result ) {
			return sanitize_text_field( $result );
		}
		return $this->askOriginal( $ctx['subscription'], $body ) ?? $success;
	}

	/**
	 * Ask the subscription's original callback URL, within one second. Null means allowed.
	 *
	 * @param string               $subscription Subscription id.
	 * @param array<string, mixed> $body         Body.
	 */
	private function askOriginal( string $subscription, array $body ): ?string {
		$original = $this->configurator->get( $subscription )['original'];
		$url      = (string) ( $original['post_purchase_url'] ?? '' );
		if ( '' === $url ) {
			return null;
		}
		$response = self::send( $original, self::forwardable( $body ), 1.0 );
		if ( is_wp_error( $response ) ) {
			return null; // Fail open.
		}
		$code  = (int) wp_remote_retrieve_response_code( $response );
		$reply = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $reply ) || ! isset( $reply['message'] ) || ! is_string( $reply['message'] ) ) {
			return null;
		}
		return 'Success' === $reply['message'] || $code >= 500 ? null : sanitize_text_field( $reply['message'] );
	}

	/**
	 * Queue handler: pass a callback on to the original URL.
	 *
	 * @param array<string, mixed> $payload subscription, body.
	 */
	public function forward( array $payload ): void {
		$original = $this->configurator->get( (string) ( $payload['subscription'] ?? '' ) )['original'];
		if ( '' === (string) ( $original['post_purchase_url'] ?? '' ) ) {
			return;
		}
		$response = self::send( $original, is_array( $payload['body'] ?? null ) ? $payload['body'] : array(), 10.0 );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
			$this->plugin->logger->warning( 'subscriptions', "The subscription's original callback URL didn't answer; it wasn't retried (Invoice Ninja doesn't retry either)." );
		}
	}

	/**
	 * Call an original callback the way Invoice Ninja would.
	 *
	 * @param array<string, mixed> $original Original webhook_configuration.
	 * @param array<string, mixed> $body     Body.
	 * @param float                $timeout  Seconds.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function send( array $original, array $body, float $timeout ): array|\WP_Error {
		$headers = array(
			'Content-Type'     => 'application/json',
			'X-Requested-With' => 'XMLHttpRequest',
		);
		foreach ( is_array( $original['post_purchase_headers'] ?? null ) ? $original['post_purchase_headers'] : array() as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				$headers[ $name ] = (string) $value;
			}
		}
		$method = 'put' === strtolower( (string) ( $original['post_purchase_rest_method'] ?? 'post' ) ) ? 'PUT' : 'POST';
		return wp_safe_remote_request(
			(string) $original['post_purchase_url'],
			array(
				'method'      => $method,
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => (string) wp_json_encode( array( 'body' => $body ) ),
			)
		);
	}

	/**
	 * The body to pass on: scalars only, no database name.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array<string, scalar|null>
	 */
	private static function forwardable( array $body ): array {
		$out = array();
		foreach ( $body as $key => $value ) {
			if ( is_string( $key ) && 'db' !== $key && ( is_scalar( $value ) || null === $value ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Note the callback for the return page ("Your subscription is ready"); no API call.
	 *
	 * @param array<string, string> $ctx Context.
	 */
	private function remember( array $ctx ): void {
		$recent   = (array) $this->plugin->options->get( self::RECENT, array() );
		$recent[] = array(
			'context'      => $ctx['context'],
			'subscription' => $ctx['subscription'],
			'client'       => $ctx['client'],
			'at'           => time(),
		);
		$this->plugin->options->set( self::RECENT, array_slice( $recent, -100 ) );
	}

	/**
	 * Recent callbacks.
	 *
	 * @return list<array{context: string, subscription: string, client: string, at: int}>
	 */
	public function recent(): array {
		$out = array();
		foreach ( (array) $this->plugin->options->get( self::RECENT, array() ) as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'context'      => (string) ( $row['context'] ?? '' ),
					'subscription' => (string) ( $row['subscription'] ?? '' ),
					'client'       => (string) ( $row['client'] ?? '' ),
					'at'           => (int) ( $row['at'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	/**
	 * The JSON Invoice Ninja merges into the callback body. Its v1 and v2 pages read `message`
	 * ("Success" goes ahead; anything else is shown to the buyer), and the v1 page's free
	 * purchase step reads `status_code` (anything but 200 stops it), so both always go.
	 *
	 * @param string $message "Success", or why the buyer can't buy.
	 * @return array{message: string, status_code: int}
	 */
	public static function answer( string $message ): array {
		return array(
			'message'     => $message,
			'status_code' => 'Success' === $message ? 200 : 403,
		);
	}

	/**
	 * The "Success" reply, always HTTP 200: Invoice Ninja turns any 4xx or 5xx into status 500.
	 *
	 * @param string $code Why nothing was done (ignored, busy), for Invoice Ninja's system log.
	 */
	private static function reply( string $code = '' ): \WP_REST_Response {
		$data     = self::answer( 'Success' ) + ( '' !== $code ? array( 'code' => $code ) : array() );
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
