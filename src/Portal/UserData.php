<?php
/**
 * Per-site user data (client links and email verification).
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

/**
 * User meta is shared by every site of a multisite network, but links and verification
 * are per site (each site has its own Invoice Ninja connection and its own secret for the
 * hashes). On multisite the keys get the site's table prefix, like get_user_option()
 * (wp_2__invoiceninja_client_id); on a single site they stay as they are. A value saved
 * before 2.0.2 without the prefix is still read when the site has none of its own;
 * callers check its hash, which only matches on the site that wrote it.
 */
final class UserData {

	/**
	 * The meta key for this site.
	 *
	 * @param string $key Key, e.g. _invoiceninja_client_id.
	 */
	public static function key( string $key ): string {
		global $wpdb;
		return is_multisite() ? $wpdb->get_blog_prefix() . $key : $key;
	}

	/**
	 * Read a value.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Key.
	 */
	public static function get( int $user_id, string $key ): string {
		if ( ! is_multisite() ) {
			return (string) get_user_meta( $user_id, $key, true );
		}
		$values = get_user_meta( $user_id, self::key( $key ), false );
		if ( is_array( $values ) && $values ) {
			return (string) $values[0];
		}
		return (string) get_user_meta( $user_id, $key, true );
	}

	/**
	 * Save a value.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Key.
	 * @param string $value   Value.
	 */
	public static function set( int $user_id, string $key, string $value ): void {
		update_user_meta( $user_id, self::key( $key ), $value );
	}

	/**
	 * Remove a value. On multisite an empty value is kept, so a value from before 2.0.2
	 * isn't read in its place.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Key.
	 */
	public static function delete( int $user_id, string $key ): void {
		if ( is_multisite() ) {
			update_user_meta( $user_id, self::key( $key ), '' );
			return;
		}
		delete_user_meta( $user_id, $key );
	}

	/**
	 * Remove this site's value and one saved before 2.0.2 (personal data erasure).
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Key.
	 * @return bool Whether anything was removed.
	 */
	public static function erase( int $user_id, string $key ): bool {
		$removed = delete_user_meta( $user_id, $key );
		if ( is_multisite() ) {
			$removed = delete_user_meta( $user_id, self::key( $key ) ) || $removed;
		}
		return $removed;
	}

	/**
	 * On multisite, copy values saved before 2.0.2 to this site's keys once a caller found
	 * them valid here, so they stay this site's when another site writes its own.
	 *
	 * @param int          $user_id User id.
	 * @param list<string> $keys    Keys.
	 */
	public static function adopt( int $user_id, array $keys ): void {
		if ( ! is_multisite() ) {
			return;
		}
		foreach ( $keys as $key ) {
			$own = get_user_meta( $user_id, self::key( $key ), false );
			if ( is_array( $own ) && $own ) {
				continue;
			}
			$legacy = (string) get_user_meta( $user_id, $key, true );
			if ( '' !== $legacy ) {
				update_user_meta( $user_id, self::key( $key ), $legacy );
			}
		}
	}

	/**
	 * A user query clause matching users with this value for the key on this site.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return array<int|string, mixed>
	 */
	public static function metaQuery( string $key, string $value ): array {
		$own = array(
			'key'   => self::key( $key ),
			'value' => $value,
		);
		if ( ! is_multisite() ) {
			return array( $own );
		}
		return array(
			'relation' => 'OR',
			$own,
			array(
				'key'   => $key,
				'value' => $value,
			),
		);
	}

	/**
	 * A user query clause matching users without a value for the key on this site.
	 *
	 * @param string $key Key.
	 * @return array<int|string, mixed>
	 */
	public static function missingQuery( string $key ): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::key( $key ),
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => self::key( $key ),
				'value' => '',
			),
		);
	}
}
