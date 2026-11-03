<?php
/**
 * What the plugin keeps on a WooCommerce order.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

/**
 * Order meta, always through the order CRUD (never post meta), so it works with HPOS on
 * and off. Keys start with an underscore, so they stay out of the Custom Fields box.
 */
final class OrderMeta {

	public const INVOICE_ID     = '_invoiceninja_invoice_id';
	public const INVOICE_NUMBER = '_invoiceninja_invoice_number';
	/** Queued, paused, draft, sent, failed, cancelled or skipped. */
	public const STATUS     = '_invoiceninja_status';
	public const ERROR      = '_invoiceninja_error';
	public const CLIENT_ID  = '_invoiceninja_client_id';
	public const CONTACT_ID = '_invoiceninja_contact_id';
	/** The buyer's own invitation link (only ever put in emails to the buyer). */
	public const LINK = '_invoiceninja_invoice_link';
	public const HASH = '_invoiceninja_lines_hash';
	/** {expected, invoice, difference} when the totals differ by more than rounding. */
	public const MISMATCH       = '_invoiceninja_mismatch';
	public const ADJUSTED       = '_invoiceninja_adjusted';
	public const LOCKED         = '_invoiceninja_locked';
	public const PAYMENT_ID     = '_invoiceninja_payment_id';
	public const PAYMENT_AMOUNT = '_invoiceninja_payment_amount';
	/** Refund id => {amount, at} for refunds mirrored (or skipped) in Invoice Ninja. */
	public const REFUNDS = '_invoiceninja_refunds';
	/** {refund_id, amount, baseline, at}: a refund sent to Invoice Ninja whose reply was lost. */
	public const REFUND_PENDING = '_invoiceninja_refund_pending';
	/** How many times the order's invoice was replaced after its payment was recorded (part of the payment key). */
	public const PAYMENT_ROUND = '_invoiceninja_payment_round';
	/** Balance left open on the invoice after a partial refund. */
	public const REFUND_BALANCE = '_invoiceninja_refund_balance';
	/** Invoices this order had that were cancelled (never matched again). */
	public const CANCELLED_IDS = '_invoiceninja_cancelled_ids';
	public const UPDATED_AT    = '_invoiceninja_updated_at';

	/**
	 * Every key, for uninstall and privacy tools.
	 *
	 * @var list<string>
	 */
	public const KEYS = array(
		self::INVOICE_ID,
		self::INVOICE_NUMBER,
		self::STATUS,
		self::ERROR,
		self::CLIENT_ID,
		self::CONTACT_ID,
		self::LINK,
		self::HASH,
		self::MISMATCH,
		self::ADJUSTED,
		self::LOCKED,
		self::PAYMENT_ID,
		self::PAYMENT_AMOUNT,
		self::REFUNDS,
		self::REFUND_PENDING,
		self::PAYMENT_ROUND,
		self::REFUND_BALANCE,
		self::CANCELLED_IDS,
		self::UPDATED_AT,
	);

	/**
	 * Keys never handed out by WooCommerce's REST API (and so its webhooks): the
	 * invitation link logs whoever opens it into the buyer's portal.
	 *
	 * @var list<string>
	 */
	public const PRIVATE_KEYS = array( self::LINK );

	/**
	 * The woocommerce_rest_prepare_shop_order_object filter (HPOS and posts alike): drop
	 * private keys from the order's meta_data.
	 *
	 * @param mixed $response REST response.
	 * @return mixed
	 */
	public static function hideFromRest( $response ) {
		if ( $response instanceof \WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
				$data['meta_data'] = self::withoutPrivate( $data['meta_data'] );
				$response->set_data( $data );
			}
		}
		return $response;
	}

	/**
	 * Meta rows (WC_Meta_Data objects or arrays) without the private keys.
	 *
	 * @param array<mixed> $rows Rows.
	 * @return list<mixed>
	 */
	public static function withoutPrivate( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$key = '';
			if ( $row instanceof \WC_Meta_Data ) {
				$key = (string) ( $row->get_data()['key'] ?? '' );
			} elseif ( is_array( $row ) ) {
				$key = (string) ( $row['key'] ?? '' );
			} elseif ( is_object( $row ) && isset( $row->key ) ) {
				$key = (string) $row->key;
			}
			if ( ! in_array( $key, self::PRIVATE_KEYS, true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * A string value.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Key.
	 */
	public static function text( \WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key, true, 'edit' );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * An array value.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Key.
	 * @return array<mixed>
	 */
	public static function list( \WC_Order $order, string $key ): array {
		$value = $order->get_meta( $key, true, 'edit' );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Set several values and save the order once. A null value deletes the key.
	 *
	 * @param \WC_Order            $order  Order.
	 * @param array<string, mixed> $fields Key => value.
	 */
	public static function set( \WC_Order $order, array $fields ): void {
		foreach ( $fields as $key => $value ) {
			if ( null === $value ) {
				$order->delete_meta_data( $key );
			} else {
				$order->update_meta_data( $key, $value );
			}
		}
		$order->update_meta_data( self::UPDATED_AT, (string) time() );
		$order->save();
	}
}
