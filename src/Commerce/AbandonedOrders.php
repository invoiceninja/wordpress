<?php
/**
 * Optional cleanup of orders that were never paid.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Commerce;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Plugin;

/**
 * Off by default. When on, orders still awaiting payment after N days have their invoice
 * cancelled (or, if the admin chose it, deleted) in Invoice Ninja. Each order's invoice
 * is fetched again first and only a completely unpaid invoice is touched. Only this
 * site's own orders are ever looked at, never other invoices in Invoice Ninja.
 */
final class AbandonedOrders {

	public const HOOK = 'invoiceninja_abandoned_orders';

	/**
	 * Orders handled per run; the rest wait for the next day.
	 */
	public const BATCH = 25;

	/**
	 * Seconds per run; the rest is picked up a minute later.
	 */
	public const BUDGET = 20.0;

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin  Plugin.
	 * @param Orders       $orders  Orders.
	 * @param OrderService $service Order service.
	 */
	public function __construct( private Plugin $plugin, private Orders $orders, private OrderService $service ) {}

	/**
	 * Whether the cleanup is on.
	 */
	public function enabled(): bool {
		return (bool) $this->plugin->options->setting( 'store.abandoned_enabled' );
	}

	/**
	 * Cancel or delete abandoned orders.
	 *
	 * @return array{checked: int, cleaned: int, skipped: int}
	 */
	public function run(): array {
		$result = array(
			'checked' => 0,
			'cleaned' => 0,
			'skipped' => 0,
		);
		if ( ! $this->enabled() || ! $this->plugin->environment->writesAllowed() || 'connected' !== $this->plugin->state->get()['status'] ) {
			return $result;
		}
		$days     = max( 1, (int) $this->plugin->options->setting( 'store.abandoned_days' ) );
		$delete   = 'delete' === $this->plugin->options->setting( 'store.abandoned_action' );
		$cursor   = (int) $this->plugin->options->get( 'abandoned_cursor', 0 );
		$deadline = microtime( true ) + self::BUDGET;
		$stopped  = false;
		$batch    = $this->orders->unpaidSince( time() - $days * DAY_IN_SECONDS, $cursor, self::BATCH );
		foreach ( $batch as $order ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			++$result['checked'];
			$cursor = (int) $order['id'];
			try {
				$closed = $this->service->cancel( $order, $delete );
				++$result['cleaned'];
				$what = 'invoice_gone' === $closed['note'] ? 'was already deleted in Invoice Ninja' : ( 'cancelled_paid' === $closed['note'] ? 'was cancelled, but had a payment' : ( $delete ? 'was deleted' : 'was cancelled' ) );
				$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s was unpaid after %d days; its invoice %s', $order['number'], $days, $what ), array(), 'order', (string) $order['number'] );
			} catch ( OrderConflict $e ) {
				++$result['skipped']; // Paid, partly paid or changed: leave it alone, and don't look at it again.
				$this->orders->transition( (int) $order['id'], array( 'awaiting_payment' ), 'awaiting_payment', array( 'note' => 'kept_unpaid' ) );
			} catch ( ApiException $e ) {
				++$result['skipped'];
				if ( $e->kind->breaksConnection() ) {
					$cursor  = (int) $order['id'] - 1; // Try this one again next time.
					$stopped = true;
					break;
				}
				$failures                       = (array) $this->plugin->options->get( 'abandoned_failures', array() );
				$tries                          = (int) ( $failures[ (int) $order['id'] ] ?? 0 ) + 1;
				$failures[ (int) $order['id'] ] = $tries;
				if ( $tries >= 3 ) {
					unset( $failures[ (int) $order['id'] ] );
					$this->orders->update( (int) $order['id'], array( 'note' => 'cleanup_failed' ) );
				}
				$this->plugin->options->set( 'abandoned_failures', array_slice( $failures, -200, null, true ) );
				$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Warning, 'checkout', sprintf( "The unpaid-orders cleanup couldn't close order %s: %s", $order['number'], $e->kind->title() ), array( 'kind' => $e->kind->value ), 'order', (string) $order['number'] );
			}
		}
		$more = $stopped || count( $batch ) >= self::BATCH || $result['checked'] < count( $batch );
		// Start from the beginning again once the end is reached.
		$this->plugin->options->set( 'abandoned_cursor', $more ? $cursor : 0 );
		if ( $more && ! $stopped ) {
			$this->plugin->queue->push( self::HOOK, array(), 60, true );
		}
		return $result;
	}
}
