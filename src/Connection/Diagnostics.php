<?php
/**
 * Plain-language health checks for Status → Checks, Site Health and `wp invoiceninja doctor`.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Connection;

use InvoiceNinja\Api\BaseUrl;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Plugin;

/**
 * Each check returns id, label, status (pass, warn, fail, info, skip), a detail line and
 * an optional action id the UI turns into a button. Results are sorted problems first.
 */
final class Diagnostics {

	private const ORDER = array(
		'fail' => 0,
		'warn' => 1,
		'info' => 2,
		'skip' => 3,
		'pass' => 4,
	);

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Run every check.
	 *
	 * @param bool $network Include checks that call Invoice Ninja or this site.
	 * @return array{checks: list<array{id: string, label: string, status: string, detail: string, action: string}>, counts: array<string, int>, ran_at: int}
	 */
	public function run( bool $network = true ): array {
		$checks = array();
		$add    = static function ( string $id, string $label, string $status, string $detail, string $action = '' ) use ( &$checks ): void {
			$checks[] = array(
				'id'     => $id,
				'label'  => $label,
				'status' => $status,
				'detail' => $detail,
				'action' => $action,
			);
		};
		$p      = $this->plugin;
		$cred   = $p->credentials;
		$state  = $p->state->get();

		global $wpdb;
		$add(
			'environment',
			__( 'Environment', 'invoiceninja' ),
			'pass',
			/* translators: 1: PHP version, 2: WordPress version, 3: memory limit */
			sprintf( __( 'PHP %1$s, WordPress %2$s, memory %3$s', 'invoiceninja' ), PHP_VERSION, get_bloginfo( 'version' ), (string) ini_get( 'memory_limit' ) )
		);

		$source = $cred->source();
		if ( 'none' === $source ) {
			$add( 'configuration', __( 'Configuration', 'invoiceninja' ), 'fail', __( 'Not connected to Invoice Ninja yet.', 'invoiceninja' ), 'setup' );
		} else {
			$add( 'configuration', __( 'Configuration', 'invoiceninja' ), 'pass', 'constants' === $source ? __( 'Connection set in wp-config.php', 'invoiceninja' ) : __( 'Connection saved in the database', 'invoiceninja' ) );
		}

		$token = $cred->token();
		if ( null === $token ) {
			$add( 'encryption', __( 'Encryption', 'invoiceninja' ), 'fail', __( "The saved token can't be unlocked because WordPress's security keys changed. Paste the token again.", 'invoiceninja' ), 'reconnect' );
		} elseif ( ! Secrets::keyIsInConfig() ) {
			$add( 'encryption', __( 'Encryption', 'invoiceninja' ), 'warn', __( "WordPress's security keys are stored in the database. Put them in wp-config.php so a database leak can't unlock your token.", 'invoiceninja' ), 'docs_salts' );
		} else {
			$add( 'encryption', __( 'Encryption', 'invoiceninja' ), 'pass', __( 'Token encrypted with a key from wp-config.php', 'invoiceninja' ) );
		}

		$url  = $cred->url();
		$host = '' !== $url ? BaseUrl::host( $url ) : '';
		$live = $network && '' !== $url && is_string( $token ) && '' !== $token;

		if ( $live ) {
			$test = $p->connection->test( $url, $token, $p->state->allowsPrivate( $url ) );
			$ips  = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
			$dns  = 'dns' === ( $test['error']['kind'] ?? '' );
			$add(
				'dns',
				__( 'DNS', 'invoiceninja' ),
				$dns ? 'fail' : 'pass',
				$dns
					/* translators: %s: host */
					? sprintf( __( '%s does not resolve', 'invoiceninja' ), $host )
					/* translators: 1: host, 2: IP addresses */
					: ( $ips ? sprintf( __( '%1$s resolves to %2$s', 'invoiceninja' ), $host, implode( ', ', array_slice( $ips, 0, 2 ) ) ) : sprintf( __( '%s is reachable', 'invoiceninja' ), $host ) ),
				$dns ? 'reconnect' : ''
			);
			foreach ( $test['checks'] as $check ) {
				$map = array(
					'tls'      => array( 'tls', __( 'Secure connection', 'invoiceninja' ) ),
					'token'    => array( 'token', __( 'Token', 'invoiceninja' ) ),
					'version'  => array( 'version', __( 'Version', 'invoiceninja' ) ),
					'webhooks' => array( 'webhooks', __( 'Webhooks', 'invoiceninja' ) ),
				);
				if ( 'admin' === $check['id'] && 'fail' === $check['status'] ) {
					$add( 'token', __( 'Token', 'invoiceninja' ), 'fail', (string) ( $test['error']['title'] ?? '' ), 'reconnect' );
				}
				if ( isset( $map[ $check['id'] ] ) && 'skip' !== $check['status'] ) {
					$status = $check['status'];
					$detail = 'token' === $check['id'] && 'pass' === $status
						/* translators: %s: company name */
						? sprintf( __( 'Admin token for %s', 'invoiceninja' ), (string) ( $test['company']['name'] ?? '' ) )
						: (string) $check['detail'];
					if ( 'fail' === $status && $test['error'] ) {
						$detail = (string) $test['error']['title'] . '. ' . (string) $test['error']['fix'];
					}
					$add( $map[ $check['id'] ][0], $map[ $check['id'] ][1], $status, $detail, 'fail' === $status ? 'reconnect' : '' );
				}
			}
			$switched = false;
			if ( $test['ok'] ) {
				try {
					$p->connection->guardCompany( $state, $test['company'] );
				} catch ( \InvoiceNinja\Api\ApiException $e ) {
					$switched = true;
					$p->state->markError( $e );
					$add( 'company', __( 'Company', 'invoiceninja' ), 'fail', $e->kind->title() . '. ' . $e->kind->fix(), 'reconnect' );
				}
			}
			if ( $test['ok'] && ! $switched ) {
				$company = $test['company'];
				$add(
					'company',
					__( 'Company', 'invoiceninja' ),
					'pass',
					(string) $company['name']
				);
				$add(
					'portal_password',
					__( 'Portal password', 'invoiceninja' ),
					empty( $company['portal_password'] ) ? 'pass' : 'info',
					empty( $company['portal_password'] ) ? __( 'Customers open the billing portal in one click', 'invoiceninja' ) : __( 'Customers will be asked for their Invoice Ninja portal password.', 'invoiceninja' )
				);
				$add(
					'gateways',
					__( 'Payment gateway', 'invoiceninja' ),
					$test['gateways'] ? 'pass' : 'warn',
					$test['gateways']
						/* translators: %s: gateway names */
						? sprintf( __( '%s set up in Invoice Ninja', 'invoiceninja' ), implode( ', ', $test['gateways'] ) )
						: __( "No payment gateway yet, so customers can't pay online.", 'invoiceninja' ),
					$test['gateways'] ? '' : 'gateways'
				);
				$this->plugin->state->markConnected( $company, $test['version'], true, true, $test['gateways'], $state['private_host'] );
				$this->plugin->notifications->connectionRecovered();
			}
		} elseif ( '' !== $url ) {
			$add( 'token', __( 'Token', 'invoiceninja' ), 'skip', __( 'Skipped until a readable token is saved.', 'invoiceninja' ) );
		}

		if ( $network ) {
			$ping = wp_remote_get(
				rest_url( 'invoiceninja/v1/ping' ),
				array(
					'timeout'   => 5,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				)
			);
			$ok   = ! is_wp_error( $ping ) && 200 === (int) wp_remote_retrieve_response_code( $ping );
			$add(
				'loopback',
				__( 'Loopback', 'invoiceninja' ),
				$ok ? 'pass' : 'warn',
				$ok ? __( 'This site can call itself for background jobs', 'invoiceninja' ) : __( "This site can't call itself, so background jobs may run late. Your host may block it.", 'invoiceninja' )
			);
		}

		$paused = $p->options->get( 'sync_paused' );
		$sync   = $p->options->get( 'sync_status' );
		if ( is_array( $paused ) ) {
			$add(
				'product_sync',
				__( 'Product sync', 'invoiceninja' ),
				'fail',
				/* translators: 1: changes, 2: total products */
				sprintf( __( 'Stopped: %1$d of %2$d products would change. Waiting for your review.', 'invoiceninja' ), (int) $paused['changes'], (int) $paused['total'] ),
				'review_sync'
			);
		} elseif ( $p->products->offByLegacy() ) {
			$add( 'product_sync', __( 'Product sync', 'invoiceninja' ), 'warn', __( 'Turned off on the legacy settings page, so prices and archived products are no longer updated. Fix problems turns it back on.', 'invoiceninja' ), 'repair' );
		} elseif ( ! $p->products->enabled() ) {
			$add( 'product_sync', __( 'Product sync', 'invoiceninja' ), 'skip', __( 'Skipped: product sync is turned off.', 'invoiceninja' ) );
		} elseif ( is_array( $sync ) && 'failed' === ( $sync['status'] ?? '' ) ) {
			/* translators: %s: reason */
			$add( 'product_sync', __( 'Product sync', 'invoiceninja' ), 'warn', sprintf( __( 'The last sync failed: %s. It retries every hour.', 'invoiceninja' ), (string) $sync['message'] ), 'sync_now' );
		} elseif ( is_array( $sync ) ) {
			/* translators: 1: number of products, 2: time ago */
			$add( 'product_sync', __( 'Product sync', 'invoiceninja' ), 'pass', sprintf( __( '%1$d products, synced %2$s ago', 'invoiceninja' ), $p->mapping->count( 'product' ), human_time_diff( (int) $sync['at'] ) ) );
		} else {
			$add( 'product_sync', __( 'Product sync', 'invoiceninja' ), 'info', __( 'Not synced yet.', 'invoiceninja' ), 'sync_now' );
		}

		$stats = Queue::stats();
		if ( ! Queue::available() ) {
			$add( 'scheduler', __( 'Background jobs', 'invoiceninja' ), 'fail', __( 'Action Scheduler is missing. Reinstall the plugin.', 'invoiceninja' ) );
		} elseif ( $stats['past_due'] > 0 ) {
			/* translators: %d: number of overdue jobs */
			$add( 'scheduler', __( 'Background jobs', 'invoiceninja' ), 'warn', sprintf( _n( '%d job is more than 15 minutes late. WP-Cron may not be running.', '%d jobs are more than 15 minutes late. WP-Cron may not be running.', $stats['past_due'], 'invoiceninja' ), $stats['past_due'] ), 'run_jobs' );
		} else {
			/* translators: %d: number of waiting jobs */
			$add( 'scheduler', __( 'Background jobs', 'invoiceninja' ), 'pass', sprintf( _n( '%d waiting', '%d waiting', $stats['pending'], 'invoiceninja' ), $stats['pending'] ) );
		}

		$table = Logger::table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$add(
			'database',
			__( 'Database', 'invoiceninja' ),
			$found === $table ? 'pass' : 'fail',
			$found === $table ? __( 'Tables up to date', 'invoiceninja' ) : __( 'A plugin table is missing.', 'invoiceninja' ),
			$found === $table ? '' : 'repair'
		);

		$mail = $p->mailer->lastTest();
		if ( null === $mail ) {
			$add( 'mail', __( 'Email', 'invoiceninja' ), 'info', __( 'Not tested yet. Shoppers need email for their codes and payment links.', 'invoiceninja' ), 'send_test' );
		} elseif ( false === $mail['confirmed'] || ! $mail['sent'] ) {
			$add( 'mail', __( 'Email', 'invoiceninja' ), 'warn', __( "The last test email didn't arrive.", 'invoiceninja' ), 'send_test' );
		} else {
			$add( 'mail', __( 'Email', 'invoiceninja' ), 'pass', true === $mail['confirmed'] ? __( 'Test email arrived', 'invoiceninja' ) : __( 'Test email sent', 'invoiceninja' ) );
		}

		$env = $p->environment;
		if ( $env->writesAllowed() ) {
			$add( 'environment_change', __( 'This site', 'invoiceninja' ), 'pass', __( 'Orders allowed from this site', 'invoiceninja' ) );
		} else {
			$add(
				'environment_change',
				__( 'This site', 'invoiceninja' ),
				'warn',
				$env->changed() ? __( 'The site address or type changed, so orders are paused until you allow them.', 'invoiceninja' ) : __( 'Looks like a staging or development copy, so orders are paused.', 'invoiceninja' ),
				'allow_orders'
			);
		}

		/**
		 * Modules add checks: arrays of id, label, status (pass, warn, fail, info, skip), detail, action.
		 *
		 * @since 2.0.0
		 * @param array<int, array<string, string>> $checks Checks.
		 */
		$checks = (array) apply_filters( 'invoiceninja_diagnostics_checks', $checks );
		usort( $checks, static fn( array $a, array $b ): int => ( self::ORDER[ $a['status'] ] ?? 9 ) <=> ( self::ORDER[ $b['status'] ] ?? 9 ) );
		$counts = array_fill_keys( array_keys( self::ORDER ), 0 );
		foreach ( $checks as $check ) {
			++$counts[ $check['status'] ];
		}
		$result = array(
			'checks' => $checks,
			'counts' => $counts,
			'ran_at' => time(),
		);
		$p->options->set( 'last_diagnostics', $result );
		return $result;
	}

	/**
	 * Repair what is safe to repair automatically.
	 *
	 * @return list<string> What was done.
	 */
	public function repair(): array {
		$done = array();
		$this->plugin->installer->createTables();
		$done[] = __( 'Checked the database tables', 'invoiceninja' );
		delete_option( 'invoiceninja_install_lock' );
		if ( Queue::available() ) {
			$this->plugin->queue->recurring( 'invoiceninja_daily', DAY_IN_SECONDS, true );
			$done[] = __( 'Checked the scheduled jobs', 'invoiceninja' );
		}
		if ( $this->plugin->credentials->encryptLegacyToken() ) {
			$done[] = __( 'Encrypted the saved token', 'invoiceninja' );
		}
		if ( $this->plugin->products->clearLegacySwitch() ) {
			$done[] = __( 'Turned product sync back on', 'invoiceninja' );
		}
		/**
		 * Fires when an admin asks to repair problems; modules add their own repairs.
		 *
		 * @since 2.0.0
		 * @param list<string> $done What was done.
		 */
		return (array) apply_filters( 'invoiceninja_repair', $done );
	}

	/**
	 * The version check needs a client; kept for modules that only want the minimum.
	 */
	public static function minimumVersion(): string {
		return HttpClient::MIN_VERSION;
	}
}
