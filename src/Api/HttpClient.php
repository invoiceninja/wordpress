<?php
/**
 * Talks to the Invoice Ninja v5 API.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api;

/**
 * Rules:
 * - every call has a time budget, and retries (idempotent calls only) stay inside it;
 * - redirects are never followed, so the token can't be forwarded to another host;
 * - certificates are always verified;
 * - private addresses are refused unless the admin allowed this host;
 * - the token never appears in exceptions, logs or return values.
 */
final class HttpClient {

	public const MIN_VERSION = '5.13.25';

	/**
	 * X-APP-VERSION from the last response.
	 *
	 * @var string
	 */
	private string $app_version = '';

	/**
	 * Server time from the last response's Date header.
	 *
	 * @var int
	 */
	private int $server_time = 0;

	/**
	 * Resolved private-host checks, per host.
	 *
	 * @var array<string, bool>
	 */
	private static array $private_hosts = array();

	/**
	 * Constructor.
	 *
	 * @param string $base          Normalised base URL.
	 * @param string $token         API token (plain text, kept in memory only).
	 * @param bool   $allow_private Whether this host may be on a private network.
	 */
	public function __construct(
		private string $base,
		#[\SensitiveParameter] private string $token,
		private bool $allow_private = false,
	) {}

	/**
	 * GET, retried on transient errors.
	 *
	 * @param string               $path   Path under /api/v1/.
	 * @param array<string, mixed> $query  Query string.
	 * @param float                $budget Seconds for the whole call including retries.
	 * @throws ApiException When the call fails.
	 */
	public function get( string $path, array $query = array(), float $budget = 15.0 ): Response {
		return $this->request( 'GET', $path, $query, null, $budget, true );
	}

	/**
	 * POST. Not retried unless $idempotent (the caller has made it safe to repeat).
	 *
	 * @param string               $path       Path.
	 * @param array<mixed>|null    $body       JSON body.
	 * @param array<string, mixed> $query      Query string.
	 * @param float                $budget     Seconds.
	 * @param bool                 $idempotent Safe to repeat.
	 * @throws ApiException When the call fails.
	 */
	public function post( string $path, ?array $body = null, array $query = array(), float $budget = 15.0, bool $idempotent = false ): Response {
		return $this->request( 'POST', $path, $query, $body, $budget, $idempotent );
	}

	/**
	 * PUT.
	 *
	 * @param string               $path   Path.
	 * @param array<mixed>         $body   Body.
	 * @param array<string, mixed> $query  Query.
	 * @param float                $budget Seconds.
	 * @throws ApiException When the call fails.
	 */
	public function put( string $path, array $body, array $query = array(), float $budget = 15.0 ): Response {
		return $this->request( 'PUT', $path, $query, $body, $budget, true );
	}

	/**
	 * DELETE.
	 *
	 * @param string $path   Path.
	 * @param float  $budget Seconds.
	 * @throws ApiException When the call fails.
	 */
	public function delete( string $path, float $budget = 15.0 ): Response {
		return $this->request( 'DELETE', $path, array(), null, $budget, true );
	}

	/**
	 * X-APP-VERSION seen on the last response.
	 */
	public function appVersion(): string {
		return $this->app_version;
	}

	/**
	 * Seconds Invoice Ninja's clock is ahead of ours (negative when behind), or null.
	 */
	public function clockSkew(): ?int {
		return $this->server_time > 0 ? $this->server_time - time() : null;
	}

	/**
	 * Whether a version string is at least the minimum.
	 *
	 * @param string $version Version.
	 * @param string $minimum Minimum.
	 */
	public static function versionAtLeast( string $version, string $minimum = self::MIN_VERSION ): bool {
		$version = ltrim( trim( $version ), 'vV' );
		return '' !== $version && version_compare( $version, $minimum, '>=' );
	}

	/**
	 * Run a request.
	 *
	 * @param string               $method     Method.
	 * @param string               $path       Path.
	 * @param array<string, mixed> $query      Query.
	 * @param array<mixed>|null    $body       Body.
	 * @param float                $budget     Seconds for all attempts.
	 * @param bool                 $idempotent Whether to retry transient failures.
	 * @throws ApiException When the call fails.
	 */
	public function request( string $method, string $path, array $query, ?array $body, float $budget, bool $idempotent ): Response {
		if ( '' === $this->base || '' === $this->token ) {
			throw new ApiException( ErrorKind::NotConfigured );
		}
		$this->guardPrivateHost();

		$url = BaseUrl::api( $this->base ) . ltrim( $path, '/' );
		if ( $query ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$deadline = microtime( true ) + $budget;
		$attempt  = 0;

		while ( true ) {
			++$attempt;
			$remaining = $deadline - microtime( true );
			if ( $remaining < 0.5 ) {
				throw new ApiException( ErrorKind::Timeout, sprintf( 'time budget of %.1fs used up', $budget ) );
			}
			try {
				return $this->once( $method, $url, $body, $remaining );
			} catch ( ApiException $e ) {
				$retry = $idempotent
					&& $attempt < 3
					&& in_array( $e->kind, array( ErrorKind::Timeout, ErrorKind::Transport, ErrorKind::Server, ErrorKind::RateLimited ), true );
				$wait  = $e->retryAfter ?? ( 0.5 * $attempt );
				if ( ! $retry || $deadline - microtime( true ) < $wait + 1.0 ) {
					throw $e;
				}
				usleep( (int) ( $wait * 1000000 ) );
			}
		}
	}

	/**
	 * One attempt.
	 *
	 * @param string            $method  Method.
	 * @param string            $url     URL.
	 * @param array<mixed>|null $body    Body.
	 * @param float             $timeout Seconds.
	 * @throws ApiException When the call fails.
	 */
	private function once( string $method, string $url, ?array $body, float $timeout ): Response {
		$headers = array(
			'X-API-TOKEN'       => $this->token,
			'X-Requested-With'  => 'XMLHttpRequest',
			'Accept'            => 'application/json',
			'X-CLIENT-PLATFORM' => 'WordPress',
			'X-CLIENT-VERSION'  => defined( 'INVOICENINJA_VERSION' ) ? INVOICENINJA_VERSION : '2',
		);
		$args    = array(
			'method'      => $method,
			'timeout'     => max( 1.0, $timeout ),
			'redirection' => 0,
			'sslverify'   => true,
			'httpversion' => '1.1',
			'user-agent'  => 'InvoiceNinja-WordPress/' . ( defined( 'INVOICENINJA_VERSION' ) ? INVOICENINJA_VERSION : '2' ) . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$raw = wp_remote_request( $url, $args );
		if ( is_wp_error( $raw ) ) {
			throw self::transportError( $raw->get_error_message() );
		}

		$status      = (int) wp_remote_retrieve_response_code( $raw );
		$headers     = array();
		$raw_headers = wp_remote_retrieve_headers( $raw );
		if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			$raw_headers = $raw_headers->getAll();
		}
		foreach ( (array) $raw_headers as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		if ( isset( $headers['x-app-version'] ) ) {
			$this->app_version = $headers['x-app-version'];
		}
		if ( isset( $headers['date'] ) && false !== strtotime( $headers['date'] ) ) {
			$this->server_time = (int) strtotime( $headers['date'] );
		}

		$text = (string) wp_remote_retrieve_body( $raw );
		$json = json_decode( $text, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $json ) ) {
				throw new ApiException( ErrorKind::InvalidResponse, sprintf( '%d %s, not JSON', $status, $headers['content-type'] ?? 'unknown type' ), $status );
			}
			return new Response( $status, $headers, $json );
		}

		throw self::statusError( $status, is_array( $json ) ? $json : array(), $headers );
	}

	/**
	 * Map a transport failure.
	 *
	 * @param string $message WP_Error message.
	 */
	public static function transportError( string $message ): ApiException {
		$code = preg_match( '/cURL error (\d+)/', $message, $m ) ? (int) $m[1] : 0;
		$kind = match ( true ) {
			6 === $code || str_contains( $message, 'resolve host' )                => ErrorKind::Dns,
			in_array( $code, array( 35, 51, 53, 54, 58, 59, 60, 77, 80, 83, 90, 91 ), true ) || stripos( $message, 'SSL' ) !== false => ErrorKind::Tls,
			28 === $code || stripos( $message, 'timed out' ) !== false            => ErrorKind::Timeout,
			default                                                               => ErrorKind::Transport,
		};
		return new ApiException( $kind, $message );
	}

	/**
	 * Map an HTTP error status.
	 *
	 * @param int                   $status  Status.
	 * @param array<mixed>          $json    Body.
	 * @param array<string, string> $headers Headers.
	 */
	public static function statusError( int $status, array $json, array $headers ): ApiException {
		$message = is_string( $json['message'] ?? null ) ? $json['message'] : '';
		$detail  = trim( $status . ' ' . $message );

		if ( $status >= 300 && $status < 400 ) {
			$location = $headers['location'] ?? '';
			return new ApiException( ErrorKind::Redirect, trim( $status . ' Location: ' . $location ), $status, array(), null, $location );
		}
		if ( 401 === $status ) {
			return new ApiException( ErrorKind::Auth, $detail, $status );
		}
		if ( 403 === $status ) {
			$kind = match ( true ) {
				stripos( $message, 'free / unpaid' ) !== false, stripos( $message, 'not available with free' ) !== false => ErrorKind::PlanRequired,
				stripos( $message, 'invalid token' ) !== false, stripos( $message, 'user inactive' ) !== false, stripos( $message, 'access locked' ) !== false => ErrorKind::Auth,
				default => ErrorKind::Forbidden,
			};
			return new ApiException( $kind, $detail, $status );
		}
		if ( 404 === $status || 405 === $status ) {
			return new ApiException( ErrorKind::NotFound, $detail, $status );
		}
		if ( 422 === $status ) {
			$errors = is_array( $json['errors'] ?? null ) ? $json['errors'] : array();
			return new ApiException( ErrorKind::Validation, $detail, $status, $errors );
		}
		if ( 429 === $status ) {
			$retry = isset( $headers['retry-after'] ) && is_numeric( $headers['retry-after'] ) ? min( 300, (int) $headers['retry-after'] ) : null;
			$kind  = stripos( $message, 'duplicate request' ) !== false ? ErrorKind::Duplicate : ErrorKind::RateLimited;
			return new ApiException( $kind, $detail, $status, array(), $retry );
		}
		if ( $status >= 500 ) {
			return new ApiException( ErrorKind::Server, $detail, $status );
		}
		return new ApiException( ErrorKind::InvalidResponse, $detail, $status );
	}

	/**
	 * Refuse private and reserved addresses unless allowed.
	 *
	 * @throws ApiException For a private host that isn't allowed.
	 */
	private function guardPrivateHost(): void {
		if ( $this->allow_private ) {
			return;
		}
		$host = BaseUrl::host( $this->base );
		if ( ! isset( self::$private_hosts[ $host ] ) ) {
			self::$private_hosts[ $host ] = self::isPrivate( $host );
		}
		if ( self::$private_hosts[ $host ] ) {
			throw new ApiException( ErrorKind::PrivateHost, $host . ' resolves to a private or reserved address' );
		}
	}

	/**
	 * Whether a host is, or resolves only to, private or reserved addresses.
	 *
	 * @param string $host Host.
	 */
	public static function isPrivate( string $host ): bool {
		$host = trim( strtolower( $host ), '[]' );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}
		$resolved = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
		$ips      = is_array( $resolved ) ? $resolved : array();
		if ( ! $ips ) {
			return false; // Unresolvable: the request reports a DNS error instead.
		}
		foreach ( $ips as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}
}
