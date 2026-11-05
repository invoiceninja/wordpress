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
	 * Words that mark a staging or development copy.
	 */
	private const STAGING_WORDS = array( 'staging', 'stage', 'dev', 'develop', 'development', 'test', 'testing', 'qa', 'uat' );

	/**
	 * Hosting services' domains used only for staging and temporary sites.
	 */
	private const STAGING_DOMAINS = array( 'flywheelstaging.com', 'instawp.xyz' );

	/**
	 * Whether the host looks like a staging copy.
	 *
	 * The rule: a staging word (staging, stage, dev, develop, development, test, testing,
	 * qa, uat; a number may follow, as in staging2) that is a whole label, or a whole
	 * hyphen-separated part of one, in front of the site's own domain; or a host on a
	 * staging-only hosting domain. So staging.example.com, shop.dev.example.com,
	 * staging-shop.example.com, shop-dev.example.com, staging2.example.com,
	 * dev-site.pantheonsite.io and staging-site.kinsta.cloud match. The site's own domain
	 * (the last two labels, three for example.co.uk-style domains) is never looked at,
	 * so my-test-kitchen.com, dev-tools.com and shop.dev.com don't; nor do words inside
	 * a word (backstage.example.com, contest.example.com).
	 *
	 * @param string $host Host name.
	 */
	public static function stagingHost( string $host ): bool {
		$host = rtrim( strtolower( $host ), '.' );
		foreach ( self::STAGING_DOMAINS as $domain ) {
			if ( str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}
		$labels = explode( '.', $host );
		foreach ( array_slice( $labels, 0, max( 0, count( $labels ) - self::domainLabels( $labels ) ) ) as $label ) {
			foreach ( explode( '-', $label ) as $part ) {
				if ( in_array( rtrim( $part, '0123456789' ), self::STAGING_WORDS, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * How many trailing labels make up the site's own domain: two (example.com), or three
	 * under a country's second-level suffix (example.co.uk, example.com.au).
	 *
	 * @param list<string> $labels Host labels.
	 */
	private static function domainLabels( array $labels ): int {
		$count = count( $labels );
		if ( $count >= 3 && 2 === strlen( $labels[ $count - 1 ] ) && in_array( $labels[ $count - 2 ], array( 'co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'or', 'ne', 'go', 'ltd', 'plc', 'nom', 'gob' ), true ) ) {
			return 3;
		}
		return 2;
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
	 * Forget the confirmation: orders wait for an admin again (unless the site looks live).
	 */
	public function forget(): void {
		$this->options->delete( self::OPTION );
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
