<?php
/**
 * The shopper's cart.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Commerce;

use InvoiceNinja\Catalog\ProductSync;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Sync\Mapping;

/**
 * Stored server-side (transients) and keyed by a signed cookie set on the first add, so
 * cached pages stay cacheable: the cookie has no wp- prefix and pages render the cart in
 * the browser from REST. Prices and limits always come from the synced products, never
 * from the request.
 */
final class Cart {

	public const COOKIE = 'invoiceninja_cart';

	public const COUNT_COOKIE = 'invoiceninja_cart_count';

	public const TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Secrets $secrets Secrets.
	 * @param Mapping $mapping Mapping.
	 */
	public function __construct( private Secrets $secrets, private Mapping $mapping ) {}

	/**
	 * The current cart id from the cookie, if it's valid.
	 */
	public function currentId(): ?string {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return self::idFromCookie( $cookie, $this->secrets->get( 'cart_secret' ) );
	}

	/**
	 * The current cart id, creating one (and its cookie) when needed.
	 */
	public function ensureId(): string {
		$id = $this->currentId();
		if ( null !== $id ) {
			return $id;
		}
		$id = bin2hex( random_bytes( 16 ) );
		$ip = \InvoiceNinja\Plugin::clientIp();
		if ( \InvoiceNinja\Plugin::booted() && \InvoiceNinja\Plugin::instance()->options->increment( 'ctr_cart_' . substr( md5( Checkout::ipBucket( $ip ) ), 0, 16 ) . '_' . (int) floor( time() / HOUR_IN_SECONDS ) ) > 200 ) {
			throw new \RuntimeException( 'Too many carts from this address' );
		}
		$this->cookie( self::COOKIE, $id . '.' . self::sign( $id, $this->secrets->get( 'cart_secret' ) ), true );
		$_COOKIE[ self::COOKIE ] = $id . '.' . self::sign( $id, $this->secrets->get( 'cart_secret' ) );
		set_transient( 'invoiceninja_cart_at_' . $id, time(), self::TTL );
		return $id;
	}

	/**
	 * Seconds since the cart was started (its first add), or null when that isn't known.
	 *
	 * @param string $id Cart id.
	 */
	public function age( string $id ): ?int {
		$at = get_transient( 'invoiceninja_cart_at_' . $id );
		return is_numeric( $at ) && (int) $at > 0 ? max( 0, time() - (int) $at ) : null;
	}

	/**
	 * Validate a cookie value.
	 *
	 * @param string $cookie Cookie.
	 * @param string $secret Secret.
	 */
	public static function idFromCookie( string $cookie, string $secret ): ?string {
		$parts = explode( '.', $cookie );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[0-9a-f]{32}$/', $parts[0] ) ) {
			return null;
		}
		return hash_equals( self::sign( $parts[0], $secret ), $parts[1] ) ? $parts[0] : null;
	}

	/**
	 * Quantities by Invoice Ninja product id.
	 *
	 * @param string|null $id Cart id.
	 * @return array<string, int>
	 */
	public function items( ?string $id ): array {
		if ( null === $id ) {
			return array();
		}
		$items = get_transient( 'invoiceninja_cart_' . $id );
		return is_array( $items ) ? array_map( 'intval', $items ) : array();
	}

	/**
	 * Set a product's quantity (0 removes it), within the product's limits.
	 *
	 * @param string $id       Cart id.
	 * @param string $product  Invoice Ninja product id.
	 * @param int    $quantity Quantity.
	 * @param bool   $only     Make this the only product in the cart (Buy Now).
	 * @return array{ok: bool, quantity: int, message: string}
	 */
	public function set( string $id, string $product, int $quantity, bool $only = false ): array {
		$product = ProductSync::cleanId( $product );
		$info    = $this->product( $product );
		$items   = $this->items( $id );
		if ( null === $info ) {
			return array(
				'ok'       => false,
				'quantity' => 0,
				'message'  => __( "That product isn't available.", 'invoiceninja' ),
			);
		}
		$quantity = max( 0, min( $quantity, $info['max'] ) );
		$message  = '';
		if ( $only ) {
			$items = array(); // Buy Now: even a sold-out choice replaces what was there.
		}
		if ( $quantity < 1 ) {
			unset( $items[ $product ] );
		} else {
			$items[ $product ] = $quantity;
		}
		if ( $info['max'] < 1 ) {
			$message = __( 'Sold out', 'invoiceninja' );
		}
		set_transient( 'invoiceninja_cart_' . $id, $items, self::TTL );
		$this->cookie( self::COUNT_COOKIE, (string) array_sum( $items ), false );
		return array(
			'ok'       => true,
			'quantity' => $quantity,
			'message'  => $message,
		);
	}

	/**
	 * Empty the cart.
	 *
	 * @param string $id       Cart id.
	 * @param int    $order_id The order it became, so a resubmit after a lost reply finds it.
	 */
	public function clear( string $id, int $order_id = 0 ): void {
		delete_transient( 'invoiceninja_cart_' . $id );
		delete_transient( 'invoiceninja_cart_at_' . $id );
		if ( $order_id > 0 ) {
			set_transient( 'invoiceninja_cart_done_' . $id, $order_id, 15 * MINUTE_IN_SECONDS );
		}
		$this->cookie( self::COUNT_COOKIE, '0', false );
		// Forget the id: the next add starts a new cart (and a new order token).
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true );
		}
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * The order a cart just became, if it was checked out in the last 15 minutes.
	 *
	 * @param string $id Cart id.
	 */
	public function orderFor( string $id ): int {
		return (int) get_transient( 'invoiceninja_cart_done_' . $id );
	}

	/**
	 * Priced lines from the synced products. Unavailable products are dropped. Taxes are
	 * worked out the way Invoice Ninja does: each rate rounded on its own, added to the
	 * price, or backed out of it when the company's prices include tax.
	 *
	 * @param array<string, int> $items Items.
	 * @return array{lines: list<array<string, mixed>>, subtotal: float, tax: float, total: float, count: int, changed: list<string>, inclusive: bool}
	 */
	public function summary( array $items ): array {
		$lines     = array();
		$total     = 0.0;
		$subtotal  = 0.0;
		$taxes     = 0.0;
		$changed   = array();
		$inclusive = $this->inclusiveTaxes();
		foreach ( $items as $product => $quantity ) {
			$info = $this->product( (string) $product );
			if ( null === $info ) {
				$changed[] = (string) $product;
				continue;
			}
			$qty = min( (int) $quantity, $info['max'] );
			if ( $qty < 1 ) {
				$changed[] = (string) $product;
				continue;
			}
			if ( $qty !== (int) $quantity ) {
				$changed[] = (string) $product;
			}
			$line      = round( $info['price'] * $qty, 2 );
			$tax       = self::taxFor( $line, array( (float) $info['tax']['tax_rate1'], (float) $info['tax']['tax_rate2'], (float) $info['tax']['tax_rate3'] ), $inclusive );
			$subtotal += $line;
			$taxes    += $tax;
			$total    += $inclusive ? $line : $line + $tax;
			$lines[]   = array(
				'product'     => (string) $product,
				'post_id'     => $info['post_id'],
				'title'       => $info['title'],
				'product_key' => $info['product_key'],
				'notes'       => $info['notes'],
				'price'       => $info['price'],
				'quantity'    => $qty,
				'max'         => $info['max'],
				'line_total'  => $line,
				'tax'         => $info['tax'],
				'inclusive'   => $inclusive,
			);
		}
		return array(
			'lines'     => $lines,
			'subtotal'  => round( $subtotal, 2 ),
			'tax'       => round( $taxes, 2 ),
			'total'     => round( $total, 2 ),
			'count'     => (int) array_sum( array_column( $lines, 'quantity' ) ),
			'changed'   => $changed,
			'inclusive' => $inclusive,
		);
	}

	/**
	 * Tax on one line, rounded like Invoice Ninja: each rate on its own. With inclusive
	 * prices the tax is backed out of the line (the combined rate is factored out once),
	 * so the line total stays what the buyer pays.
	 *
	 * @param float       $line      Line total (price times quantity).
	 * @param list<float> $rates     Tax rates in percent.
	 * @param bool        $inclusive Prices include tax.
	 */
	public static function taxFor( float $line, array $rates, bool $inclusive ): float {
		$combined = 100.0 + array_sum( $rates );
		$tax      = 0.0;
		foreach ( $rates as $rate ) {
			if ( 0.0 === (float) $rate ) {
				continue;
			}
			if ( ! $inclusive ) {
				$tax += round( $line * $rate / 100, 2 );
			} elseif ( $combined > 0 ) {
				$tax += round( $line * $rate / $combined, 2 );
			}
		}
		return round( $tax, 2 );
	}

	/**
	 * Whether the company's prices include tax (Invoice Ninja's "inclusive taxes" setting).
	 */
	public function inclusiveTaxes(): bool {
		$state = get_option( 'invoiceninja_connection', array() );
		/**
		 * Whether product prices include tax. The order total and the invoice both follow it.
		 *
		 * @since 2.0.0
		 * @param bool $inclusive Prices include tax.
		 */
		return (bool) apply_filters( 'invoiceninja_inclusive_taxes', is_array( $state ) && ! empty( $state['company']['inclusive_taxes'] ) );
	}

	/**
	 * Price and limits of a published, synced product.
	 *
	 * @param string $product Invoice Ninja product id.
	 * @return array{post_id: int, title: string, product_key: string, notes: string, price: float, max: int, tax: array<string, mixed>}|null
	 */
	public function product( string $product ): ?array {
		$map = $this->mapping->find( 'product', $product );
		if ( null === $map || 'publish' !== get_post_status( $map['wp_id'] ) || post_password_required( $map['wp_id'] ) ) {
			return null;
		}
		$post_id = $map['wp_id'];
		$max     = 99;
		$limit   = (int) get_post_meta( $post_id, 'max_quantity', true );
		if ( $limit > 0 ) {
			$max = min( $max, $limit );
		}
		if ( $this->tracksStock() ) {
			$max = min( $max, max( 0, (int) get_post_meta( $post_id, 'in_stock_quantity', true ) ) );
		}
		return array(
			'post_id'     => $post_id,
			'title'       => (string) get_post_field( 'post_title', $post_id, 'raw' ),
			'product_key' => (string) ( get_post_meta( $post_id, 'product_key', true ) ?: get_post_field( 'post_title', $post_id, 'raw' ) ),
			'notes'       => wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ),
			'price'       => (float) get_post_meta( $post_id, 'price', true ),
			'max'         => $max,
			'tax'         => array(
				'tax_name1' => (string) get_post_meta( $post_id, 'tax_name1', true ),
				'tax_rate1' => (float) get_post_meta( $post_id, 'tax_rate1', true ),
				'tax_name2' => (string) get_post_meta( $post_id, 'tax_name2', true ),
				'tax_rate2' => (float) get_post_meta( $post_id, 'tax_rate2', true ),
				'tax_name3' => (string) get_post_meta( $post_id, 'tax_name3', true ),
				'tax_rate3' => (float) get_post_meta( $post_id, 'tax_rate3', true ),
			),
		);
	}

	/**
	 * Whether stock limits apply: the connected company tracks inventory.
	 */
	public function tracksStock(): bool {
		$state = get_option( 'invoiceninja_connection', array() );
		/**
		 * Whether stock limits apply (Invoice Ninja's "track inventory").
		 *
		 * @since 2.0.0
		 * @param bool $track Track inventory.
		 */
		return (bool) apply_filters( 'invoiceninja_track_inventory', is_array( $state ) && ! empty( $state['company']['track_inventory'] ) );
	}

	/**
	 * Bring the stored cart in line with what can be bought now (lower quantities, drop
	 * unavailable products), so "we've updated your cart" is true.
	 *
	 * @param string $id   Cart id.
	 * @param bool   $only Buy Now: keep only the most recently added product.
	 * @return array{lines: list<array<string, mixed>>, subtotal: float, tax: float, total: float, count: int, changed: list<string>, inclusive: bool}
	 */
	public function reconcile( string $id, bool $only = false ): array {
		$items   = $this->items( $id );
		$trimmed = '';
		if ( $only && count( $items ) > 1 ) {
			$trimmed = (string) array_key_last( $items );
			$items   = array( $trimmed => $items[ $trimmed ] );
		}
		$summary = $this->summary( $items );
		if ( '' !== $trimmed ) {
			$summary['changed'][] = $trimmed;
		}
		if ( $summary['changed'] ) {
			$saved = array();
			foreach ( $summary['lines'] as $line ) {
				$saved[ (string) $line['product'] ] = (int) $line['quantity'];
			}
			set_transient( 'invoiceninja_cart_' . $id, $saved, self::TTL );
			$this->cookie( self::COUNT_COOKIE, (string) array_sum( $saved ), false );
		}
		return $summary;
	}

	/**
	 * Signature for a cart id.
	 *
	 * @param string $id     Cart id.
	 * @param string $secret Secret.
	 */
	private static function sign( string $id, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'cart|' . $id, $secret ), 0, 32 );
	}

	/**
	 * Set a cookie on this response.
	 *
	 * @param string $name     Name.
	 * @param string $value    Value.
	 * @param bool   $httponly HttpOnly (the count cookie is readable by scripts).
	 */
	private function cookie( string $name, string $value, bool $httponly ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + self::TTL,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => $httponly,
				'samesite' => 'Lax',
			)
		);
	}
}
