<?php
/**
 * Invoice past WooCommerce orders.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Plugin;

/**
 * Orders in a date range that have no invoice yet are queued in pages, each through the
 * same jobs as new orders (so they are idempotent too), with their payment and refunds.
 * Nobody is emailed: Invoice Ninja never sends (draft, mark sent without email,
 * email_receipt=false), WooCommerce sends nothing for order notes we add, and admin
 * mismatch emails are skipped (mismatches still show on each order). A dry run only
 * counts.
 */
final class Backfill {

	public const HOOK   = 'invoiceninja_woo_backfill';
	public const OPTION = 'woo_backfill';

	/** Statuses worth invoicing afterwards. */
	public const STATUSES = array( 'processing', 'completed', 'on-hold', 'refunded' );

	private const PAGE = 25;

	/** Orders a dry run looks at. */
	private const DRY_RUN_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param Plugin         $plugin   Plugin.
	 * @param OrderToInvoice $invoices Invoices.
	 * @param Payments       $payments Payments.
	 */
	public function __construct( private Plugin $plugin, private OrderToInvoice $invoices, private Payments $payments ) {}

	/**
	 * Register job handlers.
	 */
	public function register(): void {
		$this->plugin->queue->handle( self::HOOK, array( $this, 'page' ) );
		add_action( 'invoiceninja_woo_backfill_item_done', array( $this, 'itemDone' ), 10, 2 );
	}

	/**
	 * Validate a Y-m-d date.
	 *
	 * @param string $date Date.
	 */
	public static function validDate( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Start (or dry-run) a backfill.
	 *
	 * @param string $from    First day (Y-m-d).
	 * @param string $to      Last day (Y-m-d).
	 * @param bool   $dry_run Only count.
	 * @return array<string, mixed> Counts, or the run's progress.
	 * @throws \InvalidArgumentException For bad dates.
	 */
	public function start( string $from, string $to, bool $dry_run ): array {
		if ( ! self::validDate( $from ) || ! self::validDate( $to ) || $from > $to ) {
			throw new \InvalidArgumentException( esc_html__( 'Choose a start and end date (YYYY-MM-DD), with the start first.', 'invoiceninja' ) );
		}
		if ( $dry_run ) {
			return $this->count( $from, $to );
		}
		$current = $this->status();
		if ( 'running' === ( $current['status'] ?? '' ) && (int) ( $current['touched'] ?? 0 ) > time() - HOUR_IN_SECONDS ) {
			return $current;
		}
		$run = array(
			'id'         => substr( md5( uniqid( '', true ) ), 0, 12 ),
			'status'     => 'running',
			'from'       => $from,
			'to'         => $to,
			'page'       => 1,
			'total'      => $this->total( $from, $to ),
			'scanned'    => 0,
			'queued'     => 0,
			'done'       => 0,
			'started_at' => time(),
			'touched'    => time(),
			'user'       => get_current_user_id(),
		);
		$this->plugin->options->set( self::OPTION, $run );
		$this->plugin->queue->push( self::HOOK, array( 'run' => $run['id'] ), 0, false );
		$this->plugin->logger->log( LogLevel::Info, 'woocommerce', sprintf( 'Backfill started for WooCommerce orders from %s to %s (%d orders)', $from, $to, $run['total'] ) );
		return $run;
	}

	/**
	 * The current or last run.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$run = $this->plugin->options->get( self::OPTION );
		return is_array( $run ) ? $run : array( 'status' => 'none' );
	}

	/**
	 * Stop queuing more orders (orders already queued still run).
	 *
	 * @return array<string, mixed>
	 */
	public function cancel(): array {
		$run = $this->status();
		if ( 'running' === ( $run['status'] ?? '' ) ) {
			$run['status']  = 'cancelled';
			$run['touched'] = time();
			$this->plugin->options->set( self::OPTION, $run );
		}
		return $run;
	}

	/**
	 * Job: queue one page of orders.
	 *
	 * @param array<string, mixed> $payload {run}.
	 */
	public function page( array $payload ): void {
		$run = $this->status();
		if ( 'running' !== ( $run['status'] ?? '' ) || (string) ( $run['id'] ?? '' ) !== (string) ( $payload['run'] ?? '' ) ) {
			return;
		}
		$ids = $this->ids( (string) $run['from'], (string) $run['to'], (int) $run['page'], self::PAGE );
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			++$run['scanned'];
			if ( ! $order instanceof \WC_Order || ( '' !== OrderMeta::text( $order, OrderMeta::INVOICE_ID ) && '' !== OrderMeta::text( $order, OrderMeta::PAYMENT_ID ) ) ) {
				continue;
			}
			++$run['queued'];
			$extra = array( 'backfill' => (string) $run['id'] );
			$this->invoices->queue( $order_id, $extra );
			if ( Payments::isPaid( $order ) ) {
				$this->payments->queue( $order_id, array( 'backfill' => 1 ), 30 );
			}
			foreach ( $order->get_refunds() as $refund ) {
				$this->plugin->queue->push(
					Payments::HOOK_REFUND,
					array(
						'order_id'  => $order_id,
						'refund_id' => $refund->get_id(),
					),
					90
				);
			}
		}
		$run['touched'] = time();
		if ( count( $ids ) < self::PAGE ) {
			$run['status']      = 'queued';
			$run['finished_at'] = time();
			$this->plugin->logger->log( LogLevel::Info, 'woocommerce', sprintf( 'Backfill queued %d of %d WooCommerce orders', (int) $run['queued'], (int) $run['scanned'] ) );
		} else {
			++$run['page'];
			$this->plugin->queue->push( self::HOOK, array( 'run' => $run['id'] ), 5, false );
		}
		$this->plugin->options->set( self::OPTION, $run );
	}

	/**
	 * Count one queued order as done.
	 *
	 * @param int    $order_id Order id.
	 * @param string $run_id   Run.
	 */
	public function itemDone( $order_id, $run_id ): void {
		unset( $order_id );
		$run = $this->status();
		if ( (string) ( $run['id'] ?? '' ) !== (string) $run_id ) {
			return;
		}
		$run['done']    = min( (int) ( $run['queued'] ?? 0 ), (int) ( $run['done'] ?? 0 ) + 1 );
		$run['touched'] = time();
		if ( 'queued' === $run['status'] && $run['done'] >= (int) $run['queued'] ) {
			$run['status'] = 'done';
		}
		$this->plugin->options->set( self::OPTION, $run );
	}

	/**
	 * Dry run: how many orders in the range would be invoiced or paid.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return array<string, mixed>
	 */
	public function count( string $from, string $to ): array {
		$result = array(
			'dry_run'   => true,
			'from'      => $from,
			'to'        => $to,
			'total'     => $this->total( $from, $to ),
			'invoice'   => 0,
			'payment'   => 0,
			'refunds'   => 0,
			'invoiced'  => 0,
			'scanned'   => 0,
			'truncated' => false,
		);
		$page   = 1;
		while ( $result['scanned'] < self::DRY_RUN_LIMIT ) {
			$ids = $this->ids( $from, $to, $page, 100 );
			foreach ( $ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				++$result['scanned'];
				if ( '' !== OrderMeta::text( $order, OrderMeta::INVOICE_ID ) ) {
					++$result['invoiced'];
				} else {
					++$result['invoice'];
				}
				if ( '' === OrderMeta::text( $order, OrderMeta::PAYMENT_ID ) && Payments::isPaid( $order ) && (float) $order->get_total() > 0 ) {
					++$result['payment'];
				}
				$result['refunds'] += count( $order->get_refunds() );
			}
			if ( count( $ids ) < 100 ) {
				break;
			}
			++$page;
		}
		$result['truncated'] = $result['scanned'] < $result['total'];
		return $result;
	}

	/**
	 * Orders in the range, oldest first.
	 *
	 * @param string $from     From.
	 * @param string $to       To.
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 * @return list<int>
	 */
	private function ids( string $from, string $to, int $page, int $per_page ): array {
		$ids = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array_map( static fn( string $s ): string => 'wc-' . $s, self::STATUSES ),
				'date_created' => $this->range( $from, $to ),
				'orderby'      => 'date',
				'order'        => 'ASC',
				'limit'        => $per_page,
				'page'         => $page,
				'return'       => 'ids',
			)
		);
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$out[] = $id instanceof \WC_Abstract_Order ? $id->get_id() : (int) $id; // With return => ids these are numbers.
		}
		return $out;
	}

	/**
	 * Orders in the range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	private function total( string $from, string $to ): int {
		$found = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array_map( static fn( string $s ): string => 'wc-' . $s, self::STATUSES ),
				'date_created' => $this->range( $from, $to ),
				'limit'        => 1,
				'paginate'     => true,
				'return'       => 'ids',
			)
		);
		return is_object( $found ) && isset( $found->total ) ? (int) $found->total : 0;
	}

	/**
	 * The range as WooCommerce timestamps (whole days in the site's time zone).
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	private function range( string $from, string $to ): string {
		$zone  = wp_timezone();
		$start = ( new \DateTimeImmutable( $from . ' 00:00:00', $zone ) )->getTimestamp();
		$end   = ( new \DateTimeImmutable( $to . ' 23:59:59', $zone ) )->getTimestamp();
		return $start . '...' . $end;
	}
}
