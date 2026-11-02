<?php
/**
 * Checks Invoice Ninja every 15 minutes when webhooks aren't available.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Webhooks;

use InvoiceNinja\Plugin;

/**
 * Without webhooks (a token that may not create them, a plan without the API feature, or a
 * site Invoice Ninja can't reach) payments and product edits are picked up by polling:
 * orders awaiting payment are checked, and a quick product sync is queued.
 */
final class Poller {

	public const HOOK = 'invoiceninja_webhooks_poll';

	public const INTERVAL = 900;

	/**
	 * Constructor.
	 *
	 * @param Plugin     $plugin     Plugin.
	 * @param Dispatcher $dispatcher Dispatcher.
	 */
	public function __construct( private Plugin $plugin, private Dispatcher $dispatcher ) {}

	/**
	 * One round.
	 */
	public function run(): void {
		if ( 'none' === $this->plugin->credentials->source() || 'connected' !== $this->plugin->state->get()['status'] ) {
			return;
		}
		$recent  = time() - 30 * DAY_IN_SECONDS;
		$invoice = array();
		foreach ( $this->plugin->orders->list(
			array(
				'status'   => 'awaiting_payment',
				'per_page' => 50,
			)
		)['items'] as $order ) {
			if ( '' !== (string) $order['invoice_id'] && strtotime( (string) $order['created_at'] . ' UTC' ) > $recent ) {
				$invoice[] = (string) $order['invoice_id'];
			}
		}
		$this->dispatcher->refreshOrders( $invoice );
		if ( $this->plugin->products->enabled() ) {
			$this->plugin->queue->push( 'invoiceninja_sync_products', array(), 0, true );
		}
		/**
		 * Fires every 15 minutes while Invoice Ninja's webhooks aren't available, so modules
		 * can poll for what they'd otherwise hear about by webhook.
		 *
		 * @since 2.0.0
		 */
		do_action( 'invoiceninja_polling' );
	}
}
