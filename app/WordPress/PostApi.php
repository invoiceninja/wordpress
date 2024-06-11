<?php

/**
 * @package Invoice Ninja
 */

namespace App\WordPress;

class PostApi
{
    public $post_types = [];

    public function register()
    {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueueStyles' ] );

        if ( ! empty( $this->post_types) ) 
        {
            add_action( 'init', [ $this, 'init' ] );
        }

        add_filter( 'the_content', [ $this, 'addDynamicContent' ] );
    }

    public function addDynamicContent($content)
    {
        if ( is_singular( 'invoiceninja_product' ) ) {
            $content = '[add_to_cart]' . $content;
        }

        return $content;
    }

    public function enqueueStyles()
    {
        global $post;

        if ($post->post_type == 'invoiceninja_product') {
            wp_enqueue_style( 'custom-page-styles', plugins_url( '/../../assets/css/product.css', __FILE__ ) );
            
            add_action( 'wp_head', [ $this, 'printInlineProductScript' ] );
        }

        if ( get_the_ID() == get_option('invoiceninja_product_page_id') ) {
            wp_enqueue_style( 'custom-page-styles', plugins_url( '/../../assets/css/products.css', __FILE__ ) );

            add_action( 'wp_head', [ $this, 'printInlineProductsScript' ] );
        }
    }

    public function printInlineProductsScript()
    {
        $color = '#0000EE';
        $profile = json_decode( get_option( 'invoiceninja_profile' ) );
        if ($profile->settings->primary_color) {
            $color = $profile->settings->primary_color;
        }

        echo '<style type="text/css">
            ' . get_option( 'invoiceninja_products_css' ) . '

            a:hover div.divider
            {
                border-color: ' . esc_attr( $color ) . '
            }
        </style>';        
    }

    public function printInlineProductScript()
    {
        echo '<style type="text/css">
            ' . get_option( 'invoiceninja_product_css' ) . '
        </style>';        
    }

    public function setPostTypes($types)
    {
        $this->post_types = $types;

        return $this;
    }

    public function init()
    {
        if ( ! session_id() ) {
            session_start();
        }

        foreach ($this->post_types as $type)
        {            
            $product_label = get_option( 'invoiceninja_product_label' );
            $slug = 'product';

            if ( $product_label ) {
                $slug = strtolower( $product_label );
            }

            $query_args = array(
                'post_type' => 'invoiceninja_product',
                'posts_per_page' => 5,
                'order_by' => 'title', // date, menu_order
                'order' => 'ASC',
            );
            $query = new \WP_Query($query_args);
                
            register_post_type(
                $type['id'], [
                    'labels' => [
                        'name' => $type['name'],
                        'singular_name' => $type['singular_name'],
                    ],
                    'hierarchical' => true,
                    'show_in_menu' => $query->have_posts(),
                    'public' => true,
                    'has_archive' => true,
                    'menu_icon' => 'dashicons-products',
                    'show_in_rest' => true,
                    'rewrite' => [ 'slug' => sanitize_title( $slug ) ],
                    /*
                    'capabilities' => [
                        'edit_post' => 'edit_post',
                        'read_post' => 'read_post',
                        'delete_post' => 'delete_post',
                        'edit_posts' => 'edit_posts',
                        'edit_others_posts' => 'edit_others_posts',
                        'publish_posts' => 'publish_posts',
                        'read_private_posts' => 'read_private_posts',
                        'delete_posts' => 'delete_posts',
                        'delete_private_posts' => 'delete_private_posts',
                        'delete_published_posts' => 'delete_published_posts',
                        'delete_others_posts' => 'delete_others_posts',
                        'edit_private_posts' => 'edit_private_posts',
                        'edit_published_posts' => 'edit_published_posts',
                        'create_posts' => 'create_posts', 
                    ],
                    */
                ],
            );       
        }

        flush_rewrite_rules();

        add_shortcode('add_to_cart', [ $this, 'addToCartShortcode' ] );
    }

    public function addToCartShortcode($atts)
    {
        $atts = shortcode_atts(array(
            'product' => '',
        ), $atts, 'add_to_cart');

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart'])) {
            $product = $_POST['product'];

            if ($product && wp_verify_nonce($_POST['invoiceninja_nonce'], 'invoiceninja_add_to_cart_' . esc_attr($atts['product']))) {
                if ( ! isset( $_SESSION['invoiceninja_cart'] ) ) {
                    $_SESSION['invoiceninja_cart'] = [];
                }

                if (isset($_SESSION['invoiceninja_cart'][$product])) {
                    $_SESSION['invoiceninja_cart'][$product]++;
                } else {
                    $_SESSION['invoiceninja_cart'][$product] = 1;
                }
            }

            echo 'CART: ' . json_encode($_SESSION['invoiceninja_cart']);        
        }
    
        ob_start();
        
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('invoiceninja_add_to_cart_' . esc_attr($atts['product']), 'invoiceninja_nonce'); ?>
            <input type="hidden" name="product" value="<?php echo esc_attr($atts['product']); ?>">
            <button type="submit" name="add_to_cart">Add to Cart</button>
        </form>
        <?php

        return ob_get_clean();    
    }
}