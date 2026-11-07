<?php
/**
 * Hide synced products from this site without touching Invoice Ninja.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Catalog;

/**
 * A hidden product is a draft with ProductSync::HIDDEN_META set to the status it gets back
 * when shown, so every shop, block, cart and sitemap leaves it out, and the sync keeps it a
 * draft while still updating it. Products → Hide from site (a row link or a bulk action)
 * hides one; so does setting a synced product to Draft by hand. Show on site, or publishing
 * it by hand, brings it back.
 */
final class Visibility {

	private const ACTION = 'invoiceninja_product_visibility';

	private const VISIBLE = array( 'publish', 'private', 'pending', 'future' );

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'statusChanged' ), 10, 3 );
		if ( ! is_admin() ) {
			return;
		}
		$screen = 'edit-' . ProductSync::POST_TYPE;
		// The post type is hierarchical, so its rows use page_row_actions.
		add_filter( 'page_row_actions', array( $this, 'rowActions' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'rowActions' ), 10, 2 );
		add_filter( 'bulk_actions-' . $screen, array( $this, 'bulkActions' ) );
		add_filter( 'handle_bulk_actions-' . $screen, array( $this, 'handleBulk' ), 10, 3 );
		add_filter( 'views_' . $screen, array( $this, 'views' ) );
		add_filter( 'display_post_states', array( $this, 'states' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'filterList' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleLink' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Whether a product is hidden from this site.
	 *
	 * @param int $post_id Post.
	 */
	public static function hidden( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, ProductSync::HIDDEN_META, true );
	}

	/**
	 * Hide a product from this site.
	 *
	 * @param int $post_id Post.
	 * @return bool Whether it's hidden now.
	 */
	public function hide( int $post_id ): bool {
		$status = get_post_status( $post_id );
		if ( ProductSync::POST_TYPE !== get_post_type( $post_id ) || false === $status || 'trash' === $status ) {
			return false;
		}
		if ( self::hidden( $post_id ) ) {
			return true;
		}
		update_post_meta( $post_id, ProductSync::HIDDEN_META, $status );
		if ( 'draft' !== $status ) {
			ProductSync::quietly(
				static fn() => wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				)
			);
		}
		return true;
	}

	/**
	 * Show a hidden product again, with the status it had (or Invoice Ninja asked for since).
	 *
	 * @param int $post_id Post.
	 * @return bool Whether it's no longer hidden.
	 */
	public function show( int $post_id ): bool {
		if ( ProductSync::POST_TYPE !== get_post_type( $post_id ) ) {
			return false;
		}
		$before = (string) get_post_meta( $post_id, ProductSync::HIDDEN_META, true );
		if ( '' === $before ) {
			return true;
		}
		delete_post_meta( $post_id, ProductSync::HIDDEN_META );
		$status = in_array( $before, array_merge( self::VISIBLE, array( 'draft' ) ), true ) ? $before : 'publish';
		if ( get_post_status( $post_id ) !== $status ) {
			ProductSync::quietly(
				static fn() => wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => $status,
					)
				)
			);
		}
		return true;
	}

	/**
	 * An admin set a synced product to Draft (hide it) or published a hidden one (show it).
	 *
	 * @param string $to   New status.
	 * @param string $from Old status.
	 * @param mixed  $post Post.
	 */
	public function statusChanged( $to, $from, $post ): void {
		if ( ProductSync::isQuiet() || ! $post instanceof \WP_Post || ProductSync::POST_TYPE !== $post->post_type || $to === $from ) {
			return;
		}
		if ( 'draft' === $to && in_array( $from, self::VISIBLE, true ) && ! self::hidden( $post->ID ) ) {
			update_post_meta( $post->ID, ProductSync::HIDDEN_META, $from );
		} elseif ( in_array( $to, self::VISIBLE, true ) && self::hidden( $post->ID ) ) {
			delete_post_meta( $post->ID, ProductSync::HIDDEN_META );
		}
	}

	/**
	 * Hide from site / Show on site under each product.
	 *
	 * @param array<string, string> $actions Actions.
	 * @param mixed                 $post    Post.
	 * @return array<string, string>
	 */
	public function rowActions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();
		if ( ! $post instanceof \WP_Post || ProductSync::POST_TYPE !== $post->post_type || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$hidden = self::hidden( $post->ID );
		$url    = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post->ID,
					'do'     => $hidden ? 'show' : 'hide',
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post->ID
		);
		$title  = wp_strip_all_tags( get_the_title( $post ) );
		$label  = $hidden ? __( 'Show on site', 'invoiceninja' ) : __( 'Hide from site', 'invoiceninja' );
		/* translators: 1: Show on site or Hide from site, 2: product name */
		$aria                               = sprintf( __( '%1$s: %2$s', 'invoiceninja' ), $label, $title );
		$actions['invoiceninja_visibility'] = '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( $label ) . '</a>';
		return $actions;
	}

	/**
	 * The row link.
	 */
	public function handleLink(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		check_admin_referer( self::ACTION . '_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this product.', 'invoiceninja' ), '', array( 'response' => 403 ) );
		}
		$hide = 'hide' === ( isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '' );
		$done = $hide ? $this->hide( $post_id ) : $this->show( $post_id );
		$back = wp_get_referer();
		$back = $back ? $back : admin_url( 'edit.php?post_type=' . ProductSync::POST_TYPE );
		wp_safe_redirect( add_query_arg( $hide ? 'invoiceninja_hid' : 'invoiceninja_showed', $done ? 1 : 0, remove_query_arg( array( 'invoiceninja_hid', 'invoiceninja_showed' ), $back ) ) );
		exit;
	}

	/**
	 * Bulk actions.
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public function bulkActions( $actions ): array {
		$actions = is_array( $actions ) ? $actions : array();
		return $actions + array(
			'invoiceninja_hide' => __( 'Hide from site', 'invoiceninja' ),
			'invoiceninja_show' => __( 'Show on site', 'invoiceninja' ),
		);
	}

	/**
	 * Run a bulk action (WordPress checked the nonce).
	 *
	 * @param string $redirect Where to go next.
	 * @param string $action   Action.
	 * @param array<int|string> $ids Post ids.
	 */
	public function handleBulk( $redirect, $action, $ids ): string {
		$redirect = (string) $redirect;
		if ( ! in_array( $action, array( 'invoiceninja_hide', 'invoiceninja_show' ), true ) ) {
			return $redirect;
		}
		$hide = 'invoiceninja_hide' === $action;
		$done = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id = (int) $id;
			// Only count products whose state changed.
			if ( current_user_can( 'edit_post', $id ) && self::hidden( $id ) !== $hide && ( $hide ? $this->hide( $id ) : $this->show( $id ) ) ) {
				++$done;
			}
		}
		return add_query_arg( $hide ? 'invoiceninja_hid' : 'invoiceninja_showed', $done, remove_query_arg( array( 'invoiceninja_hid', 'invoiceninja_showed' ), $redirect ) );
	}

	/**
	 * "Hidden from site" in place of "Draft".
	 *
	 * @param array<string, string> $states States.
	 * @param mixed                 $post   Post.
	 * @return array<string, string>
	 */
	public function states( $states, $post ): array {
		$states = is_array( $states ) ? $states : array();
		if ( $post instanceof \WP_Post && ProductSync::POST_TYPE === $post->post_type && 'trash' !== $post->post_status && self::hidden( $post->ID ) ) {
			unset( $states['draft'] );
			$states['invoiceninja_hidden'] = __( 'Hidden from site', 'invoiceninja' );
		}
		return $states;
	}

	/**
	 * A "Hidden from site" view above the list.
	 *
	 * @param array<string, string> $views Views.
	 * @return array<string, string>
	 */
	public function views( $views ): array {
		global $wpdb;
		$views = is_array( $views ) ? $views : array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_type = %s AND p.post_status <> 'trash'", ProductSync::HIDDEN_META, ProductSync::POST_TYPE ) );
		if ( $count < 1 ) {
			return $views;
		}
		$current = ! empty( $_GET['invoiceninja_hidden'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
		$url     = admin_url( 'edit.php?post_type=' . ProductSync::POST_TYPE . '&invoiceninja_hidden=1' );
		if ( $current ) {
			$views = array_map( static fn( string $link ): string => str_replace( array( ' class="current"', ' aria-current="page"' ), '', $link ), $views );
		}
		$views['invoiceninja_hidden'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Hidden from site', 'invoiceninja' ),
			esc_html( number_format_i18n( $count ) )
		);
		return $views;
	}

	/**
	 * The "Hidden from site" view's query.
	 *
	 * @param mixed $query Query.
	 */
	public function filterList( $query ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || empty( $_GET['invoiceninja_hidden'] ) || ProductSync::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$query->set(
			'meta_query',
			array(
				array(
					'key'     => ProductSync::HIDDEN_META,
					'value'   => '',
					'compare' => '!=',
				),
			)
		);
	}

	/**
	 * What a row or bulk action did.
	 */
	public function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'edit-' . ProductSync::POST_TYPE !== $screen->id ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- counts to show.
		$hid    = isset( $_GET['invoiceninja_hid'] ) ? absint( $_GET['invoiceninja_hid'] ) : null;
		$showed = isset( $_GET['invoiceninja_showed'] ) ? absint( $_GET['invoiceninja_showed'] ) : null;
		// phpcs:enable
		if ( null !== $hid ) {
			/* translators: %d: number of products */
			$text = sprintf( _n( '%d product hidden from your site. It stays in Invoice Ninja and keeps syncing.', '%d products hidden from your site. They stay in Invoice Ninja and keep syncing.', $hid, 'invoiceninja' ), $hid );
		} elseif ( null !== $showed ) {
			/* translators: %d: number of products */
			$text = sprintf( _n( '%d product shown on your site again.', '%d products shown on your site again.', $showed, 'invoiceninja' ), $showed );
		} else {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
