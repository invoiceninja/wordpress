<?php
/**
 * The store front end: blocks, matching shortcodes, pages and assets.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Frontend;

use InvoiceNinja\Catalog\ProductSync;
use InvoiceNinja\Plugin;

/**
 * Every block renders on the server with the same function its shortcode uses, so cached
 * pages work: prices and products are in the HTML, while anything shopper-specific (the
 * cart, the checkout steps, an order's status) is filled in by assets/store/store.js from
 * REST. Assets load only on pages that render one of these.
 */
final class Store {

	public const HANDLE = 'invoiceninja-store';

	/**
	 * Blocks, by folder under blocks/, and the shortcode each one also answers to.
	 */
	public const BLOCKS = array(
		'products'     => 'invoiceninja_products',
		'product'      => 'invoiceninja_product',
		'add-to-cart'  => 'invoiceninja_add_to_cart',
		'mini-cart'    => 'invoiceninja_mini_cart',
		'checkout'     => 'invoiceninja_checkout_form',
		'order-status' => 'invoiceninja_order_status',
	);

	/**
	 * Renderers added by other modules (account, pay-invoice, pricing-table, ...).
	 *
	 * @var array<string, array{0: callable, 1: string}>
	 */
	private array $extra = array();

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
		add_action( 'init', array( $this, 'registerAssets' ) );
		add_action( 'init', array( $this, 'registerBlocks' ), 20 );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
		add_filter( 'the_content', array( $this, 'productContent' ), 9 );
		add_action( 'template_redirect', array( $this, 'statusRedirect' ) );
	}

	/**
	 * Add a block and shortcode from another module.
	 *
	 * @param string   $folder    Folder under blocks/.
	 * @param string   $shortcode Shortcode tag.
	 * @param callable $render    function( array $attributes ): string.
	 */
	public function add( string $folder, string $shortcode, callable $render ): void {
		$this->extra[ $folder ] = array( $render, $shortcode );
	}

	/**
	 * Whether the plugin's own cart and checkout run (WooCommerce mode turns them off).
	 */
	public static function enabled(): bool {
		/**
		 * Whether the plugin's own cart and checkout run. The WooCommerce module returns
		 * false: WooCommerce runs the store.
		 *
		 * @since 2.0.0
		 * @param bool $enabled Enabled.
		 */
		$on = ! Plugin::booted() || (bool) Plugin::instance()->options->setting( 'modules.store' );
		return (bool) apply_filters( 'invoiceninja_store_enabled', $on );
	}

	/**
	 * Whether the 1.x products page and cart are shown instead of the blocks.
	 */
	public function legacy(): bool {
		return 'legacy' === $this->plugin->options->setting( 'store.display' );
	}

	/**
	 * The "Invoice Ninja" block category.
	 *
	 * @param array<int, array<string, mixed>> $categories Categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'invoiceninja',
				'title' => __( 'Invoice Ninja', 'invoiceninja' ),
				'icon'  => null,
			)
		);
		return $categories;
	}

	/**
	 * Register the front-end and editor assets.
	 */
	public function registerAssets(): void {
		$url = plugins_url( '', $this->plugin->file );
		wp_register_style( self::HANDLE, $url . '/assets/store/store.css', array(), INVOICENINJA_VERSION );
		wp_register_script(
			self::HANDLE,
			$url . '/assets/store/store.js',
			array(),
			INVOICENINJA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_script( 'invoiceninja-blocks', $url . '/assets/blocks/editor.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ), INVOICENINJA_VERSION, true );
		wp_set_script_translations( 'invoiceninja-blocks', 'invoiceninja' );
	}

	/**
	 * Register every block with its server renderer, and the matching shortcodes.
	 */
	public function registerBlocks(): void {
		$base       = dirname( $this->plugin->file ) . '/blocks/';
		$renders    = array(
			'products'     => array( $this, 'products' ),
			'product'      => array( $this, 'product' ),
			'add-to-cart'  => array( $this, 'addToCart' ),
			'mini-cart'    => array( $this, 'miniCart' ),
			'checkout'     => array( $this, 'checkout' ),
			'order-status' => array( $this, 'orderStatus' ),
		);
		$shortcodes = self::BLOCKS;
		foreach ( $this->extra as $folder => [ $render, $shortcode ] ) {
			$renders[ $folder ]    = $render;
			$shortcodes[ $folder ] = $shortcode;
		}
		$names = array();
		foreach ( $renders as $folder => $render ) {
			if ( is_file( $base . $folder . '/block.json' ) ) {
				$names[] = 'invoiceninja/' . $folder;
				register_block_type(
					$base . $folder,
					array(
						'render_callback' => static fn( $attributes ) => (string) call_user_func( $render, is_array( $attributes ) ? $attributes : array() ),
						'editor_script'   => 'invoiceninja-blocks',
					)
				);
			}
			add_shortcode(
				$shortcodes[ $folder ],
				static function ( $atts ) use ( $render ): string {
					$atts = is_array( $atts ) ? $atts : array();
					// Shortcode attributes are lower-case strings; blocks use camelCase and types.
					$clean = array();
					foreach ( $atts as $key => $value ) {
						$key           = lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', (string) $key ) ) ) );
						$clean[ $key ] = in_array( $value, array( 'true', 'false' ), true ) ? 'true' === $value : $value;
					}
					return (string) call_user_func( $render, $clean );
				}
			);
		}
		wp_add_inline_script( 'invoiceninja-blocks', 'window.invoiceNinjaBlocks = ' . wp_json_encode( $names ) . ';', 'before' );
	}

	/**
	 * Load the store script and style, with what it needs to know.
	 */
	public function enqueue(): void {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_add_inline_script( self::HANDLE, 'window.invoiceNinjaStore = ' . wp_json_encode( $this->scriptData() ) . ';', 'before' );
	}

	/**
	 * Data for store.js: REST root, pages, store settings, money format and strings.
	 *
	 * @return array<string, mixed>
	 */
	public function scriptData(): array {
		return array(
			'rest'         => esc_url_raw( rest_url( 'invoiceninja/v1' ) ),
			'mode'         => (string) $this->plugin->options->setting( 'store.mode' ),
			'requireLogin' => (bool) $this->plugin->options->setting( 'store.require_login' ),
			'loggedIn'     => \InvoiceNinja\Commerce\Checkout::userId() > 0,
			'loginUrl'     => wp_login_url( (string) get_permalink() ),
			'taxFields'    => ! empty( $this->plugin->state->get()['company']['calculate_taxes'] ),
			'pages'        => array(
				'shop'     => $this->pageUrl( 'shop' ),
				'checkout' => $this->pageUrl( 'checkout' ),
				'status'   => $this->pageUrl( 'status' ),
			),
			'money'        => $this->plugin->statics->forScript(),
			'unavailable'  => $this->plugin->checkout->unavailable(),
			'i18n'         => Strings::all(),
		);
	}

	/**
	 * A store page's address, or an empty string when it doesn't exist.
	 *
	 * @param string $page shop, checkout, status or account.
	 */
	public function pageUrl( string $page ): string {
		$id = (int) $this->plugin->options->setting( 'pages.' . $page );
		return $id > 0 && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Create the store pages that don't exist yet.
	 *
	 * @param list<string> $which Pages to create (shop, checkout, status).
	 * @return array<string, int> Page ids by key.
	 */
	public function createPages( array $which = array( 'shop', 'checkout', 'status' ) ): array {
		$pages = array(
			'shop'     => array( __( 'Shop', 'invoiceninja' ), '<!-- wp:invoiceninja/products /-->' ),
			'checkout' => array( __( 'Checkout', 'invoiceninja' ), '<!-- wp:invoiceninja/checkout /-->' ),
			'status'   => array( __( 'Order Status', 'invoiceninja' ), '<!-- wp:invoiceninja/order-status /-->' ),
			'account'  => array( __( 'Account', 'invoiceninja' ), '<!-- wp:invoiceninja/account /-->' ),
		);
		$ids   = array();
		foreach ( $which as $key ) {
			if ( ! isset( $pages[ $key ] ) ) {
				continue;
			}
			$current = (int) $this->plugin->options->setting( 'pages.' . $key );
			if ( $current > 0 && get_post( $current ) && 'trash' !== get_post_status( $current ) ) {
				$ids[ $key ] = $current;
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $pages[ $key ][0],
					'post_content' => $pages[ $key ][1],
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				$ids[ $key ] = (int) $id;
			}
		}
		if ( $ids ) {
			$this->plugin->options->saveSettings( array( 'pages' => $ids ) );
		}
		return $ids;
	}

	/**
	 * 1.x settings that the blocks display doesn't use.
	 *
	 * @return list<string>
	 */
	public function legacyCustomisations(): array {
		$found = array();
		$names = array(
			'invoiceninja_product_template' => __( 'a custom product template', 'invoiceninja' ),
			'invoiceninja_image_template'   => __( 'a custom image template', 'invoiceninja' ),
			'invoiceninja_product_css'      => __( 'custom product CSS', 'invoiceninja' ),
			'invoiceninja_products_css'     => __( 'custom products page CSS', 'invoiceninja' ),
		);
		foreach ( $names as $option => $label ) {
			if ( '' !== trim( (string) get_option( $option, '' ) ) ) {
				$found[] = $label;
			}
		}
		return $found;
	}

	/**
	 * Preview, switch to, or switch back from the blocks display.
	 *
	 * @param string $action preview, blocks or legacy.
	 * @return array<string, mixed>
	 */
	public function switchDisplay( string $action ): array {
		$shop = (int) get_option( 'invoiceninja_product_page_id', 0 );
		if ( $shop > 0 && ( ! get_post( $shop ) || 'trash' === get_post_status( $shop ) ) ) {
			$shop = 0;
		}
		$blocks = '<!-- wp:invoiceninja/products /-->';
		if ( 'preview' === $action ) {
			$draft = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					/* translators: %s: page title */
					'post_title'   => sprintf( __( '%s (blocks preview)', 'invoiceninja' ), $shop ? get_the_title( $shop ) : __( 'Shop', 'invoiceninja' ) ),
					'post_content' => $blocks,
				),
				true
			);
			if ( is_wp_error( $draft ) ) {
				return array( 'ok' => false );
			}
			return array(
				'ok'  => true,
				'url' => (string) get_preview_post_link( (int) $draft ),
			);
		}
		if ( 'blocks' === $action ) {
			if ( $shop > 0 ) {
				// Keep the 1.x page for switching back (WordPress also keeps a revision).
				update_post_meta( $shop, '_invoiceninja_legacy_content', wp_slash( (string) get_post_field( 'post_content', $shop, 'raw' ) ) );
				wp_update_post(
					array(
						'ID'           => $shop,
						'post_content' => $blocks,
						'post_status'  => 'publish',
					)
				);
				$this->plugin->options->saveSettings( array( 'pages' => array( 'shop' => $shop ) ) );
			}
			$this->plugin->options->saveSettings( array( 'store' => array( 'display' => 'blocks' ) ) );
			$this->createPages();
			return array(
				'ok'    => true,
				'pages' => array(
					'shop'     => $this->pageUrl( 'shop' ),
					'checkout' => $this->pageUrl( 'checkout' ),
					'status'   => $this->pageUrl( 'status' ),
				),
			);
		}
		$this->plugin->options->saveSettings( array( 'store' => array( 'display' => 'legacy' ) ) );
		$saved = $shop > 0 ? (string) get_post_meta( $shop, '_invoiceninja_legacy_content', true ) : '';
		if ( '' !== $saved ) {
			wp_update_post(
				array(
					'ID'           => $shop,
					'post_content' => wp_slash( $saved ),
				)
			);
		}
		return array( 'ok' => true );
	}

	/**
	 * Old status links (?invoiceninja_order=…) go to the Order Status page. The key is in
	 * the fragment, which browsers keep across the redirect.
	 */
	public function statusRedirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link, nothing changes.
		$number = isset( $_GET['invoiceninja_order'] ) ? sanitize_text_field( wp_unslash( $_GET['invoiceninja_order'] ) ) : '';
		if ( '' === $number || is_page( (int) $this->plugin->options->setting( 'pages.status' ) ) ) {
			return;
		}
		$page = $this->pageUrl( 'status' );
		if ( '' === $page ) {
			$ids  = $this->createPages( array( 'status' ) );
			$page = isset( $ids['status'] ) ? (string) get_permalink( $ids['status'] ) : '';
		}
		if ( '' !== $page ) {
			nocache_headers();
			wp_safe_redirect( add_query_arg( 'order', rawurlencode( $number ), $page ) );
			exit;
		}
	}

	/**
	 * In blocks display, a product's own page shows its price, stock and Add to cart.
	 *
	 * @param string $content Content.
	 */
	public function productContent( string $content ): string {
		if ( $this->legacy() || ! is_singular( ProductSync::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || has_block( 'invoiceninja/product' ) ) {
			return $content;
		}
		$id = (string) get_post_meta( (int) get_the_ID(), 'product_id', true );
		return $this->product( array( 'productId' => $id ) ) . $content;
	}

	// ---------- Renderers ----------

	/**
	 * Products grid.
	 *
	 * @param array<string, mixed> $a Attributes: columns, perPage, orderBy, showImage, showPrice, showStock, showExcerpt, showButton, soldOut.
	 */
	public function products( array $a ): string {
		$a     = $a + array(
			'columns'     => 3,
			'perPage'     => 12,
			'orderBy'     => 'title',
			'showImage'   => true,
			'showPrice'   => true,
			'showStock'   => true,
			'showExcerpt' => false,
			'showButton'  => true,
			'soldOut'     => 'show',
		);
		$state = $this->placeholder();
		if ( null !== $state ) {
			return $state;
		}
		$order = array(
			'title' => array( 'title', 'ASC' ),
			'price' => array( 'meta_value_num', 'ASC' ),
			'date'  => array( 'date', 'DESC' ),
		)[ (string) $a['orderBy'] ] ?? array( 'title', 'ASC' );
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$query = new \WP_Query(
			array(
				'post_type'      => ProductSync::POST_TYPE,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => max( 1, min( 100, (int) $a['perPage'] ) ),
				'paged'          => $paged,
				'orderby'        => $order[0],
				'order'          => $order[1],
				'meta_key'       => 'price' === $a['orderBy'] ? 'price' : '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only when sorting by price.
				'no_found_rows'  => false,
			)
		);
		$this->enqueue();
		$items = '';
		foreach ( $query->posts as $post ) {
			$card = $this->card( $post, $a );
			if ( '' !== $card ) {
				$items .= '<li class="invoiceninja-products__item">' . $card . '</li>';
			}
		}
		if ( '' === $items ) {
			return '<div class="invoiceninja-products invoiceninja-empty"><p>' . esc_html__( 'No products to show yet.', 'invoiceninja' ) . '</p></div>';
		}
		$columns = max( 1, min( 6, (int) $a['columns'] ) );
		$html    = '<div class="invoiceninja-products" data-invoiceninja-store><ul class="invoiceninja-products__grid" style="--invoiceninja-columns:' . $columns . '">' . $items . '</ul>';
		if ( $query->max_num_pages > 1 ) {
			$html .= '<nav class="invoiceninja-products__pages" aria-label="' . esc_attr__( 'Products pages', 'invoiceninja' ) . '">' . paginate_links(
				array(
					'total'   => (int) $query->max_num_pages,
					'current' => $paged,
				)
			) . '</nav>';
		}
		return $html . '</div>';
	}

	/**
	 * One product card in the grid.
	 *
	 * @param \WP_Post             $post Post.
	 * @param array<string, mixed> $a    Attributes.
	 */
	private function card( \WP_Post $post, array $a ): string {
		$id   = (string) get_post_meta( $post->ID, 'product_id', true );
		$info = $this->plugin->cart->product( $id );
		if ( null === $info ) {
			return '';
		}
		$sold_out = $info['max'] < 1;
		if ( $sold_out && 'hide' === $a['soldOut'] ) {
			return '';
		}
		$link  = (string) get_permalink( $post );
		$title = get_the_title( $post );
		$html  = '';
		if ( $a['showImage'] ) {
			$image = get_the_post_thumbnail( $post, 'medium_large', array( 'class' => 'invoiceninja-card__image' ) );
			$html .= '<a class="invoiceninja-card__media" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">'
				. ( $image ? $image : '<span class="invoiceninja-card__noimage">' . esc_html( mb_substr( wp_strip_all_tags( $title ), 0, 1 ) ) . '</span>' )
				. ( $sold_out ? '<span class="invoiceninja-badge">' . esc_html__( 'Sold out', 'invoiceninja' ) . '</span>' : '' ) . '</a>';
		}
		$html .= '<h3 class="invoiceninja-card__title"><a href="' . esc_url( $link ) . '">' . esc_html( wp_strip_all_tags( $title ) ) . '</a></h3>';
		if ( $a['showPrice'] || $a['showStock'] ) {
			$html .= '<p class="invoiceninja-card__meta">'
				. ( $a['showPrice'] ? '<span class="invoiceninja-price">' . esc_html( $this->plugin->statics->money( $info['price'] ) ) . '</span>' : '' )
				. ( $a['showStock'] ? $this->stockText( $info ) : '' ) . '</p>';
		}
		if ( $a['showExcerpt'] ) {
			$html .= '<p class="invoiceninja-card__excerpt">' . esc_html( wp_trim_words( $info['notes'], 20 ) ) . '</p>';
		}
		if ( $a['showButton'] ) {
			$html .= $this->button( $id, $info, false );
		}
		return '<article class="invoiceninja-card">' . $html . '</article>';
	}

	/**
	 * Stock note: sold out, or only a few left.
	 *
	 * @param array<string, mixed> $info Product info from the cart.
	 */
	private function stockText( array $info ): string {
		if ( ! $this->plugin->cart->tracksStock() ) {
			return '';
		}
		if ( $info['max'] < 1 ) {
			return ' <span class="invoiceninja-stock is-out">' . esc_html__( 'Sold out', 'invoiceninja' ) . '</span>';
		}
		if ( $info['max'] < 5 ) {
			/* translators: %d: number left */
			return ' <span class="invoiceninja-stock is-low">' . esc_html( sprintf( _n( 'Only %d left', 'Only %d left', (int) $info['max'], 'invoiceninja' ), (int) $info['max'] ) ) . '</span>';
		}
		return '';
	}

	/**
	 * Add to cart (or Buy now) button, with an optional quantity field.
	 *
	 * @param string               $id       Invoice Ninja product id.
	 * @param array<string, mixed> $info     Product info.
	 * @param bool                 $quantity Show a quantity field.
	 * @param string               $label    Label override.
	 */
	private function button( string $id, array $info, bool $quantity, string $label = '' ): string {
		$mode = (string) $this->plugin->options->setting( 'store.mode' );
		if ( 'off' === $mode || ! self::enabled() ) {
			return '';
		}
		if ( $info['max'] < 1 ) {
			return '<p class="invoiceninja-add"><button type="button" class="wp-element-button invoiceninja-add__button" disabled>' . esc_html__( 'Sold out', 'invoiceninja' ) . '</button></p>';
		}
		$label = '' !== $label ? $label : ( 'buy_now' === $mode ? __( 'Buy now', 'invoiceninja' ) : __( 'Add to cart', 'invoiceninja' ) );
		$field = '';
		if ( $quantity && 'buy_now' !== $mode ) {
			$field_id = 'invoiceninja-qty-' . sanitize_html_class( $id ) . '-' . wp_rand( 100, 999 );
			$field    = '<label class="invoiceninja-add__qty" for="' . esc_attr( $field_id ) . '"><span>' . esc_html__( 'Quantity', 'invoiceninja' ) . '</span><input id="' . esc_attr( $field_id ) . '" type="number" min="1" max="' . (int) $info['max'] . '" value="1" inputmode="numeric" data-invoiceninja-qty></label>';
		}
		return '<form class="invoiceninja-add" data-invoiceninja-add="' . esc_attr( $id ) . '" data-title="' . esc_attr( $info['title'] ) . '">' . $field
			. '<button type="submit" class="wp-element-button invoiceninja-add__button">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * One product: price, stock and Add to cart (variations show only price or stock).
	 *
	 * @param array<string, mixed> $a productId, show (all, price, stock, button), quantity.
	 */
	public function product( array $a ): string {
		$id = ProductSync::cleanId( (string) ( $a['productId'] ?? '' ) );
		if ( '' === $id && is_singular( ProductSync::POST_TYPE ) ) {
			$id = (string) get_post_meta( (int) get_the_ID(), 'product_id', true );
		}
		$info = '' !== $id ? $this->plugin->cart->product( $id ) : null;
		if ( null === $info ) {
			return current_user_can( 'edit_posts' ) ? '<p class="invoiceninja-empty">' . esc_html__( 'Choose a product in the block settings.', 'invoiceninja' ) . '</p>' : '';
		}
		$this->enqueue();
		$show = (string) ( $a['show'] ?? 'all' );
		$html = '';
		if ( in_array( $show, array( 'all', 'price' ), true ) ) {
			$html .= '<span class="invoiceninja-price">' . esc_html( $this->plugin->statics->money( $info['price'] ) ) . '</span>';
		}
		if ( in_array( $show, array( 'all', 'stock' ), true ) ) {
			$html .= $this->stockText( $info );
		}
		if ( 'price' === $show || 'stock' === $show ) {
			return '<span class="invoiceninja-product invoiceninja-product--' . esc_attr( $show ) . '">' . $html . '</span>';
		}
		$out = '<div class="invoiceninja-product" data-invoiceninja-store><p class="invoiceninja-product__meta">' . $html . '</p>';
		if ( in_array( $show, array( 'all', 'button' ), true ) ) {
			$out .= $this->button( $id, $info, (bool) ( $a['quantity'] ?? true ) );
		}
		return $out . '</div>';
	}

	/**
	 * Add to Cart button on its own.
	 *
	 * @param array<string, mixed> $a productId, label, quantity.
	 */
	public function addToCart( array $a ): string {
		$id   = ProductSync::cleanId( (string) ( $a['productId'] ?? '' ) );
		$info = '' !== $id ? $this->plugin->cart->product( $id ) : null;
		if ( null === $info ) {
			return current_user_can( 'edit_posts' ) ? '<p class="invoiceninja-empty">' . esc_html__( 'Choose a product in the block settings.', 'invoiceninja' ) . '</p>' : '';
		}
		$this->enqueue();
		return '<div class="invoiceninja-product" data-invoiceninja-store>' . $this->button( $id, $info, (bool) ( $a['quantity'] ?? false ), sanitize_text_field( (string) ( $a['label'] ?? '' ) ) ) . '</div>';
	}

	/**
	 * Mini Cart: a button with the count (from a readable cookie) and a panel filled from REST.
	 *
	 * @param array<string, mixed> $a label.
	 */
	public function miniCart( array $a ): string {
		if ( 'off' === $this->plugin->options->setting( 'store.mode' ) || ! self::enabled() ) {
			return '';
		}
		$this->enqueue();
		$label = sanitize_text_field( (string) ( $a['label'] ?? '' ) );
		return '<div class="invoiceninja-minicart" data-invoiceninja-minicart data-invoiceninja-store>'
			. '<button type="button" class="invoiceninja-minicart__button" aria-expanded="false" aria-haspopup="dialog">'
			. '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 7h14l-1.2 11.2a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>'
			. '<span class="invoiceninja-minicart__label">' . esc_html( '' !== $label ? $label : __( 'Cart', 'invoiceninja' ) ) . '</span>'
			. '<span class="invoiceninja-minicart__count" data-invoiceninja-count hidden></span></button>'
			. '<div class="invoiceninja-minicart__panel" role="dialog" aria-label="' . esc_attr__( 'Your cart', 'invoiceninja' ) . '" hidden></div></div>';
	}

	/**
	 * Checkout: the cart, the buyer's details, the email code and the result, in steps.
	 *
	 * @param array<string, mixed> $a Unused.
	 */
	public function checkout( array $a ): string {
		unset( $a );
		if ( ! self::enabled() ) {
			return current_user_can( \InvoiceNinja\Core\Capabilities::MANAGE ) ? '<div class="invoiceninja-notice invoiceninja-notice--admin"><strong>' . esc_html__( 'Only you can see this.', 'invoiceninja' ) . '</strong> ' . esc_html__( 'WooCommerce runs your store, so this checkout is hidden. Customers check out with WooCommerce.', 'invoiceninja' ) . '</div>' : '';
		}
		$state = $this->placeholder();
		if ( null !== $state ) {
			return $state;
		}
		$this->enqueue();
		$reason = $this->plugin->checkout->unavailable();
		$admin  = '';
		if ( null !== $reason && current_user_can( \InvoiceNinja\Core\Capabilities::MANAGE ) ) {
			$admin = '<div class="invoiceninja-notice invoiceninja-notice--admin"><strong>' . esc_html__( 'Only you can see this.', 'invoiceninja' ) . '</strong> ' . esc_html( $this->adminReason() ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-status' ) ) . '">' . esc_html__( 'Open Status', 'invoiceninja' ) . '</a></div>';
		}
		if ( current_user_can( \InvoiceNinja\Core\Capabilities::MANAGE ) && empty( $this->plugin->state->get()['gateways'] ) && 'connected' === $this->plugin->state->get()['status'] ) {
			$admin .= '<div class="invoiceninja-notice invoiceninja-notice--admin"><strong>' . esc_html__( 'Only you can see this.', 'invoiceninja' ) . '</strong> ' . esc_html__( "No payment gateway is set up in Invoice Ninja. Customers can place orders but can't pay online.", 'invoiceninja' ) . '</div>';
		}
		return '<div class="invoiceninja-checkout" data-invoiceninja-checkout data-invoiceninja-store>' . $admin
			. '<noscript><p>' . esc_html__( 'Checkout needs JavaScript. Please turn it on, or contact us to order.', 'invoiceninja' ) . '</p></noscript>'
			. '<p class="invoiceninja-loading">' . esc_html__( 'Loading your cart…', 'invoiceninja' ) . '</p></div>';
	}

	/**
	 * Order Status: filled in from ?order= and the #key= fragment.
	 *
	 * @param array<string, mixed> $a Unused.
	 */
	public function orderStatus( array $a ): string {
		unset( $a );
		$this->enqueue();
		return '<div class="invoiceninja-status" data-invoiceninja-status data-invoiceninja-store><p class="invoiceninja-loading">' . esc_html__( 'Loading your order…', 'invoiceninja' ) . '</p></div>';
	}

	/**
	 * What admins (only) see when nothing can be shown: not connected yet.
	 */
	private function placeholder(): ?string {
		if ( 'none' !== $this->plugin->credentials->source() ) {
			return null;
		}
		if ( ! current_user_can( \InvoiceNinja\Core\Capabilities::MANAGE ) ) {
			return '';
		}
		return '<div class="invoiceninja-notice invoiceninja-notice--admin"><strong>' . esc_html__( 'Only you can see this.', 'invoiceninja' ) . '</strong> ' . esc_html__( 'Connect Invoice Ninja to show your products here.', 'invoiceninja' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-setup' ) ) . '">' . esc_html__( 'Connect Invoice Ninja', 'invoiceninja' ) . '</a></div>';
	}

	/**
	 * Why ordering is unavailable, for admins.
	 */
	private function adminReason(): string {
		if ( ! $this->plugin->environment->writesAllowed() ) {
			return __( 'Orders are paused on this site (it looks like a staging copy). Allow them in Status.', 'invoiceninja' );
		}
		$kind = \InvoiceNinja\Api\ErrorKind::tryFrom( (string) ( $this->plugin->state->get()['error']['kind'] ?? '' ) );
		return $kind ? $kind->title() . '. ' . $kind->fix() : __( "Invoice Ninja isn't connected.", 'invoiceninja' );
	}
}
