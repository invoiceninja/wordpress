<?php
/**
 * WooCommerce payments and refunds, mirrored into Invoice Ninja.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Plugin;

/**
 * A payment is recorded only when the money is there: on woocommerce_payment_complete,
 * or when an admin moves an unpaid order to a paid status. Never on the status alone for
 * pay-on-delivery methods: cash on delivery reaches "processing" while still unpaid, so
 * it counts as paid only once the order is completed.
 *
 * Payments carry idempotency_key = wp_<site code>_<order id> and email_receipt=false; a
 * 422 for that key means it's already recorded, so it is looked up instead. Refunds are
 * recorded against the payment and invoice with gateway_refund=false (WooCommerce's
 * gateway already refunded the money).
 */
final class Payments {

	public const HOOK_PAYMENT = 'invoiceninja_woo_payment';
	public const HOOK_REFUND  = 'invoiceninja_woo_refund';

	/**
	 * Constructor.
	 *
	 * @param Plugin         $plugin   Plugin.
	 * @param OrderToInvoice $invoices Invoices.
	 */
	public function __construct( private Plugin $plugin, private OrderToInvoice $invoices ) {}

	/**
	 * Register hooks and job handlers.
	 */
	public function register(): void {
		add_action( 'woocommerce_payment_complete', fn( $order_id ) => $this->queue( (int) $order_id ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'statusChanged' ), 20, 4 );
		add_action(
			'woocommerce_order_refunded',
			function ( $order_id, $refund_id ): void {
				if ( $this->plugin->options->setting( 'woo.invoices' ) ) {
					$this->plugin->queue->push(
						self::HOOK_REFUND,
						array(
							'order_id'  => (int) $order_id,
							'refund_id' => (int) $refund_id,
						)
					);
				}
			},
			20,
			2
		);
		$this->plugin->queue->handle( self::HOOK_PAYMENT, array( $this, 'handlePayment' ) );
		$this->plugin->queue->handle( self::HOOK_REFUND, array( $this, 'handleRefund' ) );
	}

	/**
	 * Payment methods paid on delivery: their "processing" is not paid.
	 *
	 * @return list<string>
	 */
	public static function onDeliveryMethods(): array {
		/**
		 * Payment methods whose orders are only paid once completed (cash on delivery).
		 *
		 * @since 2.0.0
		 * @param list<string> $methods Gateway ids.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'invoiceninja_woo_pay_on_delivery_methods', array( 'cod' ) ) ) );
	}

	/**
	 * Whether a status change means the order was just paid.
	 *
	 * @param string       $from   Old status.
	 * @param string       $to     New status.
	 * @param string       $method Payment method.
	 * @param list<string> $paid   Paid statuses (wc_get_is_paid_statuses()).
	 */
	public static function becamePaid( string $from, string $to, string $method, array $paid ): bool {
		if ( in_array( $method, self::onDeliveryMethods(), true ) ) {
			return 'completed' === $to && 'completed' !== $from;
		}
		return in_array( $to, $paid, true ) && ! in_array( $from, $paid, true );
	}

	/**
	 * Whether an order counts as paid now (used by backfill and before recording).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function isPaid( \WC_Order $order ): bool {
		$status = $order->get_status();
		if ( in_array( (string) $order->get_payment_method(), self::onDeliveryMethods(), true ) ) {
			return in_array( $status, array( 'completed', 'refunded' ), true );
		}
		return $order->is_paid() || ( 'refunded' === $status && null !== $order->get_date_paid() );
	}

	/**
	 * Status changes: an admin marking an unpaid order paid (bank transfer, cheque), or a
	 * cash-on-delivery order completed.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order.
	 */
	public function statusChanged( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? array_values( array_map( 'strval', wc_get_is_paid_statuses() ) ) : array( 'processing', 'completed' );
		if ( self::becamePaid( (string) $from, (string) $to, (string) $order->get_payment_method(), $paid ) ) {
			$this->queue( $order->get_id() );
		}
	}

	/**
	 * Queue recording the payment.
	 *
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $extra    Extra payload.
	 * @param int                  $delay    Seconds.
	 */
	public function queue( int $order_id, array $extra = array(), int $delay = 0 ): void {
		if ( $order_id > 0 && $this->plugin->options->setting( 'woo.invoices' ) ) {
			$this->plugin->queue->push( self::HOOK_PAYMENT, array( 'order_id' => $order_id ) + $extra, $delay );
		}
	}

	/**
	 * Job: record the payment.
	 *
	 * @param array<string, mixed> $payload {order_id}.
	 * @throws ApiException To let the queue retry.
	 */
	public function handlePayment( array $payload ): void {
		$order_id = (int) ( $payload['order_id'] ?? 0 );
		$this->record( $order_id, ! empty( $payload['backfill'] ) );
	}

	/**
	 * Job: mirror a refund.
	 *
	 * @param array<string, mixed> $payload {order_id, refund_id}.
	 * @throws ApiException To let the queue retry.
	 */
	public function handleRefund( array $payload ): void {
		$this->refund( (int) ( $payload['order_id'] ?? 0 ), (int) ( $payload['refund_id'] ?? 0 ) );
	}

	/**
	 * Record the order's payment in Invoice Ninja (once).
	 *
	 * @param int  $order_id Order id.
	 * @param bool $quiet    No admin emails (backfill).
	 * @throws ApiException When Invoice Ninja should be asked again later.
	 */
	public function record( int $order_id, bool $quiet = false ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || '' !== OrderMeta::text( $order, OrderMeta::PAYMENT_ID ) ) {
			return;
		}
		if ( ! self::isPaid( $order ) ) {
			return; // Not paid (any more): e.g. cash on delivery that isn't completed.
		}
		if ( (float) $order->get_total() <= 0 ) {
			return; // Nothing to record for a free order.
		}
		$invoice = $this->invoices->sync( $order_id, $quiet );
		$order   = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( null !== $invoice && '5' === (string) ( $invoice['status_id'] ?? '' ) ) {
			// The order failed and its invoice was cancelled, then it was paid after all: invoice it again.
			$this->invoices->closed( $order, (string) $invoice['id'] );
			$this->invoices->forget( $order, '', 'queued' );
			$order->add_order_note( __( 'This order was paid after its invoice was cancelled, so a new invoice is created in Invoice Ninja.', 'invoiceninja' ) );
			$invoice = $this->invoices->sync( $order_id, $quiet );
			$order   = wc_get_order( $order_id );
		}
		if ( null === $invoice || ! $order instanceof \WC_Order || 'sent' !== OrderMeta::text( $order, OrderMeta::STATUS ) ) {
			// Not invoiced yet (paused, or another job is on it): try again shortly. A failed
			// invoice waits for Retry, which records the payment too.
			$status = $order instanceof \WC_Order ? OrderMeta::text( $order, OrderMeta::STATUS ) : '';
			if ( in_array( $status, array( '', 'queued', 'paused', 'draft' ), true ) ) {
				$this->queue( $order_id, $quiet ? array( 'backfill' => 1 ) : array(), 120 );
			}
			return;
		}
		$this->invoices->withLock(
			$order_id,
			fn() => $this->recordLocked( $order_id, $invoice ),
			fn() => $this->queue( $order_id, array(), 30 )
		);
	}

	/**
	 * Record it while holding the order's lock.
	 *
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $invoice  Invoice (as last seen).
	 * @throws ApiException When Invoice Ninja should be asked again later.
	 */
	private function recordLocked( int $order_id, array $invoice ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || '' !== OrderMeta::text( $order, OrderMeta::PAYMENT_ID ) ) {
			return;
		}
		$client     = $this->plugin->api();
		$invoice_id = (string) $invoice['id'];
		$invoice    = $client->get( 'invoices/' . rawurlencode( $invoice_id ), array(), 10.0 )->data();
		$client_id  = (string) ( $invoice['client_id'] ?? OrderMeta::text( $order, OrderMeta::CLIENT_ID ) );
		$key        = self::key( $this->plugin->secrets->siteCode(), $order_id );
		$balance    = round( (float) ( $invoice['balance'] ?? 0 ), 2 );
		if ( $balance <= 0 ) {
			// Already paid in Invoice Ninja (by hand, or by us with a reply that got lost).
			$existing = $this->findPayment( $client, $client_id, $key, $invoice_id );
			OrderMeta::set(
				$order,
				array(
					OrderMeta::PAYMENT_ID     => null !== $existing ? (string) $existing['id'] : 'external',
					OrderMeta::PAYMENT_AMOUNT => null !== $existing ? (float) ( $existing['amount'] ?? 0 ) : 0.0,
				)
			);
			if ( null === $existing ) {
				$order->add_order_note( __( 'The invoice was already paid in Invoice Ninja, so no payment was recorded.', 'invoiceninja' ) );
			}
			return;
		}
		$amount  = round( min( (float) $order->get_total(), $balance ), 2 );
		$paid_at = $order->get_date_paid() ?? $order->get_date_completed();
		$txn     = trim( (string) $order->get_transaction_id() );
		$body    = array(
			'client_id'             => $client_id,
			'amount'                => $amount,
			'date'                  => $paid_at ? $paid_at->date_i18n( 'Y-m-d' ) : wp_date( 'Y-m-d' ),
			'transaction_reference' => '' !== $txn ? $txn . ' · ' . $key : $key,
			'idempotency_key'       => $key,
			/* translators: 1: order number, 2: payment method */
			'private_notes'         => sprintf( __( 'WooCommerce order #%1$s (%2$s)', 'invoiceninja' ), $order->get_order_number(), (string) $order->get_payment_method_title() ),
			'invoices'              => array(
				array(
					'invoice_id' => $invoice_id,
					'amount'     => $amount,
				),
			),
		);
		try {
			$payment = $client->post( 'payments', $body, array( 'email_receipt' => 'false' ), 15.0 )->data();
		} catch ( ApiException $e ) {
			$duplicate = ErrorKind::Validation === $e->kind && isset( $e->errors['idempotency_key'] );
			$unknown   = $e->kind->outcomeUnknown();
			$payment   = $duplicate || $unknown ? $this->findPayment( $client, $client_id, $key, $invoice_id ) : null;
			if ( null === $payment ) {
				OrderMeta::set( $order, array( OrderMeta::ERROR => $duplicate ? 'payment_key_taken' : 'payment:' . $e->kind->value ) );
				$this->plugin->logger->log( $e->kind->retryable() ? LogLevel::Warning : LogLevel::Error, 'woocommerce', sprintf( "WooCommerce order %s: the payment couldn't be recorded: %s", $order->get_order_number(), $e->kind->title() ), array( 'technical' => $e->technical ), 'woo_order', (string) $order_id );
				throw $e;
			}
		}
		OrderMeta::set(
			$order,
			array(
				OrderMeta::PAYMENT_ID     => (string) ( $payment['id'] ?? '' ),
				OrderMeta::PAYMENT_AMOUNT => (float) ( $payment['amount'] ?? $amount ),
				OrderMeta::ERROR          => null,
			)
		);
		$order->add_order_note(
			sprintf(
				/* translators: %s: amount */
				__( 'Payment of %s recorded in Invoice Ninja (no receipt email).', 'invoiceninja' ),
				wp_strip_all_tags( (string) wc_price( (float) ( $payment['amount'] ?? $amount ), array( 'currency' => $order->get_currency() ) ) )
			)
		);
		$this->plugin->logger->log( LogLevel::Info, 'woocommerce', sprintf( 'WooCommerce order %s: payment recorded', $order->get_order_number() ), array(), 'woo_order', (string) $order_id );
	}

	/**
	 * The idempotency key for an order's payment.
	 *
	 * @param string $site_code Site code.
	 * @param int    $order_id  Order id.
	 */
	public static function key( string $site_code, int $order_id ): string {
		return 'wp_' . $site_code . '_' . $order_id;
	}

	/**
	 * A payment we recorded before: its transaction reference carries the key. Invoice
	 * Ninja doesn't return idempotency_key, so the reference, client and invoice must all
	 * match.
	 *
	 * @param HttpClient $client     API client.
	 * @param string     $client_id  Client id.
	 * @param string     $key        Idempotency key.
	 * @param string     $invoice_id Invoice id.
	 * @return array<string, mixed>|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	public function findPayment( HttpClient $client, string $client_id, string $key, string $invoice_id ): ?array {
		$found = $client->get(
			'payments',
			array(
				'filter'    => $key,
				'client_id' => $client_id,
				'include'   => 'paymentables',
				'per_page'  => 20,
				'sort'      => 'id|desc',
			),
			10.0
		)->data();
		foreach ( $found as $payment ) {
			if ( ! is_array( $payment ) || ! empty( $payment['is_deleted'] ) || (string) ( $payment['client_id'] ?? '' ) !== $client_id ) {
				continue;
			}
			$reference = (string) ( $payment['transaction_reference'] ?? '' );
			if ( $reference !== $key && ! str_ends_with( $reference, ' · ' . $key ) ) {
				continue;
			}
			$paymentables = (array) ( $payment['paymentables'] ?? array() );
			if ( $paymentables && ! in_array( $invoice_id, array_map( static fn( $p ): string => is_array( $p ) ? (string) ( $p['invoice_id'] ?? '' ) : '', $paymentables ), true ) ) {
				continue; // Another site with the same code, on a different invoice.
			}
			return $payment;
		}
		return null;
	}

	/**
	 * Mirror one WooCommerce refund (once).
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @throws ApiException When Invoice Ninja should be asked again later.
	 */
	public function refund( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $refund_id <= 0 ) {
			return;
		}
		if ( isset( OrderMeta::list( $order, OrderMeta::REFUNDS )[ $refund_id ] ) ) {
			return;
		}
		if ( '' === OrderMeta::text( $order, OrderMeta::PAYMENT_ID ) && self::isPaid( $order ) ) {
			$this->record( $order_id ); // Refunded before the payment job ran: record the payment first.
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}
		}
		$payment_id = OrderMeta::text( $order, OrderMeta::PAYMENT_ID );
		if ( '' === $payment_id ) {
			if ( self::isPaid( $order ) ) {
				// Paid, but the payment isn't recorded yet (still queued): mirror the refund after it.
				$this->plugin->queue->push(
					self::HOOK_REFUND,
					array(
						'order_id'  => $order_id,
						'refund_id' => $refund_id,
					),
					300
				);
				return;
			}
			$this->remember( $order, $refund_id, 0.0, 'unpaid' );
			$order->add_order_note( __( 'This refund was not recorded in Invoice Ninja, because no payment was recorded there for this order.', 'invoiceninja' ) );
			return;
		}
		if ( 'external' === $payment_id ) {
			$this->remember( $order, $refund_id, 0.0, 'external' );
			$order->add_order_note( __( 'This refund was not recorded in Invoice Ninja, because the invoice was paid there by hand. Record the refund in Invoice Ninja.', 'invoiceninja' ) );
			return;
		}
		$this->invoices->withLock(
			$order_id,
			fn() => $this->refundLocked( $order_id, $refund_id, $payment_id ),
			fn() => $this->plugin->queue->push(
				self::HOOK_REFUND,
				array(
					'order_id'  => $order_id,
					'refund_id' => $refund_id,
				),
				30
			)
		);
	}

	/**
	 * Mirror the refund while holding the order's lock.
	 *
	 * @param int    $order_id   Order id.
	 * @param int    $refund_id  Refund id.
	 * @param string $payment_id Invoice Ninja payment id.
	 * @throws ApiException When Invoice Ninja should be asked again later.
	 */
	private function refundLocked( int $order_id, int $refund_id, string $payment_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund || isset( OrderMeta::list( $order, OrderMeta::REFUNDS )[ $refund_id ] ) ) {
			return;
		}
		$done     = OrderMeta::list( $order, OrderMeta::REFUNDS );
		$mirrored = 0.0;
		foreach ( $done as $row ) {
			$mirrored += is_array( $row ) ? (float) ( $row['amount'] ?? 0 ) : 0.0;
		}
		$paid   = (float) OrderMeta::text( $order, OrderMeta::PAYMENT_AMOUNT );
		$amount = round( min( (float) $refund->get_amount(), max( 0.0, $paid - $mirrored ) ), 2 );
		if ( $amount <= 0 ) {
			$this->remember( $order, $refund_id, 0.0, 'nothing' );
			return;
		}
		$client     = $this->plugin->api();
		$invoice_id = OrderMeta::text( $order, OrderMeta::INVOICE_ID );
		$created    = $refund->get_date_created();
		$body       = array(
			'id'             => $payment_id,
			'amount'         => $amount,
			'date'           => $created ? $created->date_i18n( 'Y-m-d' ) : wp_date( 'Y-m-d' ),
			'gateway_refund' => false,
			'send_email'     => false,
			'email_receipt'  => 'false',
		);
		if ( '' !== $invoice_id ) {
			$body['invoices'] = array(
				array(
					'invoice_id' => $invoice_id,
					'amount'     => $amount,
				),
			);
		}
		try {
			$before = (float) ( $client->get( 'payments/' . rawurlencode( $payment_id ), array(), 10.0 )->data()['refunded'] ?? 0 );
			$client->post( 'payments/refund', $body, array(), 15.0 );
		} catch ( ApiException $e ) {
			if ( ! $e->kind->outcomeUnknown() || ! isset( $before ) ) {
				OrderMeta::set( $order, array( OrderMeta::ERROR => 'refund:' . $e->kind->value ) );
				$this->plugin->logger->log( LogLevel::Error, 'woocommerce', sprintf( "WooCommerce order %s: the refund couldn't be recorded: %s", $order->get_order_number(), $e->kind->title() ), array( 'technical' => $e->technical ), 'woo_order', (string) $order_id );
				throw $e;
			}
			// We didn't hear back: it counts as done only if the payment's refunded total moved.
			$after = (float) ( $client->get( 'payments/' . rawurlencode( $payment_id ), array(), 10.0 )->data()['refunded'] ?? 0 );
			if ( round( $after - $before, 2 ) < $amount ) {
				throw $e;
			}
		}
		$this->remember( $order, $refund_id, $amount, 'mirrored' );
		$order->add_order_note(
			sprintf(
				/* translators: %s: amount */
				__( 'Refund of %s recorded in Invoice Ninja (no money was moved there).', 'invoiceninja' ),
				wp_strip_all_tags( (string) wc_price( $amount, array( 'currency' => $order->get_currency() ) ) )
			)
		);
		$this->plugin->logger->log( LogLevel::Info, 'woocommerce', sprintf( 'WooCommerce order %s: refund mirrored', $order->get_order_number() ), array(), 'woo_order', (string) $order_id );
		$this->closeBalance( $client, $order, $invoice_id );
	}

	/**
	 * After a refund the invoice owes the refunded amount again. A fully refunded order's
	 * invoice is cancelled, so the customer isn't asked to pay; after a partial refund the
	 * open balance is flagged for an admin (Invoice Ninja would otherwise remind them).
	 *
	 * @param HttpClient $client     API client.
	 * @param \WC_Order  $order      Order.
	 * @param string     $invoice_id Invoice id.
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private function closeBalance( HttpClient $client, \WC_Order $order, string $invoice_id ): void {
		if ( '' === $invoice_id ) {
			return;
		}
		$fresh   = wc_get_order( $order->get_id() );
		$order   = $fresh instanceof \WC_Order ? $fresh : $order;
		$invoice = $client->get( 'invoices/' . rawurlencode( $invoice_id ), array(), 10.0 )->data();
		$balance = round( (float) ( $invoice['balance'] ?? 0 ), 2 );
		if ( $balance <= 0 || in_array( (string) ( $invoice['status_id'] ?? '' ), array( '5', '6' ), true ) ) {
			OrderMeta::set( $order, array( OrderMeta::REFUND_BALANCE => null ) );
			return;
		}
		$fully = 'refunded' === $order->get_status() || (float) $order->get_remaining_refund_amount() <= 0;
		if ( ! $fully ) {
			OrderMeta::set( $order, array( OrderMeta::REFUND_BALANCE => $balance ) );
			return;
		}
		$cancelled = OrderToInvoice::pick(
			$client->post(
				'invoices/bulk',
				array(
					'action' => 'cancel',
					'ids'    => array( $invoice_id ),
				),
				array(),
				15.0
			)->data(),
			$invoice_id
		);
		if ( null !== $cancelled && '5' === (string) ( $cancelled['status_id'] ?? '' ) ) {
			OrderMeta::set(
				$order,
				array(
					OrderMeta::STATUS         => 'cancelled',
					OrderMeta::REFUND_BALANCE => null,
				)
			);
			$order->add_order_note( __( 'The order is fully refunded, so its invoice was cancelled in Invoice Ninja and the customer is not asked to pay.', 'invoiceninja' ) );
		} else {
			OrderMeta::set( $order, array( OrderMeta::REFUND_BALANCE => $balance ) );
		}
	}

	/**
	 * Remember a refund as handled.
	 *
	 * @param \WC_Order $order     Order.
	 * @param int       $refund_id Refund id.
	 * @param float     $amount    Amount mirrored.
	 * @param string    $result    mirrored, unpaid, external or nothing.
	 */
	private function remember( \WC_Order $order, int $refund_id, float $amount, string $result ): void {
		$fresh              = wc_get_order( $order->get_id() );
		$order              = $fresh instanceof \WC_Order ? $fresh : $order;
		$done               = OrderMeta::list( $order, OrderMeta::REFUNDS );
		$done[ $refund_id ] = array(
			'amount' => $amount,
			'result' => $result,
			'at'     => time(),
		);
		OrderMeta::set(
			$order,
			array(
				OrderMeta::REFUNDS => $done,
				OrderMeta::ERROR   => null,
			)
		);
	}
}
