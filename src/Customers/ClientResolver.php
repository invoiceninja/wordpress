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
 * never changed by findOrCreate: its name, phone and address stay as the business keeps
 * them. The one exception is updateShipping, for the delivery address checkout asked for.
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
	 *                                      shipping_address1 … shipping_country_id, currency_id,
	 *                                      plus `client` (extra client fields).
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
		foreach ( array( 'address1', 'address2', 'city', 'state', 'postal_code', 'country_id', 'phone', 'vat_number', 'shipping_address1', 'shipping_address2', 'shipping_city', 'shipping_state', 'shipping_postal_code', 'shipping_country_id' ) as $field ) {
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

	/**
	 * Save a delivery address as a client's shipping address. Only the shipping fields are
	 * sent, plus the client's own currency unchanged: Invoice Ninja fills an update without
	 * one with the company's currency (UpdateClientRequest::prepareForValidation), which
	 * would pin a client that follows its group or the company. Callers skip clients that
	 * have no currency of their own.
	 *
	 * @param HttpClient            $client      API client.
	 * @param string                $client_id   Client id.
	 * @param array<string, string> $address     shipping_address1, shipping_address2, shipping_city, shipping_state, shipping_postal_code, shipping_country_id.
	 * @param string                $currency_id The client's own currency, sent back as it is.
	 * @throws ApiException When Invoice Ninja can't be asked or refuses.
	 */
	public function updateShipping( HttpClient $client, string $client_id, array $address, string $currency_id = '' ): void {
		$body = array();
		foreach ( array( 'shipping_address1', 'shipping_address2', 'shipping_city', 'shipping_state', 'shipping_postal_code', 'shipping_country_id' ) as $field ) {
			$body[ $field ] = (string) ( $address[ $field ] ?? '' );
		}
		if ( '' === $body['shipping_country_id'] ) {
			unset( $body['shipping_country_id'] ); // An integer field: leave it as it is.
		}
		if ( '' !== $currency_id ) {
			$body['settings'] = array( 'currency_id' => $currency_id );
		}
		$client->put( 'clients/' . rawurlencode( $client_id ), $body, array(), 5.0 );
		$this->plugin->logger->info( 'customers', 'Saved a delivery address on an Invoice Ninja client' );
	}
}
