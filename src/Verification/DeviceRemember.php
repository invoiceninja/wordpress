<?php
/**
 * "Remember this device for 30 days" after an email code.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Verification;

use InvoiceNinja\Core\Secrets;

/**
 * The cookie holds a hash of the email and an expiry, signed with the device secret.
 * It only skips the code for the same email; "Not you?" clears it. The name has no
 * `wp-` prefix, so page caches keep serving cached pages to these shoppers.
 */
final class DeviceRemember {

	public const COOKIE = 'invoiceninja_device';

	public const DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param Secrets $secrets Secrets.
	 */
	public function __construct( private Secrets $secrets ) {}

	/**
	 * Build a cookie value for an email.
	 *
	 * @param string $email Email.
	 * @param int    $now   Current time.
	 */
	public function value( string $email, int $now ): string {
		$payload = $this->emailHash( $email ) . '.' . ( $now + self::DAYS * DAY_IN_SECONDS );
		return $payload . '.' . $this->sign( $payload );
	}

	/**
	 * Whether a cookie value remembers this email and hasn't expired.
	 *
	 * @param string $cookie Cookie value.
	 * @param string $email  Email being used now.
	 * @param int    $now    Current time.
	 */
	public function remembers( string $cookie, string $email, int $now ): bool {
		$parts = explode( '.', $cookie );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		[ $hash, $expires, $signature ] = $parts;
		return hash_equals( $this->sign( $hash . '.' . $expires ), $signature )
			&& hash_equals( $this->emailHash( $email ), $hash )
			&& (int) $expires > $now;
	}

	/**
	 * Set the cookie on this response.
	 *
	 * @param string $email Email.
	 */
	public function remember( string $email ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$this->value( $email, time() ),
			array(
				'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Clear the cookie ("Not you?").
	 */
	public function forget(): void {
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true );
		}
	}

	/**
	 * Whether the current request's cookie remembers this email.
	 *
	 * @param string $email Email.
	 */
	public function current( string $email ): bool {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return '' !== $cookie && $this->remembers( $cookie, $email, time() );
	}

	/**
	 * Keyed hash of an email.
	 *
	 * @param string $email Email.
	 */
	private function emailHash( string $email ): string {
		return substr( hash_hmac( 'sha256', 'email|' . EmailCode::normalize( $email ), $this->secrets->get( 'device_secret' ) ), 0, 32 );
	}

	/**
	 * Signature.
	 *
	 * @param string $payload Payload.
	 */
	private function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', 'device|' . $payload, $this->secrets->get( 'device_secret' ) ), 0, 40 );
	}
}
