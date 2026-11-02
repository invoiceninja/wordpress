<?php
/**
 * Acts on a webhook call: fetch the entity again, then tell everyone.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Webhooks;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Plugin;

/**
 * Runs in the queue. The entity comes from Invoice Ninja by id, never from the webhook body,
 * so a forged or replayed call can't change a price or mark an order paid. Then:
 * - products are updated in place (one product, no full sync);
 * - orders whose invoice was paid are marked paid;
 * - invoiceninja_webhook_<entity>_<event> and invoiceninja_webhook fire for other modules.
 */
final class Dispatcher {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Queue handler.
	 *
	 * @param array<string, mixed> $payload event, id.
	 * @throws ApiException On a temporary failure (the queue tries again).
	 */
	public function handle( array $payload ): void {
		$event = (int) ( $payload['event'] ?? 0 );
		$id    = Events::cleanId( $payload['id'] ?? '' );
		if ( ! Events::known( $event ) || '' === $id || 'none' === $this->plugin->credentials->source() ) {
			return;
		}
		$entity = Events::entity( $event );
		$name   = Events::name( $event );
		if ( 'product' === $entity && ! $this->plugin->products->enabled() ) {
			return;
		}
		[ $path, $query ] = Events::fetch( $event, $id );
		try {
			$data = $this->plugin->api()->get( $path, $query, 10.0 )->data();
		} catch ( ApiException $e ) {
			if ( ErrorKind::NotFound === $e->kind ) {
				// Not in this company: forged, or from a company this site no longer uses.
				$this->plugin->logger->log( LogLevel::Info, 'webhooks', sprintf( 'Ignored a webhook for a %s Invoice Ninja does not have', $entity ), array( 'event' => $event ), $entity, $id );
				return;
			}
			throw $e;
		}
		if ( Events::cleanId( $data['id'] ?? '' ) !== $id ) {
			return;
		}

		if ( 'product' === $entity ) {
			$result = $this->plugin->products->syncOne( $data );
			if ( 'busy' === $result ) {
				// A full sync is running; this one product follows it.
				$this->plugin->queue->push( Receiver::JOB, $payload, 60, true );
				return;
			}
		} elseif ( 'invoice' === $entity ) {
			$this->refreshOrders( array( $id ) );
		} elseif ( 'payment' === $entity ) {
			$this->refreshOrders( Events::paymentInvoiceIds( $data ) );
		}

		/**
		 * Fires after a webhook from Invoice Ninja, with the entity fetched again by id.
		 * For example invoiceninja_webhook_payment_created or invoiceninja_webhook_quote_approved.
		 *
		 * @since 2.0.0
		 * @param array $data     The entity, as Invoice Ninja returns it now.
		 * @param int   $event_id Invoice Ninja event id.
		 */
		do_action( "invoiceninja_webhook_{$entity}_{$name}", $data, $event );
		/**
		 * Fires after every webhook from Invoice Ninja.
		 *
		 * @since 2.0.0
		 * @param string $entity client, invoice, payment, quote or product.
		 * @param string $event  created, updated, deleted, archived, restored, sent, approved, late or reminder.
		 * @param array  $data   The entity, fetched again by id.
		 */
		do_action( 'invoiceninja_webhook', $entity, $name, $data );
	}

	/**
	 * Check the orders for these invoices with Invoice Ninja now (a paid one turns paid).
	 *
	 * @param list<string> $invoice_ids Invoice ids.
	 */
	public function refreshOrders( array $invoice_ids ): void {
		foreach ( array_slice( $invoice_ids, 0, 50 ) as $invoice_id ) {
			$order = $this->plugin->orders->findBy( 'invoice_id', $invoice_id );
			if ( null === $order || 'awaiting_payment' !== $order['status'] ) {
				continue;
			}
			// The live check is throttled for shoppers; a webhook is a reason to look now.
			delete_transient( 'invoiceninja_order_check_' . $order['id'] );
			$this->plugin->orderService->refreshStatus( $order );
		}
	}
}
