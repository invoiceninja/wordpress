<?php
/**
 * Translated strings for the store script.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Frontend;

/**
 * Translated on the server and passed to store.js, so the front end needs no wp-i18n.
 * Placeholders use %s / %d / %1$s like sprintf.
 */
final class Strings {

	/**
	 * All strings, by key.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			/* translators: %s: product name */
			'added'           => __( '%s added to cart', 'invoiceninja' ),
			'viewCart'        => __( 'View cart', 'invoiceninja' ),
			'checkout'        => __( 'Checkout', 'invoiceninja' ),
			'yourCart'        => __( 'Your cart', 'invoiceninja' ),
			'cartEmpty'       => __( 'Your cart is empty.', 'invoiceninja' ),
			'browse'          => __( 'Browse the shop', 'invoiceninja' ),
			'close'           => __( 'Close', 'invoiceninja' ),
			'closeCart'       => __( 'Close cart', 'invoiceninja' ),
			'remove'          => __( 'Remove', 'invoiceninja' ),
			/* translators: %s: product name */
			'removeItem'      => __( 'Remove %s', 'invoiceninja' ),
			/* translators: %s: product name */
			'oneMore'         => __( 'One more %s', 'invoiceninja' ),
			/* translators: %s: product name */
			'oneFewer'        => __( 'One fewer %s', 'invoiceninja' ),
			'quantity'        => __( 'Quantity', 'invoiceninja' ),
			/* translators: %d: maximum quantity */
			'max'             => __( 'Max %d', 'invoiceninja' ),
			/* translators: %s: price */
			'each'            => __( '%s each', 'invoiceninja' ),
			'subtotal'        => __( 'Subtotal', 'invoiceninja' ),
			'tax'             => __( 'Tax', 'invoiceninja' ),
			'taxLater'        => __( 'Added on the payment page', 'invoiceninja' ),
			'taxAddress'      => __( 'Added on the payment page, based on your address', 'invoiceninja' ),
			'total'           => __( 'Total', 'invoiceninja' ),
			'orderSummary'    => __( 'Order summary', 'invoiceninja' ),
			'editCart'        => __( 'Edit cart', 'invoiceninja' ),
			'continueShop'    => __( 'Continue shopping', 'invoiceninja' ),
			'email'           => __( 'Email', 'invoiceninja' ),
			'firstName'       => __( 'First name', 'invoiceninja' ),
			'lastName'        => __( 'Last name', 'invoiceninja' ),
			'phone'           => __( 'Phone', 'invoiceninja' ),
			'optional'        => __( '(optional)', 'invoiceninja' ),
			'country'         => __( 'Country', 'invoiceninja' ),
			'postcode'        => __( 'Postcode', 'invoiceninja' ),
			'continue'        => __( 'Continue', 'invoiceninja' ),
			'nextCode'        => __( "Next, we'll email you a 6-digit code to confirm it's you. Then you'll pay on our secure payment page.", 'invoiceninja' ),
			'haveAccount'     => __( 'Already have an account?', 'invoiceninja' ),
			'logIn'           => __( 'Log in', 'invoiceninja' ),
			'loginRequired'   => __( 'Log in to place your order. Your cart is saved while you do.', 'invoiceninja' ),
			'placeOrder'      => __( 'Place order', 'invoiceninja' ),
			'checkEmail'      => __( 'Check your email', 'invoiceninja' ),
			/* translators: %s: email address */
			'codeSent'        => __( 'We sent a 6-digit code to %s.', 'invoiceninja' ),
			'codeWhy'         => __( "It confirms it's you and keeps your invoices private.", 'invoiceninja' ),
			'change'          => __( 'Change', 'invoiceninja' ),
			'code'            => __( '6-digit code', 'invoiceninja' ),
			'codeHelp'        => __( "Type or paste it. We'll place your order as soon as all 6 digits are in.", 'invoiceninja' ),
			'remember'        => __( 'Remember this device for 30 days', 'invoiceninja' ),
			'rememberHint'    => __( '(untick on a shared computer)', 'invoiceninja' ),
			'confirmOrder'    => __( 'Confirm and place order', 'invoiceninja' ),
			/* translators: %s: time left, like 0:42 */
			'resendIn'        => __( 'You can ask for a new code in %s', 'invoiceninja' ),
			'resendNow'       => __( 'Not there? Check spam or promotions, or use another email.', 'invoiceninja' ),
			'resend'          => __( 'Resend code', 'invoiceninja' ),
			'useAnother'      => __( 'Use another email', 'invoiceninja' ),
			'placing'         => __( 'Placing your order…', 'invoiceninja' ),
			'welcomeBack'     => __( 'Welcome back', 'invoiceninja' ),
			/* translators: %s: email address */
			'remembered'      => __( 'This device is remembered for %s, so there is no code this time.', 'invoiceninja' ),
			'thanksPay'       => __( 'Thanks. Your order is ready to pay.', 'invoiceninja' ),
			'thanksReceived'  => __( "Thanks. We've received your order.", 'invoiceninja' ),
			/* translators: 1: order number, 2: invoice number */
			'orderLine'       => __( 'Order #%1$s · Invoice %2$s', 'invoiceninja' ),
			/* translators: %s: order number */
			'orderLineLater'  => __( 'Order #%s · Invoice to follow', 'invoiceninja' ),
			'payButton'       => __( 'Continue to secure payment', 'invoiceninja' ),
			'opensBilling'    => __( '(opens our billing system)', 'invoiceninja' ),
			'payLater'        => __( "We've also emailed this link to you. Paying later is fine: your order is saved.", 'invoiceninja' ),
			'linkSoon'        => __( "We'll email your payment link shortly, usually within a few minutes. Not there within an hour? Contact us.", 'invoiceninja' ),
			'inTouch'         => __( "We'll be in touch about payment. Nothing has been charged.", 'invoiceninja' ),
			'viewOrder'       => __( 'View your order', 'invoiceninja' ),
			'backToShop'      => __( 'Back to the shop', 'invoiceninja' ),
			/* translators: %s: order number */
			'waiting'         => __( 'Order #%s is waiting for payment', 'invoiceninja' ),
			'changeOrder'     => __( 'Change order', 'invoiceninja' ),
			/* translators: %s: order number */
			'changeConfirm'   => __( "Change your order? We'll cancel #%s and put its items back in your cart. You haven't been charged.", 'invoiceninja' ),
			'keepOrder'       => __( 'Keep order', 'invoiceninja' ),
			'cancelEdit'      => __( 'Cancel it and edit cart', 'invoiceninja' ),
			'yourOrder'       => __( 'Your order', 'invoiceninja' ),
			'statusQueued'    => __( 'Received', 'invoiceninja' ),
			'statusAwaiting'  => __( 'Waiting for payment', 'invoiceninja' ),
			'statusPaid'      => __( 'Paid', 'invoiceninja' ),
			'statusCancelled' => __( 'Cancelled', 'invoiceninja' ),
			'cancelledText'   => __( "This order was cancelled and its payment link no longer works. You haven't been charged for it.", 'invoiceninja' ),
			'paidText'        => __( 'Thanks! Your receipt is on its way.', 'invoiceninja' ),
			'checking'        => __( 'Checking your payment…', 'invoiceninja' ),
			'notYet'          => __( "Your payment isn't showing yet. Card payments usually appear within a minute; bank transfers can take a few days. We'll email you when it arrives.", 'invoiceninja' ),
			'checkAgain'      => __( 'Check again', 'invoiceninja' ),
			'setPassword'     => __( 'Save your details', 'invoiceninja' ),
			'setPasswordText' => __( 'Set a password to see your orders and invoices in one place.', 'invoiceninja' ),
			'findOrder'       => __( 'Find my order', 'invoiceninja' ),
			'findIntro'       => __( "That link didn't open an order. Enter your order number and email, and we'll send you a fresh link.", 'invoiceninja' ),
			'orderNumber'     => __( 'Order number', 'invoiceninja' ),
			'orderNumberHint' => __( "It's in your order email.", 'invoiceninja' ),
			'emailLink'       => __( 'Email me the link', 'invoiceninja' ),
			'linkOnWay'       => __( 'Link on its way', 'invoiceninja' ),
			'findSent'        => __( "If that order exists, we've emailed its link to that address. It can take a minute or two.", 'invoiceninja' ),
			/* translators: %s: order number */
			'bannerWaiting'   => __( 'Order #%s is waiting for payment.', 'invoiceninja' ),
			/* translators: %s: order number */
			'bannerPaid'      => __( 'Thanks! Order #%s is paid.', 'invoiceninja' ),
			'hide'            => __( 'Hide this message', 'invoiceninja' ),
			'error'           => __( 'Something went wrong. Please try again.', 'invoiceninja' ),
			'tryAgain'        => __( 'Try again', 'invoiceninja' ),
			'unavailable'     => __( 'Online ordering is temporarily unavailable', 'invoiceninja' ),
			'unavailableText' => __( 'Your cart is saved. Please try again later, or contact us to order.', 'invoiceninja' ),
			'soldOut'         => __( 'Sold out', 'invoiceninja' ),
			'items'           => __( 'items', 'invoiceninja' ),
		);
	}
}
