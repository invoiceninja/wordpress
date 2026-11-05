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
 * Abuse protection: a honeypot, a minimum time from the first add to checkout, rate
 * limits, the invoiceninja_checkout_captcha filter, and a cap on unpaid orders per email.
 */
final class Checkout {

	/**
	 * Unpaid orders one email may have open from the last day (filter: invoiceninja_max_open_orders).
	 */
	public const MAX_OPEN_ORDERS = 5;

	/**
	 * Seconds from the first add to checkout, at least (filter: invoiceninja_checkout_min_seconds).
	 */
	public const MIN_SECONDS = 3;

	/**
	 * Find my order emails per email address an hour.
	 */
	public const FIND_PER_EMAIL_HOUR = 3;

	/**
	 * Background job that emails a Find my order link.
	 */
	public const FIND_HOOK = 'invoiceninja_find_order';

	/**
	 * Countries whose tax Invoice Ninja works out from the postcode, so it can't be left out.
	 * In Invoice Ninja's tax rules only the United States (840) has subregions (the state),
	 * found from the client's state or else its ZIP code; checkout doesn't ask for a state,
	 * and with neither Invoice Ninja falls back to California's rate. Other regions (EU,
	 * UK, Australia, Singapore…) are taxed by country alone.
	 */
	public const POSTCODE_REQUIRED = array( '840' );

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
		if ( 'off' === $this->plugin->options->setting( 'store.mode' ) || ! \InvoiceNinja\Frontend\Store::enabled() ) {
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
	 * A logged-in customer ordering as their own, verified email needs no code.
	 *
	 * @param string $email Email.
	 */
	private function verifiedBuyer( string $email ): bool {
		$user_id = self::userId();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user && strtolower( (string) $user->user_email ) === $email && (bool) apply_filters( 'invoiceninja_is_verified', false, $user_id );
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
	 * Whether checkout asks for the country (and postcode): Invoice Ninja works out the tax
	 * from the client's address (the company's "calculate taxes").
	 */
	public function taxFields(): bool {
		return ! empty( $this->plugin->state->get()['company']['calculate_taxes'] ) && (bool) $this->plugin->statics->countries();
	}

	/**
	 * What the checkout form needs for the tax address. Sent with the cart and with a
	 * tax_address error, since a cached page may predate taxes being turned on or off.
	 *
	 * postcode_required lists the country ids that need a postcode.
	 *
	 * @return array{fields: bool, countries: list<array{0: string, 1: string}>, country: string, postcode_required: list<string>}
	 */
	public function taxConfig(): array {
		$fields    = $this->taxFields();
		$countries = array();
		foreach ( $fields ? $this->plugin->statics->countries() : array() as $id => $name ) {
			$countries[] = array( (string) $id, (string) $name );
		}
		return array(
			'fields'            => $fields,
			'countries'         => $countries,
			'country'           => (string) ( $this->plugin->state->get()['company']['country_id'] ?? '' ),
			'postcode_required' => $fields ? self::POSTCODE_REQUIRED : array(),
		);
	}

	/**
	 * Check the tax address when it's asked for.
	 *
	 * @param array<string, string> $details Buyer details.
	 * @return array<string, mixed>|null The error reply, or null when it's fine (or not needed).
	 */
	private function taxAddressError( array $details ): ?array {
		if ( ! $this->taxFields() ) {
			return null;
		}
		$errors = self::taxAddressErrors( (string) ( $details['country_id'] ?? '' ), (string) ( $details['postal_code'] ?? '' ), array_keys( $this->plugin->statics->countries() ) );
		if ( ! $errors ) {
			return null;
		}
		return array(
			'ok'          => false,
			'code'        => 'tax_address',
			'message'     => isset( $errors['country_id'] )
				? __( 'Choose your country. We need it to work out the tax.', 'invoiceninja' )
				: ( $errors['postal_code'] ?? __( 'Check your postcode.', 'invoiceninja' ) ),
			'fields'      => $errors,
			'tax_address' => $this->taxConfig(),
		);
	}

	/**
	 * Field errors for a tax address. The country is required. The postcode is required
	 * where the tax depends on it (a US ZIP code: 12345 or 12345-6789) and optional
	 * elsewhere (many countries have none), where only its format is checked when given.
	 *
	 * @param string            $country   Invoice Ninja country id.
	 * @param string            $postcode  Postcode.
	 * @param list<int|string>  $countries Known country ids.
	 * @return array<string, string> Errors by field.
	 */
	public static function taxAddressErrors( string $country, string $postcode, array $countries ): array {
		$errors   = array();
		$postcode = trim( $postcode );
		if ( '' === $country || ! in_array( $country, array_map( 'strval', $countries ), true ) ) {
			$errors['country_id'] = __( 'Choose your country.', 'invoiceninja' );
		}
		if ( in_array( $country, self::POSTCODE_REQUIRED, true ) ) {
			if ( '' === $postcode ) {
				$errors['postal_code'] = __( 'Enter your ZIP code. We need it to work out the tax.', 'invoiceninja' );
			} elseif ( '840' === $country && ! preg_match( '/^[0-9]{5}(-[0-9]{4})?$/', $postcode ) ) {
				$errors['postal_code'] = __( 'Check your ZIP code.', 'invoiceninja' );
			}
		} elseif ( '' !== $postcode && ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 -]{0,15}$/', $postcode ) ) {
			$errors['postal_code'] = __( 'Check your postcode.', 'invoiceninja' );
		}
		return $errors;
	}

	/**
	 * The spam trap: a field people never see or fill. It isn't called "website" or anything
	 * else autofill or a password manager would fill in for a real shopper.
	 *
	 * @param array<string, mixed> $form Form checks (trap: the field's value).
	 */
	public static function trapped( array $form ): bool {
		$trap = $form['trap'] ?? '';
		return ! is_scalar( $trap ) || '' !== trim( (string) $trap );
	}

	/**
	 * Whether the shopper passed a CAPTCHA at checkout. There is none by default.
	 *
	 * @param array<string, mixed> $input Everything sent.
	 */
	public static function captchaPassed( array $input ): bool {
		/**
		 * Whether the shopper passed a CAPTCHA at checkout, before the email code is sent.
		 * There is none by default; a CAPTCHA plugin returns false to refuse the request.
		 * The checkout sends no CAPTCHA token of its own, so hook this only with a CAPTCHA
		 * that adds one. (The Quote Request Form uses invoiceninja_captcha.)
		 *
		 * @since 2.0.1
		 * @param bool                 $passed Passed.
		 * @param array<string, mixed> $input  Submitted values.
		 */
		return (bool) apply_filters( 'invoiceninja_checkout_captcha', true, $input );
	}

	/**
	 * Whether the shopper reached checkout too quickly after the first add to be a person.
	 *
	 * @param string $cart_id Cart id.
	 * @return int Seconds still to wait (0 when it's fine).
	 */
	private function tooFast( string $cart_id ): int {
		/**
		 * Seconds a person needs at least from adding the first product to checking out.
		 *
		 * @since 2.0.0
		 * @param int $seconds Seconds.
		 */
		$min = max( 0, (int) apply_filters( 'invoiceninja_checkout_min_seconds', self::MIN_SECONDS ) );
		$age = $this->cart->age( $cart_id );
		return null === $age || $age >= $min ? 0 : $min - $age;
	}

	/**
	 * Whether this email may place another order: unpaid orders are capped, so a remembered
	 * device can't be used to create invoices (and hold stock) without end.
	 *
	 * @param string $email Email.
	 * @return array<string, mixed>|null The error reply, or null to continue.
	 */
	private function openOrdersError( string $email ): ?array {
		/**
		 * Unpaid orders one email may have from the last day before checkout refuses more.
		 * Only orders waiting for the buyer's payment count (being placed, or awaiting
		 * payment), not ones held up on the shop's side. 0 turns the cap off.
		 *
		 * @since 2.0.0
		 * @param int    $max   Maximum.
		 * @param string $email Email.
		 */
		$max = (int) apply_filters( 'invoiceninja_max_open_orders', self::MAX_OPEN_ORDERS, $email );
		if ( $max <= 0 || $this->orders->openFor( $email ) < $max ) {
			return null;
		}
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Warning, 'checkout', 'Checkout refused: too many unpaid orders for one email' );
		return array(
			'ok'      => false,
			'code'    => 'too_many_orders',
			'message' => __( 'You have several unpaid orders already. Please pay for them first, or contact us.', 'invoiceninja' ),
		);
	}

	/**
	 * The cart changed since the shopper saw it: the total they were shown isn't today's.
	 *
	 * @param array<string, mixed> $form    Form checks (total: what the page showed).
	 * @param array<string, mixed> $summary Cart summary now.
	 */
	private static function totalChanged( array $form, array $summary ): bool {
		return isset( $form['total'] ) && is_numeric( $form['total'] ) && abs( round( (float) $form['total'], 2 ) - round( (float) $summary['total'], 2 ) ) >= 0.005;
	}

	/**
	 * The "cart changed" reply.
	 *
	 * @param array<string, mixed> $summary Cart summary.
	 * @return array<string, mixed>
	 */
	private static function cartChanged( array $summary ): array {
		return array(
			'ok'      => false,
			'code'    => 'cart_changed',
			'message' => __( "Some items changed or sold out, so we've updated your cart. Please check it before continuing.", 'invoiceninja' ),
			'cart'    => $summary,
		);
	}

	/**
	 * Step 1: validate and send a code.
	 *
	 * @param array<string, string> $details email, first_name, last_name, phone, country_id, postal_code.
	 * @param string                $ip      Shopper IP.
	 * @param array<string, mixed>  $form    trap (spam trap), total (as shown), input (everything sent, for a CAPTCHA).
	 * @return array<string, mixed>
	 */
	public function start( array $details, string $ip, array $form = array() ): array {
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
		// Bots that fill every field get the reply people get, and no code is sent.
		if ( self::trapped( $form ) ) {
			$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', 'Checkout dropped: spam trap filled' );
			return array(
				'ok'          => true,
				'next'        => 'code',
				'retry_after' => EmailCode::RESEND_AFTER,
			);
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
		$wait = $this->tooFast( $cart_id );
		if ( $wait > 0 ) {
			return array(
				'ok'          => false,
				'code'        => 'too_fast',
				'retry_after' => $wait,
				'message'     => __( 'That was quick! Please check your details, then continue.', 'invoiceninja' ),
			);
		}
		if ( ! self::captchaPassed( (array) ( $form['input'] ?? array() ) ) ) {
			return array(
				'ok'      => false,
				'code'    => 'captcha',
				'message' => __( 'Please complete the check to show you are not a robot.', 'invoiceninja' ),
			);
		}
		$tax = $this->taxAddressError( $details );
		if ( null !== $tax ) {
			return $tax;
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
		if ( $summary['changed'] || self::totalChanged( $form, $summary ) ) {
			return self::cartChanged( $summary );
		}
		if ( $this->verifiedBuyer( $email ) || $this->device->current( $email ) ) {
			// Already proven to own the email, so saying it has unpaid orders reveals nothing.
			$open = $this->openOrdersError( $email );
			if ( null !== $open ) {
				return $open;
			}
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
	 * @param array<string, string> $details  email, first_name, last_name, phone, country_id, postal_code.
	 * @param string                $code     Code (empty when the device is remembered).
	 * @param bool                  $remember Remember this device.
	 * @param string                $ip       Shopper IP.
	 * @param array<string, mixed>  $form     trap (spam trap), total (as shown).
	 * @return array<string, mixed>
	 */
	public function confirm( array $details, string $code, bool $remember, string $ip = '', array $form = array() ): array {
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
		$wrong_code = array(
			'ok'      => false,
			'code'    => 'code_wrong',
			'message' => __( "That code isn't right. Check the email and try again.", 'invoiceninja' ),
		);
		if ( self::trapped( $form ) ) {
			return $wrong_code;
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
		// The cart first: a changed cart mustn't use up a correct code.
		$summary = null === $cart_id ? $this->cart->summary( array() ) : $this->cart->reconcile( $cart_id, $this->buyNow() );
		if ( ! $summary['lines'] || null === $cart_id ) {
			return array(
				'ok'      => false,
				'code'    => 'empty_cart',
				'message' => __( 'Your cart is empty.', 'invoiceninja' ),
			);
		}
		if ( $summary['changed'] || self::totalChanged( $form, $summary ) ) {
			return self::cartChanged( $summary );
		}
		$tax = $this->taxAddressError( $details );
		if ( null !== $tax ) {
			return $tax;
		}

		if ( ! $this->verifiedBuyer( $email ) && ! $this->device->current( $email ) ) {
			$result = $this->codes->verify( $email, $code, 'checkout', $ip );
			if ( 'ok' !== $result ) {
				$messages = array(
					'wrong'   => $wrong_code['message'],
					'expired' => __( 'That code has expired. Send a new one.', 'invoiceninja' ),
					'locked'  => __( 'Too many wrong codes. Send a new one.', 'invoiceninja' ),
				);
				return array(
					'ok'      => false,
					'code'    => 'code_' . $result,
					'message' => $messages[ $result ] ?? $messages['wrong'],
				);
			}
			/**
			 * Fires when an email code was typed correctly.
			 *
			 * @since 2.0.0
			 * @param string $email   Email.
			 * @param string $purpose checkout.
			 */
			do_action( 'invoiceninja_code_verified', $email, 'checkout' );
			if ( $remember ) {
				$this->device->remember( $email );
			}
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
			$open = $this->openOrdersError( $email );
			if ( null !== $open ) {
				return $open;
			}
			$customer = array(
				'first_name' => sanitize_text_field( $details['first_name'] ?? '' ),
				'last_name'  => sanitize_text_field( $details['last_name'] ?? '' ),
				'phone'      => sanitize_text_field( $details['phone'] ?? '' ),
				'locale'     => (string) get_user_locale(),
			);
			if ( $this->taxFields() ) {
				$customer['country_id']  = sanitize_text_field( $details['country_id'] ?? '' );
				$customer['postal_code'] = strtoupper( trim( sanitize_text_field( $details['postal_code'] ?? '' ) ) );
			}
			try {
				$order = $this->orders->create(
					array(
						'number'   => $this->numbers->next(),
						'token'    => $token,
						'email'    => $email,
						'user_id'  => self::userId(),
						'customer' => $customer,
						'items'    => $summary['lines'],
						'total'    => $summary['total'],
						'currency' => $this->plugin->statics->currencyCode(),
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
			// "Received"; the payment link follows once it's placed. A client in another
			// currency gets no promise of a payment link: an admin is in touch instead.
			if ( in_array( $order['status'], array( 'queued', 'attention' ), true ) && 'currency' !== $order['note'] ) {
				$this->emailBuyer( $order );
			}
		}
		// A new cart id, so buying the same items again later is a new order.
		$this->cart->clear( $cart_id, (int) $order['id'] );
		$this->rememberOrder( $order );
		return array( 'ok' => true ) + $this->publicView( $order, true );
	}

	/**
	 * "Not you?": forget this device's remembered email, so the next checkout asks for a code.
	 */
	public function forgetDevice(): void {
		$this->device->forget();
	}

	/**
	 * The order as the buyer may see it.
	 *
	 * @param array<string, mixed> $order    Order.
	 * @param bool                 $with_key Include the status link.
	 * @return array<string, mixed>
	 */
	public function publicView( array $order, bool $with_key ): array {
		$items = (array) $order['items'];
		$view  = array(
			'number'         => (string) $order['number'],
			'status'         => (string) $order['status'],
			'total'          => (float) $order['total'],
			'subtotal'       => round( array_sum( array_map( static fn( $i ): float => (float) ( is_array( $i ) ? ( $i['line_total'] ?? ( (float) ( $i['price'] ?? 0 ) * (int) ( $i['quantity'] ?? 0 ) ) ) : 0 ), $items ) ), 2 ),
			'inclusive'      => OrderService::inclusive( $order ),
			'invoice_number' => (string) $order['invoice_number'],
			'items'          => array_map(
				static fn( array $i ): array => array(
					'product'  => (string) ( $i['product'] ?? '' ),
					'title'    => (string) $i['title'],
					'quantity' => (int) $i['quantity'],
					'price'    => (float) $i['price'],
				),
				$items
			),
			'email'          => self::maskEmail( (string) $order['email'] ),
			'pay_link'       => 'awaiting_payment' === $order['status'] ? (string) $order['pay_link'] : '',
			// The buyer's client in Invoice Ninja uses another currency: we can't take it online.
			'problem'        => 'attention' === $order['status'] && 'currency' === (string) $order['note'] ? 'currency' : '',
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
		$page = $this->plugin->store->pageUrl( 'status' );
		$base = '' !== $page ? add_query_arg( 'order', rawurlencode( (string) $order['number'] ), $page ) : add_query_arg( 'invoiceninja_order', rawurlencode( (string) $order['number'] ), home_url( '/' ) );
		return $base . '#key=' . $this->key( (string) $order['number'] );
	}

	/**
	 * Remember this browser's latest order for the banner and "back from paying" (30 days).
	 * The key is in an HttpOnly cookie; a readable flag tells the script there's one.
	 *
	 * @param array<string, mixed> $order Order.
	 */
	private function rememberOrder( array $order ): void {
		if ( headers_sent() ) {
			return;
		}
		$args = array(
			'expires'  => time() + 30 * DAY_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'samesite' => 'Lax',
		);
		setcookie( 'invoiceninja_order', $order['number'] . '.' . $this->key( (string) $order['number'] ), $args + array( 'httponly' => true ) );
		setcookie( 'invoiceninja_order_flag', '1', $args + array( 'httponly' => false ) );
	}

	/**
	 * This browser's latest order, if its cookie is valid.
	 *
	 * @return array<string, mixed>|null
	 */
	public function lastOrder(): ?array {
		$cookie = isset( $_COOKIE['invoiceninja_order'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['invoiceninja_order'] ) ) : '';
		$parts  = explode( '.', $cookie );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[A-Za-z0-9-]{1,32}$/', $parts[0] ) ) {
			return null;
		}
		$order = $this->orders->findBy( 'number', $parts[0] );
		if ( null === $order || ! hash_equals( $this->key( (string) $order['number'] ), $parts[1] ) ) {
			return null;
		}
		return $this->service->refreshStatus( $order );
	}

	/**
	 * "Change order" after coming back from the payment page: cancel the unpaid order and
	 * put its items back in the cart.
	 *
	 * @return array<string, mixed>
	 */
	public function changeLastOrder(): array {
		// A paused (staging) copy must not cancel invoices in Invoice Ninja.
		$reason = $this->unavailable();
		if ( null !== $reason ) {
			return array(
				'ok'      => false,
				'code'    => 'unavailable',
				'message' => $reason,
			);
		}
		$order = $this->lastOrder();
		if ( null === $order || 'awaiting_payment' !== $order['status'] ) {
			return array(
				'ok'      => false,
				'code'    => 'not_changeable',
				'message' => __( 'That order can no longer be changed. If you have paid, your receipt is on its way.', 'invoiceninja' ),
			);
		}
		try {
			$this->service->cancel( $order );
		} catch ( \InvoiceNinja\Commerce\OrderConflict | \InvoiceNinja\Api\ApiException $e ) {
			return array(
				'ok'      => false,
				'code'    => 'not_changeable',
				'message' => __( "We couldn't change that order right now. Please contact us.", 'invoiceninja' ),
			);
		}
		$id = $this->cart->ensureId();
		foreach ( (array) $order['items'] as $item ) {
			$this->cart->set( $id, (string) $item['product'], (int) $item['quantity'] );
		}
		$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Info, 'checkout', sprintf( 'Order %s cancelled by the buyer (Change order)', $order['number'] ), array(), 'order', (string) $order['number'] );
		return array( 'ok' => true );
	}

	/**
	 * Find my order: queue an email of the status link to the order's address. The request
	 * does the same work whether or not the number and email match (the match is checked
	 * in the background job), so neither the reply nor its timing tells them apart.
	 * Requests are limited per address.
	 *
	 * @param string $number Order number.
	 * @param string $email  Email.
	 * @param string $ip     Requester IP.
	 */
	public function findOrder( string $number, string $email, string $ip ): void {
		$hour   = (int) floor( time() / HOUR_IN_SECONDS );
		$number = strtoupper( trim( $number ) );
		if ( $this->plugin->options->increment( 'ctr_find_' . substr( md5( self::ipBucket( $ip ) ), 0, 16 ) . '_' . $hour ) > 10 ) {
			return;
		}
		if ( ! preg_match( '/^[A-Z0-9-]{1,32}$/', $number ) ) {
			return;
		}
		// Only a keyed hash of the email goes into the job (job arguments are kept in plain text).
		$this->plugin->queue->push(
			self::FIND_HOOK,
			array(
				'number' => $number,
				'email'  => $this->findHash( EmailCode::normalize( $email ) ),
			),
			0,
			true
		);
	}

	/**
	 * Background job: email the status link when the number and email match. At most a
	 * few per email an hour; only matches count, so nobody can use up someone else's.
	 *
	 * @param array<string, mixed> $payload {number, email (keyed hash)}.
	 */
	public function sendFindLink( array $payload ): void {
		$order = $this->orders->findBy( 'number', (string) ( $payload['number'] ?? '' ) );
		if ( null === $order || ! hash_equals( $this->findHash( (string) $order['email'] ), (string) ( $payload['email'] ?? '' ) ) ) {
			return;
		}
		if ( $this->plugin->options->increment( 'ctr_findmail_' . substr( md5( (string) $order['email'] ), 0, 16 ) . '_' . (int) floor( time() / HOUR_IN_SECONDS ) ) > self::FIND_PER_EMAIL_HOUR ) {
			return;
		}
		$this->plugin->mailer->send(
			(string) $order['email'],
			/* translators: %s: order number */
			sprintf( __( 'Your link for order #%s', 'invoiceninja' ), $order['number'] ),
			'<p>' . esc_html__( 'Here is the link to your order. It shows its status, and lets you pay while it is unpaid.', 'invoiceninja' ) . '</p><p><a href="' . esc_url( $this->statusUrl( $order ) ) . '">' . esc_html__( 'View your order', 'invoiceninja' ) . '</a></p><p style="color:#50575e;font-size:13px">' . esc_html__( "Didn't ask for this? You can ignore this email.", 'invoiceninja' ) . '</p>'
		);
	}

	/**
	 * Keyed hash of an email for Find my order jobs.
	 *
	 * @param string $email Email.
	 */
	private function findHash( string $email ): string {
		return substr( hash_hmac( 'sha256', 'find|' . strtolower( trim( $email ) ), $this->plugin->secrets->get( 'secret' ) ), 0, 40 );
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
		$money  = $this->plugin->statics;
		$lines  = '';
		foreach ( (array) $order['items'] as $item ) {
			$lines .= '<tr><td style="padding:4px 0">' . esc_html( wp_specialchars_decode( (string) $item['title'], ENT_QUOTES ) ) . ' × ' . (int) $item['quantity'] . '</td><td style="padding:4px 0;text-align:right">' . esc_html( $money->money( (float) $item['line_total'] ) ) . '</td></tr>';
		}
		$table = '<table style="width:100%;border-collapse:collapse;border:1px solid #dcdcde;margin:12px 0" cellpadding="8">' . $lines . '<tr><td style="font-weight:600;border-top:1px solid #dcdcde">' . esc_html__( 'Total', 'invoiceninja' ) . '</td><td style="font-weight:600;text-align:right;border-top:1px solid #dcdcde">' . esc_html( $money->money( (float) $order['total'] ) ) . '</td></tr></table>';
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
