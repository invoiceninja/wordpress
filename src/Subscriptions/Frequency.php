<?php
/**
 * Invoice Ninja billing frequencies.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

/**
 * Ids are App\Models\RecurringInvoice::FREQUENCY_* (1 daily … 12 every three years).
 */
final class Frequency {

	/**
	 * "per month"-style text shown after a price.
	 *
	 * @param int $id Frequency id.
	 */
	public static function per( int $id ): string {
		return match ( $id ) {
			1       => __( 'per day', 'invoiceninja' ),
			2       => __( 'per week', 'invoiceninja' ),
			3       => __( 'every 2 weeks', 'invoiceninja' ),
			4       => __( 'every 4 weeks', 'invoiceninja' ),
			5       => __( 'per month', 'invoiceninja' ),
			6       => __( 'every 2 months', 'invoiceninja' ),
			7       => __( 'per quarter', 'invoiceninja' ),
			8       => __( 'every 4 months', 'invoiceninja' ),
			9       => __( 'every 6 months', 'invoiceninja' ),
			10      => __( 'per year', 'invoiceninja' ),
			11      => __( 'every 2 years', 'invoiceninja' ),
			12      => __( 'every 3 years', 'invoiceninja' ),
			default => '',
		};
	}

	/**
	 * A name for the billing-period toggle ("Monthly", "Yearly").
	 *
	 * @param int $id Frequency id.
	 */
	public static function period( int $id ): string {
		return match ( $id ) {
			1       => __( 'Daily', 'invoiceninja' ),
			2       => __( 'Weekly', 'invoiceninja' ),
			3, 4    => __( 'Every few weeks', 'invoiceninja' ),
			5       => __( 'Monthly', 'invoiceninja' ),
			6, 7, 8 => __( 'Quarterly', 'invoiceninja' ),
			9       => __( 'Every 6 months', 'invoiceninja' ),
			10      => __( 'Yearly', 'invoiceninja' ),
			11, 12  => __( 'Multi-year', 'invoiceninja' ),
			default => __( 'One-off', 'invoiceninja' ),
		};
	}
}
