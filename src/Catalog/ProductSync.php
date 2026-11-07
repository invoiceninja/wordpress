<?php
/**
 * Keeps WordPress product posts in step with Invoice Ninja products.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Catalog;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\Paginator;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Plugin;
use InvoiceNinja\Sync\Mapping;

/**
 * Rules (unlike 1.x, which deleted and re-created every post on each import):
 * - posts are updated in place, so post ids and URLs never change;
 * - nothing is removed because it's missing from a list; only products Invoice Ninja
 *   reports as archived or deleted become drafts (and those with a negative price, which
 *   are discounts or credits);
 * - a sync that would change more than 20% of the catalog stops before changing
 *   anything and waits for an admin (the circuit breaker). Only changes a shopper would
 *   notice count: price, name, description and being published. Stock changes (every
 *   sale changes Invoice Ninja's stock) and other details don't;
 * - unchanged products are skipped by comparing a content hash;
 * - products an admin hid from this site (Visibility) stay drafts; they're still updated,
 *   and remember the status they get back when shown again;
 * - one run writes at most BATCH products and stops after TIME_BUDGET seconds; the
 *   rest is queued as a follow-up run, so a large catalog syncs in several steps.
 */
final class ProductSync {

	public const POST_TYPE = 'invoiceninja_product';

	public const BREAKER_RATIO = 0.2;

	public const BREAKER_MIN = 10;

	/**
	 * Products one run writes at most; the rest continue in a follow-up run.
	 */
	public const BATCH = 500;

	/**
	 * Seconds one run spends at most (fetching and writing) before it stops and continues later.
	 */
	public const TIME_BUDGET = 60;

	/**
	 * Seconds after which a lock that wasn't refreshed is considered abandoned.
	 */
	public const LOCK_TTL = 600;

	/**
	 * Hook of the sync job.
	 */
	public const HOOK = 'invoiceninja_sync_products';

	/**
	 * Post meta of a product hidden from this site: the status it gets back when shown.
	 */
	public const HIDDEN_META = '_invoiceninja_hidden';

	/**
	 * Set while this plugin changes a product's status itself, so Visibility doesn't take
	 * it for an admin's choice.
	 */
	private static bool $quiet = false;

	/**
	 * The lock this object holds, if any.
	 */
	private string $token = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin  $plugin  Plugin.
	 * @param Mapping $mapping Mapping.
	 */
	public function __construct( private Plugin $plugin, private Mapping $mapping ) {}

	/**
	 * Run a status change that isn't an admin's choice (see Visibility).
	 *
	 * @template T
	 * @param callable(): T $change Change.
	 * @return T
	 */
	public static function quietly( callable $change ): mixed {
		$was         = self::$quiet;
		self::$quiet = true;
		try {
			return $change();
		} finally {
			self::$quiet = $was;
		}
	}

	/**
	 * Whether a status change now comes from this plugin, not an admin.
	 */
	public static function isQuiet(): bool {
		return self::$quiet;
	}

	/**
	 * Whether products should sync automatically: 1.x sites keep their "Sync products"
	 * choice; new sites follow the Store feature.
	 */
	public function enabled(): bool {
		$legacy = get_option( 'invoiceninja_sync_products', null );
		if ( null !== $legacy && false !== $legacy ) {
			return (bool) $legacy;
		}
		return (bool) $this->plugin->options->setting( 'modules.store' );
	}

	/**
	 * Whether the 1.x "Sync products" option keeps syncing off although the Store feature is
	 * on (before 2.1.0, saving the legacy settings page wrote it as off).
	 */
	public function offByLegacy(): bool {
		$legacy = get_option( 'invoiceninja_sync_products', null );
		return null !== $legacy && false !== $legacy && ! (bool) $legacy && (bool) $this->plugin->options->setting( 'modules.store' );
	}

	/**
	 * Turn syncing back on after offByLegacy(): forget the 1.x option, so the Store feature decides.
	 */
	public function clearLegacySwitch(): bool {
		if ( ! $this->offByLegacy() ) {
			return false;
		}
		delete_option( 'invoiceninja_sync_products' );
		$this->queue( true, false );
		$this->plugin->logger->info( 'sync', 'Product sync turned back on (it had been turned off on the legacy settings page)' );
		return true;
	}

	/**
	 * Sync products.
	 *
	 * @param bool $full   Fetch every product, not only those changed since the last sync.
	 * @param bool $force  Apply even when the circuit breaker would stop it.
	 * @param bool $resume This run continues one that ran out of time (it was already allowed).
	 * @return array{status: string, created: int, updated: int, drafted: int, unchanged: int, total: int, remaining: int, message: string}
	 */
	public function run( bool $full = false, bool $force = false, bool $resume = false ): array {
		$result = array(
			'status'    => 'synced',
			'created'   => 0,
			'updated'   => 0,
			'drafted'   => 0,
			'unchanged' => 0,
			'total'     => 0,
			'remaining' => 0,
			'message'   => '',
		);
		if ( 'none' === $this->plugin->credentials->source() ) {
			$result['status'] = 'skipped';
			return $result;
		}
		$continuing = $this->continuing();
		if ( $resume && null === $continuing ) {
			$resume = false; // The run it continued was finished or forgotten: a normal run.
		}
		if ( ! $resume && ! $force && null !== $continuing ) {
			// A large sync is still going in steps; its next step picks up these changes too.
			$result['status']  = 'busy';
			$result['message'] = __( 'A sync is running in the background.', 'invoiceninja' );
			return $result;
		}
		// Only one sync at a time, or two runs could both create the same new product.
		if ( ! $this->lock() ) {
			$result['status']  = 'busy';
			$result['message'] = __( 'Another sync is running.', 'invoiceninja' );
			return $result;
		}
		try {
			return $this->runLocked( $full, $force || ( $resume && ! empty( $continuing['force'] ) ), $resume, $result );
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Queue a sync in the background (Sync now and Apply on a large catalog).
	 *
	 * @param bool $full  Full sync.
	 * @param bool $force Bypass the circuit breaker.
	 */
	public function queue( bool $full, bool $force ): void {
		$this->plugin->queue->push(
			self::HOOK,
			array(
				'full'  => $full,
				'force' => $force,
			)
		);
	}

	/**
	 * Whether "Sync now" should run in the background: the catalog is larger than one run writes.
	 */
	public function large(): bool {
		return $this->mapping->count( 'product' ) > $this->batch();
	}

	/**
	 * The unfinished sync being continued in steps, if any (forgotten after an hour, so a
	 * lost follow-up job can't stop syncing).
	 *
	 * @return array{at: int, full: bool, force: bool}|null
	 */
	private function continuing(): ?array {
		$state = $this->plugin->options->get( 'sync_continue' );
		if ( ! is_array( $state ) || (int) ( $state['at'] ?? 0 ) < time() - HOUR_IN_SECONDS ) {
			return null;
		}
		return array(
			'at'    => (int) $state['at'],
			'full'  => ! empty( $state['full'] ),
			'force' => ! empty( $state['force'] ),
		);
	}

	/**
	 * Products one run writes at most.
	 */
	private function batch(): int {
		/**
		 * Products one product sync run writes at most; the rest continue in a follow-up run.
		 *
		 * @since 2.0.2
		 * @param int $batch Products.
		 */
		return max( 1, (int) apply_filters( 'invoiceninja_product_sync_batch', self::BATCH ) );
	}

	/**
	 * The sync itself, with the lock held.
	 *
	 * @param bool                 $full     Full sync.
	 * @param bool                 $force    Bypass the circuit breaker.
	 * @param bool                 $resume   Continues an earlier run.
	 * @param array<string, mixed> $result   Result skeleton.
	 * @return array{status: string, created: int, updated: int, drafted: int, unchanged: int, total: int, remaining: int, message: string}
	 */
	private function runLocked( bool $full, bool $force, bool $resume, array $result ): array {
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$batch    = $this->batch();
		$cursor   = (int) $this->plugin->options->get( 'sync_cursor_products', 0 );
		$query    = array( 'status' => 'active,archived,deleted' );
		if ( ! $full && $cursor > 0 ) {
			$query['updated_at'] = 'gte:' . max( 0, $cursor - 300 );
			$query['sort']       = 'updated_at|asc';
		}

		// Load mappings, post statuses and 1.x links in a few queries instead of several per product.
		$mapped   = $this->mapping->all( 'product' );
		$posts    = $this->postStatuses();
		$statuses = array_map( static fn( array $p ): string => $p['status'], $posts );
		$legacy   = $this->legacyIndex();
		$keys     = $this->changeKeys();
		$hidden   = $this->hiddenIndex();
		$existing = count( array_filter( $mapped, static fn( array $m ): bool => isset( $statuses[ $m['wp_id'] ] ) ) );

		// Plan while fetching, keeping only the products to write (at most one batch).
		$plan     = array();
		$changes  = 0;
		$sample   = array();
		$max      = $cursor;
		$deferred = PHP_INT_MAX; // updated_at of the first product left for the next run.
		try {
			foreach ( Paginator::each( $this->plugin->api(), 'products', $query, 250 ) as $item ) {
				$id = self::cleanId( (string) ( $item['id'] ?? '' ) );
				if ( '' === $id ) {
					continue;
				}
				$desired = self::desired( $item );
				$map     = $mapped[ $id ] ?? null;
				if ( null !== $map && ! isset( $statuses[ $map['wp_id'] ] ) ) {
					// Its post is gone (1.x re-creates posts after a downgrade): link the 1.x post instead.
					$map = null;
				}
				$post_id = $map ? $map['wp_id'] : (int) ( $legacy[ $id ] ?? 0 );
				$status  = $statuses[ $post_id ] ?? null;
				if ( null === $status ) {
					$post_id = 0;
				}
				$updated_at = (int) ( $item['updated_at'] ?? 0 );
				$action     = match ( true ) {
					'trash' === $status                                              => 'skip', // An admin removed it; leave it alone.
					0 === $post_id && 'draft' === $desired['post']['post_status']   => 'skip',
					0 === $post_id                                                   => 'create',
					null !== $map && $map['hash'] === $desired['hash']               => 'unchanged',
					'draft' === $desired['post']['post_status'] && 'draft' !== $status => 'draft',
					default                                                          => 'update',
				};
				if ( 'skip' === $action || 'unchanged' === $action ) {
					$result['unchanged'] += 'unchanged' === $action ? 1 : 0;
					$max                  = max( $max, $updated_at );
					continue;
				}
				// Products just linked from 1.x have no hash yet; their first update isn't a "change".
				$adopting = 'update' === $action && ( null === $map || '' === $map['hash'] );
				$counts   = match ( $action ) {
					// Shoppers don't see hidden products, so their changes don't count.
					'update' => ! $adopting && ! isset( $hidden[ $post_id ] ) && self::noticeable( $desired, $keys[ $post_id ] ?? '', $posts[ $post_id ] ?? null ),
					'draft'  => true,
					default  => $existing > 0, // New products count once there's a catalog to protect.
				};
				if ( $counts ) {
					++$changes;
					if ( count( $sample ) < 10 ) {
						$sample[] = array(
							'action' => $action,
							'title'  => (string) $desired['post']['post_title'],
						);
					}
				}
				if ( count( $plan ) < $batch ) {
					$plan[] = array( $action, $id, $post_id, $desired, $updated_at, $status );
				} else {
					$deferred = min( $deferred, $updated_at );
					++$result['remaining'];
				}
			}
		} catch ( ApiException $e ) {
			$this->plugin->logger->warning( 'sync', 'Product sync failed: ' . $e->kind->title(), array( 'technical' => $e->technical ) );
			$this->record( 'failed', $e->kind->title() );
			return array_merge(
				$result,
				array(
					'status'    => 'failed',
					'unchanged' => 0,
					'remaining' => 0,
					'message'   => $e->kind->title(),
				)
			);
		}

		if ( ! $force && ! $resume && $existing >= self::BREAKER_MIN && $changes / max( 1, $existing ) > self::BREAKER_RATIO ) {
			return $this->pause( $result, $changes, $existing, $sample );
		}

		foreach ( $plan as $i => [ $action, $id, $post_id, $desired, $updated_at, $status ] ) {
			// Out of time or memory: leave the rest for the next run (at least a few per run).
			if ( $i >= 20 && ( microtime( true ) > $deadline || self::lowOnMemory() ) ) {
				$deferred = min( $deferred, $updated_at );
				++$result['remaining'];
				continue;
			}
			$written = $this->write( (int) $post_id, $desired, $status );
			if ( $written <= 0 ) {
				$deferred = min( $deferred, $updated_at );
				continue;
			}
			$this->mapping->save( 'product', $id, 'post', $written, $desired['hash'] );
			++$result[ 'create' === $action ? 'created' : ( 'draft' === $action ? 'drafted' : 'updated' ) ];
			$max = max( $max, $updated_at );
			$this->maybeQueueImage( $written, $desired['image'] );
			if ( 0 === ( $i + 1 ) % 50 ) {
				$this->refreshLock();
			}
		}

		// Never move the cursor past a product that failed to save or wasn't reached, so
		// it's tried again.
		if ( PHP_INT_MAX !== $deferred ) {
			$max = min( $max, max( 0, $deferred - 1 ) );
		}
		$this->plugin->options->set( 'sync_cursor_products', $max );
		$this->plugin->options->delete( 'sync_paused' );
		if ( $result['remaining'] > 0 ) {
			// Continue in a moment; the next run skips what this one wrote (its hash matches).
			$this->plugin->options->set(
				'sync_continue',
				array(
					'at'    => time(),
					'full'  => $full,
					'force' => $force,
				)
			);
			$this->plugin->queue->push(
				self::HOOK,
				array(
					'full'     => $full,
					'continue' => true,
				),
				5
			);
			/* translators: %d: number of products */
			$result['message'] = sprintf( _n( '%d more product syncs in the background.', '%d more products sync in the background.', $result['remaining'], 'invoiceninja' ), $result['remaining'] );
		} else {
			$this->plugin->options->delete( 'sync_continue' );
		}
		$result['total'] = $this->mapping->count( 'product' );
		if ( $result['created'] || $result['updated'] || $result['drafted'] ) {
			$this->plugin->logger->info( 'sync', sprintf( 'Products synced: %d added, %d updated, %d unpublished', $result['created'], $result['updated'], $result['drafted'] ) . ( $result['remaining'] > 0 ? sprintf( '; %d more continue in the background', $result['remaining'] ) : '' ) );
		}
		$this->record( 'synced', '' );
		/**
		 * Fires after products were synced.
		 *
		 * @since 2.0.0
		 * @param array $result Counts.
		 */
		do_action( 'invoiceninja_products_synced', $result );
		return $result;
	}

	/**
	 * Stop before changing anything: too much would change. Admins hear about it once per
	 * incident (a notice and one email); later runs keep checking and resume on their own
	 * once the changes are back under the limit, or an admin applies them.
	 *
	 * @param array<string, mixed>                       $result   Result so far.
	 * @param int                                        $changes  Noticeable changes.
	 * @param int                                        $existing Products already synced.
	 * @param list<array{action: string, title: string}> $sample   Some of the changes.
	 * @return array{status: string, created: int, updated: int, drafted: int, unchanged: int, total: int, remaining: int, message: string}
	 */
	private function pause( array $result, int $changes, int $existing, array $sample ): array {
		$before = $this->plugin->options->get( 'sync_paused' );
		$this->plugin->options->set(
			'sync_paused',
			array(
				'at'      => is_array( $before ) ? (int) ( $before['at'] ?? time() ) : time(),
				'changes' => $changes,
				'total'   => $existing,
				'sample'  => $sample,
			)
		);
		$message = sprintf( '%d of %d products would change', $changes, $existing );
		$this->record( 'paused', $message );
		if ( ! is_array( $before ) ) {
			$this->plugin->logger->log( LogLevel::Error, 'sync', 'Product sync stopped before changing anything: ' . $message );
			$this->plugin->notifications->syncPaused( $changes, $existing );
		}
		return array_merge(
			$result,
			array(
				'status'    => 'paused',
				'unchanged' => 0,
				'remaining' => 0,
				'total'     => $existing,
				'message'   => $message,
			)
		);
	}

	/**
	 * Whether an update changes what shoppers see: price, name, description or being
	 * published. Compared with the key saved with the post; posts synced before 2.0.2 have
	 * none, so their name, price and status are compared instead.
	 *
	 * @param array<string, mixed>                                 $desired Desired state.
	 * @param string                                               $key     Saved key, or ''.
	 * @param array{status: string, title: string, price: string}|null $post    The post now.
	 */
	public static function noticeable( array $desired, string $key, ?array $post ): bool {
		if ( '' !== $key ) {
			return ! hash_equals( $key, (string) $desired['key'] );
		}
		if ( null === $post ) {
			return true;
		}
		// Titles saved without a user (cron, webhooks) are HTML-encoded ("Fish &amp; Chips").
		$plain = static fn( string $text ): string => html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return $plain( (string) $post['title'] ) !== $plain( (string) $desired['post']['post_title'] )
			|| $post['price'] !== (string) $desired['meta']['price']
			|| ( 'publish' === $desired['post']['post_status'] ) !== in_array( $post['status'], array( 'publish', 'private', 'pending', 'future' ), true );
	}

	/**
	 * Whether this run is close to PHP's memory limit.
	 */
	private static function lowOnMemory(): bool {
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		return $limit > 0 && memory_get_usage() > $limit * 0.8;
	}

	/**
	 * Update one product in place, from a record just fetched from Invoice Ninja (a webhook).
	 * Same rules as a full sync, without the circuit breaker or moving the cursor.
	 *
	 * @param array<string, mixed> $product Invoice Ninja product.
	 * @return string created, updated, drafted, unchanged, skipped, busy (a sync is running; try later) or failed.
	 */
	public function syncOne( array $product ): string {
		$id = self::cleanId( (string) ( $product['id'] ?? '' ) );
		if ( '' === $id ) {
			return 'skipped';
		}
		if ( ! $this->lock() ) {
			return 'busy';
		}
		try {
			$desired = self::desired( $product );
			$map     = $this->mapping->find( 'product', $id );
			if ( null !== $map && ( false === get_post_status( (int) $map['wp_id'] ) || self::POST_TYPE !== get_post_type( (int) $map['wp_id'] ) ) ) {
				$map = null; // Its post is gone: link a 1.x post for it, if there is one.
			}
			$post_id = $map ? (int) $map['wp_id'] : (int) ( $this->legacyIndex()[ $id ] ?? 0 );
			$status  = $post_id > 0 ? get_post_status( $post_id ) : false;
			if ( false === $status || self::POST_TYPE !== get_post_type( $post_id ) ) {
				$post_id = 0;
				$status  = null;
			}
			$action = match ( true ) {
				'trash' === $status                                                => 'skipped',
				0 === $post_id && 'draft' === $desired['post']['post_status']     => 'skipped',
				0 === $post_id                                                     => 'created',
				null !== $map && $map['hash'] === $desired['hash']                 => 'unchanged',
				'draft' === $desired['post']['post_status'] && 'draft' !== $status => 'drafted',
				default                                                            => 'updated',
			};
			if ( 'skipped' === $action || 'unchanged' === $action ) {
				return $action;
			}
			$written = $this->write( $post_id, $desired, is_string( $status ) ? $status : null );
			if ( $written <= 0 ) {
				return 'failed';
			}
			$this->mapping->save( 'product', $id, 'post', $written, $desired['hash'] );
			$this->maybeQueueImage( $written, $desired['image'] );
			$this->plugin->logger->log( LogLevel::Info, 'sync', sprintf( 'Product %s %s from an Invoice Ninja update', $desired['post']['post_title'], $action ), array(), 'product', $id );
			return $action;
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Take the sync lock. The lock holds when it was taken and a random token, so only its
	 * owner releases it; one that wasn't refreshed for LOCK_TTL seconds (its run died) is
	 * taken over in one atomic step, so two runs can't both take it over.
	 */
	private function lock(): bool {
		$options = $this->plugin->options;
		$token   = time() . ':' . bin2hex( random_bytes( 8 ) );
		if ( $options->addAtomic( 'sync_lock', $token ) ) {
			$this->token = $token;
			return true;
		}
		$held = (string) $options->get( 'sync_lock', '' );
		if ( '' !== $held && (int) $held < time() - self::LOCK_TTL && $options->replaceAtomic( 'sync_lock', $held, $token ) ) {
			$this->plugin->logger->warning( 'sync', 'Took over a product sync lock that was not released' );
			$this->token = $token;
			return true;
		}
		return false;
	}

	/**
	 * Show that the run holding the lock is still alive.
	 */
	private function refreshLock(): void {
		$token = time() . ':' . bin2hex( random_bytes( 8 ) );
		if ( '' !== $this->token && $this->plugin->options->replaceAtomic( 'sync_lock', $this->token, $token ) ) {
			$this->token = $token;
		}
	}

	/**
	 * Release the lock, only if this object still holds it.
	 */
	private function unlock(): void {
		if ( '' !== $this->token ) {
			$this->plugin->options->deleteIf( 'sync_lock', $this->token );
			$this->token = '';
		}
	}

	/**
	 * Unpublish the old company's products after a company switch and forget the links.
	 */
	public function forgetCompany(): void {
		self::quietly(
			function (): void {
				foreach ( $this->mapping->all( 'product' ) as $map ) {
					if ( 'publish' === get_post_status( $map['wp_id'] ) ) {
						wp_update_post(
							array(
								'ID'          => $map['wp_id'],
								'post_status' => 'draft',
							)
						);
					}
				}
			}
		);
		$this->mapping->clear( 'product' );
		$this->plugin->options->delete( 'sync_cursor_products' );
		$this->plugin->options->delete( 'sync_paused' );
		$this->plugin->options->delete( 'sync_continue' );
		$this->plugin->logger->info( 'sync', 'Products from the previous company were moved to drafts' );
	}

	/**
	 * Status, title and price of every product post, by id.
	 *
	 * @return array<int, array{status: string, title: string, price: string}>
	 */
	private function postStatuses(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_status, post_title, (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = 'price' LIMIT 1) AS price FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['ID'] ] = array(
				'status' => (string) $row['post_status'],
				'title'  => (string) ( $row['post_title'] ?? '' ),
				'price'  => (string) ( $row['price'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * The change key saved with each product post (see noticeable()), by post id.
	 *
	 * @return array<int, string>
	 */
	private function changeKeys(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_invoiceninja_sync_key' AND p.post_type = %s", self::POST_TYPE ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['post_id'] ] = (string) $row['meta_value'];
		}
		return $out;
	}

	/**
	 * Product posts hidden from this site, by post id.
	 *
	 * @return array<int, true>
	 */
	private function hiddenIndex(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_type = %s", self::HIDDEN_META, self::POST_TYPE ) );
		return array_fill_keys( array_map( 'intval', is_array( $ids ) ? $ids : array() ), true );
	}

	/**
	 * 1.x product ids to their newest post, for products not mapped yet.
	 *
	 * @return array<string, int>
	 */
	private function legacyIndex(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value AS in_id, MAX(p.ID) AS id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'product_id' AND p.post_type = %s GROUP BY pm.meta_value", self::POST_TYPE ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['in_id'] ] = (int) $row['id'];
		}
		return $out;
	}

	/**
	 * Keep ids to the characters Invoice Ninja uses.
	 *
	 * @param string $id Id.
	 */
	public static function cleanId( string $id ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $id ), 0, 64 );
	}

	/**
	 * Link 1.x product posts (meta product_id) into the mapping table: products not linked
	 * yet, and products whose linked post is gone (1.x deletes and re-creates every post, also
	 * after a downgrade). When 1.x left several posts for one product, the newest is kept and
	 * the others become drafts. Products whose linked post still exists are left alone.
	 */
	public function linkLegacyPosts(): int {
		$linked = 0;
		$groups = array();
		$posts  = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$in_id = self::cleanId( (string) get_post_meta( $post->ID, 'product_id', true ) );
			if ( '' !== $in_id ) {
				$groups[ $in_id ][] = $post; // Newest first.
			}
		}
		$mapped = $this->mapping->all( 'product' );
		foreach ( $groups as $in_id => $group ) {
			$map = $mapped[ (string) $in_id ] ?? null;
			if ( null !== $map && self::POST_TYPE === get_post_type( (int) $map['wp_id'] ) ) {
				continue;
			}
			foreach ( array_slice( $group, 1 ) as $older ) {
				if ( 'draft' !== $older->post_status && 'trash' !== $older->post_status ) {
					self::quietly(
						static fn() => wp_update_post(
							array(
								'ID'          => $older->ID,
								'post_status' => 'draft',
							)
						)
					);
				}
			}
			$this->mapping->save( 'product', (string) $in_id, 'post', (int) $group[0]->ID, '' );
			++$linked;
		}
		return $linked;
	}

	/**
	 * What the post should look like.
	 *
	 * @param array<string, mixed> $item Invoice Ninja product.
	 * @return array{post: array<string, mixed>, meta: array<string, string>, image: string, hash: string, key: string}
	 */
	public static function desired( array $item ): array {
		// Discount and credit items (a negative price) are never sold on their own: shoppers
		// could add them to lower an order's total.
		$hidden = ! empty( $item['is_deleted'] ) || ! empty( $item['archived_at'] ) || ( is_numeric( $item['price'] ?? null ) && (float) $item['price'] < 0 );
		$text   = static fn( string $key ): string => sanitize_text_field( (string) ( $item[ $key ] ?? '' ) );
		$num    = static fn( string $key ): string => is_numeric( $item[ $key ] ?? null ) ? (string) ( 0 + $item[ $key ] ) : '0';
		$post   = array(
			'post_title'   => sanitize_text_field( (string) ( $item['product_key'] ?? '' ) ),
			'post_content' => wp_kses_post( (string) ( $item['notes'] ?? '' ) ),
			'post_status'  => $hidden ? 'draft' : 'publish',
		);
		$meta   = array(
			'product_id'        => self::cleanId( (string) ( $item['id'] ?? '' ) ),
			'product_key'       => sanitize_text_field( (string) ( $item['product_key'] ?? '' ) ),
			'price'             => $num( 'price' ),
			'quantity'          => $num( 'quantity' ),
			'max_quantity'      => $num( 'max_quantity' ),
			'in_stock_quantity' => $num( 'in_stock_quantity' ),
			'cost'              => $num( 'cost' ),
			'custom_value1'     => $text( 'custom_value1' ),
			'custom_value2'     => $text( 'custom_value2' ),
			'custom_value3'     => $text( 'custom_value3' ),
			'custom_value4'     => $text( 'custom_value4' ),
			'tax_name1'         => $text( 'tax_name1' ),
			'tax_rate1'         => $num( 'tax_rate1' ),
			'tax_name2'         => $text( 'tax_name2' ),
			'tax_rate2'         => $num( 'tax_rate2' ),
			'tax_name3'         => $text( 'tax_name3' ),
			'tax_rate3'         => $num( 'tax_rate3' ),
			'tax_id'            => $text( 'tax_id' ),
		);
		$image  = esc_url_raw( (string) ( $item['product_image'] ?? '' ) );
		return array(
			'post'  => $post,
			'meta'  => $meta,
			'image' => $image,
			'hash'  => sha1( (string) wp_json_encode( array( $post, $meta, $image ) ) ),
			// What shoppers notice (for the circuit breaker): not stock, costs or images.
			'key'   => sha1( (string) wp_json_encode( array( $post['post_title'], $post['post_content'], $post['post_status'], $meta['price'] ) ) ),
		);
	}

	/**
	 * Create or update the post.
	 *
	 * @param int                  $post_id Existing post or 0.
	 * @param array<string, mixed> $desired Desired state.
	 * @param string|null          $status  Current status.
	 */
	private function write( int $post_id, array $desired, ?string $status ): int {
		$extra  = array( '_invoiceninja_sync_key' => (string) ( $desired['key'] ?? '' ) );
		$hidden = $post_id > 0 ? (string) get_post_meta( $post_id, self::HIDDEN_META, true ) : '';
		if ( '' !== $hidden ) {
			// Hidden from this site: it stays a draft and remembers the status it gets back.
			$extra[ self::HIDDEN_META ]     = self::shownStatus( (string) $desired['post']['post_status'], $hidden );
			$desired['post']['post_status'] = 'draft';
		} elseif ( $post_id > 0 && in_array( $status, array( 'private', 'pending', 'future' ), true ) && 'publish' === $desired['post']['post_status'] ) {
			unset( $desired['post']['post_status'] ); // Keep a status an admin chose.
		}
		$data   = $desired['post'] + array(
			'post_type'  => self::POST_TYPE,
			'meta_input' => $desired['meta'] + $extra,
		);
		$result = self::quietly(
			static function () use ( $post_id, $data ) {
				if ( $post_id > 0 ) {
					$data['ID'] = $post_id;
					return wp_update_post( wp_slash( $data ), true );
				}
				$data['post_author'] = 0;
				return wp_insert_post( wp_slash( $data ), true );
			}
		);
		if ( is_wp_error( $result ) ) {
			$this->plugin->logger->warning( 'sync', 'Saving a product failed: ' . $result->get_error_message(), array( 'product' => $desired['meta']['product_id'] ) );
			return 0;
		}
		return (int) $result;
	}

	/**
	 * The status a hidden product gets back when shown (pure, for tests): what Invoice Ninja
	 * asks for now, keeping a private, pending or scheduled status an admin chose.
	 *
	 * @param string $desired Status from Invoice Ninja (publish or draft).
	 * @param string $before  Status remembered when it was hidden.
	 */
	public static function shownStatus( string $desired, string $before ): string {
		return 'publish' === $desired && in_array( $before, array( 'private', 'pending', 'future' ), true ) ? $before : $desired;
	}

	/**
	 * Queue the image download when the image changed.
	 *
	 * @param int    $post_id Post.
	 * @param string $url     Image URL.
	 */
	private function maybeQueueImage( int $post_id, string $url ): void {
		$known = get_post_meta( $post_id, '_invoiceninja_image_url', true );
		if ( (string) $known === $url ) {
			return;
		}
		if ( '' === $known && '' !== $url && has_post_thumbnail( $post_id ) && ! get_post_meta( $post_id, '_invoiceninja_attachment', true ) ) {
			// 1.x already downloaded it: adopt it as ours, so a later image change replaces it.
			update_post_meta( $post_id, '_invoiceninja_attachment', (int) get_post_thumbnail_id( $post_id ) );
			update_post_meta( $post_id, '_invoiceninja_image_url', $url );
			return;
		}
		$this->plugin->queue->push(
			'invoiceninja_sideload_image',
			array(
				'post_id' => $post_id,
				'url'     => $url,
			),
			0,
			false
		);
	}

	/**
	 * Remember the last result for Status and Home.
	 *
	 * @param string $status  Status.
	 * @param string $message Message.
	 */
	private function record( string $status, string $message ): void {
		$this->plugin->options->set(
			'sync_status',
			array(
				'status'  => $status,
				'message' => $message,
				'at'      => time(),
			)
		);
	}
}
