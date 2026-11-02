<?php
/**
 * The subscribe, manage and return endpoints.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Plugin;
use InvoiceNinja\Webhooks\Events;

/**
 * ?invoiceninja=subscribe&s=<id>: verified customers go through their contact's key_login
 *   link with ?next=<purchase page>, so they arrive logged in; everyone else goes to the
 *   purchase page with their name and email filled in (Invoice Ninja then confirms the email).
 * ?invoiceninja=subscription&do=change|cancel|payment_method&r=<recurring invoice>: the
 *   Account tab's links, for verified customers and only for their own subscriptions.
 * ?invoiceninja=subscribed: the return page Invoice Ninja sends buyers back to.
 *
 * Nothing here is cacheable, and no Invoice Ninja link is ever printed into a page.
 */
final class Redirects {

	public const COOKIE = 'invoiceninja_sub';

	/**
	 * Constructor.
	 *
	 * @param Plugin           $plugin   Plugin.
	 * @param SubscriptionSync $sync     Subscriptions.
	 * @param Callback         $callback Callback (recent purchases).
	 * @param State            $state    State.
	 */
	public function __construct(
		private Plugin $plugin,
		private SubscriptionSync $sync,
		private Callback $callback,
		private State $state,
	) {}

	/**
	 * Route ?invoiceninja=… requests.
	 */
	public function route(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- links; nothing changes on this site.
		$what = isset( $_GET['invoiceninja'] ) ? sanitize_key( wp_unslash( $_GET['invoiceninja'] ) ) : '';
		if ( 'subscribe' === $what ) {
			$this->subscribe();
		} elseif ( 'subscription' === $what ) {
			$this->manage();
		} elseif ( 'subscribed' === $what ) {
			( new ReturnPage( $this->plugin, $this ) )->render();
		}
	}

	/**
	 * Whether a WordPress user may be sent into the billing portal.
	 *
	 * @param int $user_id User.
	 */
	public static function verified( int $user_id ): bool {
		/** This filter is documented by the Portal module. */
		return $user_id > 0 && (bool) apply_filters( 'invoiceninja_is_verified', false, $user_id );
	}

	/**
	 * ?invoiceninja=subscribe&s=<id>.
	 */
	private function subscribe(): void {
		nocache_headers();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link.
		$id  = Events::cleanId( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' );
		$sub = '' !== $id && $this->plugin->options->setting( 'modules.subs' ) ? $this->sync->find( $id ) : null;
		if ( null === $sub || '' === (string) $sub['purchase_page'] ) {
			$this->fail( __( "This plan isn't available right now.", 'invoiceninja' ), 404 );
		}
		if ( $this->limited( 'subscribe' ) ) {
			$this->fail( __( 'Too many requests. Please try again in a few minutes.', 'invoiceninja' ), 429 );
		}
		$user_id = get_current_user_id();
		$client  = $user_id > 0 ? (string) get_user_meta( $user_id, \InvoiceNinja\Portal\Links::CLIENT, true ) : '';
		$this->pending( (string) $sub['id'], self::verified( $user_id ) ? $client : '', 'purchase' );

		$page = (string) $sub['purchase_page'];
		if ( self::verified( $user_id ) ) {
			$link = $this->contactLink( $user_id );
			if ( null !== $link ) {
				$this->away( add_query_arg( 'next', rawurlencode( $page ), $link ) );
			}
		}
		// Guests (and anyone whose link couldn't be fetched): Invoice Ninja's own email step.
		$prefill = array();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( $user ) {
			$prefill = array(
				'email'      => (string) $user->user_email,
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
			);
		} else {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- optional prefill from a form on this site.
			$prefill = array(
				'email'      => isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '',
				'first_name' => isset( $_GET['first_name'] ) ? sanitize_text_field( wp_unslash( $_GET['first_name'] ) ) : '',
				'last_name'  => isset( $_GET['last_name'] ) ? sanitize_text_field( wp_unslash( $_GET['last_name'] ) ) : '',
			);
			// phpcs:enable
		}
		$this->away( add_query_arg( array_map( 'rawurlencode', array_filter( $prefill ) ), $page ) );
	}

	/**
	 * ?invoiceninja=subscription&do=change|cancel|payment_method&r=<recurring invoice id>.
	 */
	private function manage(): void {
		nocache_headers();
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			auth_redirect();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link; it only leads to the customer's own portal.
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$ri = Events::cleanId( isset( $_GET['r'] ) ? sanitize_text_field( wp_unslash( $_GET['r'] ) ) : '' );
		// phpcs:enable
		if ( ! in_array( $do, array( 'change', 'cancel', 'payment_method' ), true ) || ! self::verified( $user_id ) || $this->limited( 'manage' ) ) {
			$this->fail( __( 'Please confirm your email on your Account page first.', 'invoiceninja' ), 403 );
		}
		$client = (string) get_user_meta( $user_id, \InvoiceNinja\Portal\Links::CLIENT, true );
		$link   = $this->contactLink( $user_id );
		if ( '' === $client || null === $link ) {
			$this->fail( __( 'Your billing details are temporarily unavailable. Please try again later.', 'invoiceninja' ), 503 );
		}
		$portal = (string) preg_replace( '#/client/.*$#', '', $link );
		if ( 'payment_method' === $do ) {
			$this->away( add_query_arg( 'next', rawurlencode( $portal . '/client/payment_methods' ), $link ) );
		}
		try {
			$recurring = $this->plugin->api()->get( 'recurring_invoices/' . rawurlencode( $ri ), array(), 5.0 )->data();
		} catch ( ApiException $e ) {
			$recurring = array();
		}
		if ( '' === $ri || Events::cleanId( $recurring['client_id'] ?? '' ) !== $client ) {
			$this->fail( __( "We couldn't find that subscription on your account.", 'invoiceninja' ), 404 );
		}
		if ( 'change' === $do ) {
			$this->pending( Events::cleanId( $recurring['subscription_id'] ?? '' ), $client, 'change' );
		}
		// The recurring invoice's page in the portal has the plan change and cancel buttons.
		$this->away( add_query_arg( 'next', rawurlencode( $portal . '/client/recurring_invoices/' . rawurlencode( $ri ) ), $link ) );
	}

	/**
	 * The linked contact's login link, fetched from Invoice Ninja (never stored).
	 *
	 * @param int $user_id User.
	 */
	public function contactLink( int $user_id ): ?string {
		$client  = Events::cleanId( get_user_meta( $user_id, \InvoiceNinja\Portal\Links::CLIENT, true ) );
		$contact = Events::cleanId( get_user_meta( $user_id, \InvoiceNinja\Portal\Links::CONTACT, true ) );
		if ( '' === $client || '' === $contact ) {
			return null;
		}
		try {
			$data = $this->plugin->api()->get( 'clients/' . rawurlencode( $client ), array(), 4.0 )->data();
		} catch ( ApiException $e ) {
			$this->plugin->logger->warning( 'subscriptions', "Couldn't fetch a customer's login link: " . $e->kind->title() );
			return null;
		}
		foreach ( (array) ( $data['contacts'] ?? array() ) as $row ) {
			if ( is_array( $row ) && Events::cleanId( $row['id'] ?? '' ) === $contact && ! empty( $row['link'] ) && self::webUrl( (string) $row['link'] ) ) {
				return (string) $row['link'];
			}
		}
		return null;
	}

	/**
	 * Whether a value is an http(s) address (Invoice Ninja may be on a private network, so
	 * wp_http_validate_url(), which refuses those, doesn't fit).
	 *
	 * @param string $url URL.
	 */
	public static function webUrl( string $url ): bool {
		return false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'https', 'http' ), true ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Remember what the visitor set out to do, for the return page.
	 *
	 * @param string $subscription Subscription id.
	 * @param string $client       Client id when known (verified customers).
	 * @param string $kind         purchase or change.
	 */
	private function pending( string $subscription, string $client, string $kind ): void {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			'invoiceninja_subctx_' . hash( 'sha256', $token ),
			array(
				's'           => $subscription,
				'client'      => $client,
				'kind'        => $kind,
				'at'          => time(),
				'returned_at' => 0,
			),
			2 * HOUR_IN_SECONDS
		);
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$token,
				array(
					'expires'  => time() + 2 * HOUR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax', // Sent on the way back from Invoice Ninja.
				)
			);
		}
	}

	/**
	 * Where the visitor stands after coming back: none, pending, slow, done or changed.
	 */
	public function returnState(): string {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return 'none';
		}
		$key = 'invoiceninja_subctx_' . hash( 'sha256', $token );
		$ctx = get_transient( $key );
		if ( ! is_array( $ctx ) ) {
			return 'none';
		}
		$contexts = 'change' === $ctx['kind'] ? array( 'change_plan' ) : array( 'single_purchase', 'recurring_purchase', 'trial', 'change_plan' );
		foreach ( array_reverse( $this->callback->recent() ) as $row ) {
			if ( $row['at'] < (int) $ctx['at'] - 60 || ! in_array( $row['context'], $contexts, true ) ) {
				continue;
			}
			$mine = '' !== (string) $ctx['client'] ? $row['client'] === $ctx['client'] : ( $row['subscription'] === $ctx['s'] || 'change_plan' === $row['context'] );
			if ( $mine ) {
				return 'change_plan' === $row['context'] ? 'changed' : 'done';
			}
		}
		if ( ! $ctx['returned_at'] ) {
			$ctx['returned_at'] = time();
			set_transient( $key, $ctx, 2 * HOUR_IN_SECONDS );
		}
		return time() - (int) $ctx['returned_at'] < 30 ? 'pending' : 'slow';
	}

	/**
	 * GET /subscriptions/return: the return page polls this.
	 */
	public function returnStatus(): \WP_REST_Response {
		nocache_headers();
		if ( $this->limited( 'return' ) ) {
			return new \WP_REST_Response( array( 'state' => 'slow' ), 429 );
		}
		return new \WP_REST_Response( array( 'state' => $this->returnState() ) );
	}

	/**
	 * Account tab rows (the invoiceninja_account_subscriptions filter).
	 *
	 * @param array<int, array<string, mixed>> $rows      Rows so far.
	 * @param string                           $client_id Client.
	 * @param int                              $user_id   User.
	 * @return array<int, array<string, mixed>>
	 */
	public function accountRows( array $rows, string $client_id, int $user_id ): array {
		$client_id = Events::cleanId( $client_id );
		if ( '' === $client_id || 'none' === $this->plugin->credentials->source() ) {
			return $rows;
		}
		$cache = 'invoiceninja_subrows_' . md5( $client_id );
		$mine  = get_transient( $cache );
		if ( ! is_array( $mine ) ) {
			try {
				$api       = $this->plugin->api();
				$recurring = $api->get(
					'recurring_invoices',
					array(
						'client_id' => $client_id,
						'per_page'  => 50,
						'sort'      => 'id|desc',
					),
					5.0
				)->data();
				$overdue   = $api->get(
					'invoices',
					array(
						'client_id'     => $client_id,
						'client_status' => 'overdue',
						'per_page'      => 50,
					),
					5.0
				)->data();
			} catch ( ApiException $e ) {
				return $rows;
			}
			$mine = self::rows( $recurring, $overdue, $client_id, $this->sync->all(), $this->state->forClient( $client_id ) );
			set_transient( $cache, $mine, 5 * MINUTE_IN_SECONDS );
		}
		foreach ( $mine as $row ) {
			$row['amount'] = $this->plugin->statics->money( (float) $row['amount'] );
			$links         = array();
			foreach ( $row['links'] as $do => $allowed ) {
				$links[ $do ] = $allowed && self::verified( $user_id ) ? add_query_arg(
					array(
						'invoiceninja' => 'subscription',
						'do'           => $do,
						'r'            => $row['id'],
					),
					home_url( '/' )
				) : '';
			}
			$row['links'] = $links;
			$rows[]       = $row;
		}
		return $rows;
	}

	/**
	 * Build Account rows (pure, for tests). Amount is a number here; links are flags.
	 *
	 * @param array<mixed>                         $recurring Recurring invoices.
	 * @param array<mixed>                         $overdue   Overdue invoices.
	 * @param string                               $client_id Client.
	 * @param array<string, array<string, mixed>>  $subs      Subscriptions by id.
	 * @param array<string, array<string, mixed>>  $states    State rows.
	 * @return list<array{id: string, name: string, status: string, next_date: string, amount: float, links: array{change: bool, payment_method: bool, cancel: bool}}>
	 */
	public static function rows( array $recurring, array $overdue, string $client_id, array $subs, array $states ): array {
		$late = array();
		foreach ( $overdue as $invoice ) {
			if ( is_array( $invoice ) && in_array( (string) ( $invoice['status_id'] ?? '' ), array( '2', '3' ), true ) ) {
				$late[ Events::cleanId( $invoice['recurring_id'] ?? '' ) ] = true;
			}
		}
		$out = array();
		foreach ( $recurring as $ri ) {
			if ( ! is_array( $ri ) || ! empty( $ri['is_deleted'] ) || Events::cleanId( $ri['client_id'] ?? '' ) !== $client_id ) {
				continue;
			}
			$sub_id = Events::cleanId( $ri['subscription_id'] ?? '' );
			$id     = Events::cleanId( $ri['id'] ?? '' );
			if ( '' === $sub_id || '' === $id ) {
				continue;
			}
			$sub    = $subs[ $sub_id ] ?? array();
			$local  = $states[ State::key( $id, $sub_id, $client_id ) ]['status'] ?? '';
			$status = match ( true ) {
				'cancelled' === $local || in_array( (int) ( $ri['status_id'] ?? 0 ), array( 3, 4 ), true ) => 'cancelled',
				'suspended' === $local                                                                    => 'suspended',
				isset( $late[ $id ] )                                                                     => 'overdue',
				'trial' === $local                                                                        => 'trial',
				default                                                                                   => 'active',
			};
			$next  = (string) ( $ri['next_send_date'] ?? '' );
			$out[] = array(
				'id'        => $id,
				'name'      => (string) ( $sub['name'] ?? ( $ri['number'] ?? '' ) ),
				'status'    => $status,
				'next_date' => 'cancelled' !== $status && preg_match( '/^\d{4}-\d{2}-\d{2}/', $next ) ? substr( $next, 0, 10 ) : '',
				'amount'    => (float) ( $ri['amount'] ?? 0 ),
				'links'     => array(
					'change'         => 'cancelled' !== $status && ! empty( $sub['allow_plan_changes'] ),
					'payment_method' => 'cancelled' !== $status,
					'cancel'         => 'cancelled' !== $status && ! empty( $sub['allow_cancellation'] ),
				),
			);
		}
		return $out;
	}

	/**
	 * Redirect to Invoice Ninja (a host wp_safe_redirect allows only for this one call).
	 *
	 * @param string $url URL from the API.
	 * @return never
	 */
	private function away( string $url ): never {
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$allow = static fn( array $hosts ): array => array_merge( $hosts, array( $host ) );
		add_filter( 'allowed_redirect_hosts', $allow );
		nocache_headers();
		wp_safe_redirect( $url, 302, 'Invoice Ninja for WordPress' );
		exit;
	}

	/**
	 * Stop with a friendly message.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return never
	 */
	private function fail( string $message, int $status ): never {
		nocache_headers();
		wp_die(
			esc_html( $message ),
			esc_html__( 'Subscription', 'invoiceninja' ),
			array(
				'response'  => $status,
				'back_link' => true,
			)
		);
	}

	/**
	 * Per-IP rate limit (60 an hour per endpoint).
	 *
	 * @param string $what Endpoint.
	 */
	private function limited( string $what ): bool {
		$ip = substr( hash( 'sha256', Plugin::clientIp() ), 0, 12 );
		return $this->plugin->options->increment( 'ctr_sub' . $what . '_' . $ip . '_' . (int) floor( time() / HOUR_IN_SECONDS ) ) > ( 'return' === $what ? 300 : 60 );
	}
}
