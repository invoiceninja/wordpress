<?php
/**
 * The Invoice Ninja catalog in Woo mode.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\WooCommerce;

use InvoiceNinja\Catalog\ProductSync;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Plugin;

/**
 * In Woo mode WooCommerce shows and sells the products:
 * - our product post type stops being public (no archive in search, menus or sitemaps),
 *   and its addresses redirect (301) to the WooCommerce product with the same SKU
 *   (SKU = Invoice Ninja product_key), or to the shop while there is none;
 * - optionally, Invoice Ninja products are pushed into WooCommerce as simple products
 *   (created when missing; prices of products we created kept in step when asked).
 *   WooCommerce owns stock, so stock is never pushed.
 */
final class CatalogBridge {

	public const META_PRODUCT = '_invoiceninja_product_id';

	/**
	 * Invoice Ninja product ids this site created in WooCommerce: one the shop has since
	 * trashed or deleted is never created again.
	 */
	public const PUSHED = 'woo_pushed_products';

	/** Products read per page. */
	private const BATCH = 200;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'register_post_type_args', array( $this, 'postTypeArgs' ), 20, 2 );
		add_action( 'template_redirect', array( $this, 'redirect' ), 5 );
		// After each product sync, push the catalog into WooCommerce when that's on.
		add_action(
			'invoiceninja_sync_products',
			function (): void {
				if ( $this->plugin->options->setting( 'woo.catalog_push' ) ) {
					$this->push( false );
				}
			},
			20
		);
	}

	/**
	 * Make our product post type non-public. Its URLs still resolve, so they can redirect.
	 *
	 * @param array<string, mixed> $args Args.
	 * @param string               $type Post type.
	 * @return array<string, mixed>
	 */
	public function postTypeArgs( $args, $type ): array {
		$args = is_array( $args ) ? $args : array();
		if ( ProductSync::POST_TYPE !== $type ) {
			return $args;
		}
		return array_merge(
			$args,
			array(
				'public'              => false,
				'publicly_queryable'  => true,
				'exclude_from_search' => true,
				'show_in_nav_menus'   => false,
				'show_ui'             => true,
			)
		);
	}

	/**
	 * Redirect our product pages to WooCommerce.
	 */
	public function redirect(): void {
		if ( is_singular( ProductSync::POST_TYPE ) ) {
			$key = (string) get_post_meta( (int) get_queried_object_id(), 'product_key', true );
			$id  = '' !== $key && function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $key ) : 0;
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				wp_safe_redirect( (string) get_permalink( $id ), 301 );
				exit;
			}
			// No WooCommerce product yet: a temporary redirect, so a later match takes over.
			wp_safe_redirect( $this->shopUrl(), 302 );
			exit;
		}
		if ( is_post_type_archive( ProductSync::POST_TYPE ) ) {
			wp_safe_redirect( $this->shopUrl(), 301 );
			exit;
		}
	}

	/**
	 * The WooCommerce shop page, or the home page.
	 */
	private function shopUrl(): string {
		$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Push Invoice Ninja products into WooCommerce.
	 *
	 * @param bool $dry_run Only report what would change.
	 * @return array{dry_run: bool, create: list<string>, update: list<string>, linked: int, skipped: int, failed: int}
	 */
	public function push( bool $dry_run = true ): array {
		$result = array(
			'dry_run' => $dry_run,
			'create'  => array(),
			'update'  => array(),
			'linked'  => 0,
			'skipped' => 0,
			'failed'  => 0,
		);
		$update = (bool) $this->plugin->options->setting( 'woo.catalog_update' );
		$page   = 1;
		do {
			$posts = get_posts(
				array(
					'post_type'      => ProductSync::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => self::BATCH,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $post ) {
				$this->pushOne( $post, $update, $dry_run, $result );
			}
			$full = count( $posts ) === self::BATCH;
			++$page;
		} while ( $full );
		if ( ! $dry_run ) {
			$this->plugin->options->set(
				'woo_catalog_push',
				array(
					'at'      => time(),
					'created' => count( $result['create'] ) - $result['failed'],
					'updated' => count( $result['update'] ),
				)
			);
			$this->plugin->logger->log( LogLevel::Info, 'woocommerce', sprintf( 'Catalog pushed to WooCommerce: %d created, %d updated', count( $result['create'] ) - $result['failed'], count( $result['update'] ) ) );
		}
		return $result;
	}

	/**
	 * Push one product, counting what happened.
	 *
	 * @param mixed                                                                                                    $post    Our product post.
	 * @param bool                                                                                                     $update  Keep prices of products we created in step.
	 * @param bool                                                                                                     $dry_run Only report.
	 * @param array{dry_run: bool, create: list<string>, update: list<string>, linked: int, skipped: int, failed: int} $result  Counts.
	 */
	private function pushOne( $post, bool $update, bool $dry_run, array &$result ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$key = trim( (string) get_post_meta( $post->ID, 'product_key', true ) );
		if ( '' === $key ) {
			++$result['skipped'];
			return;
		}
		$item     = array(
			'id'    => (string) get_post_meta( $post->ID, 'product_id', true ),
			'key'   => $key,
			'price' => (string) get_post_meta( $post->ID, 'price', true ),
			'notes' => (string) $post->post_content,
			'image' => (int) get_post_thumbnail_id( $post->ID ),
		);
		$existing = (int) wc_get_product_id_by_sku( $key );
		if ( $existing <= 0 ) {
			if ( $this->removedByShop( $item['id'] ) ) {
				++$result['skipped']; // Trashed or deleted in WooCommerce: the shop stopped selling it.
				return;
			}
			$result['create'][] = $key;
			if ( ! $dry_run && ! $this->create( $item ) ) {
				++$result['failed'];
			}
			return;
		}
		$product = wc_get_product( $existing );
		if ( ! $product instanceof \WC_Product ) {
			++$result['skipped'];
			return;
		}
		// Only products we created are kept in step; a product the shop made stays theirs.
		if ( ! $update || (string) $product->get_meta( self::META_PRODUCT ) !== $item['id'] ) {
			++$result['linked'];
			return;
		}
		if ( wc_format_decimal( $product->get_regular_price() ) === wc_format_decimal( $item['price'] ) ) {
			++$result['linked'];
			return;
		}
		$result['update'][] = $key;
		if ( ! $dry_run ) {
			$product->set_regular_price( wc_format_decimal( $item['price'] ) );
			$product->save();
		}
	}

	/**
	 * Whether this site created a product for an Invoice Ninja product before, which the
	 * shop has since trashed, deleted or renamed.
	 *
	 * @param string $id Invoice Ninja product id.
	 */
	private function removedByShop( string $id ): bool {
		if ( '' === $id ) {
			return false;
		}
		if ( in_array( $id, array_map( 'strval', (array) $this->plugin->options->get( self::PUSHED, array() ) ), true ) ) {
			return true;
		}
		// Created before the list was kept: still in the trash, or under another SKU.
		return (bool) get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'meta_key'       => self::META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only for products missing by SKU.
				'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- only for products missing by SKU.
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Create one simple product.
	 *
	 * @param array{id: string, key: string, price: string, notes: string, image: int} $item Product.
	 */
	private function create( array $item ): bool {
		try {
			$product = new \WC_Product_Simple();
			$product->set_name( $item['key'] );
			$product->set_sku( $item['key'] );
			$product->set_regular_price( wc_format_decimal( $item['price'] ) );
			$product->set_description( wp_kses_post( $item['notes'] ) );
			$product->set_status( 'publish' );
			if ( $item['image'] > 0 ) {
				$product->set_image_id( $item['image'] );
			}
			$product->update_meta_data( self::META_PRODUCT, $item['id'] );
			if ( $product->save() <= 0 ) {
				return false;
			}
			if ( '' !== $item['id'] ) {
				$pushed   = (array) $this->plugin->options->get( self::PUSHED, array() );
				$pushed[] = $item['id'];
				$this->plugin->options->set( self::PUSHED, array_values( array_unique( array_map( 'strval', $pushed ) ) ), false );
			}
			return true;
		} catch ( \Throwable $e ) {
			$this->plugin->logger->warning( 'woocommerce', sprintf( "Product %s couldn't be created in WooCommerce: %s", $item['key'], $e->getMessage() ) );
			return false;
		}
	}
}
