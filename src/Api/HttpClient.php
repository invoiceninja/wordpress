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
	 * Host and address checked by the private-host guard, used for the request.
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private ?array $pinned = null;

	/**
	 * Recent lookups (host => [time, addresses]); the checked address is pinned, so a short
	 * cache can't be used for rebinding.
	 *
	 * @var array<string, array{0: int, 1: list<string>}>
	 */
	private static array $resolved = array();

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
	public function request( string $method, string $path, array $query, ?array $body, float $budget, bool $idempotent, bool $raw = false ): Response {
		if ( '' === $this->base || '' === $this->token ) {
			throw new ApiException( ErrorKind::NotConfigured ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
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
				throw new ApiException( ErrorKind::Timeout, sprintf( 'time budget of %.1fs used up', $budget ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
			}
			try {
				return $this->once( $method, $url, $body, $remaining, $raw );
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
	 * Download a file (a PDF), as it is.
	 *
	 * @param string $path   Path under /api/v1.
	 * @param float  $budget Seconds.
	 * @return array{body: string, type: string}
	 * @throws ApiException When the call fails.
	 */
	public function download( string $path, float $budget = 20.0 ): array {
		$response = $this->request( 'GET', $path, array(), null, $budget, true, true );
		return array(
			'body' => (string) ( $response->json['raw'] ?? '' ),
			'type' => (string) ( $response->headers['content-type'] ?? 'application/octet-stream' ),
		);
	}

	/**
	 * One attempt.
	 *
	 * @param string            $method  Method.
	 * @param string            $url     URL.
	 * @param array<mixed>|null $body    Body.
	 * @param float             $timeout Seconds.
	 * @param bool              $raw_body Return the body as it is (a file), in json['raw'].
	 * @throws ApiException When the call fails.
	 */
	private function once( string $method, string $url, ?array $body, float $timeout, bool $raw_body = false ): Response {
		$headers = array(
			'X-API-TOKEN'       => $this->token,
			'X-Requested-With'  => 'XMLHttpRequest',
			'Accept'            => $raw_body ? '*/*' : 'application/json',
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

		$pin = null;
		if ( null !== $this->pinned ) {
			[ $pin_host, $pin_ip ] = $this->pinned;
			$port                  = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ?: ( str_starts_with( $url, 'https:' ) ? 443 : 80 ) );
			$pin                   = static function ( $handle ) use ( $pin_host, $pin_ip, $port ): void {
				if ( defined( 'CURLOPT_RESOLVE' ) ) {
					curl_setopt( $handle, CURLOPT_RESOLVE, array( $pin_host . ':' . $port . ':' . ( str_contains( $pin_ip, ':' ) ? '[' . $pin_ip . ']' : $pin_ip ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the checked address.
				}
			};
			add_action( 'http_api_curl', $pin );
		}
		try {
			$raw = wp_remote_request( $url, $args );
		} finally {
			if ( null !== $pin ) {
				remove_action( 'http_api_curl', $pin );
			}
		}
		if ( is_wp_error( $raw ) ) {
			throw self::transportError( $raw->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
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
		if ( $raw_body && $status >= 200 && $status < 300 ) {
			return new Response( $status, $headers, array( 'raw' => $text ) );
		}
		$json = json_decode( $text, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $json ) ) {
				throw new ApiException( ErrorKind::InvalidResponse, sprintf( '%d %s, not JSON', $status, $headers['content-type'] ?? 'unknown type' ), $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
			}
			return new Response( $status, $headers, $json );
		}

		throw self::statusError( $status, is_array( $json ) ? $json : array(), $headers ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
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
			// Invoice Ninja answers a valid token without permission for the action with 401
			// "This action is unauthorized." (a policy check), not 403: that's not a bad token.
			return new ApiException( stripos( $message, 'unauthorized' ) !== false ? ErrorKind::Forbidden : ErrorKind::Auth, $detail, $status );
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
	 * Refuse private and reserved addresses unless allowed, and pin the checked address so
	 * DNS can't change between the check and the request (rebinding).
	 *
	 * @throws ApiException For a private host that isn't allowed.
	 */
	private function guardPrivateHost(): void {
		$this->pinned = null;
		if ( $this->allow_private ) {
			return;
		}
		$host = BaseUrl::host( $this->base );
		$ips  = self::resolve( $host );
		if ( self::isPrivateSet( $host, $ips ) ) {
			throw new ApiException( ErrorKind::PrivateHost, $host . ' resolves to a private or reserved address' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
		}
		if ( ! $ips ) {
			// Unchecked, the request would resolve the name again itself, and a second answer
			// could be a private address (DNS rebinding).
			throw new ApiException( ErrorKind::Dns, $host . ' did not resolve' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal API error; logged, and escaped where shown.
		}
		if ( ! filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			$this->pinned = array( $host, $ips[0] );
		}
	}

	/**
	 * Every IPv4 and IPv6 address a host resolves to.
	 *
	 * @param string $host Host.
	 * @return list<string>
	 */
	public static function resolve( string $host ): array {
		$host = trim( strtolower( $host ), '[]' );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		/**
		 * Short-circuit DNS resolution for the private-network check (return a list of addresses).
		 *
		 * @since 2.0.0
		 * @param list<string>|null $ips  Addresses, or null to resolve normally.
		 * @param string            $host Host.
		 */
		$pre = apply_filters( 'invoiceninja_pre_resolve_host', null, $host );
		if ( is_array( $pre ) ) {
			return array_values( array_map( 'strval', $pre ) );
		}
		if ( isset( self::$resolved[ $host ] ) && self::$resolved[ $host ][0] > time() - 60 ) {
			return self::$resolved[ $host ][1];
		}
		$ips     = array();
		$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A | DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unresolvable hosts are reported by the request.
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( isset( $record['ip'] ) ) {
				$ips[] = (string) $record['ip'];
			} elseif ( isset( $record['ipv6'] ) ) {
				$ips[] = (string) $record['ipv6'];
			}
		}
		if ( ! $ips ) {
			$v4  = gethostbynamel( $host );
			$ips = is_array( $v4 ) ? $v4 : array();
		}
		$ips                     = array_values( array_unique( $ips ) );
		self::$resolved[ $host ] = array( time(), $ips );
		return $ips;
	}

	/**
	 * Whether a host is, or resolves to any, private or reserved address.
	 *
	 * @param string $host Host.
	 */
	public static function isPrivate( string $host ): bool {
		return self::isPrivateSet( $host, self::resolve( $host ) );
	}

	/**
	 * Whether a host or any of its addresses is private.
	 *
	 * @param string       $host Host.
	 * @param list<string> $ips  Addresses.
	 */
	private static function isPrivateSet( string $host, array $ips ): bool {
		$host = trim( strtolower( $host ), '[]' );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}
		foreach ( $ips as $ip ) {
			if ( self::privateIp( $ip ) ) {
				return true;
			}
		}
		return false; // Unresolvable: guardPrivateHost() refuses it as a DNS error.
	}

	/**
	 * Whether one address is private, reserved, shared or otherwise not on the public internet.
	 *
	 * @param string $ip Address.
	 */
	public static function privateIp( string $ip ): bool {
		$ip = strtolower( trim( $ip, '[]' ) );
		if ( preg_match( '/^(?:::ffff:|::)(\d+\.\d+\.\d+\.\d+)$/', $ip, $m ) ) {
			$ip = $m[1]; // IPv4-mapped or -compatible IPv6.
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = (int) ip2long( $ip );
			foreach ( array( array( '100.64.0.0', 10 ), array( '198.18.0.0', 15 ), array( '192.0.0.0', 24 ), array( '0.0.0.0', 8 ) ) as [ $net, $bits ] ) {
				$mask = -1 << ( 32 - $bits );
				if ( ( $long & $mask ) === ( (int) ip2long( $net ) & $mask ) ) {
					return true;
				}
			}
			return false;
		}
		$bin = (string) inet_pton( $ip );
		// Also private: NAT64 prefix, unique local, link local and documentation ranges.
		return substr( $bin, 0, 12 ) === substr( (string) inet_pton( '64:ff9b::' ), 0, 12 )
			|| ( ord( $bin[0] ) & 0xfe ) === 0xfc
			|| ( ord( $bin[0] ) === 0xfe && ( ord( $bin[1] ) & 0xc0 ) === 0x80 )
			|| substr( $bin, 0, 4 ) === substr( (string) inet_pton( '2001:db8::' ), 0, 4 );
	}
}
