<?php
/**
 * WP-CLI: wp invoiceninja woo …
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

/**
 * Invoice WooCommerce orders.
 */
final class WooCommand {

	/**
	 * Constructor.
	 *
	 * @param WooCommerceModule $module Module.
	 */
	public function __construct( private WooCommerceModule $module ) {}

	/**
	 * Invoice past WooCommerce orders in a date range, through the queue. Nobody is emailed.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<date>]
	 * : First day, YYYY-MM-DD. Defaults to 30 days ago.
	 *
	 * [--to=<date>]
	 * : Last day, YYYY-MM-DD. Defaults to today.
	 *
	 * [--dry-run]
	 * : Only count what would be invoiced, paid and refunded.
	 *
	 * [--status]
	 * : Show the progress of the current or last backfill.
	 *
	 * ## EXAMPLES
	 *
	 *     wp invoiceninja woo backfill --from=2026-01-01 --to=2026-03-31 --dry-run
	 *     wp invoiceninja woo backfill --from=2026-01-01 --to=2026-03-31
	 *     wp invoiceninja woo backfill --status
	 *
	 * @param array<int, string>         $args  Arguments.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function backfill( array $args, array $assoc ): void {
		unset( $args );
		if ( ! $this->module->active() ) {
			\WP_CLI::error( 'The WooCommerce module is off, or WooCommerce is missing or too old.' );
		}
		$backfill = $this->module->backfill;
		if ( null === $backfill ) {
			\WP_CLI::error( 'The WooCommerce module is not running.' );
		}
		if ( ! empty( $assoc['status'] ) ) {
			$this->table( $backfill->status() );
			return;
		}
		$from   = (string) ( $assoc['from'] ?? wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) );
		$to     = (string) ( $assoc['to'] ?? wp_date( 'Y-m-d' ) );
		$result = array();
		try {
			$result = $backfill->start( $from, $to, ! empty( $assoc['dry-run'] ) );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$this->table( $result );
		if ( ! empty( $assoc['dry-run'] ) ) {
			\WP_CLI::success( sprintf( 'Dry run: %d orders would be invoiced, %d payments and %d refunds recorded. Nothing was changed.', (int) $result['invoice'], (int) $result['payment'], (int) $result['refunds'] ) );
			return;
		}
		\WP_CLI::success( sprintf( 'Backfill started for %d orders. Follow it with: wp invoiceninja woo backfill --status', (int) ( $result['total'] ?? 0 ) ) );
	}

	/**
	 * Print a key/value table.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	private function table( array $data ): void {
		$rows = array();
		foreach ( $data as $field => $value ) {
			$rows[] = array(
				'field' => (string) $field,
				'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}
