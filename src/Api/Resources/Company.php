<?php
/**
 * The company a token belongs to.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api\Resources;

use InvoiceNinja\Api\HttpClient;

/**
 * companies/current includes mail-provider secrets in its settings, so only an
 * allowlist of fields is ever kept.
 */
final class Company {

	/**
	 * Fetch the current company, reduced to the fields the plugin uses.
	 *
	 * @param HttpClient $client Client.
	 * @param float      $budget Seconds.
	 * @return array<string, mixed>
	 */
	public static function current( HttpClient $client, float $budget = 15.0 ): array {
		return self::summary( $client->post( 'companies/current', null, array(), $budget, true )->data() );
	}

	/**
	 * Reduce a company record to the allowlist.
	 *
	 * @param array<mixed> $company Company record.
	 * @return array<string, mixed>
	 */
	public static function summary( array $company ): array {
		$settings = is_array( $company['settings'] ?? null ) ? $company['settings'] : array();
		return array(
			'id'              => (string) ( $company['id'] ?? '' ),
			'name'            => sanitize_text_field( (string) ( $settings['name'] ?? '' ) ),
			'logo'            => esc_url_raw( (string) ( $settings['company_logo'] ?? '' ) ),
			'email'           => sanitize_email( (string) ( $settings['email'] ?? '' ) ),
			'currency_id'     => (string) ( $settings['currency_id'] ?? '' ),
			'country_id'      => (string) ( $settings['country_id'] ?? '' ),
			'portal_password' => ! empty( $settings['enable_client_portal_password'] ),
			'portal_enabled'  => ! array_key_exists( 'enable_client_portal', $settings ) || ! empty( $settings['enable_client_portal'] ),
			'track_inventory' => ! empty( $company['track_inventory'] ),
			'calculate_taxes' => ! empty( $company['calculate_taxes'] ),
			'inclusive_taxes' => ! empty( $settings['inclusive_taxes'] ),
			'portal_domain'   => esc_url_raw( (string) ( $company['portal_domain'] ?? '' ) ),
			'subdomain'       => sanitize_key( (string) ( $company['subdomain'] ?? '' ) ),
		);
	}
}
