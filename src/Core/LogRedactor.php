<?php
/**
 * Removes secrets and personal data from log context.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

/**
 * Tokens and secrets are replaced, emails are masked (j***@example.com) and links that
 * log someone in (invitation, key_login, portal links) are cut down to their host.
 */
final class LogRedactor {

	private const SECRET_KEYS = '/(token|secret|password|passwd|api[_-]?key|authorization|signature|cookie|nonce|code_hash|client_hash)/i';

	private const MAX_BYTES = 8192;

	/**
	 * Redact a context array and cap its encoded size.
	 *
	 * @param array<mixed> $context Context.
	 */
	public static function context( array $context ): string {
		$clean = self::walk( $context, 0 );
		$json  = (string) wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			$json = (string) wp_json_encode( array( 'truncated' => substr( $json, 0, self::MAX_BYTES - 64 ) ) );
		}
		return $json;
	}

	/**
	 * Redact free text.
	 *
	 * @param string $text Text.
	 */
	public static function text( string $text ): string {
		$text = (string) preg_replace_callback(
			'/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
			static fn( array $m ): string => $m[1] . '***@' . $m[2],
			$text
		);
		$text = (string) preg_replace( '#(https?://[^/\s]+)/(client|portal)/[^\s"\']*#i', '$1/$2/[link removed]', $text );
		$text = (string) preg_replace( '#([?&](key|token|client_hash|invitation|signature)=)[^&\s"\']+#i', '$1[removed]', $text );
		return (string) preg_replace( '/\bin1:[0-9a-f]{8}:[A-Za-z0-9+\/=]+/', '[encrypted token]', $text );
	}

	/**
	 * Recursive walk.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function walk( mixed $value, int $depth ): mixed {
		if ( $depth > 6 ) {
			return '[nested]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = is_string( $key ) && preg_match( self::SECRET_KEYS, $key )
					? '[removed]'
					: self::walk( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return self::walk( get_object_vars( $value ), $depth + 1 );
		}
		if ( is_string( $value ) ) {
			return self::text( $value );
		}
		return is_scalar( $value ) || null === $value ? $value : '[' . gettype( $value ) . ']';
	}
}
