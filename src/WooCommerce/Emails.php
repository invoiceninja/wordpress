<?php
/**
 * The invoice link in WooCommerce's customer emails.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Plugin;

/**
 * Once the invoice exists, customer emails get a link to view and download it. The link
 * is the buyer's own invitation (stored when the invoice was created), never another
 * contact's. Emails to the shop never include it. Before the invoice exists, customers
 * with an account are told where it will appear.
 */
final class Emails {

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
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render' ), 10, 4 );
	}

	/**
	 * Add the link after the order table.
	 *
	 * @param mixed $order         Order.
	 * @param mixed $sent_to_admin Whether it goes to the shop.
	 * @param mixed $plain_text    Plain-text email.
	 * @param mixed $email         WC_Email.
	 */
	public function render( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! OrderToInvoice::isOrder( $order ) || $sent_to_admin || ! $this->plugin->options->setting( 'woo.email_link' ) ) {
			return;
		}
		if ( $email instanceof \WC_Email && ! $email->is_customer_email() ) {
			return;
		}
		$text = self::text( $order, (bool) $this->plugin->options->setting( 'woo.account_tab' ), (bool) $plain_text );
		if ( '' !== $text ) {
			echo $plain_text ? wp_strip_all_tags( $text ) . "\n\n" : wp_kses_post( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email (no HTML to escape), or kses'd HTML.
		}
	}

	/**
	 * What to add for this order (HTML, or plain text).
	 *
	 * @param \WC_Order $order       Order.
	 * @param bool      $account_tab Whether My Account has the Invoices & billing tab.
	 * @param bool      $plain       Plain text.
	 */
	public static function text( \WC_Order $order, bool $account_tab, bool $plain = false ): string {
		$link   = OrderMeta::text( $order, OrderMeta::LINK );
		$number = OrderMeta::text( $order, OrderMeta::INVOICE_NUMBER );
		$status = OrderMeta::text( $order, OrderMeta::STATUS );
		if ( '' !== $link && 'sent' === $status ) {
			$label = '' !== $number
				/* translators: %s: invoice number */
				? sprintf( __( 'View or download invoice %s', 'invoiceninja' ), $number )
				: __( 'View or download your invoice', 'invoiceninja' );
			if ( $plain ) {
				return $label . ': ' . esc_url_raw( $link );
			}
			return '<p class="invoiceninja-woo-invoice" style="margin:0 0 16px"><a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a></p>';
		}
		if ( in_array( $status, array( '', 'queued', 'draft', 'paused' ), true ) && $account_tab && $order->get_customer_id() > 0 ) {
			$text = __( 'Your invoice is being prepared. You’ll find it in your account under Invoices & billing.', 'invoiceninja' );
			return $plain ? $text : '<p class="invoiceninja-woo-invoice" style="margin:0 0 16px">' . esc_html( $text ) . '</p>';
		}
		return '';
	}
}
