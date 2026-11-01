<?php
/**
 * Payment gateways set up in Invoice Ninja.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api\Resources;

use InvoiceNinja\Api\HttpClient;

/**
 * Only names are kept; gateway configuration contains credentials.
 */
final class CompanyGateways {

	/**
	 * Labels of active gateways.
	 *
	 * @param HttpClient $client Client.
	 * @param float      $budget Seconds.
	 * @return list<string>
	 */
	public static function activeLabels( HttpClient $client, float $budget = 10.0 ): array {
		$labels = array();
		foreach ( $client->get(
			'company_gateways',
			array(
				'status'   => 'active',
				'per_page' => 50,
			),
			$budget
		)->data() as $gateway ) {
			if ( ! is_array( $gateway ) || ! empty( $gateway['is_deleted'] ) || ! empty( $gateway['archived_at'] ) ) {
				continue;
			}
			$label    = (string) ( $gateway['label'] ?? '' );
			$labels[] = sanitize_text_field( '' !== $label ? $label : (string) ( $gateway['gateway_key'] ?? 'Gateway' ) );
		}
		return array_values( array_unique( $labels ) );
	}
}
