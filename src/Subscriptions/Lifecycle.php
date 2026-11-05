<?php
/**
 * Turns Invoice Ninja's subscription callbacks and overdue invoices into lifecycle events.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Api\Paginator;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Plugin;
use InvoiceNinja\Webhooks\Events;

/**
 * Every event fetches the client, recurring invoice and invoice again by id, checks they
 * belong together, then fires invoiceninja_subscription_<event> with a stable payload
 * (Payload, version 1) and the raw records, and hands it to the ProvisioningRelay.
 *
 * Events: purchased, trial, plan_changed, paid, cancelled, suspended, resumed.
 * Invoice Ninja has no "suspended": a subscription invoice unpaid for the configured number
 * of days suspends it (from the plan_expired callback, the late-invoice webhook as a hint,
 * or the daily overdue poll), and paying that invoice resumes it.
 */
final class Lifecycle {

	public const EVENTS_LOG = 'subscriptions_events';

	/**
	 * Constructor.
	 *
	 * @param Plugin            $plugin Plugin.
	 * @param SubscriptionSync  $sync   Subscriptions.
	 * @param State             $state  State.
	 * @param ProvisioningRelay $relay  Relay.
	 */
	public function __construct(
		private Plugin $plugin,
		private SubscriptionSync $sync,
		private State $state,
		private ProvisioningRelay $relay,
	) {}

	/**
	 * Days an invoice may stay unpaid before its subscription is suspended.
	 */
	public function suspendDays(): int {
		return min( 365, max( 0, (int) $this->plugin->options->setting( 'subscriptions.suspend_days' ) ) );
	}

	/**
	 * Queue handler for a callback.
	 *
	 * @param array<string, mixed> $ctx context, subscription, client, invoice, recurring_invoice.
	 * @throws ApiException On a temporary failure (the queue tries again).
	 */
	public function handle( array $ctx ): void {
		$context = (string) ( $ctx['context'] ?? '' );
		$sub_id  = Events::cleanId( $ctx['subscription'] ?? '' );
		$api     = $this->plugin->api();
		$client  = $this->fetch( $api, 'clients', Events::cleanId( $ctx['client'] ?? '' ) );
		if ( null === $client ) {
			$this->ignored( $context, 'client' );
			return;
		}
		$invoice   = $this->fetch( $api, 'invoices', Events::cleanId( $ctx['invoice'] ?? '' ) );
		$ri_id     = Events::cleanId( $ctx['recurring_invoice'] ?? '' );
		$ri_id     = '' !== $ri_id ? $ri_id : Events::cleanId( $invoice['recurring_id'] ?? '' );
		$recurring = $this->fetch( $api, 'recurring_invoices', $ri_id );
		if ( ( '' !== (string) ( $ctx['invoice'] ?? '' ) && null === $invoice ) || ( '' !== $ri_id && null === $recurring ) ) {
			$this->ignored( $context, 'invoice' );
			return;
		}
		if ( ! self::belong( (string) $client['id'], $sub_id, $recurring, $invoice ) ) {
			$this->ignored( $context, 'mismatch' );
			return;
		}
		$sub = $this->subscription( $api, $sub_id );
		if ( null === $sub ) {
			$this->ignored( $context, 'subscription' );
			return;
		}
		$refused = self::refusal( $context, $sub, $recurring, $invoice );
		if ( '' !== $refused ) {
			// The secret is shared by every subscription and visible in Invoice Ninja, so a call
			// with it is still only believed when Invoice Ninja's records say the same.
			$this->plugin->logger->warning( 'subscriptions', sprintf( 'Ignored a %s callback: %s', $context, $refused ) );
			return;
		}
		$key = State::key( (string) ( $recurring['id'] ?? '' ), $sub_id, (string) $client['id'] );
		$row = $this->state->get( $key );

		switch ( $context ) {
			case 'single_purchase':
			case 'recurring_purchase':
				$this->apply( $key, $this->row( 'active', $sub_id, $client, $recurring, $invoice ), 'purchased', $context, $sub, $client, $recurring, $invoice );
				break;
			case 'trial':
				$this->apply( $key, $this->row( 'trial', $sub_id, $client, $recurring, $invoice ), 'trial', $context, $sub, $client, $recurring, $invoice );
				break;
			case 'change_plan':
				$this->apply( $key, $this->row( 'active', $sub_id, $client, $recurring, $invoice ), 'plan_changed', $context, $sub, $client, $recurring, $invoice );
				break;
			case 'plan_paid':
				$suspended = null !== $row && 'suspended' === $row['status'];
				$paid_row  = $this->row( $suspended ? 'suspended' : 'active', $sub_id, $client, $recurring, $invoice );
				if ( $suspended ) {
					$paid_row['invoice'] = $row['invoice']; // Still the invoice it was suspended for; resume() checks that one.
				}
				$this->apply( $key, $paid_row, 'paid', $context, $sub, $client, $recurring, $invoice );
				$this->resume( $key, $context, $sub, $client, $recurring, $invoice ?? array() );
				break;
			case 'cancellation':
				$this->apply( $key, $this->row( 'cancelled', $sub_id, $client, $recurring, $invoice ), 'cancelled', $context, $sub, $client, $recurring, $invoice );
				break;
			case 'plan_expired':
				if ( null !== $invoice && $this->unpaid( $invoice ) && self::daysOverdue( $invoice ) >= $this->suspendDays() ) {
					$this->suspend( $key, $context, $sub, $client, $recurring, $invoice );
				}
				break;
		}
	}

	/**
	 * Whether the invoice and recurring invoice belong to this client and subscription, and to
	 * each other (pure, for tests).
	 *
	 * @param string                    $client_id Client id.
	 * @param string                    $sub_id    Subscription id.
	 * @param array<string, mixed>|null $recurring Recurring invoice.
	 * @param array<string, mixed>|null $invoice   Invoice.
	 */
	public static function belong( string $client_id, string $sub_id, ?array $recurring, ?array $invoice ): bool {
		foreach ( array( $invoice, $recurring ) as $doc ) {
			if ( null !== $doc && ( Events::cleanId( $doc['client_id'] ?? '' ) !== $client_id || Events::cleanId( $doc['subscription_id'] ?? '' ) !== $sub_id ) ) {
				return false;
			}
		}
		$linked = Events::cleanId( $invoice['recurring_id'] ?? '' );
		return null === $recurring || null === $invoice || '' === $linked || Events::cleanId( $recurring['id'] ?? '' ) === $linked;
	}

	/**
	 * Why Invoice Ninja's records don't back up a callback, or '' when they do (pure, for tests).
	 * What Invoice Ninja sends, and the state its records are in by then:
	 * - single_purchase: the invoice, paid;
	 * - recurring_purchase: the invoice, paid (free plans too), and the recurring invoice, started;
	 * - trial: the recurring invoice, started, on a subscription with trials;
	 * - change_plan: the new recurring invoice, started (and its invoice, paid, when there was one);
	 * - plan_paid: the invoice, paid;
	 * - cancellation: the recurring invoice, stopped (paused) and archived.
	 *
	 * @param string                    $context   Callback context.
	 * @param array<string, mixed>      $sub       Subscription.
	 * @param array<string, mixed>|null $recurring Recurring invoice.
	 * @param array<string, mixed>|null $invoice   Invoice.
	 */
	public static function refusal( string $context, array $sub, ?array $recurring, ?array $invoice ): string {
		$paid    = null !== $invoice && self::paid( $invoice );
		$running = null !== $recurring && empty( $recurring['is_deleted'] ) && in_array( (int) ( $recurring['status_id'] ?? 0 ), array( 2, -1 ), true );
		switch ( $context ) {
			case 'single_purchase':
			case 'plan_paid':
				return $paid ? '' : 'its invoice is not paid';
			case 'recurring_purchase':
				if ( ! $paid ) {
					return 'its invoice is not paid';
				}
				return $running ? '' : 'its recurring invoice is not active';
			case 'trial':
				if ( empty( $sub['trial_enabled'] ) ) {
					return 'the subscription has no trial';
				}
				return $running ? '' : 'its recurring invoice is not active';
			case 'change_plan':
				if ( null !== $invoice && ! $paid ) {
					return 'its invoice is not paid';
				}
				return $running ? '' : 'its recurring invoice is not active';
			case 'cancellation':
				$stopped = null !== $recurring && ( ! empty( $recurring['is_deleted'] ) || ! empty( $recurring['archived_at'] ) || in_array( (int) ( $recurring['status_id'] ?? 0 ), array( 3, 4 ), true ) );
				return $stopped ? '' : 'its recurring invoice is still active';
		}
		return '';
	}

	/**
	 * Whether an invoice is paid (status paid, or nothing left to pay on a sent invoice).
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 */
	public static function paid( array $invoice ): bool {
		if ( ! empty( $invoice['is_deleted'] ) ) {
			return false;
		}
		$status = (int) ( $invoice['status_id'] ?? 0 );
		return 4 === $status || ( in_array( $status, array( 2, 3 ), true ) && (float) ( $invoice['balance'] ?? 1 ) <= 0.0 );
	}

	/**
	 * Change the state and fire the event, unless this exact event already happened (a
	 * repeated or replayed callback changes nothing).
	 *
	 * @param string                    $key       State key.
	 * @param array<string, string>     $row       New state.
	 * @param string                    $event     Event.
	 * @param string                    $context   Context.
	 * @param array<string, mixed>      $sub       Subscription.
	 * @param array<string, mixed>      $client    Client.
	 * @param array<string, mixed>|null $recurring Recurring invoice.
	 * @param array<string, mixed>|null $invoice   Invoice.
	 */
	private function apply( string $key, array $row, string $event, string $context, array $sub, array $client, ?array $recurring, ?array $invoice ): bool {
		$id = Payload::id( $event, (string) ( $sub['id'] ?? '' ), (string) ( $client['id'] ?? '' ), (string) ( $recurring['id'] ?? '' ), (string) ( $invoice['id'] ?? '' ), $context );
		if ( $this->state->seen( $id ) ) {
			return false;
		}
		$this->state->set( $key, $row );
		$this->fire( $event, $context, $sub, $client, $recurring, $invoice );
		return true;
	}

	/**
	 * Suspend, once.
	 *
	 * @param string                    $key       State key.
	 * @param string                    $context   Why.
	 * @param array<string, mixed>      $sub       Subscription.
	 * @param array<string, mixed>      $client    Client.
	 * @param array<string, mixed>|null $recurring Recurring invoice.
	 * @param array<string, mixed>      $invoice   The overdue invoice.
	 */
	private function suspend( string $key, string $context, array $sub, array $client, ?array $recurring, array $invoice ): void {
		$row = $this->state->get( $key );
		if ( null !== $row && in_array( $row['status'], array( 'suspended', 'cancelled' ), true ) ) {
			return;
		}
		$this->apply( $key, $this->row( 'suspended', (string) $sub['id'], $client, $recurring, $invoice ), 'suspended', $context, $sub, $client, $recurring, $invoice );
	}

	/**
	 * Resume a suspended subscription, once.
	 *
	 * @param string                    $key       State key.
	 * @param string                    $context   Why.
	 * @param array<string, mixed>      $sub       Subscription.
	 * @param array<string, mixed>      $client    Client.
	 * @param array<string, mixed>|null $recurring Recurring invoice.
	 * @param array<string, mixed>      $invoice   The paid invoice.
	 * @throws ApiException On a temporary failure.
	 */
	private function resume( string $key, string $context, array $sub, array $client, ?array $recurring, array $invoice ): void {
		$row = $this->state->get( $key );
		if ( null === $row || 'suspended' !== $row['status'] || ! self::paid( $invoice ) ) {
			return;
		}
		// Paying another invoice doesn't settle the one that suspended it.
		if ( '' !== $row['invoice'] && Events::cleanId( $invoice['id'] ?? '' ) !== $row['invoice'] ) {
			$overdue = $this->fetch( $this->plugin->api(), 'invoices', $row['invoice'] );
			if ( null !== $overdue && $this->unpaid( $overdue ) ) {
				return;
			}
		}
		$this->apply( $key, array( 'status' => 'active' ), 'resumed', $context, $sub, $client, $recurring, $invoice );
	}

	/**
	 * A state row.
	 *
	 * @param string                    $status    Status.
	 * @param string                    $sub_id    Subscription id.
	 * @param array<string, mixed>      $client    Client.
	 * @param array<string, mixed>|null $recurring Recurring invoice.
	 * @param array<string, mixed>|null $invoice   Invoice.
	 * @return array<string, string>
	 */
	private function row( string $status, string $sub_id, array $client, ?array $recurring, ?array $invoice ): array {
		return array(
			'status'            => $status,
			'subscription'      => $sub_id,
			'client'            => (string) $client['id'],
			'recurring_invoice' => (string) ( $recurring['id'] ?? '' ),
			'invoice'           => (string) ( $invoice['id'] ?? '' ),
		);
	}

	/**
	 * Fire an event (once per event id), and relay it.
	 *
	 * @param string                    $event     Event.
	 * @param string                    $context   Context.
	 * @param array<string, mixed>      $sub       Subscription.
	 * @param array<string, mixed>      $client    Client.
	 * @param array<string, mixed>|null $recurring Recurring invoice.
	 * @param array<string, mixed>|null $invoice   Invoice.
	 */
	public function fire( string $event, string $context, array $sub, array $client, ?array $recurring, ?array $invoice ): void {
		$payload = Payload::build( $event, $context, $sub, $client, $recurring, $invoice, $this->userFor( (string) $client['id'] ), time() );
		if ( $this->state->seen( $payload['id'] ) ) {
			return;
		}
		$payload['sequence'] = $this->relay->nextSequence();
		// Into the relay's outbox first, then recorded as fired: if queueing throws, the
		// callback job can run again and nothing is lost.
		$this->relay->queue( (string) $sub['id'], $payload );
		if ( ! $this->state->firstTime( $payload['id'] ) ) {
			return;
		}
		$log   = (array) $this->plugin->options->get( self::EVENTS_LOG, array() );
		$log[] = array(
			'id'           => $payload['id'],
			'event'        => $event,
			'context'      => $context,
			'subscription' => (string) $sub['id'],
			'client'       => (string) $client['id'],
			'at'           => time(),
		);
		$this->plugin->options->set( self::EVENTS_LOG, array_slice( $log, -50 ) );
		$this->plugin->logger->log( LogLevel::Info, 'subscriptions', sprintf( 'Subscription "%s" %s for client %s', (string) ( $sub['name'] ?? $sub['id'] ), str_replace( '_', ' ', $event ), (string) ( $client['name'] ?? $client['id'] ) ), array(), 'subscription', (string) $sub['id'] );

		$raw = array(
			'client'            => $client,
			'recurring_invoice' => $recurring,
			'invoice'           => $invoice,
		);
		/**
		 * Subscription lifecycle events: invoiceninja_subscription_purchased, _trial,
		 * _plan_changed, _paid, _cancelled, _suspended and _resumed.
		 *
		 * @since 2.0.0
		 * @param array $payload Stable payload (version 1, see docs/hosting.md).
		 * @param array $raw     client, recurring_invoice and invoice as Invoice Ninja returned them.
		 */
		try {
			do_action( "invoiceninja_subscription_{$event}", $payload, $raw );
		} catch ( \Throwable $e ) {
			// A listener's error mustn't stop the rest (a plan_paid also resumes).
			$this->plugin->logger->error( 'subscriptions', sprintf( 'A listener of invoiceninja_subscription_%s failed: %s', $event, $e->getMessage() ) );
		}
	}

	/**
	 * Daily: suspend subscriptions with invoices unpaid for too long; resume paid ones.
	 *
	 * @throws ApiException On a temporary failure (the queue tries again).
	 */
	public function pollOverdue(): void {
		if ( 'connected' !== $this->plugin->state->get()['status'] ) {
			return;
		}
		$api     = $this->plugin->api();
		$days    = $this->suspendDays();
		$overdue = array();
		$calls   = 0;
		foreach ( Paginator::each(
			$api,
			'invoices',
			array(
				'client_status' => 'overdue',
				'status'        => 'active',
				'sort'          => 'due_date|asc',
			),
			250,
			20
		) as $invoice ) {
			$sub_id = Events::cleanId( $invoice['subscription_id'] ?? '' );
			if ( '' === $sub_id || ! $this->unpaid( $invoice ) ) {
				continue;
			}
			$key             = State::key( Events::cleanId( $invoice['recurring_id'] ?? '' ), $sub_id, Events::cleanId( $invoice['client_id'] ?? '' ) );
			$overdue[ $key ] = true;
			$row             = $this->state->get( $key );
			if ( self::daysOverdue( $invoice ) < $days || ( null !== $row && in_array( $row['status'], array( 'suspended', 'cancelled' ), true ) ) || $calls > 100 ) {
				continue;
			}
			++$calls;
			$this->suspendFor( $api, $invoice, 'overdue_poll' );
		}
		foreach ( $this->state->all() as $key => $row ) {
			if ( 'suspended' !== $row['status'] || isset( $overdue[ $key ] ) || '' === $row['invoice'] || $calls > 100 ) {
				continue;
			}
			++$calls;
			$invoice = $this->fetch( $api, 'invoices', $row['invoice'] );
			if ( null !== $invoice && ! $this->unpaid( $invoice ) ) {
				$this->resumeFor( $api, $invoice, 'overdue_poll' );
			}
		}
	}

	/**
	 * Late-invoice webhook (event 22): a hint to check that one invoice now.
	 *
	 * @param array<string, mixed> $invoice Invoice, fetched again by the Dispatcher.
	 */
	public function onLate( array $invoice ): void {
		if ( '' === Events::cleanId( $invoice['subscription_id'] ?? '' ) || ! $this->unpaid( $invoice ) || self::daysOverdue( $invoice ) < $this->suspendDays() ) {
			return;
		}
		$this->suspendFor( $this->plugin->api(), $invoice, 'invoice_late' );
	}

	/**
	 * Payment webhook: a paid subscription invoice resumes a suspended subscription.
	 *
	 * @param array<string, mixed> $payment Payment with its invoices, fetched again by the Dispatcher.
	 */
	public function onPayment( array $payment ): void {
		$api = $this->plugin->api();
		foreach ( Events::paymentInvoiceIds( $payment ) as $invoice_id ) {
			$invoice = $this->fetch( $api, 'invoices', $invoice_id );
			if ( null !== $invoice && '' !== Events::cleanId( $invoice['subscription_id'] ?? '' ) && ! $this->unpaid( $invoice ) ) {
				$this->resumeFor( $api, $invoice, 'payment' );
			}
		}
	}

	/**
	 * Suspend the subscription an invoice belongs to.
	 *
	 * @param HttpClient           $api     Client.
	 * @param array<string, mixed> $invoice Invoice.
	 * @param string               $context Why.
	 * @throws ApiException On a temporary failure.
	 */
	private function suspendFor( HttpClient $api, array $invoice, string $context ): void {
		[ $key, $sub, $client, $recurring ] = $this->around( $api, $invoice );
		if ( null !== $client && null !== $sub ) {
			$this->suspend( $key, $context, $sub, $client, $recurring, $invoice );
		}
	}

	/**
	 * Resume the subscription an invoice belongs to.
	 *
	 * @param HttpClient           $api     Client.
	 * @param array<string, mixed> $invoice Invoice.
	 * @param string               $context Why.
	 * @throws ApiException On a temporary failure.
	 */
	private function resumeFor( HttpClient $api, array $invoice, string $context ): void {
		[ $key, $sub, $client, $recurring ] = $this->around( $api, $invoice );
		if ( null !== $client && null !== $sub ) {
			$this->resume( $key, $context, $sub, $client, $recurring, $invoice );
		}
	}

	/**
	 * The state key, subscription, client and recurring invoice around an invoice.
	 *
	 * @param HttpClient           $api     Client.
	 * @param array<string, mixed> $invoice Invoice.
	 * @return array{0: string, 1: array<string, mixed>|null, 2: array<string, mixed>|null, 3: array<string, mixed>|null}
	 * @throws ApiException On a temporary failure.
	 */
	private function around( HttpClient $api, array $invoice ): array {
		$sub_id    = Events::cleanId( $invoice['subscription_id'] ?? '' );
		$client_id = Events::cleanId( $invoice['client_id'] ?? '' );
		$recurring = $this->fetch( $api, 'recurring_invoices', Events::cleanId( $invoice['recurring_id'] ?? '' ) );
		return array(
			State::key( (string) ( $recurring['id'] ?? '' ), $sub_id, $client_id ),
			$this->subscription( $api, $sub_id ),
			$this->fetch( $api, 'clients', $client_id ),
			$recurring,
		);
	}

	/**
	 * Whether an invoice still needs paying (sent or partial, with a balance).
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 */
	private function unpaid( array $invoice ): bool {
		return empty( $invoice['is_deleted'] ) && in_array( (string) ( $invoice['status_id'] ?? '' ), array( '2', '3' ), true ) && (float) ( $invoice['balance'] ?? 0 ) > 0;
	}

	/**
	 * Whole days since an invoice was due (0 on the due date, negative before it).
	 *
	 * @param array<string, mixed> $invoice Invoice.
	 * @param int|null             $now     Now.
	 */
	public static function daysOverdue( array $invoice, ?int $now = null ): int {
		$due = (string) ( $invoice['due_date'] ?? '' );
		if ( (float) ( $invoice['partial'] ?? 0 ) > 0 && '' !== (string) ( $invoice['partial_due_date'] ?? '' ) ) {
			$due = (string) $invoice['partial_due_date'];
		}
		$time = '' !== $due ? strtotime( substr( $due, 0, 10 ) . ' 00:00:00 UTC' ) : false;
		if ( false === $time ) {
			return -1;
		}
		return (int) floor( ( ( $now ?? time() ) - $time ) / DAY_IN_SECONDS );
	}

	/**
	 * An entity by id, or null when Invoice Ninja doesn't have it.
	 *
	 * @param HttpClient $api  Client.
	 * @param string     $type clients, invoices or recurring_invoices.
	 * @param string     $id   Id.
	 * @return array<string, mixed>|null
	 * @throws ApiException On a temporary failure.
	 */
	private function fetch( HttpClient $api, string $type, string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}
		try {
			$data = $api->get( $type . '/' . rawurlencode( $id ), array(), 8.0 )->data();
		} catch ( ApiException $e ) {
			if ( ErrorKind::NotFound === $e->kind ) {
				return null;
			}
			throw $e;
		}
		return Events::cleanId( $data['id'] ?? '' ) === $id ? $data : null;
	}

	/**
	 * A subscription from the local copy, or from Invoice Ninja.
	 *
	 * @param HttpClient $api Client.
	 * @param string     $id  Id.
	 * @return array<string, mixed>|null
	 * @throws ApiException On a temporary failure.
	 */
	private function subscription( HttpClient $api, string $id ): ?array {
		$known = $this->sync->cache()['items'][ $id ] ?? null;
		if ( is_array( $known ) ) {
			return $known;
		}
		$raw = $this->fetch( $api, 'subscriptions', $id );
		return null === $raw ? null : SubscriptionSync::normalize( $raw );
	}

	/**
	 * The WordPress user linked to a client.
	 *
	 * @param string $client_id Client id.
	 */
	private function userFor( string $client_id ): int {
		$portal = $this->plugin->module( 'portal' );
		if ( '' === $client_id || ! $portal instanceof \InvoiceNinja\Portal\PortalModule ) {
			return 0;
		}
		// The meta query finds candidates; the Links service decides whether a link still holds.
		$users = get_users(
			array(
				'meta_query'  => \InvoiceNinja\Portal\UserData::metaQuery( \InvoiceNinja\Portal\Links::CLIENT, $client_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed lookup per event.
				'number'      => 5,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);
		foreach ( (array) $users as $user_id ) {
			if ( ( $portal->links->get( (int) $user_id )['client_id'] ?? '' ) === $client_id ) {
				return (int) $user_id;
			}
		}
		return 0;
	}

	/**
	 * Log a call that was ignored.
	 *
	 * @param string $context Context.
	 * @param string $why     What didn't match.
	 */
	private function ignored( string $context, string $why ): void {
		$this->plugin->logger->warning( 'subscriptions', sprintf( 'Ignored a %s callback: its %s was not found in Invoice Ninja or did not match', $context, $why ) );
	}

	/**
	 * The latest lifecycle events, newest last.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function events(): array {
		return array_values( array_filter( (array) $this->plugin->options->get( self::EVENTS_LOG, array() ), 'is_array' ) );
	}
}
