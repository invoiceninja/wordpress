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
	 * Whether wp-config.php sets the connection.
	 */
	public function locked(): bool {
		return defined( 'INVOICENINJA_API_URL' ) || defined( 'INVOICENINJA_API_TOKEN' );
	}

	/**
	 * Where the credentials come from: constants, options or none.
	 */
	public function source(): string {
		if ( $this->locked() ) {
			return 'constants';
		}
		return '' !== $this->url() && '' !== (string) $this->options->get( 'api_token', '' ) ? 'options' : 'none';
	}

	/**
	 * The normalised base URL, or '' when unset.
	 */
	public function url(): string {
		$raw = defined( 'INVOICENINJA_API_URL' ) ? (string) constant( 'INVOICENINJA_API_URL' ) : (string) $this->options->get( 'api_url', '' );
		if ( '' === trim( $raw ) ) {
			return '';
		}
		return BaseUrl::normalize( $raw ) ?? '';
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
		return Secrets::isEnvelope( $stored ) ? $this->secrets->decrypt( $stored ) : $stored;
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
		if ( $this->locked() ) {
			return;
		}
		$this->options->set( 'api_url', $url, true );
		$this->options->set( 'api_token', $this->secrets->encrypt( $token ) );
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
			throw new ApiException( ErrorKind::TokenUnreadable );
		}
		if ( '' === $this->url() || '' === $token ) {
			throw new ApiException( ErrorKind::NotConfigured );
		}
		return new HttpClient( $this->url(), $token, $allow_private );
	}
}
