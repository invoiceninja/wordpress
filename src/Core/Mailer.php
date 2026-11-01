<?php
/**
 * Sends the plugin's emails.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

/**
 * Uses wp_mail so SMTP plugins apply. With WooCommerce active, messages are wrapped
 * in WooCommerce's email template; otherwise in a minimal accessible layout.
 */
final class Mailer {

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * Send an email.
	 *
	 * @param string|list<string> $to      Recipients.
	 * @param string              $subject Subject.
	 * @param string              $html    Body (trusted HTML built by the plugin).
	 * @param string              $heading Heading inside the template.
	 */
	public function send( string|array $to, string $subject, string $html, string $heading = '' ): bool {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$email   = (array) $this->options->setting( 'email' );
		$name    = '' !== (string) ( $email['sender_name'] ?? '' ) ? (string) $email['sender_name'] : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$from    = sanitize_email( (string) ( $email['from'] ?? '' ) );
		if ( '' !== $from ) {
			$headers[] = sprintf( 'From: %s <%s>', str_replace( array( "\r", "\n", '<', '>' ), '', $name ), $from );
		}
		$reply = sanitize_email( (string) ( $email['reply_to'] ?? '' ) );
		if ( '' !== $reply ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		return (bool) wp_mail( $to, $subject, $this->wrap( '' !== $heading ? $heading : $subject, $html ), $headers );
	}

	/**
	 * Send the setup test email and remember it, so Home can ask whether it arrived.
	 *
	 * @param string $to Recipient.
	 */
	public function sendTest( string $to ): bool {
		$to   = sanitize_email( $to );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$sent = '' !== $to && $this->send(
			$to,
			/* translators: %s: site name */
			sprintf( __( 'Test email from %s', 'invoiceninja' ), $site ),
			'<p>' . esc_html__( 'It works. Shoppers will get their codes and payment links the same way.', 'invoiceninja' ) . '</p><p>' . esc_html__( "If you're in setup, go back and answer “Yes”.", 'invoiceninja' ) . '</p>'
		);
		$this->options->set(
			'mail_test',
			array(
				'to'        => $to,
				'at'        => time(),
				'sent'      => $sent,
				'confirmed' => null,
			)
		);
		return $sent;
	}

	/**
	 * Record whether the admin received the test email.
	 *
	 * @param bool $arrived Answer.
	 */
	public function confirmTest( bool $arrived ): void {
		$test              = (array) $this->options->get( 'mail_test', array() );
		$test['confirmed'] = $arrived;
		$this->options->set( 'mail_test', $test );
	}

	/**
	 * Last test result.
	 *
	 * @return array{to: string, at: int, sent: bool, confirmed: bool|null}|null
	 */
	public function lastTest(): ?array {
		$test = $this->options->get( 'mail_test' );
		if ( ! is_array( $test ) || empty( $test['at'] ) ) {
			return null;
		}
		return array(
			'to'        => (string) ( $test['to'] ?? '' ),
			'at'        => (int) $test['at'],
			'sent'      => (bool) ( $test['sent'] ?? false ),
			'confirmed' => isset( $test['confirmed'] ) ? (bool) $test['confirmed'] : null,
		);
	}

	/**
	 * Wrap a body in a template.
	 *
	 * @param string $heading Heading.
	 * @param string $html    Body.
	 */
	private function wrap( string $heading, string $html ): string {
		if ( function_exists( 'WC' ) && method_exists( WC(), 'mailer' ) ) {
			$mailer = WC()->mailer();
			if ( is_object( $mailer ) && method_exists( $mailer, 'wrap_message' ) ) {
				return (string) $mailer->wrap_message( $heading, $html );
			}
		}
		$site = esc_html( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return '<!doctype html><html><body style="margin:0;padding:24px;background:#f6f7f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1d2327;font-size:15px;line-height:1.55">'
			. '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dcdcde;padding:28px">'
			. '<p style="margin:0 0 16px;font-weight:600">' . $site . '</p>'
			. $html
			. '<p style="margin:24px 0 0;padding-top:12px;border-top:1px solid #f0f0f1;color:#50575e;font-size:12px">' . $site . ' · ' . esc_html( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) ) . '</p>'
			. '</div></body></html>';
	}
}
