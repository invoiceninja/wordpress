<?php
/**
 * WP-CLI commands.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\CLI;

use InvoiceNinja\Plugin;

/**
 * Manage the Invoice Ninja connection.
 */
final class Command {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Show the connection status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp invoiceninja status
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function status( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$state = $this->plugin->state->get();
		$rows  = array(
			array(
				'field' => 'status',
				'value' => $state['status'],
			),
			array(
				'field' => 'address',
				'value' => $this->plugin->credentials->url(),
			),
			array(
				'field' => 'source',
				'value' => $this->plugin->credentials->source(),
			),
			array(
				'field' => 'company',
				'value' => (string) ( $state['company']['name'] ?? '' ),
			),
			array(
				'field' => 'version',
				'value' => $state['version'],
			),
			array(
				'field' => 'gateways',
				'value' => implode( ', ', $state['gateways'] ),
			),
			array(
				'field' => 'orders',
				'value' => $this->plugin->environment->writesAllowed() ? 'allowed' : 'paused',
			),
			array(
				'field' => 'plugin',
				'value' => INVOICENINJA_VERSION,
			),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Run every check, and optionally repair what is safe to repair.
	 *
	 * ## OPTIONS
	 *
	 * [--repair]
	 * : Repair tables, scheduled jobs and other safe problems first.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp invoiceninja doctor --repair
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function doctor( array $args, array $assoc ): void {
		unset( $args );
		if ( ! empty( $assoc['repair'] ) ) {
			foreach ( $this->plugin->diagnostics->repair() as $done ) {
				\WP_CLI::log( $done );
			}
		}
		$result = $this->plugin->diagnostics->run( true );
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $result['checks'], array( 'status', 'label', 'detail' ) );
		if ( $result['counts']['fail'] > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Connect to Invoice Ninja. Reads the token from STDIN so it stays out of shell history.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Invoice Ninja address, e.g. https://invoicing.co.
	 *
	 * [--allow-private]
	 * : Allow a host on a private network.
	 *
	 * [--yes]
	 * : Confirm switching to a different company.
	 *
	 * ## EXAMPLES
	 *
	 *     wp invoiceninja connect https://billing.example.com < token.txt
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function connect( array $args, array $assoc ): void {
		if ( $this->plugin->credentials->locked() ) {
			\WP_CLI::error( 'The connection is set in wp-config.php (INVOICENINJA_API_URL and INVOICENINJA_API_TOKEN). Change it there.' );
		}
		$token = trim( (string) stream_get_contents( STDIN ) );
		if ( '' === $token ) {
			\WP_CLI::error( 'Pipe the API token on STDIN, e.g. wp invoiceninja connect https://invoicing.co < token.txt' );
		}
		$result = $this->plugin->connection->connect( (string) $args[0], $token, ! empty( $assoc['allow-private'] ), ! empty( $assoc['yes'] ) );
		foreach ( $result['checks'] as $check ) {
			\WP_CLI::log( sprintf( '%-5s %s %s', strtoupper( $check['status'] ), $check['label'], $check['detail'] ) );
		}
		if ( ! empty( $result['needs_switch_confirmation'] ) ) {
			\WP_CLI::error( sprintf( 'This token is for %s, not %s. Run again with --yes to switch companies.', $result['needs_switch_confirmation']['to'], $result['needs_switch_confirmation']['from'] ) );
		}
		if ( empty( $result['saved'] ) ) {
			\WP_CLI::error( (string) ( $result['error']['title'] ?? 'Connection failed' ) . '. ' . (string) ( $result['error']['fix'] ?? '' ) );
		}
		\WP_CLI::success( sprintf( 'Connected to %s.', (string) $result['company']['name'] ) );
	}

	/**
	 * Remove the token and stop syncing. Products, orders and settings stay.
	 *
	 * ## EXAMPLES
	 *
	 *     wp invoiceninja disconnect
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function disconnect( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$this->plugin->connection->disconnect();
		\WP_CLI::success( 'Disconnected.' );
	}

	/**
	 * Allow or pause orders from this site.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : confirm (allow orders from this site as it is now) or pause.
	 * ---
	 * options:
	 *   - confirm
	 *   - pause
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp invoiceninja environment confirm
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function environment( array $args, array $assoc ): void {
		unset( $assoc );
		if ( 'confirm' === $args[0] ) {
			$this->plugin->environment->confirm( get_current_user_id() );
			$this->plugin->logger->info( 'connection', 'Orders allowed from this site (WP-CLI)' );
			\WP_CLI::success( 'Orders are allowed from this site.' );
			return;
		}
		$this->plugin->environment->pause();
		$this->plugin->logger->info( 'connection', 'Orders paused on this site (WP-CLI)' );
		\WP_CLI::success( 'Orders are paused on this site.' );
	}
}
