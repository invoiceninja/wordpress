<?php
/**
 * Works out what Invoice Ninja will total, and reconciles it with WooCommerce.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

/**
 * Mirrors Invoice Ninja's own sums (InvoiceItemSum / InvoiceSum, exclusive taxes, amount
 * discounts, no invoice-level discount):
 * - line total = round(cost × quantity, 2) − round(discount, precision);
 * - each tax = round(line total × rate / 100, 2), on lines whose total isn't zero;
 * - taxes are grouped by name and rate and each group rounded to the currency precision;
 * - invoice total = Σ line totals + Σ tax groups.
 *
 * A difference no larger than rounding (a cent per line) is closed with an adjustment
 * line; anything larger is reported as a mismatch for an admin to look at, never hidden.
 */
final class Totals {

	public const EXACT    = 'exact';
	public const ADJUSTED = 'adjusted';
	public const MISMATCH = 'mismatch';

	/**
	 * One line's total before tax.
	 *
	 * @param array<string, mixed> $line      Line item.
	 * @param int                  $precision Currency precision.
	 */
	public static function lineTotal( array $line, int $precision = 2 ): float {
		$total = round( (float) ( $line['cost'] ?? 0 ) * (float) ( $line['quantity'] ?? 0 ) + 0.000000000000004, 2 );
		return round( $total - round( (float) ( $line['discount'] ?? 0 ), $precision ), 2 );
	}

	/**
	 * Taxes per group (name + rate) for these lines, unrounded per group.
	 *
	 * @param list<array<string, mixed>> $lines     Lines.
	 * @param int                        $precision Currency precision.
	 * @return array<string, float>
	 */
	public static function taxGroups( array $lines, int $precision = 2 ): array {
		$groups = array();
		foreach ( $lines as $line ) {
			$base = self::lineTotal( $line, $precision );
			if ( 0.0 === $base ) {
				continue;
			}
			foreach ( array( 1, 2, 3 ) as $n ) {
				$rate = (float) ( $line[ 'tax_rate' . $n ] ?? 0 );
				$name = (string) ( $line[ 'tax_name' . $n ] ?? '' );
				if ( 0.0 === $rate ) {
					continue;
				}
				$key            = str_replace( ' ', '', $name . $rate );
				$groups[ $key ] = ( $groups[ $key ] ?? 0.0 ) + round( $base * $rate / 100, 2 );
			}
		}
		return $groups;
	}

	/**
	 * What Invoice Ninja will total these lines to.
	 *
	 * @param list<array<string, mixed>> $lines     Lines.
	 * @param int                        $precision Currency precision.
	 */
	public static function invoiceTotal( array $lines, int $precision = 2 ): float {
		$sum = 0.0;
		foreach ( $lines as $line ) {
			$sum += self::lineTotal( $line, $precision );
		}
		foreach ( self::taxGroups( $lines, $precision ) as $tax ) {
			$sum += round( $tax, $precision );
		}
		return round( $sum, max( $precision, 2 ) );
	}

	/**
	 * The largest difference treated as rounding: one smallest unit per line (at least
	 * one), plus one per tax group.
	 *
	 * @param list<array<string, mixed>> $lines     Lines.
	 * @param int                        $precision Currency precision.
	 */
	public static function tolerance( array $lines, int $precision = 2 ): float {
		$unit = 1 / ( 10 ** max( 0, $precision ) );
		return round( $unit * ( max( 1, count( $lines ) ) + count( self::taxGroups( $lines, $precision ) ) ), max( $precision, 2 ) );
	}

	/**
	 * Compare with the WooCommerce total and close a rounding difference.
	 *
	 * @param list<array<string, mixed>> $lines     Lines.
	 * @param float                      $expected  WooCommerce order total.
	 * @param int                        $precision Currency precision.
	 * @param string                     $label     product_key for the adjustment line.
	 * @return array{lines: list<array<string, mixed>>, status: string, expected: float, computed: float, difference: float}
	 */
	public static function reconcile( array $lines, float $expected, int $precision = 2, string $label = 'Rounding' ): array {
		$scale      = max( $precision, 2 );
		$expected   = round( $expected, $scale );
		$computed   = self::invoiceTotal( $lines, $precision );
		$difference = round( $expected - $computed, $scale );
		$status     = self::EXACT;
		if ( 0.0 !== $difference ) {
			// A hair over the limit from float noise still counts as rounding.
			if ( abs( $difference ) <= self::tolerance( $lines, $precision ) + 0.0000001 ) {
				$lines[] = self::adjustment( $difference, $label );
				$status  = self::ADJUSTED;
			} else {
				$status = self::MISMATCH;
			}
		}
		return array(
			'lines'      => $lines,
			'status'     => $status,
			'expected'   => $expected,
			'computed'   => $computed,
			'difference' => $difference,
		);
	}

	/**
	 * An untaxed line for a rounding difference.
	 *
	 * @param float  $amount Amount (negative to reduce the total).
	 * @param string $label  product_key.
	 * @return array<string, mixed>
	 */
	public static function adjustment( float $amount, string $label = 'Rounding' ): array {
		return array(
			'product_key' => $label,
			'notes'       => '',
			'cost'        => LineItems::number( $amount ),
			'quantity'    => 1,
			'discount'    => 0,
			'type_id'     => '1',
			'tax_name1'   => '',
			'tax_rate1'   => 0,
			'tax_name2'   => '',
			'tax_rate2'   => 0,
			'tax_name3'   => '',
			'tax_rate3'   => 0,
		);
	}
}
