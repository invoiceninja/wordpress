<?php
/**
 * The store front end: blocks, matching shortcodes, pages and assets.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Frontend;

use InvoiceNinja\Catalog\Categories;
use InvoiceNinja\Catalog\ImageSideloader;
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

	/** The block template slug for one product's page. */
	private const PRODUCT_TEMPLATE = 'single-' . ProductSync::POST_TYPE;

	/**
	 * Script for the shop filters, the quantity stepper and the photo gallery.
	 */
	public const CATALOG = 'invoiceninja-catalog';

	/**
	 * Product custom fields a block can show under the product name.
	 */
	public const DESCRIPTOR_FIELDS = array( 'custom_value1', 'custom_value2', 'custom_value3', 'custom_value4' );

	/**
	 * Stock below this shows "Only N left".
	 */
	public const LOW_STOCK = 5;

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
		add_action( 'enqueue_block_assets', array( $this, 'editorStyles' ) );
		add_action( 'init', array( $this, 'productTemplate' ), 20 );
		add_filter( 'the_content', array( $this, 'productContent' ), 9 );
		add_action( 'template_redirect', array( $this, 'statusRedirect' ) );
		add_action( 'init', array( Categories::class, 'register' ), 11 );
		$this->plugin->queue->handle( ProductSync::GALLERY_HOOK, fn( array $payload ) => ( new ImageSideloader( $this->plugin ) )->gallery( $payload ) );
		// Find my order emails are sent in the background, so the reply's timing is the same either way.
		$this->plugin->queue->handle( \InvoiceNinja\Commerce\Checkout::FIND_HOOK, fn( array $payload ) => $this->plugin->checkout->sendFindLink( $payload ) );
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
		if ( 'legacy' === $this->plugin->options->setting( 'store.display' ) ) {
			return true;
		}
		// A 1.x site whose upgrade hasn't run yet (the 1.x code decides before it runs) keeps its display.
		return '' === (string) $this->plugin->options->get( 'version', '' ) && \InvoiceNinja\Core\Installer::hasLegacyData();
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
		wp_register_script(
			self::CATALOG,
			$url . '/assets/store/catalog.js',
			array( self::HANDLE ),
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
	 * A product page template for block themes: image beside the title, price, Add to cart and
	 * description, without the blog post byline. A theme's own single-invoiceninja_product wins,
	 * and the legacy display keeps the theme's single template.
	 */
	public function productTemplate(): void {
		if ( ! function_exists( 'register_block_template' ) || $this->legacy() ) {
			return;
		}
		// WordPress 6.7 refuses template names with an underscore (allowed from 6.8), and the
		// post type's single template needs one, so 6.7 gets the same template through filters.
		if ( version_compare( get_bloginfo( 'version' ), '6.8-alpha', '<' ) ) {
			add_filter( 'get_block_templates', array( $this, 'addProductTemplate' ), 10, 3 );
			add_filter( 'get_block_file_template', array( $this, 'productFileTemplate' ), 10, 3 );
			return;
		}
		register_block_template( 'invoiceninja//' . self::PRODUCT_TEMPLATE, self::productTemplateArgs() );
	}

	/**
	 * WordPress 6.7: our product template among the theme's, unless the theme has its own or
	 * it was edited in the Site Editor (both come back with the same slug, and win).
	 *
	 * @param mixed                $templates Templates found.
	 * @param array<string, mixed> $query     Query: slug__in, slug__not_in, post_type.
	 * @param string               $type      wp_template or wp_template_part.
	 * @return mixed
	 */
	public function addProductTemplate( $templates, array $query = array(), string $type = 'wp_template' ) {
		if ( ! is_array( $templates ) || 'wp_template' !== $type
			|| ( ! empty( $query['slug__in'] ) && ! in_array( self::PRODUCT_TEMPLATE, (array) $query['slug__in'], true ) )
			|| ( ! empty( $query['slug__not_in'] ) && in_array( self::PRODUCT_TEMPLATE, (array) $query['slug__not_in'], true ) )
			|| ( ! empty( $query['post_type'] ) && ProductSync::POST_TYPE !== $query['post_type'] ) ) {
			return $templates;
		}
		foreach ( $templates as $template ) {
			if ( $template instanceof \WP_Block_Template && self::PRODUCT_TEMPLATE === $template->slug ) {
				return $templates;
			}
		}
		$templates[] = self::productBlockTemplate();
		return $templates;
	}

	/**
	 * WordPress 6.7: the Site Editor opens our product template by its id.
	 *
	 * @param mixed  $template Template found, or null.
	 * @param string $id       Template id: theme//slug.
	 * @param string $type     wp_template or wp_template_part.
	 * @return mixed
	 */
	public function productFileTemplate( $template, string $id = '', string $type = 'wp_template' ) {
		if ( null === $template && 'wp_template' === $type && get_stylesheet() . '//' . self::PRODUCT_TEMPLATE === $id ) {
			return self::productBlockTemplate();
		}
		return $template;
	}

	/**
	 * The product template as WordPress 6.8+ builds it from register_block_template().
	 */
	private static function productBlockTemplate(): \WP_Block_Template {
		$args                  = self::productTemplateArgs();
		$template              = new \WP_Block_Template();
		$template->id          = get_stylesheet() . '//' . self::PRODUCT_TEMPLATE;
		$template->theme       = get_stylesheet();
		$template->plugin      = 'invoiceninja';
		$template->content     = $args['content'];
		$template->source      = 'plugin';
		$template->origin      = 'plugin';
		$template->slug        = self::PRODUCT_TEMPLATE;
		$template->type        = 'wp_template';
		$template->title       = $args['title'];
		$template->description = $args['description'];
		$template->status      = 'publish';
		$template->is_custom   = true;
		$template->post_types  = $args['post_types'];
		return $template;
	}

	/**
	 * Title, description, post types and content of the product template.
	 *
	 * @return array{title: string, description: string, post_types: array<int, string>, content: string}
	 */
	private static function productTemplateArgs(): array {
		return array(
			'title'       => __( 'Invoice Ninja product', 'invoiceninja' ),
			'description' => __( 'One product: image, price, Add to cart and description.', 'invoiceninja' ),
			'post_types'  => array( ProductSync::POST_TYPE ),
			'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
				. '<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->'
				. '<main class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">'
				. '<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|50"}}}} -->'
				. '<div class="wp-block-columns alignwide">'
				. '<!-- wp:column --><div class="wp-block-column"><!-- wp:invoiceninja/product {"show":"gallery"} /--></div><!-- /wp:column -->'
				. '<!-- wp:column {"verticalAlignment":"center"} --><div class="wp-block-column is-vertically-aligned-center">'
				. '<!-- wp:post-title {"level":1} /--><!-- wp:post-content {"layout":{"type":"default"}} /-->'
				. '</div><!-- /wp:column --></div><!-- /wp:columns --></main><!-- /wp:group -->'
				. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
		);
	}

	/**
	 * Our block styles inside the editor canvas, so the previews look like the site.
	 */
	public function editorStyles(): void {
		if ( ! is_admin() ) {
			return;
		}
		foreach ( array( self::HANDLE, 'invoiceninja-quote-form', 'invoiceninja-subscriptions' ) as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				wp_enqueue_style( $handle );
			}
		}
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
						// The block's own settings (alignment, spacing, colours) go on a wrapper.
						'render_callback' => static function ( $attributes ) use ( $render ): string {
							$html = (string) call_user_func( $render, is_array( $attributes ) ? $attributes : array() );
							return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
						},
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
		$company    = (array) ( $this->plugin->state->get()['company'] ?? array() );
		$tax_fields = $this->plugin->checkout->taxFields();
		$countries  = array();
		foreach ( $tax_fields ? $this->plugin->statics->countries() : array() as $id => $name ) {
			$countries[] = array( (string) $id, $name );
		}
		return array(
			'rest'         => esc_url_raw( rest_url( 'invoiceninja/v1' ) ),
			'mode'         => (string) $this->plugin->options->setting( 'store.mode' ),
			'requireLogin' => (bool) $this->plugin->options->setting( 'store.require_login' ),
			'loggedIn'     => \InvoiceNinja\Commerce\Checkout::userId() > 0,
			'loginUrl'     => wp_login_url( (string) get_permalink() ),
			'taxFields'    => $tax_fields,
			'countries'    => $countries,
			'country'      => (string) ( $company['country_id'] ?? '' ),
			'taxIncluded'  => $this->plugin->cart->inclusiveTaxes(),
			'contact'      => is_email( (string) ( $company['email'] ?? '' ) ) ? 'mailto:' . (string) $company['email'] : '',
			'pages'        => array(
				'shop'     => $this->pageUrl( 'shop' ),
				'checkout' => $this->pageUrl( 'checkout' ),
				'status'   => $this->pageUrl( 'status' ),
			),
			'money'        => $this->plugin->statics->forScript(),
			// Whether ordering is available isn't here: the page may be cached, so checkout asks with the cart.
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
	 * Status links without an Order Status page (?invoiceninja_order=…) go to that page once
	 * it exists; the key is in the fragment, which browsers keep across the redirect. Until
	 * then the order is shown on a plain page here. Visitors never create pages.
	 */
	public function statusRedirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link, nothing changes.
		$number      = isset( $_GET['invoiceninja_order'] ) ? sanitize_text_field( wp_unslash( $_GET['invoiceninja_order'] ) ) : '';
		$status_page = (int) $this->plugin->options->setting( 'pages.status' );
		// is_page( 0 ) would be true on any page.
		if ( '' === $number || ( $status_page > 0 && is_page( $status_page ) ) ) {
			return;
		}
		nocache_headers();
		$page = $this->pageUrl( 'status' );
		if ( '' !== $page ) {
			wp_safe_redirect( add_query_arg( 'order', rawurlencode( $number ), $page ) );
			exit;
		}
		$this->standalone( $this->orderStatus( array() ) );
		exit;
	}

	/**
	 * A minimal page around one block's HTML, in the theme's styles (for links that work
	 * before the store pages exist).
	 *
	 * @param string $html Block HTML, already escaped.
	 */
	public function standalone( string $html ): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		echo '<!doctype html><html ';
		language_attributes();
		echo '><head><meta charset="' . esc_attr( (string) get_option( 'blog_charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
		wp_head();
		echo '</head><body class="' . esc_attr( implode( ' ', get_body_class( 'invoiceninja-standalone' ) ) ) . '">';
		wp_body_open();
		echo '<main style="max-width:720px;margin:0 auto;padding:32px 16px">';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the block's render function.
		echo '</main>';
		wp_footer();
		echo '</body></html>';
	}

	/**
	 * In blocks display, a product's own page shows its price, stock and Add to cart.
	 *
	 * @param string $content Content.
	 */
	public function productContent( string $content ): string {
		// Block themes render the content outside the loop, so compare with the page's own product.
		if ( $this->legacy() || ! is_singular( ProductSync::POST_TYPE ) || (int) get_the_ID() !== (int) get_queried_object_id() || has_block( 'invoiceninja/product' ) ) {
			return $content;
		}
		// A block template that already shows the price and button (a Photos-only Product block doesn't count).
		$template = (string) ( $GLOBALS['_wp_current_template_content'] ?? '' );
		if ( '' !== $template && self::hasBuyBlock( parse_blocks( $template ) ) ) {
			return $content;
		}
		$id = (string) get_post_meta( (int) get_the_ID(), 'product_id', true );
		return $this->product( array( 'productId' => $id ) ) . $content;
	}

	/**
	 * Whether these blocks include a Product block that shows the price and button.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private static function hasBuyBlock( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( 'invoiceninja/product' === ( $block['blockName'] ?? '' ) && in_array( (string) ( $block['attrs']['show'] ?? 'all' ), array( 'all', 'button' ), true ) ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && self::hasBuyBlock( $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------- Renderers ----------

	/**
	 * Products grid, with optional category chips and a sort menu. Both work through query
	 * args (?category=, ?sort=), so they work without JavaScript and pages cache like any
	 * other URL with a query.
	 *
	 * @param array<string, mixed> $a Attributes: columns, perPage, orderBy, showImage, showPrice, showStock, showExcerpt, showButton, soldOut, showFilter, showSort, descriptor.
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
			'showFilter'  => true,
			'showSort'    => true,
			'descriptor'  => '',
		);
		$state = $this->placeholder();
		if ( null !== $state ) {
			return $state;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filter links, nothing changes.
		$categories = $a['showFilter'] ? Categories::forShop() : array();
		$categories = count( $categories ) >= 2 ? $categories : array();
		$category   = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( (string) $_GET['category'] ) ) : '';
		$category   = isset( $categories[ $category ] ) ? $category : '';
		$sort       = $a['showSort'] && isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( (string) $_GET['sort'] ) ) : '';
		$sort       = in_array( $sort, array( 'price-asc', 'price-desc' ), true ) ? $sort : '';
		// phpcs:enable
		$order = match ( $sort ) {
			'price-asc'  => array( 'meta_value_num', 'ASC', 'price' ),
			'price-desc' => array( 'meta_value_num', 'DESC', 'price' ),
			default      => array(
				'title' => array( 'title', 'ASC', '' ),
				'price' => array( 'meta_value_num', 'ASC', 'price' ),
				'date'  => array( 'date', 'DESC', '' ),
			)[ (string) $a['orderBy'] ] ?? array( 'title', 'ASC', '' ),
		};
		// A static front page pages with "page", not "paged".
		$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$args  = array(
			'post_type'      => ProductSync::POST_TYPE,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => max( 1, min( 100, (int) $a['perPage'] ) ),
			'paged'          => $paged,
			'orderby'        => '' !== $order[2] ? array(
				$order[0] => $order[1],
				'title'   => 'ASC',
			) : $order[0],
			'order'          => $order[1],
			'meta_key'       => $order[2], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only when sorting by price.
			'no_found_rows'  => false,
		);
		if ( '' !== $category ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one category, when the shopper picks it.
				array(
					'taxonomy' => Categories::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $category,
				),
			);
		}
		$query = new \WP_Query( $args );
		$this->enqueue();
		$items = '';
		foreach ( $query->posts as $post ) {
			$card = $post instanceof \WP_Post ? $this->card( $post, $a ) : '';
			if ( '' !== $card ) {
				$items .= '<li class="invoiceninja-products__item">' . $card . '</li>';
			}
		}
		$toolbar = $this->toolbar( $categories, $category, (bool) $a['showSort'], $sort );
		if ( '' !== $toolbar ) {
			wp_enqueue_script( self::CATALOG );
		}
		if ( '' === $items ) {
			if ( '' !== $category ) {
				return '<div class="invoiceninja-products" data-invoiceninja-store>' . $toolbar . '<p class="invoiceninja-empty">' . esc_html__( 'Nothing in this category right now.', 'invoiceninja' ) . '</p></div>';
			}
			return '<div class="invoiceninja-products invoiceninja-empty"><p>' . esc_html__( 'No products to show yet.', 'invoiceninja' ) . '</p>' . $this->emptyShopNote() . '</div>';
		}
		$columns = max( 1, min( 6, (int) $a['columns'] ) );
		$html    = '<div class="invoiceninja-products" data-invoiceninja-store>' . $toolbar . '<ul class="invoiceninja-products__grid" style="--invoiceninja-columns:' . $columns . '">' . $items . '</ul>';
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
	 * What admins (only) see on an empty shop: where products come from.
	 */
	private function emptyShopNote(): string {
		if ( ! current_user_can( \InvoiceNinja\Core\Capabilities::MANAGE ) ) {
			return '';
		}
		return '<div class="invoiceninja-notice invoiceninja-notice--admin"><strong>' . esc_html__( 'Only you can see this.', 'invoiceninja' ) . '</strong> '
			. esc_html__( 'Products come from Invoice Ninja. Add products there, then sync them here.', 'invoiceninja' )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-status' ) ) . '">' . esc_html__( 'Open Status', 'invoiceninja' ) . '</a></div>';
	}

	/**
	 * The category chips and sort menu: one GET form, so both work without JavaScript
	 * (store/catalog.js submits the menu on change and drops empty query args).
	 *
	 * @param array<string, string> $categories Slug => name (none: no chips).
	 * @param string                $current    Chosen category slug.
	 * @param bool                  $show_sort  Show the sort menu.
	 * @param string                $sort       Chosen sort.
	 */
	private function toolbar( array $categories, string $current, bool $show_sort, string $sort ): string {
		if ( ! $categories && ! $show_sort ) {
			return '';
		}
		[ $action, $keep ] = self::shopBase();
		$uid               = 'invoiceninja-shop-' . wp_rand( 1000, 9999 );
		// In the editor's preview it's only a picture: a click mustn't navigate the editor.
		$preview = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$html    = '<form class="invoiceninja-shop__toolbar" method="get" action="' . esc_url( $action ) . '" data-invoiceninja-shop' . ( $preview ? ' inert' : '' ) . '>';
		foreach ( $keep as $key => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		if ( $categories ) {
			// Before the chips: a chip's own value comes later in the query and wins.
			$html .= '' !== $current ? '<input type="hidden" name="category" value="' . esc_attr( $current ) . '">' : '';
			$html .= '<div class="invoiceninja-shop__chips" role="group" aria-label="' . esc_attr__( 'Category', 'invoiceninja' ) . '">';
			foreach ( array( '' => __( 'All', 'invoiceninja' ) ) + $categories as $slug => $name ) {
				$html .= '<button type="submit" class="invoiceninja-shop__chip" name="category" value="' . esc_attr( (string) $slug ) . '" aria-pressed="' . ( (string) $slug === $current ? 'true' : 'false' ) . '">' . esc_html( $name ) . '</button>';
			}
			$html .= '</div>';
		}
		if ( $show_sort ) {
			$options = array(
				''           => __( 'Featured', 'invoiceninja' ),
				'price-asc'  => __( 'Price, low to high', 'invoiceninja' ),
				'price-desc' => __( 'Price, high to low', 'invoiceninja' ),
			);
			$html   .= '<p class="invoiceninja-shop__sort"><label for="' . esc_attr( $uid ) . '-sort">' . esc_html__( 'Sort', 'invoiceninja' ) . '</label> <select id="' . esc_attr( $uid ) . '-sort" name="sort">';
			foreach ( $options as $value => $label ) {
				$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $value, $sort, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select> <button type="submit" class="wp-element-button invoiceninja-shop__apply" data-invoiceninja-apply>' . esc_html__( 'Apply', 'invoiceninja' ) . '</button></p>';
		}
		return $html . '</form>';
	}

	/**
	 * The shop's own address for the filter form: this page without paging, and the query
	 * args to keep (a GET form replaces the action's query string, e.g. ?page_id= on plain permalinks).
	 *
	 * @return array{0: string, 1: array<string, string>}
	 */
	private static function shopBase(): array {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path  = (string) preg_replace( '#/page/\d+/?$#', '/', '' !== $path ? $path : '/' );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		$keep = array();
		foreach ( $query as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) && ! in_array( $key, array( 'category', 'sort', 'paged', 'page' ), true ) ) {
				$keep[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		$home = (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		return array( $home . ( $port ? ':' . (int) $port : '' ) . $path, $keep );
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
		$title = wp_strip_all_tags( get_the_title( $post ) );
		$left  = $this->stockLeft( $info );
		$html  = '';
		$badge = false;
		if ( $a['showImage'] ) {
			$photos = $this->photos( $post->ID, true );
			$image  = $photos ? wp_get_attachment_image(
				$photos[0],
				'medium_large',
				false,
				array(
					'class'   => 'invoiceninja-card__image',
					'alt'     => '',
					'loading' => 'lazy',
					'sizes'   => '(max-width: 480px) 50vw, (max-width: 781px) 50vw, 33vw',
				)
			) : '';
			// The badge repeats the stock line, which stays for screen readers (this link is hidden from them).
			$note  = $sold_out ? __( 'Sold out', 'invoiceninja' ) : ( null !== $left && $a['showStock'] ? $this->onlyLeft( $left ) : '' );
			$badge = '' !== $note;
			$html .= '<a class="invoiceninja-card__media" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">'
				. ( $image ? $image : '<span class="invoiceninja-card__noimage">' . esc_html( mb_substr( $title, 0, 1 ) ) . '</span>' )
				. ( $badge ? '<span class="invoiceninja-badge' . ( $sold_out ? '' : ' is-low' ) . '">' . esc_html( $note ) . '</span>' : '' ) . '</a>';
		}
		$html      .= '<h3 class="invoiceninja-card__title"><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></h3>';
		$descriptor = $this->descriptor( $post->ID, (string) $a['descriptor'] );
		if ( '' !== $descriptor ) {
			$html .= '<p class="invoiceninja-card__descriptor">' . esc_html( $descriptor ) . '</p>';
		}
		if ( $a['showPrice'] || $a['showStock'] ) {
			$stock = $a['showStock'] ? $this->stockText( $info ) : '';
			if ( $badge && '' !== $stock ) {
				$stock = '<span class="invoiceninja-sr">' . $stock . '</span>';
			}
			$html .= '<p class="invoiceninja-card__meta">'
				. ( $a['showPrice'] ? '<span class="invoiceninja-price">' . esc_html( $this->plugin->statics->money( $info['price'] ) ) . '</span>' : '' )
				. $stock . '</p>';
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
	 * The product custom field chosen as its short descriptor, or ''.
	 *
	 * @param int    $post_id Post.
	 * @param string $field   custom_value1 to custom_value4, or '' for none.
	 */
	private function descriptor( int $post_id, string $field ): string {
		if ( ! in_array( $field, self::DESCRIPTOR_FIELDS, true ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( (string) get_post_meta( $post_id, $field, true ) ) );
	}

	/**
	 * The product's photos: its featured image, then the photos synced from its documents.
	 *
	 * @param int  $post_id    Post.
	 * @param bool $first_only Only the first one (a card's image).
	 * @return list<int> Attachment ids.
	 */
	private function photos( int $post_id, bool $first_only = false ): array {
		$ids   = array();
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb > 0 ) {
			$ids[] = $thumb;
			if ( $first_only ) {
				return $ids; // A card needs one image: skip checking the gallery.
			}
		}
		$gallery = get_post_meta( $post_id, ProductSync::GALLERY_META, true );
		foreach ( is_array( $gallery ) ? $gallery : array() as $row ) {
			$attachment = is_array( $row ) ? (int) ( $row['attachment'] ?? 0 ) : 0;
			if ( $attachment > 0 && ! in_array( $attachment, $ids, true ) && 'attachment' === get_post_type( $attachment ) ) {
				$ids[] = $attachment;
				if ( $first_only ) {
					break;
				}
			}
		}
		return $ids;
	}

	/**
	 * How many are left when stock is tracked and low, else null. Stock, not the per-order
	 * limit: a product limited to 2 per order isn't "only 2 left".
	 *
	 * @param array<string, mixed> $info Product info from the cart.
	 */
	private function stockLeft( array $info ): ?int {
		if ( $info['max'] < 1 || ! $this->plugin->cart->tracksStock() ) {
			return null;
		}
		$stock = (int) get_post_meta( (int) $info['post_id'], 'in_stock_quantity', true );
		return $stock > 0 && $stock < self::LOW_STOCK ? $stock : null;
	}

	/**
	 * "Only N left".
	 *
	 * @param int $left Number left.
	 */
	private function onlyLeft( int $left ): string {
		/* translators: %d: number left */
		return sprintf( _n( 'Only %d left', 'Only %d left', $left, 'invoiceninja' ), $left );
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
		$left = $this->stockLeft( $info );
		return null !== $left ? ' <span class="invoiceninja-stock is-low">' . esc_html( $this->onlyLeft( $left ) ) . '</span>' : '';
	}

	/**
	 * Add to cart (or Buy now) button, with an optional quantity field. With the field and
	 * the default label, the button shows the price for that quantity (price times quantity,
	 * as the cart works it out; tax isn't added here).
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
		$custom = '' !== $label;
		$label  = $custom ? $label : ( 'buy_now' === $mode ? __( 'Buy now', 'invoiceninja' ) : __( 'Add to cart', 'invoiceninja' ) );
		$field  = '';
		$text   = esc_html( $label );
		if ( $quantity && 'buy_now' !== $mode ) {
			$field_id = 'invoiceninja-qty-' . sanitize_html_class( $id ) . '-' . wp_rand( 100, 999 );
			$max      = (int) $info['max'];
			// The − and + are input buttons (store.js takes the form's first <button> for the
			// submit button) and appear with catalog.js; without it the number field works alone.
			$step  = static fn( int $by, string $glyph, string $name ): string => '<input type="button" class="invoiceninja-qty__step" value="' . esc_attr( $glyph ) . '" aria-label="' . esc_attr( $name ) . '" aria-controls="' . esc_attr( $field_id ) . '" data-invoiceninja-step="' . $by . '"' . ( -1 === $by || 1 === $max ? ' disabled' : '' ) . ' hidden>';
			$field = '<div class="invoiceninja-add__qty"><label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Quantity', 'invoiceninja' ) . '</label><span class="invoiceninja-qty">'
				. $step( -1, '−', __( 'Decrease quantity', 'invoiceninja' ) )
				. '<input id="' . esc_attr( $field_id ) . '" type="number" min="1" max="' . $max . '" value="1" inputmode="numeric" data-invoiceninja-qty>'
				. $step( 1, '+', __( 'Increase quantity', 'invoiceninja' ) ) . '</span></div>';
			if ( ! $custom ) {
				$text .= '<span class="invoiceninja-add__sep" aria-hidden="true"> · </span><span class="invoiceninja-add__total" data-invoiceninja-total="' . esc_attr( (string) $info['price'] ) . '">'
					. esc_html( $this->plugin->statics->money( $info['price'] ) ) . '</span>';
				wp_enqueue_script( self::CATALOG );
			}
		}
		return '<form class="invoiceninja-add" data-invoiceninja-add="' . esc_attr( $id ) . '" data-title="' . esc_attr( $info['title'] ) . '">' . $field
			. '<button type="submit" class="wp-element-button invoiceninja-add__button">' . $text . '</button></form>';
	}

	/**
	 * One product: price, stock and Add to cart (variations show only the price, the stock or
	 * the photos).
	 *
	 * @param array<string, mixed> $a productId, show (all, price, stock, button, gallery), quantity, descriptor.
	 */
	public function product( array $a ): string {
		$id = ProductSync::cleanId( (string) ( $a['productId'] ?? '' ) );
		if ( '' === $id && is_singular( ProductSync::POST_TYPE ) ) {
			$id = (string) get_post_meta( (int) get_the_ID(), 'product_id', true );
		}
		$show = (string) ( $a['show'] ?? 'all' );
		if ( 'gallery' === $show ) {
			// Photos need no price: a product that can't be bought (a draft being previewed,
			// a negative price) still shows them, like the featured image did.
			$mapped  = '' !== $id ? $this->plugin->mapping->find( 'product', $id ) : null;
			$post_id = is_array( $mapped ) ? (int) $mapped['wp_id'] : ( is_singular( ProductSync::POST_TYPE ) ? (int) get_the_ID() : 0 );
			// A product hidden from the site shows only to those who can edit it (a preview).
			if ( $post_id > 0 && ( 'publish' === get_post_status( $post_id ) || current_user_can( 'edit_post', $post_id ) ) ) {
				return $this->gallery( $post_id, wp_strip_all_tags( get_the_title( $post_id ) ) );
			}
		}
		$info = '' !== $id ? $this->plugin->cart->product( $id ) : null;
		if ( null === $info ) {
			return current_user_can( 'edit_posts' ) ? '<p class="invoiceninja-empty">' . esc_html__( 'Choose a product in the block settings.', 'invoiceninja' ) . '</p>' : '';
		}
		$this->enqueue();
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
		$descriptor = 'all' === $show ? $this->descriptor( (int) $info['post_id'], (string) ( $a['descriptor'] ?? '' ) ) : '';
		$out        = '<div class="invoiceninja-product" data-invoiceninja-store>'
			. ( '' !== $descriptor ? '<p class="invoiceninja-product__descriptor">' . esc_html( $descriptor ) . '</p>' : '' )
			. ( '' !== $html ? '<p class="invoiceninja-product__meta">' . $html . '</p>' : '' );
		if ( in_array( $show, array( 'all', 'button' ), true ) ) {
			$out .= $this->button( $id, $info, (bool) ( $a['quantity'] ?? true ) );
		}
		return $out . '</div>';
	}

	/**
	 * The product's photos: the main one, and thumbnails that switch it (store/catalog.js).
	 *
	 * @param int    $post_id Post.
	 * @param string $title   Product name, for alt text.
	 */
	private function gallery( int $post_id, string $title ): string {
		$photos = $this->photos( $post_id );
		if ( ! $photos ) {
			return '';
		}
		$this->enqueue();
		$count = count( $photos );
		$uid   = 'invoiceninja-photo-' . $post_id . '-' . wp_rand( 100, 999 );
		$alt   = static function ( int $attachment, int $n ) use ( $title, $count ): string {
			$own = trim( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
			if ( '' !== $own ) {
				return $own;
			}
			/* translators: 1: product name, 2: photo number, 3: number of photos */
			return 1 === $count ? $title : sprintf( __( '%1$s, photo %2$d of %3$d', 'invoiceninja' ), $title, $n, $count );
		};
		$sizes = '(max-width: 781px) 100vw, 50vw';
		$html  = '<div class="invoiceninja-gallery" data-invoiceninja-gallery><div class="invoiceninja-gallery__main">'
			. wp_get_attachment_image(
				$photos[0],
				'large',
				false,
				array(
					'id'            => $uid,
					'class'         => 'invoiceninja-gallery__image',
					'alt'           => $alt( $photos[0], 1 ),
					'sizes'         => $sizes,
					'loading'       => false,
					'fetchpriority' => 'high',
				)
			) . '</div>';
		if ( $count > 1 ) {
			wp_enqueue_script( self::CATALOG );
			$html .= '<ul class="invoiceninja-gallery__thumbs" aria-label="' . esc_attr__( 'Photos', 'invoiceninja' ) . '">';
			foreach ( $photos as $i => $attachment ) {
				$large = wp_get_attachment_image_src( $attachment, 'large' );
				$html .= '<li><button type="button" class="invoiceninja-gallery__thumb" aria-controls="' . esc_attr( $uid ) . '" aria-pressed="' . ( 0 === $i ? 'true' : 'false' ) . '"'
					/* translators: 1: photo number, 2: number of photos */
					. ' aria-label="' . esc_attr( sprintf( __( 'Show photo %1$d of %2$d', 'invoiceninja' ), $i + 1, $count ) ) . '"'
					. ' data-src="' . esc_url( is_array( $large ) ? (string) $large[0] : '' ) . '"'
					. ' data-srcset="' . esc_attr( (string) wp_get_attachment_image_srcset( $attachment, 'large' ) ) . '"'
					. ' data-alt="' . esc_attr( $alt( $attachment, $i + 1 ) ) . '">'
					. wp_get_attachment_image(
						$attachment,
						'thumbnail',
						false,
						array(
							'alt'     => '',
							'loading' => 'lazy',
						)
					) . '</button></li>';
			}
			$html .= '</ul>';
		}
		return $html . '</div>';
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
		$cart  = $this->pageUrl( 'checkout' );
		// A link to the cart page without JavaScript; store.js makes it the drawer's button.
		$open = '' !== $cart ? '<a class="invoiceninja-minicart__button" href="' . esc_url( $cart ) . '">' : '<button type="button" class="invoiceninja-minicart__button" aria-expanded="false" aria-haspopup="dialog">';
		return '<div class="invoiceninja-minicart" data-invoiceninja-minicart data-invoiceninja-store>'
			. $open
			. '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 7h14l-1.2 11.2a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>'
			. '<span class="invoiceninja-minicart__label">' . esc_html( '' !== $label ? $label : __( 'Cart', 'invoiceninja' ) ) . '</span>'
			. '<span class="invoiceninja-minicart__count" data-invoiceninja-count hidden></span>' . ( '' !== $cart ? '</a>' : '</button>' )
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
