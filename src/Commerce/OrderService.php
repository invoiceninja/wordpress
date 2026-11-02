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
			$order     = (array) $this->orders->find( (int) $order['id'] );
			$existing  = $this->findExisting( $client, $order, $client_id );
			$invoice   = $existing ?? $this->createInvoice( $client, $order, $client_id );
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
	 * @param array<string, mixed> $order Order.
	 * @return array<string, mixed> The cancelled order.
	 * @throws ApiException When Invoice Ninja can't be reached or refuses.
	 * @throws OrderConflict When the order can't be cancelled now.
	 */
	public function cancel( array $order ): array {
		$order = (array) $this->orders->find( (int) $order['id'] );
		switch ( $order['status'] ?? '' ) {
			case 'paid':
				throw new OrderConflict( esc_html__( 'This order is paid. Refund it in Invoice Ninja instead.', 'invoiceninja' ) );
			case 'cancelled':
				throw new OrderConflict( esc_html__( 'This order is already cancelled.', 'invoiceninja' ) );
			case 'placing':
				throw new OrderConflict( esc_html__( 'This order is being sent to Invoice Ninja right now. Try again in a minute.', 'invoiceninja' ) );
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
						$this->cancelInvoice( $client, (string) $existing['id'] );
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
			$invoice = $client->get( 'invoices/' . rawurlencode( (string) $order['invoice_id'] ), array(), 10.0 )->data();
			$status  = (string) ( $invoice['status_id'] ?? '' );
			if ( '4' === $status || (float) ( $invoice['paid_to_date'] ?? 0 ) > 0 ) {
				if ( '4' === $status ) {
					$this->orders->transition( (int) $order['id'], array( 'awaiting_payment' ), 'paid', array( 'paid_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				}
				throw new OrderConflict( esc_html__( 'This order has a payment in Invoice Ninja, so it was not cancelled. Refund or adjust it there.', 'invoiceninja' ) );
			}
			if ( '5' !== $status ) {
				$this->cancelInvoice( $client, (string) $order['invoice_id'] );
			}
			if ( ! $this->orders->transition( (int) $order['id'], array( 'awaiting_payment', 'attention', 'queued' ), 'cancelled', array( 'note' => '' ) ) ) {
				throw new OrderConflict( esc_html__( 'This order changed in the meantime. Reload and try again.', 'invoiceninja' ) );
			}
		}
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s cancelled', $order['number'] ), array(), 'order', (string) $order['number'] );
		return (array) $this->orders->find( (int) $order['id'] );
	}

	/**
	 * Cancel an invoice in Invoice Ninja and make sure it took.
	 *
	 * @param HttpClient $client     Client.
	 * @param string     $invoice_id Invoice id.
	 * @throws ApiException When Invoice Ninja didn't cancel it.
	 */
	private function cancelInvoice( HttpClient $client, string $invoice_id ): void {
		$result = $client->post(
			'invoices/bulk',
			array(
				'action' => 'cancel',
				'ids'    => array( $invoice_id ),
			),
			array(),
			10.0
		)->data();
		foreach ( $result as $invoice ) {
			if ( is_array( $invoice ) && (string) ( $invoice['id'] ?? '' ) === $invoice_id && '5' === (string) ( $invoice['status_id'] ?? '' ) ) {
				return;
			}
		}
		throw new ApiException( ErrorKind::InvalidResponse, "Invoice Ninja didn't cancel the invoice" );
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
		if ( 'placing' === ( $order['status'] ?? '' ) ) {
			throw new OrderConflict( esc_html__( 'This order is being sent to Invoice Ninja right now. Reload in a minute.', 'invoiceninja' ) );
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
		$status_id = (string) ( $invoice['status_id'] ?? '' );
		// Paid only when Invoice Ninja says so; cancelled (5) and reversed (6) invoices also have no balance.
		if ( '4' === $status_id || ( ! in_array( $status_id, array( '5', '6' ), true ) && (float) ( $invoice['amount'] ?? 0 ) > 0 && (float) ( $invoice['paid_to_date'] ?? 0 ) >= (float) $invoice['amount'] ) ) {
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
		foreach ( $client->get(
			'clients',
			array(
				'email'  => $email,
				'status' => 'active,archived',
			),
			5.0
		)->data() as $candidate ) {
			foreach ( (array) ( $candidate['contacts'] ?? array() ) as $contact ) {
				if ( is_array( $contact ) && strtolower( (string) ( $contact['email'] ?? '' ) ) === $email && empty( $candidate['is_deleted'] ) ) {
					$this->remember( $order, (string) $candidate['id'], (string) ( $contact['id'] ?? '' ) );
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
		$this->remember( $order, (string) $created['id'], (string) ( $created['contacts'][0]['id'] ?? '' ) );
		$this->plugin->logger->info( 'checkout', sprintf( 'Created an Invoice Ninja client for %s', $email ) );
		return (string) $created['id'];
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
		);
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
