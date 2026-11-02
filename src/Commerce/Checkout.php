<?php
/**
 * Guest checkout: details, email code, order.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Commerce;

use InvoiceNinja\Plugin;
use InvoiceNinja\Verification\DeviceRemember;
use InvoiceNinja\Verification\EmailCode;

/**
 * 1. start: check the cart and send a 6-digit code (skipped on a remembered device);
 *    the response is the same whether or not the email belongs to a client.
 * 2. confirm: check the code, save the order (a double submit returns the same order),
 *    then create the invoice. If Invoice Ninja is down the order is kept and sent later.
 * No Invoice Ninja record is created before the code is confirmed.
 */
final class Checkout {

	/**
	 * Constructor.
	 *
	 * @param Plugin         $plugin  Plugin.
	 * @param Cart           $cart    Cart.
	 * @param Orders         $orders  Orders.
	 * @param OrderService   $service Order service.
	 * @param OrderNumbers   $numbers Numbers.
	 * @param EmailCode      $codes   Email codes.
	 * @param DeviceRemember $device  Remembered devices.
	 * @param StockCheck     $stock   Live stock check.
	 */
	public function __construct(
		private Plugin $plugin,
		private Cart $cart,
		private Orders $orders,
		private OrderService $service,
		private OrderNumbers $numbers,
		private EmailCode $codes,
		private DeviceRemember $device,
		private StockCheck $stock,
	) {}

	/**
	 * Store settings that stop this visitor ordering: store off, or login required.
	 *
	 * @return array<string, mixed>|null The error reply, or null to continue.
	 */
	private function gate(): ?array {
		if ( 'off' === $this->plugin->options->setting( 'store.mode' ) ) {
			return array(
				'ok'      => false,
				'code'    => 'store_off',
				'message' => __( "Online ordering isn't available on this site.", 'invoiceninja' ),
			);
		}
		if ( $this->plugin->options->setting( 'store.require_login' ) && 0 === self::userId() ) {
			$back = wp_get_referer();
			return array(
				'ok'        => false,
				'code'      => 'login_required',
				'message'   => __( 'Log in to place your order. Your cart is saved while you do.', 'invoiceninja' ),
				'login_url' => wp_login_url( $back ? $back : home_url( '/' ) ),
			);
		}
		return null;
	}

	/**
	 * The logged-in shopper. Store requests carry no REST nonce (pages stay cacheable), so
	 * WordPress treats them as logged out; read the login cookie instead. The routes'
	 * same-site Origin check stands in for the nonce.
	 */
	public static function userId(): int {
		$id = get_current_user_id();
		if ( $id > 0 ) {
			return $id;
		}
		$cookie = wp_validate_auth_cookie( '', 'logged_in' );
		return $cookie ? (int) $cookie : 0;
	}

	/**
	 * With login required, orders always use the account's own email: a shared computer's
	 * remembered device must never let one customer order as another.
	 *
	 * @param array<string, string> $details Buyer details.
	 * @return array<string, string>
	 */
	private function accountEmail( array $details ): array {
		if ( $this->plugin->options->setting( 'store.require_login' ) ) {
			$user = get_userdata( self::userId() );
			if ( $user ) {
				$details['email'] = (string) $user->user_email;
			}
		}
		return $details;
	}

	/**
	 * The address used for rate limits: IPv6 by /64, since one connection usually gets a
	 * whole /64 to rotate through.
	 *
	 * @param string $ip IP.
	 */
	public static function ipBucket( string $ip ): string {
		$packed = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? inet_pton( $ip ) : false;
		return false !== $packed ? bin2hex( substr( $packed, 0, 8 ) ) . '::/64' : $ip;
	}

	/**
	 * Whether the store sells one product per order.
	 */
	private function buyNow(): bool {
		return 'buy_now' === $this->plugin->options->setting( 'store.mode' );
	}

	/**
	 * Whether the store can take orders right now; returns a reason when it can't.
	 */
	public function unavailable(): ?string {
		if ( 'connected' !== $this->plugin->state->get()['status'] || ! $this->plugin->environment->writesAllowed() ) {
			return __( 'Online ordering is temporarily unavailable. Please try again later.', 'invoiceninja' );
		}
		return null;
	}

	/**
	 * Step 1: validate and send a code.
	 *
	 * @param array<string, string> $details email, first_name, last_name, phone.
	 * @param string                $ip      Shopper IP.
	 * @return array<string, mixed>
	 */
	public function start( array $details, string $ip ): array {
		$reason = $this->unavailable();
		if ( null !== $reason ) {
			return array(
				'ok'      => false,
				'code'    => 'unavailable',
				'message' => $reason,
			);
		}
		$gate = $this->gate();
		if ( null !== $gate ) {
			return $gate;
		}
		$details = $this->accountEmail( $details );
		$email   = EmailCode::normalize( $details['email'] ?? '' );
		if ( ! is_email( $email ) ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_email',
				'message' => __( 'Enter a valid email address.', 'invoiceninja' ),
			);
		}
		$cart_id = $this->cart->currentId();
		if ( null === $cart_id || ! $this->cart->items( $cart_id ) ) {
			return array(
				'ok'      => false,
				'code'    => 'empty_cart',
				'message' => __( 'Your cart is empty.', 'invoiceninja' ),
			);
		}
		// Stock right now, before the code is sent (limited per address: each check calls Invoice Ninja).
		if ( $this->plugin->options->increment( 'ctr_stock_' . substr( md5( self::ipBucket( $ip ) ), 0, 16 ) . '_' . (int) floor( time() / HOUR_IN_SECONDS ) ) <= 60 ) {
			$this->stock->refresh( $this->cart->items( $cart_id ) );
		}
		$summary = $this->cart->reconcile( $cart_id, $this->buyNow() );
		if ( ! $summary['lines'] ) {
			return array(
				'ok'      => false,
				'code'    => 'cart_changed',
				'message' => __( 'Everything in your cart has sold out, so your cart is now empty.', 'invoiceninja' ),
				'cart'    => $summary,
			);
		}
		if ( $summary['changed'] ) {
			return array(
				'ok'      => false,
				'code'    => 'cart_changed',
				'message' => __( "Some items changed or sold out, so we've updated your cart. Please check it before continuing.", 'invoiceninja' ),
				'cart'    => $summary,
			);
		}
		if ( $this->device->current( $email ) ) {
			return array(
				'ok'   => true,
				'next' => 'confirm',
			);
		}
		$sent = $this->codes->send( $email, $ip, 'checkout' );
		return match ( $sent['status'] ) {
			'sent'    => array(
				'ok'          => true,
				'next'        => 'code',
				'retry_after' => $sent['retry_after'],
			),
			'wait'    => array(
				'ok'          => true,
				'next'        => 'code',
				'retry_after' => $sent['retry_after'],
			),
			'limited' => array(
				'ok'      => false,
				'code'    => 'rate_limited',
				'message' => __( 'Too many codes were requested. Please try again in an hour.', 'invoiceninja' ),
			),
			default   => array(
				'ok'      => false,
				'code'    => 'send_failed',
				'message' => __( "We couldn't send the code. Please check the address or contact us.", 'invoiceninja' ),
			),
		};
	}

	/**
	 * Step 2: check the code and place the order.
	 *
	 * @param array<string, string> $details  email, first_name, last_name, phone.
	 * @param string                $code     Code (empty when the device is remembered).
	 * @param bool                  $remember Remember this device.
	 * @param string                $ip       Shopper IP.
	 * @return array<string, mixed>
	 */
	public function confirm( array $details, string $code, bool $remember, string $ip = '' ): array {
		$reason = $this->unavailable();
		if ( null !== $reason ) {
			return array(
				'ok'      => false,
				'code'    => 'unavailable',
				'message' => $reason,
			);
		}
		$gate = $this->gate();
		if ( null !== $gate ) {
			return $gate;
		}
		$details = $this->accountEmail( $details );
		$email   = EmailCode::normalize( $details['email'] ?? '' );
		// The same browser sending again after its reply was lost (its code is used up by now):
		// give it the order it already placed. Needs this browser's signed cart cookie and the same email.
		$cart_id = $this->cart->currentId();
		$done    = null !== $cart_id ? $this->orders->find( $this->cart->orderFor( $cart_id ) ) : null;
		if ( null !== $done && $done['email'] === $email && null !== $cart_id && ! $this->cart->items( $cart_id ) ) {
			return array( 'ok' => true ) + $this->publicView( $done, true );
		}
		if ( ! $this->device->current( $email ) ) {
			$result = $this->codes->verify( $email, $code, 'checkout', $ip );
			if ( 'ok' !== $result ) {
				$messages = array(
					'wrong'   => __( "That code isn't right. Check the email and try again.", 'invoiceninja' ),
					'expired' => __( 'That code has expired. Send a new one.', 'invoiceninja' ),
					'locked'  => __( 'Too many wrong codes. Send a new one.', 'invoiceninja' ),
				);
				return array(
					'ok'      => false,
					'code'    => 'code_' . $result,
					'message' => $messages[ $result ] ?? $messages['wrong'],
				);
			}
			if ( $remember ) {
				$this->device->remember( $email );
			}
		}

		$summary = null === $cart_id ? $this->cart->summary( array() ) : $this->cart->reconcile( $cart_id, $this->buyNow() );
		if ( ! $summary['lines'] || null === $cart_id ) {
			return array(
				'ok'      => false,
				'code'    => 'empty_cart',
				'message' => __( 'Your cart is empty.', 'invoiceninja' ),
			);
		}
		if ( $summary['changed'] ) {
			return array(
				'ok'      => false,
				'code'    => 'cart_changed',
				'message' => __( "Some items changed or sold out, so we've updated your cart. Please check it before continuing.", 'invoiceninja' ),
				'cart'    => $summary,
			);
		}

		// Same cart, same email, same contents: a double submit gets the same order.
		// Only what's bought, not stock limits that can move between two clicks.
		$bought = array_map(
			static fn( array $line ): array => array( $line['product'], $line['quantity'], $line['price'] ),
			$summary['lines']
		);
		$token  = substr( hash( 'sha256', $cart_id . '|' . $email . '|' . wp_json_encode( $bought ) ), 0, 32 );
		$order  = $this->orders->findBy( 'token', $token );
		if ( null === $order ) {
			try {
				$order = $this->orders->create(
					array(
						'number'   => $this->numbers->next(),
						'token'    => $token,
						'email'    => $email,
						'user_id'  => self::userId(),
						'customer' => array(
							'first_name' => sanitize_text_field( $details['first_name'] ?? '' ),
							'last_name'  => sanitize_text_field( $details['last_name'] ?? '' ),
							'phone'      => sanitize_text_field( $details['phone'] ?? '' ),
						),
						'items'    => $summary['lines'],
						'total'    => $summary['total'],
					)
				);
			} catch ( \RuntimeException $e ) {
				return array(
					'ok'      => false,
					'code'    => 'save_failed',
					'message' => __( "We couldn't save your order. Please try again.", 'invoiceninja' ),
				);
			}
			$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s received', $order['number'] ), array(), 'order', (string) $order['number'] );
			$order = $this->service->place( $order, true );
			if ( in_array( $order['status'], array( 'queued', 'attention' ), true ) ) {
				$this->emailBuyer( $order ); // "Received"; the payment link follows once it's placed.
			}
		}
		// A new cart id, so buying the same items again later is a new order.
		$this->cart->clear( $cart_id, (int) $order['id'] );
		return array( 'ok' => true ) + $this->publicView( $order, true );
	}

	/**
	 * The order as the buyer may see it.
	 *
	 * @param array<string, mixed> $order    Order.
	 * @param bool                 $with_key Include the status link.
	 * @return array<string, mixed>
	 */
	public function publicView( array $order, bool $with_key ): array {
		$view = array(
			'number'         => (string) $order['number'],
			'status'         => (string) $order['status'],
			'total'          => (float) $order['total'],
			'invoice_number' => (string) $order['invoice_number'],
			'items'          => array_map(
				static fn( array $i ): array => array(
					'title'    => (string) $i['title'],
					'quantity' => (int) $i['quantity'],
					'price'    => (float) $i['price'],
				),
				(array) $order['items']
			),
			'email'          => self::maskEmail( (string) $order['email'] ),
			'pay_link'       => 'awaiting_payment' === $order['status'] ? (string) $order['pay_link'] : '',
		);
		if ( $with_key ) {
			$view['status_url'] = $this->statusUrl( $order );
		}
		return $view;
	}

	/**
	 * Look up an order for the Order Status page.
	 *
	 * @param string $number Order number.
	 * @param string $key    Status key.
	 * @return array<string, mixed>|null Null when the number or key is wrong (never says which).
	 */
	public function status( string $number, string $key ): ?array {
		$order = $this->orders->findBy( 'number', $number );
		if ( null === $order || ! hash_equals( $this->key( (string) $order['number'] ), $key ) ) {
			return null;
		}
		return $this->publicView( $this->service->refreshStatus( $order ), false );
	}

	/**
	 * The status link for an order.
	 *
	 * @param array<string, mixed> $order Order.
	 */
	public function statusUrl( array $order ): string {
		// The key sits in the fragment, which browsers never send to servers, logs, analytics or caches.
		return add_query_arg( 'invoiceninja_order', rawurlencode( (string) $order['number'] ), home_url( '/' ) ) . '#key=' . $this->key( (string) $order['number'] );
	}

	/**
	 * Secret key for an order (128 bits, derived, so it's never stored in plain text).
	 *
	 * @param string $number Order number.
	 */
	private function key( string $number ): string {
		return substr( hash_hmac( 'sha256', 'order|' . $number, $this->plugin->secrets->get( 'secret' ) ), 0, 32 );
	}

	/**
	 * Email the buyer: the payment link, or that the order was received.
	 *
	 * @param array<string, mixed> $order Order.
	 */
	public function emailBuyer( array $order ): void {
		$status = esc_url( $this->statusUrl( $order ) );
		$lines  = '';
		foreach ( (array) $order['items'] as $item ) {
			$lines .= '<tr><td style="padding:4px 0">' . esc_html( wp_specialchars_decode( (string) $item['title'], ENT_QUOTES ) ) . ' × ' . (int) $item['quantity'] . '</td><td style="padding:4px 0;text-align:right">' . esc_html( number_format( (float) $item['line_total'], 2 ) ) . '</td></tr>';
		}
		$table = '<table style="width:100%;border-collapse:collapse;border:1px solid #dcdcde;margin:12px 0" cellpadding="8">' . $lines . '<tr><td style="font-weight:600;border-top:1px solid #dcdcde">' . esc_html__( 'Total', 'invoiceninja' ) . '</td><td style="font-weight:600;text-align:right;border-top:1px solid #dcdcde">' . esc_html( number_format( (float) $order['total'], 2 ) ) . '</td></tr></table>';
		if ( 'awaiting_payment' === $order['status'] && '' !== (string) $order['pay_link'] ) {
			/* translators: %s: order number */
			$subject = sprintf( __( 'Order #%s · complete your payment', 'invoiceninja' ), $order['number'] );
			$body    = '<p>' . esc_html__( 'Thanks for your order. Pay now to complete it.', 'invoiceninja' ) . '</p>' . $table
				. '<p><a href="' . esc_url( (string) $order['pay_link'] ) . '" style="display:inline-block;background:#2271b1;color:#ffffff;padding:12px 20px;border-radius:4px;text-decoration:none;font-weight:600">' . esc_html__( 'Continue to secure payment', 'invoiceninja' ) . '</a></p>'
				. '<p style="color:#50575e;font-size:13px">' . esc_html__( "You'll pay on our secure payment page. Afterwards you can close it; your receipt comes by email.", 'invoiceninja' ) . ' <a href="' . $status . '">' . esc_html__( 'Order status', 'invoiceninja' ) . '</a></p>';
		} elseif ( 'attention' === $order['status'] || 'awaiting_payment' === $order['status'] ) {
			/* translators: %s: order number */
			$subject = sprintf( __( "We've received order #%s", 'invoiceninja' ), $order['number'] );
			$body    = '<p>' . esc_html__( "We've received your order and will email you about payment shortly. Nothing has been charged.", 'invoiceninja' ) . '</p>' . $table
				. '<p><a href="' . $status . '">' . esc_html__( 'Order status', 'invoiceninja' ) . '</a></p>';
		} else {
			/* translators: %s: order number */
			$subject = sprintf( __( "We've received order #%s", 'invoiceninja' ), $order['number'] );
			$body    = '<p>' . esc_html__( "We've received your order. Your payment link comes in a separate email, usually within a few minutes. Nothing has been charged yet.", 'invoiceninja' ) . '</p>' . $table
				. '<p><a href="' . $status . '">' . esc_html__( 'Order status', 'invoiceninja' ) . '</a></p>';
		}
		$this->plugin->mailer->send( (string) $order['email'], $subject, $body );
	}

	/**
	 * j***@example.com.
	 *
	 * @param string $email Email.
	 */
	public static function maskEmail( string $email ): string {
		$at = strpos( $email, '@' );
		return false === $at ? '' : substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}
}
