<?php
/**
 * Keeps a local copy of the company's Invoice Ninja subscriptions.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\Paginator;
use InvoiceNinja\Plugin;
use InvoiceNinja\Webhooks\Events;

/**
 * Refreshed hourly in the background (and on demand from Settings), so pages with a Pricing
 * Table never wait for Invoice Ninja. A failed refresh keeps the last good copy.
 */
final class SubscriptionSync {

	public const OPTION = 'subscriptions_cache';

	public const HOOK = 'invoiceninja_subscriptions_sync';

	/**
	 * Ids of subscriptions an admin hid from this site.
	 */
	public const HIDDEN = 'subscriptions_hidden';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Every known subscription, by id (archived and deleted ones left out).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$cache = $this->cache();
		return array_filter( $cache['items'], static fn( array $s ): bool => ! $s['archived'] );
	}

	/**
	 * One subscription, if known.
	 *
	 * @param string $id Id.
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		$items = $this->all();
		return $items[ $id ] ?? null;
	}

	/**
	 * Subscriptions this site offers: known, not archived and not hidden. Blocks and the
	 * subscribe link use these; customers' own subscriptions (Account, callbacks) don't care.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function shown(): array {
		return array_diff_key( $this->all(), $this->hidden() );
	}

	/**
	 * One subscription this site offers, if any.
	 *
	 * @param string $id Id.
	 * @return array<string, mixed>|null
	 */
	public function findShown( string $id ): ?array {
		return $this->shown()[ $id ] ?? null;
	}

	/**
	 * Subscriptions hidden from this site.
	 *
	 * @return array<string, true>
	 */
	public function hidden(): array {
		$ids = $this->plugin->options->get( self::HIDDEN, array() );
		$ids = array_filter( array_map( array( Events::class, 'cleanId' ), is_array( $ids ) ? $ids : array() ) );
		return array_fill_keys( $ids, true );
	}

	/**
	 * Hide a subscription from this site, or show it again.
	 *
	 * @param string $id     Id.
	 * @param bool   $hidden Hidden.
	 */
	public function setHidden( string $id, bool $hidden ): void {
		$ids = $this->hidden();
		if ( $hidden ) {
			$ids[ $id ] = true;
		} else {
			unset( $ids[ $id ] );
		}
		$this->plugin->options->set( self::HIDDEN, array_keys( $ids ) );
	}

	/**
	 * The stored copy.
	 *
	 * @return array{at: int, items: array<string, array<string, mixed>>, error: string}
	 */
	public function cache(): array {
		$cache = $this->plugin->options->get( self::OPTION, array() );
		$cache = is_array( $cache ) ? $cache : array();
		return array(
			'at'    => (int) ( $cache['at'] ?? 0 ),
			'items' => is_array( $cache['items'] ?? null ) ? $cache['items'] : array(),
			'error' => (string) ( $cache['error'] ?? '' ),
		);
	}

	/**
	 * Fetch every subscription now.
	 *
	 * @return array<string, array<string, mixed>>
	 * @throws ApiException When Invoice Ninja can't be asked (the last good copy stays).
	 */
	public function refresh(): array {
		try {
			$items = array();
			foreach ( Paginator::each( $this->plugin->api(), 'subscriptions', array( 'status' => 'active,archived' ), 200, 20 ) as $raw ) {
				$item = self::normalize( $raw );
				if ( '' !== $item['id'] ) {
					$items[ $item['id'] ] = $item;
				}
			}
		} catch ( ApiException $e ) {
			$cache          = $this->cache();
			$cache['error'] = $e->kind->title();
			$this->plugin->options->set( self::OPTION, $cache );
			throw $e;
		}
		$this->plugin->options->set(
			self::OPTION,
			array(
				'at'    => time(),
				'items' => $items,
				'error' => '',
			)
		);
		/**
		 * Fires after the subscriptions were fetched from Invoice Ninja.
		 *
		 * @since 2.0.0
		 * @param array $items Subscriptions by id.
		 */
		do_action( 'invoiceninja_subscriptions_synced', $items );
		return $items;
	}

	/**
	 * Refresh when older than an hour (or never fetched), quietly.
	 */
	public function refreshIfStale(): void {
		if ( $this->cache()['at'] > time() - HOUR_IN_SECONDS ) {
			return;
		}
		try {
			$this->refresh();
		} catch ( ApiException $e ) {
			$this->plugin->logger->warning( 'subscriptions', "Couldn't fetch subscriptions from Invoice Ninja: " . $e->kind->title(), array( 'technical' => $e->technical ) );
		}
	}

	/**
	 * What we keep of a subscription.
	 *
	 * @param array<string, mixed> $raw Invoice Ninja subscription.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw ): array {
		$config = is_array( $raw['webhook_configuration'] ?? null ) ? $raw['webhook_configuration'] : array();
		return array(
			'id'                    => Events::cleanId( $raw['id'] ?? '' ),
			'name'                  => sanitize_text_field( (string) ( $raw['name'] ?? '' ) ),
			'price'                 => (float) ( $raw['price'] ?? 0 ),
			'promo_price'           => (float) ( $raw['promo_price'] ?? 0 ),
			'frequency_id'          => (int) ( $raw['frequency_id'] ?? 0 ),
			'currency_id'           => (string) ( $raw['currency_id'] ?? '' ),
			'recurring'             => '' !== trim( (string) ( $raw['recurring_product_ids'] ?? '' ) ),
			'purchase_page'         => esc_url_raw( (string) ( $raw['purchase_page'] ?? '' ) ),
			'trial_enabled'         => (bool) ( $raw['trial_enabled'] ?? false ),
			'trial_days'            => (int) floor( (int) ( $raw['trial_duration'] ?? 0 ) / DAY_IN_SECONDS ),
			'allow_cancellation'    => (bool) ( $raw['allow_cancellation'] ?? false ),
			'allow_plan_changes'    => (bool) ( $raw['allow_plan_changes'] ?? false ),
			'group_id'              => (string) ( $raw['group_id'] ?? '' ),
			'steps'                 => (string) ( $raw['steps'] ?? '' ),
			'archived'              => ! empty( $raw['is_deleted'] ) || ! empty( $raw['archived_at'] ),
			'webhook_configuration' => $config,
		);
	}
}
