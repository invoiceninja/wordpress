<?php
/**
 * Background jobs on Action Scheduler.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

use InvoiceNinja\Api\ApiException;

/**
 * Jobs are Action Scheduler actions in the `invoiceninja` group. A job is a hook name
 * plus one array argument; handlers registered with handle() get the payload and the
 * attempt number, and are retried by RetryPolicy when they throw a retryable error.
 */
final class Queue {

	public const GROUP = 'invoiceninja';

	/**
	 * Option holding the jobs set aside while the plugin is deactivated.
	 */
	public const PARKED = 'invoiceninja_parked_jobs';

	/**
	 * Sites eachSite() visits at most (network activation and deactivation must finish).
	 */
	public const MAX_SITES = 500;

	/**
	 * Autoloaded option remembering which recurring jobs were checked lately, so the check
	 * (two Action Scheduler queries per job) runs about once an hour, not on every request.
	 */
	public const CHECKED = 'invoiceninja_recurring_checked';

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( private Logger $logger ) {}

	/**
	 * Whether Action Scheduler is loaded.
	 */
	public static function available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Queue a job.
	 *
	 * @param string       $hook    Hook name, e.g. invoiceninja_sync_products.
	 * @param array<mixed> $payload Payload (stored as JSON; no secrets).
	 * @param int          $delay   Seconds from now.
	 * @param bool         $unique  Skip if the same job is already waiting to run (a running job may queue its own follow-up).
	 * @param int          $attempt Attempt number.
	 */
	public function push( string $hook, array $payload = array(), int $delay = 0, bool $unique = true, int $attempt = 1 ): int {
		if ( ! self::available() ) {
			return 0;
		}
		$args = array(
			array(
				'payload' => $payload,
				'attempt' => $attempt,
			),
		);
		if ( $unique && self::pending( $hook, $args ) ) {
			return 0;
		}
		return $delay > 0
			? (int) as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP )
			: (int) as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Whether the same job is waiting to run. Action Scheduler's own check also counts the
	 * job that's running now, which would stop a job from scheduling its next round.
	 *
	 * @param string       $hook Hook.
	 * @param array<mixed> $args Args.
	 */
	private static function pending( string $hook, array $args ): bool {
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => 'pending', // ActionScheduler_Store::STATUS_PENDING.
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);
	}

	/**
	 * Schedule a recurring job once. Checked at most hourly unless $verify.
	 *
	 * @param string $hook     Hook.
	 * @param int    $interval Seconds.
	 * @param bool   $verify   Check now (Repair).
	 */
	public function recurring( string $hook, int $interval, bool $verify = false ): void {
		if ( ! self::available() || ( ! $verify && self::checkedLately( $hook, true ) ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( $hook, null, self::GROUP ) ) {
			as_schedule_recurring_action( time() + wp_rand( 60, 600 ), $interval, $hook, array(), self::GROUP );
		}
		self::remember( $hook, true );
	}

	/**
	 * Remove a recurring job (a feature was turned off). Checked at most hourly.
	 *
	 * @param string $hook Hook.
	 */
	public function stopRecurring( string $hook ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || self::checkedLately( $hook, false ) ) {
			return;
		}
		if ( as_has_scheduled_action( $hook, null, self::GROUP ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
		self::remember( $hook, false );
	}

	/**
	 * Whether the job was found scheduled ($on) or not within the last hour.
	 *
	 * @param string $hook Hook.
	 * @param bool   $on   Scheduled.
	 */
	private static function checkedLately( string $hook, bool $on ): bool {
		$checked = get_option( self::CHECKED, array() );
		$entry   = is_array( $checked ) && is_array( $checked[ $hook ] ?? null ) ? $checked[ $hook ] : null;
		return null !== $entry && (bool) ( $entry['on'] ?? false ) === $on && (int) ( $entry['at'] ?? 0 ) > time() - HOUR_IN_SECONDS;
	}

	/**
	 * Remember a check.
	 *
	 * @param string $hook Hook.
	 * @param bool   $on   Scheduled.
	 */
	private static function remember( string $hook, bool $on ): void {
		$checked          = get_option( self::CHECKED, array() );
		$checked          = is_array( $checked ) ? $checked : array();
		$checked[ $hook ] = array(
			'on' => $on,
			'at' => time(),
		);
		update_option( self::CHECKED, $checked, true );
	}

	/**
	 * Register a handler. The handler gets the payload and the attempt number.
	 * Retryable API errors are scheduled again; anything else is logged once.
	 *
	 * @param string   $hook    Hook.
	 * @param callable $handler function( array $payload, int $attempt ): void.
	 */
	public function handle( string $hook, callable $handler ): void {
		add_action(
			$hook,
			function ( $job = array() ) use ( $hook, $handler ): void {
				$job     = is_array( $job ) ? $job : array();
				$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
				$attempt = max( 1, (int) ( $job['attempt'] ?? 1 ) );
				try {
					$handler( $payload, $attempt );
				} catch ( ApiException $e ) {
					$delay = $e->kind->retryable() ? RetryPolicy::delay( $attempt ) : null;
					if ( null !== $delay ) {
						$this->push( $hook, $payload, $e->retryAfter ?? $delay, false, $attempt + 1 );
						$this->logger->warning( 'queue', sprintf( '%s failed (attempt %d), will retry: %s', $hook, $attempt, $e->getMessage() ) );
					} else {
						$this->logger->error( 'queue', sprintf( '%s failed: %s', $hook, $e->getMessage() ), array( 'kind' => $e->kind->value ) );
						do_action( 'invoiceninja_job_failed', $hook, $payload, $e );
					}
				} catch ( \Throwable $e ) {
					$this->logger->error( 'queue', sprintf( '%s failed: %s', $hook, $e->getMessage() ), array( 'exception' => get_class( $e ) ) );
					do_action( 'invoiceninja_job_failed', $hook, $payload, $e );
				}
			}
		);
	}

	/**
	 * Whether a job for this payload is waiting to run, at any attempt.
	 *
	 * @param string       $hook    Hook.
	 * @param array<mixed> $payload Payload.
	 */
	public static function waiting( string $hook, array $payload ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return true; // Can't tell: don't queue it twice.
		}
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => 'pending',
				// Jobs are stored with their payload first, then the attempt number. Matching the
				// whole payload object means order 12 never matches order 120.
				'search'   => '"payload":' . wp_json_encode( $payload ) . ',',
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);
	}

	/**
	 * Remove every pending job (uninstall).
	 */
	public static function clear(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		delete_option( self::PARKED );
		delete_option( self::CHECKED );
	}

	/**
	 * Deactivation: set the one-off jobs aside (queued orders, WooCommerce invoices, emails)
	 * and remove every job. Another plugin's Action Scheduler would otherwise run them with
	 * no handler and mark them failed. Recurring jobs are scheduled again on the next load.
	 */
	public static function park(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}
		$parked = array();
		foreach ( (array) as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1000,
			),
			'objects'
		) as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_schedule' ) ) {
				continue;
			}
			$schedule = $action->get_schedule();
			if ( method_exists( $schedule, 'is_recurring' ) && $schedule->is_recurring() ) {
				continue;
			}
			$date     = method_exists( $schedule, 'get_date' ) ? $schedule->get_date() : null;
			$parked[] = array(
				'hook' => (string) $action->get_hook(),
				'args' => (array) $action->get_args(),
				'at'   => $date ? $date->getTimestamp() : 0,
			);
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		delete_option( self::CHECKED ); // Recurring jobs are scheduled again on the next load.
		if ( $parked ) {
			update_option( self::PARKED, self::mergeParked( (array) get_option( self::PARKED, array() ), $parked ), false );
		}
	}

	/**
	 * Run $work on this site, or on every site of the network (up to MAX_SITES, the
	 * most recently updated first) when $network is true.
	 *
	 * @param bool     $network Every site of the network.
	 * @param callable $work    function(): void.
	 */
	public static function eachSite( bool $network, callable $work ): void {
		if ( ! $network || ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			$work();
			return;
		}
		foreach ( get_sites(
			array(
				'fields'   => 'ids',
				'number'   => self::MAX_SITES,
				'orderby'  => 'last_updated',
				'order'    => 'DESC',
				'archived' => 0,
				'deleted'  => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				$work();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Put parked jobs back (after reactivation, from a one-off cron event so Action
	 * Scheduler is ready; the daily sweep also re-queues any queued order without a job).
	 */
	public static function restore(): void {
		$parked = get_option( self::PARKED, array() );
		if ( ! is_array( $parked ) || ! $parked || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		delete_option( self::PARKED );
		foreach ( $parked as $job ) {
			if ( ! is_array( $job ) || ! is_string( $job['hook'] ?? null ) || ! str_starts_with( $job['hook'], 'invoiceninja_' ) ) {
				continue;
			}
			as_schedule_single_action( max( time(), (int) ( $job['at'] ?? 0 ) ), $job['hook'], (array) ( $job['args'] ?? array() ), self::GROUP );
		}
	}

	/**
	 * Add newly parked jobs to those already parked, without duplicates (deactivating twice).
	 *
	 * @param array<mixed>       $before Already parked.
	 * @param list<array<mixed>> $added  Just parked.
	 * @return list<array<mixed>>
	 */
	public static function mergeParked( array $before, array $added ): array {
		$all = array();
		foreach ( array_merge( $before, $added ) as $job ) {
			if ( is_array( $job ) ) {
				$all[ md5( (string) wp_json_encode( array( $job['hook'] ?? '', $job['args'] ?? array() ) ) ) ] = $job;
			}
		}
		return array_slice( array_values( $all ), -1000 );
	}

	/**
	 * Pending job count and the last time any job ran, for diagnostics.
	 *
	 * @return array{pending: int, past_due: int, last_run: int}
	 */
	public static function stats(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array(
				'pending'  => 0,
				'past_due' => 0,
				'last_run' => 0,
			);
		}
		$pending  = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 200,
			),
			'ids'
		);
		$past_due = as_get_scheduled_actions(
			array(
				'group'        => self::GROUP,
				'status'       => 'pending',
				'date'         => gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ),
				'date_compare' => '<=',
				'per_page'     => 200,
			),
			'ids'
		);
		$done     = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'complete',
				'per_page' => 1,
				'orderby'  => 'date',
				'order'    => 'DESC',
			),
			'objects'
		);
		$last     = 0;
		foreach ( $done as $action ) {
			$date = $action->get_schedule()->get_date();
			$last = $date ? $date->getTimestamp() : 0;
		}
		return array(
			'pending'  => count( $pending ),
			'past_due' => count( $past_due ),
			'last_run' => $last,
		);
	}
}
