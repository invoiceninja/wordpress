<?php
/**
 * The page buyers land on after Invoice Ninja's purchase page.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Frontend\Store;
use InvoiceNinja\Plugin;

/**
 * Invoice Ninja's return_url carries no context, so a short-lived cookie set by the
 * Subscribe endpoint says what the visitor set out to do. States:
 * - done: the purchase callback arrived ("Your subscription is ready");
 * - changed: a plan change arrived;
 * - pending: not yet; the page checks again for 30 seconds;
 * - slow: still nothing after 30 seconds ("Payment received; we'll email you");
 * - none: no cookie (another device, or a bookmark): "Check your email · Manage in Account".
 */
final class ReturnPage {

	/**
	 * Constructor.
	 *
	 * @param Plugin    $plugin    Plugin.
	 * @param Redirects $redirects Redirects.
	 */
	public function __construct( private Plugin $plugin, private Redirects $redirects ) {}

	/**
	 * Copy for each state: title and text.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function copy(): array {
		return array(
			'done'    => array( __( 'Your subscription is ready', 'invoiceninja' ), __( "Thank you! We've emailed your receipt and the details of your subscription.", 'invoiceninja' ) ),
			'changed' => array( __( 'Your plan has changed', 'invoiceninja' ), __( "We've emailed the details. Your next invoice reflects the new plan.", 'invoiceninja' ) ),
			'pending' => array( __( 'Setting up your subscription…', 'invoiceninja' ), __( 'This usually takes a few seconds.', 'invoiceninja' ) ),
			'slow'    => array( __( 'Payment received', 'invoiceninja' ), __( "We're still setting up your subscription. We'll email you as soon as it's ready.", 'invoiceninja' ) ),
			'none'    => array( __( 'Thanks for subscribing', 'invoiceninja' ), __( 'Check your email for the details of your subscription.', 'invoiceninja' ) ),
		);
	}

	/**
	 * Print the page and stop.
	 *
	 * @return never
	 */
	public function render(): never {
		nocache_headers();
		$state   = $this->redirects->returnState();
		$copy    = self::copy();
		$account = $this->plugin->store->pageUrl( 'account' );
		$html    = '<div class="invoiceninja-subreturn" data-invoiceninja-return="' . esc_attr( $state ) . '">'
			. '<h1 class="invoiceninja-subreturn__title" data-title tabindex="-1">' . esc_html( $copy[ $state ][0] ) . '</h1>'
			. '<p class="invoiceninja-subreturn__text" data-text role="status" aria-live="polite">' . esc_html( $copy[ $state ][1] ) . '</p>'
			. '<p class="invoiceninja-subreturn__links">'
			. ( '' !== $account ? '<a class="wp-element-button" href="' . esc_url( $account ) . '">' . esc_html__( 'Manage in Account', 'invoiceninja' ) . '</a> ' : '' )
			. '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the site', 'invoiceninja' ) . '</a></p></div>';

		wp_enqueue_style( Blocks::HANDLE );
		wp_enqueue_script( Blocks::HANDLE );
		wp_add_inline_script(
			Blocks::HANDLE,
			'window.invoiceNinjaSubscriptions = ' . wp_json_encode(
				array(
					'rest' => esc_url_raw( rest_url( 'invoiceninja/v1/subscriptions/return' ) ),
					'copy' => $copy,
				)
			) . ';',
			'before'
		);
		add_filter( 'pre_get_document_title', static fn(): string => $copy[ $state ][0] . ' – ' . get_bloginfo( 'name' ) );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		status_header( 200 );
		self::shell( $html );
		exit;
	}

	/**
	 * Print content inside the theme's header and footer (block and classic themes).
	 *
	 * @param string $html Escaped HTML.
	 */
	private static function shell( string $html ): void {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
			$footer = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
			echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped attributes.
			wp_head();
			echo '</head><body class="' . esc_attr( implode( ' ', get_body_class( 'invoiceninja-return-page' ) ) ) . '">';
			wp_body_open();
			echo '<div class="wp-site-blocks">' . $header . '<main class="wp-block-group is-layout-constrained has-global-padding">' . $html . '</main>' . $footer . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks and escaped markup.
			wp_footer();
			echo '</body></html>';
			return;
		}
		get_header();
		echo '<main id="primary" class="site-main invoiceninja-return-main">' . $html . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		get_footer();
	}
}
