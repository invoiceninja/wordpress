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

	public const CREATE_BUDGET = 8.0;

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
		if ( 'queued' !== $order['status'] ) {
			return $order;
		}
		if ( ! $this->plugin->environment->writesAllowed() ) {
			$this->orders->update( (int) $order['id'], array( 'note' => 'Orders are paused on this site' ) );
			return (array) $this->orders->find( (int) $order['id'] );
		}
		$client = $this->client();
		try {
			$existing = $this->findByPoNumber( $client, (string) $order['number'] );
			$invoice  = $existing ?? $this->createInvoice( $client, $order, $this->clientFor( $client, $order ) );
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
		if ( null === $order || 'queued' !== $order['status'] ) {
			return;
		}
		$this->orders->update( (int) $order['id'], array( 'attempts' => (int) $order['attempts'] + 1 ) );
		$placed = $this->place( $order, false );
		if ( 'queued' === $placed['status'] && $this->plugin->environment->writesAllowed() ) {
			throw new ApiException( ErrorKind::Transport, 'order still queued' );
		}
	}

	/**
	 * Check payment with Invoice Ninja (rate-limited per order).
	 *
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed> The order, updated if it was paid.
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
		if ( '4' === (string) ( $invoice['status_id'] ?? '' ) || ( isset( $invoice['balance'] ) && (float) $invoice['balance'] <= 0 && (float) ( $invoice['amount'] ?? 0 ) > 0 ) ) {
			$this->orders->update(
				(int) $order['id'],
				array(
					'status'  => 'paid',
					'paid_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s paid', $order['number'] ), array(), 'order', (string) $order['number'] );
			return (array) $this->orders->find( (int) $order['id'] );
		}
		return $order;
	}

	/**
	 * API client for the connection.
	 */
	private function client(): HttpClient {
		return $this->plugin->credentials->client( $this->plugin->state->allowsPrivate( $this->plugin->credentials->url() ) );
	}

	/**
	 * An invoice already created for this order, if any.
	 *
	 * @param HttpClient $client Client.
	 * @param string     $number Order number.
	 * @return array<string, mixed>|null
	 */
	private function findByPoNumber( HttpClient $client, string $number ): ?array {
		foreach ( $client->get( 'invoices', array( 'po_number' => $number ), 5.0 )->data() as $invoice ) {
			if ( is_array( $invoice ) && (string) ( $invoice['po_number'] ?? '' ) === $number && empty( $invoice['is_deleted'] ) ) {
				return $invoice;
			}
		}
		return null;
	}

	/**
	 * Find the client whose contact has this email, or create one.
	 *
	 * @param HttpClient           $client Client.
	 * @param array<string, mixed> $order  Order.
	 */
	private function clientFor( HttpClient $client, array $order ): string {
		if ( '' !== (string) $order['client_id'] ) {
			return (string) $order['client_id'];
		}
		$email = strtolower( (string) $order['email'] );
		foreach ( $client->get( 'clients', array( 'email' => $email ), 5.0 )->data() as $candidate ) {
			foreach ( (array) ( $candidate['contacts'] ?? array() ) as $contact ) {
				if ( is_array( $contact ) && strtolower( (string) ( $contact['email'] ?? '' ) ) === $email && empty( $candidate['is_deleted'] ) ) {
					$this->orders->update( (int) $order['id'], array( 'client_id' => (string) $candidate['id'] ) );
					return (string) $candidate['id'];
				}
			}
		}
		$customer = $order['customer'];
		$name     = trim( (string) ( $customer['first_name'] ?? '' ) . ' ' . (string) ( $customer['last_name'] ?? '' ) );
		$created  = $client->post(
			'clients',
			array(
				'name'     => '' !== $name ? $name : $email,
				'contacts' => array(
					array(
						'first_name' => (string) ( $customer['first_name'] ?? '' ),
						'last_name'  => (string) ( $customer['last_name'] ?? '' ),
						'email'      => $email,
						'phone'      => (string) ( $customer['phone'] ?? '' ),
					),
				),
			),
			array(),
			5.0
		)->data();
		$this->orders->update( (int) $order['id'], array( 'client_id' => (string) $created['id'] ) );
		$this->plugin->logger->info( 'checkout', sprintf( 'Created an Invoice Ninja client for %s', $email ) );
		return (string) $created['id'];
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
				'product_key' => (string) $item['title'],
				'notes'       => (string) ( $item['notes'] ?? '' ),
				'cost'        => (float) $item['price'],
				'quantity'    => (int) $item['quantity'],
			) + (array) ( $item['tax'] ?? array() );
		}
		return $client->post(
			'invoices',
			array(
				'client_id'  => $client_id,
				'po_number'  => (string) $order['number'],
				'line_items' => $lines,
			),
			array( 'mark_sent' => 'true' ),
			self::CREATE_BUDGET
		)->data();
	}

	/**
	 * Record the created invoice and email the buyer.
	 *
	 * @param array<string, mixed> $order   Order.
	 * @param array<string, mixed> $invoice Invoice.
	 * @return array<string, mixed>
	 */
	private function placed( array $order, array $invoice ): array {
		$link = (string) ( $invoice['invitations'][0]['link'] ?? '' );
		$this->orders->update(
			(int) $order['id'],
			array(
				'status'         => 'awaiting_payment',
				'invoice_id'     => (string) ( $invoice['id'] ?? '' ),
				'invoice_number' => (string) ( $invoice['number'] ?? '' ),
				'client_id'      => (string) ( $invoice['client_id'] ?? $order['client_id'] ),
				'pay_link'       => esc_url_raw( $link ),
				'note'           => '',
			)
		);
		$order = (array) $this->orders->find( (int) $order['id'] );
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s: invoice %s created', $order['number'], $order['invoice_number'] ), array(), 'order', (string) $order['number'] );
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
		if ( in_array( $e->kind, array( ErrorKind::Timeout, ErrorKind::Duplicate, ErrorKind::Server, ErrorKind::Transport ), true ) ) {
			try {
				$existing = $this->findByPoNumber( $client, (string) $order['number'] );
				if ( null !== $existing ) {
					return $this->placed( $order, $existing );
				}
			} catch ( ApiException $ignored ) {
				unset( $ignored ); // Still unknown; handled below.
			}
		}
		if ( $e->kind->retryable() ) {
			$this->orders->update( (int) $order['id'], array( 'note' => $e->kind->title() ) );
			if ( $inline ) {
				$this->plugin->queue->push( 'invoiceninja_place_order', array( 'order_id' => (int) $order['id'] ), 60, true );
				$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Warning, 'checkout', sprintf( 'Order %s saved while Invoice Ninja was unavailable; it will be sent shortly', $order['number'] ), array( 'kind' => $e->kind->value ), 'order', (string) $order['number'] );
				return (array) $this->orders->find( (int) $order['id'] );
			}
			throw $e;
		}
		$this->orders->update(
			(int) $order['id'],
			array(
				'status' => 'attention',
				'note'   => $e->kind->title() . ( '' !== $e->technical ? ': ' . substr( $e->technical, 0, 180 ) : '' ),
			)
		);
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Error, 'checkout', sprintf( "Order %s couldn't be invoiced: %s", $order['number'], $e->kind->title() ), array( 'technical' => $e->technical ), 'order', (string) $order['number'] );
		return (array) $this->orders->find( (int) $order['id'] );
	}
}
