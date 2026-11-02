<?php
/**
 * Six-digit email codes that prove someone owns an email address.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Verification;

use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Mailer;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;

/**
 * Used at guest checkout and to confirm a customer's email for the billing portal.
 * - Codes are stored only as keyed hashes, expire after 10 minutes and allow 5 attempts.
 * - A new code can be requested after 60 seconds; at most 5 codes per email and 20 per
 *   IP address an hour.
 * - The caller gives the same response whether or not the email belongs to a client,
 *   so codes can't be used to find out who is a customer.
 */
final class EmailCode {

	public const TTL = 600;

	public const MAX_ATTEMPTS = 5;

	public const RESEND_AFTER = 60;

	public const PER_EMAIL_HOUR = 5;

	public const PER_IP_HOUR = 20;

	public const GUESSES_PER_EMAIL_HOUR = 10;

	public const GUESSES_PER_IP_HOUR = 30;

	/**
	 * Constructor.
	 *
	 * @param Secrets $secrets Secrets.
	 * @param Mailer  $mailer  Mailer.
	 * @param Logger  $logger  Logger.
	 * @param Options $options Options (atomic counters).
	 */
	public function __construct( private Secrets $secrets, private Mailer $mailer, private Logger $logger, private Options $options ) {}

	/**
	 * Send a code.
	 *
	 * @param string $email   Email address.
	 * @param string $ip      Requester IP (for rate limits).
	 * @param string $purpose checkout or portal.
	 * @return array{status: string, retry_after: int} status: sent, wait, limited, invalid or failed.
	 */
	public function send( string $email, string $ip, string $purpose = 'checkout' ): array {
		$email = self::normalize( $email );
		if ( ! is_email( $email ) ) {
			return array(
				'status'      => 'invalid',
				'retry_after' => 0,
			);
		}
		$key    = $this->key( $email, $purpose );
		$record = get_transient( 'invoiceninja_code_' . $key );
		if ( is_array( $record ) && (int) $record['sent_at'] > time() - self::RESEND_AFTER ) {
			return array(
				'status'      => 'wait',
				'retry_after' => (int) $record['sent_at'] + self::RESEND_AFTER - time(),
			);
		}
		// IP first, so a blocked IP doesn't use up the victim's hourly email allowance.
		if ( ! $this->allow( 'i' . $this->hash( $ip ), self::PER_IP_HOUR ) || ! $this->allow( 'e' . $key, self::PER_EMAIL_HOUR ) ) {
			$this->logger->warning( 'email', 'Email code rate limit reached' );
			return array(
				'status'      => 'limited',
				'retry_after' => HOUR_IN_SECONDS,
			);
		}

		$code = sprintf( '%06d', random_int( 0, 999999 ) );
		set_transient(
			'invoiceninja_code_' . $key,
			array(
				'hash'     => $this->hash( $code . '|' . $key ),
				'sent_at'  => time(),
				'attempts' => 0,
			),
			self::TTL
		);

		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$spaced = substr( $code, 0, 3 ) . ' ' . substr( $code, 3 );
		$intro  = 'portal' === $purpose
			? __( 'Enter it on your Account page to see your invoices.', 'invoiceninja' )
			: __( 'Enter it on the checkout page.', 'invoiceninja' );
		$body   = '<p>' . esc_html__( 'Your code is', 'invoiceninja' ) . '</p>'
			. '<p style="font-size:30px;font-weight:600;letter-spacing:.12em;margin:8px 0 16px">' . esc_html( $spaced ) . '</p>'
			. '<p>' . esc_html( $intro ) . ' ' . esc_html__( 'It expires in 10 minutes.', 'invoiceninja' ) . '</p>'
			/* translators: %s: site address */
			. '<p style="color:#50575e;font-size:13px">' . esc_html( sprintf( __( "Requested on %s. Didn't ask for this? Ignore this email. We never ask for this code by phone or email.", 'invoiceninja' ), (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) ) ) . '</p>';
		/* translators: 1: code, 2: site name */
		$sent = $this->mailer->send( $email, sprintf( __( '%1$s is your %2$s code', 'invoiceninja' ), $code, $site ), $body );
		if ( ! $sent ) {
			delete_transient( 'invoiceninja_code_' . $key );
			$this->logger->error( 'email', 'Sending an email code failed' );
			return array(
				'status'      => 'failed',
				'retry_after' => 0,
			);
		}
		return array(
			'status'      => 'sent',
			'retry_after' => self::RESEND_AFTER,
		);
	}

	/**
	 * Check a code. Succeeds once; the code is then used up.
	 *
	 * @param string $email   Email.
	 * @param string $code    Code as typed (spaces allowed).
	 * @param string $purpose checkout or portal.
	 * @param string $ip      Requester IP.
	 * @return string ok, wrong, expired or locked.
	 */
	public function verify( string $email, string $code, string $purpose = 'checkout', string $ip = '' ): string {
		$key = $this->key( self::normalize( $email ), $purpose );
		// Atomic caps: parallel guesses each take a number, so racing requests can't share one attempt.
		$hour = (int) floor( time() / HOUR_IN_SECONDS );
		if ( $this->options->increment( 'ctr_guess_' . substr( $key, 0, 20 ) . '_' . $hour ) > self::GUESSES_PER_EMAIL_HOUR
			|| ( '' !== $ip && $this->options->increment( 'ctr_guessip_' . substr( $this->hash( $ip ), 0, 20 ) . '_' . $hour ) > self::GUESSES_PER_IP_HOUR ) ) {
			return 'locked';
		}
		$name   = 'invoiceninja_code_' . $key;
		$record = get_transient( $name );
		if ( ! is_array( $record ) ) {
			return 'expired';
		}
		if ( (int) $record['attempts'] >= self::MAX_ATTEMPTS ) {
			return 'locked';
		}
		$code = preg_replace( '/\D/', '', $code ) ?? '';
		if ( 6 === strlen( $code ) && hash_equals( (string) $record['hash'], $this->hash( $code . '|' . $key ) ) ) {
			delete_transient( $name );
			return 'ok';
		}
		++$record['attempts'];
		$left = self::TTL - ( time() - (int) $record['sent_at'] );
		if ( $left > 0 ) {
			set_transient( $name, $record, $left );
		}
		return $record['attempts'] >= self::MAX_ATTEMPTS ? 'locked' : 'wrong';
	}

	/**
	 * Lower-case and trim an email.
	 *
	 * @param string $email Email.
	 */
	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Count an action against an hourly limit (atomically).
	 *
	 * @param string $bucket Bucket.
	 * @param int    $limit  Limit.
	 */
	private function allow( string $bucket, int $limit ): bool {
		return $this->options->increment( 'ctr_send_' . substr( $bucket, 0, 24 ) . '_' . (int) floor( time() / HOUR_IN_SECONDS ) ) <= $limit;
	}

	/**
	 * Key for an email and purpose.
	 *
	 * @param string $email   Normalised email.
	 * @param string $purpose Purpose.
	 */
	private function key( string $email, string $purpose ): string {
		return substr( $this->hash( $purpose . '|' . $email ), 0, 40 );
	}

	/**
	 * Keyed hash.
	 *
	 * @param string $value Value.
	 */
	private function hash( string $value ): string {
		return hash_hmac( 'sha256', $value, $this->secrets->get( 'device_secret' ) );
	}
}
