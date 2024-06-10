<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

use \App\WordPress\PostApi;
use \App\InvoiceNinja\ProductApi;

class ProductController extends BaseController
{
    public function __construct()
    {
        parent::__construct();

        $this->posts = new PostApi();
    }

    public function register()
    {
        $profile = json_decode( get_option( 'invoiceninja_profile' ) );

        $product_label = get_option( 'invoiceninja_product_label');
        if ( ! $product_label ) {
           $product_label = 'Product';
        }

        $products_label = get_option( 'invoiceninja_products_label');
        if ( ! $products_label ) {
           $products_label = 'Products';
        }

        $types = [
            [
                'id' => 'invoiceninja_product',
                'name' => $products_label,
                'singular_name' => $product_label,
            ]
        ];

        $this->posts
            ->setPostTypes($types)
            ->register();

        add_action('init', [ $this, 'registerCron' ] );
        register_deactivation_hook(__FILE__, [ $this, 'deactivation' ] );
    }

    public function registerCron()
    {
        if ( ! wp_next_scheduled('auto_refresh') ) {
            wp_schedule_event( time(), 'hourly', 'auto_refresh' );
        }

        add_action('auto_refresh', [ $this, 'autoRefresh' ] );
    }

    function deactivation() 
    {
        $timestamp = wp_next_scheduled( 'auto_refresh' );
        
        wp_unschedule_event( $timestamp, 'auto_refresh' );
    }

    public function autoRefresh()
    {
        SettingsController::loadProfile();

        $args = [
            'post_type' => 'invoiceninja_product',
            'posts_per_page' => -1,
        ];
        
        $query = new \WP_Query( $args );
        
        if ( $query->have_posts() ) {
            ProductController::loadProducts();
        }        
    }

    public static function loadProducts()
    {
        /*
        // Disable email notifications for new posts
        function disable_post_email_notifications() {
            remove_action( 'publish_post', 'wp_notify_postauthor' );
        }
        add_action( 'pre_insert_post', 'disable_post_email_notifications' );

        // Re-enable email notifications after post insertion
        function enable_post_email_notifications() {
            add_action( 'publish_post', 'wp_notify_postauthor' );
        }
        add_action( 'wp_insert_post', 'enable_post_email_notifications' );
        */
        
        $args = [
            'post_type' => 'invoiceninja_product',
            'posts_per_page' => -1,
        ];
        
        $query = new \WP_Query( $args );
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                wp_delete_post( $post_id, true );
            }
            wp_reset_postdata();
        }

        $products = ProductApi::load();
        $products = json_decode( $products );

        foreach ($products as $product) 
        {             
            $post_data = array(
                'post_title' => esc_attr( $product->product_key ),
                'post_content' => esc_attr( $product->notes ),
                'post_status' => 'publish', // publish, draft, pending, private, trash
                'post_type' => 'invoiceninja_product',
                'meta_input' => [
                    'id' => esc_attr( $product->id ),
                    'price' => esc_attr( $product->price ),
                    'quantity' => esc_attr( $product->quantity ),
                    'max_quantity' => esc_attr( $product->max_quantity ),
                    'in_stock_quantity' => esc_attr( $product->in_stock_quantity ),
                    'custom_value1' => esc_attr( $product->custom_value1 ),
                    'custom_value2' => esc_attr( $product->custom_value2 ),
                    'custom_value3' => esc_attr( $product->custom_value3 ),
                    'custom_value4' => esc_attr( $product->custom_value4 ),
                ],
            );

            $post_id = wp_insert_post($post_data); 
            
            if ( $product->product_image && $post_id && ! is_wp_error( $post_id ) ) 
            {                
                $file_extension = pathinfo( $product->product_image, PATHINFO_EXTENSION );
                $allowed_mime_types = wp_get_mime_types();
                $post_mime_type = isset( $allowed_mime_types[ $file_extension ] ) ? $allowed_mime_types[ $file_extension ] : 'image/jpeg';            
                $filename = $product->id . '.' . $file_extension;

                // Delete the old image if it exists 
                $args = array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => '_wp_attached_file',
                            'value'   => $filename,
                            'compare' => 'LIKE',
                        ),
                    ),
                );

                $attachments = get_posts( $args );
                if ( $attachments ) {
                    $attachment_id = $attachments[0];                    
                    $result = wp_delete_attachment( $attachment_id, true );
                }
                
                if (filter_var($product->product_image, FILTER_VALIDATE_URL) 
                    && $image_data = @file_get_contents( $product->product_image ) ) 
                {                
                    $upload = wp_upload_bits( $filename, null, $image_data );

                    if ( ! $upload['error'] ) {
                        $attachment_id = wp_insert_attachment( 
                            [
                                'post_mime_type' => $post_mime_type,
                                'post_title' => sanitize_file_name( $filename ),
                                'post_content' => $product->product_key,
                                'post_status' => 'inherit'
                            ], 
                            $upload['file']
                        );

                        set_post_thumbnail( $post_id, $attachment_id );
                    }
                }
            }
        }     
        
        $profile = json_decode( get_option( 'invoiceninja_profile' ) );
        $page = '<div class="wp-block-query alignwide is-layout-flow wp-block-query-is-layout-flow">';
        
        $count = 0;
        $args = [
            'post_type' => 'invoiceninja_product',
            'posts_per_page' => -1,
            'order_by' => 'title', // date, menu_order
            'order' => 'ASC',
        ];
        
        $query = new \WP_Query( $args );
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $price = get_post_meta( $post_id, 'price', true );

                if ($count % 3 == 0) {
                    $page .= '<div class="wp-block-columns" style="padding:0px; margin:0px;">';
                }

                $page .= '<div class="wp-block-column">';
                $template = get_option( 'invoiceninja_product_template' );
                if ( ! $template ) {
                    $template = INVOICENINJA_DEFAULT_PRODUCT_TEMPLATE;
                }

                $image = '';

                if ( has_post_thumbnail( $post_id ) ) {
                    $image_url = get_the_post_thumbnail_url( $post_id, 'medium' );
                    $image_template = get_option( 'invoiceninja_image_template' );
                    if ( ! $image_template ) {
                        $image_template = INVOICENINJA_DEFAULT_IMAGE_TEMPLATE;
                    }
                    $image = str_replace( [ 
                        '$image_url',
                        '$title',
                    ], [ 
                        $image_url,
                        get_the_title(),
                    ], $image_template );
                }

                $template = str_replace( [
                    '$post_url',
                    '$title',
                    '$content',
                    '$price',
                    '$image',
                    '$custom1',
                    '$custom2',
                    '$custom3',
                    '$custom4',
                ], [
                    get_permalink(),
                    get_the_title(),
                    get_the_content(),
                    $price,
                    $image,
                    get_post_meta( $post_id, 'custom_value1', true ),
                    get_post_meta( $post_id, 'custom_value2', true ),
                    get_post_meta( $post_id, 'custom_value3', true ),
                    get_post_meta( $post_id, 'custom_value4', true ),
                ], $template );

                $page .= $template;
                $page .= '</div>';

                if ($count % 3 == 2) {
                    $page .= '</div>';
                }
    
                $count++;
    
            }
            wp_reset_postdata();
        }

        while ($count % 3 != 0) {
            $page .= '<div class="wp-block-column" style="margin-left:16px;margin-right:16px;"></div>';
            $count++;
        }        

        $products_label = get_option( 'invoiceninja_products_label' );
        if ( ! $products_label ) {
           $products_label = 'Products';
        }

        $post_status = 'draft';
        if ( $post_id = get_option( 'invoiceninja_product_page_id' ) ) 
        {
            if ( $post = get_post($post_id) ) 
            {
                $post_status = $post->post_status;
            }
        }

        $page_data = [
            'ID' => $post_id,
            'post_title' => $products_label,
            'post_content' => $page,
            'post_status' => $post_status,
            'post_author' => 1,
            'post_type' => 'page',
            'post_name' => sanitize_title( strtolower( $products_label ) ),
        ];

        $page_id = wp_insert_post( $page_data );

        if ( ! is_wp_error( $page_id ) ) {
            update_option('invoiceninja_product_page_id', $page_id);
        }
    }
}