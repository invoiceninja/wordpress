<?php
/**
 * What the plugin knows about each subscriber's lifecycle.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Core\Options;

/**
 * One row per subscription purchase, keyed by its recurring invoice (or subscription and
 * client for one-off purchases): status (active, trial, suspended, cancelled), ids, since.
 * Invoice Ninja has no suspended state, so this is where suspension lives. Also keeps the
 * ids of events already fired, so a repeated callback fires nothing twice.
 */
final class State {

	public const OPTION = 'subscriptions_state';

	public const FIRED = 'subscriptions_fired';

	public const MAX = 5000;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * The key for a purchase.
	 *
	 * @param string $recurring    Recurring invoice id.
	 * @param string $subscription Subscription id.
	 * @param string $client       Client id.
	 */
	public static function key( string $recurring, string $subscription, string $client ): string {
		return '' !== $recurring ? 'ri:' . $recurring : 's:' . $subscription . ':c:' . $client;
	}

	/**
	 * Every row.
	 *
	 * @return array<string, array{status: string, subscription: string, client: string, recurring_invoice: string, invoice: string, since: int, updated: int}>
	 */
	public function all(): array {
		$rows = $this->options->get( self::OPTION, array() );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $key => $row ) {
			if ( is_array( $row ) ) {
				$out[ (string) $key ] = array(
					'status'            => (string) ( $row['status'] ?? 'active' ),
					'subscription'      => (string) ( $row['subscription'] ?? '' ),
					'client'            => (string) ( $row['client'] ?? '' ),
					'recurring_invoice' => (string) ( $row['recurring_invoice'] ?? '' ),
					'invoice'           => (string) ( $row['invoice'] ?? '' ),
					'since'             => (int) ( $row['since'] ?? 0 ),
					'updated'           => (int) ( $row['updated'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	/**
	 * One row.
	 *
	 * @param string $key Key.
	 * @return array{status: string, subscription: string, client: string, recurring_invoice: string, invoice: string, since: int, updated: int}|null
	 */
	public function get( string $key ): ?array {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Save a row.
	 *
	 * @param string               $key Key.
	 * @param array<string, mixed> $row Fields.
	 */
	public function set( string $key, array $row ): void {
		$all  = $this->all();
		$old  = $all[ $key ] ?? null;
		$next = array_merge(
			$old ?? array(
				'status'            => 'active',
				'subscription'      => '',
				'client'            => '',
				'recurring_invoice' => '',
				'invoice'           => '',
				'since'             => time(),
				'updated'           => time(),
			),
			$row
		);
		if ( null !== $old && ( $row['status'] ?? $old['status'] ) !== $old['status'] ) {
			$next['since'] = time();
		}
		$next['updated'] = time();
		$all[ $key ]     = $next;
		if ( count( $all ) > self::MAX ) {
			uasort( $all, static fn( array $a, array $b ): int => ( 'cancelled' === $a['status'] ? 0 : 1 ) <=> ( 'cancelled' === $b['status'] ? 0 : 1 ) ?: $a['updated'] <=> $b['updated'] );
			$all = array_slice( $all, count( $all ) - self::MAX, null, true );
		}
		$this->options->set( self::OPTION, $all );
	}

	/**
	 * Rows for one client.
	 *
	 * @param string $client Client id.
	 * @return array<string, array{status: string, subscription: string, client: string, recurring_invoice: string, invoice: string, since: int, updated: int}>
	 */
	public function forClient( string $client ): array {
		return array_filter( $this->all(), static fn( array $row ): bool => $row['client'] === $client );
	}

	/**
	 * Whether an event id was already recorded.
	 *
	 * @param string $id Event id.
	 */
	public function seen( string $id ): bool {
		return in_array( $id, (array) $this->options->get( self::FIRED, array() ), true );
	}

	/**
	 * Record an event id; false when it was already recorded.
	 *
	 * @param string $id Event id.
	 */
	public function firstTime( string $id ): bool {
		$fired = (array) $this->options->get( self::FIRED, array() );
		if ( in_array( $id, $fired, true ) ) {
			return false;
		}
		$fired[] = $id;
		$this->options->set( self::FIRED, array_slice( $fired, -500 ) );
		return true;
	}
}
