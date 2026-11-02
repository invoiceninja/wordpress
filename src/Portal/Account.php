<?php
/**
 * The Account block, its data, the Pay Invoice block and the verified redirects.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Commerce\Checkout;
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

	public const TABS = array( 'invoices', 'quotes', 'payments', 'credits', 'subscriptions' );

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
	}

	/**
	 * Register the portal script.
	 */
	public function registerScript(): void {
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
	 * @param array<string, mixed> $a mode (account or link), label, style (link or button), class, tabs.
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
		$tabs = array_values( array_intersect( self::TABS, array_map( 'strval', (array) ( $a['tabs'] ?? self::TABS ) ) ) );
		return '<div class="invoiceninja-account" data-invoiceninja-account data-tabs="' . esc_attr( implode( ',', $tabs ? $tabs : self::TABS ) ) . '" data-invoiceninja-store>'
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
		return '<div class="invoiceninja-payinvoice" data-invoiceninja-payinvoice data-invoiceninja-store></div>';
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
		$tab  = (string) $request->get_param( 'tab' );
		$link = $this->links->get( $user_id );
		if ( null === $link ) {
			return new \WP_REST_Response( array( 'rows' => array() ) );
		}
		if ( 'subscriptions' === $tab ) {
			/** This filter is documented in the module brief: rows from the subscriptions module. */
			$rows = (array) apply_filters( 'invoiceninja_account_subscriptions', array(), $link['client_id'], $user_id );
			return new \WP_REST_Response( array( 'rows' => array_values( $rows ) ) );
		}
		$cache = 'invoiceninja_acct_' . $user_id . '_' . $tab;
		$rows  = get_transient( $cache );
		if ( ! is_array( $rows ) ) {
			try {
				$rows = $this->rows( $tab, $link['client_id'] );
			} catch ( ApiException $e ) {
				return Routes::fail( 'unavailable', __( 'Your invoices are temporarily unavailable. Please try again later.', 'invoiceninja' ), 503 );
			}
			set_transient( $cache, $rows, MINUTE_IN_SECONDS );
		}
		return new \WP_REST_Response( array( 'rows' => $rows ) );
	}

	/**
	 * Rows for a tab, from Invoice Ninja.
	 *
	 * @param string $tab       invoices, quotes, payments or credits.
	 * @param string $client_id Client id.
	 * @return list<array<string, mixed>>
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private function rows( string $tab, string $client_id ): array {
		$data  = $this->plugin->api()->get(
			$tab,
			array(
				'client_id'  => $client_id,
				'per_page'   => 50,
				'sort'       => 'date|desc',
				'is_deleted' => 'false',
			),
			5.0
		)->data();
		$rows  = array();
		$today = gmdate( 'Y-m-d' );
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['is_deleted'] ) || (string) ( $item['client_id'] ?? $client_id ) !== $client_id ) {
				continue;
			}
			$status = (string) ( $item['status_id'] ?? '' );
			if ( in_array( $tab, array( 'invoices', 'quotes', 'credits' ), true ) && '1' === $status ) {
				continue; // Drafts aren't the customer's business yet.
			}
			$id      = (string) ( $item['id'] ?? '' );
			$balance = (float) ( $item['balance'] ?? 0 );
			$row     = array(
				'id'      => $id,
				'number'  => (string) ( $item['number'] ?? '' ),
				'date'    => (string) ( $item['date'] ?? '' ),
				'due'     => (string) ( $item['due_date'] ?? ( $item['valid_until'] ?? '' ) ),
				'amount'  => $this->plugin->statics->money( (float) ( $item['amount'] ?? 0 ) ),
				'balance' => $this->plugin->statics->money( $balance ),
				'status'  => $this->statusLabel( $tab, $status, $balance, (string) ( $item['due_date'] ?? '' ), $today ),
				'links'   => array(),
			);
			if ( 'invoices' === $tab ) {
				$row['links']['pdf'] = $this->link( 'pdf', 'invoice', $id );
				if ( $balance > 0 && in_array( $status, array( '2', '3' ), true ) ) {
					$row['links']['pay'] = $this->link( 'pay', 'invoice', $id );
				}
			} elseif ( 'quotes' === $tab ) {
				$row['links']['pdf']  = $this->link( 'pdf', 'quote', $id );
				$row['links']['view'] = $this->link( 'view', 'quote', $id );
			} elseif ( 'credits' === $tab ) {
				$row['links']['pdf'] = $this->link( 'pdf', 'credit', $id );
			} elseif ( 'payments' === $tab ) {
				$row['number'] = (string) ( $item['number'] ?? ( $item['transaction_reference'] ?? '' ) );
				$row['method'] = (string) ( $item['type_id'] ?? '' );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * A plain status for a customer.
	 *
	 * @param string $tab     Tab.
	 * @param string $status  Invoice Ninja status id.
	 * @param float  $balance Balance.
	 * @param string $due     Due date.
	 * @param string $today   Today (Y-m-d).
	 */
	private function statusLabel( string $tab, string $status, float $balance, string $due, string $today ): string {
		if ( 'invoices' === $tab ) {
			if ( in_array( $status, array( '2', '3' ), true ) && $balance > 0 && '' !== $due && $due < $today ) {
				return __( 'Overdue', 'invoiceninja' );
			}
			return array(
				'2' => __( 'Unpaid', 'invoiceninja' ),
				'3' => __( 'Partly paid', 'invoiceninja' ),
				'4' => __( 'Paid', 'invoiceninja' ),
				'5' => __( 'Cancelled', 'invoiceninja' ),
				'6' => __( 'Reversed', 'invoiceninja' ),
			)[ $status ] ?? '';
		}
		if ( 'quotes' === $tab ) {
			return array(
				'2'  => __( 'Waiting for you', 'invoiceninja' ),
				'3'  => __( 'Approved', 'invoiceninja' ),
				'4'  => __( 'Converted', 'invoiceninja' ),
				'5'  => __( 'Cancelled', 'invoiceninja' ),
				'6'  => __( 'Cancelled', 'invoiceninja' ),
				'-1' => __( 'Expired', 'invoiceninja' ),
			)[ $status ] ?? '';
		}
		if ( 'payments' === $tab ) {
			return array(
				'1' => __( 'Pending', 'invoiceninja' ),
				'2' => __( 'Voided', 'invoiceninja' ),
				'3' => __( 'Failed', 'invoiceninja' ),
				'4' => __( 'Completed', 'invoiceninja' ),
				'5' => __( 'Partly refunded', 'invoiceninja' ),
				'6' => __( 'Refunded', 'invoiceninja' ),
			)[ $status ] ?? '';
		}
		return array(
			'2' => __( 'Available', 'invoiceninja' ),
			'3' => __( 'Partly used', 'invoiceninja' ),
			'4' => __( 'Used', 'invoiceninja' ),
		)[ $status ] ?? '';
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
		if ( '' === $number || ! is_email( $email )
			|| $this->plugin->options->increment( 'ctr_pay_' . substr( md5( Checkout::ipBucket( Routes::clientIp() ) ), 0, 16 ) . '_' . $hour ) > 10
			|| $this->plugin->options->increment( 'ctr_paymail_' . substr( md5( $email ), 0, 16 ) . '_' . $hour ) > 3 ) {
			return $reply;
		}
		try {
			$api = $this->plugin->api();
			foreach ( $api->get(
				'invoices',
				array(
					'filter'   => $number,
					'per_page' => 20,
				),
				5.0
			)->data() as $invoice ) {
				if ( ! is_array( $invoice ) || (string) ( $invoice['number'] ?? '' ) !== $number || ! empty( $invoice['is_deleted'] ) || (float) ( $invoice['balance'] ?? 0 ) <= 0 || ! in_array( (string) ( $invoice['status_id'] ?? '' ), array( '2', '3' ), true ) ) {
					continue;
				}
				$contact = $api->get( 'clients/' . rawurlencode( (string) $invoice['client_id'] ), array(), 5.0 )->data();
				foreach ( (array) ( $contact['contacts'] ?? array() ) as $person ) {
					if ( is_array( $person ) && strtolower( (string) ( $person['email'] ?? '' ) ) === $email ) {
						$link = $this->invitationLink( $invoice, (string) ( $person['id'] ?? '' ) );
						if ( '' !== $link ) {
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
					)
				),
				home_url( '/' )
			);
			wp_safe_redirect( wp_login_url( $return ) );
			exit;
		}
		if ( ! $this->verification->isVerified( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'invoiceninja_verify', '1', $back ) );
			exit;
		}
		try {
			$link = $this->links->discover( $user_id );
			if ( null === $link || '' === $link['contact_id'] ) {
				wp_safe_redirect( $back );
				exit;
			}
			$api = $this->plugin->api();
			if ( 'portal' === $action ) {
				$client = $api->get( 'clients/' . rawurlencode( $link['client_id'] ), array(), 5.0 )->data();
				foreach ( (array) ( $client['contacts'] ?? array() ) as $contact ) {
					if ( is_array( $contact ) && (string) ( $contact['id'] ?? '' ) === $link['contact_id'] && ! empty( $contact['link'] ) ) {
						wp_redirect( (string) $contact['link'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the customer's own Invoice Ninja portal, which lives on another host.
						exit;
					}
				}
				wp_safe_redirect( $back );
				exit;
			}
			if ( ! in_array( $type, array( 'invoice', 'quote', 'credit' ), true ) || '' === $id ) {
				wp_safe_redirect( $back );
				exit;
			}
			$entity = $api->get( $type . 's/' . rawurlencode( $id ), array(), 5.0 )->data();
			if ( (string) ( $entity['client_id'] ?? '' ) !== $link['client_id'] ) {
				wp_safe_redirect( $back ); // Not theirs.
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
