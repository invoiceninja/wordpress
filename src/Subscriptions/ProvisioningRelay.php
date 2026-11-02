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
use InvoiceNinja\Plugin;

/**
 * Optional, per subscription. Each event is POSTed as JSON (Payload, version 1) and signed:
 * X-Invoiceninja-Signature = sha256=HMAC-SHA256("<timestamp>.<body>", relay secret) and
 * X-Invoiceninja-Timestamp. Anything but a 2xx is retried through the queue (from one
 * minute up to four hours, eight attempts), and every delivery is logged.
 */
final class ProvisioningRelay {

	public const JOB = 'invoiceninja_subscription_relay';

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin       Plugin.
	 * @param Configurator $configurator Configurator.
	 */
	public function __construct( private Plugin $plugin, private Configurator $configurator ) {}

	/**
	 * Queue a delivery when the subscription has a relay URL.
	 *
	 * @param string               $subscription Subscription id.
	 * @param array<string, mixed> $payload      Payload.
	 */
	public function queue( string $subscription, array $payload ): void {
		if ( '' === $this->configurator->get( $subscription )['relay_url'] ) {
			return;
		}
		$this->plugin->queue->push(
			self::JOB,
			array(
				'subscription' => $subscription,
				'payload'      => $payload,
			),
			0,
			false
		);
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
	 * Queue handler: deliver one event.
	 *
	 * @param array<string, mixed> $job     subscription, payload.
	 * @param int                  $attempt Attempt.
	 * @throws ApiException When the receiver didn't accept it (the queue tries again).
	 */
	public function deliver( array $job, int $attempt = 1 ): void {
		$subscription = (string) ( $job['subscription'] ?? '' );
		$payload      = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$url          = $this->configurator->get( $subscription )['relay_url'];
		if ( '' === $url || ! $payload ) {
			return;
		}
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
		if ( is_wp_error( $response ) ) {
			throw new ApiException( ErrorKind::Transport, sprintf( 'relay %s to %s: %s', $event, (string) wp_parse_url( $url, PHP_URL_HOST ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new ApiException( ErrorKind::Server, sprintf( 'relay %s to %s: HTTP %d (attempt %d)', $event, (string) wp_parse_url( $url, PHP_URL_HOST ), $code, $attempt ), $code );
		}
		$this->plugin->logger->log( LogLevel::Info, 'subscriptions', sprintf( 'Sent "%s" to the provisioning system (HTTP %d)', $event, $code ), array( 'id' => (string) ( $payload['id'] ?? '' ) ), 'subscription', $subscription );
	}
}
