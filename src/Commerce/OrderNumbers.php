<?php
/**
 * Readable, unique order numbers like WEB-K7-1001.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Commerce;

use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;

/**
 * Prefix (setting, default WEB) + this site's code + a per-site sequence. The site code
 * keeps numbers unique when several sites share one Invoice Ninja company. The sequence
 * is incremented in one SQL statement so concurrent checkouts never share a number.
 */
final class OrderNumbers {

	public const START = 1000;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 * @param Secrets $secrets Secrets.
	 */
	public function __construct( private Options $options, private Secrets $secrets ) {}

	/**
	 * Reserve the next number.
	 */
	public function next(): string {
		return $this->format( $this->increment() );
	}

	/**
	 * Format a sequence number.
	 *
	 * @param int $sequence Sequence.
	 */
	public function format( int $sequence ): string {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $this->options->get( 'order_prefix', 'WEB' ) ) ?? '' );
		$prefix = '' !== $prefix ? substr( $prefix, 0, 8 ) : 'WEB';
		return $prefix . '-' . $this->secrets->siteCode() . '-' . $sequence;
	}

	/**
	 * Whether a string looks like one of our order numbers (for lookups by po_number).
	 *
	 * @param string $value Value.
	 */
	public static function looksLikeOrderNumber( string $value ): bool {
		return 1 === preg_match( '/^[A-Z0-9]{1,8}-[A-Z][0-9]-\d{4,}$/', $value );
	}

	/**
	 * Atomically increment the stored sequence and return the new value.
	 */
	private function increment(): int {
		global $wpdb;
		$name = Options::PREFIX . 'order_sequence';
		$this->options->addAtomic( 'order_sequence', (string) self::START );
		// LAST_INSERT_ID(expr) makes the incremented value readable on this connection only.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", $name ) );
		$value = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return $value > self::START ? $value : self::START + 1;
	}
}
