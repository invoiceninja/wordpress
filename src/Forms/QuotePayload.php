<?php
/**
 * Builds the Invoice Ninja quote for a lead, and reads quotes back.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

/**
 * Pure helpers. Invoice Ninja has no idempotency key for quotes, so each lead's quote
 * carries a unique marker in private_notes; after an unknown result the client's recent
 * quotes are searched for it before creating again.
 */
final class QuotePayload {

	/**
	 * Quote status_id to our status name.
	 */
	public const STATUSES = array(
		'1'  => 'draft',
		'2'  => 'sent',
		'3'  => 'approved',
		'4'  => 'converted',
		'5'  => 'rejected',
		'6'  => 'cancelled',
		'-1' => 'expired',
	);

	/**
	 * Quote statuses that may still change, so the daily refresh checks them.
	 */
	public const OPEN = array( 'draft', 'sent' );

	/**
	 * A tag name Invoice Ninja accepts: no commas, single spaces, at most 191 characters.
	 *
	 * @param string $name Name.
	 */
	public static function tagName( string $name ): string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( ',', ' ', wp_strip_all_tags( $name ) ) ) );
		return mb_substr( $name, 0, 191 );
	}

	/**
	 * Tag names from a comma-separated setting (or a list), cleaned and without repeats.
	 *
	 * @param string|list<string> $names Names.
	 * @return list<string>
	 */
	public static function tagNames( string|array $names ): array {
		$out  = array();
		$seen = array();
		foreach ( is_array( $names ) ? $names : explode( ',', $names ) as $name ) {
			$name = self::tagName( (string) $name );
			$key  = mb_strtolower( $name );
			if ( '' !== $name && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out[]        = $name;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * The marker that identifies this lead's quote.
	 *
	 * @param string $site_code Site code.
	 * @param int    $lead_id   Lead id.
	 * @param string $nonce     Random part, fixed per lead.
	 */
	public static function marker( string $site_code, int $lead_id, string $nonce ): string {
		return 'wp_' . strtolower( $site_code ) . '_lead_' . $lead_id . '_' . $nonce;
	}

	/**
	 * private_notes: what the requester sent that the quote itself doesn't show, plus the marker.
	 *
	 * @param string                $marker   Marker.
	 * @param array<string, string> $fields   Submitted fields.
	 * @param array<string, mixed>  $consents Consents (which => {at, title}).
	 * @param string                $site     Site host.
	 */
	public static function privateNotes( string $marker, array $fields, array $consents, string $site ): string {
		$lines = array(
			/* translators: %s: site host name */
			sprintf( __( 'Quote Request Form on %s', 'invoiceninja' ), $site ),
		);
		if ( '' !== ( $fields['phone'] ?? '' ) ) {
			/* translators: %s: phone number */
			$lines[] = sprintf( __( 'Phone: %s', 'invoiceninja' ), $fields['phone'] );
		}
		if ( FormConfig::NOT_SURE === ( $fields['product'] ?? '' ) ) {
			$lines[] = __( 'Product: Not sure yet', 'invoiceninja' );
		}
		foreach ( $consents as $consent ) {
			if ( is_array( $consent ) && ! empty( $consent['agreed'] ) ) {
				/* translators: 1: page title, 2: date and time (UTC) */
				$lines[] = sprintf( __( 'Agreed to %1$s at %2$s UTC', 'invoiceninja' ), (string) ( $consent['title'] ?? '' ), gmdate( 'Y-m-d H:i', (int) ( $consent['at'] ?? 0 ) ) );
			}
		}
		$lines[] = 'Ref: ' . $marker;
		return implode( "\n", $lines );
	}

	/**
	 * The body of POST /quotes.
	 *
	 * @param string                                                     $client_id     Client id.
	 * @param string                                                     $public_notes  The requester's message.
	 * @param string                                                     $private_notes Notes with the marker.
	 * @param array{product_key: string, notes?: string, price: float}|null $product    The chosen product (prices from our synced copy, never the browser).
	 * @param list<string>                                               $tag_ids       Existing tag ids (empty: no tags sent).
	 * @return array<string, mixed>
	 */
	public static function quoteBody( string $client_id, string $public_notes, string $private_notes, ?array $product, array $tag_ids ): array {
		$body = array(
			'client_id'     => $client_id,
			'public_notes'  => $public_notes,
			'private_notes' => $private_notes,
			'line_items'    => array(),
		);
		if ( null !== $product && '' !== $product['product_key'] ) {
			$body['line_items'][] = array(
				'product_key' => $product['product_key'],
				'notes'       => (string) ( $product['notes'] ?? '' ),
				'cost'        => round( (float) $product['price'], 6 ),
				'quantity'    => 1,
			);
		}
		if ( $tag_ids ) {
			// Sending tags replaces the quote's tag list; a new quote has none to lose.
			$body['tags'] = array_values( array_unique( $tag_ids ) );
		}
		return $body;
	}

	/**
	 * This lead's quote among a client's quotes, if Invoice Ninja already has it.
	 *
	 * @param array<mixed> $quotes    Quotes from GET /quotes.
	 * @param string       $marker    Marker.
	 * @param string       $client_id Client id.
	 * @return array<string, mixed>|null
	 */
	public static function findByMarker( array $quotes, string $marker, string $client_id ): ?array {
		foreach ( $quotes as $quote ) {
			if ( ! is_array( $quote ) || ! empty( $quote['is_deleted'] ) ) {
				continue;
			}
			if ( (string) ( $quote['client_id'] ?? '' ) !== $client_id ) {
				continue;
			}
			// The whole marker on its own line, so lead 1 never matches lead 12.
			if ( in_array( 'Ref: ' . $marker, array_map( 'trim', explode( "\n", (string) ( $quote['private_notes'] ?? '' ) ) ), true ) ) {
				return $quote;
			}
		}
		return null;
	}

	/**
	 * Our name for a quote's status.
	 *
	 * @param array<string, mixed> $quote Quote.
	 */
	public static function status( array $quote ): string {
		if ( ! empty( $quote['is_deleted'] ) ) {
			return 'deleted';
		}
		return self::STATUSES[ (string) ( $quote['status_id'] ?? '1' ) ] ?? 'draft';
	}
}
