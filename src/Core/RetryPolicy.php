<?php
/**
 * When to try a failed background job again.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

/**
 * Backs off from one minute to four hours over eight attempts (about 8 hours in total),
 * with jitter so many sites don't retry at the same moment.
 */
final class RetryPolicy {

	public const DELAYS = array( 60, 120, 300, 900, 1800, 3600, 7200, 14400 );

	/**
	 * Seconds to wait before attempt number $attempt (1-based), or null when out of attempts.
	 *
	 * @param int $attempt The attempt that just failed.
	 */
	public static function delay( int $attempt ): ?int {
		if ( $attempt < 1 || $attempt > count( self::DELAYS ) ) {
			return null;
		}
		$base = self::DELAYS[ $attempt - 1 ];
		return $base + random_int( 0, (int) ( $base / 10 ) );
	}

	/**
	 * Maximum attempts.
	 */
	public static function maxAttempts(): int {
		return count( self::DELAYS );
	}
}
