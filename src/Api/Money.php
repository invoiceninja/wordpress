<?php
/**
 * Formats amounts the way Invoice Ninja prints them on invoices.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api;

/**
 * A port of Invoice Ninja's App\Utils\Number::formatMoney: the country's separators and
 * symbol position override the currency's; "show currency code" prints the code instead
 * of the symbol (CHF goes first); negative amounts put the minus before the symbol.
 */
final class Money {

	/**
	 * Format an amount.
	 *
	 * @param float|int|string     $amount   Amount.
	 * @param array<string, mixed> $currency Currency: code, symbol, precision, thousand_separator, decimal_separator, swap_currency_symbol.
	 * @param array<string, mixed> $country  Country overrides: thousand_separator, decimal_separator, swap_currency_symbol.
	 * @param bool                 $show_code Show the currency code instead of the symbol.
	 */
	public static function format( float|int|string $amount, array $currency, array $country = array(), bool $show_code = false ): string {
		$value = (float) $amount;
		if ( ! $currency ) {
			return number_format( $value, 2 );
		}
		$thousand  = (string) ( $currency['thousand_separator'] ?? ',' );
		$decimal   = (string) ( $currency['decimal_separator'] ?? '.' );
		$swap      = ! empty( $currency['swap_currency_symbol'] );
		$precision = (int) ( $currency['precision'] ?? 2 );
		if ( isset( $country['thousand_separator'] ) && strlen( (string) $country['thousand_separator'] ) >= 1 ) {
			$thousand = (string) $country['thousand_separator'];
		}
		if ( isset( $country['decimal_separator'] ) && strlen( (string) $country['decimal_separator'] ) >= 1 ) {
			$decimal = (string) $country['decimal_separator'];
		}
		if ( ! empty( $country['swap_currency_symbol'] ) ) {
			$swap = true;
		}
		$formatted = number_format( $value, $precision, $decimal, $thousand );
		$symbol    = (string) ( $currency['symbol'] ?? '' );
		$code      = (string) ( $currency['code'] ?? '' );
		if ( $show_code ) {
			return 'CHF' === $code ? "{$code} {$formatted}" : "{$formatted} {$code}";
		}
		if ( $swap ) {
			return "{$formatted} " . trim( $symbol );
		}
		if ( $value < 0 ) {
			return '-' . $symbol . substr( $formatted, 1 );
		}
		return $symbol . $formatted;
	}

	/**
	 * Round to a currency's precision, for totals.
	 *
	 * @param float $amount    Amount.
	 * @param int   $precision Decimal places.
	 */
	public static function round( float $amount, int $precision = 2 ): float {
		return round( $amount, $precision, PHP_ROUND_HALF_UP );
	}
}
