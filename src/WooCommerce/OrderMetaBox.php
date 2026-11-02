<?php
/**
 * The Invoice Ninja box on the WooCommerce order screen.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Core\Capabilities;
use InvoiceNinja\Plugin;

/**
 * Works on both order screens (HPOS and the classic post editor): shows the invoice
 * number with a link into Invoice Ninja, its state (pending, failed with the reason, a
 * totals mismatch, refunds mirrored) and a Retry button. The buyer's invitation link is
 * never shown here: it logs whoever opens it into the client's portal.
 */
final class OrderMetaBox {

	/**
	 * Constructor.
	 *
	 * @param Plugin         $plugin   Plugin.
	 * @param OrderToInvoice $invoices Invoices.
	 * @param Payments       $payments Payments.
	 */
	public function __construct( private Plugin $plugin, private OrderToInvoice $invoices, private Payments $payments ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 30 );
		add_action( 'admin_post_invoiceninja_woo_retry', array( $this, 'retry' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Add the box to whichever order screen is in use.
	 */
	public function add(): void {
		$screen = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() && function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box( 'invoiceninja-woo', __( 'Invoice Ninja', 'invoiceninja' ), array( $this, 'render' ), $screen, 'side', 'high' );
	}

	/**
	 * Render the box.
	 *
	 * @param mixed $post_or_order WP_Post (classic) or WC_Order (HPOS).
	 */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		echo wp_kses_post( $this->html( $order ) );
	}

	/**
	 * The box's HTML.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function html( \WC_Order $order ): string {
		$state  = self::state( $order );
		$app    = self::appUrl( $this->plugin );
		$out    = '<div class="invoiceninja-woo-box">';
		$labels = array(
			'pending'   => __( 'Waiting to be sent to Invoice Ninja', 'invoiceninja' ),
			'paused'    => __( 'Paused: orders are paused on this site (it looks like a staging copy). Allow them in Invoice Ninja → Status.', 'invoiceninja' ),
			'draft'     => __( 'Draft created, finishing up', 'invoiceninja' ),
			'sent'      => __( 'Invoice sent', 'invoiceninja' ),
			'failed'    => __( 'Not invoiced', 'invoiceninja' ),
			'cancelled' => __( 'Invoice cancelled', 'invoiceninja' ),
			'skipped'   => __( 'No invoice (the order was cancelled first)', 'invoiceninja' ),
			'off'       => __( 'Invoicing WooCommerce orders is off', 'invoiceninja' ),
		);
		if ( '' !== $state['number'] ) {
			$out .= '<p><strong>' . esc_html(
				sprintf(
					/* translators: %s: invoice number */
					__( 'Invoice %s', 'invoiceninja' ),
					$state['number']
				)
			) . '</strong>';
			if ( '' !== $state['invoice_id'] && null !== $app ) {
				$out .= '<br><a href="' . esc_url( $app( 'invoices/' . rawurlencode( $state['invoice_id'] ) ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in Invoice Ninja', 'invoiceninja' ) . ' <span aria-hidden="true">↗</span><span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'invoiceninja' ) . '</span></a>';
			}
			$out .= '</p>';
		}
		$out .= '<p><span class="invoiceninja-woo-status invoiceninja-woo-status--' . esc_attr( $state['status'] ) . '">' . esc_html( $labels[ $state['status'] ] ?? $state['status'] ) . '</span></p>';
		if ( '' !== $state['error'] ) {
			$out .= '<p class="invoiceninja-woo-error" style="color:#b32d2e">' . esc_html( self::reason( $state['error'] ) ) . '</p>';
		}
		if ( null !== $state['mismatch'] ) {
			$out .= '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Totals differ', 'invoiceninja' ) . '</strong><br>' . esc_html(
				sprintf(
					/* translators: 1: order total, 2: invoice total */
					__( 'WooCommerce: %1$s · Invoice Ninja: %2$s. Check the taxes and lines in Invoice Ninja.', 'invoiceninja' ),
					wp_strip_all_tags( (string) wc_price( $state['mismatch']['expected'], array( 'currency' => $order->get_currency() ) ) ),
					wp_strip_all_tags( (string) wc_price( $state['mismatch']['invoice'], array( 'currency' => $order->get_currency() ) ) )
				)
			) . '</p></div>';
		} elseif ( null !== $state['adjusted'] && 0.0 !== $state['adjusted'] ) {
			$out .= '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: amount */
					__( 'A rounding line of %s matches the invoice to the order total.', 'invoiceninja' ),
					wp_strip_all_tags( (string) wc_price( $state['adjusted'], array( 'currency' => $order->get_currency() ) ) )
				)
			) . '</p>';
		}
		if ( '' !== $state['locked'] ) {
			$out .= '<p class="description">' . esc_html( 'paid' === $state['locked'] ? __( 'The order changed after it was paid; the invoice was left as it was.', 'invoiceninja' ) : __( 'The order changed, but the invoice is locked in Invoice Ninja; it was left as it was.', 'invoiceninja' ) ) . '</p>';
		}
		if ( null !== $state['payment'] ) {
			$out .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: amount */
					__( 'Payment recorded: %s', 'invoiceninja' ),
					wp_strip_all_tags( (string) wc_price( $state['payment'], array( 'currency' => $order->get_currency() ) ) )
				)
			) . '</p>';
		} elseif ( 'sent' === $state['status'] ) {
			$out .= '<p class="description">' . esc_html__( 'No payment recorded yet.', 'invoiceninja' ) . '</p>';
		}
		foreach ( $state['refunds'] as $refund ) {
			$out .= '<p>' . esc_html(
				'mirrored' === $refund['result']
					? sprintf(
						/* translators: %s: amount */
						__( 'Refund of %s mirrored', 'invoiceninja' ),
						wp_strip_all_tags( (string) wc_price( $refund['amount'], array( 'currency' => $order->get_currency() ) ) )
					)
					: __( 'A refund was not mirrored (no payment recorded in Invoice Ninja)', 'invoiceninja' )
			) . '</p>';
		}
		if ( null !== $state['refund_balance'] ) {
			$out .= '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: amount */
					__( 'After the partial refund the invoice shows %s due. Credit or adjust it in Invoice Ninja so the customer isn\'t reminded to pay it.', 'invoiceninja' ),
					wp_strip_all_tags( (string) wc_price( $state['refund_balance'], array( 'currency' => $order->get_currency() ) ) )
				)
			) . '</p></div>';
		}
		if ( $state['retry'] && current_user_can( Capabilities::MANAGE ) ) {
			$url  = wp_nonce_url( admin_url( 'admin-post.php?action=invoiceninja_woo_retry&order_id=' . $order->get_id() ), 'invoiceninja_woo_retry_' . $order->get_id() );
			$out .= '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Retry', 'invoiceninja' ) . '</a></p>';
		}
		return $out . '</div>';
	}

	/**
	 * Everything the box shows, from the order meta.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{status: string, invoice_id: string, number: string, error: string, mismatch: array{expected: float, invoice: float}|null, adjusted: float|null, locked: string, payment: float|null, refunds: list<array{amount: float, result: string}>, refund_balance: float|null, retry: bool}
	 */
	public static function state( \WC_Order $order ): array {
		$status = OrderMeta::text( $order, OrderMeta::STATUS );
		if ( '' === $status || 'queued' === $status ) {
			$status = 'pending';
		}
		$mismatch = OrderMeta::list( $order, OrderMeta::MISMATCH );
		$refunds  = array();
		foreach ( OrderMeta::list( $order, OrderMeta::REFUNDS ) as $row ) {
			if ( is_array( $row ) && 'nothing' !== ( $row['result'] ?? '' ) ) {
				$refunds[] = array(
					'amount' => (float) ( $row['amount'] ?? 0 ),
					'result' => (string) ( $row['result'] ?? '' ),
				);
			}
		}
		$payment_id = OrderMeta::text( $order, OrderMeta::PAYMENT_ID );
		$balance    = OrderMeta::text( $order, OrderMeta::REFUND_BALANCE );
		$adjusted   = OrderMeta::text( $order, OrderMeta::ADJUSTED );
		$error      = OrderMeta::text( $order, OrderMeta::ERROR );
		$updated    = (int) OrderMeta::text( $order, OrderMeta::UPDATED_AT );
		$created    = $order->get_date_created();
		$since      = $updated > 0 ? $updated : ( $created ? $created->getTimestamp() : 0 );
		return array(
			'status'         => $status,
			'invoice_id'     => OrderMeta::text( $order, OrderMeta::INVOICE_ID ),
			'number'         => OrderMeta::text( $order, OrderMeta::INVOICE_NUMBER ),
			'error'          => $error,
			'mismatch'       => isset( $mismatch['expected'], $mismatch['invoice'] ) ? array(
				'expected' => (float) $mismatch['expected'],
				'invoice'  => (float) $mismatch['invoice'],
			) : null,
			'adjusted'       => '' !== $adjusted ? (float) $adjusted : null,
			'locked'         => OrderMeta::text( $order, OrderMeta::LOCKED ),
			'payment'        => '' !== $payment_id && 'external' !== $payment_id ? (float) OrderMeta::text( $order, OrderMeta::PAYMENT_AMOUNT ) : null,
			'refunds'        => $refunds,
			'refund_balance' => '' !== $balance ? (float) $balance : null,
			'retry'          => in_array( $status, array( 'failed', 'draft' ), true ) || '' !== $error || null !== ( $mismatch['expected'] ?? null ) || ( 'pending' === $status && $since < time() - 5 * MINUTE_IN_SECONDS ),
		);
	}

	/**
	 * A plain sentence for an error code.
	 *
	 * @param string $code Code.
	 */
	public static function reason( string $code ): string {
		if ( str_contains( $code, ':' ) ) {
			[ $what, $kind ] = explode( ':', $code, 2 );
			$kind            = \InvoiceNinja\Api\ErrorKind::tryFrom( $kind );
			$title           = null !== $kind ? $kind->title() . '. ' . $kind->fix() : '';
			return match ( $what ) {
				/* translators: %s: what went wrong */
				'payment' => sprintf( __( "The payment couldn't be recorded: %s", 'invoiceninja' ), $title ),
				/* translators: %s: what went wrong */
				'refund'  => sprintf( __( "The refund couldn't be recorded: %s", 'invoiceninja' ), $title ),
				default   => $title,
			};
		}
		return match ( $code ) {
			'no_email'          => __( 'The order has no billing email, so no client could be found or created.', 'invoiceninja' ),
			'currency'          => __( 'The matching client in Invoice Ninja uses another currency than this order. Change the client’s currency there, then retry.', 'invoiceninja' ),
			'invoice_gone'      => __( 'The invoice was deleted in Invoice Ninja. Retry to create a new one.', 'invoiceninja' ),
			'has_payment'       => __( 'The order was cancelled, but its invoice has a payment, so it was left as it was. Refund or adjust it in Invoice Ninja.', 'invoiceninja' ),
			'payment_key_taken' => __( 'Invoice Ninja already has a payment for this order, but it could not be found. Check the payments in Invoice Ninja.', 'invoiceninja' ),
			default             => $code,
		};
	}

	/**
	 * Retry now (admin-post): create or update the invoice, then the payment and refunds.
	 */
	public function retry(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		if ( ! current_user_can( Capabilities::MANAGE ) || ! check_admin_referer( 'invoiceninja_woo_retry_' . $order_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'invoiceninja' ), '', array( 'response' => 403 ) );
		}
		$order  = wc_get_order( $order_id );
		$result = 'done';
		if ( $order instanceof \WC_Order ) {
			$result = $this->run( $order );
		}
		$back = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders' );
		wp_safe_redirect( add_query_arg( 'invoiceninja_retry', $result, $back ) );
		exit;
	}

	/**
	 * Retry an order now.
	 *
	 * @param \WC_Order $order Order.
	 * @return string done or failed.
	 */
	public function run( \WC_Order $order ): string {
		if ( 'failed' === OrderMeta::text( $order, OrderMeta::STATUS ) && '' === OrderMeta::text( $order, OrderMeta::INVOICE_ID ) ) {
			// Ask again from the start: the client may have been fixed in Invoice Ninja meanwhile.
			OrderMeta::set(
				$order,
				array(
					OrderMeta::STATUS    => 'queued',
					OrderMeta::ERROR     => null,
					OrderMeta::CLIENT_ID => null,
				)
			);
		} else {
			OrderMeta::set( $order, array( OrderMeta::ERROR => null ) );
		}
		try {
			if ( in_array( $order->get_status(), array( 'cancelled', 'failed' ), true ) ) {
				$this->invoices->cancel( $order->get_id() );
			} else {
				$this->invoices->sync( $order->get_id() );
				$this->payments->record( $order->get_id() );
				foreach ( $order->get_refunds() as $refund ) {
					$this->payments->refund( $order->get_id(), $refund->get_id() );
				}
			}
		} catch ( ApiException $e ) {
			return 'failed';
		}
		$fresh = wc_get_order( $order->get_id() );
		return $fresh instanceof \WC_Order && '' === OrderMeta::text( $fresh, OrderMeta::ERROR ) ? 'done' : 'failed';
	}

	/**
	 * The result of a retry.
	 */
	public function notice(): void {
		$result = isset( $_GET['invoiceninja_retry'] ) ? sanitize_key( wp_unslash( $_GET['invoiceninja_retry'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( '' === $result ) {
			return;
		}
		$ok = 'done' === $result;
		echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $ok ? __( 'Sent to Invoice Ninja again.', 'invoiceninja' ) : __( "Invoice Ninja still couldn't take it. See the Invoice Ninja box on the order for why.", 'invoiceninja' ) ) . '</p></div>';
	}

	/**
	 * Links into the Invoice Ninja app (the new app on 5.13.44+).
	 *
	 * @param Plugin $plugin Plugin.
	 * @return (callable(string): string)|null
	 */
	public static function appUrl( Plugin $plugin ): ?callable {
		$base = $plugin->credentials->url();
		if ( '' === $base ) {
			return null;
		}
		$state   = $plugin->state->get();
		$company = (string) ( $state['company']['id'] ?? '' );
		if ( \InvoiceNinja\Api\HttpClient::versionAtLeast( (string) ( $state['version'] ?? '' ), '5.13.44' ) ) {
			return static fn( string $path ): string => $base . '/app/' . $path . ( '' !== $company ? '?company=' . rawurlencode( $company ) : '' );
		}
		$web = \InvoiceNinja\Api\BaseUrl::isHosted( $base ) ? 'https://app.invoicing.co' : $base;
		return static fn( string $path ): string => $web . '/#/' . $path . ( str_starts_with( $path, 'invoices/' ) ? '/edit' : '' );
	}
}
