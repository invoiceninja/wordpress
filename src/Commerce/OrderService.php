<?php
/**
 * Turns orders into Invoice Ninja invoices.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Commerce;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Plugin;

/**
 * Placing is idempotent: the order number is the invoice's po_number, and after any
 * result we can't be sure of (timeout, "Duplicate request", server error) we search
 * Invoice Ninja by po_number before trying again, so one order never becomes two
 * invoices. When Invoice Ninja is down the order stays queued and a background job
 * retries; the buyer is told their payment link is on its way.
 */
final class OrderService {

	/** Background job that saves a delivery address on an existing client. */
	public const SHIPPING_HOOK = 'invoiceninja_client_shipping';

	public const CREATE_BUDGET = 8.0;

	/**
	 * Seconds an order's invoice is kept for its Order Status page (the price breakdown).
	 */
	public const INVOICE_CACHE = 5 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Orders $orders Orders.
	 */
	public function __construct( private Plugin $plugin, private Orders $orders ) {}

	/**
	 * Create the client and invoice for a queued order.
	 *
	 * @param array<string, mixed> $order Order.
	 * @param bool                 $inline Called during checkout (short time budget).
	 * @return array<string, mixed> The updated order.
	 * @throws ApiException When the order should be retried later (background jobs only).
	 */
	public function place( array $order, bool $inline = true ): array {
		if ( ! in_array( $order['status'], array( 'queued', 'placing' ), true ) ) {
			return $order;
		}
		if ( ! $this->plugin->environment->writesAllowed() ) {
			$this->orders->update( (int) $order['id'], array( 'note' => 'paused' ) );
			$this->plugin->queue->push( 'invoiceninja_place_order', array( 'order_id' => (int) $order['id'] ), 900, true );
			return (array) $this->orders->find( (int) $order['id'] );
		}
		// Only one request or job may place an order (double clicks, retries during a slow response).
		if ( ! $this->orders->claim( (int) $order['id'] ) ) {
			return (array) $this->orders->find( (int) $order['id'] );
		}
		$client = $this->client();
		try {
			$client_id = $this->clientFor( $client, $order );
			if ( null === $client_id ) {
				return $this->currencyMismatch( $order );
			}
			$order    = (array) $this->orders->find( (int) $order['id'] );
			$existing = $this->findExisting( $client, $order, $client_id );
			$invoice  = $existing ?? $this->createInvoice( $client, $order, $client_id );
		} catch ( ApiException $e ) {
			return $this->failed( $order, $e, $inline, $client );
		}
		return $this->placed( $order, $invoice );
	}

	/**
	 * Background job: retry a queued order.
	 *
	 * @param array<string, mixed> $payload {order_id}.
	 * @throws ApiException To let the queue retry.
	 */
	public function retry( array $payload ): void {
		$order = $this->orders->find( (int) ( $payload['order_id'] ?? 0 ) );
		if ( null === $order || ! in_array( $order['status'], array( 'queued', 'placing' ), true ) ) {
			return;
		}
		if ( ! $this->plugin->environment->writesAllowed() ) {
			$this->place( $order, false ); // Re-queues for later without counting an attempt: nothing was asked.
			return;
		}
		$attempts = (int) $order['attempts'] + 1;
		$this->orders->update( (int) $order['id'], array( 'attempts' => $attempts ) );
		if ( $attempts > \InvoiceNinja\Core\RetryPolicy::maxAttempts() ) {
			if ( ! $this->orders->transition( (int) $order['id'], array( 'queued' ), 'attention', array( 'note' => 'gave_up' ) ) ) {
				return;
			}
			$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Error, 'checkout', sprintf( "Order %s couldn't be sent to Invoice Ninja after %d attempts", $order['number'], $attempts - 1 ), array(), 'order', (string) $order['number'] );
			do_action( 'invoiceninja_order_needs_attention', (array) $this->orders->find( (int) $order['id'] ) );
			return;
		}
		$this->place( $order, false );
	}

	/**
	 * Cancel an order and its invoice in Invoice Ninja (the payment link stops working).
	 *
	 * @param array<string, mixed> $order  Order.
	 * @param bool                 $delete Delete the invoice instead of cancelling it (gives stock back, leaves a gap in invoice numbers).
	 * @return array<string, mixed> The cancelled order.
	 * @throws ApiException When Invoice Ninja can't be reached or refuses.
	 * @throws OrderConflict When the order can't be cancelled now.
	 */
	public function cancel( array $order, bool $delete = false ): array {
		$order = (array) $this->orders->find( (int) $order['id'] );
		switch ( $order['status'] ?? '' ) {
			case 'paid':
				throw new OrderConflict( esc_html__( 'This order is paid. Refund it in Invoice Ninja instead.', 'invoiceninja' ) );
			case 'cancelled':
				throw new OrderConflict( esc_html__( 'This order is already cancelled.', 'invoiceninja' ) );
			case 'placing':
				if ( ! Orders::staleClaim( $order ) ) {
					throw new OrderConflict( esc_html__( 'This order is being sent to Invoice Ninja right now. Try again in a minute.', 'invoiceninja' ) );
				}
				// The request placing it died: it's queued as far as anyone can tell.
				$this->orders->transition( (int) $order['id'], array( 'placing' ), 'queued' );
				$order = (array) $this->orders->find( (int) $order['id'] );
		}
		$client = $this->client();
		if ( '' === (string) $order['invoice_id'] ) {
			// Stop the background job from placing it first, then look for an invoice a lost reply may have created.
			if ( ! $this->orders->transition( (int) $order['id'], array( 'queued', 'attention' ), 'cancelled', array( 'note' => '' ) ) ) {
				throw new OrderConflict( esc_html__( 'This order changed in the meantime. Reload and try again.', 'invoiceninja' ) );
			}
			if ( '' !== (string) $order['client_id'] ) {
				try {
					$existing = $this->findExisting( $client, $order, (string) $order['client_id'] );
					if ( null !== $existing ) {
						$this->closeInvoice( $client, (string) $existing['id'], $delete );
						$this->orders->update(
							(int) $order['id'],
							array(
								'invoice_id'     => (string) $existing['id'],
								'invoice_number' => (string) ( $existing['number'] ?? '' ),
							)
						);
					}
				} catch ( ApiException $e ) {
					$this->orders->update( (int) $order['id'], array( 'note' => 'check_invoice' ) );
					$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Warning, 'checkout', sprintf( "Order %s cancelled, but Invoice Ninja couldn't be checked for an invoice. Look for PO number %s there.", $order['number'], $order['number'] ), array( 'kind' => $e->kind->value ), 'order', (string) $order['number'] );
				}
			}
		} else {
			try {
				$invoice = $client->get( 'invoices/' . rawurlencode( (string) $order['invoice_id'] ), array(), 10.0 )->data();
			} catch ( ApiException $e ) {
				if ( ErrorKind::NotFound !== $e->kind ) {
					throw $e;
				}
				$invoice = array( 'is_deleted' => true );
			}
			// Deleted (Invoice Ninja keeps it, with is_deleted) or reversed there: there's
			// nothing left to pay or cancel. Invoice Ninja refuses to cancel those.
			$gone = self::gone( $invoice );
			if ( null !== $gone && ( 'invoice_gone' === $gone || '6' === (string) ( $invoice['status_id'] ?? '' ) ) ) {
				if ( ! $this->orders->transition( (int) $order['id'], array( 'awaiting_payment', 'attention', 'queued' ), 'cancelled', array( 'note' => $gone ) ) ) {
					throw new OrderConflict( esc_html__( 'This order changed in the meantime. Reload and try again.', 'invoiceninja' ) );
				}
				return (array) $this->orders->find( (int) $order['id'] );
			}
			$status   = (string) ( $invoice['status_id'] ?? '' );
			$credited = isset( $invoice['balance'], $invoice['amount'] ) && round( (float) $invoice['balance'], 2 ) < round( (float) $invoice['amount'], 2 ) && '5' !== $status;
			if ( '4' === $status || (float) ( $invoice['paid_to_date'] ?? 0 ) > 0 || $credited ) {
				if ( '4' === $status ) {
					$this->orders->transition( (int) $order['id'], array( 'awaiting_payment' ), 'paid', array( 'paid_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				}
				throw new OrderConflict( esc_html__( 'This order has a payment in Invoice Ninja, so it was not cancelled. Refund or adjust it there.', 'invoiceninja' ) );
			}
			$had_payment = $this->closeInvoice( $client, (string) $order['invoice_id'], $delete, '5' === $status );
			if ( ! $this->orders->transition( (int) $order['id'], array( 'awaiting_payment', 'attention', 'queued' ), 'cancelled', array( 'note' => $had_payment ? 'cancelled_paid' : '' ) ) ) {
				throw new OrderConflict( esc_html__( 'This order changed in the meantime. Reload and try again.', 'invoiceninja' ) );
			}
		}
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s cancelled', $order['number'] ), array(), 'order', (string) $order['number'] );
		return (array) $this->orders->find( (int) $order['id'] );
	}

	/**
	 * Cancel an invoice, then delete it if asked. Cancelling first means a payment made a
	 * moment ago stops the delete: Invoice Ninja won't cancel a paid invoice.
	 *
	 * @param HttpClient $client            Client.
	 * @param string     $invoice_id        Invoice id.
	 * @param bool       $delete            Delete after cancelling.
	 * @param bool       $already_cancelled Skip the cancel.
	 * @return bool Whether the cancelled invoice turned out to have a payment.
	 * @throws ApiException When Invoice Ninja didn't do it.
	 */
	private function closeInvoice( HttpClient $client, string $invoice_id, bool $delete, bool $already_cancelled = false ): bool {
		if ( ! $already_cancelled ) {
			try {
				$cancelled = $this->cancelInvoice( $client, $invoice_id );
			} catch ( ApiException $e ) {
				// "This invoice cannot be cancelled": deleted or cancelled in Invoice Ninja meanwhile?
				$now  = ErrorKind::Validation === $e->kind ? $client->get( 'invoices/' . rawurlencode( $invoice_id ), array(), 10.0 )->data() : array();
				$gone = self::gone( $now );
				if ( null === $gone ) {
					throw $e;
				}
				if ( 'invoice_gone' === $gone ) {
					return false;
				}
				$cancelled = $now;
			}
			if ( (float) ( $cancelled['paid_to_date'] ?? 0 ) > 0 ) {
				return true; // A payment arrived just before the cancel: never delete it.
			}
		}
		if ( $delete ) {
			$this->cancelInvoice( $client, $invoice_id, true );
		}
		return false;
	}

	/**
	 * Whether an invoice is closed in Invoice Ninja: invoice_gone when it was deleted (it's
	 * kept with is_deleted), invoice_cancelled when cancelled (5) or reversed (6), else null.
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 */
	public static function gone( array $invoice ): ?string {
		if ( ! empty( $invoice['is_deleted'] ) ) {
			return 'invoice_gone';
		}
		return in_array( (string) ( $invoice['status_id'] ?? '' ), array( '5', '6' ), true ) ? 'invoice_cancelled' : null;
	}

	/**
	 * Cancel (or delete) an invoice in Invoice Ninja and make sure it took.
	 *
	 * @param HttpClient $client     Client.
	 * @param string     $invoice_id Invoice id.
	 * @param bool       $delete     Delete instead of cancel.
	 * @return array<string, mixed> The invoice as Invoice Ninja returned it.
	 * @throws ApiException When Invoice Ninja didn't do it.
	 */
	private function cancelInvoice( HttpClient $client, string $invoice_id, bool $delete = false ): array {
		$result = $client->post(
			'invoices/bulk',
			array(
				'action' => $delete ? 'delete' : 'cancel',
				'ids'    => array( $invoice_id ),
			),
			array(),
			10.0
		)->data();
		foreach ( $result as $invoice ) {
			if ( ! is_array( $invoice ) || (string) ( $invoice['id'] ?? '' ) !== $invoice_id ) {
				continue;
			}
			if ( $delete ? ! empty( $invoice['is_deleted'] ) : '5' === (string) ( $invoice['status_id'] ?? '' ) ) {
				return $invoice;
			}
		}
		throw new ApiException( ErrorKind::InvalidResponse, $delete ? "Invoice Ninja didn't delete the invoice" : "Invoice Ninja didn't cancel the invoice" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
	}

	/**
	 * Try a stuck order again now.
	 *
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed>
	 * @throws OrderConflict When the order is being placed or can't be sent again.
	 */
	public function retryNow( array $order ): array {
		$order = (array) $this->orders->find( (int) $order['id'] );
		if ( 'placing' === ( $order['status'] ?? '' ) && ! Orders::staleClaim( $order ) ) {
			throw new OrderConflict( esc_html__( 'This order is being sent to Invoice Ninja right now. Reload in a minute.', 'invoiceninja' ) );
		}
		if ( 'placing' === $order['status'] ) {
			$this->orders->transition( (int) $order['id'], array( 'placing' ), 'queued' );
		}
		if ( 'attention' === $order['status'] && ! $this->orders->transition( (int) $order['id'], array( 'attention' ), 'queued', array( 'attempts' => 0 ) ) ) {
			throw new OrderConflict( esc_html__( 'This order changed in the meantime. Reload and try again.', 'invoiceninja' ) );
		}
		$order = (array) $this->orders->find( (int) $order['id'] );
		if ( 'queued' !== $order['status'] ) {
			throw new OrderConflict( esc_html__( 'Only queued orders and orders that need attention can be sent again.', 'invoiceninja' ) );
		}
		return $this->place( $order, true );
	}

	/**
	 * Check the invoice in Invoice Ninja (rate-limited per order): paid, cancelled or
	 * deleted there, or a different amount (Invoice Ninja's own tax).
	 *
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed> The order, updated.
	 */
	public function refreshStatus( array $order ): array {
		if ( 'awaiting_payment' !== $order['status'] || '' === (string) $order['invoice_id'] ) {
			return $order;
		}
		$throttle = 'invoiceninja_order_check_' . $order['id'];
		if ( get_transient( $throttle ) ) {
			return $order;
		}
		set_transient( $throttle, 1, 15 );
		try {
			$invoice = $this->client()->get( 'invoices/' . rawurlencode( (string) $order['invoice_id'] ), array(), 1.5 )->data();
		} catch ( ApiException $e ) {
			return $order;
		}
		$this->cacheInvoice( $order, $invoice );
		$status_id = (string) ( $invoice['status_id'] ?? '' );
		// Paid only when Invoice Ninja says so; cancelled (5) and reversed (6) invoices also have no balance.
		if ( '4' === $status_id || ( ! in_array( $status_id, array( '5', '6' ), true ) && (float) ( $invoice['amount'] ?? 0 ) > 0 && (float) ( $invoice['paid_to_date'] ?? 0 ) >= (float) $invoice['amount'] ) ) {
			if ( ! $this->orders->transition( (int) $order['id'], array( 'awaiting_payment' ), 'paid', array( 'paid_at' => gmdate( 'Y-m-d H:i:s' ) ) ) ) {
				return (array) $this->orders->find( (int) $order['id'] ); // Changed meanwhile.
			}
			$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s paid', $order['number'] ), array(), 'order', (string) $order['number'] );
			return (array) $this->orders->find( (int) $order['id'] );
		}
		// Cancelled, reversed or deleted in Invoice Ninja: the order is closed (no pay link).
		$gone = self::gone( $invoice );
		if ( null !== $gone ) {
			if ( $this->orders->transition( (int) $order['id'], array( 'awaiting_payment' ), 'cancelled', array( 'note' => $gone ) ) ) {
				$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s closed: its invoice was %s in Invoice Ninja', $order['number'], 'invoice_gone' === $gone ? 'deleted' : 'cancelled' ), array(), 'order', (string) $order['number'] );
			}
			return (array) $this->orders->find( (int) $order['id'] );
		}
		$total = self::invoiceTotal( $invoice );
		if ( isset( $total['total'] ) && abs( $total['total'] - (float) $order['total'] ) >= 0.005 ) {
			$this->orders->update( (int) $order['id'], $total );
			$order['total'] = $total['total'];
		}
		return $order;
	}

	/**
	 * The order's invoice as Invoice Ninja last returned it (kept a few minutes), or null.
	 *
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed>|null
	 */
	public function cachedInvoice( array $order ): ?array {
		$cached = get_transient( 'invoiceninja_order_invoice_' . (int) $order['id'] );
		return is_array( $cached ) && (string) ( $cached['id'] ?? '' ) === (string) $order['invoice_id'] ? $cached : null;
	}

	/**
	 * The order's invoice: the cached copy, or fetched now (briefly). Null when there's no
	 * invoice yet or Invoice Ninja didn't answer in time.
	 *
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed>|null
	 */
	public function invoice( array $order ): ?array {
		if ( '' === (string) $order['invoice_id'] ) {
			return null;
		}
		$cached = $this->cachedInvoice( $order );
		if ( null !== $cached ) {
			return $cached;
		}
		try {
			$invoice = $this->client()->get( 'invoices/' . rawurlencode( (string) $order['invoice_id'] ), array(), 1.5 )->data();
		} catch ( ApiException $e ) {
			return null;
		}
		$this->cacheInvoice( $order, $invoice );
		return $invoice;
	}

	/**
	 * Keep an order's invoice for a few minutes.
	 *
	 * @param array<string, mixed> $order   Order.
	 * @param array<string, mixed> $invoice Invoice.
	 */
	private function cacheInvoice( array $order, array $invoice ): void {
		if ( '' !== (string) ( $invoice['id'] ?? '' ) ) {
			set_transient( 'invoiceninja_order_invoice_' . (int) $order['id'], $invoice, self::INVOICE_CACHE );
		}
	}

	/**
	 * An invoice's figures for the buyer, exactly as Invoice Ninja worked them out: its
	 * lines, tax and total, what's been paid and what's left. Anything else on the invoice
	 * (a discount, a surcharge) is the difference, so the rows always add up to its total.
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 * @return array{lines: list<array{title: string, quantity: float, line_total: float}>, subtotal: float, tax: float, inclusive: bool, adjustments: float, total: float, paid: float, balance: float}
	 */
	public static function breakdown( array $invoice ): array {
		$lines = array();
		foreach ( (array) ( $invoice['line_items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$quantity = (float) ( $item['quantity'] ?? 0 );
			$title    = (string) ( $item['product_key'] ?? '' );
			$lines[]  = array(
				'title'      => '' !== $title ? $title : wp_trim_words( wp_strip_all_tags( (string) ( $item['notes'] ?? '' ) ), 8 ),
				'quantity'   => $quantity,
				'line_total' => round( isset( $item['line_total'] ) && is_numeric( $item['line_total'] ) ? (float) $item['line_total'] : (float) ( $item['cost'] ?? 0 ) * $quantity, 2 ),
			);
		}
		$inclusive = ! empty( $invoice['uses_inclusive_taxes'] );
		$subtotal  = round( array_sum( array_column( $lines, 'line_total' ) ), 2 );
		$tax       = round( (float) ( $invoice['total_taxes'] ?? 0 ), 2 );
		$total     = round( (float) ( $invoice['amount'] ?? 0 ), 2 );
		$other     = round( $total - $subtotal - ( $inclusive ? 0.0 : $tax ), 2 );
		$paid      = round( (float) ( $invoice['paid_to_date'] ?? 0 ), 2 );
		return array(
			'lines'       => $lines,
			'subtotal'    => $subtotal,
			'tax'         => $tax,
			'inclusive'   => $inclusive,
			'adjustments' => abs( $other ) >= 0.005 ? $other : 0.0,
			'total'       => $total,
			'paid'        => $paid,
			// A deposit: Invoice Ninja's payment page asks for this before the balance.
			'partial'     => round( max( 0.0, (float) ( $invoice['partial'] ?? 0 ) ), 2 ),
			'balance'     => round( isset( $invoice['balance'] ) && is_numeric( $invoice['balance'] ) ? (float) $invoice['balance'] : $total - $paid, 2 ),
		);
	}

	/**
	 * API client for the connection.
	 */
	private function client(): HttpClient {
		return $this->plugin->api();
	}

	/**
	 * An invoice already created for this order, if any. Invoice Ninja has no exact
	 * po_number filter, so search with `filter` within the client and check the result:
	 * same po_number, same client, created after this order.
	 *
	 * @param HttpClient           $client    Client.
	 * @param array<string, mixed> $order     Order.
	 * @param string               $client_id Invoice Ninja client id.
	 * @return array<string, mixed>|null
	 */
	private function findExisting( HttpClient $client, array $order, string $client_id ): ?array {
		$number  = (string) $order['number'];
		$created = (int) strtotime( (string) $order['created_at'] . ' UTC' ) - 300;
		$found   = $client->get(
			'invoices',
			array(
				'filter'    => $number,
				'client_id' => $client_id,
				'per_page'  => 50,
				'sort'      => 'id|desc',
			),
			5.0
		)->data();
		foreach ( $found as $invoice ) {
			if ( ! is_array( $invoice ) || (string) ( $invoice['po_number'] ?? '' ) !== $number || ! empty( $invoice['is_deleted'] ) ) {
				continue;
			}
			if ( (string) ( $invoice['client_id'] ?? '' ) !== $client_id ) {
				continue;
			}
			if ( isset( $invoice['created_at'] ) && (int) $invoice['created_at'] < $created ) {
				continue; // An older invoice with a reused number (e.g. after a reinstall) is not ours.
			}
			return $invoice;
		}
		return null;
	}

	/**
	 * Find the client whose contact has this email, or create one. An existing client
	 * that uses another currency would get an invoice in that currency for the store's
	 * amounts, so it gets none.
	 *
	 * @param HttpClient           $client Client.
	 * @param array<string, mixed> $order  Order.
	 * @return string|null The client id, or null when its currency isn't the store's.
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private function clientFor( HttpClient $client, array $order ): ?string {
		// A currency mismatch is looked up again: an admin may have changed the client since.
		if ( '' !== (string) $order['client_id'] && 'currency' !== (string) $order['note'] ) {
			return (string) $order['client_id'];
		}
		$found = $this->plugin->clients->findOrCreate( $client, (string) $order['email'], $this->newClient( $order ) );
		$this->remember( $order, $found['client_id'], $found['contact_id'] );
		$company = (string) ( $this->plugin->state->get()['company']['currency_id'] ?? '' );
		if ( ! $found['created'] && self::currencyDiffers( $found['client'], $company ) ) {
			return null;
		}
		if ( ! $found['created'] ) {
			$this->saveShipping( $order, $found['client_id'], $found['client'] );
		}
		return $found['client_id'];
	}

	/**
	 * Whether an existing client's currency differs from the store's (the company's).
	 * A client without its own currency uses the company's.
	 *
	 * @param array<string, mixed> $client           Client record.
	 * @param string               $company_currency Company currency id.
	 */
	public static function currencyDiffers( array $client, string $company_currency ): bool {
		$theirs = (string) ( is_array( $client['settings'] ?? null ) ? ( $client['settings']['currency_id'] ?? '' ) : '' );
		return '' !== $theirs && '' !== $company_currency && $theirs !== $company_currency;
	}

	/**
	 * Details for a new client: the buyer's name and phone, the tax address when it was
	 * asked for, and the store's currency plus the buyer's language.
	 *
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed>
	 */
	private function newClient( array $order ): array {
		$customer = (array) $order['customer'];
		$details  = array_intersect_key( $customer, array_flip( array( 'first_name', 'last_name', 'phone', 'country_id', 'postal_code' ) ) );
		if ( is_array( $customer['shipping'] ?? null ) ) {
			// The delivery address, when checkout asked for one, is the client's shipping address.
			$details += array_intersect_key( $customer['shipping'], array_flip( Checkout::DELIVERY_FIELDS ) );
		}
		$settings = array_filter(
			array(
				'currency_id' => (string) ( $this->plugin->state->get()['company']['currency_id'] ?? '' ),
				'language_id' => $this->plugin->statics->languageId( (string) ( $customer['locale'] ?? '' ) ),
			),
			static fn( string $v ): bool => '' !== $v
		);
		if ( $settings ) {
			// Invoice Ninja keeps both in the client's settings.
			$details['client'] = array( 'settings' => $settings );
		}
		return $details;
	}

	/**
	 * Save the order's delivery address as an existing client's shipping address, when the
	 * order has one and it differs from what Invoice Ninja holds. It runs as a background
	 * job, so placing the order never waits on it, and a refused update never holds the
	 * order up. A client without its own currency is left alone: any update would pin the
	 * company's currency on it (see ClientResolver::updateShipping).
	 *
	 * @param array<string, mixed> $order     Order.
	 * @param string               $client_id Client id.
	 * @param array<string, mixed> $current   The client as Invoice Ninja returned it.
	 */
	private function saveShipping( array $order, string $client_id, array $current ): void {
		$shipping = $order['customer']['shipping'] ?? null;
		if ( ! is_array( $shipping ) || '' === $client_id ) {
			return;
		}
		$address = array();
		foreach ( Checkout::DELIVERY_FIELDS as $field ) {
			$address[ $field ] = (string) ( $shipping[ $field ] ?? '' );
		}
		$same = true;
		foreach ( $address as $field => $value ) {
			if ( 'shipping_country_id' === $field && '' === $value ) {
				continue; // Not sent (see updateShipping), so not a difference.
			}
			$same = $same && (string) ( $current[ $field ] ?? '' ) === $value;
		}
		if ( $same || '' === $address['shipping_address1'] ) {
			return;
		}
		$currency = (string) ( is_array( $current['settings'] ?? null ) ? ( $current['settings']['currency_id'] ?? '' ) : '' );
		if ( '' === $currency ) {
			$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( "Order %s: the client follows its group's or the company's currency, so its shipping address wasn't changed. The delivery address is on the order here.", $order['number'] ), array(), 'order', (string) $order['number'] );
			return;
		}
		if ( ! \InvoiceNinja\Core\Queue::available() ) {
			// Never inline: placing the order mustn't wait on it.
			$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( "Order %s: the delivery address wasn't saved on the client in Invoice Ninja (no background jobs). It's on the order here.", $order['number'] ), array(), 'order', (string) $order['number'] );
			return;
		}
		// Only the order id: the job reads the address from the order, so an order erased for
		// privacy leaves no address behind in the job queue.
		$this->plugin->queue->push(
			self::SHIPPING_HOOK,
			array(
				'order_id'    => (int) $order['id'],
				'client_id'   => $client_id,
				'currency_id' => $currency,
			),
			0,
			false
		);
	}

	/**
	 * Background job: save an order's delivery address on its client (see saveShipping).
	 *
	 * @param array<string, mixed> $payload {order_id, client_id, currency_id}.
	 * @throws ApiException To let the queue retry.
	 */
	public function shippingJob( array $payload ): void {
		$order    = $this->orders->find( (int) ( $payload['order_id'] ?? 0 ) );
		$shipping = is_array( $order ) && is_array( $order['customer']['shipping'] ?? null ) ? $order['customer']['shipping'] : null;
		if ( null === $shipping || '' === (string) ( $shipping['shipping_address1'] ?? '' ) ) {
			return; // Gone or erased since.
		}
		$address = array();
		foreach ( Checkout::DELIVERY_FIELDS as $field ) {
			$address[ $field ] = (string) ( $shipping[ $field ] ?? '' );
		}
		$this->plugin->clients->updateShipping( $this->client(), (string) ( $payload['client_id'] ?? '' ), $address, (string) ( $payload['currency_id'] ?? '' ) );
	}

	/**
	 * The matching client uses another currency: no invoice. The buyer is told we can't
	 * take the order online, and an admin is asked to sort it out.
	 *
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed>
	 */
	private function currencyMismatch( array $order ): array {
		if ( ! $this->orders->transition( (int) $order['id'], array( 'placing' ), 'attention', array( 'note' => 'currency' ) ) ) {
			return (array) $this->orders->find( (int) $order['id'] );
		}
		$order = (array) $this->orders->find( (int) $order['id'] );
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Error, 'checkout', sprintf( "Order %s wasn't invoiced: the matching Invoice Ninja client uses another currency", $order['number'] ), array(), 'order', (string) $order['number'] );
		do_action( 'invoiceninja_order_needs_attention', $order );
		return $order;
	}

	/**
	 * Store the matched client and the buyer's own contact.
	 *
	 * @param array<string, mixed> $order      Order.
	 * @param string               $client_id  Client id.
	 * @param string               $contact_id Contact id.
	 */
	private function remember( array $order, string $client_id, string $contact_id ): void {
		$customer               = (array) $order['customer'];
		$customer['contact_id'] = $contact_id;
		$this->orders->update(
			(int) $order['id'],
			array(
				'client_id' => $client_id,
				'customer'  => $customer,
			)
		);
	}

	/**
	 * Create the invoice (marked sent so it can be paid; Invoice Ninja sends no email).
	 *
	 * @param HttpClient           $client    Client.
	 * @param array<string, mixed> $order     Order.
	 * @param string               $client_id Client id.
	 * @return array<string, mixed>
	 */
	private function createInvoice( HttpClient $client, array $order, string $client_id ): array {
		$lines = array();
		foreach ( (array) $order['items'] as $item ) {
			$lines[] = array(
				'product_key' => (string) ( $item['product_key'] ?? $item['title'] ),
				'notes'       => (string) ( $item['notes'] ?? '' ),
				'cost'        => (float) $item['price'],
				'quantity'    => (int) $item['quantity'],
			) + (array) ( $item['tax'] ?? array() );
		}
		$body = array(
			'client_id'            => $client_id,
			'po_number'            => (string) $order['number'],
			'line_items'           => $lines,
			// Left out, Invoice Ninja uses the client's or company's setting, which may not be
			// how the order total was worked out.
			'uses_inclusive_taxes' => self::inclusive( $order ),
			// Shown on the PDF and invoice page. Invoice Ninja puts a client's own public notes
			// in place of these when the client has some.
			/* translators: 1: order number, 2: order status link */
			'public_notes'         => sprintf( __( 'Order #%1$s · Order status: %2$s', 'invoiceninja' ), (string) $order['number'], $this->plugin->checkout->statusUrl( $order ) ),
		);
		return $client->post( 'invoices', $body, array( 'mark_sent' => 'true' ), self::CREATE_BUDGET )->data();
	}

	/**
	 * The invoice's amount as the order total: what the buyer pays. It differs from the
	 * cart's when Invoice Ninja applied its own tax (its "calculate taxes" setting).
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 * @return array{total?: float}
	 */
	public static function invoiceTotal( array $invoice ): array {
		return isset( $invoice['amount'] ) && is_numeric( $invoice['amount'] ) && (float) $invoice['amount'] >= 0 ? array( 'total' => round( (float) $invoice['amount'], 2 ) ) : array();
	}

	/**
	 * Whether the order's prices included tax when it was placed.
	 *
	 * @param array<string, mixed> $order Order.
	 */
	public static function inclusive( array $order ): bool {
		foreach ( (array) $order['items'] as $item ) {
			return is_array( $item ) && ! empty( $item['inclusive'] );
		}
		return false;
	}

	/**
	 * Record the created invoice and email the buyer.
	 *
	 * @param array<string, mixed> $order   Order.
	 * @param array<string, mixed> $invoice Invoice.
	 * @return array<string, mixed>
	 */
	private function placed( array $order, array $invoice ): array {
		// Only the buyer's own invitation: another contact's link would log the buyer in as them.
		$contact = (string) ( $order['customer']['contact_id'] ?? '' );
		$link    = '';
		foreach ( (array) ( $invoice['invitations'] ?? array() ) as $invitation ) {
			if ( is_array( $invitation ) && '' !== $contact && (string) ( $invitation['client_contact_id'] ?? '' ) === $contact ) {
				$link = (string) ( $invitation['link'] ?? '' );
			}
		}
		$fields = array(
			'invoice_id'     => (string) ( $invoice['id'] ?? '' ),
			'invoice_number' => (string) ( $invoice['number'] ?? '' ),
			'client_id'      => (string) ( $invoice['client_id'] ?? $order['client_id'] ),
			'pay_link'       => esc_url_raw( $link ),
			'note'           => '' === $link ? 'no_invitation' : '',
			'placed_at'      => gmdate( 'Y-m-d H:i:s' ),
		) + self::invoiceTotal( $invoice );
		if ( ! $this->orders->transition( (int) $order['id'], array( 'placing' ), 'awaiting_payment', $fields ) ) {
			// Cancelled by an admin while the invoice was being created: cancel the invoice too.
			$current = (array) $this->orders->find( (int) $order['id'] );
			if ( 'cancelled' === ( $current['status'] ?? '' ) && '' !== $fields['invoice_id'] ) {
				$this->orders->update( (int) $order['id'], array_diff_key( $fields, array( 'pay_link' => 1 ) ) );
				try {
					$this->cancelInvoice( $this->client(), $fields['invoice_id'] );
				} catch ( ApiException $e ) {
					$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Error, 'checkout', sprintf( 'Order %s was cancelled while its invoice %s was being created. Cancel that invoice in Invoice Ninja.', $current['number'], $fields['invoice_number'] ), array( 'kind' => $e->kind->value ), 'order', (string) $current['number'] );
				}
			}
			return (array) $this->orders->find( (int) $order['id'] );
		}
		$order = (array) $this->orders->find( (int) $order['id'] );
		$this->cacheInvoice( $order, $invoice );
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s: invoice %s created', $order['number'], $order['invoice_number'] ), array(), 'order', (string) $order['number'] );
		if ( '' === $link ) {
			do_action( 'invoiceninja_order_needs_attention', $order ); // The buyer has no link to pay with; an admin sends the invoice.
		}
		/**
		 * Fires when an order's invoice was created.
		 *
		 * @since 2.0.0
		 * @param array $order Order.
		 */
		do_action( 'invoiceninja_order_placed', $order );
		return $order;
	}

	/**
	 * Handle a failure: queue it when it may succeed later, flag it when it can't.
	 *
	 * @param array<string, mixed> $order  Order.
	 * @param ApiException         $e      Error.
	 * @param bool                 $inline During checkout.
	 * @param HttpClient           $client Client.
	 * @return array<string, mixed>
	 * @throws ApiException From background jobs, so the queue retries.
	 */
	private function failed( array $order, ApiException $e, bool $inline, HttpClient $client ): array {
		// The invoice may exist even though we didn't hear back: look before anything else.
		if ( $e->kind->outcomeUnknown() ) {
			try {
				$order    = (array) $this->orders->find( (int) $order['id'] );
				$existing = '' !== (string) $order['client_id'] ? $this->findExisting( $client, $order, (string) $order['client_id'] ) : null;
				if ( null !== $existing ) {
					return $this->placed( $order, $existing );
				}
			} catch ( ApiException $ignored ) {
				unset( $ignored ); // Still unknown; handled below.
			}
		}
		if ( $e->kind->retryable() ) {
			if ( ! $this->orders->transition( (int) $order['id'], array( 'placing' ), 'queued', array( 'note' => 'error:' . $e->kind->value ) ) ) {
				return (array) $this->orders->find( (int) $order['id'] ); // Cancelled meanwhile.
			}
			if ( $inline ) {
				$this->plugin->queue->push( 'invoiceninja_place_order', array( 'order_id' => (int) $order['id'] ), 60, true );
				$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Warning, 'checkout', sprintf( 'Order %s saved while Invoice Ninja was unavailable; it will be sent shortly', $order['number'] ), array( 'kind' => $e->kind->value ), 'order', (string) $order['number'] );
				return (array) $this->orders->find( (int) $order['id'] );
			}
			throw $e;
		}
		if ( ! $this->orders->transition( (int) $order['id'], array( 'placing' ), 'attention', array( 'note' => 'error:' . $e->kind->value ) ) ) {
			return (array) $this->orders->find( (int) $order['id'] ); // Cancelled meanwhile.
		}
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Error, 'checkout', sprintf( "Order %s couldn't be invoiced: %s", $order['number'], $e->kind->title() ), array( 'technical' => $e->technical ), 'order', (string) $order['number'] );
		$order = (array) $this->orders->find( (int) $order['id'] );
		do_action( 'invoiceninja_order_needs_attention', $order );
		return $order;
	}
}
