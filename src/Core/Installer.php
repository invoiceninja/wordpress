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

	public const SCHEMA = 8;

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
			4 => function (): void {
				// 2.0 syncs products in place; link 1.x posts and retire its hourly delete-and-reimport.
				$linked = \InvoiceNinja\Plugin::instance()->products->linkLegacyPosts();
				wp_clear_scheduled_hook( 'invoiceninja_auto_refresh' );
				if ( $linked > 0 ) {
					$this->logger->info( 'upgrade', sprintf( 'Linked %d products from 1.x', $linked ) );
				}
			},
			5 => static function (): void {
				// Tables were widened; createTables() above already applied it.
			},
			6 => static function (): void {
				// Orders table; created by createTables() above.
			},
			7 => function (): void {
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
			8 => static function (): void {
				// Orders gained placed_at; createTables() above added it.
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
