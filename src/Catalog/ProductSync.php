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
 *   reports as archived or deleted become drafts;
 * - a sync that would change more than 20% of the catalog stops before changing
 *   anything and waits for an admin (the circuit breaker);
 * - unchanged products are skipped by comparing a content hash.
 */
final class ProductSync {

	public const POST_TYPE = 'invoiceninja_product';

	public const BREAKER_RATIO = 0.2;

	public const BREAKER_MIN = 10;

	/**
	 * Constructor.
	 *
	 * @param Plugin  $plugin  Plugin.
	 * @param Mapping $mapping Mapping.
	 */
	public function __construct( private Plugin $plugin, private Mapping $mapping ) {}

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
	 * Sync products.
	 *
	 * @param bool $full  Fetch every product, not only those changed since the last sync.
	 * @param bool $force Apply even when the circuit breaker would stop it.
	 * @return array{status: string, created: int, updated: int, drafted: int, unchanged: int, total: int, message: string}
	 */
	public function run( bool $full = false, bool $force = false ): array {
		$result = array(
			'status'    => 'synced',
			'created'   => 0,
			'updated'   => 0,
			'drafted'   => 0,
			'unchanged' => 0,
			'total'     => 0,
			'message'   => '',
		);
		if ( 'none' === $this->plugin->credentials->source() ) {
			$result['status'] = 'skipped';
			return $result;
		}
		// Only one sync at a time, or two runs could both create the same new product.
		if ( ! $this->lock() ) {
			$result['status']  = 'busy';
			$result['message'] = __( 'Another sync is running.', 'invoiceninja' );
			return $result;
		}
		try {
			return $this->runLocked( $full, $force, $result );
		} finally {
			$this->plugin->options->delete( 'sync_lock' );
		}
	}

	/**
	 * The sync itself, with the lock held.
	 *
	 * @param bool                 $full   Full sync.
	 * @param bool                 $force  Bypass the circuit breaker.
	 * @param array<string, mixed> $result Result skeleton.
	 * @return array{status: string, created: int, updated: int, drafted: int, unchanged: int, total: int, message: string}
	 */
	private function runLocked( bool $full, bool $force, array $result ): array {
		$cursor = (int) $this->plugin->options->get( 'sync_cursor_products', 0 );
		$query  = array( 'status' => 'active,archived,deleted' );
		if ( ! $full && $cursor > 0 ) {
			$query['updated_at'] = 'gte:' . max( 0, $cursor - 300 );
			$query['sort']       = 'updated_at|asc';
		}

		try {
			$client = $this->plugin->credentials->client( $this->plugin->state->allowsPrivate( $this->plugin->credentials->url() ) );
			$items  = iterator_to_array( Paginator::each( $client, 'products', $query, 250 ), false );
		} catch ( ApiException $e ) {
			$this->plugin->logger->warning( 'sync', 'Product sync failed: ' . $e->kind->title(), array( 'technical' => $e->technical ) );
			$this->record( 'failed', $e->kind->title() );
			return array_merge(
				$result,
				array(
					'status'  => 'failed',
					'message' => $e->kind->title(),
				)
			);
		}

		// Load mappings, post statuses and 1.x links in three queries instead of several per product.
		$mapped   = $this->mapping->all( 'product' );
		$statuses = $this->postStatuses();
		$legacy   = $this->legacyIndex();
		$existing = count( array_filter( $mapped, static fn( array $m ): bool => isset( $statuses[ $m['wp_id'] ] ) ) );

		$plan    = array();
		$changes = 0;
		foreach ( $items as $item ) {
			$id = self::cleanId( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$desired = self::desired( $item );
			$map     = $mapped[ $id ] ?? null;
			$post_id = $map ? $map['wp_id'] : (int) ( $legacy[ $id ] ?? 0 );
			$status  = $statuses[ $post_id ] ?? null;
			if ( null === $status ) {
				$post_id = 0;
			}
			$action = match ( true ) {
				'trash' === $status                                              => 'skip', // An admin removed it; leave it alone.
				0 === $post_id && 'draft' === $desired['post']['post_status']   => 'skip',
				0 === $post_id                                                   => 'create',
				null !== $map && $map['hash'] === $desired['hash']               => 'unchanged',
				'draft' === $desired['post']['post_status'] && 'draft' !== $status => 'draft',
				default                                                          => 'update',
			};
			// Products just linked from 1.x have no hash yet; their first update isn't a "change".
			$adopting = 'update' === $action && ( null === $map || '' === $map['hash'] );
			if ( ( 'update' === $action && ! $adopting ) || 'draft' === $action || ( 'create' === $action && $existing > 0 ) ) {
				++$changes;
			}
			$plan[] = array( $action, $id, $post_id, $desired, (int) ( $item['updated_at'] ?? 0 ), $status );
		}

		if ( ! $force && $existing >= self::BREAKER_MIN && $changes / max( 1, $existing ) > self::BREAKER_RATIO ) {
			$sample = array();
			foreach ( $plan as $p ) {
				if ( in_array( $p[0], array( 'create', 'update', 'draft' ), true ) && count( $sample ) < 10 ) {
					$sample[] = array(
						'action' => $p[0],
						'title'  => (string) $p[3]['post']['post_title'],
					);
				}
			}
			$this->plugin->options->set(
				'sync_paused',
				array(
					'at'      => time(),
					'changes' => $changes,
					'total'   => $existing,
					'sample'  => $sample,
				)
			);
			$message = sprintf( '%d of %d products would change', $changes, $existing );
			$this->plugin->logger->log( LogLevel::Error, 'sync', 'Product sync stopped before changing anything: ' . $message );
			$this->record( 'paused', $message );
			return array_merge(
				$result,
				array(
					'status'  => 'paused',
					'total'   => $existing,
					'message' => $message,
				)
			);
		}

		$max          = $cursor;
		$first_failed = PHP_INT_MAX;
		foreach ( $plan as [ $action, $id, $post_id, $desired, $updated_at, $status ] ) {
			if ( 'skip' === $action || 'unchanged' === $action ) {
				$result['unchanged'] += 'unchanged' === $action ? 1 : 0;
				$max                  = max( $max, $updated_at );
				continue;
			}
			$written = $this->write( (int) $post_id, $desired, $status );
			if ( $written <= 0 ) {
				$first_failed = min( $first_failed, $updated_at );
				continue;
			}
			$this->mapping->save( 'product', $id, 'post', $written, $desired['hash'] );
			++$result[ 'create' === $action ? 'created' : ( 'draft' === $action ? 'drafted' : 'updated' ) ];
			$max = max( $max, $updated_at );
			$this->maybeQueueImage( $written, $desired['image'] );
		}

		// Never move the cursor past a product that failed to save, so it's tried again.
		if ( PHP_INT_MAX !== $first_failed ) {
			$max = min( $max, max( 0, $first_failed - 1 ) );
		}
		$this->plugin->options->set( 'sync_cursor_products', $max );
		$this->plugin->options->delete( 'sync_paused' );
		$result['total'] = $this->mapping->count( 'product' );
		if ( $result['created'] || $result['updated'] || $result['drafted'] ) {
			$this->plugin->logger->info( 'sync', sprintf( 'Products synced: %d added, %d updated, %d unpublished', $result['created'], $result['updated'], $result['drafted'] ) );
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
	 * Take the sync lock (stale after 10 minutes).
	 */
	private function lock(): bool {
		$options = $this->plugin->options;
		if ( $options->addAtomic( 'sync_lock', (string) time() ) ) {
			return true;
		}
		if ( (int) $options->get( 'sync_lock', 0 ) < time() - 600 ) {
			$options->delete( 'sync_lock' );
			return $options->addAtomic( 'sync_lock', (string) time() );
		}
		return false;
	}

	/**
	 * Unpublish the old company's products after a company switch and forget the links.
	 */
	public function forgetCompany(): void {
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
		$this->mapping->clear( 'product' );
		$this->plugin->options->delete( 'sync_cursor_products' );
		$this->plugin->options->delete( 'sync_paused' );
		$this->plugin->logger->info( 'sync', 'Products from the previous company were moved to drafts' );
	}

	/**
	 * Status of every product post, by id.
	 *
	 * @return array<int, string>
	 */
	private function postStatuses(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['ID'] ] = (string) $row['post_status'];
		}
		return $out;
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
	 * Link 1.x product posts (meta product_id) into the mapping table. When 1.x left
	 * several posts for one product, the newest is kept and the others become drafts.
	 */
	public function linkLegacyPosts(): int {
		$linked = 0;
		$by_id  = array();
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
			if ( '' === $in_id ) {
				continue;
			}
			if ( isset( $by_id[ $in_id ] ) ) {
				if ( 'draft' !== $post->post_status ) {
					wp_update_post(
						array(
							'ID'          => $post->ID,
							'post_status' => 'draft',
						)
					);
				}
				continue;
			}
			$by_id[ $in_id ] = $post->ID;
			if ( null === $this->mapping->find( 'product', $in_id ) ) {
				$this->mapping->save( 'product', $in_id, 'post', (int) $post->ID, '' );
				++$linked;
			}
		}
		return $linked;
	}

	/**
	 * What the post should look like.
	 *
	 * @param array<string, mixed> $item Invoice Ninja product.
	 * @return array{post: array<string, mixed>, meta: array<string, string>, image: string, hash: string}
	 */
	public static function desired( array $item ): array {
		$hidden = ! empty( $item['is_deleted'] ) || ! empty( $item['archived_at'] );
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
		if ( $post_id > 0 && in_array( $status, array( 'private', 'pending', 'future' ), true ) && 'publish' === $desired['post']['post_status'] ) {
			unset( $desired['post']['post_status'] ); // Keep a status an admin chose.
		}
		$data = $desired['post'] + array(
			'post_type'  => self::POST_TYPE,
			'meta_input' => $desired['meta'],
		);
		if ( $post_id > 0 ) {
			$data['ID'] = $post_id;
			$result     = wp_update_post( wp_slash( $data ), true );
		} else {
			$data['post_author'] = 0;
			$result              = wp_insert_post( wp_slash( $data ), true );
		}
		if ( is_wp_error( $result ) ) {
			$this->plugin->logger->warning( 'sync', 'Saving a product failed: ' . $result->get_error_message(), array( 'product' => $desired['meta']['product_id'] ) );
			return 0;
		}
		return (int) $result;
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
