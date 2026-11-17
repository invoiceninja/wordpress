<?php
/**
 * The Account block, its data, the Pay Invoice block and the verified redirects.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\Money;
use InvoiceNinja\Commerce\Checkout;
use InvoiceNinja\Commerce\OrderService;
use InvoiceNinja\Commerce\Orders;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * The Account page shows a customer's invoices, quotes, payments, credits and
 * subscriptions from Invoice Ninja, loaded after the page renders (so it stays cacheable
 * and fast). Links that open Invoice Ninja (portal, pay, view, PDF) go through
 * ?invoiceninja=… on this site, which checks the visitor is logged in, verified and the
 * owner, and only then redirects. Portal links are never printed into HTML.
 */
final class Account {

	/** Pages of 100 unpaid invoices read for the Balance due panel, at most. */
	private const MAX_UNPAID_PAGES = 5;

	/** Seconds the Balance due panel may spend reading them. */
	private const UNPAID_BUDGET = 8.0;

	public const TABS = array( 'invoices', 'quotes', 'payments', 'credits', 'subscriptions' );

	/** Transient: the page with the Quote Request Form, for the empty Quotes tab. */
	private const QUOTE_PAGE = 'invoiceninja_quote_page';

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin       Plugin.
	 * @param Verification $verification Verification.
	 * @param Links        $links        Links.
	 */
	public function __construct( private Plugin $plugin, private Verification $verification, private Links $links ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		if ( $this->plugin->options->setting( 'modules.account' ) ) {
			$this->plugin->store->add( 'account', 'invoiceninja_account', array( $this, 'render' ) );
			$this->plugin->store->add( 'pay-invoice', 'invoiceninja_pay_invoice', array( $this, 'renderPayInvoice' ) );
		}
		add_shortcode( 'invoiceninja_client_portal_url', static fn(): string => esc_url( home_url( '/?invoiceninja=portal' ) ) );
		add_action( 'template_redirect', array( $this, 'redirect' ), 5 );
		add_action( 'invoiceninja_rest_routes', array( $this, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'registerScript' ) );
		add_action( 'save_post_page', array( self::class, 'forgetQuotePage' ) );
	}

	/**
	 * Register the portal script and style.
	 */
	public function registerScript(): void {
		wp_register_style( 'invoiceninja-portal', $this->plugin->url() . 'assets/portal/portal.css', array( \InvoiceNinja\Frontend\Store::HANDLE ), INVOICENINJA_VERSION );
		wp_register_script(
			'invoiceninja-portal',
			$this->plugin->url() . 'assets/portal/portal.js',
			array( \InvoiceNinja\Frontend\Store::HANDLE ),
			INVOICENINJA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * REST routes.
	 *
	 * @param Routes $routes Routes.
	 */
	public function routes( Routes $routes ): void {
		$routes->add( '/account', 'GET', array( $this, 'summary' ), 'logged_in' );
		$routes->add( '/account/(?P<tab>invoices|quotes|payments|credits|subscriptions)', 'GET', array( $this, 'tab' ), 'logged_in' );
		$routes->add( '/account/verify/start', 'POST', array( $this, 'verifyStart' ), 'logged_in' );
		$routes->add( '/account/verify/confirm', 'POST', array( $this, 'verifyConfirm' ), 'logged_in' );
		$routes->add( '/pay-invoice', 'POST', array( $this, 'payInvoice' ), 'same_site' );
	}

	// ---------- Rendering ----------

	/**
	 * Account block, or its Portal Link variation.
	 *
	 * @param array<string, mixed> $a mode (account or link), label, style (link or button), class, tabs, balance.
	 */
	public function render( array $a ): string {
		$mode = (string) ( $a['mode'] ?? 'account' );
		if ( 'link' === $mode ) {
			$label = sanitize_text_field( (string) ( $a['label'] ?? '' ) );
			$class = 'button' === ( $a['style'] ?? 'link' ) ? 'wp-element-button invoiceninja-portal-link' : 'invoiceninja-portal-link';
			$class = trim( $class . ' ' . sanitize_html_class( (string) ( $a['class'] ?? '' ) ) );
			return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( home_url( '/?invoiceninja=portal' ) ) . '">' . esc_html( '' !== $label ? $label : __( 'Billing portal', 'invoiceninja' ) ) . '</a>';
		}
		if ( 'none' === $this->plugin->credentials->source() ) {
			return current_user_can( \InvoiceNinja\Core\Capabilities::MANAGE ) ? '<p class="invoiceninja-notice invoiceninja-notice--admin">' . esc_html__( 'Connect Invoice Ninja to show customer accounts here.', 'invoiceninja' ) . '</p>' : '';
		}
		$this->plugin->store->enqueue();
		wp_enqueue_script( 'invoiceninja-portal' );
		wp_enqueue_style( 'invoiceninja-portal' );
		// A block passes a list; a shortcode a comma-separated string (tabs="invoices,payments").
		$wanted = $a['tabs'] ?? self::TABS;
		$wanted = is_string( $wanted ) ? explode( ',', $wanted ) : (array) $wanted;
		$tabs   = array_values( array_intersect( self::TABS, array_map( static fn( $tab ): string => trim( (string) $tab ), $wanted ) ) );
		// The Balance due panel is on unless turned off (balance: false, or balance="0" in a shortcode).
		$balance = ! in_array( $a['balance'] ?? true, array( false, 0, '0', 'no', 'off', '' ), true );
		// Where to go once the email is confirmed (e.g. back to a product), on this site only.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link; nothing changes.
		$back = ConfirmEmail::back( (string) ( $a['return'] ?? ( isset( $_GET['invoiceninja_return'] ) && is_string( $_GET['invoiceninja_return'] ) ? esc_url_raw( wp_unslash( $_GET['invoiceninja_return'] ) ) : '' ) ) );
		return '<div class="invoiceninja-account" data-invoiceninja-account data-tabs="' . esc_attr( implode( ',', $tabs ? $tabs : self::TABS ) ) . '" data-balance="' . ( $balance ? '1' : '0' ) . '"' . ( '' !== $back ? ' data-return="' . esc_url( $back ) . '"' : '' ) . ' data-invoiceninja-store>'
			. '<noscript><p>' . esc_html__( 'Your account needs JavaScript.', 'invoiceninja' ) . '</p></noscript>'
			. '<p class="invoiceninja-loading">' . esc_html__( 'Loading your account…', 'invoiceninja' ) . '</p></div>';
	}

	/**
	 * Pay Invoice: invoice number and email; the payment link is emailed (never shown).
	 *
	 * @param array<string, mixed> $a Unused.
	 */
	public function renderPayInvoice( array $a ): string {
		unset( $a );
		$this->plugin->store->enqueue();
		wp_enqueue_script( 'invoiceninja-portal' );
		// The signed time and the spam trap are moved into the form by portal.js.
		return '<div class="invoiceninja-payinvoice" data-invoiceninja-payinvoice data-invoiceninja-store data-form="' . esc_attr( self::formToken( time(), $this->plugin->secrets->get( 'secret' ) ) ) . '">'
			. '<p class="invoiceninja-sr" aria-hidden="true"><label for="invoiceninja-pay-trap">' . esc_html__( 'Leave this empty', 'invoiceninja' ) . '</label><input type="text" id="invoiceninja-pay-trap" name="' . esc_attr( Routes::TRAP ) . '" tabindex="-1" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore></p>'
			. '</div>';
	}

	/**
	 * A signed record of when the Pay Invoice form was rendered.
	 *
	 * @param int    $time   Unix time.
	 * @param string $secret Signing secret.
	 */
	public static function formToken( int $time, #[\SensitiveParameter] string $secret ): string {
		return $time . '.' . hash_hmac( 'sha256', 'pay_invoice|' . $time, $secret );
	}

	/**
	 * When a Pay Invoice form token was made, or null when it's forged or damaged.
	 *
	 * @param string $token  Token.
	 * @param string $secret Signing secret.
	 */
	public static function formTime( string $token, #[\SensitiveParameter] string $secret ): ?int {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || strlen( $parts[0] ) > 12 ) {
			return null;
		}
		return hash_equals( hash_hmac( 'sha256', 'pay_invoice|' . $parts[0], $secret ), $parts[1] ) ? (int) $parts[0] : null;
	}

	// ---------- REST ----------

	/**
	 * GET /account — who the visitor is to the portal.
	 */
	public function summary(): \WP_REST_Response {
		nocache_headers();
		$user_id  = Checkout::userId();
		$user     = get_userdata( $user_id );
		$verified = $this->verification->isVerified( $user_id );
		$link     = null;
		$down     = false;
		if ( $verified ) {
			try {
				$link = $this->links->discover( $user_id );
			} catch ( ApiException $e ) {
				$down = true;
				$link = $this->links->get( $user_id );
			}
		}
		$company = (array) ( $this->plugin->state->get()['company'] ?? array() );
		return new \WP_REST_Response(
			array(
				'email'          => $user ? (string) $user->user_email : '',
				'name'           => $user ? (string) $user->display_name : '',
				'verified'       => $verified,
				'linked'         => null !== $link,
				'unavailable'    => $down,
				'portalPassword' => ! empty( $company['portal_password'] ),
				'portalUrl'      => home_url( '/?invoiceninja=portal' ),
				// Has a nonce, so it comes with this uncached reply, never in the page.
				'logoutUrl'      => $user ? wp_logout_url( home_url( '/' ) ) : '',
			)
		);
	}

	/**
	 * GET /account/{tab}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function tab( \WP_REST_Request $request ): \WP_REST_Response {
		nocache_headers();
		$user_id = Checkout::userId();
		if ( ! $this->verification->isVerified( $user_id ) ) {
			return Routes::fail( 'not_verified', __( 'Confirm your email to see your account.', 'invoiceninja' ), 403 );
		}
		$tab   = (string) $request->get_param( 'tab' );
		$link  = $this->links->get( $user_id );
		$cache = 'invoiceninja_acct_' . $user_id . '_' . $tab;
		$saved = null === $link || 'subscriptions' === $tab ? false : get_transient( $cache );
		// Cached replies are only kept for a minute after the link was confirmed for this email.
		if ( is_array( $saved ) && ( $saved['client_id'] ?? null ) === $link['client_id'] && is_array( $saved['reply'] ?? null ) ) {
			return new \WP_REST_Response( $saved['reply'] );
		}
		try {
			$link = $this->links->confirmed( $user_id );
			if ( null === $link ) {
				return new \WP_REST_Response( array( 'rows' => array() ) );
			}
			if ( 'subscriptions' === $tab ) {
				/** This filter is documented in the module brief: rows from the subscriptions module. */
				$rows = (array) apply_filters( 'invoiceninja_account_subscriptions', array(), $link['client_id'], $user_id );
				return new \WP_REST_Response( array( 'rows' => array_values( $rows ) ) );
			}
			$reply = $this->reply( $tab, $link['client_id'], $link['client'] );
		} catch ( ApiException $e ) {
			return Routes::fail( 'unavailable', __( 'Your invoices are temporarily unavailable. Please try again later.', 'invoiceninja' ), 503 );
		}
		set_transient(
			$cache,
			array(
				'client_id' => $link['client_id'],
				'reply'     => $reply,
			),
			MINUTE_IN_SECONDS
		);
		return new \WP_REST_Response( $reply );
	}

	/**
	 * A tab's reply: its rows, plus the Balance due summary (invoices) or the quote request
	 * page (quotes).
	 *
	 * @param string               $tab       invoices, quotes, payments or credits.
	 * @param string               $client_id Client id.
	 * @param array<string, mixed> $client    Client record (currency, credit balance).
	 * @return array<string, mixed>
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private function reply( string $tab, string $client_id, array $client ): array {
		$api      = $this->plugin->api();
		$response = $api->get(
			$tab,
			array(
				'client_id'  => $client_id,
				'per_page'   => 50,
				'sort'       => 'date|desc',
				'is_deleted' => 'false',
			),
			5.0
		);
		$items    = array();
		foreach ( $response->data() as $item ) {
			if ( is_array( $item ) && empty( $item['is_deleted'] ) && (string) ( $item['client_id'] ?? $client_id ) === $client_id ) {
				$items[] = $item;
			}
		}
		$money = $this->moneyFor( $client );
		$today = gmdate( 'Y-m-d' );
		$reply = array( 'rows' => $this->rows( $tab, $items, $money, $today ) );
		if ( 'invoices' === $tab ) {
			$unpaid = $this->unpaid( $api, $client_id, $items, $response->totalPages() );
			if ( null !== $unpaid ) {
				$reply['balance'] = $this->balance( self::summarize( $unpaid, $client_id, $today ), $client, $money );
			}
		} elseif ( 'quotes' === $tab ) {
			$reply['requestUrl'] = $this->quotePage();
		}
		return $reply;
	}

	/**
	 * Rows for a tab.
	 *
	 * @param string                     $tab   invoices, quotes, payments or credits.
	 * @param list<array<string, mixed>> $items Invoices, quotes, payments or credits.
	 * @param callable(float): string    $money Formats an amount in the client's currency.
	 * @param string                     $today Today (Y-m-d).
	 * @return list<array<string, mixed>>
	 */
	private function rows( string $tab, array $items, callable $money, string $today ): array {
		$rows   = array();
		$orders = 'invoices' === $tab ? $this->orderNumbers( array_map( static fn( array $item ): string => (string) ( $item['id'] ?? '' ), $items ) ) : array();
		foreach ( $items as $item ) {
			$status = (string) ( $item['status_id'] ?? '' );
			if ( in_array( $tab, array( 'invoices', 'quotes', 'credits' ), true ) && '1' === $status ) {
				continue; // Drafts aren't the customer's business yet.
			}
			$id      = (string) ( $item['id'] ?? '' );
			$balance = (float) ( $item['balance'] ?? 0 );
			// A quote's due date is its "valid until".
			$due              = (string) ( ( $item['due_date'] ?? '' ) ?: ( $item['valid_until'] ?? '' ) );
			[ $label, $tone ] = self::status( $tab, $status, $balance, $due, $today );
			$row              = array(
				'id'      => $id,
				'number'  => (string) ( $item['number'] ?? '' ),
				'date'    => self::day( (string) ( $item['date'] ?? '' ) ),
				'due'     => self::day( $due ),
				'amount'  => $money( (float) ( $item['amount'] ?? 0 ) ),
				'balance' => $money( $balance ),
				'status'  => $label,
				'tone'    => $tone,
				'links'   => array(),
			);
			if ( 'invoices' === $tab ) {
				$row['links']['view'] = $this->link( 'view', 'invoice', $id );
				$row['links']['pdf']  = $this->link( 'pdf', 'invoice', $id );
				if ( self::payable( $item ) ) {
					$row['links']['pay'] = $this->link( 'pay', 'invoice', $id );
				}
				if ( isset( $orders[ $id ] ) ) {
					$row['order']          = $orders[ $id ];
					$row['links']['order'] = $this->plugin->checkout->statusUrl( array( 'number' => $orders[ $id ] ) );
				}
			} elseif ( 'quotes' === $tab ) {
				$row['links']['pdf']  = $this->link( 'pdf', 'quote', $id );
				$row['links']['view'] = $this->link( 'view', 'quote', $id );
			} elseif ( 'credits' === $tab ) {
				$row['links']['view'] = $this->link( 'view', 'credit', $id );
				$row['links']['pdf']  = $this->link( 'pdf', 'credit', $id );
			} elseif ( 'payments' === $tab ) {
				$row['number'] = (string) ( ( $item['number'] ?? '' ) ?: ( $item['transaction_reference'] ?? '' ) );
				$row['method'] = (string) ( $item['type_id'] ?? '' );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Every unpaid invoice, for the Balance due panel. One page of invoices covers them when
	 * there is only one; else the unpaid ones are asked for, up to MAX_UNPAID_PAGES pages
	 * within UNPAID_BUDGET seconds. Null when they can't all be read: no panel then, rather
	 * than a wrong total or a failed Invoices tab.
	 *
	 * @param \InvoiceNinja\Api\HttpClient $api       API client.
	 * @param string                       $client_id Client id.
	 * @param list<array<string, mixed>>   $first     The first page of invoices.
	 * @param int                          $pages     How many pages of invoices there are.
	 * @return list<array<string, mixed>>|null
	 */
	private function unpaid( \InvoiceNinja\Api\HttpClient $api, string $client_id, array $first, int $pages ): ?array {
		if ( $pages <= 1 ) {
			return $first;
		}
		$deadline = microtime( true ) + self::UNPAID_BUDGET;
		$unpaid   = array();
		$page     = 1;
		try {
			do {
				$left = $deadline - microtime( true );
				if ( $left < 1.0 ) {
					return null;
				}
				$part   = $api->get(
					'invoices',
					array(
						'client_id'     => $client_id,
						'client_status' => 'unpaid',
						'per_page'      => 100,
						'page'          => $page,
						'is_deleted'    => 'false',
					),
					min( 5.0, $left )
				);
				$unpaid = array_merge( $unpaid, $part->data() );
				++$page;
				if ( $page > $part->totalPages() ) {
					return $unpaid;
				}
			} while ( $page <= self::MAX_UNPAID_PAGES );
		} catch ( \InvoiceNinja\Api\ApiException $e ) {
			return null;
		}
		return null; // More unpaid invoices than the panel reads.
	}

	/**
	 * What a client owes: the unpaid (sent or partly paid) invoices with a balance.
	 * Invoice Ninja bills a client in one currency, so one total is the whole story.
	 *
	 * @param array<mixed> $invoices  Invoices from Invoice Ninja.
	 * @param string       $client_id The client's id.
	 * @param string       $today     Today (Y-m-d).
	 * @return array{total: float, count: int, overdue: int, next_due: string, single: array<string, mixed>|null}
	 */
	public static function summarize( array $invoices, string $client_id, string $today ): array {
		$total   = 0.0;
		$count   = 0;
		$overdue = 0;
		$next    = '';
		$last    = null;
		$seen    = array();
		foreach ( $invoices as $invoice ) {
			if ( ! is_array( $invoice ) || ! self::opensFor( $invoice, $client_id ) || ! self::payable( $invoice ) || isset( $seen[ (string) ( $invoice['id'] ?? '' ) ] ) ) {
				continue;
			}
			$seen[ (string) ( $invoice['id'] ?? '' ) ] = true;
			$total                                    += (float) $invoice['balance'];
			++$count;
			$due = (string) ( $invoice['due_date'] ?? '' );
			if ( '' !== $due && $due < $today ) {
				++$overdue;
			}
			if ( '' !== $due && ( '' === $next || $due < $next ) ) {
				$next = $due;
			}
			$last = $invoice;
		}
		return array(
			'total'    => round( $total, 6 ),
			'count'    => $count,
			'overdue'  => $overdue,
			'next_due' => $next,
			'single'   => 1 === $count ? $last : null,
		);
	}

	/**
	 * Whether an invoice can be paid now: sent or partly paid, with a balance.
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 */
	public static function payable( array $invoice ): bool {
		return (float) ( $invoice['balance'] ?? 0 ) > 0 && in_array( (string) ( $invoice['status_id'] ?? '' ), array( '2', '3' ), true );
	}

	/**
	 * The Balance due panel, formatted.
	 *
	 * @param array{total: float, count: int, overdue: int, next_due: string, single: array<string, mixed>|null} $sum    Summary.
	 * @param array<string, mixed>                                                                                $client Client record.
	 * @param callable(float): string                                                                             $money  Formats an amount.
	 * @return array<string, mixed>
	 */
	private function balance( array $sum, array $client, callable $money ): array {
		$credit = (float) ( $client['credit_balance'] ?? 0 );
		$detail = array();
		if ( $sum['count'] > 0 ) {
			/* translators: %d: number of unpaid invoices */
			$detail[] = sprintf( _n( '%d unpaid invoice', '%d unpaid invoices', $sum['count'], 'invoiceninja' ), $sum['count'] );
			if ( $sum['overdue'] > 0 ) {
				/* translators: %d: number of overdue invoices */
				$detail[] = sprintf( _n( '%d overdue', '%d overdue', $sum['overdue'], 'invoiceninja' ), $sum['overdue'] );
			} elseif ( '' !== $sum['next_due'] ) {
				/* translators: %s: date */
				$detail[] = sprintf( __( 'Due %s', 'invoiceninja' ), self::day( $sum['next_due'] ) );
			}
		}
		$out = array(
			'count'     => $sum['count'],
			'total'     => $money( $sum['total'] ),
			'overdue'   => $sum['overdue'],
			'detail'    => implode( ' · ', $detail ),
			/* translators: %s: amount */
			'credit'    => $credit > 0 ? sprintf( __( 'Available credit: %s', 'invoiceninja' ), $money( $credit ) ) : '',
			'pay'       => '',
			'payAmount' => '',
			'number'    => '',
		);
		if ( null !== $sum['single'] ) {
			$out['pay']    = $this->link( 'pay', 'invoice', (string) ( $sum['single']['id'] ?? '' ) );
			$out['number'] = (string) ( $sum['single']['number'] ?? '' );
			// With a deposit due, the payment page asks for that first: no amount on the button then.
			$out['payAmount'] = (float) ( $sum['single']['partial'] ?? 0 ) > 0 ? '' : $out['total'];
		} elseif ( $sum['count'] > 1 ) {
			// Invoice Ninja pays several invoices at once only from its own invoice list.
			$out['pay'] = add_query_arg(
				array(
					'invoiceninja' => 'portal',
					'to'           => 'invoices',
				),
				home_url( '/' )
			);
		}
		return $out;
	}

	/**
	 * Formats amounts in the client's currency: the company's, or the client's own (its
	 * format is looked up once a day).
	 *
	 * @param array<string, mixed> $client Client record.
	 * @return callable(float): string
	 */
	private function moneyFor( array $client ): callable {
		$company = (string) ( $this->plugin->state->get()['company']['currency_id'] ?? '' );
		if ( ! OrderService::currencyDiffers( $client, $company ) ) {
			return fn( float $amount ): string => $this->plugin->statics->money( $amount );
		}
		$id       = (string) $client['settings']['currency_id'];
		$key      = 'invoiceninja_currency_' . substr( md5( $this->plugin->credentials->url() . '|' . $id ), 0, 12 );
		$currency = get_transient( $key );
		if ( ! is_array( $currency ) ) {
			$currency = array();
			try {
				foreach ( (array) ( $this->plugin->api()->get( 'statics', array(), 10.0 )->json['currencies'] ?? array() ) as $row ) {
					if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $id ) {
						$currency = array_intersect_key( $row, array_flip( array( 'code', 'symbol', 'precision', 'thousand_separator', 'decimal_separator', 'swap_currency_symbol' ) ) );
					}
				}
			} catch ( ApiException $e ) {
				unset( $e ); // Plain numbers this time; asked again in an hour.
			}
			set_transient( $key, $currency, $currency ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		}
		return static fn( float $amount ): string => $currency ? Money::format( $amount, $currency ) : number_format_i18n( $amount, 2 );
	}

	/**
	 * Store order numbers for invoices made by this plugin's checkout.
	 *
	 * @param list<string> $invoice_ids Invoice ids.
	 * @return array<string, string> Invoice id => order number.
	 */
	private function orderNumbers( array $invoice_ids ): array {
		$invoice_ids = array_values( array_unique( array_filter( $invoice_ids ) ) );
		if ( ! $invoice_ids ) {
			return array();
		}
		global $wpdb;
		$table = Orders::table();
		$list  = implode( ',', array_fill( 0, count( $invoice_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- our own table; one placeholder per id.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT invoice_id, number FROM {$table} WHERE invoice_id IN ({$list})", $invoice_ids ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['invoice_id'] ] = (string) $row['number'];
		}
		return $out;
	}

	/**
	 * A page was saved: look for the Quote Request Form page again next time.
	 */
	public static function forgetQuotePage(): void {
		delete_transient( self::QUOTE_PAGE );
	}

	/**
	 * The page with the Quote Request Form, or an empty string.
	 */
	private function quotePage(): string {
		$url = get_transient( self::QUOTE_PAGE );
		if ( ! is_string( $url ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed lookup, cached for an hour.
			$id  = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_password = '' AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY menu_order, ID LIMIT 1",
					'%' . $wpdb->esc_like( '<!-- wp:invoiceninja/quote-form' ) . '%',
					'%' . $wpdb->esc_like( '[invoiceninja_quote_form' ) . '%'
				)
			);
			$url = $id > 0 ? (string) get_permalink( $id ) : '';
			set_transient( self::QUOTE_PAGE, $url, HOUR_IN_SECONDS );
		}
		/**
		 * The quote request page the Account block's empty Quotes tab links to ('' for none).
		 *
		 * @since 2.1.0
		 * @param string $url Found page with the Quote Request Form, or ''.
		 */
		return (string) apply_filters( 'invoiceninja_quote_page_url', $url );
	}

	/**
	 * A short date in the site's language (Nov 12, 2026), or an empty string.
	 *
	 * @param string $ymd Date (Y-m-d).
	 */
	private static function day( string $ymd ): string {
		$time = preg_match( '/^\d{4}-\d{2}-\d{2}/', $ymd ) ? strtotime( substr( $ymd, 0, 10 ) . ' 00:00:00 UTC' ) : false;
		/* translators: a short date format, see https://www.php.net/manual/datetime.format.php */
		return false === $time ? '' : (string) wp_date( _x( 'M j, Y', 'short date', 'invoiceninja' ), $time, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * A plain status for a customer, and its tone: alert (overdue), open (waiting for
	 * something), done, or off (cancelled, expired, used up).
	 *
	 * @param string $tab     Tab.
	 * @param string $status  Invoice Ninja status id.
	 * @param float  $balance Balance.
	 * @param string $due     Due date (a quote's valid until).
	 * @param string $today   Today (Y-m-d).
	 * @return array{0: string, 1: string}
	 */
	public static function status( string $tab, string $status, float $balance, string $due, string $today ): array {
		$past = '' !== $due && substr( $due, 0, 10 ) < $today;
		if ( 'invoices' === $tab ) {
			if ( in_array( $status, array( '2', '3' ), true ) && $balance > 0 && $past ) {
				return array( __( 'Overdue', 'invoiceninja' ), 'alert' );
			}
			return array(
				'2' => array( __( 'Unpaid', 'invoiceninja' ), 'open' ),
				'3' => array( __( 'Partly paid', 'invoiceninja' ), 'open' ),
				'4' => array( __( 'Paid', 'invoiceninja' ), 'done' ),
				'5' => array( __( 'Cancelled', 'invoiceninja' ), 'off' ),
				'6' => array( __( 'Reversed', 'invoiceninja' ), 'off' ),
			)[ $status ] ?? array( '', 'off' );
		}
		if ( 'quotes' === $tab ) {
			if ( '2' === $status && $past ) {
				return array( __( 'Expired', 'invoiceninja' ), 'off' ); // Sent, never approved, past its date.
			}
			return array(
				'2'  => array( __( 'Waiting for you', 'invoiceninja' ), 'open' ),
				'3'  => array( __( 'Approved', 'invoiceninja' ), 'done' ),
				'4'  => array( __( 'Converted', 'invoiceninja' ), 'done' ),
				'5'  => array( __( 'Cancelled', 'invoiceninja' ), 'off' ),
				'6'  => array( __( 'Cancelled', 'invoiceninja' ), 'off' ),
				'-1' => array( __( 'Expired', 'invoiceninja' ), 'off' ),
			)[ $status ] ?? array( '', 'off' );
		}
		if ( 'payments' === $tab ) {
			return array(
				'1' => array( __( 'Pending', 'invoiceninja' ), 'open' ),
				'2' => array( __( 'Voided', 'invoiceninja' ), 'off' ),
				'3' => array( __( 'Failed', 'invoiceninja' ), 'off' ),
				'4' => array( __( 'Completed', 'invoiceninja' ), 'done' ),
				'5' => array( __( 'Partly refunded', 'invoiceninja' ), 'done' ),
				'6' => array( __( 'Refunded', 'invoiceninja' ), 'off' ),
			)[ $status ] ?? array( '', 'off' );
		}
		return array(
			'2' => array( __( 'Available', 'invoiceninja' ), 'open' ),
			'3' => array( __( 'Partly used', 'invoiceninja' ), 'open' ),
			'4' => array( __( 'Used', 'invoiceninja' ), 'off' ),
		)[ $status ] ?? array( '', 'off' );
	}

	/**
	 * Where the portal link goes: the portal's home, or (to=invoices) its invoice list, where
	 * several invoices can be paid at once. Invoice Ninja's login link takes a `next` path.
	 *
	 * @param string $login The contact's login link.
	 * @param string $to    '' or invoices.
	 */
	public static function portalTarget( string $login, string $to ): string {
		return 'invoices' === $to && str_contains( $login, '/client/key_login/' ) ? add_query_arg( 'next', rawurlencode( '/client/invoices' ), $login ) : $login;
	}

	/**
	 * A link through this site's verified redirect.
	 *
	 * @param string $action pay, view or pdf.
	 * @param string $type   invoice, quote or credit.
	 * @param string $id     Id.
	 */
	private function link( string $action, string $type, string $id ): string {
		return add_query_arg(
			array(
				'invoiceninja' => $action,
				'type'         => $type,
				'id'           => rawurlencode( $id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * POST /account/verify/start — email a code to the account's own address.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function verifyStart( \WP_REST_Request $request ): \WP_REST_Response {
		$routes = new Routes( $this->plugin );
		if ( ! $routes->sameSite( $request ) ) {
			return Routes::fail( 'forbidden', __( 'Please try again from this site.', 'invoiceninja' ), 403 );
		}
		$user = get_userdata( Checkout::userId() );
		if ( ! $user ) {
			return Routes::fail( 'forbidden', __( 'Log in first.', 'invoiceninja' ), 403 );
		}
		$sent = $this->plugin->codes->send( (string) $user->user_email, Routes::clientIp(), 'portal' );
		if ( 'limited' === $sent['status'] ) {
			return Routes::fail( 'rate_limited', __( 'Too many codes were requested. Please try again in an hour.', 'invoiceninja' ), 429 );
		}
		if ( ! in_array( $sent['status'], array( 'sent', 'wait' ), true ) ) {
			return Routes::fail( 'send_failed', __( "We couldn't send the code. Please contact us.", 'invoiceninja' ), 500 );
		}
		return new \WP_REST_Response(
			array(
				'ok'          => true,
				// False when a code was sent moments ago: no new one until retry_after.
				'sent'        => 'sent' === $sent['status'],
				'retry_after' => (int) $sent['retry_after'],
			)
		);
	}

	/**
	 * POST /account/verify/confirm {code}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function verifyConfirm( \WP_REST_Request $request ): \WP_REST_Response {
		$routes = new Routes( $this->plugin );
		$user   = get_userdata( Checkout::userId() );
		if ( ! $user || ! $routes->sameSite( $request ) ) {
			return Routes::fail( 'forbidden', __( 'Log in first.', 'invoiceninja' ), 403 );
		}
		$result = $this->plugin->codes->verify( (string) $user->user_email, (string) $request->get_param( 'code' ), 'portal', Routes::clientIp() );
		if ( 'ok' !== $result ) {
			$messages = array(
				'wrong'   => __( "That code isn't right. Check the email and try again.", 'invoiceninja' ),
				'expired' => __( 'That code has expired. Send a new one.', 'invoiceninja' ),
				'locked'  => __( 'Too many wrong codes. Send a new one.', 'invoiceninja' ),
			);
			return Routes::fail( 'code_' . $result, $messages[ $result ] ?? $messages['wrong'], 422 );
		}
		$this->verification->mark( (int) $user->ID, 'code' );
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST /pay-invoice {number, email} — always the same reply.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function payInvoice( \WP_REST_Request $request ): \WP_REST_Response {
		$number = sanitize_text_field( (string) $request->get_param( 'number' ) );
		$email  = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
		$hour   = (int) floor( time() / HOUR_IN_SECONDS );
		$reply  = new \WP_REST_Response( array( 'ok' => true ) );
		$trap   = $request->get_param( Routes::TRAP );
		// Bots that fill the hidden field get the same reply as people, and nothing is sent.
		if ( Checkout::trapped( array( 'trap' => null === $trap ? '' : $trap ) ) ) {
			return $reply;
		}
		$started = self::formTime( (string) ( is_scalar( $request->get_param( 'form' ) ) ? $request->get_param( 'form' ) : '' ), $this->plugin->secrets->get( 'secret' ) );
		if ( null === $started ) {
			return Routes::fail( 'form_changed', __( 'This form has changed since the page loaded. Reload the page and try again.', 'invoiceninja' ), 400 );
		}
		/**
		 * Seconds a person needs at least to fill in the Pay Invoice form.
		 *
		 * @since 2.0.0
		 * @param int $seconds Seconds.
		 */
		$min     = max( 0, (int) apply_filters( 'invoiceninja_pay_invoice_min_seconds', 3 ) );
		$elapsed = $request->get_param( 'elapsed' );
		if ( time() - $started < $min || (int) ( is_numeric( $elapsed ) ? $elapsed : 0 ) < $min ) {
			return Routes::fail( 'too_fast', __( 'That was quick! Please check your details, then send again.', 'invoiceninja' ), 422 );
		}
		/**
		 * Whether the visitor passed a CAPTCHA on Pay Invoice. There is none by default; a
		 * CAPTCHA plugin returns false to refuse the request. Pay Invoice sends no CAPTCHA
		 * token of its own, so hook this only with a CAPTCHA that adds one. (The Quote
		 * Request Form uses invoiceninja_captcha.)
		 *
		 * @since 2.0.1
		 * @param bool                 $passed Passed.
		 * @param array<string, mixed> $input  Submitted values.
		 */
		if ( ! apply_filters( 'invoiceninja_pay_invoice_captcha', true, (array) $request->get_params() ) ) {
			return Routes::fail( 'captcha', __( 'Please complete the check to show you are not a robot.', 'invoiceninja' ), 422 );
		}
		if ( '' === $number || ! is_email( $email )
			|| $this->plugin->options->increment( 'ctr_pay_' . substr( md5( Checkout::ipBucket( Routes::clientIp() ) ), 0, 16 ) . '_' . $hour ) > 10 ) {
			return $reply;
		}
		try {
			$api = $this->plugin->api();
			foreach ( $api->get(
				'invoices',
				array(
					'number'     => $number, // An exact match (Invoice Ninja's `filter` is a fuzzy search).
					'is_deleted' => 'false',
					'per_page'   => 20,
				),
				5.0
			)->data() as $invoice ) {
				if ( ! is_array( $invoice ) || (string) ( $invoice['number'] ?? '' ) !== $number || ! empty( $invoice['is_deleted'] ) || (float) ( $invoice['balance'] ?? 0 ) <= 0 || ! in_array( (string) ( $invoice['status_id'] ?? '' ), array( '2', '3' ), true ) ) {
					continue;
				}
				$contact = $api->get( 'clients/' . rawurlencode( (string) $invoice['client_id'] ), array(), 5.0 )->data();
				if ( ! empty( $contact['is_deleted'] ) ) {
					continue;
				}
				foreach ( (array) ( $contact['contacts'] ?? array() ) as $person ) {
					if ( is_array( $person ) && empty( $person['is_deleted'] ) && strtolower( trim( (string) ( $person['email'] ?? '' ) ) ) === $email ) {
						$link = $this->invitationLink( $invoice, (string) ( $person['id'] ?? '' ) );
						// Only real sends count towards the per-invoice limit, so nobody can use
						// up a customer's emails by guessing.
						if ( '' !== $link && $this->plugin->options->increment( 'ctr_paymail_' . substr( md5( $email . '|' . $number ), 0, 16 ) . '_' . $hour ) <= 3 ) {
							$this->plugin->mailer->send(
								$email,
								/* translators: %s: invoice number */
								sprintf( __( 'Pay invoice %s', 'invoiceninja' ), $number ),
								'<p>' . esc_html__( 'Here is the link to pay your invoice.', 'invoiceninja' ) . '</p><p><a href="' . esc_url( $link ) . '" style="display:inline-block;background:#2271b1;color:#ffffff;padding:12px 20px;border-radius:4px;text-decoration:none;font-weight:600">' . esc_html__( 'Continue to secure payment', 'invoiceninja' ) . '</a></p><p style="color:#50575e;font-size:13px">' . esc_html__( "Didn't ask for this? You can ignore this email.", 'invoiceninja' ) . '</p>'
							);
						}
						break 2;
					}
				}
			}
		} catch ( ApiException $e ) {
			unset( $e ); // The reply never says whether the invoice exists.
		}
		return $reply;
	}

	/**
	 * The invitation link for one contact (never another contact's).
	 *
	 * @param array<string, mixed> $entity     Invoice, quote or credit.
	 * @param string               $contact_id Contact id.
	 */
	private function invitationLink( array $entity, string $contact_id ): string {
		foreach ( (array) ( $entity['invitations'] ?? array() ) as $invitation ) {
			if ( is_array( $invitation ) && '' !== $contact_id && (string) ( $invitation['client_contact_id'] ?? '' ) === $contact_id ) {
				return (string) ( $invitation['link'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Whether an invoice, quote or credit may be opened by this client: theirs, not
	 * deleted, and not a draft (drafts aren't the customer's business yet).
	 *
	 * @param array<string, mixed> $entity    Entity from Invoice Ninja.
	 * @param string               $client_id The visitor's client id.
	 */
	public static function opensFor( array $entity, string $client_id ): bool {
		return '' !== $client_id
			&& (string) ( $entity['client_id'] ?? '' ) === $client_id
			&& empty( $entity['is_deleted'] )
			&& ! in_array( (string) ( $entity['status_id'] ?? '' ), array( '', '1' ), true );
	}

	/**
	 * The invitation key for one contact.
	 *
	 * @param array<string, mixed> $entity     Entity.
	 * @param string               $contact_id Contact id.
	 */
	private function invitationKey( array $entity, string $contact_id ): string {
		foreach ( (array) ( $entity['invitations'] ?? array() ) as $invitation ) {
			if ( is_array( $invitation ) && (string) ( $invitation['client_contact_id'] ?? '' ) === $contact_id ) {
				return (string) ( $invitation['key'] ?? '' );
			}
		}
		return '';
	}

	// ---------- Redirects ----------

	/**
	 * ?invoiceninja=portal | pay | view | pdf — only for the logged-in, verified owner.
	 */
	public function redirect(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- links; nothing changes.
		$action = isset( $_GET['invoiceninja'] ) ? sanitize_key( wp_unslash( $_GET['invoiceninja'] ) ) : '';
		if ( ! in_array( $action, array( 'portal', 'pay', 'view', 'pdf' ), true ) ) {
			return;
		}
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$id   = isset( $_GET['id'] ) ? \InvoiceNinja\Catalog\ProductSync::cleanId( sanitize_text_field( wp_unslash( $_GET['id'] ) ) ) : '';
		$to   = isset( $_GET['to'] ) && 'invoices' === sanitize_key( wp_unslash( $_GET['to'] ) ) ? 'invoices' : '';
		// phpcs:enable
		nocache_headers();
		$user_id = Checkout::userId();
		$back    = (string) ( $this->plugin->store->pageUrl( 'account' ) ?: home_url( '/' ) );
		if ( 0 === $user_id ) {
			$return = add_query_arg(
				array_filter(
					array(
						'invoiceninja' => $action,
						'type'         => $type,
						'id'           => $id,
						'to'           => $to,
					)
				),
				home_url( '/' )
			);
			wp_safe_redirect( wp_login_url( $return ) );
			exit;
		}
		if ( ! $this->verification->isVerified( $user_id ) ) {
			// Confirm the email (works without an Account page), then come back to this link.
			wp_safe_redirect(
				ConfirmEmail::url(
					add_query_arg(
						array_filter(
							array(
								'invoiceninja' => $action,
								'type'         => $type,
								'id'           => $id,
								'to'           => $to,
							)
						),
						home_url( '/' )
					)
				)
			);
			exit;
		}
		try {
			// The linked contact must still use this account's (verified) email.
			$link = $this->links->confirmed( $user_id );
			if ( null === $link ) {
				wp_safe_redirect( $back );
				exit;
			}
			$api = $this->plugin->api();
			if ( 'portal' === $action ) {
				if ( ! empty( $link['contact']['link'] ) ) {
					wp_redirect( self::portalTarget( (string) $link['contact']['link'], $to ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the customer's own Invoice Ninja portal, which lives on another host.
					exit;
				}
				wp_safe_redirect( $back );
				exit;
			}
			if ( ! in_array( $type, array( 'invoice', 'quote', 'credit' ), true ) || '' === $id ) {
				wp_safe_redirect( $back );
				exit;
			}
			$entity = $api->get( $type . 's/' . rawurlencode( $id ), array(), 5.0 )->data();
			if ( ! self::opensFor( $entity, $link['client_id'] ) ) {
				wp_safe_redirect( $back ); // Not theirs, deleted or still a draft.
				exit;
			}
			if ( 'pdf' === $action ) {
				$key = $this->invitationKey( $entity, $link['contact_id'] );
				if ( '' === $key ) {
					wp_safe_redirect( $back );
					exit;
				}
				$file = $api->download( $type . '/' . rawurlencode( $key ) . '/download' );
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: inline; filename="' . sanitize_file_name( (string) ( $entity['number'] ?? $type ) ) . '.pdf"' );
				header( 'X-Content-Type-Options: nosniff' );
				echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a PDF file.
				exit;
			}
			$target = $this->invitationLink( $entity, $link['contact_id'] );
			if ( '' !== $target ) {
				wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the customer's own invitation on Invoice Ninja.
				exit;
			}
		} catch ( ApiException $e ) {
			wp_safe_redirect( add_query_arg( 'invoiceninja_unavailable', '1', $back ) );
			exit;
		}
		wp_safe_redirect( $back );
		exit;
	}
}
