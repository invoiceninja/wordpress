<?php
/**
 * Normalises the Invoice Ninja address an admin types.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api;

/**
 * Accepts what people paste ("billing.example.com/", "https://x.com/api/v1/",
 * "http://192.168.1.5:8000/ninja") and produces the base URL without a trailing slash.
 */
final class BaseUrl {

	public const HOSTED = 'https://invoicing.co';

	/**
	 * Normalise a pasted address. Returns null when it can't be a web address.
	 *
	 * @param string $raw What the admin typed.
	 */
	public static function normalize( string $raw ): ?string {
		$url = trim( $raw );
		if ( '' === $url || preg_match( '/\s/', $url ) ) {
			return null;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		// Underscores aren't valid in public DNS names, but Docker and LAN names often use them.
		if ( ! preg_match( '/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $host ) && ! filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$path = preg_replace( '#/+#', '/', $path ) ?? '';
		$path = preg_replace( '#/(api/v1)?/*$#i', '', $path ) ?? '';
		$path = preg_replace( '#/(client|login|dashboard)(/.*)?$#i', '', $path ) ?? '';
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return strtolower( (string) $parts['scheme'] ) . '://' . $host . $port . rtrim( $path, '/' );
	}

	/**
	 * The API root for a base URL, with a trailing slash.
	 *
	 * @param string $base Normalised base URL.
	 */
	public static function api( string $base ): string {
		return rtrim( $base, '/' ) . '/api/v1/';
	}

	/**
	 * Whether the address is Invoice Ninja's hosted service.
	 *
	 * @param string $base Normalised base URL.
	 */
	public static function isHosted( string $base ): bool {
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		return 'invoicing.co' === $host || str_ends_with( $host, '.invoicing.co' );
	}

	/**
	 * The host, for messages.
	 *
	 * @param string $base Normalised base URL.
	 */
	public static function host( string $base ): string {
		return (string) wp_parse_url( $base, PHP_URL_HOST );
	}
}
