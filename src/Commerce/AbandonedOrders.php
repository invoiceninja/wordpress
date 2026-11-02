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
		$days   = max( 1, (int) $this->plugin->options->setting( 'store.abandoned_days' ) );
		$delete = 'delete' === $this->plugin->options->setting( 'store.abandoned_action' );
		foreach ( $this->orders->olderThan( 'awaiting_payment', time() - $days * DAY_IN_SECONDS, self::BATCH ) as $order ) {
			++$result['checked'];
			try {
				$this->service->cancel( $order, $delete );
				++$result['cleaned'];
				$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s was unpaid after %d days, so its invoice was %s', $order['number'], $days, $delete ? 'deleted' : 'cancelled' ), array(), 'order', (string) $order['number'] );
			} catch ( OrderConflict $e ) {
				++$result['skipped']; // Paid, partly paid or changed: leave it alone, and don't look at it again.
				$this->orders->transition( (int) $order['id'], array( 'awaiting_payment' ), 'awaiting_payment', array( 'note' => 'kept_unpaid' ) );
			} catch ( ApiException $e ) {
				++$result['skipped'];
				if ( $e->kind->breaksConnection() ) {
					break;
				}
			}
		}
		return $result;
	}
}
