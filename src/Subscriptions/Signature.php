<?php
/**
 * Signs ProvisioningRelay deliveries.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

/**
 * X-Invoiceninja-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">, keyed with
 * the site's relay secret. X-Invoiceninja-Timestamp is that timestamp (Unix seconds), so a
 * receiver can refuse old deliveries. See docs/hosting.md for receivers in PHP and Node.
 */
final class Signature {

	public const HEADER = 'X-Invoiceninja-Signature';

	public const TIMESTAMP = 'X-Invoiceninja-Timestamp';

	public const TOLERANCE = 300;

	/**
	 * The signature header value.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $body      Raw JSON body, exactly as sent.
	 * @param string $secret    Relay secret.
	 */
	public static function sign( int $timestamp, string $body, #[\SensitiveParameter] string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Whether a delivery is genuine and recent (what a receiver does).
	 *
	 * @param string   $header    Signature header.
	 * @param string   $timestamp Timestamp header.
	 * @param string   $body      Raw body.
	 * @param string   $secret    Relay secret.
	 * @param int|null $now       Current time.
	 */
	public static function verify( string $header, string $timestamp, string $body, #[\SensitiveParameter] string $secret, ?int $now = null ): bool {
		if ( ! ctype_digit( $timestamp ) || '' === $secret ) {
			return false;
		}
		$now = $now ?? time();
		if ( abs( $now - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		return hash_equals( self::sign( (int) $timestamp, $body, $secret ), $header );
	}
}
