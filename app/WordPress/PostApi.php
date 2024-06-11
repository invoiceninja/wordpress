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
        echo '<style type="text/css">
            ' . get_option( 'invoiceninja_products_css' ) . '
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
    }
}