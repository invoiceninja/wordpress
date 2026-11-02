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
	 * Schedule a recurring job once.
	 *
	 * @param string $hook     Hook.
	 * @param int    $interval Seconds.
	 */
	public function recurring( string $hook, int $interval ): void {
		if ( self::available() && ! as_has_scheduled_action( $hook, null, self::GROUP ) ) {
			as_schedule_recurring_action( time() + wp_rand( 60, 600 ), $interval, $hook, array(), self::GROUP );
		}
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
	 * Remove every pending job (deactivation and uninstall).
	 */
	public static function clear(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
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
