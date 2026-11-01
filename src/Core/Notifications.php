<?php
/**
 * Emails to the site's admins about problems.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

use InvoiceNinja\Api\ErrorKind;

/**
 * One email when an incident starts and one when it ends; failed background jobs are
 * batched to at most one email an hour.
 */
final class Notifications {

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 * @param Mailer  $mailer  Mailer.
	 * @param Logger  $logger  Logger.
	 */
	public function __construct( private Options $options, private Mailer $mailer, private Logger $logger ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'invoiceninja_job_failed', array( $this, 'jobFailed' ), 10, 3 );
		add_action( 'invoiceninja_flush_failed_jobs', array( $this, 'flushFailedJobs' ) );
	}

	/**
	 * Who gets admin emails.
	 *
	 * @return list<string>
	 */
	public function recipients(): array {
		$list = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $this->options->setting( 'notifications.recipients' ) ) ) ) );
		return $list ? array_values( $list ) : array( (string) get_option( 'admin_email' ) );
	}

	/**
	 * The connection broke. Emails once per incident.
	 *
	 * @param array<string, mixed> $state Connection state.
	 */
	public function connectionLost( array $state ): void {
		if ( $this->options->get( 'incident_open' ) ) {
			return;
		}
		$this->options->set( 'incident_open', time() );
		if ( ! $this->options->setting( 'notifications.incidents' ) ) {
			return;
		}
		$kind  = ErrorKind::tryFrom( (string) ( $state['error']['kind'] ?? '' ) ) ?? ErrorKind::Transport;
		$link  = admin_url( 'admin.php?page=invoiceninja-status' );
		$body  = '<p>' . esc_html( $kind->title() ) . '.</p><p>' . esc_html( $kind->fix() ) . '</p>';
		$body .= '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;background:#2271b1;color:#ffffff;padding:10px 18px;border-radius:4px;text-decoration:none;font-weight:600">' . esc_html__( 'Open Status', 'invoiceninja' ) . '</a></p>';
		$this->mailer->send(
			$this->recipients(),
			/* translators: %s: problem */
			sprintf( __( 'Invoice Ninja connection problem: %s', 'invoiceninja' ), $kind->title() ),
			$body
		);
	}

	/**
	 * The connection works again. Closes the incident.
	 */
	public function connectionRecovered(): void {
		$opened = (int) $this->options->get( 'incident_open', 0 );
		if ( ! $opened ) {
			return;
		}
		$this->options->delete( 'incident_open' );
		$minutes = max( 1, (int) round( ( time() - $opened ) / 60 ) );
		$this->logger->info( 'connection', sprintf( 'Invoice Ninja reachable again after %d minutes', $minutes ) );
		if ( $this->options->setting( 'notifications.incidents' ) ) {
			$this->mailer->send(
				$this->recipients(),
				__( 'Invoice Ninja is back', 'invoiceninja' ),
				/* translators: %d: minutes */
				'<p>' . esc_html( sprintf( _n( 'The connection works again after %d minute.', 'The connection works again after %d minutes.', $minutes, 'invoiceninja' ), $minutes ) ) . '</p>'
			);
		}
	}

	/**
	 * Collect a failed job for the hourly email.
	 *
	 * @param string       $hook    Job hook.
	 * @param array<mixed> $payload Payload.
	 * @param \Throwable   $error   Error.
	 */
	public function jobFailed( string $hook, array $payload, \Throwable $error ): void {
		$pending   = (array) $this->options->get( 'failed_jobs', array() );
		$pending[] = array(
			'hook' => $hook,
			'at'   => time(),
			'msg'  => LogRedactor::text( $error->getMessage() ),
		);
		$this->options->set( 'failed_jobs', array_slice( $pending, -50 ) );
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'invoiceninja_flush_failed_jobs', null, Queue::GROUP ) ) {
			as_schedule_single_action( time() + HOUR_IN_SECONDS, 'invoiceninja_flush_failed_jobs', array(), Queue::GROUP );
		}
	}

	/**
	 * Send the batched failed-jobs email.
	 */
	public function flushFailedJobs(): void {
		$pending = (array) $this->options->get( 'failed_jobs', array() );
		$this->options->set( 'failed_jobs', array() );
		if ( ! $pending || ! $this->options->setting( 'notifications.failed_jobs' ) ) {
			return;
		}
		$items = '';
		foreach ( $pending as $job ) {
			$items .= '<li>' . esc_html( (string) ( $job['msg'] ?? '' ) ) . '</li>';
		}
		$count = count( $pending );
		$this->mailer->send(
			$this->recipients(),
			/* translators: %d: number of failed tasks */
			sprintf( _n( '%d background task failed in the last hour', '%d background tasks failed in the last hour', $count, 'invoiceninja' ), $count ),
			'<ul>' . $items . '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-status&tab=activity' ) ) . '">' . esc_html__( 'Review in Status', 'invoiceninja' ) . '</a></p>'
		);
	}
}
