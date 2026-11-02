<?php
/**
 * The Invoice Ninja webhook events the plugin listens to.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Webhooks;

/**
 * Event ids are Invoice Ninja's (App\Models\Webhook::EVENT_*). Webhook payloads carry no
 * event id and aren't signed, so each event gets its own webhook whose URL ends in
 * ?event=<id>, and the entity is always fetched again by id before anything happens.
 */
final class Events {

	/**
	 * Event id => [entity, event].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public const MAP = array(
		1  => array( 'client', 'created' ),
		10 => array( 'client', 'updated' ),
		11 => array( 'client', 'deleted' ),
		37 => array( 'client', 'archived' ),
		45 => array( 'client', 'restored' ),
		2  => array( 'invoice', 'created' ),
		8  => array( 'invoice', 'updated' ),
		9  => array( 'invoice', 'deleted' ),
		33 => array( 'invoice', 'archived' ),
		41 => array( 'invoice', 'restored' ),
		60 => array( 'invoice', 'sent' ),
		24 => array( 'invoice', 'reminder' ),
		22 => array( 'invoice', 'late' ),
		4  => array( 'payment', 'created' ),
		31 => array( 'payment', 'updated' ),
		12 => array( 'payment', 'deleted' ),
		32 => array( 'payment', 'archived' ),
		40 => array( 'payment', 'restored' ),
		21 => array( 'quote', 'approved' ),
		50 => array( 'product', 'created' ),
		51 => array( 'product', 'updated' ),
		52 => array( 'product', 'deleted' ),
		53 => array( 'product', 'restored' ),
		54 => array( 'product', 'archived' ),
	);

	/**
	 * Every event id we register.
	 *
	 * @return list<int>
	 */
	public static function ids(): array {
		return array_keys( self::MAP );
	}

	/**
	 * Whether we listen to this event.
	 *
	 * @param int $id Event id.
	 */
	public static function known( int $id ): bool {
		return isset( self::MAP[ $id ] );
	}

	/**
	 * The entity an event is about (client, invoice, payment, quote or product).
	 *
	 * @param int $id Event id.
	 */
	public static function entity( int $id ): string {
		return self::MAP[ $id ][0] ?? '';
	}

	/**
	 * The event name (created, updated, deleted, archived, restored, sent, approved, late, reminder).
	 *
	 * @param int $id Event id.
	 */
	public static function name( int $id ): string {
		return self::MAP[ $id ][1] ?? '';
	}

	/**
	 * The API path to fetch the entity again, with the query it needs.
	 *
	 * @param int    $id        Event id.
	 * @param string $entity_id Entity id.
	 * @return array{0: string, 1: array<string, string>}
	 */
	public static function fetch( int $id, string $entity_id ): array {
		$entity = self::entity( $id );
		$query  = 'payment' === $entity ? array( 'include' => 'invoices' ) : array();
		return array( $entity . 's/' . rawurlencode( $entity_id ), $query );
	}

	/**
	 * Keep an id to the characters Invoice Ninja's hashed ids use.
	 *
	 * @param mixed $id Raw id.
	 */
	public static function cleanId( mixed $id ): string {
		return is_scalar( $id ) ? substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id ), 0, 64 ) : '';
	}

	/**
	 * Invoice ids a payment applies to (from the invoices include and its paymentables).
	 *
	 * @param array<string, mixed> $payment Payment.
	 * @return list<string>
	 */
	public static function paymentInvoiceIds( array $payment ): array {
		$ids = array();
		foreach ( (array) ( $payment['invoices'] ?? array() ) as $invoice ) {
			if ( is_array( $invoice ) ) {
				$ids[] = self::cleanId( $invoice['id'] ?? '' );
			}
		}
		foreach ( (array) ( $payment['paymentables'] ?? array() ) as $line ) {
			if ( is_array( $line ) && isset( $line['invoice_id'] ) ) {
				$ids[] = self::cleanId( $line['invoice_id'] );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
