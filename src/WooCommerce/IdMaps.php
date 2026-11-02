<?php
/**
 * Invoice Ninja's currency and country ids, by ISO code.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Plugin;

/**
 * WooCommerce speaks ISO codes; Invoice Ninja wants its own ids. The two small maps
 * (code => id) come from /statics and are kept for a day.
 */
final class IdMaps {

	public const OPTION = 'woo_statics';

	/**
	 * Loaded maps.
	 *
	 * @var array{currencies: array<string, string>, countries: array<string, string>, at: int}|null
	 */
	private ?array $maps = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Invoice Ninja's id for a currency code, or ''.
	 *
	 * @param string $code e.g. EUR.
	 */
	public function currency( string $code ): string {
		return $this->maps()['currencies'][ strtoupper( $code ) ] ?? '';
	}

	/**
	 * Invoice Ninja's id for a country code, or ''.
	 *
	 * @param string $code e.g. DE.
	 */
	public function country( string $code ): string {
		return $this->maps()['countries'][ strtoupper( $code ) ] ?? '';
	}

	/**
	 * The maps, fetched when missing or a day old (an outage keeps the old ones).
	 *
	 * @return array{currencies: array<string, string>, countries: array<string, string>, at: int}
	 */
	private function maps(): array {
		if ( null !== $this->maps ) {
			return $this->maps;
		}
		$stored = $this->plugin->options->get( self::OPTION );
		$maps   = is_array( $stored ) && isset( $stored['currencies'], $stored['countries'] ) ? array(
			'currencies' => (array) $stored['currencies'],
			'countries'  => (array) $stored['countries'],
			'at'         => (int) ( $stored['at'] ?? 0 ),
		) : array(
			'currencies' => array(),
			'countries'  => array(),
			'at'         => 0,
		);
		if ( $maps['at'] < time() - DAY_IN_SECONDS ) {
			try {
				$maps = self::fromStatics( $this->plugin->api()->get( 'statics', array(), 10.0 )->json );
				$this->plugin->options->set( self::OPTION, $maps );
			} catch ( ApiException $e ) {
				unset( $e ); // Keep what we had; a missing id only leaves the field out.
			}
		}
		$this->maps = $maps;
		return $maps;
	}

	/**
	 * Build the maps from a /statics response.
	 *
	 * @param array<mixed> $statics Response body.
	 * @return array{currencies: array<string, string>, countries: array<string, string>, at: int}
	 */
	public static function fromStatics( array $statics ): array {
		$currencies = array();
		foreach ( (array) ( $statics['currencies'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['code'] ) && isset( $row['id'] ) ) {
				$currencies[ strtoupper( (string) $row['code'] ) ] = (string) $row['id'];
			}
		}
		$countries = array();
		foreach ( (array) ( $statics['countries'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['iso_3166_2'] ) && isset( $row['id'] ) ) {
				$countries[ strtoupper( (string) $row['iso_3166_2'] ) ] = (string) $row['id'];
			}
		}
		return array(
			'currencies' => $currencies,
			'countries'  => $countries,
			'at'         => time(),
		);
	}
}
