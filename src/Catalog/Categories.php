<?php
/**
 * Product categories, kept in step with Invoice Ninja's product tags.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Catalog;

/**
 * Each Invoice Ninja product tag is a term of TAXONOMY, linked by the tag's id (term meta
 * TAG_META), so a tag renamed in Invoice Ninja renames its term instead of adding another.
 * The sync sets each product's terms; terms no product uses any more are removed after a
 * sync. Shoppers filter the Products block by them; there are no category archive pages.
 */
final class Categories {

	public const TAXONOMY = 'invoiceninja_category';

	public const TAG_META = '_invoiceninja_tag_id';

	/**
	 * Register the taxonomy.
	 */
	public static function register(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		register_taxonomy(
			self::TAXONOMY,
			ProductSync::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Categories', 'invoiceninja' ),
					'singular_name' => __( 'Category', 'invoiceninja' ),
				),
				'description'        => __( 'Product tags from Invoice Ninja.', 'invoiceninja' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_admin_column'  => true,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'show_in_rest'       => false,
				'hierarchical'       => false,
				'rewrite'            => false,
				'query_var'          => false,
				// Invoice Ninja owns them: changes made here would be undone by the next sync.
				'meta_box_cb'        => false,
				'capabilities'       => array(
					'manage_terms' => 'manage_invoiceninja',
					'edit_terms'   => 'do_not_allow',
					'delete_terms' => 'do_not_allow',
					'assign_terms' => 'do_not_allow',
				),
			)
		);
	}

	/**
	 * Tags from an Invoice Ninja product (pure, for tests): id and name, sorted by id.
	 *
	 * @param mixed $tags The product's tags field.
	 * @return list<array{id: string, name: string}>
	 */
	public static function fromProduct( mixed $tags ): array {
		$out = array();
		foreach ( is_array( $tags ) ? $tags : array() as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}
			$id   = ProductSync::cleanId( (string) ( $tag['id'] ?? '' ) );
			$name = trim( sanitize_text_field( (string) ( $tag['name'] ?? '' ) ) );
			if ( '' === $id || '' === $name ) {
				continue;
			}
			$out[ $id ] = array(
				'id'   => $id,
				'name' => mb_substr( $name, 0, 190 ),
			);
		}
		ksort( $out, SORT_STRING );
		return array_values( $out );
	}

	/**
	 * Give a product post exactly these categories, adding or renaming terms as needed.
	 *
	 * @param int                                    $post_id Post.
	 * @param list<array{id: string, name: string}> $tags    Tags.
	 */
	public static function assign( int $post_id, array $tags ): void {
		self::register();
		$ids = array();
		foreach ( $tags as $tag ) {
			$term = self::term( $tag['id'], $tag['name'] );
			if ( $term > 0 ) {
				$ids[] = $term;
			}
		}
		wp_set_object_terms( $post_id, $ids, self::TAXONOMY );
	}

	/**
	 * The term for a tag: found by the tag's id, renamed when the name changed, or added.
	 *
	 * @param string $tag_id Invoice Ninja tag id.
	 * @param string $name   Tag name.
	 */
	private static function term( string $tag_id, string $name ): int {
		$found = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'all',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key, only while syncing.
					array(
						'key'   => self::TAG_META,
						'value' => $tag_id,
					),
				),
			)
		);
		$term  = is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ? $found[0] : null;
		if ( null !== $term ) {
			if ( $term->name !== $name ) {
				$changed = wp_update_term(
					$term->term_id,
					self::TAXONOMY,
					array(
						'name' => $name,
						'slug' => wp_unique_term_slug( sanitize_title( $name ), $term ),
					)
				);
				if ( is_wp_error( $changed ) ) {
					wp_update_term( $term->term_id, self::TAXONOMY, array( 'name' => $name ) );
				}
			}
			return (int) $term->term_id;
		}
		$added = wp_insert_term( $name, self::TAXONOMY );
		if ( is_wp_error( $added ) ) {
			$existing = (int) $added->get_error_data( 'term_exists' );
			if ( $existing > 0 && '' === (string) get_term_meta( $existing, self::TAG_META, true ) ) {
				// A term with this name and no link yet (another tag had it before a rename): take it over.
				$added = array( 'term_id' => $existing );
			} else {
				// The name is taken by another tag's term (a previous company's "Sale", or a name
				// that differs only in case): a term of its own, with a slug made unique by the tag.
				$added = wp_insert_term( $name, self::TAXONOMY, array( 'slug' => sanitize_title( $name . '-' . $tag_id ) ) );
				if ( is_wp_error( $added ) ) {
					return 0;
				}
			}
		}
		update_term_meta( (int) $added['term_id'], self::TAG_META, $tag_id );
		return (int) $added['term_id'];
	}

	/**
	 * Remove synced terms no product uses any more (a tag deleted or taken off every product).
	 */
	public static function prune(): void {
		global $wpdb;
		self::register();
		// One query for every unused term. Not the term count: that counts published
		// products only, and a hidden (draft) product still has its categories.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off cleanup after a sync.
		$unused = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id IS NULL",
				self::TAXONOMY
			)
		);
		foreach ( $unused as $term_id ) {
			wp_delete_term( (int) $term_id, self::TAXONOMY );
		}
	}

	/**
	 * Categories that have published products, by name: slug => name.
	 *
	 * @return array<string, string>
	 */
	public static function forShop(): array {
		self::register();
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[ $term->slug ] = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $out;
	}
}
