<?php
/**
 * Per-purpose secrets and encryption of the API token at rest.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

/**
 * Tokens are stored as `in1:<key id>:<base64(nonce . ciphertext)>`, encrypted with
 * libsodium's secretbox (WordPress ships sodium_compat, so it is always available).
 * The key is derived with HKDF from INVOICENINJA_ENCRYPTION_KEY when defined, otherwise
 * from the site's auth salts. If the salts change, decrypt() returns null and the plugin
 * asks for the token again; it never falls back to sending ciphertext.
 */
final class Secrets {

	public const PREFIX = 'in1:';

	public const PURPOSES = array( 'secret', 'cart_secret', 'device_secret', 'callback_secret', 'relay_secret', 'webhook_secret' );

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * A 64-character random secret for one purpose, created on first use.
	 *
	 * @param string $purpose One of PURPOSES.
	 * @throws \InvalidArgumentException For an unknown purpose.
	 */
	public function get( string $purpose ): string {
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown secret purpose' );
		}
		$value = (string) $this->options->get( $purpose, '' );
		if ( strlen( $value ) < 64 ) {
			$value = bin2hex( random_bytes( 32 ) );
			// add_option() is atomic: if another request won the race, use its value.
			if ( ! add_option( Options::PREFIX . $purpose, $value, '', false ) ) {
				$value = (string) $this->options->get( $purpose, $value );
			}
		}
		return $value;
	}

	/**
	 * Two characters that make this site's order numbers unique (e.g. "K7").
	 */
	public function siteCode(): string {
		$code = (string) $this->options->get( 'site_code', '' );
		if ( ! preg_match( '/^[A-Z][0-9]$/', $code ) ) {
			$code = chr( 65 + random_int( 0, 25 ) ) . (string) random_int( 1, 9 );
			if ( ! add_option( Options::PREFIX . 'site_code', $code, '', false ) ) {
				$code = (string) $this->options->get( 'site_code', $code );
			}
		}
		return $code;
	}

	/**
	 * Whether a stored value is an encrypted envelope.
	 *
	 * @param string $value Stored value.
	 */
	public static function isEnvelope( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plain Plain text.
	 */
	public function encrypt( string $plain ): string {
		[ $id, $key ] = $this->key();
		$nonce        = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher       = sodium_crypto_secretbox( $plain, $nonce, $key );
		return self::PREFIX . $id . ':' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary envelope, not obfuscation.
	}

	/**
	 * Decrypt an envelope. Returns null when it was made with another key or is damaged.
	 *
	 * @param string $envelope Envelope.
	 */
	public function decrypt( string $envelope ): ?string {
		$parts = explode( ':', $envelope, 3 );
		if ( 3 !== count( $parts ) || 'in1' !== $parts[0] ) {
			return null;
		}
		[ $id, $key ] = $this->key();
		if ( ! hash_equals( $id, $parts[1] ) ) {
			return null;
		}
		$raw = base64_decode( $parts[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary envelope.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);
		return false === $plain ? null : $plain;
	}

	/**
	 * Whether the encryption key comes from wp-config.php rather than the database.
	 */
	public static function keyIsInConfig(): bool {
		return defined( 'INVOICENINJA_ENCRYPTION_KEY' ) || ( defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) );
	}

	/**
	 * The key id and key.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function key(): array {
		$material = defined( 'INVOICENINJA_ENCRYPTION_KEY' ) && '' !== (string) constant( 'INVOICENINJA_ENCRYPTION_KEY' )
			? (string) constant( 'INVOICENINJA_ENCRYPTION_KEY' )
			: wp_salt( 'auth' );
		$key      = hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'invoiceninja-token-v1' );
		return array( substr( hash( 'sha256', $key ), 0, 8 ), $key );
	}
}
