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
            $productId = get_post_meta( get_the_ID(), 'product_id', true );
            $content = '[add_to_cart id="' . $productId . '"]' . $content;
        }

        if ( isset( $_SESSION['invoiceninja_cart'] ) && ! empty( $_SESSION['invoiceninja_cart'] ) ) {
            $cart = $_SESSION['invoiceninja_cart'];
            $color = '#0000EE';
            $profile = json_decode( get_option( 'invoiceninja_profile' ) );
            if ($profile->settings->primary_color) {
                $color = $profile->settings->primary_color;
            }    
            $str = '<div class="invoiceninja-cart">
                    <div class="cart-header" style="background-color: ' . $color . ';">';

            if ( count($cart) == 1 ) {
                $str .= '1 item in cart';
            } else {
                $str .= count($cart) . ' items in cart';
            }

            $str .= '[checkout details="true"]</div>';

            $str .= '<table style="displayx:none">
                     <form method="POST" action="" id="invoiceninja_cart">
                     <input type="text" id="cart_action" name="cart_action" value=""/>
                     <input type="text" id="product_id" name="product_id"/>
                     <input type="text" id="quantity" name="quantity"/>';
            $str .= wp_nonce_field('invoiceninja_checkout', 'invoiceninja_nonce');
            
            foreach ( $_SESSION['invoiceninja_cart'] as $product_id => $quantity ) {

                $args = [
                    'post_type' => 'invoiceninja_product',
                    'meta_query' => [
                        [
                            'key' => 'product_id',
                            'value' => $product_id,
                            'compare' => 'EQUAL',
                        ],
                    ],
                ];
                
                $query = new \WP_Query( $args );
                
                if ( $query->have_posts() ) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    
                    $url = get_permalink();
                    $product = get_the_title();
                    $price = get_post_meta( $post_id, 'price', true );
                    
                    $str .= '<tr><td style="width: 0px">';

                    $image_url = '';
                    if ( has_post_thumbnail( $post_id ) ) {
                        $image_url = get_the_post_thumbnail_url( $post_id, 'medium' );
                        $str .= '<img src="' . $image_url . '"/>';
                    }
                    
                    $str .= '</td>
                        <td style="width: 30%"><a href="' . $url . '">' . $product . '</a><br/>' . $price . '</td>
                        <td><select onchange="document.getElementById(\'cart_action\').value = \'update\';
                                              document.getElementById(\'quantity\').value = this.value;
                                              document.getElementById(\'product_id\').value = \'' . $product_id . '\';
                                              document.getElementById(\'invoiceninja_cart\').submit();">';
                        
                    for ($i=1; $i<100; $i++) {
                        $str .= '<option value="' . $i . '"' . ($quantity == $i ? 'SELECTED' : '') . '>' . $i . '</option>';
                    }

                    $str .= '</select></td>
                        <td>' . ($quantity * $price) . '</td>
                        <td>[<a href="">Remove</a>]';

                    $str .= '</tr>';
                }
            }


            $str .= '</form></table>';
                       
            $content = $str . '</div>' . $content;
        }
        
        //$content = json_encode( $_SESSION['invoiceninja_cart'] ) . $content;

        return $content;
    }

    public function enqueueStyles()
    {
        global $post;

        if ( $post->post_type == 'invoiceninja_product' ) {
            wp_enqueue_style( 'product-styles', plugins_url( '/../../assets/css/product.css?t=' . time(), __FILE__ ) );
            
            add_action( 'wp_head', [ $this, 'printInlineProductScript' ] );
        }

        if ( get_the_ID() == get_option( 'invoiceninja_product_page_id' ) ) {
            wp_enqueue_style( 'products-styles', plugins_url( '/../../assets/css/products.css?t=' . time(), __FILE__ ) );

            add_action( 'wp_head', [ $this, 'printInlineProductsScript' ] );
        }

        if ( ! is_admin() ) {
            wp_enqueue_style( 'frontend-styles', plugins_url( '/../../assets/css/frontend.css?t=' . time(), __FILE__ ) );
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
        add_shortcode('checkout', [ $this, 'checkoutShortcode' ] );
    }

    public function addToCartShortcode($atts)
    {
        $atts = shortcode_atts(array(
            'product_id' => '',
        ), $atts, 'add_to_cart');

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart'])) {
            $product_id = $_POST['product_id'];
            
            if ($product_id && wp_verify_nonce($_POST['invoiceninja_nonce'], 'invoiceninja_add_to_cart_' . esc_attr($atts['product_id']))) {
                if ( ! isset( $_SESSION['invoiceninja_cart'] ) ) {
                    $_SESSION['invoiceninja_cart'] = [];
                }

                if (isset($_SESSION['invoiceninja_cart'][$product_id])) {
                    $_SESSION['invoiceninja_cart'][$product_id]++;
                } else {
                    $_SESSION['invoiceninja_cart'][$product_id] = 1;
                }
            }
        }
    
        ob_start();
        
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('invoiceninja_add_to_cart_' . esc_attr($atts['product_id']), 'invoiceninja_nonce'); ?>
            <input type="hidden" name="product_id" value="<?php echo esc_attr($atts['product_id']); ?>">
            <button type="submit" name="add_to_cart">Add to Cart</button>
        </form>
        <?php

        return ob_get_clean();    
    }

    public function checkoutShortcode($atts)
    {
        $atts = shortcode_atts(array(
            'details' => false,
        ), $atts, 'add_to_cart');

        if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset( $_POST['cart_action'] ) ) {
            if ( wp_verify_nonce($_POST['invoiceninja_nonce'], 'invoiceninja_checkout' ) ) {
                $action = $_POST['cart_action'];
                if ($action == 'update') {                    
                    $_SESSION['invoiceninja_cart'][$_POST['product_id']] = $_POST['quantity'];
                }
            }
        }
    
        ob_start();
        
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('invoiceninja_checkout', 'invoiceninja_nonce'); ?>
            <?php echo $atts['details'] ? '<button name="">View Details</button>' : '' ?>
            <button type="submit" name="checkout">Checkout</button>
        </form>
        <?php

        return ob_get_clean();    
    }
}