<?php
/**
 * Detects staging copies and clones so they never create real invoices.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

/**
 * The site's identity is its address (host and path, ignoring http/https and "www.")
 * plus WordPress's environment type. Orders and other writes to Invoice Ninja are
 * allowed only while the current identity matches the one an admin confirmed.
 * A production site is confirmed automatically the first time; anything that looks
 * like staging or a local copy waits for an admin.
 */
final class Environment {

	public const OPTION = 'environment';

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * The current identity.
	 */
	public function fingerprint(): string {
		return hash( 'sha256', $this->type() . '|' . self::identity( (string) home_url() ) );
	}

	/**
	 * WordPress's environment type.
	 */
	public function type(): string {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	}

	/**
	 * Whether this looks like a live site: production type on a public-looking host.
	 */
	public function looksLive(): bool {
		if ( 'production' !== $this->type() || $this->looksLocal() ) {
			return false;
		}
		return ! self::stagingHost( strtolower( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) ) );
	}

	/**
	 * Whether the site runs on this computer or a private network (localhost, .local, .test,
	 * a private IP address).
	 */
	public function looksLocal(): bool {
		$host = strtolower( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(localhost|local|test|example|invalid|lan|internal)$/', $host ) ) {
			return true;
		}
		return filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether a whole label in front of the site's own domain says staging, e.g.
	 * staging.example.com, dev.example.com or shop.staging.example.com, but not
	 * my-test-kitchen.com or dev-tools.com.
	 *
	 * @param string $host Host name.
	 */
	public static function stagingHost( string $host ): bool {
		$labels = explode( '.', $host );
		// The last two labels are the domain itself (dev.com is a domain, not a dev copy).
		foreach ( array_slice( $labels, 0, max( 0, count( $labels ) - 2 ) ) as $label ) {
			if ( in_array( $label, array( 'staging', 'stage', 'dev', 'develop', 'development', 'test', 'testing', 'qa', 'uat' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this site may create invoices and other records in Invoice Ninja.
	 * Confirms a live-looking production site automatically the first time.
	 */
	public function writesAllowed(): bool {
		$state = $this->state();
		if ( null === $state ) {
			if ( $this->looksLive() ) {
				$this->confirm( 0 );
				return true;
			}
			return false;
		}
		return hash_equals( (string) $state['fingerprint'], $this->fingerprint() );
	}

	/**
	 * Whether a previously confirmed site has since changed address or type.
	 */
	public function changed(): bool {
		$state = $this->state();
		return null !== $state && ! hash_equals( (string) $state['fingerprint'], $this->fingerprint() );
	}

	/**
	 * Allow orders from this site as it is now.
	 *
	 * @param int $user_id Who confirmed (0 for automatic).
	 */
	public function confirm( int $user_id ): void {
		$this->options->set(
			self::OPTION,
			array(
				'fingerprint' => $this->fingerprint(),
				'url'         => (string) home_url(),
				'type'        => $this->type(),
				'by'          => $user_id,
				'at'          => time(),
			)
		);
	}

	/**
	 * Pause orders from this site.
	 */
	public function pause(): void {
		$this->options->set(
			self::OPTION,
			array(
				'fingerprint' => '',
				'url'         => (string) home_url(),
				'type'        => $this->type(),
				'by'          => get_current_user_id(),
				'at'          => time(),
			)
		);
	}

	/**
	 * The stored confirmation, if any.
	 *
	 * @return array{fingerprint: string, url: string, type: string, by: int, at: int}|null
	 */
	public function state(): ?array {
		$state = $this->options->get( self::OPTION );
		if ( ! is_array( $state ) || ! isset( $state['fingerprint'] ) ) {
			return null;
		}
		return array(
			'fingerprint' => (string) $state['fingerprint'],
			'url'         => (string) ( $state['url'] ?? '' ),
			'type'        => (string) ( $state['type'] ?? '' ),
			'by'          => (int) ( $state['by'] ?? 0 ),
			'at'          => (int) ( $state['at'] ?? 0 ),
		);
	}

	/**
	 * Host and path without scheme, "www." or trailing slash.
	 *
	 * @param string $url Site address.
	 */
	public static function identity( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^www\./', '', $host );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$path = rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		return $host . ( $port ? ':' . $port : '' ) . $path;
	}
}
