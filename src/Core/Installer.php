<?php
/**
 * Creates tables and runs upgrades.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\BaseUrl;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Connection\ConnectionState;
use InvoiceNinja\Connection\Credentials;

/**
 * Runs on every request until the stored version matches, not only on activation,
 * because plugin updates (including auto-updates) don't fire the activation hook.
 * A lock stops two requests from upgrading at once. Nothing from 1.x is deleted.
 */
final class Installer {

	public const SCHEMA = 11;

	/**
	 * Seconds to wait after a failed upgrade before trying again.
	 */
	public const RETRY_AFTER = 600;

	/**
	 * Constructor.
	 *
	 * @param Options     $options     Options.
	 * @param Secrets     $secrets     Secrets.
	 * @param Credentials $credentials Credentials.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct(
		private Options $options,
		private Secrets $secrets,
		private Credentials $credentials,
		private Logger $logger,
	) {}

	/**
	 * Whether an upgrade is due.
	 */
	public function due(): bool {
		return (string) $this->options->get( 'version', '' ) !== INVOICENINJA_VERSION
			|| (int) $this->options->get( 'schema_version', 0 ) < self::SCHEMA;
	}

	/**
	 * Upgrade if needed. Safe to call on every request.
	 */
	public function maybeUpgrade(): void {
		if ( ! $this->due() || $this->waiting() || ! $this->lock() ) {
			return;
		}
		try {
			$from = (string) $this->options->get( 'version', '' );
			if ( '' === $from && self::hasLegacyData() ) {
				$from = '1.x';
				$this->options->set( 'upgraded_from', '1.x' );
			}
			$this->createTables();
			$this->migrate( (int) $this->options->get( 'schema_version', 0 ), $from );
			// Migration 1 again: 1.x saves the token in plain text, also after a downgrade.
			if ( $this->credentials->encryptLegacyToken() ) {
				$this->logger->info( 'upgrade', 'Encrypted the saved API token' );
			}
			$this->options->set( 'version', INVOICENINJA_VERSION, true );
			$this->options->delete( 'upgrade_failed' );
			if ( '' !== $from && INVOICENINJA_VERSION !== $from ) {
				$this->logger->info( 'upgrade', sprintf( 'Upgraded from %s to %s', $from, INVOICENINJA_VERSION ) );
				/**
				 * Fires after the plugin upgraded.
				 *
				 * @since 2.0.0
				 * @param string $from Previous version ('1.x' when upgrading from 1.x).
				 * @param string $to   New version.
				 */
				do_action( 'invoiceninja_upgraded', $from, INVOICENINJA_VERSION );
			}
		} catch ( \Throwable $e ) {
			// Never take the site down: record it, show admins a notice and try again later.
			$this->failed( $e );
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Record a failed upgrade.
	 *
	 * @param \Throwable $e What went wrong.
	 */
	private function failed( \Throwable $e ): void {
		$message = sprintf( '%s in %s:%d', $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
		try {
			$this->options->set(
				'upgrade_failed',
				array(
					'at'      => time(),
					'version' => INVOICENINJA_VERSION,
					'step'    => (int) $this->options->get( 'schema_version', 0 ) + 1,
					'message' => substr( $message, 0, 500 ),
				)
			);
			$this->logger->error( 'upgrade', 'The update to ' . INVOICENINJA_VERSION . ' failed and will be tried again: ' . $message, array( 'exception' => get_class( $e ) ) );
		} catch ( \Throwable $ignored ) {
			// The log table may be what failed.
			error_log( 'Invoice Ninja: the update failed: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last resort when the log table is unusable.
		}
	}

	/**
	 * The last failed upgrade, if the current version hasn't finished upgrading.
	 *
	 * @return array{at: int, version: string, step: int, message: string}|null
	 */
	public function failure(): ?array {
		$failed = $this->options->get( 'upgrade_failed' );
		if ( ! is_array( $failed ) || ! $this->due() ) {
			return null;
		}
		return array(
			'at'      => (int) ( $failed['at'] ?? 0 ),
			'version' => (string) ( $failed['version'] ?? '' ),
			'step'    => (int) ( $failed['step'] ?? 0 ),
			'message' => (string) ( $failed['message'] ?? '' ),
		);
	}

	/**
	 * Whether a recent failure means waiting before trying again.
	 */
	private function waiting(): bool {
		$failed = $this->options->get( 'upgrade_failed' );
		return is_array( $failed ) && (int) ( $failed['at'] ?? 0 ) > time() - self::RETRY_AFTER;
	}

	/**
	 * Create or update custom tables.
	 */
	public function createTables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Logger::schema() );
		dbDelta( \InvoiceNinja\Sync\Mapping::schema() );
		dbDelta( \InvoiceNinja\Commerce\Orders::schema() );
		/**
		 * Modules create or update their tables here (dbDelta is loaded).
		 *
		 * @since 2.0.0
		 */
		do_action( 'invoiceninja_create_tables' );
	}

	/**
	 * Run each migration after $from, recording progress after each step.
	 *
	 * @param int    $from    Last completed schema version.
	 * @param string $version The plugin version being upgraded from ('1.x' from 1.x, '' on a new install).
	 */
	private function migrate( int $from, string $version ): void {
		$steps = array(
			1  => function (): void {
				// Encrypt a 1.x plain-text token in place.
				if ( $this->credentials->encryptLegacyToken() ) {
					$this->logger->info( 'upgrade', 'Encrypted the saved API token' );
				}
			},
			2  => function (): void {
				foreach ( Secrets::PURPOSES as $purpose ) {
					$this->secrets->get( $purpose );
				}
				$this->secrets->siteCode();
			},
			3  => static function (): void {
				Capabilities::grant();
			},
			4  => function (): void {
				// 2.0 syncs products in place; link 1.x posts and retire its hourly delete-and-reimport.
				$linked = \InvoiceNinja\Plugin::instance()->products->linkLegacyPosts();
				wp_clear_scheduled_hook( 'invoiceninja_auto_refresh' );
				if ( $linked > 0 ) {
					$this->logger->info( 'upgrade', sprintf( 'Linked %d products from 1.x', $linked ) );
				}
			},
			5  => static function (): void {
				// Tables were widened; createTables() above already applied it.
			},
			6  => static function (): void {
				// Orders table; created by createTables() above.
			},
			7  => function (): void {
				// 1.x purchase modes. Login stays required: 1.0.15 required it for every purchase.
				$legacy = get_option( 'invoiceninja_online_purchases', false );
				if ( false === $legacy ) {
					// Only a site that came from 1.x (2.0 also keeps its token in invoiceninja_api_token).
					if ( '1.x' !== $this->options->get( 'upgraded_from', '' ) ) {
						return;
					}
					$legacy = 'disabled'; // 1.x's default: it never sold online.
				}
				$modes = array(
					'disabled' => 'off',
					'single'   => 'buy_now',
					'multiple' => 'cart',
				);
				$mode  = $modes[ (string) $legacy ] ?? 'off';
				$this->options->saveSettings(
					array(
						'store' => array(
							'mode'          => $mode,
							'require_login' => true,
							'display'       => 'legacy',
						),
					)
				);
				$this->logger->info( 'upgrade', sprintf( 'Kept the 1.x store settings: %s, login required, legacy display', $mode ) );
				$this->options->set( 'welcome_pending', 1 );
			},
			8  => static function (): void {
				// Orders gained placed_at; createTables() above added it.
			},
			9  => function (): void {
				// 1.x stored the whole company record (company key, mail secrets); keep only what it reads.
				if ( self::reduceLegacyProfile() ) {
					$this->logger->info( 'upgrade', 'Removed secrets from the saved 1.x company profile' );
				}
			},
			10 => function () use ( $version ): void {
				// Orders stay allowed only while upgrading from 1.x itself, not on a later update
				// of a site that came from 1.x (its staging copy may have been paused since).
				$this->keepLegacyConnection( '1.x' === $version );
			},
			11 => function (): void {
				// 2.0.1 allowed orders on any site that came from 1.x, staging copies included.
				if ( '1.x' === $this->options->get( 'upgraded_from', '' ) && self::undoStagingConfirmation( new Environment( $this->options ) ) ) {
					$this->logger->warning( 'upgrade', 'Orders are paused on this site because it looks like a staging copy. An admin can allow them again.' );
				}
			},
		);
		foreach ( $steps as $version => $step ) {
			if ( $version > $from ) {
				$step();
				$this->options->set( 'schema_version', $version, true );
			}
		}
	}

	/**
	 * Rewrite the stored 1.x company profile (invoiceninja_profile) to its allowlist.
	 *
	 * @return bool Whether it changed.
	 */
	public static function reduceLegacyProfile(): bool {
		$stored = get_option( 'invoiceninja_profile', false );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}
		$reduced = \InvoiceNinja\Api\ProfileApi::reduce( json_decode( $stored ) );
		$value   = null === $reduced ? '' : (string) wp_json_encode( $reduced );
		if ( $value === $stored ) {
			return false;
		}
		update_option( 'invoiceninja_profile', $value );
		return true;
	}

	/**
	 * Keep a 1.x site working as it did: hosted when the address was blank, its own
	 * private-network address, and orders allowed on a live site.
	 *
	 * @param bool $from_1x Whether this is the update from 1.x itself (orders are only allowed then).
	 */
	private function keepLegacyConnection( bool $from_1x ): void {
		// 1.x said to leave the address blank for hosted Invoice Ninja.
		if ( ! defined( 'INVOICENINJA_API_URL' ) && '' === trim( (string) $this->options->get( 'api_url', '' ) ) && $this->credentials->hasToken() ) {
			$this->options->set( 'api_url', BaseUrl::HOSTED, true );
			$this->logger->info( 'upgrade', 'No Invoice Ninja address was saved, so the hosted service is used, as in 1.x' );
		}
		if ( '1.x' !== $this->options->get( 'upgraded_from', '' ) ) {
			return;
		}
		$state = new ConnectionState( $this->options );
		$url   = $this->credentials->url();
		$known = $state->get();
		// The admin chose this address in 1.x, where private addresses worked.
		if ( '' !== $url && '' === $known['private_host'] && '' === (string) ( $known['company']['id'] ?? '' ) && HttpClient::isPrivate( BaseUrl::host( $url ) ) ) {
			if ( ConnectionState::privateAllowedWithoutAdmin( $this->credentials->urlFromConstant() ) ) {
				$state->allowPrivateHost( BaseUrl::host( $url ) );
				$this->logger->info( 'upgrade', sprintf( 'Allowed %s on the private network, as in 1.x', BaseUrl::host( $url ) ) );
			} else {
				$state->markError( new ApiException( ErrorKind::PrivateHost, BaseUrl::host( $url ) . ': on multisite a network admin needs to allow it' ) );
				$this->logger->warning( 'upgrade', sprintf( '%s is on the private network; a network admin needs to allow it in the setup', BaseUrl::host( $url ) ) );
			}
		}
		// 1.x had no staging check: a live production site keeps taking orders. A staging
		// address still waits for an admin, as does a site updated from 1.x before (its
		// staging copy may have been paused since, and an admin may have paused it).
		$environment = new Environment( $this->options );
		if ( $from_1x && null === $environment->state() && $environment->looksLive() ) {
			$environment->confirm( 0 );
			$this->logger->info( 'upgrade', 'Orders stay allowed on this site, as in 1.x' );
		}
	}

	/**
	 * Undo an automatic confirmation of a staging-looking address (made by 2.0.1's update
	 * step), so orders wait for an admin. A confirmation an admin made is kept.
	 *
	 * @param Environment $environment Environment.
	 * @return bool Whether it was undone.
	 */
	public static function undoStagingConfirmation( Environment $environment ): bool {
		$state = $environment->state();
		$host  = strtolower( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) );
		if ( null === $state || 0 !== $state['by'] || '' === $state['fingerprint'] || ! hash_equals( $state['fingerprint'], $environment->fingerprint() ) || ! Environment::stagingHost( $host ) ) {
			return false;
		}
		$environment->forget();
		return true;
	}

	/**
	 * Whether 1.x left settings behind.
	 */
	public static function hasLegacyData(): bool {
		return '' !== (string) get_option( 'invoiceninja_api_token', '' )
			|| false !== get_option( 'invoiceninja_profile', false )
			|| false !== get_option( 'invoiceninja_online_purchases', false );
	}

	/**
	 * Take the upgrade lock. Locks older than ten minutes are considered abandoned.
	 */
	private function lock(): bool {
		if ( $this->options->addAtomic( 'install_lock', (string) time() ) ) {
			return true;
		}
		if ( (int) $this->options->get( 'install_lock', 0 ) < time() - 600 ) {
			$this->options->delete( 'install_lock' );
			return $this->options->addAtomic( 'install_lock', (string) time() );
		}
		return false;
	}

	/**
	 * Release the lock.
	 */
	private function unlock(): void {
		delete_option( Options::PREFIX . 'install_lock' );
	}
}
