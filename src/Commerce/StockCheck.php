<?php
/**
 * Live stock check before the email code is sent.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Commerce;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Plugin;
use InvoiceNinja\Sync\Mapping;

/**
 * Stock changes don't send product webhooks and the sync is hourly, so when Invoice
 * Ninja tracks inventory the cart's products are re-read just before checkout. The whole
 * check has a 3 second budget; if Invoice Ninja is slow the order continues on the synced
 * numbers (Invoice Ninja still has the final say when the invoice is created).
 */
final class StockCheck {

	public const BUDGET = 3.0;

	/**
	 * At most this many products are checked live; larger carts use the synced numbers.
	 */
	public const MAX_PRODUCTS = 10;

	/**
	 * A product checked this recently isn't asked about again.
	 */
	public const FRESH = 60;

	/**
	 * Live checks per hour for the whole site, whoever asks.
	 */
	public const SITE_HOURLY = 600;

	/**
	 * Constructor.
	 *
	 * @param Plugin  $plugin  Plugin.
	 * @param Cart    $cart    Cart.
	 * @param Mapping $mapping Mapping.
	 */
	public function __construct( private Plugin $plugin, private Cart $cart, private Mapping $mapping ) {}

	/**
	 * Refresh the stock of these cart items from Invoice Ninja.
	 *
	 * @param array<string, int> $items Quantities by Invoice Ninja product id.
	 * @return bool False when Invoice Ninja couldn't be asked in time (the synced numbers apply).
	 */
	public function refresh( array $items ): bool {
		if ( ! $items || ! $this->cart->tracksStock() ) {
			return true;
		}
		$client   = $this->plugin->api();
		$deadline = microtime( true ) + self::BUDGET;
		foreach ( array_slice( array_keys( $items ), 0, self::MAX_PRODUCTS ) as $product ) {
			$map  = $this->mapping->find( 'product', (string) $product );
			$left = $deadline - microtime( true );
			if ( null === $map || get_transient( 'invoiceninja_stock_seen_' . md5( (string) $product ) ) ) {
				continue;
			}
			if ( $left < 0.3 ) {
				return $this->slow();
			}
			// Bounded by the catalogue, not by visitors: each product is asked about at most once a minute.
			if ( $this->plugin->options->increment( 'ctr_stock_site_' . (int) floor( time() / HOUR_IN_SECONDS ) ) > self::SITE_HOURLY ) {
				return $this->slow();
			}
			set_transient( 'invoiceninja_stock_seen_' . md5( (string) $product ), 1, self::FRESH );
			try {
				$data = $client->get( 'products/' . rawurlencode( (string) $product ), array(), max( 0.3, min( 1.5, $left ) ) )->data();
			} catch ( ApiException $e ) {
				return $this->slow( $e );
			}
			$stock = (string) (int) ( $data['in_stock_quantity'] ?? 0 );
			$saved = (string) get_post_meta( $map['wp_id'], 'in_stock_quantity', true );
			if ( isset( $data['in_stock_quantity'] ) && $saved !== $stock ) {
				update_post_meta( $map['wp_id'], 'in_stock_quantity', (int) $stock );
				// The post no longer matches the last sync: let the next sync write it again even if Invoice Ninja looks unchanged.
				$this->mapping->save( 'product', (string) $product, 'post', $map['wp_id'], '' );
			}
		}
		return true;
	}

	/**
	 * Note that the live check was skipped.
	 *
	 * @param ApiException|null $e Error, if any.
	 */
	private function slow( ?ApiException $e = null ): bool {
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Debug, 'checkout', 'Live stock check skipped; using synced stock', $e ? array( 'kind' => $e->kind->value ) : array() );
		return false;
	}
}
