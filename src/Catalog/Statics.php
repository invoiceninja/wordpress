<?php
/**
 * Invoice Ninja's currencies and countries, for formatting money like Invoice Ninja does.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Catalog;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\Money;
use InvoiceNinja\Plugin;

/**
 * Only the company's own currency and country are kept (refreshed daily and on connect).
 */
final class Statics {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Fetch and keep the company's currency and country formats.
	 */
	public function refresh(): void {
		$company = (array) ( $this->plugin->state->get()['company'] ?? array() );
		if ( empty( $company['currency_id'] ) ) {
			return;
		}
		try {
			$statics = $this->plugin->api()->get( 'statics', array(), 10.0 )->json;
		} catch ( ApiException $e ) {
			return;
		}
		$pick = static function ( $rows, string $id ): array {
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $id ) {
					return array_intersect_key( $row, array_flip( array( 'id', 'code', 'symbol', 'precision', 'thousand_separator', 'decimal_separator', 'swap_currency_symbol', 'iso_3166_2', 'name' ) ) );
				}
			}
			return array();
		};
		$this->plugin->options->set(
			'money_format',
			array(
				'currency' => $pick( $statics['currencies'] ?? array(), (string) $company['currency_id'] ),
				'country'  => $pick( $statics['countries'] ?? array(), (string) ( $company['country_id'] ?? '' ) ),
			)
		);
	}

	/**
	 * The stored format.
	 *
	 * @return array{currency: array<string, mixed>, country: array<string, mixed>}
	 */
	public function format(): array {
		$format = (array) $this->plugin->options->get( 'money_format', array() );
		return array(
			'currency' => (array) ( $format['currency'] ?? array() ),
			'country'  => (array) ( $format['country'] ?? array() ),
		);
	}

	/**
	 * An amount in the company's currency.
	 *
	 * @param float $amount Amount.
	 */
	public function money( float $amount ): string {
		$format = $this->format();
		return $format['currency'] ? Money::format( $amount, $format['currency'], $format['country'] ) : number_format_i18n( $amount, 2 );
	}

	/**
	 * The company currency code (for orders), or an empty string.
	 */
	public function currencyCode(): string {
		return (string) ( $this->format()['currency']['code'] ?? '' );
	}

	/**
	 * What the front end needs to format money the same way.
	 *
	 * @return array<string, mixed>
	 */
	public function forScript(): array {
		$format   = $this->format();
		$currency = $format['currency'];
		$country  = $format['country'];
		$pick     = static fn( string $key, string $fallback ) => isset( $country[ $key ] ) && '' !== (string) $country[ $key ] ? (string) $country[ $key ] : (string) ( $currency[ $key ] ?? $fallback );
		return array(
			'symbol'    => (string) ( $currency['symbol'] ?? '' ),
			'precision' => (int) ( $currency['precision'] ?? 2 ),
			'thousand'  => $pick( 'thousand_separator', ',' ),
			'decimal'   => $pick( 'decimal_separator', '.' ),
			'swap'      => ! empty( $currency['swap_currency_symbol'] ) || ! empty( $country['swap_currency_symbol'] ),
		);
	}
}
