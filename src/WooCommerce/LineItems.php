<?php
/**
 * Turns a WooCommerce order into Invoice Ninja line items.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

/**
 * Works on a plain array (see fromOrder()), so the mapping can be tested without
 * WooCommerce. Amounts are sent tax exclusive, the way WooCommerce stores them on
 * order items, with the tax rates WooCommerce charged on each line:
 * - products: product_key = SKU (or the name), cost = subtotal / quantity, and the
 *   coupon discount as an amount on the line, so tax is worked out after it;
 * - shipping and fees: one line each (a negative fee is a discount);
 * - compound rates, and rates whose percentage isn't known, are sent as the effective
 *   rate on that line, so Invoice Ninja's tax matches what was charged.
 */
final class LineItems {

	/**
	 * Item meta WooCommerce keeps to itself (its woocommerce_hidden_order_itemmeta list).
	 * Keys starting with an underscore are private too.
	 */
	public const HIDDEN_META = array( '_qty', '_tax_class', '_product_id', '_variation_id', '_line_subtotal', '_line_subtotal_tax', '_line_total', '_line_tax', 'method_id', 'cost', '_reduced_stock', '_restock_refunded_amount' );

	/**
	 * Build the line items.
	 *
	 * @param array<string, mixed> $order Plain order: items, shipping, fees (each with name,
	 *                                    total, taxes [rate_id => amount]; items also sku,
	 *                                    quantity, subtotal, notes) and rates
	 *                                    [rate_id => {label, percent, compound}].
	 * @return list<array<string, mixed>>
	 */
	public static function build( array $order ): array {
		$rates     = is_array( $order['rates'] ?? null ) ? $order['rates'] : array();
		$precision = (int) ( $order['precision'] ?? 2 );
		$lines     = array();
		foreach ( (array) ( $order['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$quantity = (float) ( $item['quantity'] ?? 0 );
			if ( $quantity <= 0 ) {
				continue;
			}
			$name     = trim( (string) ( $item['name'] ?? '' ) );
			$sku      = trim( (string) ( $item['sku'] ?? '' ) );
			$subtotal = (float) ( $item['subtotal'] ?? $item['total'] ?? 0 );
			$total    = (float) ( $item['total'] ?? $subtotal );
			$notes    = trim( (string) ( $item['notes'] ?? '' ) );
			if ( '' !== $sku && '' !== $name ) {
				$notes = '' !== $notes ? $name . "\n" . $notes : $name;
			}
			$line    = array(
				'product_key' => '' !== $sku ? $sku : $name,
				'notes'       => $notes,
				'cost'        => self::cost( $subtotal, $quantity ),
				'quantity'    => self::number( $quantity ),
				'discount'    => max( 0.0, round( $subtotal - $total, $precision ) ),
				'type_id'     => '1',
			);
			$lines[] = $line + self::taxes( (array) ( $item['taxes'] ?? array() ), $rates, $total );
		}
		foreach ( (array) ( $order['shipping'] ?? array() ) as $shipping ) {
			if ( ! is_array( $shipping ) ) {
				continue;
			}
			$total = (float) ( $shipping['total'] ?? 0 );
			$taxes = (array) ( $shipping['taxes'] ?? array() );
			if ( 0.0 === round( $total, 6 ) && 0.0 === round( (float) array_sum( $taxes ), 6 ) ) {
				continue; // Free shipping adds nothing to the invoice.
			}
			$lines[] = array(
				'product_key' => (string) ( $order['labels']['shipping'] ?? 'Shipping' ),
				'notes'       => trim( (string) ( $shipping['name'] ?? '' ) ),
				'cost'        => self::number( $total ),
				'quantity'    => 1,
				'discount'    => 0,
				'type_id'     => '1',
			) + self::taxes( $taxes, $rates, $total );
		}
		foreach ( (array) ( $order['fees'] ?? array() ) as $fee ) {
			if ( ! is_array( $fee ) ) {
				continue;
			}
			$total   = (float) ( $fee['total'] ?? 0 );
			$name    = trim( (string) ( $fee['name'] ?? '' ) );
			$lines[] = array(
				'product_key' => '' !== $name ? $name : (string) ( $order['labels']['fee'] ?? 'Fee' ),
				'notes'       => '',
				'cost'        => self::number( $total ),
				'quantity'    => 1,
				'discount'    => 0,
				'type_id'     => '1',
			) + self::taxes( (array) ( $fee['taxes'] ?? array() ), $rates, $total );
		}
		return $lines;
	}

	/**
	 * Up to three taxes for a line, as Invoice Ninja's tax_name1..3 and tax_rate1..3.
	 *
	 * @param array<int|string, mixed>                $taxes Rate id => amount charged on this line.
	 * @param array<int|string, array<string, mixed>> $rates Rate id => label, percent, compound.
	 * @param float                                   $base  The line's taxable amount (after discounts).
	 * @return array<string, mixed>
	 */
	public static function taxes( array $taxes, array $rates, float $base ): array {
		$out = array(
			'tax_name1' => '',
			'tax_rate1' => 0,
			'tax_name2' => '',
			'tax_rate2' => 0,
			'tax_name3' => '',
			'tax_rate3' => 0,
		);
		$n   = 0;
		foreach ( $taxes as $rate_id => $amount ) {
			$amount = is_numeric( $amount ) ? (float) $amount : 0.0;
			if ( 0.0 === round( $amount, 6 ) ) {
				continue;
			}
			if ( ++$n > 3 ) {
				break; // Invoice Ninja has three tax slots per line; the reconciler reports the rest.
			}
			$rate    = is_array( $rates[ $rate_id ] ?? null ) ? $rates[ $rate_id ] : array();
			$percent = isset( $rate['percent'] ) && is_numeric( $rate['percent'] ) ? (float) $rate['percent'] : 0.0;
			if ( ( ! empty( $rate['compound'] ) || $percent <= 0 ) && 0.0 !== round( $base, 6 ) ) {
				$percent = round( $amount / $base * 100, 6 );
			}
			$label                  = trim( (string) ( $rate['label'] ?? '' ) );
			$out[ 'tax_name' . $n ] = self::taxName( $label );
			$out[ 'tax_rate' . $n ] = self::number( $percent );
		}
		return $out;
	}

	/**
	 * A tax name Invoice Ninja totals: names shorter than two characters are left out of
	 * its tax summary.
	 *
	 * @param string $label WooCommerce label.
	 */
	public static function taxName( string $label ): string {
		$label = trim( str_replace( ',', ' ', $label ) );
		return strlen( $label ) >= 2 ? $label : 'Tax' . ( '' !== $label ? ' ' . $label : '' );
	}

	/**
	 * Unit cost from a line subtotal (six decimals, like Invoice Ninja's own storage).
	 *
	 * @param float $subtotal Subtotal.
	 * @param float $quantity Quantity.
	 */
	public static function cost( float $subtotal, float $quantity ): float|int {
		return self::number( $quantity > 0 ? round( $subtotal / $quantity, 6 ) : $subtotal );
	}

	/**
	 * A number without float noise (whole numbers as integers).
	 *
	 * @param float $value Value.
	 */
	public static function number( float $value ): float|int {
		$value = round( $value, 6 );
		return floor( $value ) === $value && abs( $value ) < PHP_INT_MAX ? (int) $value : $value;
	}

	/**
	 * A fingerprint of the lines, to tell whether an order edit changed anything:
	 * "v2:<what is charged>:<everything>", so a change to descriptions alone can be told apart.
	 *
	 * @param list<array<string, mixed>> $lines Lines.
	 */
	public static function hash( array $lines ): string {
		return 'v2:' . sha1( (string) wp_json_encode( self::charged( $lines ) ) ) . ':' . sha1( (string) wp_json_encode( $lines ) );
	}

	/**
	 * How the lines differ from a stored fingerprint: same, notes (only the descriptions),
	 * changed, or unknown (no fingerprint, or one from before descriptions were told apart;
	 * compare with the invoice instead).
	 *
	 * @param string                     $stored Stored fingerprint.
	 * @param list<array<string, mixed>> $lines  Lines now.
	 */
	public static function compare( string $stored, array $lines ): string {
		$now = self::hash( $lines );
		if ( $now === $stored ) {
			return 'same';
		}
		if ( str_starts_with( $stored, 'v2:' ) ) {
			$charged = explode( ':', $now )[1];
			$before  = explode( ':', $stored )[1];
			return $before === $charged ? 'notes' : 'changed';
		}
		if ( '' !== $stored && sha1( (string) wp_json_encode( $lines ) ) === $stored ) {
			return 'same'; // An older fingerprint of the same lines.
		}
		return 'unknown';
	}

	/**
	 * Whether the invoice already charges what these lines charge (descriptions aside, and
	 * leaving out rounding lines).
	 *
	 * @param list<array<string, mixed>> $lines    Lines.
	 * @param array<mixed>               $invoiced The invoice's line_items.
	 * @param string                     $rounding product_key of rounding lines.
	 */
	public static function sameAsInvoice( array $lines, array $invoiced, string $rounding = 'Rounding' ): bool {
		$theirs = array();
		foreach ( $invoiced as $line ) {
			if ( is_array( $line ) && trim( (string) ( $line['product_key'] ?? '' ) ) !== $rounding ) {
				$theirs[] = $line;
			}
		}
		return self::charged( $lines ) === self::charged( $theirs );
	}

	/**
	 * What each line charges, normalised (no descriptions).
	 *
	 * @param array<array<mixed>> $lines Lines.
	 * @return list<array<string, mixed>>
	 */
	private static function charged( array $lines ): array {
		$out = array();
		foreach ( $lines as $line ) {
			$row = array( 'product_key' => trim( (string) ( $line['product_key'] ?? '' ) ) );
			foreach ( array( 'cost', 'quantity', 'discount' ) as $field ) {
				$row[ $field ] = self::number( (float) ( $line[ $field ] ?? 0 ) );
			}
			foreach ( array( 1, 2, 3 ) as $n ) {
				$row[ 'tax_name' . $n ] = trim( (string) ( $line[ 'tax_name' . $n ] ?? '' ) );
				$row[ 'tax_rate' . $n ] = self::number( (float) ( $line[ 'tax_rate' . $n ] ?? 0 ) );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Item meta worth showing the customer, as "Label: value" lines. Never private keys (an
	 * underscore, or WooCommerce's hidden list): they can hold costs or stock notes, and
	 * some are added after payment.
	 *
	 * @param list<array{key: string, label: string, value: string}> $meta   Formatted meta.
	 * @param list<string>                                           $hidden Hidden keys.
	 */
	public static function visibleMeta( array $meta, array $hidden = self::HIDDEN_META ): string {
		$notes = array();
		foreach ( $meta as $row ) {
			if ( '' === $row['key'] || str_starts_with( $row['key'], '_' ) || in_array( $row['key'], $hidden, true ) ) {
				continue;
			}
			$notes[] = trim( $row['label'] ) . ': ' . trim( $row['value'] );
		}
		return implode( "\n", $notes );
	}

	/**
	 * The plain array for build(), from a WooCommerce order.
	 *
	 * @param \WC_Order $order     Order.
	 * @param int|null  $precision Decimal places Invoice Ninja uses for the order's currency (default: the store's).
	 * @return array<string, mixed>
	 */
	public static function fromOrder( \WC_Order $order, ?int $precision = null ): array {
		// WooCommerce's own filter for the item meta it hides on the order screen.
		$hidden = array_values( array_map( 'strval', (array) apply_filters( 'woocommerce_hidden_order_itemmeta', self::HIDDEN_META ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
		$rates  = array();
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( ! $tax instanceof \WC_Order_Item_Tax ) {
				continue;
			}
			$rates[ (int) $tax->get_rate_id() ] = array(
				'label'    => (string) $tax->get_label(),
				'percent'  => (float) $tax->get_rate_percent(),
				'compound' => (bool) $tax->is_compound(),
			);
		}
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$taxes   = $item->get_taxes();
			$meta    = array();
			foreach ( $item->get_all_formatted_meta_data() as $row ) {
				$meta[] = array(
					'key'   => (string) $row->key,
					'label' => wp_strip_all_tags( (string) $row->display_key ),
					'value' => wp_strip_all_tags( (string) $row->display_value ),
				);
			}
			$items[] = array(
				'name'     => (string) $item->get_name(),
				'sku'      => $product instanceof \WC_Product ? (string) $product->get_sku() : '',
				'quantity' => (float) $item->get_quantity(),
				'subtotal' => (float) $item->get_subtotal(),
				'total'    => (float) $item->get_total(),
				'taxes'    => is_array( $taxes['total'] ?? null ) ? $taxes['total'] : array(),
				'notes'    => self::visibleMeta( $meta, $hidden ),
			);
		}
		$shipping = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}
			$taxes      = $item->get_taxes();
			$shipping[] = array(
				'name'  => (string) $item->get_name(),
				'total' => (float) $item->get_total(),
				'taxes' => is_array( $taxes['total'] ?? null ) ? $taxes['total'] : array(),
			);
		}
		$fees = array();
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Fee ) {
				continue;
			}
			$taxes  = $item->get_taxes();
			$fees[] = array(
				'name'  => (string) $item->get_name(),
				'total' => (float) $item->get_total(),
				'taxes' => is_array( $taxes['total'] ?? null ) ? $taxes['total'] : array(),
			);
		}
		return array(
			'currency'  => (string) $order->get_currency(),
			'precision' => $precision ?? ( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 ),
			'total'     => (float) $order->get_total(),
			'items'     => $items,
			'shipping'  => $shipping,
			'fees'      => $fees,
			'rates'     => $rates,
			'coupons'   => array_values( array_map( 'strval', $order->get_coupon_codes() ) ),
			'labels'    => array(
				'shipping' => __( 'Shipping', 'invoiceninja' ),
				'fee'      => __( 'Fee', 'invoiceninja' ),
			),
		);
	}
}
