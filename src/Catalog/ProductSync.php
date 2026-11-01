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

		$plan = array();
		$max  = $cursor;
		foreach ( $items as $item ) {
			$id = (string) ( $item['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$desired = self::desired( $item );
			$mapped  = $this->mapping->find( 'product', $id );
			$post_id = $mapped ? $mapped['wp_id'] : $this->legacyPostId( $id );
			if ( $post_id && ! get_post( $post_id ) ) {
				$post_id = 0;
			}
			$action = match ( true ) {
				0 === $post_id && 'draft' === $desired['post']['post_status'] => 'skip',
				0 === $post_id                                                 => 'create',
				null !== $mapped && $mapped['hash'] === $desired['hash']       => 'unchanged',
				'draft' === $desired['post']['post_status'] && 'draft' !== get_post_status( $post_id ) => 'draft',
				default                                                        => 'update',
			};
			$plan[] = array( $action, $id, $post_id, $desired );
			$max    = max( $max, (int) ( $item['updated_at'] ?? 0 ) );
		}

		$existing = $this->mapping->count( 'product' );
		$changes  = count( array_filter( $plan, static fn( array $p ): bool => in_array( $p[0], array( 'update', 'draft' ), true ) || ( 'create' === $p[0] && $existing > 0 ) ) );
		if ( ! $force && $existing >= self::BREAKER_MIN && $changes / max( 1, $existing ) > self::BREAKER_RATIO ) {
			$titles = array();
			foreach ( $plan as $p ) {
				if ( 'unchanged' !== $p[0] && 'skip' !== $p[0] && count( $titles ) < 10 ) {
					$titles[] = array(
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
					'sample'  => $titles,
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

		foreach ( $plan as [ $action, $id, $post_id, $desired ] ) {
			if ( 'skip' === $action ) {
				continue;
			}
			if ( 'unchanged' === $action ) {
				++$result['unchanged'];
				continue;
			}
			$post_id = $this->write( (int) $post_id, $desired );
			if ( $post_id <= 0 ) {
				continue;
			}
			$this->mapping->save( 'product', $id, 'post', $post_id, $desired['hash'] );
			++$result[ 'create' === $action ? 'created' : ( 'draft' === $action ? 'drafted' : 'updated' ) ];
			$this->maybeQueueImage( $post_id, $desired['image'] );
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
	 * Link 1.x product posts (meta product_id) into the mapping table.
	 */
	public function linkLegacyPosts(): int {
		$linked = 0;
		$posts  = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post_id ) {
			$in_id = (string) get_post_meta( (int) $post_id, 'product_id', true );
			if ( '' !== $in_id && null === $this->mapping->find( 'product', $in_id ) ) {
				$this->mapping->save( 'product', $in_id, 'post', (int) $post_id, '' );
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
			'product_id'        => (string) ( $item['id'] ?? '' ),
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
	 */
	private function write( int $post_id, array $desired ): int {
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
	 * A 1.x post for this product, if any.
	 *
	 * @param string $in_id Product id.
	 */
	private function legacyPostId( string $in_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'product_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time lookup for 1.x posts.
				'meta_value'     => $in_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
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
		if ( '' === $known && '' !== $url && has_post_thumbnail( $post_id ) ) {
			update_post_meta( $post_id, '_invoiceninja_image_url', $url ); // 1.x already downloaded it.
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
