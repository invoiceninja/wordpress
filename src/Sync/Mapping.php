<?php
/**
 * Links Invoice Ninja records to WordPress objects.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Sync;

/**
 * One row per Invoice Ninja record (in_type + in_id is unique), with the WordPress
 * object it maps to and a hash of the last synced content so unchanged records are skipped.
 */
final class Mapping {

	public const TABLE = 'invoiceninja_map';

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Table definition for dbDelta.
	 */
	public static function schema(): string {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			in_type varchar(20) NOT NULL,
			in_id varchar(64) NOT NULL,
			wp_type varchar(20) NOT NULL,
			wp_id bigint(20) unsigned NOT NULL,
			hash char(40) NOT NULL DEFAULT '',
			synced_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY in_record (in_type, in_id),
			KEY wp_object (wp_type, wp_id)
		) {$charset};";
	}

	/**
	 * The mapping for an Invoice Ninja record.
	 *
	 * @param string $in_type Record type, e.g. product.
	 * @param string $in_id   Record id.
	 * @return array{wp_id: int, hash: string}|null
	 */
	public function find( string $in_type, string $in_id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT wp_id, hash FROM {$table} WHERE in_type = %s AND in_id = %s", $in_type, $in_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? array(
			'wp_id' => (int) $row['wp_id'],
			'hash'  => (string) $row['hash'],
		) : null;
	}

	/**
	 * The Invoice Ninja id for a WordPress object.
	 *
	 * @param string $wp_type WordPress type, e.g. post.
	 * @param int    $wp_id   WordPress id.
	 */
	public function inId( string $wp_type, int $wp_id ): ?string {
		global $wpdb;
		$table = self::table();
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT in_id FROM {$table} WHERE wp_type = %s AND wp_id = %d", $wp_type, $wp_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return null === $id ? null : (string) $id;
	}

	/**
	 * Create or update a mapping.
	 *
	 * @param string $in_type Record type.
	 * @param string $in_id   Record id.
	 * @param string $wp_type WordPress type.
	 * @param int    $wp_id   WordPress id.
	 * @param string $hash    Content hash.
	 */
	public function save( string $in_type, string $in_id, string $wp_type, int $wp_id, string $hash ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (in_type, in_id, wp_type, wp_id, hash, synced_at) VALUES (%s, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE wp_type = VALUES(wp_type), wp_id = VALUES(wp_id), hash = VALUES(hash), synced_at = VALUES(synced_at)", $in_type, $in_id, $wp_type, $wp_id, $hash, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Every mapping of a type, by Invoice Ninja id.
	 *
	 * @param string $in_type Record type.
	 * @return array<string, array{wp_id: int, hash: string}>
	 */
	public function all( string $in_type ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT in_id, wp_id, hash FROM {$table} WHERE in_type = %s", $in_type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['in_id'] ] = array(
				'wp_id' => (int) $row['wp_id'],
				'hash'  => (string) $row['hash'],
			);
		}
		return $out;
	}

	/**
	 * Number of mappings of a type.
	 *
	 * @param string $in_type Record type.
	 */
	public function count( string $in_type ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE in_type = %s", $in_type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Remove every mapping of a type (company switch).
	 *
	 * @param string $in_type Record type.
	 */
	public function clear( string $in_type ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'in_type' => $in_type ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
