<?php
/**
 * WooCommerce orders become Invoice Ninja invoices.
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
 * Woo is the store, Invoice Ninja is the ledger. Every order is turned into an invoice
 * in the background:
 * 1. find or create the client (billing details; an existing client is never changed);
 * 2. create the invoice as a draft, with the products, shipping, fees, discounts and
 *    the taxes WooCommerce charged;
 * 3. reconcile: a rounding difference from WooCommerce's total is closed with an
 *    adjustment line while it's still a draft, a larger one is flagged for an admin;
 * 4. mark it sent without Invoice Ninja's email.
 *
 * Idempotent: po_number is the WooCommerce order number, and an invoice is always looked
 * up by it before one is created. One job at a time works on an order (an atomic lock).
 */
final class OrderToInvoice {

	public const HOOK_SYNC   = 'invoiceninja_woo_invoice';
	public const HOOK_CANCEL = 'invoiceninja_woo_cancel';

	/** Orders WooCommerce hasn't really created yet. */
	private const NOT_YET = array( 'checkout-draft', 'auto-draft', 'draft', 'trash', 'new' );

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param IdMaps $ids    Currency and country ids.
	 */
	public function __construct( private Plugin $plugin, private IdMaps $ids ) {}

	/**
	 * Register order hooks and job handlers.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', fn( $order_id ) => $this->queue( (int) $order_id ), 20, 1 );
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			function ( $order ): void {
				if ( $order instanceof \WC_Order ) {
					$this->queue( $order->get_id() );
				}
			},
			20,
			1
		);
		// Orders created or saved on the admin order screen (classic and HPOS).
		add_action( 'woocommerce_process_shop_order_meta', fn( $order_id ) => $this->queue( (int) $order_id ), 60, 1 );
		add_action(
			'woocommerce_rest_insert_shop_order_object',
			function ( $order, $request, $creating ): void {
				unset( $request );
				if ( $order instanceof \WC_Order && $creating ) {
					$this->queue( $order->get_id() );
				}
			},
			20,
			3
		);
		// Items edited on an existing order: update the invoice.
		add_action( 'woocommerce_saved_order_items', fn( $order_id ) => $this->queue( (int) $order_id ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', fn( $order_id ) => $this->queueCancel( (int) $order_id ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', fn( $order_id ) => $this->queueCancel( (int) $order_id ), 20, 1 );

		$this->plugin->queue->handle( self::HOOK_SYNC, array( $this, 'handleSync' ) );
		$this->plugin->queue->handle( self::HOOK_CANCEL, array( $this, 'handleCancel' ) );
	}

	/**
	 * Queue the invoice for an order (created, or updated after an edit).
	 *
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $extra    Extra payload (e.g. backfill run).
	 * @param int                  $delay    Seconds.
	 */
	public function queue( int $order_id, array $extra = array(), int $delay = 0 ): void {
		if ( $order_id <= 0 || ! $this->plugin->options->setting( 'woo.invoices' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), self::NOT_YET, true ) ) {
			return;
		}
		$this->plugin->queue->push( self::HOOK_SYNC, array( 'order_id' => $order_id ) + $extra, $delay );
	}

	/**
	 * Queue cancelling the invoice of a cancelled or failed order.
	 *
	 * @param int $order_id Order id.
	 */
	public function queueCancel( int $order_id ): void {
		if ( $order_id > 0 && $this->plugin->options->setting( 'woo.invoices' ) ) {
			$this->plugin->queue->push( self::HOOK_CANCEL, array( 'order_id' => $order_id ) );
		}
	}

	/**
	 * Job: create or update the invoice.
	 *
	 * @param array<string, mixed> $payload {order_id, backfill?}.
	 * @throws ApiException To let the queue retry.
	 */
	public function handleSync( array $payload ): void {
		$order_id = (int) ( $payload['order_id'] ?? 0 );
		$run      = (string) ( $payload['backfill'] ?? '' );
		try {
			$this->sync( $order_id, '' !== $run, $run );
		} catch ( ApiException $e ) {
			if ( '' !== $run && ! $e->kind->retryable() ) {
				do_action( 'invoiceninja_woo_backfill_item_done', $order_id, $run ); // Done, if not well: it shows on the order.
			}
			throw $e;
		}
		if ( '' !== $run ) {
			/**
			 * Fires when a backfilled order has been handled.
			 *
			 * @since 2.0.0
			 * @param int    $order_id Order id.
			 * @param string $run      Backfill run id.
			 */
			do_action( 'invoiceninja_woo_backfill_item_done', $order_id, $run );
		}
	}

	/**
	 * Job: cancel the invoice.
	 *
	 * @param array<string, mixed> $payload {order_id}.
	 * @throws ApiException To let the queue retry.
	 */
	public function handleCancel( array $payload ): void {
		$order_id = (int) ( $payload['order_id'] ?? 0 );
		$this->withLock(
			$order_id,
			fn() => $this->cancel( $order_id ),
			fn() => $this->plugin->queue->push( self::HOOK_CANCEL, array( 'order_id' => $order_id ), 30, true )
		);
	}

	/**
	 * Make sure the order has an up-to-date, sent invoice.
	 *
	 * @param int    $order_id Order id.
	 * @param bool   $quiet    No admin emails (backfill).
	 * @param string $run      Backfill run id, kept when the job is queued again.
	 * @return array<string, mixed>|null The invoice, or null when there's none (yet).
	 * @throws ApiException When Invoice Ninja should be asked again later.
	 */
	public function sync( int $order_id, bool $quiet = false, string $run = '' ): ?array {
		$result  = null;
		$payload = array( 'order_id' => $order_id ) + ( '' !== $run ? array( 'backfill' => $run ) : array() );
		$this->withLock(
			$order_id,
			function () use ( $order_id, $quiet, $payload, &$result ): void {
				$result = $this->syncLocked( $order_id, $quiet, $payload );
			},
			fn() => $this->plugin->queue->push( self::HOOK_SYNC, $payload, 30, true )
		);
		return $result;
	}

	/**
	 * Run while holding the order's lock; or call $busy when another job holds it.
	 *
	 * @param int      $order_id Order id.
	 * @param callable $work     Work.
	 * @param callable $busy     Called when locked.
	 * @throws ApiException From $work.
	 */
	public function withLock( int $order_id, callable $work, callable $busy ): void {
		if ( $order_id <= 0 ) {
			return;
		}
		$key = 'woo_lock_' . $order_id;
		if ( ! $this->plugin->options->addAtomic( $key, (string) time() ) ) {
			$since = (int) $this->plugin->options->get( $key, 0 );
			if ( $since > time() - 5 * MINUTE_IN_SECONDS ) {
				$busy();
				return;
			}
			// A job that died holding it.
			$this->plugin->options->delete( $key );
			if ( ! $this->plugin->options->addAtomic( $key, (string) time() ) ) {
				$busy();
				return;
			}
		}
		try {
			$work();
		} finally {
			$this->plugin->options->delete( $key );
		}
	}

	/**
	 * The sync itself.
	 *
	 * @param int                  $order_id Order id.
	 * @param bool                 $quiet    No admin emails.
	 * @param array<string, mixed> $payload  The job payload, for queueing it again.
	 * @return array<string, mixed>|null
	 * @throws ApiException When Invoice Ninja should be asked again later.
	 */
	private function syncLocked( int $order_id, bool $quiet, array $payload = array() ): ?array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), self::NOT_YET, true ) ) {
			return null;
		}
		if ( ! $this->plugin->environment->writesAllowed() ) {
			OrderMeta::set( $order, array( OrderMeta::STATUS => 'paused' ) );
			$this->plugin->queue->push( self::HOOK_SYNC, $payload ? $payload : array( 'order_id' => $order_id ), 900, true );
			return null;
		}
		$client = $this->plugin->api();
		try {
			if ( '' !== OrderMeta::text( $order, OrderMeta::INVOICE_ID ) ) {
				return $this->update( $client, $order, $quiet );
			}
			if ( in_array( $order->get_status(), array( 'cancelled', 'failed' ), true ) ) {
				// Cancelled before it was invoiced: nothing to create (a lost reply is handled by cancel()).
				OrderMeta::set( $order, array( OrderMeta::STATUS => 'skipped' ) );
				return null;
			}
			return $this->create( $client, $order, $quiet );
		} catch ( ApiException $e ) {
			$this->failed( $order, $e );
			throw $e;
		}
	}

	/**
	 * Create (or find) the invoice, reconcile it and mark it sent.
	 *
	 * @param HttpClient $client API client.
	 * @param \WC_Order  $order  Order.
	 * @param bool       $quiet  No admin emails.
	 * @return array<string, mixed>|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private function create( HttpClient $client, \WC_Order $order, bool $quiet ): ?array {
		$found = $this->clientFor( $client, $order );
		if ( null === $found ) {
			return null;
		}
		$plain     = LineItems::fromOrder( $order );
		$lines     = LineItems::build( $plain );
		$precision = (int) $plain['precision'];
		$check     = Totals::reconcile( $lines, (float) $order->get_total(), $precision, __( 'Rounding', 'invoiceninja' ) );
		$invoice   = $this->findExisting( $client, $order, $found['client_id'] );
		if ( null === $invoice ) {
			try {
				$invoice = $client->post( 'invoices', $this->body( $order, $found['client_id'], $check['lines'] ), array(), 15.0 )->data();
			} catch ( ApiException $e ) {
				// It may have been created even though we didn't hear back.
				if ( ! $e->kind->outcomeUnknown() ) {
					throw $e;
				}
				$invoice = $this->findExisting( $client, $order, $found['client_id'] );
				if ( null === $invoice ) {
					throw $e;
				}
			}
		}
		if ( empty( $invoice['id'] ) ) {
			throw new ApiException( ErrorKind::InvalidResponse, 'Invoice Ninja returned no invoice id' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
		}
		OrderMeta::set(
			$order,
			array(
				OrderMeta::INVOICE_ID     => (string) $invoice['id'],
				OrderMeta::INVOICE_NUMBER => (string) ( $invoice['number'] ?? '' ),
				OrderMeta::STATUS         => '1' === (string) ( $invoice['status_id'] ?? '1' ) ? 'draft' : 'sent',
				OrderMeta::HASH           => LineItems::hash( $lines ),
				OrderMeta::ERROR          => null,
			)
		);
		$invoice = $this->settle( $client, $order, $invoice, $check, $precision, $quiet );
		$invoice = $this->markSent( $client, $order, $invoice );
		$order->add_order_note(
			sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice %s created in Invoice Ninja and marked sent (Invoice Ninja sent no email).', 'invoiceninja' ),
				(string) ( $invoice['number'] ?? '' )
			)
		);
		$this->plugin->logger->log( LogLevel::Info, 'woocommerce', sprintf( 'WooCommerce order %s: invoice %s created', $order->get_order_number(), (string) ( $invoice['number'] ?? '' ) ), array(), 'woo_order', (string) $order->get_id() );
		/**
		 * Fires when a WooCommerce order's invoice was created and marked sent.
		 *
		 * @since 2.0.0
		 * @param int   $order_id WooCommerce order id.
		 * @param array $invoice  Invoice Ninja invoice.
		 */
		do_action( 'invoiceninja_woo_invoice_created', $order->get_id(), $invoice );
		return $invoice;
	}

	/**
	 * Compare Invoice Ninja's total with WooCommerce's: close a rounding difference while
	 * the invoice is a draft, flag a larger one.
	 *
	 * @param HttpClient                                                                                  $client    API client.
	 * @param \WC_Order                                                                                   $order     Order.
	 * @param array<string, mixed>                                                                        $invoice   Invoice.
	 * @param array{lines: list<array<string, mixed>>, status: string, expected: float, computed: float, difference: float} $check Our reconciliation.
	 * @param int                                                                                         $precision Precision.
	 * @param bool                                                                                        $quiet     No admin emails.
	 * @return array<string, mixed>
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private function settle( HttpClient $client, \WC_Order $order, array $invoice, array $check, int $precision, bool $quiet ): array {
		$expected = round( (float) $order->get_total(), max( 2, $precision ) );
		$actual   = round( (float) ( $invoice['amount'] ?? 0 ), max( 2, $precision ) );
		$diff     = round( $expected - $actual, max( 2, $precision ) );
		$draft    = '1' === (string) ( $invoice['status_id'] ?? '' );
		if ( 0.0 !== $diff && $draft && abs( $diff ) <= Totals::tolerance( $check['lines'], $precision ) + 0.0000001 ) {
			// Invoice Ninja rounded differently than we predicted (e.g. it calculates taxes itself): one more adjustment.
			$lines   = $check['lines'];
			$lines[] = Totals::adjustment( $diff, __( 'Rounding', 'invoiceninja' ) );
			$invoice = $client->put( 'invoices/' . rawurlencode( (string) $invoice['id'] ), array( 'line_items' => $lines ), array(), 15.0 )->data();
			$actual  = round( (float) ( $invoice['amount'] ?? 0 ), max( 2, $precision ) );
			$diff    = round( $expected - $actual, max( 2, $precision ) );
		}
		$fields = array(
			OrderMeta::ADJUSTED => Totals::ADJUSTED === $check['status'] ? $check['difference'] : null,
			OrderMeta::MISMATCH => null,
		);
		if ( 0.0 !== $diff ) {
			$fields[ OrderMeta::MISMATCH ] = array(
				'expected'   => $expected,
				'invoice'    => $actual,
				'difference' => $diff,
			);
		}
		OrderMeta::set( $order, $fields );
		if ( 0.0 !== $diff ) {
			$this->mismatch( $order, $expected, $actual, $quiet );
		}
		return $invoice;
	}

	/**
	 * Mark the invoice sent (no email) and remember the buyer's invitation link.
	 *
	 * @param HttpClient           $client  API client.
	 * @param \WC_Order            $order   Order.
	 * @param array<string, mixed> $invoice Invoice.
	 * @return array<string, mixed>
	 * @throws ApiException When Invoice Ninja didn't do it.
	 */
	private function markSent( HttpClient $client, \WC_Order $order, array $invoice ): array {
		$id      = (string) $invoice['id'];
		$created = $invoice;
		if ( '1' === (string) ( $invoice['status_id'] ?? '1' ) ) {
			$invoice = self::pick(
				$client->post(
					'invoices/bulk',
					array(
						'action' => 'mark_sent',
						'ids'    => array( $id ),
					),
					array(),
					15.0
				)->data(),
				$id
			) ?? $invoice;
			if ( '1' === (string) ( $invoice['status_id'] ?? '1' ) ) {
				throw new ApiException( ErrorKind::InvalidResponse, "Invoice Ninja didn't mark the invoice sent" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
			}
		}
		$contact = OrderMeta::text( $order, OrderMeta::CONTACT_ID );
		$link    = '';
		// Only the buyer's own invitation: another contact's link would log them in as that contact.
		foreach ( (array) ( $invoice['invitations'] ?? $created['invitations'] ?? array() ) as $invitation ) {
			if ( is_array( $invitation ) && '' !== $contact && (string) ( $invitation['client_contact_id'] ?? '' ) === $contact ) {
				$link = esc_url_raw( (string) ( $invitation['link'] ?? '' ) );
			}
		}
		OrderMeta::set(
			$order,
			array(
				OrderMeta::STATUS         => 'sent',
				OrderMeta::INVOICE_NUMBER => (string) ( $invoice['number'] ?? OrderMeta::text( $order, OrderMeta::INVOICE_NUMBER ) ),
				OrderMeta::LINK           => '' !== $link ? $link : null,
			)
		);
		return $invoice;
	}

	/**
	 * After an order edit: update the invoice unless it's locked, paid or closed.
	 *
	 * @param HttpClient $client API client.
	 * @param \WC_Order  $order  Order.
	 * @param bool       $quiet  No admin emails.
	 * @return array<string, mixed>|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private function update( HttpClient $client, \WC_Order $order, bool $quiet ): ?array {
		$id = OrderMeta::text( $order, OrderMeta::INVOICE_ID );
		try {
			$invoice = $client->get( 'invoices/' . rawurlencode( $id ), array(), 10.0 )->data();
		} catch ( ApiException $e ) {
			if ( ErrorKind::NotFound !== $e->kind ) {
				throw $e;
			}
			$this->forget( $order, 'invoice_gone' );
			return null;
		}
		if ( ! empty( $invoice['is_deleted'] ) ) {
			$this->forget( $order, 'invoice_gone' );
			return null;
		}
		$status = (string) ( $invoice['status_id'] ?? '' );
		if ( in_array( $status, array( '5', '6' ), true ) ) {
			return $invoice; // Cancelled or reversed: nothing to update.
		}
		$plain     = LineItems::fromOrder( $order );
		$lines     = LineItems::build( $plain );
		$precision = (int) $plain['precision'];
		if ( LineItems::hash( $lines ) === OrderMeta::text( $order, OrderMeta::HASH ) ) {
			return '1' === $status ? $this->markSent( $client, $order, $invoice ) : $invoice; // Unchanged (or a retry after mark_sent failed).
		}
		if ( '4' === $status || (float) ( $invoice['paid_to_date'] ?? 0 ) > 0 ) {
			// Paid: changing it would leave the payment over or under the total. Flag it instead.
			OrderMeta::set(
				$order,
				array(
					OrderMeta::LOCKED => 'paid',
					OrderMeta::HASH   => LineItems::hash( $lines ),
				)
			); // Noted once per change.
			$order->add_order_note( __( "This order changed after it was paid, so its invoice in Invoice Ninja wasn't updated. Adjust it there if needed.", 'invoiceninja' ) );
			return $invoice;
		}
		if ( $this->locked( $client, $invoice ) ) {
			OrderMeta::set(
				$order,
				array(
					OrderMeta::LOCKED => 'locked',
					OrderMeta::HASH   => LineItems::hash( $lines ),
				)
			); // Noted once per change.
			$order->add_order_note( __( "This order changed, but Invoice Ninja locks sent invoices, so the invoice wasn't updated. Adjust it there if needed.", 'invoiceninja' ) );
			return $invoice;
		}
		$check = Totals::reconcile( $lines, (float) $order->get_total(), $precision, __( 'Rounding', 'invoiceninja' ) );
		try {
			$invoice = $client->put( 'invoices/' . rawurlencode( $id ), array( 'line_items' => $check['lines'] ), array(), 15.0 )->data();
		} catch ( ApiException $e ) {
			if ( ErrorKind::Validation === $e->kind && isset( $e->errors['number'] ) ) {
				OrderMeta::set(
					$order,
					array(
						OrderMeta::LOCKED => 'locked',
						OrderMeta::HASH   => LineItems::hash( $lines ),
					)
				); // Noted once per change.
				$order->add_order_note( __( "This order changed, but Invoice Ninja locks sent invoices, so the invoice wasn't updated. Adjust it there if needed.", 'invoiceninja' ) );
				return $invoice;
			}
			throw $e;
		}
		OrderMeta::set(
			$order,
			array(
				OrderMeta::HASH   => LineItems::hash( $lines ),
				OrderMeta::LOCKED => null,
			)
		);
		$this->compare( $order, $invoice, $precision, $quiet );
		$order->add_order_note(
			sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice %s updated in Invoice Ninja to match the order.', 'invoiceninja' ),
				(string) ( $invoice['number'] ?? OrderMeta::text( $order, OrderMeta::INVOICE_NUMBER ) )
			)
		);
		return '1' === (string) ( $invoice['status_id'] ?? '' ) ? $this->markSent( $client, $order, $invoice ) : $invoice;
	}

	/**
	 * Record whether an updated invoice still matches the order total.
	 *
	 * @param \WC_Order            $order     Order.
	 * @param array<string, mixed> $invoice   Invoice.
	 * @param int                  $precision Precision.
	 * @param bool                 $quiet     No admin emails.
	 */
	private function compare( \WC_Order $order, array $invoice, int $precision, bool $quiet ): void {
		$expected = round( (float) $order->get_total(), max( 2, $precision ) );
		$actual   = round( (float) ( $invoice['amount'] ?? 0 ), max( 2, $precision ) );
		$diff     = round( $expected - $actual, max( 2, $precision ) );
		OrderMeta::set(
			$order,
			array(
				OrderMeta::MISMATCH => 0.0 !== $diff ? array(
					'expected'   => $expected,
					'invoice'    => $actual,
					'difference' => $diff,
				) : null,
			)
		);
		if ( 0.0 !== $diff ) {
			$this->mismatch( $order, $expected, $actual, $quiet );
		}
	}

	/**
	 * Whether Invoice Ninja would refuse to change this invoice (the company's
	 * lock_invoices setting; checked hourly).
	 *
	 * @param HttpClient           $client  API client.
	 * @param array<string, mixed> $invoice Invoice.
	 */
	private function locked( HttpClient $client, array $invoice ): bool {
		$setting = get_transient( 'invoiceninja_woo_lock_invoices' );
		if ( ! is_string( $setting ) ) {
			try {
				$company = $client->post( 'companies/current', null, array(), 10.0, true )->data();
				$setting = (string) ( $company['settings']['lock_invoices'] ?? 'off' );
			} catch ( ApiException $e ) {
				$setting = 'off'; // Unknown: try, and let a 422 tell us.
			}
			set_transient( 'invoiceninja_woo_lock_invoices', sanitize_key( $setting ), HOUR_IN_SECONDS );
		}
		$status = (int) ( $invoice['status_id'] ?? 1 );
		return match ( $setting ) {
			'when_sent'    => $status >= 2,
			'when_paid'    => in_array( $status, array( 3, 4 ), true ),
			'end_of_month' => '' !== (string) ( $invoice['date'] ?? '' ) && strtotime( gmdate( 'Y-m-t 23:59:59', (int) strtotime( (string) $invoice['date'] ) ) ) < time(),
			default        => false,
		};
	}

	/**
	 * Cancel the invoice of a cancelled or failed order.
	 *
	 * @param int $order_id Order id.
	 * @throws ApiException When Invoice Ninja should be asked again later.
	 */
	public function cancel( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! in_array( $order->get_status(), array( 'cancelled', 'failed' ), true ) ) {
			return; // Reinstated meanwhile.
		}
		if ( ! $this->plugin->environment->writesAllowed() ) {
			$this->plugin->queue->push( self::HOOK_CANCEL, array( 'order_id' => $order_id ), 900, true );
			return;
		}
		$client = $this->plugin->api();
		$id     = OrderMeta::text( $order, OrderMeta::INVOICE_ID );
		try {
			if ( '' === $id ) {
				// A reply may have been lost while creating it: look before saying there's nothing.
				$client_id = OrderMeta::text( $order, OrderMeta::CLIENT_ID );
				$existing  = '' !== $client_id ? $this->findExisting( $client, $order, $client_id ) : null;
				if ( null === $existing ) {
					OrderMeta::set( $order, array( OrderMeta::STATUS => 'skipped' ) );
					return;
				}
				$id = (string) $existing['id'];
				OrderMeta::set(
					$order,
					array(
						OrderMeta::INVOICE_ID     => $id,
						OrderMeta::INVOICE_NUMBER => (string) ( $existing['number'] ?? '' ),
					)
				);
			}
			try {
				$invoice = $client->get( 'invoices/' . rawurlencode( $id ), array(), 10.0 )->data();
			} catch ( ApiException $e ) {
				if ( ErrorKind::NotFound !== $e->kind ) {
					throw $e;
				}
				$this->forget( $order, '', 'cancelled' );
				return;
			}
			$status = (string) ( $invoice['status_id'] ?? '' );
			if ( '5' === $status ) {
				$this->closed( $order, $id );
				return;
			}
			if ( '4' === $status || (float) ( $invoice['paid_to_date'] ?? 0 ) > 0 ) {
				OrderMeta::set( $order, array( OrderMeta::ERROR => 'has_payment' ) );
				$order->add_order_note( __( "The order was cancelled, but its invoice in Invoice Ninja has a payment, so it wasn't cancelled. Refund or adjust it there.", 'invoiceninja' ) );
				return;
			}
			if ( '1' === $status ) {
				$invoice = $this->markSent( $client, $order, $invoice ); // Invoice Ninja only cancels sent invoices.
			}
			$cancelled = self::pick(
				$client->post(
					'invoices/bulk',
					array(
						'action' => 'cancel',
						'ids'    => array( $id ),
					),
					array(),
					15.0
				)->data(),
				$id
			);
			if ( null === $cancelled || '5' !== (string) ( $cancelled['status_id'] ?? '' ) ) {
				throw new ApiException( ErrorKind::InvalidResponse, "Invoice Ninja didn't cancel the invoice" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
			}
		} catch ( ApiException $e ) {
			$this->failed( $order, $e );
			throw $e;
		}
		$this->closed( $order, $id );
		$order->add_order_note(
			sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice %s cancelled in Invoice Ninja.', 'invoiceninja' ),
				OrderMeta::text( $order, OrderMeta::INVOICE_NUMBER )
			)
		);
		$this->plugin->logger->log( LogLevel::Info, 'woocommerce', sprintf( 'WooCommerce order %s: invoice cancelled', $order->get_order_number() ), array(), 'woo_order', (string) $order->get_id() );
	}

	/**
	 * Record a cancelled invoice. It is never matched to this order again, so a later
	 * payment (a failed order paid on a second try) gets a new invoice.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Invoice id.
	 */
	public function closed( \WC_Order $order, string $id ): void {
		$ids = array_values( array_unique( array_merge( array_map( 'strval', OrderMeta::list( $order, OrderMeta::CANCELLED_IDS ) ), array( $id ) ) ) );
		OrderMeta::set(
			$order,
			array(
				OrderMeta::STATUS        => 'cancelled',
				OrderMeta::CANCELLED_IDS => $ids,
				OrderMeta::ERROR         => null,
			)
		);
	}

	/**
	 * Start over without the invoice (it was deleted, or cancelled before a payment).
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Error code to show, or ''.
	 * @param string    $status New status.
	 */
	public function forget( \WC_Order $order, string $reason, string $status = 'failed' ): void {
		OrderMeta::set(
			$order,
			array(
				OrderMeta::INVOICE_ID     => null,
				OrderMeta::INVOICE_NUMBER => null,
				OrderMeta::LINK           => null,
				OrderMeta::HASH           => null,
				OrderMeta::MISMATCH       => null,
				OrderMeta::LOCKED         => null,
				OrderMeta::STATUS         => $status,
				OrderMeta::ERROR          => '' !== $reason ? $reason : null,
			)
		);
	}

	/**
	 * The invoice body.
	 *
	 * @param \WC_Order                  $order     Order.
	 * @param string                     $client_id Client id.
	 * @param list<array<string, mixed>> $lines     Line items.
	 * @return array<string, mixed>
	 */
	private function body( \WC_Order $order, string $client_id, array $lines ): array {
		$created = $order->get_date_created();
		$body    = array(
			'client_id'            => $client_id,
			'po_number'            => (string) $order->get_order_number(),
			'line_items'           => $lines,
			'is_amount_discount'   => true,
			'uses_inclusive_taxes' => false,
			'discount'             => 0,
			/* translators: %s: WooCommerce order number */
			'private_notes'        => sprintf( __( 'WooCommerce order #%s', 'invoiceninja' ), $order->get_order_number() ),
		);
		if ( $created ) {
			$body['date'] = $created->date_i18n( 'Y-m-d' );
		}
		$coupons = $order->get_coupon_codes();
		if ( $coupons ) {
			/* translators: %s: coupon codes */
			$body['public_notes'] = sprintf( __( 'Coupon: %s', 'invoiceninja' ), implode( ', ', array_map( 'strval', $coupons ) ) );
		}
		/**
		 * The invoice sent to Invoice Ninja for a WooCommerce order.
		 *
		 * @since 2.0.0
		 * @param array     $body  Invoice body.
		 * @param \WC_Order $order Order.
		 */
		return (array) apply_filters( 'invoiceninja_woo_invoice_body', $body, $order );
	}

	/**
	 * Find or create the buyer's client; refuse a currency the client doesn't use.
	 *
	 * @param HttpClient $client API client.
	 * @param \WC_Order  $order  Order.
	 * @return array{client_id: string, contact_id: string}|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	public function clientFor( HttpClient $client, \WC_Order $order ): ?array {
		$known = OrderMeta::text( $order, OrderMeta::CLIENT_ID );
		// A currency mismatch keeps its client id for the admin to see, never to invoice.
		if ( '' !== $known && 'currency' !== OrderMeta::text( $order, OrderMeta::ERROR ) ) {
			return array(
				'client_id'  => $known,
				'contact_id' => OrderMeta::text( $order, OrderMeta::CONTACT_ID ),
			);
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $email || ! is_email( $email ) ) {
			OrderMeta::set(
				$order,
				array(
					OrderMeta::STATUS => 'failed',
					OrderMeta::ERROR  => 'no_email',
				)
			);
			return null;
		}
		$found    = $this->plugin->clients->findOrCreate( $client, $email, $this->details( $order ) );
		$currency = $this->ids->currency( (string) $order->get_currency() );
		$theirs   = (string) ( $found['client']['settings']['currency_id'] ?? '' );
		$theirs   = '' !== $theirs ? $theirs : (string) ( $this->plugin->state->get()['company']['currency_id'] ?? '' );
		if ( ! $found['created'] && '' !== $currency && '' !== $theirs && $currency !== $theirs ) {
			OrderMeta::set(
				$order,
				array(
					OrderMeta::STATUS => 'failed',
					OrderMeta::ERROR  => 'currency',
				)
			);
			$this->plugin->logger->log( LogLevel::Error, 'woocommerce', sprintf( "WooCommerce order %s is in %s, but the matching Invoice Ninja client uses another currency, so it wasn't invoiced", $order->get_order_number(), $order->get_currency() ), array(), 'woo_order', (string) $order->get_id() );
			return null;
		}
		OrderMeta::set(
			$order,
			array(
				OrderMeta::CLIENT_ID  => $found['client_id'],
				OrderMeta::CONTACT_ID => $found['contact_id'],
			)
		);
		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 && '' === (string) get_user_meta( $user_id, \InvoiceNinja\Portal\Links::CLIENT, true ) ) {
			$user = get_userdata( $user_id );
			if ( $user && strtolower( (string) $user->user_email ) === $email ) {
				update_user_meta( $user_id, \InvoiceNinja\Portal\Links::CLIENT, sanitize_text_field( $found['client_id'] ) );
				update_user_meta( $user_id, \InvoiceNinja\Portal\Links::CONTACT, sanitize_text_field( $found['contact_id'] ) );
			}
		}
		return array(
			'client_id'  => $found['client_id'],
			'contact_id' => $found['contact_id'],
		);
	}

	/**
	 * Client details from the billing (and shipping) address.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public function details( \WC_Order $order ): array {
		$details = array(
			'first_name'  => (string) $order->get_billing_first_name(),
			'last_name'   => (string) $order->get_billing_last_name(),
			'company'     => (string) $order->get_billing_company(),
			'phone'       => (string) $order->get_billing_phone(),
			'address1'    => (string) $order->get_billing_address_1(),
			'address2'    => (string) $order->get_billing_address_2(),
			'city'        => (string) $order->get_billing_city(),
			'state'       => (string) $order->get_billing_state(),
			'postal_code' => (string) $order->get_billing_postcode(),
			'country_id'  => $this->ids->country( (string) $order->get_billing_country() ),
			'currency_id' => $this->ids->currency( (string) $order->get_currency() ),
		);
		if ( '' !== (string) $order->get_shipping_address_1() ) {
			$details['client'] = array_filter(
				array(
					'shipping_address1'    => (string) $order->get_shipping_address_1(),
					'shipping_address2'    => (string) $order->get_shipping_address_2(),
					'shipping_city'        => (string) $order->get_shipping_city(),
					'shipping_state'       => (string) $order->get_shipping_state(),
					'shipping_postal_code' => (string) $order->get_shipping_postcode(),
					'shipping_country_id'  => $this->ids->country( (string) $order->get_shipping_country() ),
				),
				static fn( string $v ): bool => '' !== $v
			);
		}
		return $details;
	}

	/**
	 * An invoice already created for this order: same po_number and client, created
	 * after the order, not deleted, and not one we cancelled.
	 *
	 * @param HttpClient $client    API client.
	 * @param \WC_Order  $order     Order.
	 * @param string     $client_id Client id.
	 * @return array<string, mixed>|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	public function findExisting( HttpClient $client, \WC_Order $order, string $client_id ): ?array {
		$number    = (string) $order->get_order_number();
		$created   = $order->get_date_created();
		$after     = $created ? $created->getTimestamp() - 300 : 0;
		$cancelled = array_map( 'strval', OrderMeta::list( $order, OrderMeta::CANCELLED_IDS ) );
		$found     = $client->get(
			'invoices',
			array(
				'filter'    => $number,
				'client_id' => $client_id,
				'per_page'  => 50,
				'sort'      => 'id|desc',
			),
			10.0
		)->data();
		foreach ( $found as $invoice ) {
			if ( ! is_array( $invoice ) || (string) ( $invoice['po_number'] ?? '' ) !== $number || ! empty( $invoice['is_deleted'] ) ) {
				continue;
			}
			if ( (string) ( $invoice['client_id'] ?? '' ) !== $client_id || in_array( (string) ( $invoice['id'] ?? '' ), $cancelled, true ) ) {
				continue;
			}
			if ( isset( $invoice['created_at'] ) && (int) $invoice['created_at'] > 0 && (int) $invoice['created_at'] < $after ) {
				continue; // An older invoice with the same number (another shop, or a reinstall) is not this order's.
			}
			return $invoice;
		}
		return null;
	}

	/**
	 * The invoice with this id from a bulk response.
	 *
	 * @param array<mixed> $invoices Invoices.
	 * @param string       $id       Id.
	 * @return array<string, mixed>|null
	 */
	public static function pick( array $invoices, string $id ): ?array {
		foreach ( $invoices as $invoice ) {
			if ( is_array( $invoice ) && (string) ( $invoice['id'] ?? '' ) === $id ) {
				return $invoice;
			}
		}
		return null;
	}

	/**
	 * Record a failure on the order: retried ones stay queued with the reason.
	 *
	 * @param \WC_Order    $order Order.
	 * @param ApiException $e     Error.
	 */
	private function failed( \WC_Order $order, ApiException $e ): void {
		$fresh = wc_get_order( $order->get_id() );
		$order = $fresh instanceof \WC_Order ? $fresh : $order;
		OrderMeta::set(
			$order,
			array(
				OrderMeta::STATUS => $e->kind->retryable() ? ( '' !== OrderMeta::text( $order, OrderMeta::INVOICE_ID ) ? OrderMeta::text( $order, OrderMeta::STATUS ) : 'queued' ) : 'failed',
				OrderMeta::ERROR  => 'error:' . $e->kind->value,
			)
		);
		$this->plugin->logger->log(
			$e->kind->retryable() ? LogLevel::Warning : LogLevel::Error,
			'woocommerce',
			sprintf( 'WooCommerce order %s: %s', $order->get_order_number(), $e->kind->title() ),
			array( 'technical' => $e->technical ),
			'woo_order',
			(string) $order->get_id()
		);
	}

	/**
	 * Tell admins the invoice total differs from the order (once per order).
	 *
	 * @param \WC_Order $order    Order.
	 * @param float     $expected WooCommerce total.
	 * @param float     $actual   Invoice total.
	 * @param bool      $quiet    No email (backfill).
	 */
	private function mismatch( \WC_Order $order, float $expected, float $actual, bool $quiet ): void {
		$order->add_order_note(
			sprintf(
				/* translators: 1: invoice total, 2: order total */
				__( 'The invoice total in Invoice Ninja (%1$s) differs from this order (%2$s) by more than rounding. Check the taxes and lines in Invoice Ninja.', 'invoiceninja' ),
				wp_strip_all_tags( (string) wc_price( $actual, array( 'currency' => $order->get_currency() ) ) ),
				wp_strip_all_tags( (string) wc_price( $expected, array( 'currency' => $order->get_currency() ) ) )
			)
		);
		$this->plugin->logger->log( LogLevel::Error, 'woocommerce', sprintf( 'WooCommerce order %s: invoice total %.2f differs from the order total %.2f', $order->get_order_number(), $actual, $expected ), array(), 'woo_order', (string) $order->get_id() );
		if ( $quiet || ! $this->plugin->options->setting( 'notifications.attention' ) ) {
			return;
		}
		$this->plugin->mailer->send(
			$this->plugin->notifications->recipients(),
			/* translators: %s: order number */
			sprintf( __( 'WooCommerce order %s: invoice total differs', 'invoiceninja' ), $order->get_order_number() ),
			'<p>' . esc_html(
				sprintf(
					/* translators: 1: invoice total, 2: order total */
					__( 'The invoice in Invoice Ninja totals %1$s, but the order totals %2$s. The difference is more than rounding, so it was not adjusted automatically.', 'invoiceninja' ),
					wp_strip_all_tags( (string) wc_price( $actual, array( 'currency' => $order->get_currency() ) ) ),
					wp_strip_all_tags( (string) wc_price( $expected, array( 'currency' => $order->get_currency() ) ) )
				)
			) . '</p><p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Open the order', 'invoiceninja' ) . '</a></p>'
		);
	}
}
