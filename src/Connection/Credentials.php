<?php
/**
 * Where the Invoice Ninja address and token come from.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Connection;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\BaseUrl;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;

/**
 * Order of precedence: INVOICENINJA_API_URL / INVOICENINJA_API_TOKEN constants, then the
 * encrypted option, then a 1.x plain-text option (encrypted by the first migration).
 * The option names are the 1.x ones, so going back to 1.x keeps the address.
 */
final class Credentials {

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 * @param Secrets $secrets Secrets.
	 */
	public function __construct( private Options $options, private Secrets $secrets ) {}

	/**
	 * Whether wp-config.php sets the connection. Either constant locks it: with only one set,
	 * an address or token typed in Settings would be paired with the other, so the token
	 * from wp-config.php could be sent to an address an admin typed.
	 */
	public function locked(): bool {
		return defined( 'INVOICENINJA_API_URL' ) || defined( 'INVOICENINJA_API_TOKEN' );
	}

	/**
	 * Where the credentials come from: constants, options or none.
	 */
	public function source(): string {
		if ( defined( 'INVOICENINJA_API_TOKEN' ) || defined( 'INVOICENINJA_API_URL' ) ) {
			return '' !== $this->url() && $this->hasToken() ? 'constants' : 'none';
		}
		return '' !== $this->url() && '' !== (string) $this->options->get( 'api_token', '' ) ? 'options' : 'none';
	}

	/**
	 * The normalised base URL, or '' when unset. A token without an address means hosted
	 * Invoice Ninja: 1.x told hosted users to leave the address blank.
	 */
	public function url(): string {
		$raw = defined( 'INVOICENINJA_API_URL' ) ? (string) constant( 'INVOICENINJA_API_URL' ) : (string) $this->options->get( 'api_url', '' );
		if ( '' === trim( $raw ) ) {
			return $this->hasToken() ? BaseUrl::HOSTED : '';
		}
		return BaseUrl::normalize( $raw ) ?? '';
	}

	/**
	 * Whether the address comes from wp-config.php.
	 */
	public function urlFromConstant(): bool {
		return defined( 'INVOICENINJA_API_URL' ) && '' !== trim( (string) constant( 'INVOICENINJA_API_URL' ) );
	}

	/**
	 * The plain token. Null when it's stored but can't be decrypted (salts changed).
	 */
	public function token(): ?string {
		if ( defined( 'INVOICENINJA_API_TOKEN' ) ) {
			return (string) constant( 'INVOICENINJA_API_TOKEN' );
		}
		$stored = (string) $this->options->get( 'api_token', '' );
		if ( '' === $stored ) {
			return '';
		}
		if ( Secrets::isEnvelope( $stored ) ) {
			return $this->secrets->decrypt( $stored );
		}
		// A plain-text token: 1.x saved it, possibly again after a downgrade. Encrypt it now.
		try {
			$this->encryptLegacyToken();
		} catch ( \Throwable $e ) {
			unset( $e ); // Still usable in plain text; the next read tries again.
		}
		return $stored;
	}

	/**
	 * Whether a token is stored (readable or not).
	 */
	public function hasToken(): bool {
		return defined( 'INVOICENINJA_API_TOKEN' ) || '' !== (string) $this->options->get( 'api_token', '' );
	}

	/**
	 * Last four characters of the token, for display.
	 */
	public function tokenHint(): string {
		$token = $this->token();
		return null === $token || strlen( $token ) < 8 ? '' : substr( $token, -4 );
	}

	/**
	 * Save new credentials. The token is encrypted; constants can't be overwritten.
	 *
	 * @param string $url   Normalised URL.
	 * @param string $token Plain token.
	 */
	public function save( string $url, #[\SensitiveParameter] string $token ): void {
		if ( ! defined( 'INVOICENINJA_API_URL' ) ) {
			$this->options->set( 'api_url', $url, true );
		}
		if ( ! defined( 'INVOICENINJA_API_TOKEN' ) ) {
			$this->options->set( 'api_token', $this->secrets->encrypt( $token ) );
		}
	}

	/**
	 * Remove the token (the address stays, so reconnecting is easy).
	 */
	public function clearToken(): void {
		if ( ! defined( 'INVOICENINJA_API_TOKEN' ) ) {
			$this->options->set( 'api_token', '' );
		}
	}

	/**
	 * Encrypt a 1.x plain-text token in place. Returns true when something changed.
	 */
	public function encryptLegacyToken(): bool {
		$stored = (string) $this->options->get( 'api_token', '' );
		if ( '' === $stored || Secrets::isEnvelope( $stored ) ) {
			return false;
		}
		$this->options->set( 'api_token', $this->secrets->encrypt( $stored ) );
		return true;
	}

	/**
	 * An API client for the saved connection.
	 *
	 * @param bool $allow_private Whether the host may be private.
	 * @throws ApiException When not configured or the token can't be read.
	 */
	public function client( bool $allow_private = false ): HttpClient {
		$token = $this->token();
		if ( null === $token ) {
			throw new ApiException( ErrorKind::TokenUnreadable ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
		}
		if ( '' === $this->url() || '' === $token ) {
			throw new ApiException( ErrorKind::NotConfigured ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
		}
		return new HttpClient( $this->url(), $token, $allow_private );
	}
}
