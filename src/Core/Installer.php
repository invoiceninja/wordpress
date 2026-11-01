<?php
/**
 * Creates tables and runs upgrades.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

use InvoiceNinja\Connection\Credentials;

/**
 * Runs on every request until the stored version matches, not only on activation,
 * because plugin updates (including auto-updates) don't fire the activation hook.
 * A lock stops two requests from upgrading at once. Nothing from 1.x is deleted.
 */
final class Installer {

	public const SCHEMA = 3;

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
		if ( ! $this->due() || ! $this->lock() ) {
			return;
		}
		try {
			$from = (string) $this->options->get( 'version', '' );
			if ( '' === $from && $this->hasLegacyData() ) {
				$from = '1.x';
				$this->options->set( 'upgraded_from', '1.x' );
			}
			$this->createTables();
			$this->migrate( (int) $this->options->get( 'schema_version', 0 ) );
			$this->options->set( 'version', INVOICENINJA_VERSION, true );
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
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Create or update custom tables.
	 */
	public function createTables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Logger::schema() );
	}

	/**
	 * Run each migration after $from, recording progress after each step.
	 *
	 * @param int $from Last completed schema version.
	 */
	private function migrate( int $from ): void {
		$steps = array(
			1 => function (): void {
				// Encrypt a 1.x plain-text token in place.
				if ( $this->credentials->encryptLegacyToken() ) {
					$this->logger->info( 'upgrade', 'Encrypted the saved API token' );
				}
			},
			2 => function (): void {
				foreach ( Secrets::PURPOSES as $purpose ) {
					$this->secrets->get( $purpose );
				}
				$this->secrets->siteCode();
			},
			3 => static function (): void {
				Capabilities::grant();
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
	 * Whether 1.x left settings behind.
	 */
	private function hasLegacyData(): bool {
		return '' !== (string) get_option( 'invoiceninja_api_token', '' )
			|| false !== get_option( 'invoiceninja_profile', false )
			|| false !== get_option( 'invoiceninja_online_purchases', false );
	}

	/**
	 * Take the upgrade lock. Locks older than ten minutes are considered abandoned.
	 */
	private function lock(): bool {
		$key = Options::PREFIX . 'install_lock';
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		if ( (int) get_option( $key, 0 ) < time() - 600 ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
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
