<?php
/**
 * Testing, saving and removing the connection.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Connection;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\BaseUrl;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Api\Resources\Company;
use InvoiceNinja\Api\Resources\CompanyGateways;
use InvoiceNinja\Core\Logger;

/**
 * The connection test runs the same six checks the setup wizard shows:
 * server found, secure connection, token accepted, admin access, version, instant updates.
 */
final class ConnectionService {

	public const CHECKS = array(
		'server'   => 'Server found',
		'tls'      => 'Secure connection',
		'token'    => 'Token accepted',
		'admin'    => 'Admin access',
		'version'  => 'Invoice Ninja version',
		'webhooks' => 'Instant updates',
	);

	/**
	 * Constructor.
	 *
	 * @param Credentials     $credentials Credentials.
	 * @param ConnectionState $state       State.
	 * @param Logger          $logger      Logger.
	 */
	public function __construct(
		private Credentials $credentials,
		private ConnectionState $state,
		private Logger $logger,
	) {}

	/**
	 * Test an address and token without saving anything.
	 *
	 * @param string $raw_url       Address as typed.
	 * @param string $token         Token.
	 * @param bool   $allow_private Allow a private host.
	 * @return array{ok: bool, url: string, checks: list<array{id: string, label: string, status: string, detail: string}>, error: array<string, mixed>|null, company: array<string, mixed>, version: string, admin: bool, webhooks: bool, gateways: list<string>, warnings: list<string>}
	 */
	public function test( string $raw_url, #[\SensitiveParameter] string $token, bool $allow_private = false ): array {
		$url    = BaseUrl::normalize( $raw_url ) ?? '';
		$result = array(
			'ok'       => false,
			'url'      => $url,
			'checks'   => array(),
			'error'    => null,
			'company'  => array(),
			'version'  => '',
			'admin'    => false,
			'webhooks' => false,
			'gateways' => array(),
			'warnings' => array(),
		);
		$status = array_fill_keys( array_keys( self::CHECKS ), array( 'skip', '' ) );
		$host   = '' !== $url ? BaseUrl::host( $url ) : '';

		try {
			if ( '' === $url ) {
				throw new ApiException( ErrorKind::Dns, 'not a valid web address' );
			}
			if ( '' === trim( $token ) ) {
				throw new ApiException( ErrorKind::Auth, 'no token entered' );
			}
			$client  = new HttpClient( $url, trim( $token ), $allow_private );
			$company = Company::current( $client, 15.0 );

			$status['server'] = array( 'pass', $host );
			$status['tls']    = str_starts_with( $url, 'https://' )
				? array( 'pass', __( 'Encrypted with a valid certificate', 'invoiceninja' ) )
				: array( 'warn', __( 'Not encrypted: the token is sent in plain text. Use https if you can.', 'invoiceninja' ) );
			$status['token']  = array( 'pass', (string) $company['name'] );

			$version = $client->appVersion();
			if ( '' === $version ) {
				throw new ApiException( ErrorKind::InvalidResponse, 'no X-APP-VERSION header' );
			}
			if ( ! HttpClient::versionAtLeast( $version ) ) {
				$status['version'] = array( 'fail', $version );
				throw new ApiException( ErrorKind::VersionTooOld, sprintf( 'X-APP-VERSION %s, minimum %s', $version, HttpClient::MIN_VERSION ) );
			}
			/* translators: 1: installed version, 2: minimum version */
			$status['version'] = array( 'pass', sprintf( __( '%1$s (needs %2$s or newer)', 'invoiceninja' ), $version, HttpClient::MIN_VERSION ) );

			// Only admins with API access may create webhooks, so an invalid create is a safe probe:
			// 422 means allowed; "This action is unauthorized." (401, or 403) means not an admin
			// (the token itself just worked for companies/current). Nothing is created either way.
			$admin = false;
			try {
				$client->post( 'webhooks', array(), array(), 10.0 );
				$admin = true; // Should not happen with an empty body, but means it's allowed.
			} catch ( ApiException $probe ) {
				if ( ErrorKind::Validation === $probe->kind ) {
					$admin = true;
				} elseif ( ErrorKind::Forbidden !== $probe->kind ) {
					throw $probe;
				}
			}
			if ( ! $admin ) {
				$status['admin'] = array( 'fail', __( 'Not an admin', 'invoiceninja' ) );
				throw new ApiException( ErrorKind::NonAdmin, 'POST /webhooks → ' . $probe->getMessage() );
			}
			$status['admin']    = array( 'pass', __( 'Can see every product and client', 'invoiceninja' ) );
			$status['webhooks'] = array( 'pass', __( 'Changes in Invoice Ninja reach this site within seconds', 'invoiceninja' ) );

			$gateways = array();
			try {
				$gateways = CompanyGateways::activeLabels( $client );
			} catch ( ApiException $ignored ) {
				$result['warnings'][] = 'gateways_unknown';
			}
			if ( ! $gateways ) {
				$result['warnings'][] = 'no_gateway';
			}
			if ( ! empty( $company['portal_password'] ) ) {
				$result['warnings'][] = 'portal_password';
			}

			$result = array_merge(
				$result,
				array(
					'ok'       => true,
					'company'  => $company,
					'version'  => $version,
					'admin'    => true,
					'webhooks' => true,
					'gateways' => $gateways,
				)
			);
		} catch ( ApiException $e ) {
			$failed = match ( $e->kind ) {
				ErrorKind::Tls                                   => 'tls',
				ErrorKind::Auth, ErrorKind::PlanRequired, ErrorKind::Forbidden => 'token',
				ErrorKind::NonAdmin                              => 'admin',
				ErrorKind::VersionTooOld                         => 'version',
				default                                          => 'server',
			};
			if ( 'tls' === $failed || 'token' === $failed ) {
				$status['server'] = array( 'pass', $host );
			}
			if ( 'token' === $failed ) {
				$status['tls'] = array( str_starts_with( $url, 'https://' ) ? 'pass' : 'warn', '' );
			}
			if ( 'admin' !== $failed && 'version' !== $failed ) {
				$status[ $failed ] = array( 'fail', $e->kind->title( $host ) );
			}
			$result['error'] = self::describe( $e, $host );
		}

		foreach ( array_keys( self::CHECKS ) as $id ) {
			$result['checks'][] = array(
				'id'     => $id,
				'label'  => self::label( $id ),
				'status' => $status[ $id ][0],
				'detail' => $status[ $id ][1],
			);
		}
		return $result;
	}

	/**
	 * Test, then save if it passed. A different company than before needs confirmation.
	 *
	 * @param string $raw_url        Address.
	 * @param string $token          Token.
	 * @param bool   $allow_private  Allow a private host.
	 * @param bool   $confirm_switch The admin confirmed switching companies.
	 * @return array<string, mixed> The test result, plus `saved` and `needs_switch_confirmation`.
	 */
	public function connect( string $raw_url, #[\SensitiveParameter] string $token, bool $allow_private = false, bool $confirm_switch = false ): array {
		$result          = $this->test( $raw_url, $token, $allow_private );
		$result['saved'] = false;
		if ( $this->credentials->locked() ) {
			$result['error'] = self::describe( new ApiException( ErrorKind::Locked ) );
			return $result;
		}
		if ( ! $result['ok'] ) {
			$this->logger->warning( 'connection', 'Connection test failed: ' . (string) ( $result['error']['title'] ?? '' ), array( 'kind' => $result['error']['kind'] ?? '' ) );
			return $result;
		}
		$previous = $this->state->get();
		$old_id   = (string) ( $previous['company']['id'] ?? '' );
		if ( '' !== $old_id && $old_id !== (string) $result['company']['id'] && ! $confirm_switch ) {
			$result['needs_switch_confirmation'] = array(
				'from' => (string) ( $previous['company']['name'] ?? '' ),
				'to'   => (string) $result['company']['name'],
			);
			return $result;
		}
		$this->credentials->save( $result['url'], trim( $token ) );
		$this->state->markConnected( $result['company'], $result['version'], true, true, $result['gateways'], $allow_private ? BaseUrl::host( $result['url'] ) : '' );
		$result['saved'] = true;
		$this->logger->info( 'connection', sprintf( 'Connected to %s (Invoice Ninja %s)', (string) $result['company']['name'], $result['version'] ) );
		if ( '' !== $old_id && $old_id !== (string) $result['company']['id'] ) {
			/**
			 * Fires after the site switched to a different Invoice Ninja company.
			 *
			 * @since 2.0.0
			 * @param string $old_id Previous company id.
			 * @param array  $company New company summary.
			 */
			do_action( 'invoiceninja_company_switched', $old_id, $result['company'] );
		}
		/**
		 * Fires after a successful connect.
		 *
		 * @since 2.0.0
		 * @param array $company Company summary.
		 */
		do_action( 'invoiceninja_connected', $result['company'] );
		return $result;
	}

	/**
	 * Check the saved connection in the background. A site that has credentials but never
	 * connected through 2.0 (upgraded from 1.x, or set in wp-config.php) is tested in full and
	 * connected, as the setup wizard would; a known company is only refreshed.
	 *
	 * @return string connected (newly), refreshed, failed or none (nothing saved).
	 */
	public function resume(): string {
		if ( 'none' === $this->credentials->source() ) {
			return 'none';
		}
		$state = $this->state->get();
		if ( '' !== (string) ( $state['company']['id'] ?? '' ) ) {
			return $this->refresh() ? 'refreshed' : 'failed';
		}
		$token = $this->credentials->token();
		if ( null === $token || '' === $token ) {
			$this->state->markError( new ApiException( null === $token ? ErrorKind::TokenUnreadable : ErrorKind::NotConfigured ) );
			return 'failed';
		}
		$url    = $this->credentials->url();
		$allow  = $this->state->allowsPrivate( $url );
		$result = $this->test( $url, $token, $allow );
		if ( ! $result['ok'] ) {
			$kind = ErrorKind::tryFrom( (string) ( $result['error']['kind'] ?? '' ) ) ?? ErrorKind::Transport;
			$this->state->markError( new ApiException( $kind, (string) ( $result['error']['technical'] ?? '' ) ) );
			$this->logger->warning( 'connection', 'Connecting with the saved token failed: ' . $kind->title( BaseUrl::host( $url ) ), array( 'technical' => (string) ( $result['error']['technical'] ?? '' ) ) );
			return 'failed';
		}
		$this->state->markConnected( $result['company'], $result['version'], true, true, $result['gateways'], $allow ? BaseUrl::host( $url ) : '' );
		$this->logger->info( 'connection', sprintf( 'Connected to %s with the saved token (Invoice Ninja %s)', (string) $result['company']['name'], $result['version'] ) );
		/** This action is documented in src/Connection/ConnectionService.php */
		do_action( 'invoiceninja_connected', $result['company'] );
		return 'connected';
	}

	/**
	 * Re-check the saved connection and refresh the company details.
	 *
	 * @return bool Whether it's healthy.
	 */
	public function refresh(): bool {
		$state = $this->state->get();
		try {
			$client  = $this->credentials->client( $this->state->allowsPrivate( $this->credentials->url() ) );
			$company = Company::current( $client, 10.0 );
			$this->guardCompany( $state, $company );
			$version  = $client->appVersion();
			$gateways = CompanyGateways::activeLabels( $client );
			if ( ! HttpClient::versionAtLeast( $version ) ) {
				throw new ApiException( ErrorKind::VersionTooOld, 'X-APP-VERSION ' . $version );
			}
			$this->state->markConnected( $company, $version, $state['admin'], $state['webhooks'], $gateways, $state['private_host'] );
			return true;
		} catch ( ApiException $e ) {
			$this->state->markError( $e );
			$this->logger->warning( 'connection', 'Refreshing the connection failed: ' . $e->kind->title(), array( 'technical' => $e->technical ) );
			return false;
		}
	}

	/**
	 * Refuse a silent company switch (e.g. a token swapped in wp-config.php).
	 *
	 * @param array<string, mixed> $state   Current state.
	 * @param array<string, mixed> $company Company the token now returns.
	 * @throws ApiException When the company changed.
	 */
	public function guardCompany( array $state, array $company ): void {
		$old = (string) ( $state['company']['id'] ?? '' );
		if ( '' !== $old && (string) ( $company['id'] ?? '' ) !== $old ) {
			throw new ApiException( ErrorKind::CompanyChanged, sprintf( 'connected to %s, token returns %s', $old, (string) ( $company['id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
		}
	}

	/**
	 * Remove the token and stop syncing.
	 */
	public function disconnect(): void {
		/**
		 * Fires before disconnecting, while the token still works: modules undo what they set
		 * up in Invoice Ninja (webhooks, subscription settings).
		 *
		 * @since 2.0.0
		 */
		do_action( 'invoiceninja_before_disconnect' );
		$this->credentials->clearToken();
		$this->state->markDisconnected();
		$this->logger->info( 'connection', 'Disconnected from Invoice Ninja' );
		/**
		 * Fires after disconnecting.
		 *
		 * @since 2.0.0
		 */
		do_action( 'invoiceninja_disconnected' );
	}

	/**
	 * Translated check label.
	 *
	 * @param string $id Check id.
	 */
	public static function label( string $id ): string {
		return match ( $id ) {
			'server'   => __( 'Server found', 'invoiceninja' ),
			'tls'      => __( 'Secure connection', 'invoiceninja' ),
			'token'    => __( 'Token accepted', 'invoiceninja' ),
			'admin'    => __( 'Admin access', 'invoiceninja' ),
			'version'  => __( 'Invoice Ninja version', 'invoiceninja' ),
			'webhooks' => __( 'Instant updates', 'invoiceninja' ),
			default    => $id,
		};
	}

	/**
	 * Plain-language description of an error, for the UI.
	 *
	 * @param ApiException $e    Error.
	 * @param string       $host Host.
	 * @return array{kind: string, title: string, fix: string, technical: string, location: string}
	 */
	public static function describe( ApiException $e, string $host = '' ): array {
		return array(
			'kind'      => $e->kind->value,
			'title'     => $e->kind->title( $host ),
			'fix'       => $e->kind->fix(),
			'technical' => $e->technical,
			'location'  => $e->location,
		);
	}
}
