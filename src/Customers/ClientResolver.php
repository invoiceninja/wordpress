<?php
/**
 * Find an Invoice Ninja client by a contact's email, or create one.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Customers;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Plugin;

/**
 * Shared by checkout, the quote form, WooCommerce and customer sync. Matching is on the
 * contact email (active and archived clients, never deleted ones). An existing client is
 * never changed here: its name, phone and address stay as the business keeps them.
 */
final class ClientResolver {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * The client with a contact using this email.
	 *
	 * @param HttpClient $client API client.
	 * @param string     $email  Email.
	 * @return array{client_id: string, contact_id: string, client: array<string, mixed>}|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	public function find( HttpClient $client, string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		foreach ( $client->get(
			'clients',
			array(
				'email'  => $email,
				'status' => 'active,archived',
			),
			5.0
		)->data() as $candidate ) {
			if ( ! is_array( $candidate ) || ! empty( $candidate['is_deleted'] ) ) {
				continue;
			}
			foreach ( (array) ( $candidate['contacts'] ?? array() ) as $contact ) {
				if ( is_array( $contact ) && strtolower( (string) ( $contact['email'] ?? '' ) ) === $email ) {
					return array(
						'client_id'  => (string) $candidate['id'],
						'contact_id' => (string) ( $contact['id'] ?? '' ),
						'client'     => $candidate,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Find the client, or create one with these details.
	 *
	 * @param HttpClient           $client  API client.
	 * @param string               $email   Email.
	 * @param array<string, mixed> $details first_name, last_name, phone, company, and optional
	 *                                      address1, address2, city, state, postal_code, country_id,
	 *                                      currency_id, plus `client` (extra client fields).
	 * @return array{client_id: string, contact_id: string, created: bool, client: array<string, mixed>}
	 * @throws ApiException When Invoice Ninja can't be asked or refuses.
	 */
	public function findOrCreate( HttpClient $client, string $email, array $details ): array {
		$found = $this->find( $client, $email );
		if ( null !== $found ) {
			return $found + array( 'created' => false );
		}
		$email = strtolower( trim( $email ) );
		$first = (string) ( $details['first_name'] ?? '' );
		$last  = (string) ( $details['last_name'] ?? '' );
		$name  = '' !== trim( (string) ( $details['company'] ?? '' ) ) ? (string) $details['company'] : trim( $first . ' ' . $last );
		$body  = array(
			'name'     => '' !== $name ? $name : $email,
			'contacts' => array(
				array(
					'first_name' => $first,
					'last_name'  => $last,
					'email'      => $email,
					'phone'      => (string) ( $details['phone'] ?? '' ),
				),
			),
		);
		foreach ( array( 'address1', 'address2', 'city', 'state', 'postal_code', 'country_id', 'phone', 'vat_number' ) as $field ) {
			if ( isset( $details[ $field ] ) && '' !== (string) $details[ $field ] ) {
				$body[ $field ] = (string) $details[ $field ];
			}
		}
		// Invoice Ninja keeps a client's currency in its settings.
		if ( ! empty( $details['currency_id'] ) ) {
			$body['settings'] = array( 'currency_id' => (string) $details['currency_id'] );
		}
		if ( isset( $details['client'] ) && is_array( $details['client'] ) ) {
			$body += $details['client'];
		}
		$created = $client->post( 'clients', $body, array(), 5.0 )->data();
		$this->plugin->logger->info( 'customers', sprintf( 'Created an Invoice Ninja client for %s', \InvoiceNinja\Core\LogRedactor::text( $email ) ) );
		return array(
			'client_id'  => (string) ( $created['id'] ?? '' ),
			'contact_id' => (string) ( $created['contacts'][0]['id'] ?? '' ),
			'created'    => true,
			'client'     => $created,
		);
	}
}
