<?php
/**
 * The stable payload of subscription lifecycle events.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

/**
 * Version 1. The same array is passed to the invoiceninja_subscription_* actions and sent
 * by the ProvisioningRelay. Fields are only ever added in version 1; anything that changes
 * or removes a field becomes version 2. Schema: docs/hosting.md.
 */
final class Payload {

	public const VERSION = 1;

	public const EVENTS = array( 'purchased', 'trial', 'plan_changed', 'paid', 'cancelled', 'suspended', 'resumed' );

	/**
	 * Build the payload.
	 *
	 * @param string                    $event        One of EVENTS.
	 * @param string                    $context      Invoice Ninja's callback context, or poll / webhook.
	 * @param array<string, mixed>      $subscription Subscription (as SubscriptionSync keeps it, or raw).
	 * @param array<string, mixed>      $client       Client, fetched again by id.
	 * @param array<string, mixed>|null $recurring    Recurring invoice, fetched again by id.
	 * @param array<string, mixed>|null $invoice      Invoice, fetched again by id.
	 * @param int                       $user_id      Linked WordPress user, or 0.
	 * @param int                       $time         When it happened (Unix time).
	 * @param int                       $occurrence   Which time this event happens (a subscription can be suspended again for the same invoice).
	 * @return array<string, mixed>
	 */
	public static function build( string $event, string $context, array $subscription, array $client, ?array $recurring, ?array $invoice, int $user_id, int $time, int $occurrence = 1 ): array {
		$contacts = array();
		foreach ( (array) ( $client['contacts'] ?? array() ) as $contact ) {
			if ( is_array( $contact ) ) {
				$contacts[] = array(
					'id'         => (string) ( $contact['id'] ?? '' ),
					'first_name' => (string) ( $contact['first_name'] ?? '' ),
					'last_name'  => (string) ( $contact['last_name'] ?? '' ),
					'email'      => (string) ( $contact['email'] ?? '' ),
					'phone'      => (string) ( $contact['phone'] ?? '' ),
					'is_primary' => (bool) ( $contact['is_primary'] ?? false ),
				);
			}
		}
		$primary = $contacts[0]['email'] ?? '';
		foreach ( $contacts as $contact ) {
			if ( $contact['is_primary'] && '' !== $contact['email'] ) {
				$primary = $contact['email'];
				break;
			}
		}
		$payload = array(
			'version'           => self::VERSION,
			'id'                => self::id( $event, (string) ( $subscription['id'] ?? '' ), (string) ( $client['id'] ?? '' ), (string) ( $recurring['id'] ?? '' ), (string) ( $invoice['id'] ?? '' ), $context, $occurrence ),
			'event'             => $event,
			'context'           => $context,
			'occurred_at'       => gmdate( 'Y-m-d\TH:i:s\Z', $time ),
			'subscription'      => array(
				'id'           => (string) ( $subscription['id'] ?? '' ),
				'name'         => (string) ( $subscription['name'] ?? '' ),
				'price'        => (float) ( $subscription['price'] ?? 0 ),
				'frequency_id' => (int) ( $subscription['frequency_id'] ?? 0 ),
			),
			'client'            => array(
				'id'            => (string) ( $client['id'] ?? '' ),
				'number'        => (string) ( $client['number'] ?? '' ),
				'name'          => (string) ( $client['name'] ?? '' ),
				'email'         => $primary,
				'account_key'   => (string) ( $client['custom_value2'] ?? '' ),
				'custom_value1' => (string) ( $client['custom_value1'] ?? '' ),
				'custom_value2' => (string) ( $client['custom_value2'] ?? '' ),
				'custom_value3' => (string) ( $client['custom_value3'] ?? '' ),
				'custom_value4' => (string) ( $client['custom_value4'] ?? '' ),
				'contacts'      => $contacts,
			),
			'recurring_invoice' => null,
			'invoice'           => null,
			'wordpress_user_id' => $user_id,
		);
		if ( is_array( $recurring ) && ! empty( $recurring['id'] ) ) {
			$payload['recurring_invoice'] = array(
				'id'               => (string) $recurring['id'],
				'number'           => (string) ( $recurring['number'] ?? '' ),
				'status_id'        => (int) ( $recurring['status_id'] ?? 0 ),
				'frequency_id'     => (int) ( $recurring['frequency_id'] ?? 0 ),
				'next_send_date'   => (string) ( $recurring['next_send_date'] ?? '' ),
				'remaining_cycles' => (int) ( $recurring['remaining_cycles'] ?? 0 ),
				'amount'           => (float) ( $recurring['amount'] ?? 0 ),
				'line_items'       => self::lines( $recurring ),
			);
		}
		if ( is_array( $invoice ) && ! empty( $invoice['id'] ) ) {
			$payload['invoice'] = array(
				'id'           => (string) $invoice['id'],
				'number'       => (string) ( $invoice['number'] ?? '' ),
				'status_id'    => (int) ( $invoice['status_id'] ?? 0 ),
				'amount'       => (float) ( $invoice['amount'] ?? 0 ),
				'balance'      => (float) ( $invoice['balance'] ?? 0 ),
				'paid_to_date' => (float) ( $invoice['paid_to_date'] ?? 0 ),
				'due_date'     => (string) ( $invoice['due_date'] ?? '' ),
				'line_items'   => self::lines( $invoice ),
			);
		}
		return $payload;
	}

	/**
	 * A stable id for one event, so receivers can ignore a repeat delivery.
	 *
	 * @param string $event        Event.
	 * @param string $subscription Subscription id.
	 * @param string $client       Client id.
	 * @param string $recurring    Recurring invoice id.
	 * @param string $invoice      Invoice id.
	 * @param string $context      Context.
	 * @param int    $occurrence   Which time it happens; the first keeps the id it always had.
	 */
	public static function id( string $event, string $subscription, string $client, string $recurring, string $invoice, string $context, int $occurrence = 1 ): string {
		$parts = array( $event, $subscription, $client, $recurring, $invoice, $context );
		if ( $occurrence > 1 ) {
			$parts[] = '#' . $occurrence;
		}
		return 'evt_' . substr( hash( 'sha256', implode( '|', $parts ) ), 0, 32 );
	}

	/**
	 * Line items reduced to what provisioning needs.
	 *
	 * @param array<string, mixed> $document Invoice or recurring invoice.
	 * @return list<array{product_key: string, notes: string, quantity: float, cost: float}>
	 */
	private static function lines( array $document ): array {
		$out = array();
		foreach ( (array) ( $document['line_items'] ?? array() ) as $line ) {
			if ( is_array( $line ) ) {
				$out[] = array(
					'product_key' => (string) ( $line['product_key'] ?? '' ),
					'notes'       => (string) ( $line['notes'] ?? '' ),
					'quantity'    => (float) ( $line['quantity'] ?? 0 ),
					'cost'        => (float) ( $line['cost'] ?? 0 ),
				);
			}
		}
		return $out;
	}
}
