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
		$this->plugin->options->set( 'statics_lists', self::lists( $statics ) );
	}

	/**
	 * The countries (for the checkout's tax fields) and languages (for new clients), reduced
	 * to what the plugin uses.
	 *
	 * @param array<mixed> $statics Invoice Ninja's statics.
	 * @return array{countries: array<string, string>, languages: array<string, string>}
	 */
	public static function lists( array $statics ): array {
		$countries = array();
		foreach ( is_array( $statics['countries'] ?? null ) ? $statics['countries'] : array() as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['name'] ) && '' !== (string) $row['id'] ) {
				$countries[ (string) $row['id'] ] = sanitize_text_field( (string) $row['name'] );
			}
		}
		asort( $countries, SORT_NATURAL | SORT_FLAG_CASE );
		$languages = array();
		foreach ( is_array( $statics['languages'] ?? null ) ? $statics['languages'] : array() as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['locale'] ) && '' !== (string) $row['locale'] ) {
				$languages[ strtolower( str_replace( '-', '_', (string) $row['locale'] ) ) ] = (string) $row['id'];
			}
		}
		return array(
			'countries' => $countries,
			'languages' => $languages,
		);
	}

	/**
	 * Invoice Ninja's countries by id.
	 *
	 * @return array<string, string>
	 */
	public function countries(): array {
		$lists = (array) $this->plugin->options->get( 'statics_lists', array() );
		return array_map( 'strval', (array) ( $lists['countries'] ?? array() ) );
	}

	/**
	 * Invoice Ninja's language id for a WordPress locale (de_DE, then de), or ''.
	 *
	 * @param string $locale WordPress locale.
	 */
	public function languageId( string $locale ): string {
		$lists = (array) $this->plugin->options->get( 'statics_lists', array() );
		return self::matchLanguage( array_map( 'strval', (array) ( $lists['languages'] ?? array() ) ), $locale );
	}

	/**
	 * Match a WordPress locale against Invoice Ninja's locales: exact first, then the language.
	 *
	 * @param array<string, string> $languages Language ids by lower-case locale.
	 * @param string                $locale    WordPress locale, e.g. pt_BR.
	 */
	public static function matchLanguage( array $languages, string $locale ): string {
		$locale = strtolower( str_replace( '-', '_', $locale ) );
		if ( '' === $locale ) {
			return '';
		}
		if ( isset( $languages[ $locale ] ) ) {
			return $languages[ $locale ];
		}
		$base = explode( '_', $locale )[0];
		return $languages[ $base ] ?? '';
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
