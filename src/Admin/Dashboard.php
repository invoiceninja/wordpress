<?php
/**
 * Order stats for Home and the dashboard widget, and the new-order and weekly emails.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Admin;

use InvoiceNinja\Commerce\Orders;
use InvoiceNinja\Core\Capabilities;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * Counts come from this site's own orders table (no Invoice Ninja calls), so Home and the
 * dashboard stay fast. Invoice Ninja's own notifications already cover invoices and
 * payments, so the new-order email is off by default.
 */
final class Dashboard {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'invoiceninja_rest_routes', fn( Routes $r ) => $r->add( '/admin/stats', 'GET', array( $this, 'statsRoute' ), 'view' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'widget' ) );
		add_action( 'invoiceninja_order_placed', array( $this, 'newOrder' ) );
		add_action( 'invoiceninja_daily_tasks', array( $this, 'weekly' ) );
	}

	/**
	 * Stats for the last 30 days and the latest orders.
	 *
	 * @return array<string, mixed>
	 */
	public function stats(): array {
		global $wpdb;
		$table = Orders::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- our own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n, SUM(total) AS amount FROM {$table} WHERE created_at >= %s GROUP BY status", $since ), ARRAY_A );
		$open = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} WHERE status IN ('attention', 'queued', 'placing', 'awaiting_payment') GROUP BY status", ARRAY_A );
		// phpcs:enable
		$by   = array();
		foreach ( $rows as $row ) {
			$by[ (string) $row['status'] ] = array(
				'count'  => (int) $row['n'],
				'amount' => (float) $row['amount'],
			);
		}
		$now = array();
		foreach ( $open as $row ) {
			$now[ (string) $row['status'] ] = (int) $row['n'];
		}
		$latest = $this->plugin->orders->list( array( 'per_page' => 5 ) );
		return array(
			'orders'    => array_sum( array_column( $by, 'count' ) ),
			'paid'      => $this->plugin->statics->money( (float) ( $by['paid']['amount'] ?? 0 ) ),
			'paidCount' => (int) ( $by['paid']['count'] ?? 0 ),
			'awaiting'  => (int) ( $now['awaiting_payment'] ?? 0 ),
			'attention' => (int) ( $now['attention'] ?? 0 ),
			'queued'    => (int) ( $now['queued'] ?? 0 ) + (int) ( $now['placing'] ?? 0 ),
			'latest'    => array_map(
				fn( array $o ): array => array(
					'id'     => (int) $o['id'],
					'number' => (string) $o['number'],
					'status' => (string) $o['status'],
					'email'  => (string) $o['email'],
					'total'  => $this->plugin->statics->money( (float) $o['total'] ),
					'date'   => (string) $o['created_at'],
				),
				$latest['items']
			),
			'pages'     => array(
				'shop'     => $this->plugin->store->pageUrl( 'shop' ),
				'checkout' => $this->plugin->store->pageUrl( 'checkout' ),
				'status'   => $this->plugin->store->pageUrl( 'status' ),
				'account'  => $this->plugin->store->pageUrl( 'account' ),
			),
			'display'   => (string) $this->plugin->options->setting( 'store.display' ),
		);
	}

	/**
	 * GET /admin/stats.
	 */
	public function statsRoute(): \WP_REST_Response {
		return new \WP_REST_Response( $this->stats() );
	}

	/**
	 * The dashboard widget.
	 */
	public function widget(): void {
		if ( ! current_user_can( Capabilities::VIEW ) || 'none' === $this->plugin->credentials->source() ) {
			return;
		}
		wp_add_dashboard_widget(
			'invoiceninja_orders',
			__( 'Invoice Ninja orders', 'invoiceninja' ),
			function (): void {
				$s = $this->stats();
				echo '<ul class="invoiceninja-dash">';
				/* translators: %d: number of orders */
				echo '<li>' . esc_html( sprintf( _n( '%d order in the last 30 days', '%d orders in the last 30 days', (int) $s['orders'], 'invoiceninja' ), (int) $s['orders'] ) ) . '</li>';
				/* translators: %s: amount */
				echo '<li>' . esc_html( sprintf( __( '%s paid', 'invoiceninja' ), (string) $s['paid'] ) ) . '</li>';
				/* translators: %d: number of orders */
				echo '<li>' . esc_html( sprintf( _n( '%d waiting for payment', '%d waiting for payment', (int) $s['awaiting'], 'invoiceninja' ), (int) $s['awaiting'] ) ) . '</li>';
				if ( $s['attention'] > 0 ) {
					/* translators: %d: number of orders */
					echo '<li><strong>' . esc_html( sprintf( _n( '%d needs attention', '%d need attention', (int) $s['attention'], 'invoiceninja' ), (int) $s['attention'] ) ) . '</strong></li>';
				}
				echo '</ul><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-orders' ) ) . '">' . esc_html__( 'View orders', 'invoiceninja' ) . '</a></p>';
			}
		);
	}

	/**
	 * Optional email to admins about each new order.
	 *
	 * @param array<string, mixed> $order Order.
	 */
	public function newOrder( array $order ): void {
		if ( ! $this->plugin->options->setting( 'notifications.new_orders' ) ) {
			return;
		}
		$lines = implode( ', ', array_map( static fn( array $i ): string => $i['quantity'] . ' × ' . $i['title'], (array) $order['items'] ) );
		$this->plugin->mailer->send(
			$this->plugin->notifications->recipients(),
			/* translators: %s: order number */
			sprintf( __( 'New order %s', 'invoiceninja' ), (string) $order['number'] ),
			'<p>' . esc_html( $lines ) . '</p><p>' . esc_html( $this->plugin->statics->money( (float) $order['total'] ) ) . ' · ' . esc_html( (string) $order['email'] ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-orders' ) ) . '">' . esc_html__( 'Open Orders', 'invoiceninja' ) . '</a></p>'
		);
	}

	/**
	 * Weekly summary on Mondays (when on).
	 */
	public function weekly(): void {
		if ( ! $this->plugin->options->setting( 'notifications.weekly' ) || '1' !== wp_date( 'N' ) ) {
			return;
		}
		$week = (string) wp_date( 'o-W' );
		if ( $this->plugin->options->get( 'weekly_sent' ) === $week ) {
			return;
		}
		$this->plugin->options->set( 'weekly_sent', $week );
		global $wpdb;
		$table = Orders::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- our own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n, SUM(total) AS amount FROM {$table} WHERE created_at >= %s GROUP BY status", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), ARRAY_A );
		$count = array_sum( array_map( 'intval', array_column( $rows, 'n' ) ) );
		$paid  = 0.0;
		foreach ( $rows as $row ) {
			if ( 'paid' === $row['status'] ) {
				$paid = (float) $row['amount'];
			}
		}
		$this->plugin->mailer->send(
			$this->plugin->notifications->recipients(),
			__( 'Your week in orders', 'invoiceninja' ),
			/* translators: 1: number of orders, 2: amount paid */
			'<p>' . esc_html( sprintf( _n( '%1$d order this week, %2$s paid.', '%1$d orders this week, %2$s paid.', $count, 'invoiceninja' ), $count, $this->plugin->statics->money( $paid ) ) ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-orders' ) ) . '">' . esc_html__( 'Open Orders', 'invoiceninja' ) . '</a></p>'
		);
	}
}
